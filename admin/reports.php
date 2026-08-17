<?php
/**
 * ============================================================
 * admin/reports.php — Advanced Reports & Analytics (Super Admin)
 * ============================================================
 */

// ── Error Display & Logging ──────────────────────────────────────────────
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);
if (isset($_GET['debug'])) {
    ini_set('display_errors', 1);
} else {
    ini_set('display_errors', 0);
    set_exception_handler(function(\Throwable $e) {
        $file = basename($e->getFile());
        error_log('[reports.php] EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        echo '<div style="background:#0d1117;color:#e63946;padding:1.5rem 2rem;font-family:monospace;border:1px solid rgba(230,57,70,.3);border-radius:10px;margin:1.5rem;max-width:900px">';
        echo '<strong style="font-size:1.1rem">⚠ Σφάλμα Συστήματος</strong><br><hr style="border-color:rgba(230,57,70,.2);margin:.75rem 0">';
        echo '<span style="color:#f0a500">Τύπος:</span> ' . get_class($e) . '<br>';
        echo '<span style="color:#f0a500">Μήνυμα:</span> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '<br>';
        echo '<span style="color:#f0a500">Αρχείο:</span> ' . htmlspecialchars($file, ENT_QUOTES) . ' — Γραμμή ' . $e->getLine() . '<br>';
        echo '</div>';
        exit;
    });
    set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
        $log = basename($errfile);
        if ($errno & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            error_log("[{$log}] FATAL ERROR [{$errno}]: {$errstr} on line {$errline}");
        } elseif ($errno & (E_WARNING | E_NOTICE | E_DEPRECATED)) {
            error_log("[{$log}] WARNING [{$errno}]: {$errstr} on line {$errline}");
        }
        return false;
    });
}
// Ensure logs directory exists
@mkdir(__DIR__ . '/../logs', 0750, true);
// ──────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

$tab = $_GET['tab'] ?? 'revenue';

// ── Revenue Analytics ──────────────────────────────────────────────────────
$revenueMonthly = [];
for ($i = 11; $i >= 0; $i--) {
    $m   = date('Y-m', strtotime("-$i months"));
    $s   = "$m-01";
    $e   = date('Y-m-t', strtotime($s));
    $rev = $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE paid_at BETWEEN '$s' AND '$e 23:59:59'")->fetchColumn();
    $cnt = $db->query("SELECT COUNT(*) FROM school_plan_payments WHERE paid_at BETWEEN '$s' AND '$e 23:59:59'")->fetchColumn();
    $revenueMonthly[] = ['month' => date('M y', strtotime($s)), 'revenue' => (float)$rev, 'count' => (int)$cnt];
}

// Revenue by plan
$revenueByPlan = $db->query("
    SELECT p.name, p.slug, COALESCE(SUM(spp.amount),0) as total, COUNT(spp.id) as cnt
    FROM plans p
    LEFT JOIN school_plan_payments spp ON spp.school_id IN (SELECT id FROM schools WHERE plan_id=p.id)
    GROUP BY p.id ORDER BY total DESC
")->fetchAll();

// Total revenue
$totalRevAll  = $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments")->fetchColumn();
$totalRevYear = $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE paid_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)")->fetchColumn();
$totalRevMonth= $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
$avgOrderVal  = $db->query("SELECT COALESCE(AVG(amount),0) FROM school_plan_payments")->fetchColumn();

// ── Growth Analytics ───────────────────────────────────────────────────────
$growthData = [];
for ($i = 11; $i >= 0; $i--) {
    $m   = date('Y-m', strtotime("-$i months"));
    $s   = "$m-01";
    $e   = date('Y-m-t', strtotime($s));
    $newSchools  = $db->query("SELECT COUNT(*) FROM schools WHERE created_at BETWEEN '$s' AND '$e 23:59:59'")->fetchColumn();
    $newAthletes = $db->query("SELECT COUNT(*) FROM athletes WHERE created_at BETWEEN '$s' AND '$e 23:59:59'")->fetchColumn();
    $newUsers    = $db->query("SELECT COUNT(*) FROM users WHERE created_at BETWEEN '$s' AND '$e 23:59:59' AND role != 'superadmin'")->fetchColumn();
    $growthData[] = ['month' => date('M y', strtotime($s)), 'schools' => (int)$newSchools, 'athletes' => (int)$newAthletes, 'users' => (int)$newUsers];
}

// Cumulative schools
$cumulativeSchools = [];
$running = 0;
foreach ($growthData as $g) {
    $running += $g['schools'];
    $cumulativeSchools[] = $running;
}

// ── Retention / Cohort ─────────────────────────────────────────────────────
$conversionRate = $db->query("
    SELECT ROUND(COUNT(CASE WHEN s.plan_status='active' AND p.slug != 'trial' THEN 1 END) * 100.0 / NULLIF(COUNT(*),0), 1)
    FROM schools s JOIN plans p ON p.id=s.plan_id
")->fetchColumn();

$trialToActive = $db->query("SELECT COUNT(*) FROM schools WHERE plan_status='active'")->fetchColumn();
$totalEver     = $db->query("SELECT COUNT(*) FROM schools")->fetchColumn();

// ── Top Performing Schools ─────────────────────────────────────────────────
$topSchoolsByAthletes = $db->query("
    SELECT s.id, s.name, s.email, p.slug as plan_slug,
           (SELECT COUNT(*) FROM athletes a WHERE a.school_id=s.id AND a.active=1) as athletes,
           (SELECT COUNT(*) FROM subscriptions sub WHERE sub.school_id=s.id AND sub.status='paid') as paid_subs,
           (SELECT COALESCE(SUM(amount),0) FROM school_plan_payments spp WHERE spp.school_id=s.id) as total_paid
    FROM schools s JOIN plans p ON p.id=s.plan_id
    WHERE s.active=1
    ORDER BY athletes DESC LIMIT 10
")->fetchAll();

// ── Subscription Analytics ────────────────────────────────────────────────
$subStats = [
    'total'   => $db->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn(),
    'paid'    => $db->query("SELECT COUNT(*) FROM subscriptions WHERE status='paid'")->fetchColumn(),
    'overdue' => $db->query("SELECT COUNT(*) FROM subscriptions WHERE status='overdue'")->fetchColumn(),
    'pending' => $db->query("SELECT COUNT(*) FROM subscriptions WHERE status='pending'")->fetchColumn(),
];
$subPaymentRate = $subStats['total'] > 0 ? round($subStats['paid'] / $subStats['total'] * 100, 1) : 0;

// Monthly subscriptions paid
$subMonthly = [];
for ($i = 5; $i >= 0; $i--) {
    $m  = date('Y-m', strtotime("-$i months"));
    $s  = "$m-01"; $e = date('Y-m-t', strtotime($s));
    $pc = $db->query("SELECT COUNT(*) FROM subscriptions WHERE status='paid' AND paid_at BETWEEN '$s' AND '$e'")->fetchColumn();
    $oc = $db->query("SELECT COUNT(*) FROM subscriptions WHERE status='overdue' AND paid_at BETWEEN '$s' AND '$e'")->fetchColumn();
    $subMonthly[] = ['month' => date('M', strtotime($s)), 'paid' => (int)$pc, 'overdue' => (int)$oc];
}

// ── Email & SMS Analytics ─────────────────────────────────────────────────
$emailTotal  = $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='email'")->fetchColumn();
$emailSent   = $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='email' AND status='sent'")->fetchColumn();
$emailFailed = $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='email' AND status='failed'")->fetchColumn();
$smsTotal    = $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='sms'")->fetchColumn();
$smsSent     = $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='sms' AND status='sent'")->fetchColumn();
$smsFailed   = $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='sms' AND status='failed'")->fetchColumn();

// Top schools by email/SMS usage
$topByComms = $db->query("
    SELECT name, emails, sms
    FROM (
        SELECT s.name,
               SUM(CASE WHEN rl.type='email' AND rl.status='sent' THEN 1 ELSE 0 END) as emails,
               SUM(CASE WHEN rl.type='sms'   AND rl.status='sent' THEN 1 ELSE 0 END) as sms
        FROM reminder_logs rl JOIN schools s ON s.id=rl.school_id
        GROUP BY rl.school_id, s.name
    ) t
    ORDER BY (emails + sms) DESC LIMIT 8
")->fetchAll();

// ── CSV Export ─────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $export = $_GET['export'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $export . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    if ($export === 'revenue') {
        fputcsv($out, ['Μήνας', 'Έσοδα (€)', 'Αριθμός Πληρωμών']);
        foreach ($revenueMonthly as $r) fputcsv($out, [$r['month'], number_format($r['revenue'],2,',','.'), $r['count']]);
    } elseif ($export === 'schools') {
        fputcsv($out, ['Σχολή', 'Email', 'Πλάνο', 'Αθλητές', 'Πληρωμένες Συνδρομές', 'Σύνολο Πληρωμών (€)']);
        foreach ($topSchoolsByAthletes as $s) fputcsv($out, [$s['name'], $s['email'], $s['plan_slug'], $s['athletes'], $s['paid_subs'], number_format($s['total_paid'],2,',','.')]);
    }
    fclose($out); exit;
}

renderHead('Αναφορές & Αναλύσεις');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
body { font-size: 15px; }
.page-body { padding: 1.75rem !important; }
.card { border-radius: 14px !important; }
.card-title { font-size: 1rem !important; font-weight: 700 !important; }
table { font-size: .9rem !important; }
thead th { font-size: .75rem !important; padding: .7rem 1rem !important; letter-spacing: .07em; }
tbody td { padding: .75rem 1rem !important; font-size: .88rem !important; }
.badge { font-size: .72rem !important; padding: .22rem .6rem !important; border-radius: 50px !important; font-weight: 700 !important; }
.btn { font-size: .875rem !important; padding: .5rem 1.05rem !important; border-radius: 9px !important; font-weight: 500 !important; }
.btn-sm { font-size: .8rem !important; padding: .32rem .65rem !important; }
.stat-card { border-radius: 14px !important; padding: 1.35rem !important; }
.stat-card .stat-val { font-size: 2.1rem !important; font-weight: 800 !important; }
.stat-card .stat-lbl { font-size: .82rem !important; }
.stat-card .stat-icon { width: 46px !important; height: 46px !important; font-size: 1.3rem !important; border-radius: 12px !important; }
.text-muted { color: var(--muted) !important; }
.text-green { color: var(--green) !important; }
.text-red { color: var(--red) !important; }
h2 { font-size: 1.2rem !important; font-weight: 700 !important; }
.progress { height: 7px !important; }

.kpi-mini { background: var(--bg2); border-radius: 12px; padding: 1rem; text-align:center; }
.kpi-mini .v { font-size: 1.6rem; font-weight: 800; }
.kpi-mini .l { font-size: .75rem; color: var(--muted); margin-top: .2rem; }

@media(max-width:768px){ .page-body { padding: 1rem !important; } }
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_reports'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-chart-pie"></i> Αναφορές & Αναλύσεις'); ?>
<div class="page-body">

<!-- Tabs -->
<div class="tabs mb-3">
    <a href="?tab=revenue" class="tab <?= $tab==='revenue'?'active':'' ?>"><i class="fa-solid fa-euro-sign"></i> Έσοδα</a>
    <a href="?tab=growth"  class="tab <?= $tab==='growth'?'active':'' ?>"><i class="fa-solid fa-chart-line"></i> Ανάπτυξη</a>
    <a href="?tab=schools" class="tab <?= $tab==='schools'?'active':'' ?>"><i class="fa-solid fa-school"></i> Σχολές</a>
    <a href="?tab=subs"    class="tab <?= $tab==='subs'?'active':'' ?>"><i class="fa-solid fa-money-bill-wave"></i> Συνδρομές</a>
    <a href="?tab=comms"   class="tab <?= $tab==='comms'?'active':'' ?>"><i class="fa-solid fa-paper-plane"></i> Επικοινωνία</a>
</div>

<!-- ═══════════════ TAB: REVENUE ═══════════════ -->
<?php if ($tab === 'revenue'): ?>
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-euro-sign"></i></div>
        <div class="stat-val text-green"><?= formatMoney($totalRevAll) ?></div>
        <div class="stat-lbl">Σύνολο Εσόδων</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-calendar"></i></div>
        <div class="stat-val"><?= formatMoney($totalRevYear) ?></div>
        <div class="stat-lbl">Έσοδα 12 Μηνών</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-calendar-day"></i></div>
        <div class="stat-val"><?= formatMoney($totalRevMonth) ?></div>
        <div class="stat-lbl">Έσοδα Μήνα</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-receipt"></i></div>
        <div class="stat-val"><?= formatMoney($avgOrderVal) ?></div>
        <div class="stat-lbl">Μέση Αξία Πληρωμής</div>
    </div>
</div>

<div class="grid grid-2 mb-3">
    <div class="card">
        <div class="d-flex ai-center jc-between mb-3">
            <div class="card-title"><i class="fa-solid fa-chart-bar"></i> Μηνιαία Έσοδα (12μηνο)</div>
            <a href="?tab=revenue&export=revenue" class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i> CSV</a>
        </div>
        <canvas id="revenueChart" height="180"></canvas>
    </div>
    <div class="card">
        <div class="card-title mb-3"><i class="fa-solid fa-boxes-stacked"></i> Έσοδα ανά Πλάνο</div>
        <?php foreach ($revenueByPlan as $rp): $pct = $totalRevAll > 0 ? round($rp['total']/$totalRevAll*100) : 0; ?>
        <div class="mb-3">
            <div class="d-flex jc-between text-sm mb-1">
                <span><?= planBadge($rp['slug']) ?> <?= h($rp['name']) ?></span>
                <span class="fw-600"><?= formatMoney($rp['total']) ?> (<?= $pct ?>%)</span>
            </div>
            <div class="progress"><div class="progress-bar <?= $rp['slug']==='pro'?'gold':'' ?>" style="width:<?= $pct ?>%"></div></div>
            <div class="text-xs text-muted mt-1"><?= $rp['cnt'] ?> πληρωμές</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Monthly table -->
<div class="card p-0">
    <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title"><i class="fa-solid fa-table"></i> Λεπτομερής Πίνακας Εσόδων</div>
    </div>
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-rev" type="text" placeholder="Αναζήτηση εσόδων..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-revenue-monthly">
        <thead><tr><th>Μήνας</th><th>Έσοδα</th><th>Αριθμός Πληρωμών</th><th>Μέση Τιμή</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($revenueMonthly) as $rm): ?>
        <tr>
            <td class="fw-600"><?= h($rm['month']) ?></td>
            <td class="text-green fw-600"><?= formatMoney($rm['revenue']) ?></td>
            <td><?= $rm['count'] ?></td>
            <td><?= $rm['count'] > 0 ? formatMoney($rm['revenue'] / $rm['count']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div id="pg-revenue-monthly" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
</div>

<!-- ═══════════════ TAB: GROWTH ═══════════════ -->
<?php elseif ($tab === 'growth'): ?>
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-school"></i></div>
        <div class="stat-val"><?= $totalEver ?></div>
        <div class="stat-lbl">Σύνολο Σχολών</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-chart-line"></i></div>
        <div class="stat-val text-green"><?= $conversionRate ?>%</div>
        <div class="stat-lbl">Ποσοστό Μετατροπής</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-person-running"></i></div>
        <div class="stat-val"><?= $db->query("SELECT COUNT(*) FROM athletes WHERE active=1")->fetchColumn() ?></div>
        <div class="stat-lbl">Ενεργοί Αθλητές</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-users"></i></div>
        <div class="stat-val"><?= $db->query("SELECT COUNT(*) FROM users WHERE role!='superadmin'")->fetchColumn() ?></div>
        <div class="stat-lbl">Σύνολο Χρηστών</div>
    </div>
</div>

<div class="grid grid-2 mb-3">
    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-chart-line"></i> Νέες Εγγραφές ανά Μήνα</div>
        <canvas id="growthChart" height="190"></canvas>
    </div>
    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-chart-area"></i> Αθροιστικές Σχολές</div>
        <canvas id="cumulChart" height="190"></canvas>
    </div>
</div>

<!-- Growth table -->
<div class="card p-0">
    <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title"><i class="fa-solid fa-table"></i> Πίνακας Ανάπτυξης</div>
    </div>
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-growth" type="text" placeholder="Αναζήτηση ανάπτυξης..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-growth">
        <thead><tr><th>Μήνας</th><th>Νέες Σχολές</th><th>Νέοι Αθλητές</th><th>Νέοι Χρήστες</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($growthData) as $gd): ?>
        <tr>
            <td class="fw-600"><?= h($gd['month']) ?></td>
            <td><?= $gd['schools'] ?></td>
            <td><?= $gd['athletes'] ?></td>
            <td><?= $gd['users'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div id="pg-growth" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
</div>

<!-- ═══════════════ TAB: SCHOOLS ═══════════════ -->
<?php elseif ($tab === 'schools'): ?>
<div class="d-flex jc-between ai-center mb-3">
    <h2>Top Σχολές</h2>
    <a href="?tab=schools&export=schools" class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i> Export CSV</a>
</div>

<div class="card p-0 mb-3">
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-top-schools" type="text" placeholder="Αναζήτηση σχολών..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-top-schools">
        <thead><tr><th>#</th><th>Σχολή</th><th>Πλάνο</th><th>Ενεργοί Αθλητές</th><th>Πληρωμένες Συνδρομές</th><th>Σύνολο Πληρωμών</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($topSchoolsByAthletes as $i => $s): ?>
        <tr>
            <td class="text-muted fw-600"><?= $i+1 ?></td>
            <td>
                <div class="fw-600"><?= h($s['name']) ?></div>
                <div class="text-xs text-muted"><?= h($s['email']) ?></div>
            </td>
            <td><?= planBadge($s['plan_slug']) ?></td>
            <td><span class="fw-600 text-green"><?= $s['athletes'] ?></span></td>
            <td><?= $s['paid_subs'] ?></td>
            <td class="text-green fw-600"><?= formatMoney($s['total_paid']) ?></td>
            <td>
                <a href="<?= APP_URL ?>/admin/schools.php?edit=<?= $s['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-pen-to-square"></i></a>
                <a href="<?= APP_URL ?>/admin/schools.php?impersonate=<?= $s['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-user-secret"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div id="pg-top-schools" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
</div>

<!-- ═══════════════ TAB: SUBSCRIPTIONS ═══════════════ -->
<?php elseif ($tab === 'subs'): ?>
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-file-invoice"></i></div>
        <div class="stat-val"><?= number_format($subStats['total']) ?></div>
        <div class="stat-lbl">Σύνολο Συνδρομών</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-val text-green"><?= number_format($subStats['paid']) ?></div>
        <div class="stat-lbl">Πληρωμένες</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-red"><i class="fa-solid fa-circle-exclamation"></i></div>
        <div class="stat-val text-red"><?= number_format($subStats['overdue']) ?></div>
        <div class="stat-lbl">Ληξιπρόθεσμες</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-percent"></i></div>
        <div class="stat-val"><?= $subPaymentRate ?>%</div>
        <div class="stat-lbl">Ποσοστό Πληρωμής</div>
    </div>
</div>

<div class="grid grid-2 mb-3">
    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-chart-bar"></i> Συνδρομές ανά Μήνα</div>
        <canvas id="subsChart" height="190"></canvas>
    </div>
    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-chart-pie"></i> Κατανομή Κατάστασης</div>
        <canvas id="subsPieChart" height="190"></canvas>
    </div>
</div>

<!-- ═══════════════ TAB: COMMS ═══════════════ -->
<?php elseif ($tab === 'comms'): ?>
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-envelope"></i></div>
        <div class="stat-val"><?= number_format($emailSent) ?></div>
        <div class="stat-lbl">Emails Απεσταλμένα</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-comment-sms"></i></div>
        <div class="stat-val"><?= number_format($smsSent) ?></div>
        <div class="stat-lbl">SMS Απεσταλμένα</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-red"><i class="fa-solid fa-circle-xmark"></i></div>
        <div class="stat-val text-red"><?= number_format($emailFailed + $smsFailed) ?></div>
        <div class="stat-lbl">Αποτυχίες</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-percent"></i></div>
        <div class="stat-val text-green">
            <?= ($emailTotal + $smsTotal) > 0 ? round(($emailSent + $smsSent) / ($emailTotal + $smsTotal) * 100, 1) : 0 ?>%
        </div>
        <div class="stat-lbl">Επιτυχία Αποστολής</div>
    </div>
</div>

<div class="card p-0">
    <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title"><i class="fa-solid fa-ranking-star"></i> Top Σχολές σε Αποστολές</div>
    </div>
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-top-comms" type="text" placeholder="Αναζήτηση αποστολών..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-top-comms">
        <thead><tr><th>Σχολή</th><th>Emails</th><th>SMS</th><th>Σύνολο</th></tr></thead>
        <tbody>
        <?php foreach ($topByComms as $c): ?>
        <tr>
            <td class="fw-600"><?= h($c['name']) ?></td>
            <td><?= $c['emails'] ?></td>
            <td><?= $c['sms'] ?></td>
            <td class="fw-600"><?= $c['emails'] + $c['sms'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div id="pg-top-comms" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
</div>
<?php endif; ?>

</div></div></div>

<script>
<?php if ($tab === 'revenue'): ?>
new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($revenueMonthly, 'month')) ?>,
        datasets: [{
            label: 'Έσοδα €',
            data: <?= json_encode(array_column($revenueMonthly, 'revenue')) ?>,
            backgroundColor: 'rgba(42,157,92,.7)',
            borderColor: 'rgba(42,157,92,1)',
            borderWidth: 1, borderRadius: 4
        }]
    },
    options: { responsive:true, plugins:{legend:{display:false}}, scales:{ x:{ticks:{color:'#7a849e',font:{size:10}}}, y:{ticks:{color:'#7a849e'}} } }
});
<?php elseif ($tab === 'growth'): ?>
new Chart(document.getElementById('growthChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($growthData, 'month')) ?>,
        datasets: [
            { label: 'Σχολές', data: <?= json_encode(array_column($growthData, 'schools')) ?>, backgroundColor: 'rgba(230,57,70,.7)', borderRadius: 3 },
            { label: 'Αθλητές', data: <?= json_encode(array_column($growthData, 'athletes')) ?>, backgroundColor: 'rgba(67,97,238,.7)', borderRadius: 3 }
        ]
    },
    options: { responsive:true, plugins:{legend:{labels:{color:'#7a849e'}}}, scales:{ x:{ticks:{color:'#7a849e',font:{size:10}}}, y:{ticks:{color:'#7a849e'}} } }
});
new Chart(document.getElementById('cumulChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($growthData, 'month')) ?>,
        datasets: [{ label: 'Σχολές (σύνολο)', data: <?= json_encode($cumulativeSchools) ?>, fill:true, backgroundColor:'rgba(230,57,70,.1)', borderColor:'rgba(230,57,70,.8)', borderWidth:2, tension:0.4 }]
    },
    options: { responsive:true, plugins:{legend:{display:false}}, scales:{ x:{ticks:{color:'#7a849e',font:{size:10}}}, y:{ticks:{color:'#7a849e'}} } }
});
<?php elseif ($tab === 'subs'): ?>
new Chart(document.getElementById('subsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($subMonthly, 'month')) ?>,
        datasets: [
            { label: 'Πληρωμένες', data: <?= json_encode(array_column($subMonthly, 'paid')) ?>, backgroundColor: 'rgba(42,157,92,.7)', borderRadius:3 },
            { label: 'Ληξιπρόθεσμες', data: <?= json_encode(array_column($subMonthly, 'overdue')) ?>, backgroundColor: 'rgba(230,57,70,.7)', borderRadius:3 }
        ]
    },
    options: { responsive:true, plugins:{legend:{labels:{color:'#7a849e'}}}, scales:{ x:{ticks:{color:'#7a849e'}}, y:{ticks:{color:'#7a849e'}} } }
});
new Chart(document.getElementById('subsPieChart'), {
    type: 'doughnut',
    data: {
        labels: ['Πληρωμένες', 'Ληξιπρόθεσμες', 'Εκκρεμείς'],
        datasets: [{ data: [<?= $subStats['paid'] ?>, <?= $subStats['overdue'] ?>, <?= $subStats['pending'] ?>], backgroundColor: ['rgba(42,157,92,.8)','rgba(230,57,70,.8)','rgba(240,165,0,.8)'], borderWidth:0 }]
    },
    options: { responsive:true, plugins:{ legend:{ position:'bottom', labels:{ color:'#7a849e', padding:12 } } } }
});
<?php endif; ?>
function initPagination(tableId, ctrlId, perPage, searchId) {
    perPage = perPage || 10;
    var table = document.getElementById(tableId);
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;
    var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    if (allRows.length === 0) return;
    var currentPage = 1;
    var currentPerPage = perPage;
    var filteredRows = allRows.slice();
    function filterRows(q) {
        q = (q || '').toLowerCase().trim();
        filteredRows = q ? allRows.filter(function(r){ return r.textContent.toLowerCase().indexOf(q) !== -1; }) : allRows.slice();
        currentPage = 1;
        render(1);
    }
    function totalPages() { return Math.max(1, Math.ceil(filteredRows.length / currentPerPage)); }
    function render(page) {
        currentPage = Math.max(1, Math.min(page, totalPages()));
        allRows.forEach(function(r){ r.style.display = 'none'; });
        filteredRows.forEach(function(r, i) {
            r.style.display = (i >= (currentPage-1)*currentPerPage && i < currentPage*currentPerPage) ? '' : 'none';
        });
        var ctrl = document.getElementById(ctrlId);
        if (!ctrl) return;
        var tp = totalPages();
        var btns = '<div style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap">';
        btns += '<select class="pg-size-select" style="font-size:.8rem;padding:.28rem .5rem;border-radius:7px;border:1px solid var(--border,#1e2536);background:var(--card,#111827);color:var(--text,#e2e8f0);cursor:pointer;margin-right:.4rem">';
        [10,25,50,100].forEach(function(n) { btns += '<option value="'+n+'"'+(n===currentPerPage?' selected':'')+'>'+n+' / σελίδα</option>'; });
        btns += '</select>';
        btns += '<a href="#" class="page-btn'+(currentPage===1?' disabled':'')+'" data-page="1" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a>';
        btns += '<a href="#" class="page-btn'+(currentPage===1?' disabled':'')+'" data-page="'+(currentPage-1)+'" title="Προηγούμενη"><i class="fa-solid fa-chevron-left"></i></a>';
        var start = Math.max(1, currentPage-2), end = Math.min(tp, currentPage+2);
        if (start > 2) { btns += '<a href="#" class="page-btn" data-page="1">1</a>'; if (start > 3) btns += '<span class="page-btn" style="pointer-events:none">…</span>'; }
        for (var p = start; p <= end; p++) btns += '<a href="#" class="page-btn'+(p===currentPage?' active':'')+'" data-page="'+p+'">'+p+'</a>';
        if (end < tp - 1) { if (end < tp - 2) btns += '<span class="page-btn" style="pointer-events:none">…</span>'; btns += '<a href="#" class="page-btn" data-page="'+tp+'">'+tp+'</a>'; }
        btns += '<a href="#" class="page-btn'+(currentPage===tp?' disabled':'')+'" data-page="'+(currentPage+1)+'" title="Επόμενη"><i class="fa-solid fa-chevron-right"></i></a>';
        btns += '<a href="#" class="page-btn'+(currentPage===tp?' disabled':'')+'" data-page="'+tp+'" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a>';
        btns += '<span style="font-size:.8rem;color:var(--muted);margin-left:.4rem">'+filteredRows.length+' εγγραφές · '+currentPage+' / '+tp+'</span>';
        btns += '</div>';
        ctrl.innerHTML = btns;
        ctrl.querySelectorAll('[data-page]').forEach(function(a) {
            a.addEventListener('click', function(e) { e.preventDefault(); if (this.classList.contains('disabled')) return; render(parseInt(this.dataset.page)); });
        });
        var sel = ctrl.querySelector('.pg-size-select');
        if (sel) sel.addEventListener('change', function() { currentPerPage = parseInt(this.value); render(1); });
    }
    if (searchId) { var inp = document.getElementById(searchId); if (inp) inp.addEventListener('input', function(){ filterRows(this.value); }); }
    render(1);
}
initPagination('tbl-revenue-monthly', 'pg-revenue-monthly', 10, 'srch-rev');
initPagination('tbl-growth', 'pg-growth', 10, 'srch-growth');
initPagination('tbl-top-schools', 'pg-top-schools', 10, 'srch-top-schools');
initPagination('tbl-top-comms', 'pg-top-comms', 10, 'srch-top-comms');
</script>
</body></html>