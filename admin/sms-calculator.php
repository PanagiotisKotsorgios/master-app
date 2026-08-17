<?php

// ── Error Display & Logging ──────────────────────────────────────────────
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);

if (isset($_GET['debug'])) {
    ini_set('display_errors', 1);
} else {
    ini_set('display_errors', 0);

    set_exception_handler(function (\Throwable $e) {
        $file = basename($e->getFile());
        error_log('[' . $file . '] EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        if (!headers_sent()) {
            http_response_code(500);
        }

        echo '<div style="background:#0d1117;color:#e63946;padding:1.5rem 2rem;font-family:monospace;border:1px solid rgba(230,57,70,.3);border-radius:10px;margin:1.5rem;max-width:900px">';
        echo '<strong style="font-size:1.1rem">⚠ Σφάλμα Συστήματος</strong><br><hr style="border-color:rgba(230,57,70,.2);margin:.75rem 0">';
        echo '<span style="color:#f0a500">Τύπος:</span> ' . get_class($e) . '<br>';
        echo '<span style="color:#f0a500">Μήνυμα:</span> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '<br>';
        echo '<span style="color:#f0a500">Αρχείο:</span> ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ' — Γραμμή ' . $e->getLine() . '<br>';
        echo '</div>';
        exit;
    });

    set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
        $log = basename($errfile);

        if ($errno & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            error_log("[{$log}] FATAL ERROR [{$errno}]: {$errstr} on line {$errline}");
        } elseif ($errno & (E_WARNING | E_NOTICE | E_DEPRECATED)) {
            error_log("[{$log}] WARNING [{$errno}]: {$errstr} on line {$errline}");
        }

        return false;
    });
}

@mkdir(__DIR__ . '/../logs', 0750, true);
// ──────────────────────────────────────────────────────────────────────────

/**
 * ============================================================
 * admin/sms-calculator.php — Κοστολογητής SMS (Super Admin)
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

requireSuperAdmin();
$db = getDB();

/**
 * ------------------------------------------------------------------------
 * Local CSRF helpers for THIS file only
 * ------------------------------------------------------------------------
 * We do NOT rely on the project's verifyCsrf(), because the generator/validator
 * naming convention may differ and causes "Security check failed".
 */
function smsCalcCsrfToken(): string
{
    if (empty($_SESSION['sms_calc_csrf'])) {
        $_SESSION['sms_calc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sms_calc_csrf'];
}

function smsCalcVerifyCsrf(): void
{
    $posted = $_POST['_csrf'] ?? '';
    $stored = $_SESSION['sms_calc_csrf'] ?? '';

    if (
        !is_string($posted) ||
        !is_string($stored) ||
        $posted === '' ||
        $stored === '' ||
        !hash_equals($stored, $posted)
    ) {
        throw new RuntimeException('Security check failed. Please go back and try again.');
    }
}

/**
 * Basic JSON validators so bad data does not get saved.
 */
function validateSmsPkgTiersJson(string $json): string
{
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new RuntimeException('Μη έγκυρο JSON για τα πακέτα SMS.');
    }

    $clean = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }

        $qty = (int)($row['qty'] ?? 0);
        $price = (float)($row['price'] ?? 0);
        $label = trim((string)($row['label'] ?? ''));

        if ($qty <= 0) {
            continue;
        }

        $clean[] = [
            'qty' => $qty,
            'price' => max(0, round($price, 2)),
            'label' => $label !== '' ? $label : ('Tier ' . $qty),
        ];
    }

    usort($clean, fn($a, $b) => $a['qty'] <=> $b['qty']);

    if (!$clean) {
        throw new RuntimeException('Πρέπει να υπάρχει τουλάχιστον 1 έγκυρο πακέτο SMS.');
    }

    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function validateSmsPlanCategoriesJson(string $json): string
{
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new RuntimeException('Μη έγκυρο JSON για τις κατηγορίες πλάνων.');
    }

    $clean = [];
    foreach ($data as $i => $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = trim((string)($row['name'] ?? ''));
        $slug = trim((string)($row['slug'] ?? ('cat_' . $i)));
        $schools = max(0, (int)($row['schools'] ?? 0));
        $smsPerSchool = max(0, (int)($row['sms_per_school'] ?? 0));
        $revenuePerSchool = max(0, round((float)($row['revenue_per_school'] ?? 0), 2));
        $color = trim((string)($row['color'] ?? '#94a3b8'));

        if ($name === '') {
            continue;
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#94a3b8';
        }

        $clean[] = [
            'name' => $name,
            'slug' => $slug !== '' ? $slug : ('cat_' . $i),
            'schools' => $schools,
            'sms_per_school' => $smsPerSchool,
            'revenue_per_school' => $revenuePerSchool,
            'color' => $color,
        ];
    }

    if (!$clean) {
        throw new RuntimeException('Πρέπει να υπάρχει τουλάχιστον 1 κατηγορία πλάνου.');
    }

    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

// ── Handle POST: Save SMS pricing settings ───────────────────────────────
$msg = null;
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    smsCalcVerifyCsrf();

    $action = (string)$_POST['_action'];

    if ($action === 'save_sms_pricing') {
        $payload = [];

        if (isset($_POST['sms_cost_per_unit'])) {
            $payload['sms_cost_per_unit'] = (string)max(0, round((float)$_POST['sms_cost_per_unit'], 4));
        }

        if (isset($_POST['sms_margin_pct'])) {
            $payload['sms_margin_pct'] = (string)max(0, min(500, (int)$_POST['sms_margin_pct']));
        }

        if (isset($_POST['sms_pkg_tiers'])) {
            $payload['sms_pkg_tiers'] = validateSmsPkgTiersJson((string)$_POST['sms_pkg_tiers']);
        }

        if (isset($_POST['sms_plan_categories'])) {
            $payload['sms_plan_categories'] = validateSmsPlanCategoriesJson((string)$_POST['sms_plan_categories']);
        }

        if ($payload) {
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");

            foreach ($payload as $key => $val) {
                $stmt->execute([$key, $val]);
            }
        }

        $msg = '✅ Ρυθμίσεις SMS κοστολόγησης αποθηκεύτηκαν.';
    }
}

// ── Load settings ────────────────────────────────────────────────────────
$rows = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'sms_%'")->fetchAll();

$cfg = [];
foreach ($rows as $r) {
    $cfg[$r['setting_key']] = $r['setting_value'];
}

$smsCostPerUnit = (float)($cfg['sms_cost_per_unit'] ?? 0.035);
$smsMarginPct   = (float)($cfg['sms_margin_pct'] ?? 20);

// Default SMS package tiers
$defaultTiers = json_encode([
    ['qty' => 1000,  'price' => 35.00,   'label' => 'Starter'],
    ['qty' => 5000,  'price' => 150.00,  'label' => 'Business'],
    ['qty' => 10000, 'price' => 280.00,  'label' => 'Pro'],
    ['qty' => 25000, 'price' => 625.00,  'label' => 'Enterprise'],
    ['qty' => 50000, 'price' => 1150.00, 'label' => 'Enterprise+'],
], JSON_UNESCAPED_UNICODE);

$pkgTiersJson = $cfg['sms_pkg_tiers'] ?? $defaultTiers;
$pkgTiers = json_decode($pkgTiersJson, true);
if (!is_array($pkgTiers)) {
    $pkgTiersJson = $defaultTiers;
    $pkgTiers = json_decode($defaultTiers, true);
}

// Default plan categories
$defaultCats = json_encode([
    ['name' => 'Basic (Trial)',   'slug' => 'basic_trial', 'schools' => 0, 'sms_per_school' => 15,  'revenue_per_school' => 0,     'color' => '#94a3b8'],
    ['name' => 'Basic',           'slug' => 'basic',       'schools' => 0, 'sms_per_school' => 30,  'revenue_per_school' => 15.00, 'color' => '#3b82f6'],
    ['name' => 'Pro',             'slug' => 'pro',         'schools' => 0, 'sms_per_school' => 80,  'revenue_per_school' => 29.00, 'color' => '#a855f7'],
    ['name' => 'Pro (Ετήσιο)',    'slug' => 'pro_annual',  'schools' => 0, 'sms_per_school' => 80,  'revenue_per_school' => 24.92, 'color' => '#8b5cf6'],
    ['name' => 'Extra (Custom)',  'slug' => 'custom',      'schools' => 0, 'sms_per_school' => 120, 'revenue_per_school' => 50.00, 'color' => '#f0a500'],
], JSON_UNESCAPED_UNICODE);

$planCatsJson = $cfg['sms_plan_categories'] ?? $defaultCats;
$planCats = json_decode($planCatsJson, true);
if (!is_array($planCats)) {
    $planCatsJson = $defaultCats;
    $planCats = json_decode($defaultCats, true);
}

// ── Real Data from DB ────────────────────────────────────────────────────
$smsThisMonth = (int)$db->query("
    SELECT COUNT(*)
    FROM reminder_logs
    WHERE type='sms' AND status='sent'
      AND MONTH(sent_at)=MONTH(CURDATE())
      AND YEAR(sent_at)=YEAR(CURDATE())
")->fetchColumn();

$smsLastMonth = (int)$db->query("
    SELECT COUNT(*)
    FROM reminder_logs
    WHERE type='sms' AND status='sent'
      AND MONTH(sent_at)=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
      AND YEAR(sent_at)=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
")->fetchColumn();

$smsFailed = (int)$db->query("
    SELECT COUNT(*)
    FROM reminder_logs
    WHERE type='sms' AND status='failed'
      AND MONTH(sent_at)=MONTH(CURDATE())
      AND YEAR(sent_at)=YEAR(CURDATE())
")->fetchColumn();

$smsBySchool = $db->query("
    SELECT s.name, s.id, p.name AS plan_name, p.slug AS plan_slug,
           COUNT(rl.id) AS sms_count
    FROM reminder_logs rl
    JOIN schools s ON s.id = rl.school_id
    JOIN plans p ON p.id = s.plan_id
    WHERE rl.type='sms'
      AND rl.status='sent'
      AND MONTH(rl.sent_at)=MONTH(CURDATE())
      AND YEAR(rl.sent_at)=YEAR(CURDATE())
    GROUP BY s.id, s.name, p.name, p.slug
    ORDER BY sms_count DESC
    LIMIT 20
")->fetchAll();

$monthlySmsSent = [];
$monthlyStmt = $db->prepare("
    SELECT COUNT(*)
    FROM reminder_logs
    WHERE type='sms'
      AND status='sent'
      AND sent_at BETWEEN ? AND ?
");

for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $s = "$m-01 00:00:00";
    $e = date('Y-m-t 23:59:59', strtotime("$m-01"));

    $monthlyStmt->execute([$s, $e]);
    $cnt = (int)$monthlyStmt->fetchColumn();

    $monthlySmsSent[] = [
        'month' => date('M Y', strtotime("$m-01")),
        'count' => $cnt,
    ];
}

$revenueThisMonth = (float)$db->query("
    SELECT COALESCE(SUM(amount),0)
    FROM school_plan_payments
    WHERE MONTH(paid_at)=MONTH(CURDATE())
      AND YEAR(paid_at)=YEAR(CURDATE())
")->fetchColumn();

$schoolsByPlan = $db->query("
    SELECT p.id, p.name, p.slug, p.price_monthly, p.sms_enabled,
           COUNT(s.id) AS school_count,
           SUM(CASE WHEN s.plan_status='active' THEN 1 ELSE 0 END) AS active_count,
           SUM(CASE WHEN s.plan_status='trial' THEN 1 ELSE 0 END) AS trial_count
    FROM plans p
    LEFT JOIN schools s ON s.plan_id=p.id
    WHERE p.active=1
    GROUP BY p.id, p.name, p.slug, p.price_monthly, p.sms_enabled
")->fetchAll();

$totalActiveSchools = (int)$db->query("
    SELECT COUNT(*)
    FROM schools
    WHERE plan_status IN ('active','trial')
")->fetchColumn();

$planSmsStmt = $db->prepare("
    SELECT COUNT(*)
    FROM reminder_logs rl
    JOIN schools s ON s.id = rl.school_id
    JOIN plans pl ON pl.id = s.plan_id
    WHERE rl.type='sms'
      AND rl.status='sent'
      AND pl.slug = ?
      AND MONTH(rl.sent_at)=MONTH(CURDATE())
      AND YEAR(rl.sent_at)=YEAR(CURDATE())
");

$planSmsCounts = [];
foreach ($schoolsByPlan as $p) {
    $planSmsStmt->execute([$p['slug']]);
    $planSmsCounts[$p['slug']] = (int)$planSmsStmt->fetchColumn();
}

// CSRF token
$csrf = smsCalcCsrfToken();

renderHead('SMS Κοστολογητής');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { font-size: 15px; }
.page-body { padding: 1.75rem !important; }
.card { border-radius: 14px !important; }
.card-title { font-size: 1rem !important; font-weight: 700 !important; }
.card-header { margin-bottom: 0 !important; }
table { font-size: .9rem !important; }
thead th { font-size: .75rem !important; padding: .7rem 1rem !important; letter-spacing: .07em; }
tbody td { padding: .8rem 1rem !important; font-size: .88rem !important; }
.fw-600 { font-size: .92rem !important; }
.text-xs { font-size: .78rem !important; }
.text-sm { font-size: .85rem !important; }
.stat-card { border-radius: 14px !important; padding: 1.35rem !important; }
.stat-card .stat-val { font-size: 2.1rem !important; font-weight: 800 !important; }
.stat-card .stat-lbl { font-size: .82rem !important; }
.stat-card .stat-icon { width: 46px !important; height: 46px !important; font-size: 1.3rem !important; border-radius: 12px !important; }
.badge { font-size: .72rem !important; padding: .22rem .6rem !important; border-radius: 50px !important; font-weight: 700 !important; }
.btn { font-size: .875rem !important; padding: .5rem 1.05rem !important; border-radius: 9px !important; font-weight: 500 !important; }
.btn-sm { font-size: .8rem !important; padding: .32rem .65rem !important; }
.form-label { font-size: .82rem !important; font-weight: 600 !important; color: var(--muted); }
.form-control { font-size: .88rem !important; padding: .58rem .8rem !important; border-radius: 9px !important; }
.form-hint { font-size: .75rem !important; }
.page-title { font-size: 1.1rem !important; font-weight: 700 !important; }
.text-muted { color: var(--muted) !important; }
.alert { font-size: .9rem !important; padding: .85rem 1.1rem !important; border-radius: 10px !important; }
h2 { font-size: 1.2rem !important; font-weight: 700 !important; }

.calc-panel {
    background: var(--card, #131929);
    border: 1px solid var(--border, #1e2536);
    border-radius: 14px;
    padding: 1.5rem;
    margin-bottom: 1rem;
}
.calc-panel h3 {
    font-size: .95rem;
    font-weight: 700;
    margin: 0 0 1.2rem;
    display: flex;
    align-items: center;
    gap: .6rem;
    color: var(--text, #e2e8f0);
}
.calc-panel h3 i { opacity: .8; }

.result-box {
    background: rgba(59,130,246,.08);
    border: 1px solid rgba(59,130,246,.2);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-top: 1rem;
}
.result-box.green { background: rgba(16,185,129,.08); border-color: rgba(16,185,129,.25); }
.result-box.red   { background: rgba(230,57,70,.08);  border-color: rgba(230,57,70,.25); }
.result-box.orange{ background: rgba(240,165,0,.08);  border-color: rgba(240,165,0,.25); }

.result-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .4rem 0;
    font-size: .9rem;
    border-bottom: 1px solid rgba(255,255,255,.05);
}
.result-row:last-child { border-bottom: none; }
.result-row .lbl { color: var(--muted, #94a3b8); }
.result-row .val { font-weight: 700; color: var(--text, #e2e8f0); }
.result-row.total { margin-top: .5rem; padding-top: .75rem; border-top: 2px solid rgba(255,255,255,.1); border-bottom: none; font-size: 1.05rem; }
.result-row.total .val { font-size: 1.3rem; }

.tier-card {
    background: var(--surface, #0d1117);
    border: 2px solid var(--border, #1e2536);
    border-radius: 12px;
    padding: 1rem 1.25rem;
    cursor: pointer;
    transition: border-color .15s, transform .1s;
    text-align: center;
}
.tier-card:hover { border-color: #3b82f6; transform: translateY(-2px); }
.tier-card.selected { border-color: #3b82f6; background: rgba(59,130,246,.08); }
.tier-card .t-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: .3rem; }
.tier-card .t-qty { font-size: 1.4rem; font-weight: 800; color: var(--text, #e2e8f0); }
.tier-card .t-price { font-size: .95rem; font-weight: 600; color: #3b82f6; margin: .2rem 0; }
.tier-card .t-unit { font-size: .72rem; color: var(--muted); }

.cat-row {
    display: grid;
    grid-template-columns: 1.5fr 80px 80px 90px 90px 80px;
    gap: .5rem;
    align-items: center;
    padding: .65rem .75rem;
    border-radius: 9px;
    margin-bottom: .4rem;
    background: var(--surface, #0d1117);
    border: 1px solid var(--border, #1e2536);
}
.cat-row input { width: 100%; background: transparent; border: 1px solid var(--border, #1e2536); border-radius: 7px; padding: .35rem .5rem; color: var(--text, #e2e8f0); font-size: .85rem; text-align: center; }
.cat-row input:focus { outline: none; border-color: #3b82f6; }
.cat-name { font-size: .88rem; font-weight: 600; display: flex; align-items: center; gap: .5rem; }
.cat-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.cat-header {
    display: grid;
    grid-template-columns: 1.5fr 80px 80px 90px 90px 80px;
    gap: .5rem;
    padding: .4rem .75rem;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted, #94a3b8);
    margin-bottom: .3rem;
}

.gauge-bar {
    height: 8px;
    background: var(--border, #1e2536);
    border-radius: 99px;
    overflow: hidden;
    margin-top: .4rem;
}
.gauge-fill { height: 100%; border-radius: 99px; transition: width .4s; }

.pkg-recommend {
    background: linear-gradient(135deg, rgba(59,130,246,.15), rgba(168,85,247,.1));
    border: 1px solid rgba(59,130,246,.3);
    border-radius: 12px;
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-top: 1rem;
}
.pkg-icon { font-size: 2.2rem; }
.pkg-info h4 { margin: 0 0 .3rem; font-size: 1.05rem; font-weight: 700; }
.pkg-info p  { margin: 0; font-size: .85rem; color: var(--muted); }

.tabs-sms { display: flex; gap: .5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border, #1e2536); padding-bottom: .75rem; flex-wrap: wrap; }
.tab-sms { background: transparent; border: 1px solid var(--border, #1e2536); border-radius: 8px; padding: .42rem 1rem; font-size: .85rem; cursor: pointer; color: var(--muted); transition: all .15s; }
.tab-sms.active { background: #3b82f6; border-color: #3b82f6; color: #fff; font-weight: 600; }

.section-title {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--muted, #94a3b8);
    margin: 1.5rem 0 .75rem;
    padding-bottom: .5rem;
    border-bottom: 1px solid var(--border, #1e2536);
}

@media(max-width:768px){
    .page-body { padding: 1rem !important; }
    .cat-row, .cat-header { grid-template-columns: 1fr 60px 60px; }
    .cat-row .hide-mobile, .cat-header .hide-mobile { display: none; }
}
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_sms_calc'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-mobile-screen-button"></i> SMS Κοστολόγηση & Υπολογιστής'); ?>
<div class="page-body">

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'success' ? 'success' : 'danger' ?> anim-1 mb-3"><?= h($msg) ?></div>
<?php endif; ?>

<div class="grid grid-4 mb-3">
  <div class="stat-card">
    <div class="stat-icon icon-blue"><i class="fa-solid fa-mobile-screen-button"></i></div>
    <div class="stat-val"><?= number_format($smsThisMonth) ?></div>
    <div class="stat-lbl">SMS Τρέχοντος Μήνα</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-orange"><i class="fa-solid fa-euro-sign"></i></div>
    <div class="stat-val"><?= number_format($smsThisMonth * $smsCostPerUnit, 2, ',', '.') ?>€</div>
    <div class="stat-lbl">Εκτιμώμενο Κόστος SMS</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-green"><i class="fa-solid fa-building-columns"></i></div>
    <div class="stat-val"><?= number_format($revenueThisMonth, 0, ',', '.') ?>€</div>
    <div class="stat-lbl">Έσοδα Τρέχοντος Μήνα</div>
  </div>
  <div class="stat-card">
    <?php
      $smsPct = $revenueThisMonth > 0 ? ($smsThisMonth * $smsCostPerUnit / $revenueThisMonth * 100) : 0;
      $iconColor = $smsPct < 5 ? 'icon-green' : ($smsPct < 15 ? 'icon-orange' : 'icon-red');
    ?>
    <div class="stat-icon <?= $iconColor ?>"><i class="fa-solid fa-percent"></i></div>
    <div class="stat-val"><?= number_format($smsPct, 1, ',', '.') ?>%</div>
    <div class="stat-lbl">SMS ως % Εσόδων</div>
  </div>
</div>

<div class="tabs-sms">
  <button class="tab-sms active" onclick="switchTab('calculator',this)"><i class="fa-solid fa-calculator"></i> Υπολογιστής</button>
  <button class="tab-sms" onclick="switchTab('history',this)"><i class="fa-solid fa-chart-bar"></i> Ιστορικό & Ανάλυση</button>
  <button class="tab-sms" onclick="switchTab('settings',this)"><i class="fa-solid fa-gear"></i> Ρυθμίσεις Κοστολόγησης</button>
</div>

<div id="tab-calculator">

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">

<div>
  <div class="calc-panel">
    <h3><i class="fa-solid fa-school" style="color:#3b82f6"></i> Κατηγορίες Σχολών & Εκτίμηση SMS</h3>

    <div class="section-title">Συμπλήρωσε αριθμούς σχολών ανά κατηγορία</div>

    <div class="cat-header">
      <span>Κατηγορία</span>
      <span>Σχολές</span>
      <span>SMS/σχολή</span>
      <span class="hide-mobile">Έσοδο/σχ.</span>
      <span class="hide-mobile">SMS σύνολο</span>
      <span>Κόστος</span>
    </div>

    <div id="cat-rows">
      <?php foreach ($planCats as $idx => $cat): ?>
      <div class="cat-row" id="cat-row-<?= $idx ?>">
        <div class="cat-name">
          <span class="cat-dot" style="background:<?= h($cat['color']) ?>"></span>
          <?= h($cat['name']) ?>
        </div>
        <input type="number" id="cat-schools-<?= $idx ?>" value="<?= (int)$cat['schools'] ?>"
               min="0" max="9999" onchange="recalc()" oninput="recalc()">
        <input type="number" id="cat-sms-<?= $idx ?>" value="<?= (int)$cat['sms_per_school'] ?>"
               min="0" max="9999" onchange="recalc()" oninput="recalc()">
        <input type="number" id="cat-rev-<?= $idx ?>" value="<?= number_format((float)$cat['revenue_per_school'], 2, '.', '') ?>"
               min="0" step="0.01" onchange="recalc()" oninput="recalc()" class="hide-mobile">
        <div id="cat-total-sms-<?= $idx ?>" class="hide-mobile" style="text-align:center;font-size:.82rem;color:var(--muted)">—</div>
        <div id="cat-cost-<?= $idx ?>" style="text-align:center;font-size:.82rem;font-weight:600;color:#f0a500">—</div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:1rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
      <label class="form-label" style="margin:0;white-space:nowrap">
        <i class="fa-solid fa-shield-halved" style="color:#a855f7"></i> Margin Buffer:
      </label>
      <input type="range" id="margin-slider" min="0" max="100" step="5" value="<?= (int)$smsMarginPct ?>"
             style="flex:1;min-width:120px" oninput="document.getElementById('margin-val').textContent=this.value+'%';recalc()">
      <span id="margin-val" style="font-weight:700;color:#a855f7;min-width:40px"><?= (int)$smsMarginPct ?>%</span>
    </div>
    <div class="form-hint" style="margin-top:.3rem">Επιπλέον buffer για έκτακτες αποστολές, νέες σχολές, δοκιμές κ.λπ.</div>
  </div>

  <div class="calc-panel">
    <h3><i class="fa-solid fa-scale-balanced" style="color:#10b981"></i> Ανάλυση P&L (SMS vs Έσοδα)</h3>
    <div id="pl-summary">
      <div class="result-box">
        <div class="result-row"><span class="lbl">Σύνολο SMS (base)</span><span class="val" id="pl-sms-base">—</span></div>
        <div class="result-row"><span class="lbl">+ Margin buffer</span><span class="val" id="pl-sms-margin">—</span></div>
        <div class="result-row total"><span class="lbl">SMS να αγοράσω</span><span class="val" id="pl-sms-total">—</span></div>
      </div>
      <div class="result-box" style="margin-top:.75rem">
        <div class="result-row"><span class="lbl">Εκτιμώμενα Έσοδα</span><span class="val" id="pl-revenue">—</span></div>
        <div class="result-row"><span class="lbl">Κόστος SMS (with margin)</span><span class="val" id="pl-sms-cost">—</span></div>
        <div class="result-row"><span class="lbl">SMS ως % εσόδων</span><span class="val" id="pl-pct">—</span></div>
        <div class="result-row total" id="pl-final-row">
          <span class="lbl">Αποτέλεσμα (μετά SMS)</span>
          <span class="val" id="pl-result">—</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div>
  <div class="calc-panel">
    <h3><i class="fa-solid fa-box-open" style="color:#a855f7"></i> Πακέτα SMS — Επέλεξε ή Υπολόγισε</h3>

    <div class="section-title">Διαθέσιμα πακέτα SMS</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.6rem;margin-bottom:1rem" id="tier-cards">
      <?php foreach ($pkgTiers as $ti => $tier): ?>
      <div class="tier-card" id="tier-<?= $ti ?>" onclick="selectTier(<?= $ti ?>)"
           data-qty="<?= (int)$tier['qty'] ?>" data-price="<?= (float)$tier['price'] ?>">
        <div class="t-label"><?= h($tier['label']) ?></div>
        <div class="t-qty"><?= number_format($tier['qty']) ?></div>
        <div class="t-price"><?= number_format($tier['price'], 2, ',', '.') ?>€</div>
        <div class="t-unit"><?= number_format(($tier['price'] / max(1, $tier['qty'])) * 100, 3, ',', '.') ?>¢/SMS</div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="pkg-recommend" id="pkg-recommend" style="display:none">
      <div class="pkg-icon">📦</div>
      <div class="pkg-info">
        <h4 id="rec-title">—</h4>
        <p id="rec-desc">—</p>
      </div>
      <div style="margin-left:auto;text-align:right">
        <div style="font-size:1.5rem;font-weight:800;color:#3b82f6" id="rec-price">—€</div>
        <div style="font-size:.72rem;color:var(--muted)">συνολικό κόστος</div>
      </div>
    </div>

    <div class="section-title" style="margin-top:1.5rem">Χειροκίνητος υπολογισμός</div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
      <div class="form-group">
        <label class="form-label">SMS που χρειάζομαι</label>
        <input type="number" id="manual-sms" class="form-control" placeholder="π.χ. 3500"
               min="0" oninput="manualCalc()">
      </div>
      <div class="form-group">
        <label class="form-label">Τιμή ανά SMS (€)</label>
        <input type="number" id="manual-cost" class="form-control" step="0.001"
               value="<?= number_format($smsCostPerUnit, 3, '.', '') ?>" oninput="manualCalc()">
      </div>
    </div>

    <div id="manual-result" class="result-box" style="margin-top:1rem;display:none">
      <div class="result-row"><span class="lbl">SMS που χρειάζεσαι</span><span class="val" id="mr-sms">—</span></div>
      <div class="result-row"><span class="lbl">Κόστος χωρίς πακέτο</span><span class="val" id="mr-open">—</span></div>
      <div class="result-row"><span class="lbl">Καλύτερο πακέτο</span><span class="val" id="mr-pkg">—</span></div>
      <div class="result-row total"><span class="lbl">Εξοικονόμηση πακέτου</span><span class="val" id="mr-save" style="color:#10b981">—</span></div>
    </div>

    <div class="section-title" style="margin-top:1.5rem">Κατανομή SMS ανά κατηγορία</div>
    <div id="cat-chart" style="margin-top:.5rem"></div>
  </div>
</div>

</div>
</div>

<div id="tab-history" style="display:none">

<div class="card mb-3">
  <div class="card-header" style="padding:1.25rem 1.5rem">
    <div class="card-title"><i class="fa-solid fa-chart-bar" style="color:#3b82f6"></i> SMS Αποστολές — Τελευταίοι 6 Μήνες</div>
  </div>
  <div class="card-body"><canvas id="smsHistoryChart" height="80"></canvas></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
  <div class="card">
    <div class="card-header" style="padding:1.25rem 1.5rem">
      <div class="card-title"><i class="fa-solid fa-mobile-screen-button" style="color:#a855f7"></i> Τρέχων Μήνας</div>
    </div>
    <div class="card-body" style="padding:1.25rem 1.5rem">
      <div class="result-row"><span class="lbl">SMS απεστάλησαν</span><span class="val"><?= number_format($smsThisMonth) ?></span></div>
      <div class="result-row"><span class="lbl">SMS απέτυχαν</span><span class="val" style="color:#e63946"><?= number_format($smsFailed) ?></span></div>
      <div class="result-row"><span class="lbl">Ποσοστό επιτυχίας</span><span class="val"><?= $smsThisMonth + $smsFailed > 0 ? number_format($smsThisMonth / ($smsThisMonth + $smsFailed) * 100, 1) . '%' : '—' ?></span></div>
      <div class="result-row"><span class="lbl">Εκτιμώμενο κόστος</span><span class="val" style="color:#f0a500"><?= number_format($smsThisMonth * $smsCostPerUnit, 2, ',', '.') ?>€</span></div>
      <div class="result-row"><span class="lbl">Ενεργές σχολές</span><span class="val"><?= $totalActiveSchools ?></span></div>
      <div class="result-row"><span class="lbl">SMS ανά σχολή avg</span><span class="val"><?= $totalActiveSchools > 0 ? number_format($smsThisMonth / $totalActiveSchools, 1) : '0' ?></span></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header" style="padding:1.25rem 1.5rem">
      <div class="card-title"><i class="fa-solid fa-calendar-check" style="color:#10b981"></i> Προηγούμενος Μήνας</div>
    </div>
    <div class="card-body" style="padding:1.25rem 1.5rem">
      <div class="result-row"><span class="lbl">SMS απεστάλησαν</span><span class="val"><?= number_format($smsLastMonth) ?></span></div>
      <div class="result-row"><span class="lbl">Εκτιμώμενο κόστος</span><span class="val" style="color:#f0a500"><?= number_format($smsLastMonth * $smsCostPerUnit, 2, ',', '.') ?>€</span></div>
      <div class="result-row">
        <?php $diff = $smsThisMonth - $smsLastMonth; $pct = $smsLastMonth > 0 ? ($diff / $smsLastMonth * 100) : 0; ?>
        <span class="lbl">Μεταβολή vs τρέχων</span>
        <span class="val" style="color:<?= $diff >= 0 ? '#10b981' : '#e63946' ?>"><?= ($diff >= 0 ? '+' : '') . number_format($diff) ?> (<?= number_format($pct, 1) ?>%)</span>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header" style="padding:1.25rem 1.5rem">
    <div class="card-title"><i class="fa-solid fa-boxes-stacked" style="color:#f0a500"></i> SMS ανά Πλάνο (τρέχων μήνας)</div>
  </div>
  <div class="card-body p-0">
    <table>
      <thead><tr><th>Πλάνο</th><th>Σχολές</th><th>SMS Ενεργές</th><th>Avg SMS/σχολή</th><th>Εκτ. Κόστος</th><th>Έσοδο Πλάνου</th></tr></thead>
      <tbody>
      <?php foreach ($schoolsByPlan as $p):
        $activePlanSms = $planSmsCounts[$p['slug']] ?? 0;
        $planCost = $activePlanSms * $smsCostPerUnit;
        $planRevEst = ((int)$p['active_count']) * (float)$p['price_monthly'];
      ?>
      <tr>
        <td><span class="badge <?= $p['slug'] === 'pro' ? 'badge-paid' : 'badge-pending' ?>"><?= h($p['name']) ?></span></td>
        <td><?= (int)$p['active_count'] ?> <span class="text-xs text-muted">(+<?= (int)$p['trial_count'] ?> trial)</span></td>
        <td><?= number_format($activePlanSms) ?></td>
        <td><?= (int)$p['active_count'] > 0 ? number_format($activePlanSms / (int)$p['active_count'], 1) : '—' ?></td>
        <td style="color:#f0a500"><?= number_format($planCost, 2, ',', '.') ?>€</td>
        <td style="color:#10b981"><?= number_format($planRevEst, 2, ',', '.') ?>€</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header" style="padding:1.25rem 1.5rem">
    <div class="card-title"><i class="fa-solid fa-ranking-star" style="color:#e63946"></i> Top Σχολές σε SMS (τρέχων μήνας)</div>
  </div>
  <div class="card-body p-0">
    <?php if (empty($smsBySchool)): ?>
    <div class="text-center text-muted" style="padding:2rem">Δεν υπάρχουν SMS αποστολές αυτόν τον μήνα.</div>
    <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Σχολή</th><th>Πλάνο</th><th>SMS</th><th>Κόστος</th><th>% Συνόλου</th></tr></thead>
      <tbody>
      <?php foreach ($smsBySchool as $i => $row): ?>
      <tr>
        <td class="text-muted"><?= $i + 1 ?></td>
        <td><div class="fw-600"><?= h($row['name']) ?></div></td>
        <td><span class="badge <?= $row['plan_slug'] === 'pro' ? 'badge-paid' : 'badge-pending' ?>"><?= h($row['plan_name']) ?></span></td>
        <td><?= number_format($row['sms_count']) ?></td>
        <td style="color:#f0a500"><?= number_format($row['sms_count'] * $smsCostPerUnit, 2, ',', '.') ?>€</td>
        <td>
          <?php $pctSchool = $smsThisMonth > 0 ? ($row['sms_count'] / $smsThisMonth * 100) : 0; ?>
          <div style="display:flex;align-items:center;gap:.5rem">
            <span><?= number_format($pctSchool, 1) ?>%</span>
            <div class="gauge-bar" style="flex:1;min-width:60px"><div class="gauge-fill" style="width:<?= min(100, $pctSchool * 3) ?>%;background:#3b82f6"></div></div>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

</div>

<div id="tab-settings" style="display:none">
<form method="POST" autocomplete="off">
  <input type="hidden" name="_action" value="save_sms_pricing">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

  <div class="card mb-3">
    <div class="card-header" style="padding:1.25rem 1.5rem">
      <div class="card-title"><i class="fa-solid fa-sliders" style="color:#3b82f6"></i> Βασικές Ρυθμίσεις SMS (bulker.gr)</div>
    </div>
    <div class="card-body" style="padding:1.5rem">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
        <div class="form-group">
          <label class="form-label"><i class="fa-solid fa-euro-sign"></i> Τιμή ανά SMS (€)</label>
          <input type="number" name="sms_cost_per_unit" class="form-control" step="0.001" min="0"
                 value="<?= number_format($smsCostPerUnit, 3, '.', '') ?>">
          <div class="form-hint">Τρέχουσα τιμή bulker.gr για Ελλάδα. Έλεγξε στο bulker.gr portal.</div>
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fa-solid fa-shield-halved"></i> Default Margin Buffer (%)</label>
          <input type="number" name="sms_margin_pct" class="form-control" step="1" min="0" max="200"
                 value="<?= (int)$smsMarginPct ?>">
          <div class="form-hint">% buffer για τον υπολογιστή αγοράς. 20% = αγόρασε 20% παραπάνω.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header" style="padding:1.25rem 1.5rem">
      <div class="card-title"><i class="fa-solid fa-boxes-stacked" style="color:#a855f7"></i> Πακέτα SMS (Tiers)</div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addTier()"><i class="fa-solid fa-plus"></i> Προσθήκη</button>
    </div>
    <div class="card-body" style="padding:1.5rem">
      <div style="display:grid;grid-template-columns:40px 80px 1fr 100px 100px 40px;gap:.5rem;align-items:center;padding:.3rem .5rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.3rem">
        <span>#</span><span>Label</span><span>SMS</span><span>Τιμή (€)</span><span>€/SMS</span><span></span>
      </div>
      <div id="tier-list">
        <?php foreach ($pkgTiers as $ti => $tier): ?>
        <div class="cat-row" id="trow-<?= $ti ?>" style="grid-template-columns:40px 80px 1fr 100px 100px 40px">
          <span class="text-muted text-sm"><?= $ti + 1 ?></span>
          <input type="text" id="tl-<?= $ti ?>" value="<?= h($tier['label']) ?>" placeholder="Label" oninput="syncTiers()">
          <input type="number" id="tq-<?= $ti ?>" value="<?= (int)$tier['qty'] ?>" min="1" oninput="syncTiers()">
          <input type="number" id="tp-<?= $ti ?>" value="<?= number_format((float)$tier['price'], 2, '.', '') ?>" min="0" step="0.01" oninput="syncTiers()">
          <span id="tu-<?= $ti ?>" style="text-align:center;font-size:.8rem;color:var(--muted)"><?= (int)$tier['qty'] > 0 ? number_format($tier['price'] / $tier['qty'] * 100, 3, ',', '.') . '¢' : '—' ?></span>
          <button type="button" class="btn btn-ghost btn-sm btn-icon" onclick="removeTier(<?= $ti ?>)" title="Διαγραφή"><i class="fa-solid fa-trash" style="color:#e63946"></i></button>
        </div>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="sms_pkg_tiers" id="sms_pkg_tiers" value="<?= h($pkgTiersJson) ?>">
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header" style="padding:1.25rem 1.5rem">
      <div class="card-title"><i class="fa-solid fa-layer-group" style="color:#10b981"></i> Κατηγορίες Πλάνων — Εκτίμηση SMS & Έσοδο</div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addCat()"><i class="fa-solid fa-plus"></i> Προσθήκη</button>
    </div>
    <div class="card-body" style="padding:1.5rem">
      <div style="display:grid;grid-template-columns:40px 1fr 100px 90px 100px 60px 40px;gap:.5rem;align-items:center;padding:.3rem .5rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.3rem">
        <span>#</span><span>Όνομα</span><span>SMS/σχολή</span><span>Έσοδο/σχ.</span><span>Χρώμα</span><span>Default</span><span></span>
      </div>
      <div id="cat-settings-list">
        <?php foreach ($planCats as $ci => $cat): ?>
        <div class="cat-row" id="crow-<?= $ci ?>" style="grid-template-columns:40px 1fr 100px 90px 100px 60px 40px">
          <span class="text-muted text-sm"><?= $ci + 1 ?></span>
          <input type="text" id="cn-<?= $ci ?>" value="<?= h($cat['name']) ?>" placeholder="Κατηγορία" oninput="syncCats()">
          <input type="number" id="cs-<?= $ci ?>" value="<?= (int)$cat['sms_per_school'] ?>" min="0" oninput="syncCats()">
          <input type="number" id="cr-<?= $ci ?>" value="<?= number_format((float)$cat['revenue_per_school'], 2, '.', '') ?>" min="0" step="0.01" oninput="syncCats()">
          <input type="color" id="cc-<?= $ci ?>" value="<?= h($cat['color']) ?>" oninput="syncCats()" style="height:38px;border-radius:7px;cursor:pointer">
          <input type="number" id="cd-<?= $ci ?>" value="<?= (int)$cat['schools'] ?>" min="0" oninput="syncCats()">
          <button type="button" class="btn btn-ghost btn-sm btn-icon" onclick="removeCat(<?= $ci ?>)" title="Διαγραφή"><i class="fa-solid fa-trash" style="color:#e63946"></i></button>
        </div>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="sms_plan_categories" id="sms_plan_categories" value="<?= h($planCatsJson) ?>">
    </div>
  </div>

  <button type="submit" class="btn btn-primary">
    <i class="fa-solid fa-floppy-disk"></i> Αποθήκευση Ρυθμίσεων
  </button>
</form>
</div>

</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const SMS_COST = <?= json_encode($smsCostPerUnit) ?>;
const NUM_CATS = <?= count($planCats) ?>;
let pkgTiers   = <?= $pkgTiersJson ?>;
let planCats   = <?= $planCatsJson ?>;

const monthlyData = <?= json_encode(array_map(fn($m) => $m['count'], $monthlySmsSent)) ?>;
const monthlyLabels = <?= json_encode(array_map(fn($m) => $m['month'], $monthlySmsSent)) ?>;

function switchTab(name, btn) {
    document.querySelectorAll('[id^="tab-"]').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-sms').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).style.display = '';
    if (btn) btn.classList.add('active');
    if (name === 'history') initHistoryChart();
}

function recalc() {
    const margin = parseInt(document.getElementById('margin-slider').value) || 0;
    let totalSmsBase = 0, totalRevenue = 0;
    const catData = [];

    for (let i = 0; i < NUM_CATS; i++) {
        const schools = parseInt(document.getElementById('cat-schools-' + i)?.value) || 0;
        const smsPerSchool = parseInt(document.getElementById('cat-sms-' + i)?.value) || 0;
        const rev = parseFloat(document.getElementById('cat-rev-' + i)?.value) || 0;
        const totalSms = schools * smsPerSchool;
        const totalRev = schools * rev;
        const cost = totalSms * SMS_COST;

        totalSmsBase += totalSms;
        totalRevenue += totalRev;

        const elSms  = document.getElementById('cat-total-sms-' + i);
        const elCost = document.getElementById('cat-cost-' + i);
        if (elSms)  elSms.textContent  = totalSms > 0 ? totalSms.toLocaleString('el') : '—';
        if (elCost) elCost.textContent = cost > 0 ? cost.toFixed(2).replace('.', ',') + '€' : '—';

        catData.push({
            name: planCats[i]?.name || ('Κατ. ' + (i + 1)),
            color: planCats[i]?.color || '#94a3b8',
            sms: totalSms
        });
    }

    const smsMargin = Math.ceil(totalSmsBase * margin / 100);
    const smsTotal = totalSmsBase + smsMargin;
    const smsCostTotal = smsTotal * SMS_COST;
    const netResult = totalRevenue - smsCostTotal;
    const smsPct = totalRevenue > 0 ? (smsCostTotal / totalRevenue * 100) : 0;

    setText('pl-sms-base', totalSmsBase.toLocaleString('el') + ' SMS');
    setText('pl-sms-margin', '+' + smsMargin.toLocaleString('el') + ' SMS (' + margin + '%)');
    setText('pl-sms-total', smsTotal.toLocaleString('el') + ' SMS');
    setText('pl-revenue', '€' + fmt(totalRevenue));
    setText('pl-sms-cost', '€' + fmt(smsCostTotal));
    setText('pl-pct', smsPct.toFixed(1).replace('.', ',') + '%');

    const plResult = document.getElementById('pl-result');
    if (plResult) {
        const sign = netResult >= 0 ? '+' : '-';
        plResult.textContent = sign + '€' + fmt(Math.abs(netResult));
        plResult.style.color = netResult >= 0 ? '#10b981' : '#e63946';
    }

    recommendPkg(smsTotal);
    drawCatChart(catData);
}

function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

function fmt(n) {
    return Number(n).toLocaleString('el-GR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function recommendPkg(needed) {
    if (needed <= 0 || !pkgTiers.length) {
        document.getElementById('pkg-recommend').style.display = 'none';
        document.querySelectorAll('.tier-card').forEach(c => c.classList.remove('selected'));
        return;
    }

    const sorted = [...pkgTiers].filter(t => Number(t.qty) > 0).sort((a, b) => Number(a.qty) - Number(b.qty));
    if (!sorted.length) return;

    let best = null;
    for (const t of sorted) {
        if (Number(t.qty) >= needed) { best = t; break; }
    }
    if (!best) best = sorted[sorted.length - 1];

    document.getElementById('pkg-recommend').style.display = 'flex';
    document.getElementById('rec-title').textContent = '📦 Προτεινόμενο: ' + (best.label || '') + ' — ' + Number(best.qty).toLocaleString('el') + ' SMS';
    document.getElementById('rec-desc').textContent = 'Καλύπτει ' + needed.toLocaleString('el') + ' SMS που χρειάζεσαι' + (Number(best.qty) > needed ? ' (περίσσεια: ' + (Number(best.qty) - needed).toLocaleString('el') + ')' : '');
    document.getElementById('rec-price').textContent = '€' + fmt(best.price);

    document.querySelectorAll('.tier-card').forEach((c, i) => {
        c.classList.toggle('selected', pkgTiers[i] === best);
    });
}

function selectTier(idx) {
    const t = pkgTiers[idx];
    if (!t) return;
    document.getElementById('manual-sms').value = t.qty;
    document.getElementById('manual-cost').value = (Number(t.price) / Math.max(1, Number(t.qty))).toFixed(4);
    manualCalc();
    document.querySelectorAll('.tier-card').forEach((c, i) => c.classList.toggle('selected', i === idx));
}

function manualCalc() {
    const needed = parseInt(document.getElementById('manual-sms').value) || 0;
    const cost = parseFloat(document.getElementById('manual-cost').value) || SMS_COST;
    if (needed <= 0) {
        document.getElementById('manual-result').style.display = 'none';
        return;
    }

    const openCost = needed * cost;
    const sorted = [...pkgTiers].filter(t => Number(t.qty) > 0).sort((a, b) => Number(a.qty) - Number(b.qty));
    let best = null;
    for (const t of sorted) {
        if (Number(t.qty) >= needed) { best = t; break; }
    }
    if (!best && sorted.length) best = sorted[sorted.length - 1];

    const saving = best ? (openCost - Number(best.price)) : 0;

    document.getElementById('manual-result').style.display = '';
    document.getElementById('mr-sms').textContent = needed.toLocaleString('el') + ' SMS';
    document.getElementById('mr-open').textContent = '€' + fmt(openCost);
    document.getElementById('mr-pkg').textContent = best ? best.label + ' (' + Number(best.qty).toLocaleString('el') + ' SMS) → €' + fmt(best.price) : '—';
    document.getElementById('mr-save').textContent =
        saving > 0
            ? '€' + fmt(saving) + ' εξοικονόμηση 🎉'
            : (saving < 0 ? 'Το open market είναι φθηνότερο κατά €' + fmt(Math.abs(saving)) : '—');
}

function drawCatChart(catData) {
    const container = document.getElementById('cat-chart');
    if (!container) return;
    const total = catData.reduce((s, c) => s + c.sms, 0);
    if (total === 0) {
        container.innerHTML = '<div style="color:var(--muted);font-size:.85rem;text-align:center;padding:.5rem">Συμπλήρωσε αριθμούς σχολών ↑</div>';
        return;
    }
    container.innerHTML = catData.filter(c => c.sms > 0).map(c => {
        const pct = Math.max(3, (c.sms / total * 100));
        return `<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem">
            <span style="width:100px;font-size:.78rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(c.name)}</span>
            <div style="flex:1;height:20px;background:var(--border,#1e2536);border-radius:6px;overflow:hidden">
                <div style="height:100%;width:${pct}%;background:${c.color};border-radius:6px;display:flex;align-items:center;padding:0 6px">
                    <span style="font-size:.7rem;color:#fff;font-weight:700;white-space:nowrap">${c.sms.toLocaleString('el')}</span>
                </div>
            </div>
            <span style="font-size:.78rem;color:var(--muted);min-width:40px;text-align:right">${(c.sms / total * 100).toFixed(0)}%</span>
        </div>`;
    }).join('');
}

function escapeHtml(str) {
    return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

let histChart = null;
function initHistoryChart() {
    if (histChart) return;
    const ctx = document.getElementById('smsHistoryChart');
    if (!ctx) return;
    histChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: monthlyLabels,
            datasets: [{
                label: 'SMS απεστάλησαν',
                data: monthlyData,
                backgroundColor: 'rgba(59,130,246,.7)',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: '#94a3b8', font: { size: 11 } }, grid: { color: 'rgba(255,255,255,.04)' } },
                y: { ticks: { color: '#94a3b8', font: { size: 11 } }, grid: { color: 'rgba(255,255,255,.07)' }, beginAtZero: true }
            }
        }
    });
}

function syncTiers() {
    const list = [];
    document.querySelectorAll('[id^="trow-"]').forEach(row => {
        const idx = row.id.split('-')[1];
        const qty = parseInt(document.getElementById('tq-' + idx)?.value) || 0;
        const price = parseFloat(document.getElementById('tp-' + idx)?.value) || 0;
        const label = document.getElementById('tl-' + idx)?.value || '';
        const unit = document.getElementById('tu-' + idx);
        if (unit) unit.textContent = qty > 0 ? (price / qty * 100).toFixed(3).replace('.', ',') + '¢' : '—';
        if (qty > 0) list.push({ qty, price, label });
    });
    list.sort((a, b) => a.qty - b.qty);
    pkgTiers = list;
    document.getElementById('sms_pkg_tiers').value = JSON.stringify(list);
}

function addTier() {
    const idx = document.querySelectorAll('[id^="trow-"]').length;
    const container = document.getElementById('tier-list');
    const div = document.createElement('div');
    div.className = 'cat-row';
    div.id = 'trow-' + idx;
    div.style.gridTemplateColumns = '40px 80px 1fr 100px 100px 40px';
    div.innerHTML = `
        <span class="text-muted text-sm">${idx + 1}</span>
        <input type="text" id="tl-${idx}" value="" placeholder="Label" oninput="syncTiers()">
        <input type="number" id="tq-${idx}" value="0" min="1" oninput="syncTiers()">
        <input type="number" id="tp-${idx}" value="0" min="0" step="0.01" oninput="syncTiers()">
        <span id="tu-${idx}" style="text-align:center;font-size:.8rem;color:var(--muted)">—</span>
        <button type="button" class="btn btn-ghost btn-sm btn-icon" onclick="removeTier(${idx})"><i class="fa-solid fa-trash" style="color:#e63946"></i></button>
    `;
    container.appendChild(div);
}

function removeTier(idx) {
    document.getElementById('trow-' + idx)?.remove();
    syncTiers();
}

function syncCats() {
    const list = [];
    document.querySelectorAll('[id^="crow-"]').forEach(row => {
        const idx = row.id.split('-')[1];
        list.push({
            name: document.getElementById('cn-' + idx)?.value || '',
            slug: 'cat_' + idx,
            sms_per_school: parseInt(document.getElementById('cs-' + idx)?.value) || 0,
            revenue_per_school: parseFloat(document.getElementById('cr-' + idx)?.value) || 0,
            color: document.getElementById('cc-' + idx)?.value || '#94a3b8',
            schools: parseInt(document.getElementById('cd-' + idx)?.value) || 0,
        });
    });
    planCats = list;
    document.getElementById('sms_plan_categories').value = JSON.stringify(list);
}

function addCat() {
    const idx = document.querySelectorAll('[id^="crow-"]').length;
    const container = document.getElementById('cat-settings-list');
    const div = document.createElement('div');
    div.className = 'cat-row';
    div.id = 'crow-' + idx;
    div.style.gridTemplateColumns = '40px 1fr 100px 90px 100px 60px 40px';
    div.innerHTML = `
        <span class="text-muted text-sm">${idx + 1}</span>
        <input type="text" id="cn-${idx}" value="" placeholder="Κατηγορία" oninput="syncCats()">
        <input type="number" id="cs-${idx}" value="0" min="0" oninput="syncCats()">
        <input type="number" id="cr-${idx}" value="0" min="0" step="0.01" oninput="syncCats()">
        <input type="color" id="cc-${idx}" value="#3b82f6" oninput="syncCats()" style="height:38px;border-radius:7px;cursor:pointer">
        <input type="number" id="cd-${idx}" value="0" min="0" oninput="syncCats()">
        <button type="button" class="btn btn-ghost btn-sm btn-icon" onclick="removeCat(${idx})"><i class="fa-solid fa-trash" style="color:#e63946"></i></button>
    `;
    container.appendChild(div);
}

function removeCat(idx) {
    document.getElementById('crow-' + idx)?.remove();
    syncCats();
}

document.addEventListener('DOMContentLoaded', function() {
    recalc();
});
</script>
</body></html>