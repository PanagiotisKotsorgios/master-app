<?php
/**
 * employee/schools.php — Σχολές
 */
ini_set('log_errors',1); ini_set('error_log',__DIR__.'/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors',0);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/privileges.php';
require_once __DIR__.'/layout.php';

empRequire('schools_view');

$db = getDB();
$canEdit        = empCan('schools_edit');
$canCreate      = empCan('schools_create');
$canDelete      = empCan('schools_delete');
$canImpersonate = empCan('schools_impersonate');

// ── Impersonate ───────────────────────────────────────────────
if (isset($_GET['impersonate']) && $canImpersonate) {
    $sid    = (int)$_GET['impersonate'];
    $school = $db->prepare("SELECT * FROM schools WHERE id=? LIMIT 1");
    $school->execute([$sid]);
    $sc2 = $school->fetch();
    $owner = $db->prepare("SELECT * FROM users WHERE school_id=? AND role='owner' LIMIT 1");
    $owner->execute([$sid]);
    $u2 = $owner->fetch();
    if ($sc2 && $u2) {
        $_SESSION['user_id']       = $u2['id'];
        $_SESSION['school_id']     = $u2['school_id'];
        $_SESSION['school_name']   = $sc2['name'];
        $_SESSION['user']          = ['id'=>$u2['id'],'name'=>$u2['name'],'email'=>$u2['email'],'role'=>$u2['role']];
        $_SESSION['impersonating'] = true;
        flash('Impersonating '.$sc2['name'].' — <a href="'.APP_URL.'/employee/schools.php">Επιστροφή</a>');
        redirect(APP_URL.'/dashboard/');
    }
    flash('Δεν βρέθηκε owner για αυτή τη σχολή.','danger');
    redirect(APP_URL.'/employee/schools.php');
}

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['_action'] ?? '';

    if ($act === 'create' && $canCreate) {
        $stmt = $db->prepare("INSERT INTO schools (name,email,phone,city,address,afm,active,created_at) VALUES (?,?,?,?,?,?,1,NOW())");
        $stmt->execute([
            trim($_POST['name'] ?? ''), trim($_POST['email'] ?? ''),
            trim($_POST['phone'] ?? ''), trim($_POST['city'] ?? ''),
            trim($_POST['address'] ?? ''), trim($_POST['afm'] ?? ''),
        ]);
        auditLog('employee_school_create','schools',(int)$db->lastInsertId(),'Employee created school');
        flash('Η σχολή δημιουργήθηκε.','success'); redirect(APP_URL.'/employee/schools.php');
    }

    if ($act === 'edit' && $canEdit) {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE schools SET name=?,email=?,phone=?,city=?,address=?,afm=? WHERE id=?");
        $stmt->execute([
            trim($_POST['name'] ?? ''), trim($_POST['email'] ?? ''),
            trim($_POST['phone'] ?? ''), trim($_POST['city'] ?? ''),
            trim($_POST['address'] ?? ''), trim($_POST['afm'] ?? ''), $id,
        ]);
        auditLog('employee_school_edit','schools',$id,'Employee edited school');
        flash('Η σχολή ενημερώθηκε.','success'); redirect(APP_URL.'/employee/schools.php');
    }

    if ($act === 'delete' && $canDelete) {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE schools SET active=0 WHERE id=?")->execute([$id]);
        auditLog('employee_school_delete','schools',$id,'Employee deactivated school');
        flash('Η σχολή απενεργοποιήθηκε.','success'); redirect(APP_URL.'/employee/schools.php');
    }
    redirect(APP_URL.'/employee/schools.php');
}

// ── Filters ──────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$status  = $_GET['status']      ?? '';
$plan_st = $_GET['plan_status'] ?? '';
$plan_id = (int)($_GET['plan_id'] ?? 0);
$perPage = 25; $page = max(1,(int)($_GET['page'] ?? 1)); $offset = ($page-1)*$perPage;

$where=['s.active = 1']; $params=[];
if ($search) { $where[]='(s.name LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.city LIKE ?)'; $like="%$search%"; $params=array_merge($params,[$like,$like,$like,$like]); }
if ($status) { $where[]='s.subscription_status = ?'; $params[]=$status; }
if ($plan_st){ $where[]='s.plan_status = ?'; $params[]=$plan_st; }
if ($plan_id){ $where[]='s.plan_id = ?'; $params[]=$plan_id; }
$whereSQL = 'WHERE '.implode(' AND ',$where);

$stmtC = $db->prepare("SELECT COUNT(*) FROM schools s $whereSQL"); $stmtC->execute($params); $totalRows=(int)$stmtC->fetchColumn();
$totalPages = max(1,ceil($totalRows/$perPage));

$stmt = $db->prepare("SELECT s.*,p.name as plan_name,(SELECT COUNT(*) FROM users u WHERE u.school_id=s.id AND u.active=1) as user_count,(SELECT COUNT(*) FROM athletes a WHERE a.school_id=s.id AND a.active=1) as athlete_count FROM schools s LEFT JOIN plans p ON p.id=s.plan_id $whereSQL ORDER BY s.name LIMIT $perPage OFFSET $offset");
$stmt->execute($params); $schools=$stmt->fetchAll();
$plans = $db->query("SELECT id,name FROM plans ORDER BY name")->fetchAll();

// ── Pre-encode ALL school data into a JS array (safe, no inline onclick breakage) ──
$schoolsJs = [];
foreach ($schools as $sc) {
    $schoolsJs[$sc['id']] = [
        'id'                  => (int)$sc['id'],
        'name'                => $sc['name'] ?? '',
        'email'               => $sc['email'] ?? '',
        'phone'               => $sc['phone'] ?? '',
        'city'                => $sc['city'] ?? '',
        'address'             => $sc['address'] ?? '',
        'afm'                 => $sc['afm'] ?? '',
        'plan_name'           => $sc['plan_name'] ?? '',
        'plan_status'         => $sc['plan_status'] ?? '',
        'subscription_status' => $sc['subscription_status'] ?? '',
        'trial_ends'          => $sc['trial_ends'] ?? '',
        'user_count'          => (int)$sc['user_count'],
        'athlete_count'       => (int)$sc['athlete_count'],
    ];
}

renderEmpHead('Σχολές');
?>
<body>
<?php renderEmpSidebar('schools'); ?>
<div class="emp-main">
<?php renderEmpTopbar('Σχολές'); ?>
<div class="emp-content">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
  <div>
    <div class="section-title">Σχολές</div>
    <div class="section-sub">Επισκόπηση εγγεγραμμένων σχολών<?= $canEdit||$canCreate||$canDelete ? ' · Έχεις δικαιώματα επεξεργασίας' : ' · Read-only' ?>.</div>
  </div>
  <?php if ($canCreate): ?>
  <button class="btn btn-primary" type="button" data-emp-open="modalCreate"><i class="fa-solid fa-plus"></i> Νέα Σχολή</button>
  <?php endif; ?>
</div>

<!-- Filters -->
<form method="get" class="card" style="padding:1rem 1.4rem">
  <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
    <div class="form-group" style="margin:0;flex:1;min-width:180px">
      <label>Αναζήτηση</label>
      <div class="search-input-wrap"><i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="form-control" placeholder="Όνομα, email, πόλη…" value="<?= h($search) ?>">
      </div>
    </div>
    <div class="form-group" style="margin:0;min-width:150px">
      <label>Sub. Status</label>
      <select name="status" class="form-control">
        <option value="">Όλα</option>
        <?php foreach(['active','trial','past_due','suspended','cancelled'] as $v): ?>
          <option value="<?= $v ?>" <?= $status===$v?'selected':'' ?>><?= ucfirst($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:140px">
      <label>Plan Status</label>
      <select name="plan_status" class="form-control">
        <option value="">Όλα</option>
        <?php foreach(['active','trial','expired','suspended'] as $v): ?>
          <option value="<?= $v ?>" <?= $plan_st===$v?'selected':'' ?>><?= ucfirst($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:140px">
      <label>Πλάνο</label>
      <select name="plan_id" class="form-control">
        <option value="0">Όλα</option>
        <?php foreach($plans as $pl): ?>
          <option value="<?= $pl['id'] ?>" <?= $plan_id==$pl['id']?'selected':'' ?>><?= h($pl['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:.5rem">
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Φίλτρο</button>
      <a href="?" class="btn btn-ghost">Reset</a>
    </div>
  </div>
</form>

<!-- Table -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:.9rem 1.4rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:.88rem;color:var(--muted)"><?= number_format($totalRows) ?> σχολές</span>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>#</th><th>Σχολή</th><th>Πόλη</th><th>Email / Τηλ.</th>
        <th>Πλάνο</th><th>Sub. Status</th><th>Plan Status</th>
        <th>Χρήστες</th><th>Αθλητές</th><th>Trial Λήξη</th>
        <th>Ενέργειες</th>
      </tr></thead>
      <tbody>
      <?php if (empty($schools)): ?>
        <tr><td colspan="11" style="text-align:center;padding:2rem;color:var(--muted)">Δεν βρέθηκαν σχολές.</td></tr>
      <?php endif; ?>
      <?php foreach($schools as $sc): ?>
        <?php $trialDisplay = $sc['trial_ends'] ? date('d/m/Y', strtotime($sc['trial_ends'])) : '—'; ?>
        <tr
          data-school-row="1"
          data-id="<?= (int)$sc['id'] ?>"
          data-name="<?= h($sc['name'] ?? '') ?>"
          data-email="<?= h($sc['email'] ?? '') ?>"
          data-phone="<?= h($sc['phone'] ?? '') ?>"
          data-city="<?= h($sc['city'] ?? '') ?>"
          data-address="<?= h($sc['address'] ?? '') ?>"
          data-afm="<?= h($sc['afm'] ?? '') ?>"
          data-plan-name="<?= h($sc['plan_name'] ?? '') ?>"
          data-plan-status="<?= h($sc['plan_status'] ?? '') ?>"
          data-subscription-status="<?= h($sc['subscription_status'] ?? '') ?>"
          data-trial="<?= h($trialDisplay) ?>"
          data-user-count="<?= (int)$sc['user_count'] ?>"
          data-athlete-count="<?= (int)$sc['athlete_count'] ?>"
        >
          <td style="color:var(--muted);font-size:.8rem"><?= $sc['id'] ?></td>
          <td>
            <div style="font-weight:600;font-size:.9rem"><?= h($sc['name']) ?></div>
            <?php if($sc['afm']): ?><div style="font-size:.75rem;color:var(--muted)">ΑΦΜ: <?= h($sc['afm']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:.85rem"><?= h($sc['city']??'—') ?></td>
          <td>
            <div style="font-size:.82rem"><?= h($sc['email']??'—') ?></div>
            <div style="font-size:.78rem;color:var(--muted)"><?= h($sc['phone']??'') ?></div>
          </td>
          <td style="font-size:.85rem"><?= h($sc['plan_name']??'—') ?></td>
          <td><?php $bc=match($sc['subscription_status']){'active'=>'badge-green','trial'=>'badge-blue','past_due'=>'badge-gold','suspended'=>'badge-red','cancelled'=>'badge-muted',default=>'badge-muted'}; ?>
              <span class="badge <?= $bc ?>"><?= h($sc['subscription_status']) ?></span></td>
          <td><?php $ps=match($sc['plan_status']){'active'=>'badge-green','trial'=>'badge-blue','expired'=>'badge-red','suspended'=>'badge-red',default=>'badge-muted'}; ?>
              <span class="badge <?= $ps ?>"><?= h($sc['plan_status']??'—') ?></span></td>
          <td style="text-align:center"><?= $sc['user_count'] ?></td>
          <td style="text-align:center"><?= $sc['athlete_count'] ?></td>
          <td style="font-size:.8rem;color:var(--muted)"><?= h($trialDisplay) ?></td>
          <td style="white-space:nowrap">
            <!-- All data lives on the button itself — no closest() traversal needed -->
            <button type="button" class="btn btn-ghost" style="padding:.35rem .6rem;font-size:.8rem" title="Προβολή"
              data-emp-action="preview"
              data-id="<?= (int)$sc['id'] ?>"
              data-name="<?= h($sc['name'] ?? '') ?>"
              data-email="<?= h($sc['email'] ?? '') ?>"
              data-phone="<?= h($sc['phone'] ?? '') ?>"
              data-city="<?= h($sc['city'] ?? '') ?>"
              data-address="<?= h($sc['address'] ?? '') ?>"
              data-afm="<?= h($sc['afm'] ?? '') ?>"
              data-plan-name="<?= h($sc['plan_name'] ?? '') ?>"
              data-plan-status="<?= h($sc['plan_status'] ?? '') ?>"
              data-subscription-status="<?= h($sc['subscription_status'] ?? '') ?>"
              data-trial="<?= h($trialDisplay) ?>"
              data-user-count="<?= (int)$sc['user_count'] ?>"
              data-athlete-count="<?= (int)$sc['athlete_count'] ?>">
              <i class="fa-solid fa-eye"></i>
            </button>
            <?php if ($canEdit): ?>
              <button type="button" class="btn btn-ghost" style="padding:.35rem .6rem;font-size:.8rem" title="Επεξεργασία"
                data-emp-action="edit"
                data-id="<?= (int)$sc['id'] ?>"
                data-name="<?= h($sc['name'] ?? '') ?>"
                data-email="<?= h($sc['email'] ?? '') ?>"
                data-phone="<?= h($sc['phone'] ?? '') ?>"
                data-city="<?= h($sc['city'] ?? '') ?>"
                data-address="<?= h($sc['address'] ?? '') ?>"
                data-afm="<?= h($sc['afm'] ?? '') ?>">
                <i class="fa-solid fa-pen"></i>
              </button>
            <?php endif; ?>
            <?php if ($canImpersonate): ?>
              <a href="?impersonate=<?= $sc['id'] ?>"
                 class="btn btn-ghost" style="padding:.35rem .6rem;font-size:.8rem;color:var(--accent)" title="Impersonate"
                 onclick="return confirm('Login ως <?= h(addslashes($sc['name'])) ?>?')">
                <i class="fa-solid fa-user-secret"></i>
              </a>
            <?php endif; ?>
            <?php if ($canDelete): ?>
              <button type="button" class="btn btn-red" style="padding:.35rem .6rem;font-size:.8rem" title="Απενεργοποίηση"
                data-emp-action="delete"
                data-id="<?= (int)$sc['id'] ?>"
                data-name="<?= h($sc['name'] ?? '') ?>">
                <i class="fa-solid fa-trash"></i>
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages>1): ?>
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

<!-- ═══════════════════════════════════════════
     MODALS — inside .emp-main so z-index/fixed works correctly
     ═══════════════════════════════════════════ -->
<style>
.emp-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;align-items:center;justify-content:center;padding:1rem;overflow-y:auto}
.emp-modal-bg.open{display:flex!important}
.emp-modal{background:#111520;border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:1.8rem;width:100%;max-width:540px;box-shadow:0 30px 80px rgba(0,0,0,.6);margin:auto}
.emp-modal h3{margin:0 0 1.2rem;font-size:1.1rem;font-weight:800;display:flex;align-items:center;gap:.6rem}
.modal-footer{display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem}
.pv-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1.4rem;margin-bottom:1rem}
.pv-item label{display:block;font-size:.72rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.22rem}
.pv-item .pv-val{font-size:.9rem;color:#fff;word-break:break-word}
.pv-section-title{font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);border-bottom:1px solid rgba(255,255,255,.07);padding-bottom:.4rem;margin:1rem 0 .6rem;grid-column:1/-1}
</style>

<!-- PREVIEW MODAL -->
<div class="emp-modal-bg" id="modalPreview">
  <div class="emp-modal" style="max-width:600px">
    <h3><i class="fa-solid fa-school" style="color:var(--blue)"></i> <span id="pvTitle">Σχολή</span></h3>
    <div class="pv-grid">
      <div class="pv-item"><label>Email</label><div class="pv-val" id="pvEmail">—</div></div>
      <div class="pv-item"><label>Τηλέφωνο</label><div class="pv-val" id="pvPhone">—</div></div>
      <div class="pv-item"><label>Πόλη</label><div class="pv-val" id="pvCity">—</div></div>
      <div class="pv-item"><label>ΑΦΜ</label><div class="pv-val" id="pvAfm">—</div></div>
      <div class="pv-item" style="grid-column:1/-1"><label>Διεύθυνση</label><div class="pv-val" id="pvAddress">—</div></div>
      <div class="pv-section-title">Συνδρομή</div>
      <div class="pv-item"><label>Πλάνο</label><div class="pv-val" id="pvPlan">—</div></div>
      <div class="pv-item"><label>Plan Status</label><div class="pv-val" id="pvPlanStatus">—</div></div>
      <div class="pv-item"><label>Sub. Status</label><div class="pv-val" id="pvSubStatus">—</div></div>
      <div class="pv-item"><label>Trial Λήξη</label><div class="pv-val" id="pvTrial">—</div></div>
      <div class="pv-section-title">Στατιστικά</div>
      <div class="pv-item"><label><i class="fa-solid fa-users"></i> Χρήστες</label><div class="pv-val" id="pvUsers">—</div></div>
      <div class="pv-item"><label><i class="fa-solid fa-person-running"></i> Αθλητές</label><div class="pv-val" id="pvAthletes">—</div></div>
    </div>
    <div class="modal-footer">
      <?php if ($canEdit): ?>
        <button type="button" class="btn btn-ghost" id="pvEditBtn" data-emp-preview-edit="1"><i class="fa-solid fa-pen"></i> Επεξεργασία</button>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost" data-emp-close="modalPreview">Κλείσιμο</button>
    </div>
  </div>
</div>

<?php if ($canCreate): ?>
<!-- CREATE MODAL -->
<div class="emp-modal-bg" id="modalCreate">
  <div class="emp-modal">
    <h3><i class="fa-solid fa-plus" style="color:var(--green)"></i> Νέα Σχολή</h3>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="create">
      <div class="form-group"><label>Όνομα *</label><input type="text" name="name" class="form-control" required></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control"></div>
      <div class="form-group"><label>Τηλέφωνο</label><input type="text" name="phone" class="form-control"></div>
      <div class="form-group"><label>Πόλη</label><input type="text" name="city" class="form-control"></div>
      <div class="form-group"><label>Διεύθυνση</label><input type="text" name="address" class="form-control"></div>
      <div class="form-group"><label>ΑΦΜ</label><input type="text" name="afm" class="form-control"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-emp-close="modalCreate">Ακύρωση</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Δημιουργία</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<!-- EDIT MODAL -->
<div class="emp-modal-bg" id="modalEdit">
  <div class="emp-modal">
    <h3><i class="fa-solid fa-pen" style="color:var(--blue)"></i> Επεξεργασία Σχολής</h3>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="edit">
      <input type="hidden" name="id" id="editId">
      <div class="form-group"><label>Όνομα *</label><input type="text" name="name" id="editName" class="form-control" required></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" class="form-control"></div>
      <div class="form-group"><label>Τηλέφωνο</label><input type="text" name="phone" id="editPhone" class="form-control"></div>
      <div class="form-group"><label>Πόλη</label><input type="text" name="city" id="editCity" class="form-control"></div>
      <div class="form-group"><label>Διεύθυνση</label><input type="text" name="address" id="editAddress" class="form-control"></div>
      <div class="form-group"><label>ΑΦΜ</label><input type="text" name="afm" id="editAfm" class="form-control"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-emp-close="modalEdit">Ακύρωση</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Αποθήκευση</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canDelete): ?>
<!-- DELETE MODAL -->
<div class="emp-modal-bg" id="modalDelete">
  <div class="emp-modal">
    <h3><i class="fa-solid fa-triangle-exclamation" style="color:var(--red)"></i> Απενεργοποίηση Σχολής</h3>
    <p style="color:var(--muted);margin-bottom:1.2rem">Είσαι σίγουρος ότι θέλεις να απενεργοποιήσεις τη σχολή <strong id="deleteName" style="color:#fff"></strong>;</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="delete">
      <input type="hidden" name="id" id="deleteId">
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-emp-close="modalDelete">Ακύρωση</button>
        <button type="submit" class="btn btn-red"><i class="fa-solid fa-trash"></i> Απενεργοποίηση</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

</div><!-- /.emp-content -->
</div><!-- /.emp-main -->


<?php renderEmpClose(); ?>