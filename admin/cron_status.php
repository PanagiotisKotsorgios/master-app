<?php
/**
 * admin/cron_status.php — Superadmin view of the in-container cron
 *   • Shows last daily reminders run + last monthly digest
 *   • Recent cron_runs entries
 *   • Manual "Run now" buttons
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
if (!isSuperAdmin()) { redirect(APP_URL . '/dashboard/'); }

$db = getDB();

// Keep this schema aligned with cron/reminders.php, which is the canonical
// writer for the daily reminder job.
$db->exec("CREATE TABLE IF NOT EXISTS cron_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    school_id INT NULL DEFAULT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_name (job_name),
    INDEX idx_started_at (started_at),
    INDEX idx_school (school_id)
)");

// ── Manual triggers ──
$flash = '';
$flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $job = (string)($_POST['_job'] ?? '');
    if ($job === 'daily' || $job === 'monthly') {
        $script = $job === 'daily' ? 'reminders.php' : 'monthly_digest.php';
        $jobName = $job === 'daily' ? 'reminders' : 'monthly_digest';

        $runningStmt = $db->prepare("SELECT id FROM cron_runs
                                      WHERE job_name = ?
                                        AND status = 'running'
                                        AND started_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                                      ORDER BY id DESC LIMIT 1");
        $runningStmt->execute([$jobName]);

        if ($runningStmt->fetchColumn()) {
            $flash = 'Το job εκτελείται ήδη. Περιμένετε να ολοκληρωθεί και ανανεώστε τη σελίδα.';
            $flashType = 'warning';
        } else {
            // PHP_BINARY belongs to the current Apache SAPI process and is not
            // guaranteed to be the CLI executable. The official php:8.2-apache
            // image used by Coolify provides the CLI at /usr/local/bin/php.
            $phpCli = '';
            $phpCandidates = array_unique([
                '/usr/local/bin/php',
                rtrim(PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . 'php',
                '/usr/bin/php',
            ]);
            foreach ($phpCandidates as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $phpCli = $candidate;
                    break;
                }
            }

            $logFile = __DIR__ . '/../logs/cron.log';
            if ($phpCli === '') {
                $flash = 'Δεν βρέθηκε εκτελέσιμο PHP CLI μέσα στον app container.';
                $flashType = 'danger';
            } elseif (!is_dir(dirname($logFile)) || !is_writable(dirname($logFile))) {
                $flash = 'Ο φάκελος logs του app container δεν είναι εγγράψιμος.';
                $flashType = 'danger';
            } else {
                $path = escapeshellarg(__DIR__ . '/../cron/' . $script);
                $php  = escapeshellarg($phpCli);
                $log  = escapeshellarg($logFile);
                // Execute the same CLI script as the container scheduler.
                // nohup keeps it alive after this HTTP request has returned.
                $pid = trim((string)@shell_exec("nohup $php $path >> $log 2>&1 < /dev/null & echo $!"));

                if ($pid !== '' && ctype_digit($pid)) {
                    $flash = 'Το job εκκίνησε με ' . $phpCli . ' (PID ' . $pid . '). Η σελίδα θα ανανεωθεί αυτόματα για τα αποτελέσματα.';
                } else {
                    $flash = 'Δεν ήταν δυνατή η εκκίνηση του PHP CLI job. Ελέγξτε το πρόσφατο output παρακάτω.';
                    $flashType = 'danger';
                }
            }
        }
    }
}

// ── Read stamp files (written by docker-entrypoint.sh loop) ──
$stampDaily   = @file_get_contents(__DIR__ . '/../logs/.cron_last_daily')   ?: '';
$stampMonthly = @file_get_contents(__DIR__ . '/../logs/.cron_last_monthly') ?: '';
$stampDaily   = trim($stampDaily);
$stampMonthly = trim($stampMonthly);

$today       = date('Y-m-d');
$thisMonth   = date('Y-m');
$dailyOk     = ($stampDaily === $today);
$monthlyOk   = ($stampMonthly === $thisMonth);

// Actual executions, including runs started manually from this page.
$lastJobStmt = $db->prepare("SELECT started_at, finished_at, status, message
                              FROM cron_runs
                             WHERE job_name = ?
                             ORDER BY id DESC LIMIT 1");
$lastJobStmt->execute(['reminders']);
$lastDailyRun = $lastJobStmt->fetch() ?: null;
$lastJobStmt->execute(['monthly_digest']);
$lastMonthlyRun = $lastJobStmt->fetch() ?: null;

// Last N cron_runs
$recent = $db->query("SELECT id, job_name, started_at, finished_at, status, message
                        FROM cron_runs
                       ORDER BY id DESC LIMIT 30")->fetchAll();

// Reminder stats — last 30 days
$rl = $db->query("SELECT
                    SUM(status='sent' AND type='email') AS emails,
                    SUM(status='sent' AND type='sms')   AS sms,
                    SUM(status='failed')                AS fails
                  FROM reminder_logs
                  WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch();

// Recent detailed CLI output, so a superadmin can verify matches, sends and
// skips without opening the container terminal.
$cronLogTail = '';
$cronLogPath = __DIR__ . '/../logs/cron.log';
if (is_file($cronLogPath) && is_readable($cronLogPath)) {
    $logSize = (int)(filesize($cronLogPath) ?: 0);
    $offset = max(0, $logSize - 65536);
    $handle = @fopen($cronLogPath, 'rb');
    if ($handle) {
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $chunk = (string)stream_get_contents($handle);
        fclose($handle);
        if ($offset > 0 && ($firstNewline = strpos($chunk, "\n")) !== false) {
            $chunk = substr($chunk, $firstNewline + 1);
        }
        $logLines = preg_split('/\R/', trim($chunk)) ?: [];
        $cronLogTail = implode(PHP_EOL, array_slice($logLines, -120));
    }
}

renderHead('Cron & Αυτόματες Υπενθυμίσεις');
?>
<body>
<div class="app-layout">
<?php renderSidebar('admin_cron'); ?>
<div class="main-content">
<?php renderTopbar('Cron & Αυτόματες Υπενθυμίσεις'); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <?php
      $flashBg = $flashType === 'danger' ? 'rgba(230,57,70,.1)' : ($flashType === 'warning' ? 'rgba(240,165,0,.1)' : 'rgba(45,198,83,.1)');
      $flashBorder = $flashType === 'danger' ? 'rgba(230,57,70,.35)' : ($flashType === 'warning' ? 'rgba(240,165,0,.35)' : 'rgba(45,198,83,.35)');
      $flashColor = $flashType === 'danger' ? '#ff8891' : ($flashType === 'warning' ? '#f0c45e' : '#8fe6a1');
      $flashIcon = $flashType === 'danger' ? 'fa-circle-xmark' : ($flashType === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check');
    ?>
    <div style="background:<?= $flashBg ?>;border:1px solid <?= $flashBorder ?>;color:<?= $flashColor ?>;padding:.7rem 1rem;border-radius:10px;margin-bottom:1rem;font-weight:700">
      <i class="fa-solid <?= $flashIcon ?>"></i> <?= h($flash) ?>
    </div>
    <?php if ($flashType === 'success'): ?>
      <script>
        window.setTimeout(function () {
          window.location.href = <?= json_encode(APP_URL . '/admin/cron_status.php', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        }, 5000);
      </script>
    <?php endif; ?>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-bottom:1.25rem">
    <div style="background:#111520;border:1px solid <?= $dailyOk ? 'rgba(45,198,83,.35)' : 'rgba(230,57,70,.35)' ?>;border-radius:14px;padding:1.1rem">
      <div style="display:flex;align-items:center;gap:.55rem;margin-bottom:.4rem">
        <i class="fa-solid <?= $dailyOk ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>" style="color:<?= $dailyOk ? '#2dc653' : '#e63946' ?>;font-size:1.3rem"></i>
        <div style="font-weight:800;color:#fff">Ημερήσια Υπενθυμίσεις</div>
      </div>
      <div style="color:#c9cee1;font-size:.9rem">Τελευταία εκτέλεση: <b style="color:#fff"><?= h($lastDailyRun ? ($lastDailyRun['finished_at'] ?: $lastDailyRun['started_at']) : '— (δεν έχει τρέξει)') ?></b></div>
      <div style="color:#8892b0;font-size:.8rem;margin-top:.35rem">Αυτόματος έλεγχος: <?= h($stampDaily ?: '—') ?><?= $dailyOk ? ' · ✓ έγινε σήμερα' : ' · θα ξανατρέξει αυτόματα' ?></div>
      <form method="POST" style="margin-top:.75rem">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_job" value="daily">
        <button type="submit" onclick="return confirm('Η εκτέλεση θα ελέγξει όλες τις ενεργές σχολές και μπορεί να στείλει πραγματικά email ή SMS. Συνέχεια;')" style="background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;border:none;padding:.55rem 1rem;border-radius:9px;font-weight:800;cursor:pointer;min-height:38px">
          <i class="fa-solid fa-play"></i> Εκτέλεση τώρα
        </button>
      </form>
    </div>

    <div style="background:#111520;border:1px solid <?= $monthlyOk ? 'rgba(45,198,83,.35)' : 'rgba(240,165,0,.35)' ?>;border-radius:14px;padding:1.1rem">
      <div style="display:flex;align-items:center;gap:.55rem;margin-bottom:.4rem">
        <i class="fa-solid <?= $monthlyOk ? 'fa-circle-check' : 'fa-clock' ?>" style="color:<?= $monthlyOk ? '#2dc653' : '#f0a500' ?>;font-size:1.3rem"></i>
        <div style="font-weight:800;color:#fff">Μηνιαία Σύνοψη</div>
      </div>
      <div style="color:#c9cee1;font-size:.9rem">Τελευταία εκτέλεση: <b style="color:#fff"><?= h($lastMonthlyRun ? ($lastMonthlyRun['finished_at'] ?: $lastMonthlyRun['started_at']) : '— (δεν έχει τρέξει)') ?></b></div>
      <div style="color:#8892b0;font-size:.8rem;margin-top:.35rem">Αυτόματος έλεγχος: <?= h($stampMonthly ?: '—') ?><?= $monthlyOk ? ' · ✓ έγινε αυτόν τον μήνα' : ' · θα τρέξει αυτόματα' ?></div>
      <form method="POST" style="margin-top:.75rem">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_job" value="monthly">
        <button type="submit" onclick="return confirm('Η εκτέλεση θα στείλει τη μηνιαία σύνοψη στους ιδιοκτήτες των σχολών που έχουν σχετικά δεδομένα. Συνέχεια;')" style="background:linear-gradient(135deg,#f0a500,#d18a00);color:#fff;border:none;padding:.55rem 1rem;border-radius:9px;font-weight:800;cursor:pointer;min-height:38px">
          <i class="fa-solid fa-play"></i> Εκτέλεση τώρα
        </button>
      </form>
    </div>

    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem">
      <div style="color:#8892b0;font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.4rem">Τελευταίες 30 μέρες</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem">
        <div style="text-align:center"><div style="color:#8fe6a1;font-weight:800;font-size:1.6rem;line-height:1"><?= (int)($rl['emails'] ?? 0) ?></div><div style="color:#8892b0;font-size:.72rem">emails</div></div>
        <div style="text-align:center"><div style="color:#a9c1ff;font-weight:800;font-size:1.6rem;line-height:1"><?= (int)($rl['sms'] ?? 0) ?></div><div style="color:#8892b0;font-size:.72rem">sms</div></div>
        <div style="text-align:center"><div style="color:<?= (int)($rl['fails'] ?? 0) > 0 ? '#ff8891' : '#4a5270' ?>;font-weight:800;font-size:1.6rem;line-height:1"><?= (int)($rl['fails'] ?? 0) ?></div><div style="color:#8892b0;font-size:.72rem">αποτυχίες</div></div>
      </div>
    </div>
  </div>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;overflow:hidden">
    <div style="padding:.85rem 1.1rem;border-bottom:1px solid #1e2536;font-weight:800;color:#fff">Ιστορικό εκτελέσεων</div>
    <div class="table-wrap" style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;min-width:640px">
        <thead>
          <tr style="background:rgba(255,255,255,.03)">
            <th style="padding:.6rem .9rem;text-align:left;font-size:.72rem;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em">Job</th>
            <th style="padding:.6rem .9rem;text-align:left;font-size:.72rem;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em">Εκκίνηση</th>
            <th style="padding:.6rem .9rem;text-align:left;font-size:.72rem;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em">Λήξη</th>
            <th style="padding:.6rem .9rem;text-align:left;font-size:.72rem;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em">Στατιστικά</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="4" style="padding:1.5rem;text-align:center;color:#8892b0">Καμία εκτέλεση ακόμη.</td></tr>
        <?php else: foreach ($recent as $r): ?>
          <tr style="border-top:1px solid rgba(255,255,255,.05)">
            <td style="padding:.65rem .9rem;color:#fff;font-weight:700"><?= h($r['job_name']) ?></td>
            <td style="padding:.65rem .9rem;color:#c9cee1"><?= h($r['started_at']) ?></td>
            <td style="padding:.65rem .9rem;color:#c9cee1"><?= h($r['finished_at'] ?? '—') ?></td>
            <td style="padding:.65rem .9rem;color:#8892b0;font-family:monospace;font-size:.8rem">
              <span style="color:<?= ($r['status'] ?? '') === 'success' ? '#8fe6a1' : (($r['status'] ?? '') === 'failed' ? '#ff8891' : '#f0c45e') ?>"><?= h($r['status'] ?? '—') ?></span>
              <?= !empty($r['message']) ? ' · ' . h($r['message']) : '' ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div style="margin-top:1rem;background:#0b0e14;border:1px solid #1e2536;border-radius:14px;overflow:hidden">
    <div style="padding:.85rem 1.1rem;border-bottom:1px solid #1e2536;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap">
      <div style="font-weight:800;color:#fff"><i class="fa-solid fa-terminal" style="color:#8fe6a1"></i> Πρόσφατο αναλυτικό output</div>
      <button type="button" onclick="window.location.reload()" style="background:#182033;color:#c9cee1;border:1px solid #2a3653;padding:.4rem .7rem;border-radius:8px;font-weight:700;cursor:pointer">
        <i class="fa-solid fa-rotate"></i> Ανανέωση
      </button>
    </div>
    <?php if ($cronLogTail !== ''): ?>
      <pre style="margin:0;padding:1rem 1.1rem;max-height:430px;overflow:auto;color:#b8c4da;font:12px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap;word-break:break-word"><?= htmlspecialchars($cronLogTail, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
    <?php else: ?>
      <div style="padding:1rem 1.1rem;color:#8892b0">Δεν υπάρχει ακόμη output στο cron log.</div>
    <?php endif; ?>
  </div>

  <div style="margin-top:1rem;padding:.85rem 1.1rem;background:#0d1017;border:1px dashed #2a3248;border-radius:10px;color:#8892b0;font-size:.85rem;line-height:1.55">
    <strong style="color:#c9cee1"><i class="fa-solid fa-circle-info"></i> Πώς λειτουργεί</strong><br>
    Ο container εκτελεί ένα loop στο background που κάθε ώρα ελέγχει αν έχει τρέξει σήμερα το ημερήσιο job. Αν όχι — τρέχει τις υπενθυμίσεις και σφραγίζει την ημέρα. Την πρώτη ώρα κάθε μήνα, στέλνει και τη μηνιαία σύνοψη στους ιδιοκτήτες κάθε ενεργής σχολής.<br>
    Δεν χρειάζεται external Coolify cron — τα stamp files επιβιώνουν restarts και εγγυώνται ότι δεν χάνεται εκτέλεση.
  </div>

</div>
</div>
</div>
</body>
</html>
