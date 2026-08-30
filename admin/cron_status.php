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

// ── Manual triggers ──
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { verifyCsrf(); } catch (Throwable $e) {}
    $job = (string)($_POST['_job'] ?? '');
    if ($job === 'daily' || $job === 'monthly') {
        $script = $job === 'daily' ? 'reminders.php' : 'monthly_digest.php';
        $path   = escapeshellarg(__DIR__ . '/../cron/' . $script);
        $php    = escapeshellarg(PHP_BINARY);
        $log    = escapeshellarg(__DIR__ . '/../logs/cron.log');
        // Run in background so the request returns fast
        @shell_exec("$php $path >> $log 2>&1 &");
        $flash = 'Το job εκκίνησε στο background. Ανανεώστε σε λίγα δευτερόλεπτα.';
    }
}

// ── Ensure cron_runs table exists (also created by the scripts) ──
$db->exec("CREATE TABLE IF NOT EXISTS cron_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job VARCHAR(60) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    stats JSON NULL,
    INDEX idx_job (job),
    INDEX idx_started (started_at)
)");

// ── Read stamp files (written by docker-entrypoint.sh loop) ──
$stampDaily   = @file_get_contents(__DIR__ . '/../logs/.cron_last_daily')   ?: '';
$stampMonthly = @file_get_contents(__DIR__ . '/../logs/.cron_last_monthly') ?: '';
$stampDaily   = trim($stampDaily);
$stampMonthly = trim($stampMonthly);

$today       = date('Y-m-d');
$thisMonth   = date('Y-m');
$dailyOk     = ($stampDaily === $today);
$monthlyOk   = ($stampMonthly === $thisMonth);

// Last N cron_runs
$recent = $db->query("SELECT id, job, started_at, finished_at, stats
                        FROM cron_runs
                       ORDER BY id DESC LIMIT 30")->fetchAll();

// Reminder stats — last 30 days
$rl = $db->query("SELECT
                    SUM(status='sent' AND type='email') AS emails,
                    SUM(status='sent' AND type='sms')   AS sms,
                    SUM(status='failed')                AS fails
                  FROM reminder_logs
                  WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch();

renderHead('Cron & Αυτόματες Υπενθυμίσεις');
?>
<body>
<div class="app-layout">
<?php renderSidebar('admin_cron'); ?>
<div class="main-content">
<?php renderTopbar('Cron & Αυτόματες Υπενθυμίσεις'); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <div style="background:rgba(45,198,83,.1);border:1px solid rgba(45,198,83,.35);color:#8fe6a1;padding:.7rem 1rem;border-radius:10px;margin-bottom:1rem;font-weight:700">
      <i class="fa-solid fa-circle-check"></i> <?= h($flash) ?>
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-bottom:1.25rem">
    <div style="background:#111520;border:1px solid <?= $dailyOk ? 'rgba(45,198,83,.35)' : 'rgba(230,57,70,.35)' ?>;border-radius:14px;padding:1.1rem">
      <div style="display:flex;align-items:center;gap:.55rem;margin-bottom:.4rem">
        <i class="fa-solid <?= $dailyOk ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>" style="color:<?= $dailyOk ? '#2dc653' : '#e63946' ?>;font-size:1.3rem"></i>
        <div style="font-weight:800;color:#fff">Ημερήσια Υπενθυμίσεις</div>
      </div>
      <div style="color:#c9cee1;font-size:.9rem">Τελευταία εκτέλεση: <b style="color:#fff"><?= h($stampDaily ?: '— (δεν έχει τρέξει)') ?></b></div>
      <div style="color:#8892b0;font-size:.8rem;margin-top:.35rem">Σήμερα: <?= $today ?><?= $dailyOk ? ' · ✓ έγινε' : ' · θα ξανατρέξει αυτόματα' ?></div>
      <form method="POST" style="margin-top:.75rem">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_job" value="daily">
        <button type="submit" style="background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;border:none;padding:.55rem 1rem;border-radius:9px;font-weight:800;cursor:pointer;min-height:38px">
          <i class="fa-solid fa-play"></i> Εκτέλεση τώρα
        </button>
      </form>
    </div>

    <div style="background:#111520;border:1px solid <?= $monthlyOk ? 'rgba(45,198,83,.35)' : 'rgba(240,165,0,.35)' ?>;border-radius:14px;padding:1.1rem">
      <div style="display:flex;align-items:center;gap:.55rem;margin-bottom:.4rem">
        <i class="fa-solid <?= $monthlyOk ? 'fa-circle-check' : 'fa-clock' ?>" style="color:<?= $monthlyOk ? '#2dc653' : '#f0a500' ?>;font-size:1.3rem"></i>
        <div style="font-weight:800;color:#fff">Μηνιαία Σύνοψη</div>
      </div>
      <div style="color:#c9cee1;font-size:.9rem">Τελευταίος μήνας που έτρεξε: <b style="color:#fff"><?= h($stampMonthly ?: '— (δεν έχει τρέξει)') ?></b></div>
      <div style="color:#8892b0;font-size:.8rem;margin-top:.35rem">Τρέχων: <?= $thisMonth ?><?= $monthlyOk ? ' · ✓ έγινε' : ' · θα τρέξει την επόμενη πρώτη του μήνα' ?></div>
      <form method="POST" style="margin-top:.75rem">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_job" value="monthly">
        <button type="submit" style="background:linear-gradient(135deg,#f0a500,#d18a00);color:#fff;border:none;padding:.55rem 1rem;border-radius:9px;font-weight:800;cursor:pointer;min-height:38px">
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
            <td style="padding:.65rem .9rem;color:#fff;font-weight:700"><?= h($r['job']) ?></td>
            <td style="padding:.65rem .9rem;color:#c9cee1"><?= h($r['started_at']) ?></td>
            <td style="padding:.65rem .9rem;color:#c9cee1"><?= h($r['finished_at'] ?? '—') ?></td>
            <td style="padding:.65rem .9rem;color:#8892b0;font-family:monospace;font-size:.8rem"><?= h($r['stats'] ?? '—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
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
