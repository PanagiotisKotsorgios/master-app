<?php
/**
 * employee/users.php — Χρήστες
 */
ini_set('log_errors',1); ini_set('error_log',__DIR__.'/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors',0);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/privileges.php';
require_once __DIR__.'/layout.php';

empRequire('users_view');

$db = getDB();
$canEdit        = empCan('users_edit');
$canCreate      = empCan('users_create');
$canDelete      = empCan('users_delete');
$canImpersonate = empCan('users_impersonate');

// ── Impersonate ───────────────────────────────────────────────
if (isset($_GET['impersonate']) && $canImpersonate) {
    $uid  = (int)$_GET['impersonate'];
    $user = $db->prepare("SELECT * FROM users WHERE id=? AND role NOT IN ('superadmin','employee','maintainer') LIMIT 1");
    $user->execute([$uid]);
    $u2 = $user->fetch();
    if ($u2) {
        $_SESSION['user_id']       = $u2['id'];
        $_SESSION['school_id']     = $u2['school_id'];
        $_SESSION['user']          = ['id'=>$u2['id'],'name'=>$u2['name'],'email'=>$u2['email'],'role'=>$u2['role']];
        $_SESSION['impersonating'] = true;
        flash('Impersonating '.$u2['name'].' — <a href="'.APP_URL.'/employee/users.php">Επιστροφή</a>');
        redirect(APP_URL.'/dashboard/');
    }
}

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['_action'] ?? '';

    if ($act === 'create' && $canCreate) {
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role']  ?? 'coach';
        $sid   = (int)($_POST['school_id'] ?? 0);
        $pass  = password_hash($_POST['password'] ?? bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
        $db->prepare("INSERT INTO users (name,email,password,role,school_id,active,created_at) VALUES (?,?,?,?,?,1,NOW())")
           ->execute([$name,$email,$pass,$role,$sid?$sid:null]);
        auditLog('employee_user_create','users',(int)$db->lastInsertId(),'Employee created user');
        flash('Ο χρήστης δημιουργήθηκε.','success'); redirect(APP_URL.'/employee/users.php');
    }

    if ($act === 'edit' && $canEdit) {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name']  ?? '');
        $email= trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'coach';
        $active=(int)($_POST['active'] ?? 1);
        $db->prepare("UPDATE users SET name=?,email=?,role=?,active=? WHERE id=?")
           ->execute([$name,$email,$role,$active,$id]);
        auditLog('employee_user_edit','users',$id,'Employee edited user');
        flash('Ο χρήστης ενημερώθηκε.','success'); redirect(APP_URL.'/employee/users.php');
    }

    if ($act === 'delete' && $canDelete) {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE users SET active=0 WHERE id=? AND role NOT IN ('superadmin','employee','maintainer')")->execute([$id]);
        auditLog('employee_user_delete','users',$id,'Employee deactivated user');
        flash('Ο χρήστης απενεργοποιήθηκε.','success'); redirect(APP_URL.'/employee/users.php');
    }
    redirect(APP_URL.'/employee/users.php');
}

// ── Filters ──────────────────────────────────────────────────
$search    = trim($_GET['q']    ?? '');
$role      = $_GET['role']      ?? '';
$active    = $_GET['active']    ?? '';
$school_id = (int)($_GET['school_id'] ?? 0);
$perPage   = 30; $page=max(1,(int)($_GET['page']??1)); $offset=($page-1)*$perPage;

$where=[]; $params=[];
if ($search){ $where[]='(u.name LIKE ? OR u.email LIKE ?)'; $like="%$search%"; $params=array_merge($params,[$like,$like]); }
if ($role)  { $where[]='u.role = ?'; $params[]=$role; }
if ($active!=='') { $where[]='u.active = ?'; $params[]=(int)$active; }
if ($school_id)   { $where[]='u.school_id = ?'; $params[]=$school_id; }
$whereSQL=$where?'WHERE '.implode(' AND ',$where):'';

$stmtC=$db->prepare("SELECT COUNT(*) FROM users u $whereSQL"); $stmtC->execute($params); $totalRows=(int)$stmtC->fetchColumn();
$totalPages=max(1,ceil($totalRows/$perPage));
$stmt=$db->prepare("SELECT u.*,s.name as school_name FROM users u LEFT JOIN schools s ON s.id=u.school_id $whereSQL ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params); $users=$stmt->fetchAll();
$schools=$db->query("SELECT id,name FROM schools WHERE active=1 ORDER BY name")->fetchAll();

renderEmpHead('Χρήστες');
?><body>
<?php renderEmpSidebar('users'); ?>
<div class="emp-main">
<?php renderEmpTopbar('Χρήστες'); ?>
<div class="emp-content">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
  <div>
    <div class="section-title">Χρήστες</div>
    <div class="section-sub">Επισκόπηση λογαριασμών<?= $canEdit||$canCreate||$canDelete?' · Έχεις δικαιώματα επεξεργασίας':' · Read-only' ?>.</div>
  </div>
  <?php if ($canCreate): ?>
    <button class="btn btn-primary" onclick="openModal('modalCreate')"><i class="fa-solid fa-plus"></i> Νέος Χρήστης</button>
  <?php endif; ?>
</div>

<form method="get" class="card" style="padding:1rem 1.4rem">
  <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
    <div class="form-group" style="margin:0;flex:1;min-width:160px"><label>Αναζήτηση</label>
      <div class="search-input-wrap"><i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="form-control" placeholder="Όνομα ή email…" value="<?= h($search) ?>"></div></div>
    <div class="form-group" style="margin:0;min-width:140px"><label>Role</label>
      <select name="role" class="form-control"><option value="">Όλα</option>
        <?php foreach(['superadmin','employee','maintainer','owner','admin','coach','secretary'] as $r): ?>
          <option value="<?= $r ?>" <?= $role===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0;min-width:120px"><label>Κατάσταση</label>
      <select name="active" class="form-control"><option value="">Όλοι</option>
        <option value="1" <?= $active==='1'?'selected':'' ?>>Ενεργοί</option>
        <option value="0" <?= $active==='0'?'selected':'' ?>>Ανενεργοί</option></select></div>
    <div class="form-group" style="margin:0;min-width:160px"><label>Σχολή</label>
      <select name="school_id" class="form-control"><option value="0">Όλες</option>
        <?php foreach($schools as $sc): ?>
          <option value="<?= $sc['id'] ?>" <?= $school_id==$sc['id']?'selected':'' ?>><?= h($sc['name']) ?></option>
        <?php endforeach; ?></select></div>
    <div style="display:flex;gap:.5rem">
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Φίλτρο</button>
      <a href="?" class="btn btn-ghost">Reset</a>
    </div>
  </div>
</form>

<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:.9rem 1.4rem;border-bottom:1px solid var(--border)">
    <span style="font-size:.88rem;color:var(--muted)"><?= number_format($totalRows) ?> χρήστες</span>
  </div>
  <div class="tbl-wrap"><table>
    <thead><tr>
      <th>#</th><th>Χρήστης</th><th>Role</th><th>Σχολή</th><th>2FA</th><th>Κατάσταση</th><th>Τελ. Σύνδεση</th><th>Εγγραφή</th>
      <th>Ενέργειες</th>
    </tr></thead>
    <tbody>
    <?php if(empty($users)): ?>
      <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--muted)">Δεν βρέθηκαν χρήστες.</td></tr>
    <?php endif; ?>
    <?php foreach($users as $u): ?>
      <tr>
        <td style="color:var(--muted);font-size:.8rem"><?= $u['id'] ?></td>
        <td>
          <div style="font-weight:600;font-size:.88rem"><?= h($u['name']) ?></div>
          <div style="font-size:.75rem;color:var(--muted)"><?= h($u['email']) ?></div>
        </td>
        <td><span class="badge <?php
          echo match($u['role']) {
            'superadmin'=>'badge-red','employee','maintainer'=>'badge-purple',
            'owner'=>'badge-gold','admin'=>'badge-blue',
            default=>'badge-muted'
          };
        ?>"><?= h($u['role']) ?></span></td>
        <td style="font-size:.82rem;color:var(--muted)"><?= h($u['school_name']??'—') ?></td>
        <td>
          <?= $u['totp_enabled']?'<span style="color:var(--green)"><i class="fa-solid fa-shield-halved"></i></span>':'<span style="color:var(--muted)"><i class="fa-solid fa-shield"></i></span>' ?>
        </td>
        <td><span class="badge <?= $u['active']?'badge-green':'badge-red' ?>"><?= $u['active']?'Ενεργός':'Ανενεργός' ?></span></td>
        <td style="font-size:.8rem;color:var(--muted)"><?= $u['last_login']?date('d/m/Y H:i',strtotime($u['last_login'])):'—' ?></td>
        <td style="font-size:.8rem;color:var(--muted)"><?= date('d/m/Y',strtotime($u['created_at'])) ?></td>
        <td style="white-space:nowrap">
          <!-- PREVIEW -->
          <button class="btn btn-ghost" style="padding:.35rem .6rem;font-size:.8rem" title="Προβολή Προφίλ"
            onclick='openPreview(<?= json_encode(['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'school_name'=>$u['school_name']??'','active'=>$u['active'],'last_login'=>$u['last_login']??'','created_at'=>$u['created_at'],'totp_enabled'=>$u['totp_enabled']??0]) ?>)'>
            <i class="fa-solid fa-eye"></i></button>
          <?php if($canEdit && !in_array($u['role'],['superadmin','employee','maintainer'])): ?>
            <button class="btn btn-ghost" style="padding:.35rem .6rem;font-size:.8rem" title="Επεξεργασία"
              onclick='openEdit(<?= json_encode(['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'active'=>$u['active']]) ?>)'>
              <i class="fa-solid fa-pen"></i></button>
          <?php endif; ?>
          <?php if($canImpersonate && !in_array($u['role'],['superadmin','employee','maintainer'])): ?>
            <a href="?impersonate=<?= $u['id'] ?>"
               class="btn btn-ghost" style="padding:.35rem .6rem;font-size:.8rem;color:var(--accent)" title="Impersonate"
               onclick="return confirm('Login ως <?= h(addslashes($u['name'])) ?>?')">
              <i class="fa-solid fa-user-secret"></i></a>
          <?php endif; ?>
          <?php if($canDelete && !in_array($u['role'],['superadmin','employee','maintainer']) && $u['active']): ?>
            <button class="btn btn-red" style="padding:.35rem .6rem;font-size:.8rem" title="Απενεργοποίηση"
              onclick="openDelete(<?= $u['id'] ?>,'<?= h(addslashes($u['name'])) ?>')">
              <i class="fa-solid fa-trash"></i></button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if($totalPages>1): ?>
  <div style="padding:.9rem 1.4rem;border-top:1px solid var(--border)">
    <div class="pagination">
      <?php if($page>1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
      <?php for($i=max(1,$page-3);$i<=min($totalPages,$page+3);$i++): ?>
        <?php if($i===$page): ?><span class="active"><?= $i ?></span>
        <?php else: ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
      <?php if($page<$totalPages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

</div><!-- /.emp-content -->

<!-- ═══════════════════════════════════════════
     MODALS — inside .emp-main, BEFORE renderEmpClose()
     ═══════════════════════════════════════════ -->
<style>
.emp-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;align-items:center;justify-content:center;padding:1rem;overflow-y:auto}
.emp-modal-bg.open{display:flex!important}
.emp-modal{background:#111520;border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:1.8rem;width:100%;max-width:540px;box-shadow:0 30px 80px rgba(0,0,0,.6);margin:auto}
.emp-modal h3{margin:0 0 1.2rem;font-size:1.1rem;font-weight:800;display:flex;align-items:center;gap:.6rem}
.modal-footer{display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem}
.two-col-form{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.pv-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1.4rem;margin-bottom:1rem}
.pv-item label{display:block;font-size:.72rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.22rem}
.pv-item .pv-val{font-size:.9rem;color:#fff;word-break:break-word}
.pv-section-title{font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);border-bottom:1px solid rgba(255,255,255,.07);padding-bottom:.4rem;margin:.8rem 0 .6rem;grid-column:1/-1}
</style>

<!-- PREVIEW MODAL -->
<div class="emp-modal-bg" id="modalPreview">
  <div class="emp-modal" style="max-width:560px">
    <h3><i class="fa-solid fa-user" style="color:var(--blue)"></i> <span id="pvTitle">Χρήστης</span></h3>
    <div class="pv-grid">
      <div class="pv-item" style="grid-column:1/-1"><label>Email</label><div class="pv-val" id="pvEmail">—</div></div>
      <div class="pv-item"><label>Role</label><div class="pv-val" id="pvRole">—</div></div>
      <div class="pv-item"><label>Κατάσταση</label><div class="pv-val" id="pvActive">—</div></div>
      <div class="pv-item" style="grid-column:1/-1"><label>Σχολή</label><div class="pv-val" id="pvSchool">—</div></div>
      <div class="pv-item"><label>2FA</label><div class="pv-val" id="pvTotp">—</div></div>
      <div class="pv-item"><label>Τελ. Σύνδεση</label><div class="pv-val" id="pvLastLogin">—</div></div>
      <div class="pv-item"><label>Εγγραφή</label><div class="pv-val" id="pvCreated">—</div></div>
    </div>
    <div class="modal-footer">
      <?php if ($canEdit): ?>
        <button type="button" class="btn btn-ghost" id="pvEditBtn" onclick="switchToEdit()"><i class="fa-solid fa-pen"></i> Επεξεργασία</button>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost" onclick="closeModal('modalPreview')">Κλείσιμο</button>
    </div>
  </div>
</div>

<?php if($canCreate): ?>
<!-- CREATE MODAL -->
<div class="emp-modal-bg" id="modalCreate"><div class="emp-modal">
  <h3><i class="fa-solid fa-plus" style="color:var(--green)"></i> Νέος Χρήστης</h3>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="_action" value="create">
    <div class="two-col-form">
      <div class="form-group"><label>Όνομα *</label><input type="text" name="name" class="form-control" required></div>
      <div class="form-group"><label>Email *</label><input type="email" name="email" class="form-control" required></div>
    </div>
    <div class="two-col-form">
      <div class="form-group"><label>Κωδικός *</label><input type="password" name="password" class="form-control" required minlength="8"></div>
      <div class="form-group"><label>Role</label>
        <select name="role" class="form-control">
          <?php foreach(['owner','admin','coach','secretary'] as $r): ?>
            <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="form-group"><label>Σχολή</label>
      <select name="school_id" class="form-control"><option value="0">— Χωρίς σχολή —</option>
        <?php foreach($schools as $sc): ?><option value="<?= $sc['id'] ?>"><?= h($sc['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('modalCreate')">Ακύρωση</button>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Δημιουργία</button>
    </div>
  </form>
</div></div>
<?php endif; ?>

<?php if($canEdit): ?>
<!-- EDIT MODAL -->
<div class="emp-modal-bg" id="modalEdit"><div class="emp-modal">
  <h3><i class="fa-solid fa-pen" style="color:var(--blue)"></i> Επεξεργασία Χρήστη</h3>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="_action" value="edit"><input type="hidden" name="id" id="editId">
    <div class="two-col-form">
      <div class="form-group"><label>Όνομα *</label><input type="text" name="name" id="editName" class="form-control" required></div>
      <div class="form-group"><label>Email *</label><input type="email" name="email" id="editEmail" class="form-control" required></div>
    </div>
    <div class="two-col-form">
      <div class="form-group"><label>Role</label>
        <select name="role" id="editRole" class="form-control">
          <?php foreach(['owner','admin','coach','secretary'] as $r): ?>
            <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="form-group"><label>Κατάσταση</label>
        <select name="active" id="editActive" class="form-control">
          <option value="1">Ενεργός</option><option value="0">Ανενεργός</option>
        </select></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('modalEdit')">Ακύρωση</button>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Αποθήκευση</button>
    </div>
  </form>
</div></div>
<?php endif; ?>

<?php if($canDelete): ?>
<!-- DELETE MODAL -->
<div class="emp-modal-bg" id="modalDelete"><div class="emp-modal">
  <h3><i class="fa-solid fa-triangle-exclamation" style="color:var(--red)"></i> Απενεργοποίηση Χρήστη</h3>
  <p style="color:var(--muted);margin-bottom:1.2rem">Απενεργοποίηση: <strong id="deleteName" style="color:#fff"></strong>;</p>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" id="deleteId">
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('modalDelete')">Ακύρωση</button>
      <button type="submit" class="btn btn-red"><i class="fa-solid fa-trash"></i> Απενεργοποίηση</button>
    </div>
  </form>
</div></div>
<?php endif; ?>

<script>
var _pvCurrent = {};

function openModal(id) {
  document.getElementById(id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.emp-modal-bg').forEach(function(bg) {
  bg.addEventListener('click', function(e) {
    if (e.target === bg) bg.classList.remove('open');
  });
});

function openPreview(u) {
  _pvCurrent = u;
  document.getElementById('pvTitle').textContent    = u.name       || '—';
  document.getElementById('pvEmail').textContent    = u.email      || '—';
  document.getElementById('pvRole').textContent     = u.role       || '—';
  document.getElementById('pvActive').textContent   = u.active==1  ? 'Ενεργός' : 'Ανενεργός';
  document.getElementById('pvSchool').textContent   = u.school_name|| '—';
  document.getElementById('pvTotp').textContent     = u.totp_enabled ? '✓ Ενεργό' : '✗ Ανενεργό';
  document.getElementById('pvLastLogin').textContent= u.last_login  || '—';
  document.getElementById('pvCreated').textContent  = u.created_at  || '—';
  openModal('modalPreview');
}

function switchToEdit() {
  closeModal('modalPreview');
  openEdit(_pvCurrent);
}

function openEdit(u) {
  document.getElementById('editId').value     = u.id;
  document.getElementById('editName').value   = u.name   || '';
  document.getElementById('editEmail').value  = u.email  || '';
  document.getElementById('editRole').value   = u.role   || 'coach';
  document.getElementById('editActive').value = u.active ? '1' : '0';
  openModal('modalEdit');
}

function openDelete(id, name) {
  document.getElementById('deleteId').value         = id;
  document.getElementById('deleteName').textContent = name;
  openModal('modalDelete');
}
</script>

<?php renderEmpClose(); ?>