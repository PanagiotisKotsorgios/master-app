<?php
/**
 * pages/payment_analytics.php — Analytical payment report (Pro-only)
 *
 * • Per-athlete × per-month matrix
 * • Totals per athlete, per department
 * • Debtors list
 * • Filters: period, department, status, athlete name
 * • Print view + XLSX export
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();

$sid  = schoolId();
$plan = schoolPlan();
$isSA = isSuperAdmin();

// Pro-gated
if (!$isSA && ($plan['slug'] ?? 'basic') !== 'pro') {
    renderHead('Αναλυτικά Πληρωμών');
    ?>
    <body>
    <div class="app-layout">
    <?php renderSidebar('payment_analytics'); ?>
    <div class="main-content">
    <?php renderTopbar('Αναλυτικά Πληρωμών'); ?>
    <div class="page-body" style="display:flex;align-items:center;justify-content:center;min-height:60vh">
      <div style="max-width:520px;text-align:center;background:#111520;border:1px solid #1e2536;border-radius:16px;padding:2rem">
        <i class="fa-solid fa-star" style="color:#f0a500;font-size:2.5rem;margin-bottom:.75rem"></i>
        <h2 style="margin:.25rem 0 .5rem">Απαιτείται πλάνο Pro</h2>
        <p style="color:#8892b0;margin-bottom:1.25rem">Τα αναλυτικά στατιστικά πληρωμών (ανά αθλητή × μήνα, ανά τμήμα, εκκρεμότητες, XLSX export) είναι διαθέσιμα στους Pro συνδρομητές.</p>
        <a href="<?= APP_URL ?>/pages/upgrade.php" class="btn btn-primary" style="background:#e63946;color:#fff;padding:.7rem 1.4rem;border-radius:10px;text-decoration:none;font-weight:700">
          <i class="fa-solid fa-arrow-up"></i> Αναβάθμιση σε Pro
        </a>
      </div>
    </div></div></div>
    </body></html>
    <?php
    exit;
}

// ── Filters ─────────────────────────────────────────────────────
$db = getDB();

$to   = $_GET['to']   ?? date('Y-m');
$from = $_GET['from'] ?? date('Y-m', strtotime('-5 months'));
if (!preg_match('/^\d{4}-\d{2}$/', $from)) $from = date('Y-m', strtotime('-5 months'));
if (!preg_match('/^\d{4}-\d{2}$/', $to))   $to   = date('Y-m');
if ($from > $to) { [$from, $to] = [$to, $from]; }

$deptFilter = (int)($_GET['dept'] ?? 0);
$statusFilter = in_array($_GET['status'] ?? 'all', ['all','paid','pending','overdue','partial'], true)
              ? ($_GET['status'] ?? 'all') : 'all';
$q  = trim((string)($_GET['q'] ?? ''));

// Build list of months in range
$months = [];
$cur = $from;
while ($cur <= $to && count($months) < 36) {
    $months[] = $cur;
    $t = strtotime($cur . '-01 +1 month');
    $cur = date('Y-m', $t);
}

$curMonth = date('Y-m');

// ── Fetch athletes + department names ────────────────────────────
$sql = "SELECT a.id, a.full_name, a.monthly_fee, a.department_id, a.active,
               d.name AS dept_name
        FROM athletes a
        LEFT JOIN departments d ON d.id = a.department_id
        WHERE a.school_id = ? AND a.active = 1";
$params = [$sid];
if ($deptFilter > 0) { $sql .= " AND a.department_id = ?"; $params[] = $deptFilter; }
if ($q !== '')       { $sql .= " AND a.full_name LIKE ?"; $params[] = '%' . $q . '%'; }
$sql .= " ORDER BY d.name IS NULL, d.name, a.full_name";
$athStmt = $db->prepare($sql);
$athStmt->execute($params);
$athletes = $athStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch payments in range for these athletes ──────────────────
$paymentMap = [];   // [athlete_id][YYYY-MM] = amount
if ($athletes) {
    $ids = array_column($athletes, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $pStmt = $db->prepare("
        SELECT athlete_id, month, amount, paid_at
        FROM payments
        WHERE school_id = ? AND athlete_id IN ($ph)
          AND month BETWEEN ? AND ?
    ");
    $pStmt->execute(array_merge([$sid], $ids, [$from, $to]));
    foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $key = (int)$p['athlete_id'];
        $m   = $p['month'];
        // Only count paid rows (paid_at IS NOT NULL) towards "paid amount"
        $paidAmt = $p['paid_at'] ? (float)$p['amount'] : 0.0;
        $paymentMap[$key][$m] = ($paymentMap[$key][$m] ?? 0.0) + $paidAmt;
    }
}

// ── Build cell status + totals ──────────────────────────────────
$grandCollected = 0.0;
$grandExpected  = 0.0;
$deptTotals     = [];   // [dept_name] = ['collected'=>, 'expected'=>]
$debtors        = [];   // [athlete_id] => ['name'=>, 'dept'=>, 'debt'=>]
$matrix         = [];   // [athlete_id][month] = ['paid'=>, 'expected'=>, 'status'=>]

foreach ($athletes as $a) {
    $aid    = (int)$a['id'];
    $fee    = (float)$a['monthly_fee'];
    $dName  = $a['dept_name'] ?: '— Χωρίς τμήμα —';
    $rowCollected = 0.0;
    $rowExpected  = 0.0;
    $rowDebt      = 0.0;

    foreach ($months as $m) {
        $paid = (float)($paymentMap[$aid][$m] ?? 0);
        $isPast = ($m < $curMonth);
        $isCur  = ($m === $curMonth);
        $exp    = $fee;  // future/current expected also = fee (no gating on future for expected)

        if ($paid >= $exp && $exp > 0)          $status = 'paid';
        elseif ($paid > 0 && $paid < $exp)      $status = 'partial';
        elseif ($isPast && $exp > 0)            $status = 'overdue';
        elseif ($isCur && $exp > 0)             $status = 'pending';
        else                                    $status = ($exp == 0 ? 'noop' : 'future');

        $matrix[$aid][$m] = ['paid' => $paid, 'expected' => $exp, 'status' => $status];
        $rowCollected += $paid;
        // Only count as expected for periods up to & including current month
        if ($m <= $curMonth) {
            $rowExpected += $exp;
            $rowDebt += max(0, $exp - $paid);
        }
    }

    $matrix[$aid]['_totals'] = [
        'collected' => $rowCollected,
        'expected'  => $rowExpected,
        'debt'      => $rowDebt,
        'name'      => $a['full_name'],
        'dept'      => $dName,
    ];

    $grandCollected += $rowCollected;
    $grandExpected  += $rowExpected;
    if (!isset($deptTotals[$dName])) {
        $deptTotals[$dName] = ['collected' => 0.0, 'expected' => 0.0, 'debt' => 0.0, 'athletes' => 0];
    }
    $deptTotals[$dName]['collected'] += $rowCollected;
    $deptTotals[$dName]['expected']  += $rowExpected;
    $deptTotals[$dName]['debt']      += $rowDebt;
    $deptTotals[$dName]['athletes']  += 1;

    if ($rowDebt > 0) {
        $debtors[$aid] = [
            'id'   => $aid,
            'name' => $a['full_name'],
            'dept' => $dName,
            'debt' => $rowDebt,
        ];
    }
}

// Filter matrix by status (post-hoc — cells still visible, but table filters rows if all cells match no filter)
if ($statusFilter !== 'all') {
    $matrix = array_filter($matrix, function($row) use ($months, $statusFilter) {
        if (isset($row['_totals']) && count($row) === 1) return false;
        foreach ($months as $m) {
            if (isset($row[$m]) && $row[$m]['status'] === $statusFilter) return true;
        }
        return false;
    });
}

$grandDebt = max(0.0, $grandExpected - $grandCollected);
$debtorCount = count($debtors);
usort($debtors, fn($a, $b) => $b['debt'] <=> $a['debt']);

// ── Departments dropdown data ───────────────────────────────────
$depts = $db->prepare("SELECT id, name FROM departments WHERE school_id=? ORDER BY name");
$depts->execute([$sid]);
$deptOptions = $depts->fetchAll(PDO::FETCH_ASSOC);

// ── XLSX Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/xlsx_writer.php';
    $xw = new XlsxWriter();

    // Sheet 1: Matrix (athlete × month)
    $headers = ['Αθλητής', 'Τμήμα'];
    foreach ($months as $m) $headers[] = $m;
    $headers[] = 'Εισπραχθέν';
    $headers[] = 'Αναμενόμενο';
    $headers[] = 'Οφειλή';
    $matrixRows = [$headers];
    foreach ($matrix as $aid => $row) {
        if (!isset($row['_totals'])) continue;
        $t = $row['_totals'];
        $line = [$t['name'], $t['dept']];
        foreach ($months as $m) {
            $line[] = isset($row[$m]) ? (float)$row[$m]['paid'] : 0.0;
        }
        $line[] = (float)$t['collected'];
        $line[] = (float)$t['expected'];
        $line[] = (float)$t['debt'];
        $matrixRows[] = $line;
    }
    $xw->addSheet('Ανά αθλητή × μήνα', $matrixRows, ['freezeHeader' => true]);

    // Sheet 2: Per department
    $deptRows = [['Τμήμα', 'Αθλητές', 'Εισπραχθέν', 'Αναμενόμενο', 'Οφειλή', 'Συλλεκτ. %']];
    foreach ($deptTotals as $dName => $dt) {
        $pct = $dt['expected'] > 0 ? round(100 * $dt['collected'] / $dt['expected'], 1) : 0;
        $deptRows[] = [$dName, (int)$dt['athletes'], (float)$dt['collected'], (float)$dt['expected'], (float)$dt['debt'], $pct];
    }
    $xw->addSheet('Ανά τμήμα', $deptRows, ['freezeHeader' => true]);

    // Sheet 3: Debtors
    $debtRows = [['Αθλητής', 'Τμήμα', 'Οφειλή']];
    foreach ($debtors as $d) {
        $debtRows[] = [$d['name'], $d['dept'], (float)$d['debt']];
    }
    $xw->addSheet('Εκκρεμότητες', $debtRows, ['freezeHeader' => true]);

    $filename = 'payment_analytics_' . $from . '_' . $to . '.xlsx';
    $xw->send($filename);
    exit;
}

// ── HTML / Print view flag ───────────────────────────────────────
$isPrint = ($_GET['view'] ?? '') === 'print';

renderHead('Αναλυτικά Πληρωμών' . ($isPrint ? ' — Εκτύπωση' : ''));
?>
<style>
.main-content { overflow-x: hidden !important; min-width: 0 !important; }
.page-body    { animation: fadeIn .35s ease both; padding: 1.5rem; }
@keyframes fadeIn { from { opacity: 0 } to { opacity: 1 } }
@media (max-width: 900px) {
  #menuBtn { display: inline-flex !important; min-width: 44px !important; min-height: 44px !important;
             align-items: center !important; justify-content: center !important; font-size: 1.2rem !important; cursor: pointer !important; }
  .sidebar { position: fixed !important; top: 0 !important; left: 0 !important; bottom: 0 !important;
             width: min(280px, 80vw) !important; z-index: 9999 !important;
             transform: translateX(-110%) !important;
             transition: transform .28s cubic-bezier(.2,.8,.2,1) !important;
             overflow-y: auto; -webkit-overflow-scrolling: touch; }
  .sidebar.open { transform: translateX(0) !important; box-shadow: 6px 0 40px rgba(0,0,0,.6) !important; }
  .main-content { margin-left: 0 !important; width: 100% !important; }
  .page-body { padding: 1rem !important; }
}
#dm-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9998; cursor: pointer; }
#dm-overlay.on { display: block; }

/* ── Analytics-specific ── */
.pa-filters { background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1rem 1.1rem;margin-bottom:1rem;
              display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;align-items:end }
.pa-filters label { display:block;font-size:.72rem;font-weight:700;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem }
.pa-filters input, .pa-filters select {
    width:100%;padding:.55rem .7rem;background:#0d1117;border:1.5px solid #1e2536;border-radius:8px;color:#f0f2ff;font-size:.9rem;font-family:inherit
}
.pa-filters .actions { display:flex;gap:.5rem;flex-wrap:wrap;grid-column:1/-1;margin-top:.35rem }
.pa-actions-top { display:flex;gap:.55rem;flex-wrap:wrap;margin-bottom:1rem;justify-content:flex-end }
.pa-actions-top a {
  padding:.7rem 1.2rem;border-radius:10px;
  font-weight:800;font-size:.95rem;
  text-decoration:none;
  display:inline-flex;align-items:center;gap:.55rem;
  min-height:44px;letter-spacing:.01em;
  transition:transform .15s, box-shadow .15s, background .15s, color .15s;
}
.pa-actions-top a:hover { transform:translateY(-1px) }
.pa-actions-top .p-xlsx {
  background:linear-gradient(135deg,#22c55e 0%,#16a34a 100%);
  color:#ffffff !important;
  box-shadow:0 6px 18px -6px rgba(34,197,94,.55), inset 0 0 0 1px rgba(255,255,255,.15);
}
.pa-actions-top .p-xlsx i { color:#ffffff !important }
.pa-actions-top .p-xlsx:hover {
  background:linear-gradient(135deg,#16a34a 0%,#118a3f 100%);
  box-shadow:0 10px 24px -8px rgba(34,197,94,.65), inset 0 0 0 1px rgba(255,255,255,.2);
}
.pa-actions-top .p-print {
  background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%);
  color:#ffffff !important;
  box-shadow:0 6px 18px -6px rgba(59,130,246,.55), inset 0 0 0 1px rgba(255,255,255,.15);
}
.pa-actions-top .p-print i { color:#ffffff !important }
.pa-actions-top .p-print:hover {
  background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 100%);
  box-shadow:0 10px 24px -8px rgba(59,130,246,.65), inset 0 0 0 1px rgba(255,255,255,.2);
}

.pa-kpis { display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;margin-bottom:1.1rem }
.pa-kpi { background:#111520;border:1px solid #1e2536;border-radius:14px;padding:.9rem 1rem;display:flex;flex-direction:column;gap:.15rem }
.pa-kpi .lbl { font-size:.72rem;font-weight:700;color:#8892b0;text-transform:uppercase;letter-spacing:.08em }
.pa-kpi .val { font-family:'Bebas Neue',sans-serif;font-size:2rem;line-height:1;font-weight:400 }
.pa-kpi.g .val { color:#2dc653 }
.pa-kpi.r .val { color:#e63946 }
.pa-kpi.o .val { color:#f0a500 }
.pa-kpi.b .val { color:#3b82f6 }

.pa-card { background:#111520;border:1px solid #1e2536;border-radius:14px;overflow:hidden;margin-bottom:1.1rem }
.pa-card .head { padding:.75rem 1.1rem;border-bottom:1px solid #1e2536;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem }
.pa-card .head h3 { margin:0;font-size:.98rem;font-weight:800 }
.pa-card .head small { color:#8892b0;font-size:.78rem }

.pa-scroll { overflow-x:auto }
.pa-matrix { width:100%;border-collapse:collapse;font-size:.82rem;min-width:100% }
.pa-matrix th, .pa-matrix td { padding:.45rem .55rem;text-align:right;white-space:nowrap;border-bottom:1px solid rgba(255,255,255,.04) }
.pa-matrix th { background:#0d1117;color:#8892b0;font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;position:sticky;top:0;z-index:1 }
.pa-matrix td.name { text-align:left;font-weight:700;color:#f0f2ff;position:sticky;left:0;background:#111520;z-index:2 }
.pa-matrix th.name { text-align:left;position:sticky;left:0;background:#0d1117;z-index:3 }
.pa-matrix .dept  { text-align:left;color:#8892b0;font-size:.77rem }
.pa-matrix tbody tr:hover td { background:rgba(255,255,255,.03) }
.pa-matrix tbody tr:hover td.name { background:#141a29 }
.pa-matrix td.total { font-weight:800;background:rgba(45,198,83,.05) }
.pa-matrix td.owe   { font-weight:800;background:rgba(230,57,70,.06);color:#ff8891 }

.pa-cell { display:inline-flex;align-items:center;justify-content:flex-end;gap:.3rem;min-width:52px }
.pa-cell.paid    { color:#8fe6a1 }
.pa-cell.partial { color:#fcd34d }
.pa-cell.overdue { color:#ff8891 }
.pa-cell.pending { color:#93c5fd }
.pa-cell.future  { color:#4a5270 }
.pa-cell.noop    { color:#4a5270 }
.pa-cell .dot { width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0 }

.pa-simple { width:100%;border-collapse:collapse;font-size:.9rem }
.pa-simple th, .pa-simple td { padding:.6rem .85rem;text-align:left;border-bottom:1px solid rgba(255,255,255,.04) }
.pa-simple th { background:#0d1117;color:#8892b0;font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em }
.pa-simple td.num { text-align:right;font-weight:700 }
.pa-simple .r { color:#ff8891 }
.pa-simple .g { color:#8fe6a1 }

/* Print */
@media print {
  body { background:#fff !important; color:#000 !important }
  .sidebar, .topbar, #menuBtn, #dm-overlay, .pa-filters, .pa-actions-top, .no-print { display:none !important }
  .main-content { margin-left:0 !important; width:100% !important }
  .page-body { padding:0 !important }
  .pa-card, .pa-kpi { background:#fff !important; border:1px solid #ccc !important; color:#000 !important; box-shadow:none !important }
  .pa-kpi .val { color:#000 !important }
  .pa-matrix th, .pa-matrix td { color:#000 !important; background:#fff !important; border-color:#ddd !important }
  .pa-matrix td.name { background:#f7f7f7 !important }
  .pa-cell.paid { color:#0a7c31 !important }
  .pa-cell.overdue, .pa-cell.partial { color:#a01824 !important }
  h1, h2, h3 { color:#000 !important }
  a { color:#000 !important; text-decoration:none !important }
}
</style>

<body <?= $isPrint ? 'class="print-preview"' : '' ?>>
<div class="app-layout">
<?php renderSidebar('payment_analytics'); ?>
<div id="dm-overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('on')"></div>
<div class="main-content">
<?php renderTopbar('Αναλυτικά Πληρωμών'); ?>
<div class="page-body">

  <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem">
    <div>
      <h2 style="margin:0;font-size:1.35rem">Αναλυτικά Στατιστικά Πληρωμών</h2>
      <div style="color:#8892b0;font-size:.88rem;margin-top:.15rem">
        Περίοδος: <strong style="color:#f0f2ff"><?= h($from) ?> → <?= h($to) ?></strong> · <?= count($athletes) ?> αθλητές
      </div>
    </div>
    <div class="pa-actions-top no-print">
      <?php
        $qs = $_GET;
        $qs['export'] = 'xlsx';
        $xlsxUrl = APP_URL . '/pages/payment_analytics.php?' . http_build_query($qs);
        unset($qs['export']);
        $qs['view'] = 'print';
        $printUrl = APP_URL . '/pages/payment_analytics.php?' . http_build_query($qs);
      ?>
      <a href="<?= h($xlsxUrl) ?>" class="p-xlsx" title="Κατέβασμα ως Excel"><i class="fa-solid fa-file-excel"></i> Εξαγωγή XLSX</a>
      <a href="<?= h($printUrl) ?>" class="p-print" onclick="setTimeout(()=>window.print(),300)" title="Εκτύπωση"><i class="fa-solid fa-print"></i> Εκτύπωση</a>
    </div>
  </div>

  <form method="get" class="pa-filters no-print">
    <div>
      <label>Από (μήνας)</label>
      <input type="month" name="from" value="<?= h($from) ?>">
    </div>
    <div>
      <label>Έως (μήνας)</label>
      <input type="month" name="to" value="<?= h($to) ?>">
    </div>
    <div>
      <label>Τμήμα</label>
      <select name="dept">
        <option value="0">— Όλα τα τμήματα —</option>
        <?php foreach ($deptOptions as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Κατάσταση κελιού</label>
      <select name="status">
        <?php
          $sOpts = ['all'=>'Όλα','paid'=>'Πληρωμένα','partial'=>'Μερικώς','pending'=>'Τρέχων μήνας','overdue'=>'Ληξιπρόθεσμα'];
          foreach ($sOpts as $k=>$lbl):
        ?>
          <option value="<?= h($k) ?>" <?= $statusFilter===$k?'selected':'' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Αναζήτηση αθλητή</label>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="π.χ. Γιάννης">
    </div>
    <div class="actions">
      <button type="submit" class="btn btn-primary" style="background:#e63946;color:#fff;border:none;padding:.55rem 1rem;border-radius:8px;font-weight:700;cursor:pointer">
        <i class="fa-solid fa-magnifying-glass"></i> Εφαρμογή
      </button>
      <a href="<?= APP_URL ?>/pages/payment_analytics.php" style="background:rgba(255,255,255,.06);color:#f0f2ff;border:1px solid #1e2536;padding:.55rem 1rem;border-radius:8px;font-weight:700;text-decoration:none">
        <i class="fa-solid fa-rotate-left"></i> Καθαρισμός
      </a>
    </div>
  </form>

  <div class="pa-kpis">
    <div class="pa-kpi g">
      <div class="lbl">Εισπραχθέντα</div>
      <div class="val"><?= number_format($grandCollected, 2, ',', '.') ?> €</div>
    </div>
    <div class="pa-kpi o">
      <div class="lbl">Αναμενόμενα</div>
      <div class="val"><?= number_format($grandExpected, 2, ',', '.') ?> €</div>
    </div>
    <div class="pa-kpi r">
      <div class="lbl">Οφειλές</div>
      <div class="val"><?= number_format($grandDebt, 2, ',', '.') ?> €</div>
    </div>
    <div class="pa-kpi b">
      <div class="lbl">Αθλητές με χρέος</div>
      <div class="val"><?= $debtorCount ?></div>
    </div>
  </div>

  <div class="pa-card">
    <div class="head">
      <h3><i class="fa-solid fa-table" style="color:#e63946"></i> Πίνακας ανά αθλητή × μήνα</h3>
      <small><?= count($months) ?> μήνες</small>
    </div>
    <div class="pa-scroll">
      <table class="pa-matrix">
        <thead>
          <tr>
            <th class="name">Αθλητής / Τμήμα</th>
            <?php foreach ($months as $m): ?>
              <th title="<?= h($m) ?>"><?= h($m) ?></th>
            <?php endforeach; ?>
            <th>Σύνολο</th>
            <th>Αναμ.</th>
            <th>Οφειλή</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($matrix)): ?>
          <tr><td class="name" colspan="<?= count($months) + 4 ?>" style="text-align:center;color:#8892b0;padding:2rem">
            <i class="fa-solid fa-inbox" style="font-size:1.8rem;display:block;margin-bottom:.5rem;opacity:.5"></i>
            Δεν βρέθηκαν αθλητές με τα φίλτρα που έβαλες.
          </td></tr>
        <?php else: foreach ($matrix as $aid => $row):
          if (!isset($row['_totals'])) continue;
          $t = $row['_totals'];
        ?>
          <tr>
            <td class="name">
              <a href="<?= APP_URL ?>/pages/athletes.php?view=<?= (int)$aid ?>" style="color:inherit;text-decoration:none">
                <?= h($t['name']) ?>
                <div class="dept"><?= h($t['dept']) ?></div>
              </a>
            </td>
            <?php foreach ($months as $m):
              $c = $row[$m] ?? ['paid'=>0,'expected'=>0,'status'=>'noop'];
              $paid = (float)$c['paid'];
              $disp = $paid > 0 ? number_format($paid, 2, ',', '.') : ($c['status']==='future' ? '—' : '0');
            ?>
              <td>
                <span class="pa-cell <?= h($c['status']) ?>" title="<?= h($m) ?> — <?= h($c['status']) ?>">
                  <span class="dot"></span>
                  <?= $disp ?>
                </span>
              </td>
            <?php endforeach; ?>
            <td class="total"><?= number_format($t['collected'], 2, ',', '.') ?></td>
            <td><?= number_format($t['expected'], 2, ',', '.') ?></td>
            <td class="owe"><?= $t['debt'] > 0 ? number_format($t['debt'], 2, ',', '.') : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="pa-card">
    <div class="head">
      <h3><i class="fa-solid fa-folder-tree" style="color:#3b82f6"></i> Ανά τμήμα</h3>
      <small><?= count($deptTotals) ?> τμήματα</small>
    </div>
    <div class="pa-scroll">
      <table class="pa-simple">
        <thead>
          <tr>
            <th>Τμήμα</th>
            <th style="text-align:right">Αθλητές</th>
            <th style="text-align:right">Εισπραχθέν</th>
            <th style="text-align:right">Αναμενόμενο</th>
            <th style="text-align:right">Οφειλή</th>
            <th style="text-align:right">Συλλεκτ. %</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($deptTotals)): ?>
          <tr><td colspan="6" style="text-align:center;color:#8892b0;padding:1.5rem">Δεν υπάρχουν δεδομένα.</td></tr>
        <?php else: foreach ($deptTotals as $dName => $dt):
            $pct = $dt['expected'] > 0 ? round(100 * $dt['collected'] / $dt['expected'], 1) : 0;
        ?>
          <tr>
            <td><?= h($dName) ?></td>
            <td class="num"><?= (int)$dt['athletes'] ?></td>
            <td class="num g"><?= number_format($dt['collected'], 2, ',', '.') ?> €</td>
            <td class="num"><?= number_format($dt['expected'], 2, ',', '.') ?> €</td>
            <td class="num <?= $dt['debt']>0?'r':'' ?>"><?= number_format($dt['debt'], 2, ',', '.') ?> €</td>
            <td class="num"><?= $pct ?>%</td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="pa-card">
    <div class="head">
      <h3><i class="fa-solid fa-triangle-exclamation" style="color:#e63946"></i> Εκκρεμότητες</h3>
      <small><?= $debtorCount ?> αθλητές</small>
    </div>
    <div class="pa-scroll">
      <table class="pa-simple">
        <thead>
          <tr>
            <th>Αθλητής</th>
            <th>Τμήμα</th>
            <th style="text-align:right">Οφειλή</th>
            <th class="no-print" style="width:120px"></th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($debtors)): ?>
          <tr><td colspan="4" style="text-align:center;color:#8fe6a1;padding:1.5rem">
            <i class="fa-solid fa-circle-check"></i> Όλες οι πληρωμές είναι ενήμερες.
          </td></tr>
        <?php else: foreach ($debtors as $d): ?>
          <tr>
            <td><strong style="color:#f0f2ff"><?= h($d['name']) ?></strong></td>
            <td style="color:#8892b0"><?= h($d['dept']) ?></td>
            <td class="num r"><?= number_format($d['debt'], 2, ',', '.') ?> €</td>
            <td class="no-print">
              <a href="<?= APP_URL ?>/pages/athletes.php?view=<?= (int)$d['id'] ?>"
                 style="background:rgba(230,57,70,.12);color:#ff8891;border:1px solid rgba(230,57,70,.3);padding:.35rem .7rem;border-radius:8px;font-size:.78rem;font-weight:700;text-decoration:none">
                <i class="fa-solid fa-eye"></i> Δες
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /page-body -->
</div><!-- /main-content -->
</div><!-- /app-layout -->

<?php if ($isPrint): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
<?php endif; ?>

</body>
</html>
