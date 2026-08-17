<?php
/**
 * ============================================================
 * employee/backups.php — Δημιουργία & Διαχείριση Backups
 * ============================================================
 * ΜΟΝΑΔΙΚΗ write-action του maintainer:
 *   - Δημιουργία SQL backup (pure PDO, χωρίς exec/mysqldump)
 *   - Λίστα υπαρχόντων backups
 *   - Download backup
 * ΔΕΝ επιτρέπεται: διαγραφή backups, πρόσβαση σε άλλα δεδομένα
 * ============================================================
 */

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors', 0);
@set_time_limit(300);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/privileges.php';
require_once __DIR__ . '/layout.php';

empRequire('backups_view');

$db         = getDB();
$backupDir  = __DIR__ . '/../backups/';
$backupUrl  = APP_URL . '/backups/';

// ── Ensure backup dir exists ──────────────────────────────────
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0750, true);
}

// ── Action: Download ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $path = $backupDir . $file;

    // Security: only .sql files, must exist
    if (preg_match('/^[\w\-\.]+\.sql$/', $file) && file_exists($path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    setFlash('danger', 'Μη έγκυρο αρχείο.');
    redirect(APP_URL . '/employee/backups.php');
}

// ── Action: Create backup ─────────────────────────────────────
$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_backup') {
    verifyCsrf();

    try {
        @ini_set('memory_limit', '256M');

        $filename  = 'master_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath  = $backupDir . $filename;

        $out  = "-- ============================================================\n";
        $out .= "-- MAster Database Backup\n";
        $out .= "-- Generated : " . date('Y-m-d H:i:s') . "\n";
        $out .= "-- Database  : " . DB_NAME . "\n";
        $out .= "-- Created by: Maintainer (" . h(currentUser()['email'] ?? '') . ")\n";
        $out .= "-- ============================================================\n\n";
        $out .= "SET NAMES utf8mb4;\n";
        $out .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $out .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $out .= "SET TIME_ZONE = '+00:00';\n\n";

        // All base tables
        $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $qt   = "`$table`";
            $out .= "-- --------------------------------------------------------\n";
            $out .= "-- Structure for table $qt\n";
            $out .= "-- --------------------------------------------------------\n\n";
            $out .= "DROP TABLE IF EXISTS $qt;\n";

            $createRow = $db->query("SHOW CREATE TABLE $qt")->fetch(PDO::FETCH_NUM);
            $out      .= $createRow[1] . ";\n\n";

            $count = $db->query("SELECT COUNT(*) FROM $qt")->fetchColumn();
            if ($count === 0) {
                $out .= "-- (table $table is empty)\n\n";
                continue;
            }

            $out .= "-- Data for $qt ($count rows)\n";

            // Fetch in chunks to avoid memory spikes
            $chunkSize = 200;
            $offset    = 0;
            $cols      = null;

            while (true) {
                $rows = $db->query("SELECT * FROM $qt LIMIT $chunkSize OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) break;

                if ($cols === null) {
                    $cols = array_keys($rows[0]);
                    $colList = '(`' . implode('`, `', $cols) . '`)';
                }

                $valueSets = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } elseif (is_numeric($val)) {
                            $vals[] = $val;
                        } else {
                            $vals[] = "'" . addslashes($val) . "'";
                        }
                    }
                    $valueSets[] = '(' . implode(', ', $vals) . ')';
                }

                $out .= "INSERT INTO $qt $colList VALUES\n" . implode(",\n", $valueSets) . ";\n";
                $offset += $chunkSize;

                // Write chunk to file immediately to save memory
                if (!isset($fh)) {
                    $fh = fopen($filepath, 'w');
                    if (!$fh) throw new \RuntimeException("Δεν ήταν δυνατή η δημιουργία αρχείου backup.");
                    fwrite($fh, $out);
                    $out = '';
                } else {
                    fwrite($fh, $out);
                    $out = '';
                }
            }
            $out .= "\n";
        }

        // Write remaining buffer
        if (!isset($fh)) {
            $fh = fopen($filepath, 'w');
            if (!$fh) throw new \RuntimeException("Δεν ήταν δυνατή η δημιουργία αρχείου backup.");
        }
        fwrite($fh, $out);
        fclose($fh);

        // Log the backup action
        $db->prepare("INSERT INTO audit_log (school_id, user_id, action, entity_type, details, ip) VALUES (0, ?, 'backup_created', 'backup', ?, ?)")
           ->execute([userId(), $filename . ' (maintainer)', $_SERVER['REMOTE_ADDR'] ?? '']);

        $message = "✓ Backup δημιουργήθηκε επιτυχώς: <strong>$filename</strong> (" . number_format(filesize($filepath)/1024, 1) . " KB)";
        $msgType = 'success';

    } catch (\Throwable $e) {
        error_log('[employee/backups.php] ERROR: ' . $e->getMessage());
        $message = 'Σφάλμα κατά τη δημιουργία backup: ' . h($e->getMessage());
        $msgType = 'danger';
    }
}

// ── List existing backups ─────────────────────────────────────
$backupFiles = [];
if (is_dir($backupDir)) {
    foreach (glob($backupDir . '*.sql') as $f) {
        $backupFiles[] = [
            'name'  => basename($f),
            'size'  => filesize($f),
            'mtime' => filemtime($f),
        ];
    }
    usort($backupFiles, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
}

renderEmpHead('Backups');
?>
<body>
<?php renderEmpSidebar('backups'); ?>
<div class="emp-main">
<?php renderEmpTopbar('Backups'); ?>
<div class="emp-content">

  <div class="section-title">Backups Βάσης Δεδομένων</div>
  <div class="section-sub">Δημιουργία και λήψη SQL backups — αυτό είναι το μόνο write-action διαθέσιμο στο maintainer.</div>

  <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>">
      <i class="fa-solid fa-<?= $msgType==='success'?'check-circle':'triangle-exclamation' ?>"></i>
      <?= $message ?>
    </div>
  <?php endif; ?>

  <!-- Create backup -->
  <div class="card">
    <div class="card-title"><span class="icon"><i class="fa-solid fa-plus-circle"></i></span> Νέο Backup</div>
    <p style="font-size:.9rem;color:var(--muted);margin-bottom:1.2rem">
      Δημιουργεί πλήρες SQL dump όλης της βάσης δεδομένων μέσω PDO (χωρίς exec/mysqldump).
      Το αρχείο αποθηκεύεται στον φάκελο <code>/backups/</code> και καταγράφεται στο audit log.
    </p>

    <div style="background:rgba(123,97,255,.07);border:1px solid rgba(123,97,255,.2);border-radius:10px;padding:1rem 1.2rem;margin-bottom:1.3rem;font-size:.875rem">
      <i class="fa-solid fa-info-circle" style="color:var(--accent);margin-right:.4rem"></i>
      <strong>Τελευταίο backup:</strong>
      <?php if ($backupFiles): ?>
        <?= date('d/m/Y H:i:s', $backupFiles[0]['mtime']) ?> —
        <strong><?= $backupFiles[0]['name'] ?></strong>
        (<?= number_format($backupFiles[0]['size']/1024, 1) ?> KB)
      <?php else: ?>
        <span style="color:var(--red)">Δεν υπάρχει κανένα backup!</span>
      <?php endif; ?>
    </div>

    <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Δημιουργία…'">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="action"     value="create_backup">
      <button type="submit" class="btn btn-green" style="font-size:1rem;padding:.7rem 1.5rem">
        <i class="fa-solid fa-database"></i> Δημιουργία Backup Τώρα
      </button>
    </form>
  </div>

  <!-- Backup list -->
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:.9rem 1.4rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <span class="card-title" style="margin:0"><span class="icon"><i class="fa-solid fa-folder-open"></i></span> Αποθηκευμένα Backups</span>
      <span style="font-size:.85rem;color:var(--muted)"><?= count($backupFiles) ?> αρχεία</span>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Αρχείο</th>
            <th>Μέγεθος</th>
            <th>Ημερομηνία</th>
            <th>Ηλικία</th>
            <th>Λήψη</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($backupFiles)): ?>
          <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Δεν υπάρχουν backups ακόμη.</td></tr>
        <?php endif; ?>
        <?php foreach ($backupFiles as $i => $bf): ?>
          <?php
          $age     = time() - $bf['mtime'];
          $ageStr  = $age < 3600
                   ? round($age/60) . ' λεπτά'
                   : ($age < 86400 ? round($age/3600) . ' ώρες' : round($age/86400) . ' μέρες');
          $isNew   = $i === 0;
          $isOld   = $age > 86400 * 3;
          ?>
          <tr>
            <td>
              <div style="font-family:monospace;font-size:.85rem;color:var(--text)">
                <?= h($bf['name']) ?>
              </div>
              <?php if ($isNew): ?>
                <span class="badge badge-green" style="font-size:.7rem;margin-top:.2rem">Τελευταίο</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.85rem"><?= number_format($bf['size']/1024, 1) ?> KB</td>
            <td style="font-size:.83rem;color:var(--muted)"><?= date('d/m/Y H:i:s', $bf['mtime']) ?></td>
            <td>
              <span class="badge <?= $isOld ? 'badge-gold' : 'badge-muted' ?>">
                <?= $ageStr ?> πριν
              </span>
            </td>
            <td>
              <a href="?download=<?= urlencode($bf['name']) ?>" class="btn btn-ghost" style="font-size:.82rem">
                <i class="fa-solid fa-download"></i> Download
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Info box -->
  <div class="card" style="background:rgba(230,57,70,.05);border-color:rgba(230,57,70,.2)">
    <div class="card-title" style="color:var(--red)"><span class="icon"><i class="fa-solid fa-shield-halved"></i></span> Σημαντικές Πληροφορίες</div>
    <ul style="font-size:.88rem;color:var(--muted);line-height:1.8;margin-left:1.2rem">
      <li>Τα backups είναι <strong style="color:var(--text)">πλήρη SQL dumps</strong> — μπορούν να χρησιμοποιηθούν για πλήρη επαναφορά μέσω phpMyAdmin.</li>
      <li>Κάθε backup καταγράφεται αυτόματα στο <strong style="color:var(--text)">Audit Log</strong>.</li>
      <li>Τα αρχεία backup προστατεύονται από <code>.htaccess</code> — δεν είναι άμεσα προσβάσιμα από browser.</li>
      <li>Ο maintainer <strong style="color:var(--red)">ΔΕΝ μπορεί να διαγράψει</strong> backups — μόνο ο superadmin.</li>
      <li>Συνιστάται backup τουλάχιστον <strong style="color:var(--text)">μία φορά ανά 24 ώρες</strong>.</li>
    </ul>
  </div>

</div>
<?php renderEmpClose(); ?>