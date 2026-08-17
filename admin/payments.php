<?php

/**
 * ============================================================
 * admin/payments.php — Διαχείριση Πληρωμών Σχολών
 * ============================================================
 * PURPOSE:
 *   Εμφάνιση, καταχώρηση και διαγραφή πληρωμών σχολών.
 *   Υποστήριξη μεθόδων: IRIS/Κάρτα (Viva), Τράπεζα, Μετρητά.
 *   Χειροκίνητη ενεργοποίηση λογαριασμών μετά από τραπεζικό έμβασμα.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf()
 *   ✓ Prepared statements παντού
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

requireSuperAdmin();

$db = getDB();

// ── CSV Export ──────────────────────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    $rows = getDB()->query("SELECT p.*,s.name as school_name,pl.name as plan_name FROM school_plan_payments p JOIN schools s ON s.id=p.school_id JOIN plans pl ON pl.id=p.plan_id ORDER BY p.paid_at DESC")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['ID','Σχολή','Πλάνο','Ποσό','Περίοδος','Ισχύει Έως','Μέθοδος','Ημερομηνία','Σημειώσεις']);
    foreach ($rows as $r) fputcsv($out,[$r['id'],$r['school_name'],$r['plan_name'],$r['amount'],$r['period'],$r['valid_until'],$r['method'],date('d/m/Y H:i',strtotime($r['paid_at'])),$r['notes']??'']);
    fclose($out); exit;
}

// ── POST: Ενέργειες Πληρωμών ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $a = $_POST;

    // ── Καταχώρηση νέας πληρωμής + ενεργοποίηση σχολής ──
    if ($a['_action'] === 'add_payment') {
        $db->prepare("INSERT INTO school_plan_payments (school_id,plan_id,amount,period,valid_until,method,notes) VALUES (?,?,?,?,?,?,?)")
           ->execute([$a['school_id'],$a['plan_id'],$a['amount'],$a['period'],$a['valid_until'],$a['method'],$a['notes']??'']);
        $db->prepare("UPDATE schools SET plan_id=?,plan_status='active',plan_expires=? WHERE id=?")
           ->execute([$a['plan_id'],$a['valid_until'],$a['school_id']]);
        auditLog('manual_payment','school',(int)$a['school_id'],"Ποσό: {$a['amount']}€, μέθοδος: {$a['method']}");

        // ── Send activation email to school owner ──
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $ownerStmt = $db->prepare("
                SELECT u.email, u.name AS full_name, s.name AS school_name, pl.name AS plan_name
                FROM users u
                JOIN schools s ON s.id = u.school_id
                JOIN plans pl ON pl.id = ?
                WHERE u.school_id = ? AND u.role = 'owner'
                LIMIT 1
            ");
            $ownerStmt->execute([$a['plan_id'], $a['school_id']]);
            $owner = $ownerStmt->fetch();
            if ($owner && filter_var($owner['email'], FILTER_VALIDATE_EMAIL)) {
                sendSchoolPlanActivationEmail(
                    $owner['email'],
                    $owner['full_name'] ?? $owner['school_name'],
                    $owner['school_name'],
                    $owner['plan_name'],
                    $a['valid_until'],
                    (float)$a['amount'],
                    $a['method']
                );
            }
        } catch (Throwable $e) {
            error_log('[MAster admin/payments] sendSchoolPlanActivationEmail error: ' . $e->getMessage());
        }

        flash('✅ Πληρωμή καταχωρήθηκε και λογαριασμός ενεργοποιήθηκε!');
        redirect(APP_URL.'/admin/payments.php');
    }

    // ── Διαγραφή πληρωμής ──
    if ($a['_action'] === 'delete_payment') {
        $id = (int)$a['id'];
        $row = $db->prepare("SELECT * FROM school_plan_payments WHERE id=?"); $row->execute([$id]);
        $pay = $row->fetch();
        if ($pay) {
            $db->prepare("DELETE FROM school_plan_payments WHERE id=?")->execute([$id]);
            auditLog('delete_payment','school',$pay['school_id'],"Payment ID: {$id}");
            flash('Πληρωμή διαγράφηκε.');
        }
        redirect(APP_URL.'/admin/payments.php');
    }

    redirect(APP_URL.'/admin/payments.php');
}

// ── Φίλτρα ─────────────────────────────────────────────────────────────────
$filterSchool = (int)($_GET['school_id'] ?? 0);
$filterPeriod = trim($_GET['period'] ?? '');
$filterMethod = trim($_GET['method'] ?? '');
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;

$where = ['1=1'];
$params = [];
if ($filterSchool) { $where[] = 'p.school_id = ?'; $params[] = $filterSchool; }
if ($filterPeriod) { $where[] = 'p.period = ?';    $params[] = $filterPeriod; }
if ($filterMethod) { $where[] = 'p.method = ?';    $params[] = $filterMethod; }
if ($search) {
    $where[] = '(s.name LIKE ? OR pl.name LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}
$whereStr = implode(' AND ', $where);

$totalRows  = $db->prepare("SELECT COUNT(*) FROM school_plan_payments p JOIN schools s ON s.id=p.school_id JOIN plans pl ON pl.id=p.plan_id WHERE $whereStr");
$totalRows->execute($params);
$totalRows  = $totalRows->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / max(1, $limit)));
$offset     = ($page - 1) * $limit;

$stmt = $db->prepare("SELECT p.*,s.name as school_name,pl.name as plan_name FROM school_plan_payments p JOIN schools s ON s.id=p.school_id JOIN plans pl ON pl.id=p.plan_id WHERE $whereStr ORDER BY p.paid_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$payments = $stmt->fetchAll();

$totalRevenue = $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments")->fetchColumn();
$monthRevenue = $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE MONTH(paid_at)=MONTH(CURDATE()) AND YEAR(paid_at)=YEAR(CURDATE())")->fetchColumn();
$pendingBank  = $db->query("SELECT COUNT(*) FROM school_plan_payments WHERE method='bank' AND notes LIKE '%pending%'")->fetchColumn();
$schools = $db->query("SELECT id,name FROM schools ORDER BY name")->fetchAll();
$plans   = $db->query("SELECT id,name FROM plans")->fetchAll();

renderHead('Πληρωμές');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { font-size: 15px; }
.page-body { padding: 1.75rem !important; }
.card { border-radius: 14px !important; }
.card-title { font-size: 1rem !important; font-weight: 700 !important; }
.card-header { margin-bottom: 1.25rem; }
table { font-size: .9rem !important; }
thead th { font-size: .75rem !important; padding: .7rem 1rem !important; letter-spacing: .07em; }
tbody td { padding: .8rem 1rem !important; font-size: .88rem !important; }
.stat-card { border-radius: 14px !important; padding: 1.35rem !important; }
.stat-card .stat-val { font-size: 2.1rem !important; font-weight: 800 !important; }
.stat-card .stat-lbl { font-size: .82rem !important; }
.stat-card .stat-icon { width: 46px !important; height: 46px !important; font-size: 1.3rem !important; border-radius: 12px !important; }
.badge { font-size: .72rem !important; padding: .22rem .6rem !important; border-radius: 50px !important; font-weight: 700 !important; }
.btn { font-size: .875rem !important; padding: .5rem 1.05rem !important; border-radius: 9px !important; font-weight: 500 !important; }
.btn-sm { font-size: .8rem !important; padding: .32rem .65rem !important; }
.form-label { font-size: .82rem !important; font-weight: 600 !important; color: var(--muted); }
.form-control { font-size: .88rem !important; padding: .58rem .8rem !important; border-radius: 9px !important; }
.form-group { gap: .4rem !important; }
.badge-iris { background: rgba(107,92,231,.12); color: #9d8fef; }
.badge-bank { background: rgba(59,130,246,.12); color: #93c5fd; }
.badge-cash { background: rgba(45,198,83,.12); color: #6ee7b7; }
@media(max-width:768px){ .page-body { padding: 1rem !important; } }
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_payments'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-credit-card"></i> Πληρωμές Σχολών'); ?>
<div class="page-body">

<!-- ── KPI Cards ── -->
<div class="grid grid-3 mb-3">
  <div class="stat-card">
    <div class="stat-icon icon-green"><i class="fa-solid fa-euro-sign"></i></div>
    <div class="stat-val text-green"><?= formatMoney($totalRevenue) ?></div>
    <div class="stat-lbl">Σύνολο Εσόδων</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-blue"><i class="fa-solid fa-calendar-day"></i></div>
    <div class="stat-val"><?= formatMoney($monthRevenue) ?></div>
    <div class="stat-lbl">Έσοδα Μήνα</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-gold"><i class="fa-solid fa-receipt"></i></div>
    <div class="stat-val"><?= $totalRows ?></div>
    <div class="stat-lbl">Σύνολο Συναλλαγών</div>
  </div>
</div>

<!-- ── Toolbar ── -->
<div class="d-flex jc-between ai-center mb-3 flex-wrap gap-sm">
  <div class="text-muted text-sm">Διαχείριση πληρωμών σχολών (IRIS, Τράπεζα, Μετρητά)</div>
  <a href="?export_csv=1" class="btn btn-ghost btn-sm" title="Εξαγωγή CSV"><i class="fa-solid fa-file-csv" style="color:#2dc653"></i> CSV</a>
</div>

<!-- ── Νέα Πληρωμή (χειροκίνητη — π.χ. μετά από τραπεζικό έμβασμα) ── -->
<div class="grid grid-2 mb-3">
  <div class="card">
    <div class="card-title mb-2"><i class="fa-solid fa-plus"></i> Νέα Πληρωμή / Ενεργοποίηση</div>
    <div class="text-muted text-sm mb-2" style="font-size:.8rem">Χρησιμοποίησε αυτή τη φόρμα για να ενεργοποιήσεις χειροκίνητα έναν λογαριασμό μετά από τραπεζικό έμβασμα ή άλλη μέθοδο.</div>
    <form method="POST">
      <input type="hidden" name="_action" value="add_payment">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <div class="form-row col-2">
        <div class="form-group">
          <label class="form-label">Σχολή</label>
          <select name="school_id" class="form-control">
            <?php foreach ($schools as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Πλάνο</label>
          <select name="plan_id" class="form-control">
            <?php foreach ($plans as $p): ?>
              <option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Ποσό (€)</label>
          <input type="number" step=".01" name="amount" class="form-control" required placeholder="25.00">
        </div>
        <div class="form-group">
          <label class="form-label">Περίοδος</label>
          <select name="period" class="form-control">
            <option value="monthly">Μηνιαία</option>
            <option value="annual">Ετήσια</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Ισχύει Έως</label>
          <input type="date" name="valid_until" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Μέθοδος</label>
          <select name="method" class="form-control">
            <option value="card">IRIS / Κάρτα</option>
            <option value="bank">Τραπεζικό Έμβασμα</option>
            <option value="cash">Μετρητά</option>
          </select>
        </div>
      </div>
      <div class="form-group mt-1">
        <label class="form-label">Σημειώσεις (π.χ. αριθμός συναλλαγής)</label>
        <input name="notes" class="form-control" placeholder="π.χ. Viva: xxxxx ή Έμβασμα 03/04/2026">
      </div>
      <button type="submit" class="btn btn-primary mt-2">
        <i class="fa-solid fa-check"></i> Καταχώρηση &amp; Ενεργοποίηση
      </button>
    </form>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <form method="GET" class="d-flex gap-sm flex-wrap ai-end">
    <div class="form-group" style="min-width:200px;margin-bottom:0">
      <label class="form-label">Αναζήτηση</label>
      <div class="search-bar">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input name="q" value="<?= h($search) ?>" placeholder="Σχολή ή πλάνο...">
      </div>
    </div>
    <div class="form-group" style="min-width:160px;margin-bottom:0">
      <label class="form-label">Σχολή</label>
      <select name="school_id" class="form-control">
        <option value="">Όλες</option>
        <?php foreach ($schools as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterSchool == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="min-width:130px;margin-bottom:0">
      <label class="form-label">Περίοδος</label>
      <select name="period" class="form-control">
        <option value="">Όλες</option>
        <option value="monthly" <?= $filterPeriod === 'monthly' ? 'selected' : '' ?>>Μηνιαία</option>
        <option value="annual"  <?= $filterPeriod === 'annual'  ? 'selected' : '' ?>>Ετήσια</option>
      </select>
    </div>
    <div class="form-group" style="min-width:150px;margin-bottom:0">
      <label class="form-label">Μέθοδος</label>
      <select name="method" class="form-control">
        <option value="">Όλες</option>
        <option value="card" <?= $filterMethod === 'card' ? 'selected' : '' ?>>IRIS / Κάρτα</option>
        <option value="bank" <?= $filterMethod === 'bank' ? 'selected' : '' ?>>Τραπεζικό Έμβασμα</option>
        <option value="cash" <?= $filterMethod === 'cash' ? 'selected' : '' ?>>Μετρητά</option>
      </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i> Φίλτρο</button>
    <a href="<?= APP_URL ?>/admin/payments.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i> Καθαρισμός</a>
  </form>
</div>

<div class="card p-0">
  <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
    <div class="card-title">Ιστορικό Πληρωμών <span class="text-muted text-sm">(<?= $totalRows ?> εγγραφές)</span></div>
  </div>
  <div class="table-wrap"><table>
    <thead>
      <tr>
        <th>Σχολή</th>
        <th>Πλάνο</th>
        <th>Ποσό</th>
        <th>Περίοδος</th>
        <th>Ισχύει Έως</th>
        <th>Μέθοδος</th>
        <th>Ημ/νία</th>
        <th>Σημειώσεις</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($payments as $p): ?>
      <tr>
        <td class="fw-600"><?= h($p['school_name']) ?></td>
        <td><?= h($p['plan_name']) ?></td>
        <td class="fw-600 text-green"><?= formatMoney($p['amount']) ?></td>
        <td><?= $p['period'] === 'annual' ? 'Ετήσια' : 'Μηνιαία' ?></td>
        <td><?= formatDate($p['valid_until']) ?></td>
        <td>
          <?php
            $methodIcons = [
              'card' => '<i class="fa-solid fa-bolt" style="color:#9d8fef"></i>',
              'bank' => '<i class="fa-solid fa-building-columns" style="color:#93c5fd"></i>',
              'cash' => '<i class="fa-solid fa-money-bill-wave" style="color:#6ee7b7"></i>',
            ];
            $methodLabels = ['card'=>'IRIS/Κάρτα','bank'=>'Τράπεζα','cash'=>'Μετρητά'];
          ?>
          <?= $methodIcons[$p['method']] ?? '' ?> <?= $methodLabels[$p['method']] ?? h($p['method']) ?>
        </td>
        <td><?= date('d/m/Y H:i', strtotime($p['paid_at'])) ?></td>
        <td class="text-muted text-xs" style="max-width:180px;word-break:break-all"><?= h(substr($p['notes'] ?? '', 0, 60)) ?></td>
        <td>
          <form method="POST" style="display:inline" onsubmit="return confirm('Διαγραφή πληρωμής;')">
            <input type="hidden" name="_action" value="delete_payment">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" title="Διαγραφή"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($payments)): ?>
      <tr><td colspan="9" class="text-center text-muted" style="padding:2rem">Δεν βρέθηκαν εγγραφές</td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.35rem 0">
  <form method="GET" style="display:inline-flex;align-items:center;margin-right:.5rem">
    <?php foreach(array_filter($_GET, fn($k)=>$k!=='per_page'&&$k!=='page', ARRAY_FILTER_USE_KEY) as $gk=>$gv): ?>
    <input type="hidden" name="<?= h($gk) ?>" value="<?= h($gv) ?>">
    <?php endforeach; ?>
    <select name="per_page" class="pg-size-select" onchange="this.form.submit()" style="font-size:.8rem;padding:.28rem .5rem;border-radius:7px;border:1px solid var(--border,#1e2536);background:var(--card,#111827);color:var(--text,#e2e8f0);cursor:pointer">
      <?php foreach([10,25,50,100] as $n): ?>
      <option value="<?= $n ?>"<?= $limit==$n?' selected':''?>><?= $n ?> / σελίδα</option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php if($page>1):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>" class="page-btn" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a><?php endif;?>
  <?php if($page>1):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="page-btn" title="Προηγούμενη"><i class="fa-solid fa-chevron-left"></i></a><?php endif;?>
  <?php for($pp=max(1,$page-2);$pp<=min($totalPages,$page+2);$pp++):?>
  <a href="?<?= http_build_query(array_merge($_GET,['page'=>$pp])) ?>" class="page-btn <?=$pp==$page?'active':''?>"><?=$pp?></a>
  <?php endfor;?>
  <?php if($page<$totalPages):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="page-btn" title="Επόμενη"><i class="fa-solid fa-chevron-right"></i></a><?php endif;?>
  <?php if($page<$totalPages):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>" class="page-btn" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a><?php endif;?>
  <span style="font-size:.8rem;color:var(--muted);margin-left:.4rem"><?=$page?> / <?=$totalPages?></span>
</div>
<?php endif; ?>

</div></div></div>
</body></html>