<?php

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
        error_log('[' . $file . '] EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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

/**
 * ============================================================
 * admin/health.php — System Health Monitor (Super Admin)
 * ============================================================
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── PHP & Server Info ──────────────────────────────────────────────────────
$phpVersion   = PHP_VERSION;
$phpMemLimit  = ini_get('memory_limit');
$phpMaxExec   = ini_get('max_execution_time');
$phpUpload    = ini_get('upload_max_filesize');
$phpPost      = ini_get('post_max_size');
$memUsage     = round(memory_get_usage(true) / 1024 / 1024, 2);
$memPeak      = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
$serverOS     = PHP_OS;
$serverSoft   = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$uptime       = @file_get_contents('/proc/uptime');
$uptimeSecs   = $uptime ? (int)explode(' ', $uptime)[0] : 0;
$uptimeDays   = floor($uptimeSecs / 86400);
$uptimeHours  = floor(($uptimeSecs % 86400) / 3600);

// ── DB Stats ───────────────────────────────────────────────────────────────
$dbVersion    = $db->query("SELECT VERSION()")->fetchColumn();
$dbSize       = $db->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchColumn();
$tableCount   = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchColumn();

// Largest tables
$largeTables  = $db->query("
    SELECT table_name, 
           ROUND((data_length + index_length) / 1024, 1) as size_kb,
           table_rows
    FROM information_schema.TABLES 
    WHERE table_schema = DATABASE()
    ORDER BY (data_length + index_length) DESC
    LIMIT 8
")->fetchAll();

// ── App Health Checks ──────────────────────────────────────────────────────
$checks = [];

// DB connectivity
try {
    $db->query("SELECT 1");
    $checks[] = ['label' => 'Βάση Δεδομένων', 'status' => 'ok', 'detail' => "MySQL $dbVersion · {$dbSize}MB"];
} catch (Exception $e) {
    $checks[] = ['label' => 'Βάση Δεδομένων', 'status' => 'error', 'detail' => $e->getMessage()];
}

// Sessions
$sessPath = session_save_path() ?: sys_get_temp_dir();
$checks[] = ['label' => 'Sessions', 'status' => is_writable($sessPath) ? 'ok' : 'warn', 'detail' => $sessPath];

// SMTP / Brevo key
$brevoKey = getSetting('brevo_api_key', '');
$checks[] = ['label' => 'Brevo API Key', 'status' => !empty($brevoKey) ? 'ok' : 'warn', 'detail' => !empty($brevoKey) ? 'Ρυθμισμένο' : 'Δεν έχει οριστεί'];

// Viva.com
$vivaClientId = getSetting('viva_client_id', '');
$checks[] = ['label' => 'Viva Client ID', 'status' => !empty($vivaClientId) ? 'ok' : 'warn', 'detail' => !empty($vivaClientId) ? 'Ρυθμισμένο' : 'Δεν έχει οριστεί'];

// PHP extensions
$exts = ['pdo', 'pdo_mysql', 'mbstring', 'curl', 'openssl', 'json', 'gd'];
foreach ($exts as $ext) {
    $checks[] = ['label' => "ext/$ext", 'status' => extension_loaded($ext) ? 'ok' : 'error', 'detail' => extension_loaded($ext) ? 'Φορτωμένο' : 'Λείπει!'];
}

// PHP version check
$phpOk = version_compare(PHP_VERSION, '8.0', '>=');
$checks[] = ['label' => 'PHP Version', 'status' => $phpOk ? 'ok' : 'warn', 'detail' => PHP_VERSION . ($phpOk ? '' : ' — Συνιστάται 8.0+')];

// Maintenance mode
$maint = getSetting('maintenance_mode', '0');
$checks[] = ['label' => 'Maintenance Mode', 'status' => $maint === '1' ? 'warn' : 'ok', 'detail' => $maint === '1' ? 'ΕΝΕΡΓΟ — Χρήστες δεν μπορούν να συνδεθούν' : 'Απενεργοποιημένο'];

// ── Error Log — searchable, paginated ─────────────────────────────────────
$logSearch   = trim($_GET['log_q'] ?? '');
$logPage     = max(1, (int)($_GET['log_page'] ?? 1));
$logPerPage  = in_array((int)($_GET['log_per'] ?? 25), [10,25,50,100]) ? (int)($_GET['log_per'] ?? 25) : 25;
$errorLog    = [];
$errorLogAll = [];
$logPaths    = [ini_get('error_log'), __DIR__ . '/../logs/php_errors.log', '/var/log/apache2/error.log', '/var/log/nginx/error.log'];
foreach ($logPaths as $lp) {
    if ($lp && file_exists($lp) && is_readable($lp)) {
        $lines = @file($lp);
        if ($lines) {
            $allLines = array_reverse(array_filter(array_map('trim', $lines)));
            if ($logSearch) {
                $allLines = array_values(array_filter($allLines, fn($l) => stripos($l, $logSearch) !== false));
            }
            $errorLogAll = $allLines;
            break;
        }
    }
}
$logTotal      = count($errorLogAll);
$logTotalPages = max(1, (int)ceil($logTotal / max(1, $logPerPage)));
$logPage       = min($logPage, $logTotalPages);
$errorLog      = array_slice($errorLogAll, ($logPage - 1) * $logPerPage, $logPerPage);

// ── Recent DB Activity (audit_log rows today) ──────────────────────────────
$todayActions = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$weekActions  = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$errorActions = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE '%error%' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

// Hourly activity today
$hourlyData = [];
for ($h = 0; $h < 24; $h++) {
    $cnt = $db->query("SELECT COUNT(*) FROM audit_log WHERE HOUR(created_at)=$h AND DATE(created_at)=CURDATE()")->fetchColumn();
    $hourlyData[] = (int)$cnt;
}

renderHead('System Health');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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

.health-check-row {
    display: flex; align-items: center; gap: 1rem;
    padding: .65rem 0; border-bottom: 1px solid var(--border);
}
.health-check-row:last-child { border-bottom: none; }
.health-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.health-dot.ok   { background: var(--green, #2a9d5c); box-shadow: 0 0 6px rgba(42,157,92,.5); }
.health-dot.warn { background: #f0a500; box-shadow: 0 0 6px rgba(240,165,0,.5); }
.health-dot.error{ background: var(--red, #e63946); box-shadow: 0 0 6px rgba(230,57,70,.5); }
.health-label { font-size: .88rem; font-weight: 600; flex: 1; }
.health-detail { font-size: .8rem; color: var(--muted); }

.info-row { display: flex; justify-content: space-between; align-items: center; padding: .5rem 0; border-bottom: 1px solid var(--border); font-size: .88rem; }
.info-row:last-child { border-bottom: none; }
.info-row .k { color: var(--muted); font-size: .82rem; }
.info-row .v { font-weight: 600; }

.log-line { font-family: monospace; font-size: .75rem; color: #b0bec5; padding: .2rem 0; border-bottom: 1px solid rgba(255,255,255,.04); }
.log-line.err { color: #ef9a9a; }
.log-line:last-child { border-bottom: none; }

.pulse { animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }

@media(max-width:768px){ .page-body { padding: 1rem !important; } }
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_health'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-heart-pulse"></i> System Health Monitor'); ?>
<div class="page-body">

<div class="alert alert-warning mb-3" style="font-size:.88rem">
    <i class="fa-solid fa-circle-info"></i> Real-time πληροφορίες συστήματος. Η σελίδα ανανεώνεται κάθε 60 δευτερόλεπτα.
    <span id="countdown" class="text-muted" style="margin-left:.5rem;font-size:.8rem">(60s)</span>
    <button onclick="location.reload()" class="btn btn-secondary btn-sm" style="margin-left:.5rem"><i class="fa-solid fa-rotate"></i> Refresh</button>
</div>

<!-- ── KPI Cards ──────────────────────────────────────────────────────────── -->
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-val text-green"><?= count(array_filter($checks, fn($c) => $c['status'] === 'ok')) ?></div>
        <div class="stat-lbl">Checks OK</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="stat-val" style="color:#f0a500"><?= count(array_filter($checks, fn($c) => $c['status'] === 'warn')) ?></div>
        <div class="stat-lbl">Προειδοποιήσεις</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-red"><i class="fa-solid fa-circle-xmark"></i></div>
        <div class="stat-val text-red"><?= count(array_filter($checks, fn($c) => $c['status'] === 'error')) ?></div>
        <div class="stat-lbl">Σφάλματα</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-bolt"></i></div>
        <div class="stat-val"><?= $todayActions ?></div>
        <div class="stat-lbl">Ενέργειες Σήμερα</div>
    </div>
</div>

<div class="grid grid-2 mb-3">

    <!-- Health Checks -->
    <div class="card">
        <div class="card-title mb-3">
            <i class="fa-solid fa-stethoscope"></i> Health Checks
            <span class="badge badge-basic" style="margin-left:.5rem"><?= count($checks) ?> checks</span>
        </div>
        <?php foreach ($checks as $c): ?>
        <div class="health-check-row">
            <div class="health-dot <?= $c['status'] ?>"></div>
            <div class="health-label"><?= h($c['label']) ?></div>
            <div class="health-detail"><?= h($c['detail']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- PHP & Server Info -->
    <div class="card">
        <div class="card-title mb-3"><i class="fa-solid fa-server"></i> Server & PHP</div>
        <div class="info-row"><span class="k">PHP Version</span><span class="v"><?= $phpVersion ?></span></div>
        <div class="info-row"><span class="k">Memory Limit</span><span class="v"><?= $phpMemLimit ?></span></div>
        <div class="info-row"><span class="k">Memory Usage</span><span class="v"><?= $memUsage ?> MB (peak: <?= $memPeak ?> MB)</span></div>
        <div class="info-row"><span class="k">Max Execution</span><span class="v"><?= $phpMaxExec ?>s</span></div>
        <div class="info-row"><span class="k">Upload Max</span><span class="v"><?= $phpUpload ?></span></div>
        <div class="info-row"><span class="k">Post Max</span><span class="v"><?= $phpPost ?></span></div>
        <div class="info-row"><span class="k">Server OS</span><span class="v"><?= h($serverOS) ?></span></div>
        <div class="info-row"><span class="k">Web Server</span><span class="v"><?= h($serverSoft) ?></span></div>
        <?php if ($uptimeSecs): ?>
        <div class="info-row"><span class="k">Server Uptime</span><span class="v"><?= $uptimeDays ?>d <?= $uptimeHours ?>h</span></div>
        <?php endif; ?>
        <div class="info-row"><span class="k">Server Time</span><span class="v"><?= date('d/m/Y H:i:s') ?></span></div>
    </div>
</div>

<div class="grid grid-2 mb-3">

    <!-- Database Info -->
    <div class="card">
        <div class="card-title mb-3"><i class="fa-solid fa-database"></i> Βάση Δεδομένων</div>
        <div class="info-row"><span class="k">MySQL Version</span><span class="v"><?= h($dbVersion) ?></span></div>
        <div class="info-row"><span class="k">Συνολικό Μέγεθος</span><span class="v"><?= $dbSize ?> MB</span></div>
        <div class="info-row"><span class="k">Αριθμός Πινάκων</span><span class="v"><?= $tableCount ?></span></div>
        <div class="info-row"><span class="k">Ενέργειες Σήμερα</span><span class="v"><?= $todayActions ?></span></div>
        <div class="info-row"><span class="k">Ενέργειες 7 Ημερών</span><span class="v"><?= $weekActions ?></span></div>
        <div class="info-row"><span class="k">Errors (24h)</span><span class="v <?= $errorActions > 0 ? 'text-red' : 'text-green' ?>"><?= $errorActions ?></span></div>
        <hr class="divider">
        <div class="card-title mb-2" style="font-size:.82rem !important">Μεγαλύτεροι Πίνακες</div>
        <?php foreach ($largeTables as $t): ?>
        <div class="d-flex jc-between text-sm mb-1">
            <span class="text-muted"><?= h($t['table_name']) ?></span>
            <span><?= $t['size_kb'] ?> KB · <?= number_format((int)$t['table_rows']) ?> rows</span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Hourly Activity Chart -->
    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-chart-area"></i> Ενέργειες Σήμερα (Ωριαία)</div>
        <canvas id="hourlyChart" height="180"></canvas>
        <div class="text-xs text-muted mt-2">
            <i class="fa-solid fa-circle-info"></i> Audit log events ανά ώρα για σήμερα
        </div>
    </div>
</div>

<!-- PHP Extensions -->
<div class="card mb-3">
    <div class="card-title mb-3"><i class="fa-brands fa-php"></i> PHP Extensions</div>
    <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
        <?php
        $importantExts = ['pdo','pdo_mysql','mbstring','curl','openssl','json','gd','intl','zip','xml','soap','redis','memcached','opcache','igbinary'];
        foreach ($importantExts as $ext) {
            $loaded = extension_loaded($ext);
            echo '<span class="badge ' . ($loaded ? 'badge-active' : 'badge-overdue') . '">' . ($loaded ? '✓' : '✗') . ' ' . $ext . '</span>';
        }
        ?>
    </div>
</div>

<!-- Error Log -->
<div class="card">
    <div class="d-flex ai-center jc-between mb-2">
        <div class="card-title"><i class="fa-solid fa-terminal"></i> Error Log <?= $logTotal > 0 ? "($logTotal γραμμές)" : "" ?></div>
    </div>
    <!-- Search + per-page -->
    <form method="GET" class="d-flex gap-sm flex-wrap ai-center mb-2">
        <?php foreach(array_filter($_GET, fn($k)=>!in_array($k,['log_q','log_page','log_per']),ARRAY_FILTER_USE_KEY) as $gk=>$gv): ?>
        <input type="hidden" name="<?= h($gk) ?>" value="<?= h($gv) ?>">
        <?php endforeach; ?>
        <div class="search-bar" style="flex:1;min-width:200px">
            <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input name="log_q" value="<?= h($logSearch) ?>" placeholder="Φίλτρο log γραμμών...">
        </div>
        <select name="log_per" class="form-control" style="width:130px" onchange="this.form.submit()">
            <?php foreach([10,25,50,100] as $n): ?>
            <option value="<?= $n ?>"<?= $logPerPage==$n?' selected':'' ?>><?= $n ?> / σελίδα</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i></button>
        <?php if($logSearch): ?><a href="?<?= http_build_query(array_diff_key($_GET,['log_q'=>'','log_page'=>''])) ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
    </form>
    <?php if(!empty($errorLog)): ?>
    <div style="background:var(--bg2);border-radius:10px;padding:1rem;overflow-x:auto;">
        <?php foreach ($errorLog as $line): ?>
        <div class="log-line <?= (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) ? 'err' : (stripos($line, 'warning') !== false ? 'warn-line' : '') ?>">
            <?= $logSearch ? str_ireplace(h($logSearch), '<mark style="background:rgba(230,57,70,.3);color:inherit">'.h($logSearch).'</mark>', h($line)) : h($line) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <!-- Pagination -->
    <?php if($logTotalPages > 1): ?>
    <div class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.4rem 0">
        <?php if($logPage>1):?><a href="?<?= http_build_query(array_merge($_GET,['log_page'=>1])) ?>" class="page-btn"><i class="fa-solid fa-angles-left"></i></a><?php endif;?>
        <?php if($logPage>1):?><a href="?<?= http_build_query(array_merge($_GET,['log_page'=>$logPage-1])) ?>" class="page-btn"><i class="fa-solid fa-chevron-left"></i></a><?php endif;?>
        <?php for($pp=max(1,$logPage-2);$pp<=min($logTotalPages,$logPage+2);$pp++):?>
        <a href="?<?= http_build_query(array_merge($_GET,['log_page'=>$pp])) ?>" class="page-btn <?=$pp==$logPage?'active':''?>"><?=$pp?></a>
        <?php endfor;?>
        <?php if($logPage<$logTotalPages):?><a href="?<?= http_build_query(array_merge($_GET,['log_page'=>$logPage+1])) ?>" class="page-btn"><i class="fa-solid fa-chevron-right"></i></a><?php endif;?>
        <?php if($logPage<$logTotalPages):?><a href="?<?= http_build_query(array_merge($_GET,['log_page'=>$logTotalPages])) ?>" class="page-btn"><i class="fa-solid fa-angles-right"></i></a><?php endif;?>
        <span style="font-size:.8rem;color:var(--muted);margin-left:.4rem"><?=$logTotal?> γραμμές · σελ. <?=$logPage?>/<?=$logTotalPages?></span>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <p class="text-muted text-sm"><?= $logSearch ? 'Δεν βρέθηκαν γραμμές για "'.h($logSearch).'"' : 'Δεν βρέθηκε error log ή δεν είναι προσβάσιμο.' ?></p>
    <?php endif; ?>
</div>

</div></div></div>

<script>
// Hourly chart
new Chart(document.getElementById('hourlyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(range(0, 23)) ?>,
        datasets: [{
            label: 'Ενέργειες',
            data: <?= json_encode($hourlyData) ?>,
            fill: true,
            backgroundColor: 'rgba(230,57,70,.12)',
            borderColor: 'rgba(230,57,70,.8)',
            borderWidth: 2,
            pointRadius: 2,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: { color: '#7a849e', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,.04)' } },
            y: { ticks: { color: '#7a849e', stepSize: 1 }, grid: { color: 'rgba(255,255,255,.04)' } }
        }
    }
});

// Countdown & auto-refresh
let secs = 60;
const el = document.getElementById('countdown');
setInterval(() => {
    secs--;
    el.textContent = `(${secs}s)`;
    if (secs <= 0) location.reload();
}, 1000);
</script>
</body></html>