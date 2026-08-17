<?php
/**
 * ============================================================
 * employee/health.php — System Health Monitor (Read-only)
 * ============================================================
 */

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/privileges.php';
require_once __DIR__ . '/layout.php';

empRequire('health_view');

$db = getDB();

// ── System checks ─────────────────────────────────────────────
$checks = [];

// DB ping
try {
    $db->query("SELECT 1");
    $checks[] = ['label' => 'Database Connection', 'ok' => true, 'detail' => 'PDO OK'];
} catch (\Throwable $e) {
    $checks[] = ['label' => 'Database Connection', 'ok' => false, 'detail' => $e->getMessage()];
}

// PHP version
$phpOk     = version_compare(PHP_VERSION, '8.0', '>=');
$checks[]  = ['label' => 'PHP Version', 'ok' => $phpOk, 'detail' => PHP_VERSION];

// Backup dir writable
$bDir      = __DIR__ . '/../backups/';
$checks[]  = ['label' => 'Backup Dir Writable', 'ok' => is_writable($bDir), 'detail' => $bDir];

// Logs dir writable
$lDir      = __DIR__ . '/../logs/';
$checks[]  = ['label' => 'Logs Dir Writable', 'ok' => is_writable($lDir), 'detail' => $lDir];

// Memory limit
$memLimit  = ini_get('memory_limit');
$checks[]  = ['label' => 'PHP Memory Limit', 'ok' => true, 'detail' => $memLimit];

// Max execution time
$maxExec   = ini_get('max_execution_time');
$checks[]  = ['label' => 'Max Execution Time', 'ok' => true, 'detail' => $maxExec . 's'];

// Session secure
$sessSecure = ini_get('session.cookie_httponly');
$checks[]  = ['label' => 'Session HttpOnly', 'ok' => (bool)$sessSecure, 'detail' => $sessSecure ? 'On' : 'Off'];

// Error log exists
$errLog    = __DIR__ . '/../logs/php_errors.log';
$checks[]  = ['label' => 'Error Log File', 'ok' => file_exists($errLog), 'detail' => file_exists($errLog) ? number_format(filesize($errLog)/1024,1).' KB' : 'Not found'];

// ── DB stats ─────────────────────────────────────────────────
$dbStats = [];
try {
    $tables = $db->query("SHOW TABLE STATUS")->fetchAll();
    foreach ($tables as $t) {
        $dbStats[] = [
            'name'    => $t['Name'],
            'rows'    => $t['Rows'],
            'data_kb' => round(($t['Data_length'] + $t['Index_length']) / 1024, 1),
            'engine'  => $t['Engine'],
        ];
    }
    usort($dbStats, fn($a,$b) => $b['data_kb'] <=> $a['data_kb']);
} catch (\Throwable $e) {}

// ── Recent error log lines ────────────────────────────────────
$recentErrors = [];
if (file_exists($errLog) && filesize($errLog) > 0) {
    $lines = file($errLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recentErrors = array_slice(array_reverse($lines), 0, 20);
}

// ── Backup age check ─────────────────────────────────────────
$backupDir   = __DIR__ . '/../backups/';
$backupFiles = [];
if (is_dir($backupDir)) {
    foreach (glob($backupDir . '*.sql') as $f) {
        $backupFiles[] = ['name' => basename($f), 'mtime' => filemtime($f)];
    }
    usort($backupFiles, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
}
$lastBackup  = $backupFiles[0] ?? null;
$backupAge   = $lastBackup ? (time() - $lastBackup['mtime']) : PHP_INT_MAX;
$backupOk    = $backupAge < 86400 * 2;

$failCount   = count(array_filter($checks, fn($c) => !$c['ok']));
$overallOk   = $failCount === 0 && $backupOk;

renderEmpHead('System Health');
?>
<body>
<?php renderEmpSidebar('health'); ?>
<div class="emp-main">
<?php renderEmpTopbar('System Health'); ?>
<div class="emp-content">

  <div class="section-title">System Health</div>
  <div class="section-sub">Παρακολούθηση κατάστασης συστήματος — Read-only.</div>

  <!-- Overall status -->
  <div class="card" style="border-color:<?= $overallOk ? 'rgba(63,185,80,.3)' : 'rgba(230,57,70,.3)' ?>;background:<?= $overallOk ? 'rgba(63,185,80,.05)' : 'rgba(230,57,70,.05)' ?>">
    <div style="display:flex;align-items:center;gap:1rem">
      <div style="font-size:2.5rem"><?= $overallOk ? '✅' : '⚠️' ?></div>
      <div>
        <div style="font-size:1.15rem;font-weight:800;color:<?= $overallOk ? 'var(--green)' : 'var(--red)' ?>">
          <?= $overallOk ? 'Σύστημα OK' : "Βρέθηκαν $failCount προβλήματα" ?>
        </div>
        <div style="font-size:.88rem;color:var(--muted);margin-top:.2rem">
          Τελευταία ανανέωση: <?= date('d/m/Y H:i:s') ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Checks grid -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">
    <?php foreach ($checks as $c): ?>
    <div class="stat-card" style="border-color:<?= $c['ok'] ? 'var(--border)' : 'rgba(230,57,70,.3)' ?>">
      <div class="stat-label">
        <i class="fa-solid <?= $c['ok'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>"
           style="color:<?= $c['ok'] ? 'var(--green)' : 'var(--red)' ?>"></i>
        <?= h($c['label']) ?>
      </div>
      <div style="font-size:.95rem;font-weight:700;margin-top:.3rem;color:<?= $c['ok'] ? 'var(--text)' : 'var(--red)' ?>">
        <?= h($c['detail']) ?>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Backup freshness -->
    <div class="stat-card" style="border-color:<?= $backupOk ? 'var(--border)' : 'rgba(240,165,0,.35)' ?>">
      <div class="stat-label">
        <i class="fa-solid <?= $backupOk ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"
           style="color:<?= $backupOk ? 'var(--green)' : 'var(--gold)' ?>"></i>
        Backup Freshness
      </div>
      <div style="font-size:.95rem;font-weight:700;margin-top:.3rem;color:<?= $backupOk ? 'var(--text)' : 'var(--gold)' ?>">
        <?php if ($lastBackup): ?>
          <?= round($backupAge/3600) ?> ώρες πριν
        <?php else: ?>
          Κανένα backup!
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">

    <!-- DB Table stats -->
    <div class="card">
      <div class="card-title"><span class="icon"><i class="fa-solid fa-table"></i></span> Πίνακες Βάσης</div>
      <div class="tbl-wrap" style="max-height:320px;overflow-y:auto">
        <table>
          <thead><tr><th>Πίνακας</th><th>Γραμμές</th><th>Μέγεθος</th><th>Engine</th></tr></thead>
          <tbody>
          <?php foreach ($dbStats as $t): ?>
            <tr>
              <td style="font-family:monospace;font-size:.82rem"><?= h($t['name']) ?></td>
              <td style="font-size:.83rem"><?= number_format($t['rows']) ?></td>
              <td style="font-size:.83rem;color:var(--muted)"><?= $t['data_kb'] ?> KB</td>
              <td style="font-size:.78rem;color:var(--muted2)"><?= h($t['engine']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Recent PHP errors -->
    <div class="card">
      <div class="card-title"><span class="icon"><i class="fa-solid fa-bug" style="color:var(--red)"></i></span> Πρόσφατα PHP Errors</div>
      <?php if (empty($recentErrors)): ?>
        <div style="text-align:center;padding:2rem 0;color:var(--green)">
          <i class="fa-solid fa-circle-check" style="font-size:1.5rem;display:block;margin-bottom:.4rem"></i>
          Δεν υπάρχουν καταγεγραμμένα errors.
        </div>
      <?php else: ?>
        <div style="max-height:320px;overflow-y:auto">
          <?php foreach ($recentErrors as $line): ?>
            <div style="font-family:monospace;font-size:.75rem;color:var(--muted);padding:.35rem 0;border-bottom:1px solid var(--border2);word-break:break-all;line-height:1.5">
              <?= h($line) ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:.75rem;font-size:.8rem;color:var(--muted2)">
          Εμφανίζονται τα τελευταία <?= count($recentErrors) ?> errors.
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Backup list quick view -->
  <div class="card">
    <div class="card-title"><span class="icon"><i class="fa-solid fa-database"></i></span> Πρόσφατα Backups</div>
    <?php if (empty($backupFiles)): ?>
      <div style="color:var(--red);font-size:.9rem"><i class="fa-solid fa-triangle-exclamation"></i> Δεν υπάρχουν backups!</div>
    <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Αρχείο</th><th>Ηλικία</th><th>Μέγεθος</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($backupFiles, 0, 5) as $bf): ?>
            <?php
            $age    = time() - $bf['mtime'];
            $ageStr = $age < 3600 ? round($age/60).' λεπτά' : ($age < 86400 ? round($age/3600).' ώρες' : round($age/86400).' μέρες');
            ?>
            <tr>
              <td style="font-family:monospace;font-size:.83rem"><?= h($bf['name']) ?></td>
              <td style="font-size:.83rem;color:var(--muted)"><?= $ageStr ?> πριν</td>
              <td style="font-size:.83rem;color:var(--muted)"><?= number_format(filesize($backupDir.$bf['name'])/1024,1) ?> KB</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div style="margin-top:.75rem">
      <a href="<?= APP_URL ?>/employee/backups.php" class="btn btn-green" style="font-size:.85rem">
        <i class="fa-solid fa-database"></i> Πήγαινε στα Backups
      </a>
    </div>
  </div>

</div>
<?php renderEmpClose(); ?>