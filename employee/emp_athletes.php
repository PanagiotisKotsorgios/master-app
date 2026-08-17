<?php
/**
 * employee/athletes.php — Αθλητές
 */
ini_set('log_errors',1); ini_set('error_log',__DIR__.'/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors',0);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/privileges.php';
require_once __DIR__.'/layout.php';

empRequire('athletes_view');

$db = getDB();
$canEdit   = empCan('athletes_edit');
$canCreate = empCan('athletes_create');
$canDelete = empCan('athletes_delete');

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['_action'] ?? '';

    if ($act === 'create' && $canCreate) {
        $sid = (int)($_POST['school_id'] ?? 0);
        $db->prepare("INSERT INTO athletes (school_id,full_name,father_name,mother_name,birthdate,phone,parent_phone,email,monthly_fee,active,created_at,registration_date) VALUES (?,?,?,?,?,?,?,?,?,1,NOW(),CURDATE())")
           ->execute([$sid,trim($_POST['full_name']??''),trim($_POST['father_name']??''),trim($_POST['mother_name']??''),
             $_POST['birthdate']??null,
             trim($_POST['phone']??''),trim($_POST['parent_phone']??''),trim($_POST['email']??''),(float)($_POST['monthly_fee']??0)]);
        auditLog('employee_athlete_create','athletes',(int)$db->lastInsertId(),'Employee created athlete');
        flash('Ο αθλητής δημιουργήθηκε.','success'); redirect(APP_URL.'/employee/athletes.php');
    }

    if ($act === 'edit' && $canEdit) {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE athletes SET full_name=?,father_name=?,mother_name=?,birthdate=?,phone=?,parent_phone=?,email=?,monthly_fee=? WHERE id=?")
           ->execute([trim($_POST['full_name']??''),trim($_POST['father_name']??''),trim($_POST['mother_name']??''),
             $_POST['birthdate']??null,
             trim($_POST['phone']??''),trim($_POST['parent_phone']??''),trim($_POST['email']??''),(float)($_POST['monthly_fee']??0),$id]);
        auditLog('employee_athlete_edit','athletes',$id,'Employee edited athlete');
        flash('Ο αθλητής ενημερώθηκε.','success'); redirect(APP_URL.'/employee/athletes.php');
    }

    if ($act === 'delete' && $canDelete) {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE athletes SET active=0 WHERE id=?")->execute([$id]);
        auditLog('employee_athlete_delete','athletes',$id,'Employee deactivated athlete');
        flash('Ο αθλητής απενεργοποιήθηκε.','success'); redirect(APP_URL.'/employee/athletes.php');
    }
    redirect(APP_URL.'/employee/athletes.php');
}

// ── Filters ──────────────────────────────────────────────────
$search    = trim($_GET['q']    ?? '');
$active    = $_GET['active']    ?? '1';
$school_id = (int)($_GET['school_id'] ?? 0);
$debt      = $_GET['debt']      ?? '';
$perPage   = 30; $page=max(1,(int)($_GET['page']??1)); $offset=($page-1)*$perPage;

$where=[]; $params=[];
if ($search)   { $where[]='(a.full_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ? OR a.parent_phone LIKE ?)'; $like="%$search%"; $params=array_merge($params,[$like,$like,$like,$like]); }
if ($active!==''){ $where[]='a.active = ?'; $params[]=(int)$active; }
if ($school_id){ $where[]='a.school_id = ?'; $params[]=$school_id; }
if ($debt==='1'){ $where[]='a.debt_from_month IS NOT NULL'; }
$whereSQL=$where?'WHERE '.implode(' AND ',$where):'';

$stmtC=$db->prepare("SELECT COUNT(*) FROM athletes a $whereSQL"); $stmtC->execute($params); $totalRows=(int)$stmtC->fetchColumn();
$totalPages=max(1,ceil($totalRows/$perPage));
$stmt=$db->prepare("SELECT a.*,s.name as school_name FROM athletes a LEFT JOIN schools s ON s.id=a.school_id $whereSQL ORDER BY a.full_name LIMIT $perPage OFFSET $offset");
$stmt->execute($params); $athletes=$stmt->fetchAll();
$schools=$db->query("SELECT id,name FROM schools WHERE active=1 ORDER BY name")->fetchAll();

renderEmpHead('Αθλητές');
?><body>
<?php renderEmpSidebar('athletes'); ?>
<div class="emp-main">
<?php renderEmpTopbar('Αθλητές'); ?>
<div class="emp-content">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
  <div>
    <div class="section-title">Αθλητές</div>
    <div class="section-sub">Επισκόπηση αθλητών από όλες τις σχολές<?= $canEdit||$canCreate||$canDelete?' · Έχεις δικαιώματα επεξεργασίας':' · Read-only' ?>.</div>
  </div>
  <?php if($canCreate): ?>
    <button class="btn btn-primary" onclick="openModal('modalCreate')"><i class="fa-solid fa-plus"></i> Νέος Αθλητής</button>
  <?php endif; ?>
</div>

<form method="get" class="card" style="padding:1rem 1.4rem">
  <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
    <div class="form-group" style="margin:0;flex:1;min-width:160px"><label>Αναζήτηση</label>
      <div class="search-input-wrap"><i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="form-control" placeholder="Όνομα, email, τηλ…" value="<?= h($search) ?>"></div></div>
    <div class="form-group" style="margin:0;min-width:120px"><label>Κατάσταση</label>
      <select name="active" class="form-control"><option value="">Όλοι</option>
        <option value="1" <?= $active==='1'?'selected':'' ?>>Ενεργοί</option>
        <option value="0" <?= $active==='0'?'selected':'' ?>>Ανενεργοί</option></select></div>
    <div class="form-group" style="margin:0;min-width:160px"><label>Σχολή</label>
      <select name="school_id" class="form-control"><option value="0">Όλες</option>
        <?php foreach($schools as $sc): ?><option value="<?= $sc['id'] ?>" <?= $school_id==$sc['id']?'selected':'' ?>><?= h($sc['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group" style="margin:0;min-width:110px"><label>Χρέος</label>
      <select name="debt" class="form-control"><option value="">Όλοι</option>
        <option value="1" <?= $debt==='1'?'selected':'' ?>>Με χρέος</option></select></div>
    <div style="display:flex;gap:.5rem">
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Φίλτρο</button>
      <a href="?" class="btn btn-ghost">Reset</a>
    </div>
  </div>
</form>

<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:.9rem 1.4rem;border-bottom:1px solid var(--border)">
    <span style="font-size:.88rem;color:var(--muted)"><?= number_format($totalRows) ?> αθλητές</span>
  </div>
  <div class="tbl-wrap"><table>
    <thead><tr>
      <th>#</th><th>Αθλητής</th><th>Σχολή</th><th>Τηλ.</th><th>Χρέος</th><th>Μηνιαίο</th><th>Κατ.</th><th>Εγγραφή</th>
      <th>Ενέργειες</th>
    </tr></thead>
    <tbody>
    <?php if(empty($athletes)): ?>
      <tr><td colspan="11" style="text-align:center;padding:2rem;color:var(--muted)">Δεν βρέθηκαν αθλητές.</td></tr>
    <?php endif; ?>
    <?php foreach($athletes as $a): ?>
      <tr>
        <td style="color:var(--muted);font-size:.8rem"><?= $a['id'] ?></td>
        <td><div style="font-weight:600;font-size:.88rem"><?= h($a['full_name']) ?></div>
            <?php if($a['email']): ?><div style="font-size:.75rem;color:var(--muted)"><?= h($a['email']) ?></div><?php endif; ?></td>
        <td style="font-size:.82rem;color:var(--muted)"><?= h($a['school_name']??'—') ?></td>
        <td style="font-size:.82rem"><?= h($a['phone']?:($a['parent_phone']??'—')) ?></td>
        <td><?php if($a['debt_from_month']): ?><span class="badge badge-red"><i class="fa-solid fa-triangle-exclamation"></i> <?= h($a['debt_from_month']) ?></span>
            <?php else: ?><span style="color:var(--muted);font-size:.8rem">—</span><?php endif; ?></td>
        <td style="font-size:.85rem"><?= $a['monthly_fee']>0?'€'.number_format($a['monthly_fee'],2):'—' ?></td>
        <td><span class="badge <?= $a['active']?'badge-green':'badge-red' ?>"><?= $a['active']?'Ενεργός':'Ανενεργός' ?></span></td>
        <td style="font-size:.78rem;color:var(--muted)"><?= date('d/m/Y',strtotime($a['registration_date']?:$a['created_at'])) ?></td>
        <td style="white-space:nowrap">
          <!-- PREVIEW -->
          <button class="btn btn-ghost" style="padding:.35rem .6rem;font-size:.8rem" title="Προβολή"
            onclick='openPreview(<?= json_encode([
              'id'=>$a['id'],'full_name'=>$a['full_name'],'email'=>$a['email']??'',
              'phone'=>$a['phone']??'','parent_phone'=>$a['parent_phone']??'',
              'school_name'=>$a['school_name']??'',
              'monthly_fee'=>$a['monthly_fee']??0,
              'birthdate'=>$a['birthdate']??'',
              'father_name'=>$a['father_name']??'',
              'mother_name'=>$a['mother_name']??'',
              'active'=>$a['active'],
              'debt_from_month'=>$a['debt_from_month']??'',
              'registration_date'=>$a['registration_date']??$a['created_at'],
            ]) ?>)'>
            <i class="fa-solid fa-eye"></i></button>
          <?php if($canEdit): ?>
            <button class="btn btn-ghost" style="padding:.35rem .6rem;font-size:.8rem" title="Επεξεργασία"
              onclick='openEdit(<?= json_encode([
                'id'=>$a['id'],'full_name'=>$a['full_name'],'father_name'=>$a['father_name']??'',
                'mother_name'=>$a['mother_name']??'','birthdate'=>$a['birthdate']??'',
                'monthly_fee'=>$a['monthly_fee']??0,
                'phone'=>$a['phone']??'',
                'parent_phone'=>$a['parent_phone']??'','email'=>$a['email']??'',
              ]) ?>)'>
              <i class="fa-solid fa-pen"></i></button>
          <?php endif; ?>
          <?php if($canDelete && $a['active']): ?>
            <button class="btn btn-red" style="padding:.35rem .6rem;font-size:.8rem" title="Απενεργοποίηση"
              onclick="openDelete(<?= $a['id'] ?>,'<?= h(addslashes($a['full_name'])) ?>')">
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
.emp-modal{background:#111520;border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:1.8rem;width:100%;max-width:580px;box-shadow:0 30px 80px rgba(0,0,0,.6);margin:auto}
.emp-modal h3{margin:0 0 1.2rem;font-size:1.1rem;font-weight:800;display:flex;align-items:center;gap:.6rem}
.modal-footer{display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem}
.two-col-form{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.pv-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem 1.4rem;margin-bottom:1rem}
.pv-item label{display:block;font-size:.72rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem}
.pv-item .pv-val{font-size:.88rem;color:#fff;word-break:break-word}
.pv-section-title{font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);border-bottom:1px solid rgba(255,255,255,.07);padding-bottom:.4rem;margin:.6rem 0 .5rem;grid-column:1/-1}
</style>

<!-- PREVIEW MODAL -->
<div class="emp-modal-bg" id="modalPreview">
  <div class="emp-modal" style="max-width:600px">
    <h3><i class="fa-solid fa-person-running" style="color:var(--green)"></i> <span id="pvTitle">Αθλητής</span></h3>
    <div class="pv-grid">
      <div class="pv-item"><label>Email</label><div class="pv-val" id="pvEmail">—</div></div>
      <div class="pv-item"><label>Κατάσταση</label><div class="pv-val" id="pvActive">—</div></div>
      <div class="pv-item"><label>Τηλέφωνο αθλητή</label><div class="pv-val" id="pvPhone">—</div></div>
      <div class="pv-item"><label>Τηλ. Γονέα</label><div class="pv-val" id="pvParent">—</div></div>
      <div class="pv-section-title">Αθλητικά Στοιχεία</div>
      <div class="pv-item"><label>Σχολή</label><div class="pv-val" id="pvSchool">—</div></div>
      <div class="pv-item"><label>Μηνιαίο</label><div class="pv-val" id="pvFee">—</div></div>
      <div class="pv-item"><label>Χρέος από</label><div class="pv-val" id="pvDebt">—</div></div>
      <div class="pv-item"><label>Εγγραφή</label><div class="pv-val" id="pvReg">—</div></div>
      <div class="pv-section-title">Προσωπικά Στοιχεία</div>
      <div class="pv-item"><label>Ημ. Γέννησης</label><div class="pv-val" id="pvBirth">—</div></div>
      <div class="pv-item"></div>
      <div class="pv-item"><label>Όνομα Πατρός</label><div class="pv-val" id="pvFather">—</div></div>
      <div class="pv-item"><label>Όνομα Μητρός</label><div class="pv-val" id="pvMother">—</div></div>
    </div>
    <div class="modal-footer">
      <?php if ($canEdit): ?>
        <button type="button" class="btn btn-ghost" onclick="switchToEdit()"><i class="fa-solid fa-pen"></i> Επεξεργασία</button>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost" onclick="closeModal('modalPreview')">Κλείσιμο</button>
    </div>
  </div>
</div>

<?php if($canCreate): ?>
<!-- CREATE MODAL -->
<div class="emp-modal-bg" id="modalCreate"><div class="emp-modal">
  <h3><i class="fa-solid fa-plus" style="color:var(--green)"></i> Νέος Αθλητής</h3>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="_action" value="create">
    <div class="form-group"><label>Σχολή *</label>
      <select name="school_id" class="form-control" required><option value="">— Επιλογή —</option>
        <?php foreach($schools as $sc): ?><option value="<?= $sc['id'] ?>"><?= h($sc['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group"><label>Ονοματεπώνυμο *</label><input type="text" name="full_name" class="form-control" required></div>
    <div class="two-col-form">
      <div class="form-group"><label>Όνομα Πατρός</label><input type="text" name="father_name" class="form-control"></div>
      <div class="form-group"><label>Όνομα Μητρός</label><input type="text" name="mother_name" class="form-control"></div>
    </div>
    <div class="two-col-form">
      <div class="form-group"><label>Ημ. Γέννησης</label><input type="date" name="birthdate" class="form-control"></div>
      <div class="form-group"><label>Μηνιαίο (€)</label><input type="number" name="monthly_fee" class="form-control" value="0" min="0" step="0.01"></div>
    </div>
    <div class="two-col-form">
      <div class="form-group"><label>Τηλέφωνο</label><input type="text" name="phone" class="form-control"></div>
      <div class="form-group"><label>Τηλ. Γονέα</label><input type="text" name="parent_phone" class="form-control"></div>
    </div>
    <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control"></div>
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
  <h3><i class="fa-solid fa-pen" style="color:var(--blue)"></i> Επεξεργασία Αθλητή</h3>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="_action" value="edit"><input type="hidden" name="id" id="editId">
    <div class="form-group"><label>Ονοματεπώνυμο *</label><input type="text" name="full_name" id="editName" class="form-control" required></div>
    <div class="two-col-form">
      <div class="form-group"><label>Όνομα Πατρός</label><input type="text" name="father_name" id="editFather" class="form-control"></div>
      <div class="form-group"><label>Όνομα Μητρός</label><input type="text" name="mother_name" id="editMother" class="form-control"></div>
    </div>
    <div class="two-col-form">
      <div class="form-group"><label>Ημ. Γέννησης</label><input type="date" name="birthdate" id="editBirth" class="form-control"></div>
      <div class="form-group"><label>Μηνιαίο (€)</label><input type="number" name="monthly_fee" id="editFee" class="form-control" min="0" step="0.01"></div>
    </div>
    <div class="two-col-form">
      <div class="form-group"><label>Τηλέφωνο</label><input type="text" name="phone" id="editPhone" class="form-control"></div>
      <div class="form-group"><label>Τηλ. Γονέα</label><input type="text" name="parent_phone" id="editParentPhone" class="form-control"></div>
    </div>
    <div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" class="form-control"></div>
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
  <h3><i class="fa-solid fa-triangle-exclamation" style="color:var(--red)"></i> Απενεργοποίηση Αθλητή</h3>
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

function openPreview(a) {
  _pvCurrent = a;
  document.getElementById('pvTitle').textContent  = a.full_name || '—';
  document.getElementById('pvEmail').textContent  = a.email     || '—';
  document.getElementById('pvPhone').textContent  = a.phone     || '—';
  document.getElementById('pvParent').textContent = a.parent_phone || '—';
  document.getElementById('pvActive').textContent = a.active==1 ? 'Ενεργός' : 'Ανενεργός';
  document.getElementById('pvSchool').textContent = a.school_name || '—';
  document.getElementById('pvFee').textContent    = a.monthly_fee > 0 ? '€' + parseFloat(a.monthly_fee).toFixed(2) : '—';
  document.getElementById('pvDebt').textContent   = a.debt_from_month || '—';
  document.getElementById('pvReg').textContent    = a.registration_date || '—';
  document.getElementById('pvBirth').textContent  = a.birthdate  || '—';
  document.getElementById('pvFather').textContent = a.father_name || '—';
  document.getElementById('pvMother').textContent = a.mother_name || '—';
  openModal('modalPreview');
}

function switchToEdit() {
  closeModal('modalPreview');
  openEdit(_pvCurrent);
}

function openEdit(a) {
  document.getElementById('editId').value           = a.id;
  document.getElementById('editName').value         = a.full_name    || '';
  document.getElementById('editFather').value       = a.father_name  || '';
  document.getElementById('editMother').value       = a.mother_name  || '';
  document.getElementById('editBirth').value        = a.birthdate    || '';
  document.getElementById('editFee').value          = a.monthly_fee  || 0;
  document.getElementById('editPhone').value        = a.phone        || '';
  document.getElementById('editParentPhone').value  = a.parent_phone || '';
  document.getElementById('editEmail').value        = a.email        || '';
  openModal('modalEdit');
}

function openDelete(id, name) {
  document.getElementById('deleteId').value         = id;
  document.getElementById('deleteName').textContent = name;
  openModal('modalDelete');
}
</script>

<?php renderEmpClose(); ?>