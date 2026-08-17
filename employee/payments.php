<?php
/**
 * employee/payments.php — Πληρωμές (View + Create + Edit + Delete gated by privileges)
 */
ini_set('log_errors',1); ini_set('error_log',__DIR__.'/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors',0);

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/privileges.php';
require_once __DIR__.'/layout.php';

empRequire('payments_view');

$db = getDB();
$canEdit   = empCan('payments_edit');
$canCreate = empCan('payments_create');
$canDelete = empCan('payments_delete');

$schools = $db->query("SELECT id,name FROM schools WHERE active=1 ORDER BY name")->fetchAll();
$plans   = $db->query("SELECT id,name FROM plans ORDER BY name")->fetchAll();

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['_action'] ?? '';

    if ($act === 'create' && $canCreate) {
        $db->prepare("INSERT INTO school_plan_payments (school_id,plan_id,amount,method,paid_at) VALUES (?,?,?,?,NOW())")
           ->execute([(int)$_POST['school_id'],(int)$_POST['plan_id'],(float)$_POST['amount'],trim($_POST['method']??'card')]);
        auditLog('employee_payment_create','school_plan_payments',(int)$db->lastInsertId(),'Employee recorded payment');
        flash('Η πληρωμή καταχωρήθηκε.','success'); redirect(APP_URL.'/employee/payments.php');
    }

    if ($act === 'edit' && $canEdit) {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE school_plan_payments SET school_id=?,plan_id=?,amount=?,method=? WHERE id=?")
           ->execute([(int)$_POST['school_id'],(int)$_POST['plan_id'],(float)$_POST['amount'],trim($_POST['method']??'card'),$id]);
        auditLog('employee_payment_edit','school_plan_payments',$id,'Employee edited payment');
        flash('Η πληρωμή ενημερώθηκε.','success'); redirect(APP_URL.'/employee/payments.php');
    }

    if ($act === 'delete' && $canDelete) {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM school_plan_payments WHERE id=?")->execute([$id]);
        auditLog('employee_payment_delete','school_plan_payments',$id,'Employee deleted payment');
        flash('Η πληρωμή διαγράφηκε.','success'); redirect(APP_URL.'/employee/payments.php');
    }
    redirect(APP_URL.'/employee/payments.php');
}

// ── Filters ──────────────────────────────────────────────────
$search    = trim($_GET['q']    ?? '');
$school_id = (int)($_GET['school_id'] ?? 0);
$from      = $_GET['from'] ?? '';
$to        = $_GET['to']   ?? '';
$perPage   = 30; $page=max(1,(int)($_GET['page']??1)); $offset=($page-1)*$perPage;

$where=[]; $params=[];
if ($search)   { $where[]='s.name LIKE ?'; $params[]="%$search%"; }
if ($school_id){ $where[]='spp.school_id = ?'; $params[]=$school_id; }
if ($from)     { $where[]='spp.paid_at >= ?'; $params[]=$from.' 00:00:00'; }
if ($to)       { $where[]='spp.paid_at <= ?'; $params[]=$to.' 23:59:59'; }
$whereSQL=$where?'WHERE '.implode(' AND ',$where):'';

$stmtC=$db->prepare("SELECT COUNT(*),COALESCE(SUM(spp.amount),0) FROM school_plan_payments spp LEFT JOIN schools s ON s.id=spp.school_id $whereSQL");
$stmtC->execute($params); [$totalRows,$totalAmount]=$stmtC->fetch(PDO::FETCH_NUM);
$totalPages=max(1,ceil($totalRows/$perPage));
$stmt=$db->prepare("SELECT spp.*,s.name as school_name,p.name as plan_name FROM school_plan_payments spp LEFT JOIN schools s ON s.id=spp.school_id LEFT JOIN plans p ON p.id=spp.plan_id $whereSQL ORDER BY spp.paid_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params); $rows=$stmt->fetchAll();

renderEmpHead('Πληρωμές');
?><body>
<?php renderEmpSidebar('payments'); ?>
<div class="emp-main">
<?php renderEmpTopbar('Πληρωμές'); ?>
<div class="emp-content">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
  <div>
    <div class="section-title">Πληρωμές Σχολών</div>
    <div class="section-sub">Ιστορικό πληρωμών πλάνων<?= $canEdit||$canCreate||$canDelete?' · Έχεις δικαιώματα επεξεργασίας':' · Read-only' ?>.</div>
  </div>
  <?php if($canCreate): ?>
    <button class="btn btn-primary" onclick="openModal('modalCreate')"><i class="fa-solid fa-plus"></i> Νέα Πληρωμή</button>
  <?php endif; ?>
</div>

<!-- Summary -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem">
  <div class="stat-card"><div class="stat-label"><i class="fa-solid fa-receipt" style="color:var(--blue)"></i> Συναλλαγές (φίλτρο)</div><div class="stat-val"><?= number_format($totalRows) ?></div></div>
  <div class="stat-card"><div class="stat-label"><i class="fa-solid fa-euro-sign" style="color:var(--gold)"></i> Σύνολο (φίλτρο)</div><div class="stat-val">€<?= number_format($totalAmount,2) ?></div></div>
  <div class="stat-card"><div class="stat-label"><i class="fa-solid fa-calendar" style="color:var(--green)"></i> Τρέχουσα σελίδα</div><div class="stat-val"><?= count($rows) ?></div><div class="stat-sub">από <?= $totalRows ?></div></div>
</div>

<form method="get" class="card" style="padding:1rem 1.4rem">
  <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
    <div class="form-group" style="margin:0;flex:1;min-width:140px"><label>Αναζήτηση Σχολής</label>
      <div class="search-input-wrap"><i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="form-control" placeholder="Σχολή…" value="<?= h($search) ?>"></div></div>
    <div class="form-group" style="margin:0;min-width:160px"><label>Σχολή</label>
      <select name="school_id" class="form-control"><option value="0">Όλες</option>
        <?php foreach($schools as $sc): ?><option value="<?= $sc['id'] ?>" <?= $school_id==$sc['id']?'selected':'' ?>><?= h($sc['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group" style="margin:0;min-width:130px"><label>Από</label><input type="date" name="from" class="form-control" value="<?= h($from) ?>"></div>
    <div class="form-group" style="margin:0;min-width:130px"><label>Έως</label><input type="date" name="to" class="form-control" value="<?= h($to) ?>"></div>
    <div style="display:flex;gap:.5rem">
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Φίλτρο</button>
      <a href="?" class="btn btn-ghost">Reset</a>
    </div>
  </div>
</form>

<div class="card" style="padding:0;overflow:hidden">
  <div class="tbl-wrap"><table>
    <thead><tr>
      <th>#</th><th>Σχολή</th><th>Πλάνο</th><th>Ποσό</th><th>Μέθοδος</th><th>Ημερομηνία</th>
      <?php if($canEdit||$canDelete): ?><th>Ενέργειες</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php if(empty($rows)): ?>
      <tr><td colspan="<?= ($canEdit||$canDelete)?7:6 ?>" style="text-align:center;padding:2rem;color:var(--muted)">Δεν βρέθηκαν πληρωμές.</td></tr>
    <?php endif; ?>
    <?php foreach($rows as $r): ?>
      <tr>
        <td style="color:var(--muted);font-size:.8rem"><?= $r['id'] ?></td>
        <td style="font-size:.87rem;font-weight:600"><?= h($r['school_name']??'—') ?></td>
        <td style="font-size:.83rem;color:var(--muted)"><?= h($r['plan_name']??'—') ?></td>
        <td style="font-weight:700;color:var(--gold)">€<?= number_format($r['amount']??0,2) ?></td>
        <td style="font-size:.83rem"><?= h($r['method']??'—') ?></td>
        <td style="font-size:.8rem;color:var(--muted)"><?= date('d/m/Y H:i',strtotime($r['paid_at'])) ?></td>
        <?php if($canEdit||$canDelete): ?>
        <td style="white-space:nowrap">
          <?php if($canEdit): ?>
            <button class="btn btn-ghost" style="padding:.35rem .6rem;font-size:.8rem" title="Επεξεργασία"
              onclick="openEdit(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)">
              <i class="fa-solid fa-pen"></i></button>
          <?php endif; ?>
          <?php if($canDelete): ?>
            <button class="btn btn-red" style="padding:.35rem .6rem;font-size:.8rem" title="Διαγραφή"
              onclick="openDelete(<?= $r['id'] ?>,<?= number_format($r['amount'],2) ?>,'<?= h($r['school_name']??'') ?>')">
              <i class="fa-solid fa-trash"></i></button>
          <?php endif; ?>
        </td>
        <?php endif; ?>
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
</div><?php renderEmpClose(); ?>

<style>
.emp-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:400;align-items:center;justify-content:center;padding:1rem}
.emp-modal-bg.open{display:flex}
.emp-modal{background:#111520;border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:1.8rem;width:100%;max-width:500px;box-shadow:0 30px 80px rgba(0,0,0,.5)}
.emp-modal h3{margin:0 0 1.2rem;font-size:1.1rem;font-weight:800}
.modal-footer{display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem}
.two-col-form{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
</style>

<?php if($canCreate): ?>
<div class="emp-modal-bg" id="modalCreate"><div class="emp-modal">
  <h3><i class="fa-solid fa-plus" style="color:var(--green)"></i> Νέα Πληρωμή</h3>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="_action" value="create">
    <div class="form-group"><label>Σχολή *</label>
      <select name="school_id" class="form-control" required><option value="">— Επιλογή —</option>
        <?php foreach($schools as $sc): ?><option value="<?= $sc['id'] ?>"><?= h($sc['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group"><label>Πλάνο *</label>
      <select name="plan_id" class="form-control" required><option value="">— Επιλογή —</option>
        <?php foreach($plans as $pl): ?><option value="<?= $pl['id'] ?>"><?= h($pl['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="two-col-form">
      <div class="form-group"><label>Ποσό (€) *</label><input type="number" name="amount" class="form-control" required min="0" step="0.01"></div>
      <div class="form-group"><label>Μέθοδος</label>
        <select name="method" class="form-control">
          <option value="card">Κάρτα</option>
          <option value="bank">Τράπεζα</option>
          <option value="cash">Μετρητά</option>
        </select></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('modalCreate')">Ακύρωση</button>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Καταχώρηση</button>
    </div>
  </form>
</div></div>
<?php endif; ?>

<?php if($canEdit): ?>
<div class="emp-modal-bg" id="modalEdit"><div class="emp-modal">
  <h3><i class="fa-solid fa-pen" style="color:var(--blue)"></i> Επεξεργασία Πληρωμής</h3>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="_action" value="edit"><input type="hidden" name="id" id="editId">
    <div class="form-group"><label>Σχολή *</label>
      <select name="school_id" id="editSchool" class="form-control" required>
        <?php foreach($schools as $sc): ?><option value="<?= $sc['id'] ?>"><?= h($sc['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group"><label>Πλάνο *</label>
      <select name="plan_id" id="editPlan" class="form-control" required>
        <?php foreach($plans as $pl): ?><option value="<?= $pl['id'] ?>"><?= h($pl['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="two-col-form">
      <div class="form-group"><label>Ποσό (€) *</label><input type="number" name="amount" id="editAmount" class="form-control" required min="0" step="0.01"></div>
      <div class="form-group"><label>Μέθοδος</label>
        <select name="method" id="editMethod" class="form-control">
          <option value="card">Κάρτα</option>
          <option value="bank">Τράπεζα</option>
          <option value="cash">Μετρητά</option>
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
<div class="emp-modal-bg" id="modalDelete"><div class="emp-modal">
  <h3><i class="fa-solid fa-triangle-exclamation" style="color:var(--red)"></i> Διαγραφή Πληρωμής</h3>
  <p style="color:var(--muted);margin-bottom:1.2rem">Διαγραφή πληρωμής <strong id="deleteDesc" style="color:#fff"></strong>;</p>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" id="deleteId">
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('modalDelete')">Ακύρωση</button>
      <button type="submit" class="btn btn-red"><i class="fa-solid fa-trash"></i> Διαγραφή</button>
    </div>
  </form>
</div></div>
<?php endif; ?>

<script>
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.emp-modal-bg').forEach(bg=>bg.addEventListener('click',e=>{if(e.target===bg)bg.classList.remove('open');}));
function openEdit(r){
  document.getElementById('editId').value=r.id;
  document.getElementById('editSchool').value=r.school_id||'';
  document.getElementById('editPlan').value=r.plan_id||'';
  document.getElementById('editAmount').value=r.amount||0;
  document.getElementById('editMethod').value=r.method||'card';
  openModal('modalEdit');
}
function openDelete(id,amount,school){
  document.getElementById('deleteId').value=id;
  document.getElementById('deleteDesc').textContent='€'+amount+' — '+school;
  openModal('modalDelete');
}
</script>