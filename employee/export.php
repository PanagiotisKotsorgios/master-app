<?php
/**
 * ============================================================
 * employee/export.php — Data Export Center
 * ============================================================
 */

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/privileges.php';
require_once __DIR__ . '/layout.php';

$db = getDB();

// ── CSV streaming mode ───────────────────────────────────────
$format  = $_GET['format']  ?? '';
$report  = $_GET['report']  ?? '';
$section = $_GET['section'] ?? 'schools';

if ($format === 'csv') {
    $privMap = [
        'schools'   => 'export_schools',
        'users'     => 'export_users',
        'athletes'  => 'export_athletes',
        'payments'  => 'export_payments',
        'analytics' => 'analytics_export',
    ];
    $required = $privMap[$report] ?? $privMap[$section] ?? null;
    if ($required && !empCan($required)) {
        http_response_code(403);
        exit('Access denied: missing privilege ' . $required);
    }

    $filename  = $report ?: $section;
    $timestamp = date('Y-m-d_His');
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$filename}_{$timestamp}.csv\"");
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    $q      = trim($_GET['q']    ?? '');
    $status = $_GET['status']    ?? '';
    $from   = $_GET['from']      ?? '';
    $to     = $_GET['to']        ?? '';

    if ($report === 'analytics' || $section === 'analytics') {
        fputcsv($out, ['Δείκτης','Τιμή']);
        $rows2 = [
            ['Σύνολο Σχολών',     $db->query("SELECT COUNT(*) FROM schools")->fetchColumn()],
            ['Ενεργές Σχολές',    $db->query("SELECT COUNT(*) FROM schools WHERE plan_status='active'")->fetchColumn()],
            ['Trial Σχολές',      $db->query("SELECT COUNT(*) FROM schools WHERE plan_status='trial'")->fetchColumn()],
            ['Σύνολο Αθλητών',    $db->query("SELECT COUNT(*) FROM athletes WHERE active=1")->fetchColumn()],
            ['Σύνολο Χρηστών',    $db->query("SELECT COUNT(*) FROM users WHERE active=1")->fetchColumn()],
            ['Συνολικά Έσοδα',    $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments")->fetchColumn()],
            ['Έσοδα Τρέχ. Μήνα', $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn()],
            ['Πληρωμές',          $db->query("SELECT COUNT(*) FROM school_plan_payments")->fetchColumn()],
        ];
        foreach ($rows2 as $r) fputcsv($out, $r);

    } elseif ($section === 'schools') {
        fputcsv($out, ['ID','Όνομα','Email','Τηλέφωνο','Πόλη','Πλάνο','Plan Status','Subscription Status','Ημ. Δημιουργίας']);
        $where = ['s.active=1']; $params = [];
        if ($q)      { $where[] = "(s.name LIKE ? OR s.email LIKE ? OR s.city LIKE ?)"; $like = "%$q%"; $params = array_merge($params, [$like,$like,$like]); }
        if ($status) { $where[] = "s.subscription_status=?"; $params[] = $status; }
        if ($from)   { $where[] = "s.created_at >= ?"; $params[] = $from.' 00:00:00'; }
        if ($to)     { $where[] = "s.created_at <= ?"; $params[] = $to.' 23:59:59'; }
        $sql  = "SELECT s.id,s.name,s.email,s.phone,s.city,p.name as plan_name,s.plan_status,s.subscription_status,s.created_at FROM schools s LEFT JOIN plans p ON p.id=s.plan_id WHERE ".implode(' AND ',$where)." ORDER BY s.name";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, array_values($r));

    } elseif ($section === 'users') {
        fputcsv($out, ['ID','Όνομα','Email','Ρόλος','Σχολή','Active','Ημ. Δημιουργίας']);
        $where = []; $params = [];
        if ($q)      { $where[] = "(u.name LIKE ? OR u.email LIKE ?)"; $like = "%$q%"; $params = [$like,$like]; }
        if ($status) { $where[] = "u.role=?"; $params[] = $status; }
        $w    = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $sql  = "SELECT u.id,u.name,u.email,u.role,s.name as school_name,u.active,u.created_at FROM users u LEFT JOIN schools s ON s.id=u.school_id $w ORDER BY u.name";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, array_values($r));

    } elseif ($section === 'athletes') {
        fputcsv($out, ['ID','Ονοματεπώνυμο','Email','Τηλέφωνο','Σχολή','Active','Ημ. Δημιουργίας']);
        $where = ['a.active=1']; $params = [];
        if ($q)      { $where[] = "(a.full_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)"; $like = "%$q%"; $params = [$like,$like,$like]; }
        if ($from)   { $where[] = "a.created_at >= ?"; $params[] = $from.' 00:00:00'; }
        if ($to)     { $where[] = "a.created_at <= ?"; $params[] = $to.' 23:59:59'; }
        $sql  = "SELECT a.id,a.full_name,a.email,a.phone,s.name as school_name,a.active,a.created_at FROM athletes a LEFT JOIN schools s ON s.id=a.school_id WHERE ".implode(' AND ',$where)." ORDER BY a.full_name";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, array_values($r));

    } elseif ($section === 'payments') {
        // Fixed: paid_at and method instead of created_at and payment_method
        fputcsv($out, ['ID','Σχολή','Πλάνο','Ποσό','Μέθοδος','Ημερομηνία']);
        $where = []; $params = [];
        if ($q)    { $where[] = "s.name LIKE ?"; $params[] = "%$q%"; }
        if ($from) { $where[] = "spp.paid_at >= ?"; $params[] = $from.' 00:00:00'; }
        if ($to)   { $where[] = "spp.paid_at <= ?"; $params[] = $to.' 23:59:59'; }
        $w    = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $sql  = "SELECT spp.id,s.name,p.name as plan_name,spp.amount,spp.method,spp.paid_at FROM school_plan_payments spp LEFT JOIN schools s ON s.id=spp.school_id LEFT JOIN plans p ON p.id=spp.plan_id $w ORDER BY spp.paid_at DESC";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, array_values($r));
    }

    fclose($out); exit;
}

// ── HTML MODE ────────────────────────────────────────────────
$tabs = [];
if (empCan('export_schools'))  $tabs[] = ['key'=>'schools',  'label'=>'Σχολές',   'icon'=>'fa-school'];
if (empCan('export_users'))    $tabs[] = ['key'=>'users',    'label'=>'Χρήστες',  'icon'=>'fa-users'];
if (empCan('export_athletes')) $tabs[] = ['key'=>'athletes', 'label'=>'Αθλητές',  'icon'=>'fa-person-running'];
if (empCan('export_payments')) $tabs[] = ['key'=>'payments', 'label'=>'Πληρωμές', 'icon'=>'fa-credit-card'];

if (empty($tabs)) {
    renderEmpHead('Export Center');
    ?><body><?php
    renderEmpSidebar('export');
    ?><div class="emp-main"><?php
    renderEmpTopbar('Export Center');
    ?><div class="emp-content">
      <div class="card" style="text-align:center;padding:3rem">
        <i class="fa-solid fa-lock" style="font-size:2rem;color:var(--muted)"></i>
        <p style="margin-top:1rem;color:var(--muted)">Δεν έχεις δικαίωμα εξαγωγής δεδομένων.<br>Ζήτησε από τον διαχειριστή να σου δώσει export δικαιώματα.</p>
      </div>
    </div><?php
    renderEmpClose(); exit;
}

if (!in_array($section, array_column($tabs,'key'))) $section = $tabs[0]['key'];

$q      = trim($_GET['q']    ?? '');
$status = $_GET['status']    ?? '';
$from   = $_GET['from']      ?? '';
$to     = $_GET['to']        ?? '';
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page-1)*$perPage;
$data    = []; $total = 0; $columns = []; $filterOptions = [];

function safeQ(PDO $db, string $sql, array $p=[]): array {
    try { $s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    catch(Throwable $e){error_log('export safeQ: '.$e->getMessage());return [];}
}
function safeScalar(PDO $db, string $sql, array $p=[], $def=0) {
    try { $s=$db->prepare($sql);$s->execute($p);$v=$s->fetchColumn();return $v!==false?$v:$def;}
    catch(Throwable $e){error_log('export scalar: '.$e->getMessage());return $def;}
}

if ($section === 'schools') {
    $columns = ['name'=>'Σχολή','email'=>'Email','phone'=>'Τηλέφωνο','city'=>'Πόλη','plan_name'=>'Πλάνο','plan_status'=>'Plan Status','subscription_status'=>'Subs. Status','created_at'=>'Εγγραφή'];
    $filterOptions = [''=>'Όλες','active'=>'Active','trial'=>'Trial','cancelled'=>'Cancelled','suspended'=>'Suspended','past_due'=>'Past Due'];
    $where = ['s.active=1']; $params = [];
    if ($q)      { $where[] = "(s.name LIKE ? OR s.email LIKE ? OR s.city LIKE ? OR s.phone LIKE ?)"; $like = "%$q%"; $params = array_merge($params, [$like,$like,$like,$like]); }
    if ($status) { $where[] = "s.subscription_status=?"; $params[] = $status; }
    if ($from)   { $where[] = "s.created_at >= ?"; $params[] = $from.' 00:00:00'; }
    if ($to)     { $where[] = "s.created_at <= ?"; $params[] = $to.' 23:59:59'; }
    $w     = implode(' AND ', $where);
    $total = (int)safeScalar($db, "SELECT COUNT(*) FROM schools s WHERE $w", $params);
    $data  = safeQ($db, "SELECT s.id,s.name,s.email,s.phone,s.city,p.name as plan_name,s.plan_status,s.subscription_status,s.created_at FROM schools s LEFT JOIN plans p ON p.id=s.plan_id WHERE $w ORDER BY s.name LIMIT $perPage OFFSET $offset", $params);

} elseif ($section === 'users') {
    $columns = ['name'=>'Όνομα','email'=>'Email','role'=>'Ρόλος','school_name'=>'Σχολή','active'=>'Active','created_at'=>'Εγγραφή'];
    $filterOptions = [''=>'Όλοι','admin'=>'Admin','user'=>'User','employee'=>'Employee'];
    $where = []; $params = [];
    if ($q)      { $where[] = "(u.name LIKE ? OR u.email LIKE ?)"; $like = "%$q%"; $params = [$like,$like]; }
    if ($status) { $where[] = "u.role=?"; $params[] = $status; }
    $w     = $where ? 'WHERE '.implode(' AND ',$where) : '';
    $total = (int)safeScalar($db, "SELECT COUNT(*) FROM users u $w", $params);
    $data  = safeQ($db, "SELECT u.id,u.name,u.email,u.role,s.name as school_name,u.active,u.created_at FROM users u LEFT JOIN schools s ON s.id=u.school_id $w ORDER BY u.name LIMIT $perPage OFFSET $offset", $params);

} elseif ($section === 'athletes') {
    $columns = ['full_name'=>'Ονοματεπώνυμο','email'=>'Email','phone'=>'Τηλέφωνο','school_name'=>'Σχολή','created_at'=>'Εγγραφή'];
    $filterOptions = [];
    $where = ['a.active=1']; $params = [];
    if ($q)      { $where[] = "(a.full_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)"; $like = "%$q%"; $params = [$like,$like,$like]; }
    if ($from)   { $where[] = "a.created_at >= ?"; $params[] = $from.' 00:00:00'; }
    if ($to)     { $where[] = "a.created_at <= ?"; $params[] = $to.' 23:59:59'; }
    $w     = implode(' AND ', $where);
    $total = (int)safeScalar($db, "SELECT COUNT(*) FROM athletes a WHERE $w", $params);
    $data  = safeQ($db, "SELECT a.id,a.full_name,a.email,a.phone,s.name as school_name,a.created_at FROM athletes a LEFT JOIN schools s ON s.id=a.school_id WHERE $w ORDER BY a.full_name LIMIT $perPage OFFSET $offset", $params);

} elseif ($section === 'payments') {
    // Fixed: paid_at and method instead of created_at and payment_method
    $columns = ['school_name'=>'Σχολή','plan_name'=>'Πλάνο','amount'=>'Ποσό','method'=>'Μέθοδος','paid_at'=>'Ημερομηνία'];
    $where = []; $params = [];
    if ($q)    { $where[] = "s.name LIKE ?"; $params[] = "%$q%"; }
    if ($from) { $where[] = "spp.paid_at >= ?"; $params[] = $from.' 00:00:00'; }
    if ($to)   { $where[] = "spp.paid_at <= ?"; $params[] = $to.' 23:59:59'; }
    $w     = $where ? 'WHERE '.implode(' AND ',$where) : '';
    $total = (int)safeScalar($db, "SELECT COUNT(*) FROM school_plan_payments spp LEFT JOIN schools s ON s.id=spp.school_id $w", $params);
    $data  = safeQ($db, "SELECT spp.id,s.name as school_name,p.name as plan_name,spp.amount,spp.method,spp.paid_at FROM school_plan_payments spp LEFT JOIN schools s ON s.id=spp.school_id LEFT JOIN plans p ON p.id=spp.plan_id $w ORDER BY spp.paid_at DESC LIMIT $perPage OFFSET $offset", $params);
}

$totalPages = max(1, (int)ceil($total / $perPage));

function buildUrl(array $overrides=[]): string {
    global $section,$q,$status,$from,$to,$page;
    $p = array_merge(['section'=>$section,'q'=>$q,'status'=>$status,'from'=>$from,'to'=>$to,'page'=>$page], $overrides);
    return APP_URL.'/employee/export.php?'.http_build_query(array_filter($p,'strlen'));
}

// ── Render — renderEmpHead() MUST come first ─────────────────
renderEmpHead('Export Center');
?><body>
<?php renderEmpSidebar('export'); ?>
<div class="emp-main">
<?php renderEmpTopbar('Export Center'); ?>

<style>
/* ── Print: B&W, no colours, table format ── */
@media print {
  .emp-sidebar,.emp-topbar,.no-print,.exp-tabs,.exp-filters,.exp-actions,.pagination-row{display:none!important}
  .emp-main{margin-left:0!important}
  .emp-content{padding:0!important}
  body{background:#fff!important;color:#000!important;font-family:Arial,sans-serif;font-size:10pt}
  .print-header{display:block!important}
  .exp-table-wrap{overflow:visible!important}
  table{border-collapse:collapse;width:100%;margin-top:8px}
  th{background:#000!important;color:#fff!important;font-size:8pt;padding:5px 8px;text-align:left;font-weight:bold}
  td{border:1px solid #888;padding:4px 8px;font-size:8.5pt;color:#000}
  tr:nth-child(even) td{background:#f0f0f0}
  .status-pill{display:none}
}
.print-header{display:none;margin-bottom:12px;border-bottom:2px solid #000;padding-bottom:8px}

/* ── Tab bar ── */
.exp-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.2rem}
.exp-tab{padding:.6rem 1.1rem;border-radius:10px;font-size:.85rem;font-weight:700;color:var(--muted);border:1px solid rgba(255,255,255,.07);cursor:pointer;text-decoration:none;transition:.18s;display:flex;align-items:center;gap:.5rem}
.exp-tab:hover{background:rgba(255,255,255,.05);color:#fff}
.exp-tab.active{background:rgba(230,57,70,.15);border-color:rgba(230,57,70,.4);color:#fff}

/* ── Filter bar ── */
.exp-filters{display:flex;gap:.65rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem}
.exp-filters input,.exp-filters select{background:rgba(17,21,32,.9);border:1px solid rgba(255,255,255,.1);border-radius:9px;color:#fff;padding:.5rem .8rem;font-size:.85rem;outline:none;transition:.18s}
.exp-filters input:focus,.exp-filters select:focus{border-color:var(--accent)}
.exp-filters label{font-size:.75rem;color:var(--muted);display:block;margin-bottom:.3rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}

/* ── Action bar ── */
.exp-actions{display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem;align-items:center}

/* ── Table ── */
.exp-table-wrap{overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,.07)}
table.exp-table{width:100%;border-collapse:collapse;font-size:.84rem}
table.exp-table thead th{background:rgba(255,255,255,.04);color:var(--muted);font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:.65rem 1rem;text-align:left;white-space:nowrap;border-bottom:1px solid rgba(255,255,255,.07);cursor:pointer;user-select:none}
table.exp-table thead th:hover{color:#fff}
table.exp-table thead th .sort-icon{margin-left:.3rem;opacity:.4}
table.exp-table tbody tr{border-bottom:1px solid rgba(255,255,255,.04);transition:.12s}
table.exp-table tbody tr:hover{background:rgba(255,255,255,.03)}
table.exp-table td{padding:.62rem 1rem;vertical-align:middle}
.status-pill{display:inline-block;padding:.2rem .55rem;border-radius:999px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.sp-active{background:rgba(45,198,83,.15);color:#2dc653}
.sp-trial{background:rgba(88,166,255,.15);color:#58a6ff}
.sp-cancelled,.sp-suspended,.sp-past_due{background:rgba(230,57,70,.12);color:#e63946}
.sp-expired{background:rgba(240,165,0,.12);color:#f0a500}

/* ── Pagination ── */
.pagination-row{display:flex;align-items:center;gap:.5rem;margin-top:1rem;flex-wrap:wrap}
.page-btn{padding:.38rem .7rem;border-radius:8px;font-size:.82rem;font-weight:700;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#fff;text-decoration:none;transition:.15s}
.page-btn:hover{background:rgba(255,255,255,.1)}
.page-btn.active{background:rgba(230,57,70,.2);border-color:rgba(230,57,70,.4);color:#fff}
.page-info{font-size:.82rem;color:var(--muted);margin-left:auto}
</style>

<div class="emp-content">

  <!-- Print header (hidden on screen) -->
  <div class="print-header">
    <h1 style="margin:0;font-size:16pt">Export: <?= h(ucfirst($section)) ?></h1>
    <p style="margin:4px 0 0;font-size:9pt">Εξαγωγή: <?= date('d/m/Y H:i') ?> · Σύνολο: <?= $total ?> εγγραφές <?= $q ? '· Αναζήτηση: '.h($q) : '' ?></p>
  </div>

  <!-- Title -->
  <div style="margin-bottom:1rem" class="no-print">
    <div class="section-title"><i class="fa-solid fa-file-export" style="color:var(--accent)"></i> Export Center</div>
    <div class="section-sub">Αναζήτηση, φίλτρα και εξαγωγή δεδομένων σε CSV, PDF ή εκτύπωση</div>
  </div>

  <!-- Tabs -->
  <div class="exp-tabs no-print">
    <?php foreach($tabs as $t): ?>
      <a href="<?= buildUrl(['section'=>$t['key'],'page'=>1,'q'=>'','status'=>'','from'=>'','to'=>'']) ?>"
         class="exp-tab <?= $section===$t['key']?'active':'' ?>">
        <i class="fa-solid <?= h($t['icon']) ?>"></i> <?= h($t['label']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Filter Bar -->
  <form method="get" action="" class="exp-filters no-print">
    <input type="hidden" name="section" value="<?= h($section) ?>">
    <div>
      <label>Αναζήτηση</label>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Αναζήτηση..." style="width:220px">
    </div>
    <?php if (!empty($filterOptions)): ?>
    <div>
      <label>Φίλτρο</label>
      <select name="status">
        <?php foreach($filterOptions as $v=>$l): ?>
          <option value="<?= h($v) ?>" <?= $status===$v?'selected':'' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if (in_array($section,['schools','athletes','payments'])): ?>
    <div>
      <label>Από</label>
      <input type="date" name="from" value="<?= h($from) ?>">
    </div>
    <div>
      <label>Έως</label>
      <input type="date" name="to" value="<?= h($to) ?>">
    </div>
    <?php endif; ?>
    <div style="padding-bottom:.05rem">
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Εφαρμογή</button>
      <a href="<?= buildUrl(['q'=>'','status'=>'','from'=>'','to'=>'','page'=>1]) ?>" class="btn btn-ghost">Καθαρισμός</a>
    </div>
  </form>

  <!-- Action Bar -->
  <div class="exp-actions no-print">
    <span style="font-size:.85rem;color:var(--muted)"><?= number_format($total) ?> αποτελέσματα</span>
    <div style="margin-left:auto;display:flex;gap:.6rem;flex-wrap:wrap">
      <a href="<?= buildUrl(['format'=>'csv']) ?>" class="btn btn-ghost">
        <i class="fa-solid fa-file-csv"></i> Εξαγωγή CSV
      </a>
      <button onclick="exportPDF()" class="btn btn-ghost">
        <i class="fa-solid fa-file-pdf"></i> PDF (B&amp;W)
      </button>
      <button onclick="window.print()" class="btn btn-ghost">
        <i class="fa-solid fa-print"></i> Εκτύπωση
      </button>
    </div>
  </div>

  <!-- Data Table -->
  <div class="exp-table-wrap">
    <table class="exp-table" id="expTable">
      <thead>
        <tr>
          <?php foreach($columns as $key=>$label): ?>
            <th onclick="sortTable('<?= h($key) ?>')" title="Ταξινόμηση κατά <?= h($label) ?>">
              <?= h($label) ?> <span class="sort-icon">↕</span>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($data)): ?>
          <tr><td colspan="<?= count($columns) ?>" style="text-align:center;padding:2rem;color:var(--muted)">Δεν βρέθηκαν αποτελέσματα</td></tr>
        <?php else: foreach($data as $row): ?>
          <tr>
            <?php foreach(array_keys($columns) as $col): ?>
              <td><?php
                $val = $row[$col] ?? '—';
                if ($col === 'subscription_status' || $col === 'plan_status') {
                    $cls = 'sp-'.strtolower(str_replace([' '],['_'],$val));
                    echo '<span class="status-pill '.$cls.'">'.h($val).'</span>';
                } elseif ($col === 'amount') {
                    echo '<strong style="color:var(--green)">€'.number_format((float)$val,2,',','.').'</strong>';
                } elseif ($col === 'active') {
                    echo $val ? '<span class="status-pill sp-active">✓ Ενεργός</span>' : '<span class="status-pill sp-cancelled">✗ Ανενεργός</span>';
                } elseif ($col === 'created_at' || $col === 'paid_at') {
                    echo h($val ? date('d/m/Y H:i', strtotime($val)) : '—');
                } else {
                    echo h($val ?: '—');
                }
              ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination-row no-print">
    <?php if ($page > 1): ?>
      <a href="<?= buildUrl(['page'=>$page-1]) ?>" class="page-btn">‹ Προηγ.</a>
    <?php endif; ?>
    <?php
      $startPage = max(1, $page-2);
      $endPage   = min($totalPages, $page+2);
      if ($startPage > 1) echo '<a href="'.buildUrl(['page'=>1]).'" class="page-btn">1</a><span style="color:var(--muted)">…</span>';
      for ($i=$startPage; $i<=$endPage; $i++):
    ?>
      <a href="<?= buildUrl(['page'=>$i]) ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor;
      if ($endPage < $totalPages) echo '<span style="color:var(--muted)">…</span><a href="'.buildUrl(['page'=>$totalPages]).'" class="page-btn">'.$totalPages.'</a>';
    ?>
    <?php if ($page < $totalPages): ?>
      <a href="<?= buildUrl(['page'=>$page+1]) ?>" class="page-btn">Επόμ. ›</a>
    <?php endif; ?>
    <span class="page-info">Σελίδα <?= $page ?> από <?= $totalPages ?> · <?= number_format($total) ?> εγγραφές</span>
  </div>
  <?php endif; ?>

</div><!-- /emp-content -->
<?php renderEmpClose(); ?>

<script>
let sortAsc = {};
function sortTable(colKey) {
  const table = document.getElementById('expTable');
  const tbody = table.querySelector('tbody');
  const rows  = Array.from(tbody.querySelectorAll('tr'));
  const headers = Array.from(table.querySelectorAll('thead th'));
  const colIndex = headers.findIndex(h => h.getAttribute('onclick')?.includes(colKey));
  if (colIndex < 0) return;
  sortAsc[colKey] = !sortAsc[colKey];
  rows.sort((a,b) => {
    const aT = a.cells[colIndex]?.innerText.trim() || '';
    const bT = b.cells[colIndex]?.innerText.trim() || '';
    const aNum = parseFloat(aT.replace(/[€,.]/g,''));
    const bNum = parseFloat(bT.replace(/[€,.]/g,''));
    if (!isNaN(aNum) && !isNaN(bNum)) return sortAsc[colKey] ? aNum-bNum : bNum-aNum;
    return sortAsc[colKey] ? aT.localeCompare(bT,'el') : bT.localeCompare(aT,'el');
  });
  rows.forEach(r => tbody.appendChild(r));
  headers.forEach((h,i) => {
    const icon = h.querySelector('.sort-icon');
    if (icon) icon.textContent = i===colIndex ? (sortAsc[colKey]?'↑':'↓') : '↕';
  });
}

function exportPDF() {
  const btn = event.currentTarget;
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Φόρτωση...';
  const load = (src) => new Promise(res => {
    const s = document.createElement('script'); s.src = src; s.onload = res;
    document.head.appendChild(s);
  });
  load('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js')
    .then(() => load('https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js'))
    .then(() => {
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF({ orientation:'landscape', unit:'mm', format:'a4' });
      const BW  = doc.internal.pageSize.getWidth();
      let y = 18;

      doc.setFontSize(14); doc.setFont(undefined,'bold');
      doc.text('<?= h(ucfirst($section)) ?> Export', BW/2, y, {align:'center'}); y+=6;
      doc.setFontSize(9); doc.setFont(undefined,'normal');
      doc.text('Εξαγωγή: ' + new Date().toLocaleString('el-GR') + '  ·  Σύνολο: <?= $total ?> εγγραφές', BW/2, y, {align:'center'}); y+=4;

      const table = document.getElementById('expTable');
      const head  = [Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.replace(/[↕↑↓]/g,'').trim())];
      const body  = Array.from(table.querySelectorAll('tbody tr'))
                      .filter(r => !r.querySelector('td[colspan]'))
                      .map(r => Array.from(r.cells).map(c => c.innerText.trim()));

      doc.autoTable({
        startY: y, head, body,
        styles:             { fontSize:7.5, cellPadding:2.5, font:'helvetica', textColor:0, fillColor:255 },
        headStyles:         { fillColor:30, textColor:255, fontStyle:'bold', fontSize:8 },
        alternateRowStyles: { fillColor:245 },
        tableLineColor: 150, tableLineWidth: 0.2,
        margin: { left:10, right:10 }
      });

      doc.save('<?= h($section) ?>_' + new Date().toISOString().slice(0,10) + '.pdf');
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-file-pdf"></i> PDF (B&W)';
    }).catch(err => {
      console.error(err);
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-file-pdf"></i> PDF (B&W)';
      alert('Αποτυχία δημιουργίας PDF. Δοκιμάστε CSV ή Εκτύπωση.');
    });
}
</script>