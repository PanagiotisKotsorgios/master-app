<?php
/**
 * ============================================================
 * employee/index.php — Maintainer Dashboard
 * ============================================================
 */

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$db = getDB();

/**
 * Μικρό helper για ασφαλές scalar query
 */
function scalar(PDO $db, string $sql, array $params = [], mixed $default = 0): mixed
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Throwable $e) {
        error_log('Dashboard scalar query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return $default;
    }
}

/**
 * Μικρό helper για ασφαλές fetchAll
 */
function rows(PDO $db, string $sql, array $params = []): array
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Dashboard rows query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return [];
    }
}

try {
    // Αν δεν το κάνει ήδη το getDB(), κλειδώνουμε σύνδεση σε utf8mb4/unicode_ci
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('SET NAMES failed in employee dashboard: ' . $e->getMessage());
}

// ── Stats ────────────────────────────────────────────────────
// schools.active, schools.plan_status, schools.subscription_status υπάρχουν στο schema
$totalSchools  = (int) scalar($db, "SELECT COUNT(*) FROM schools WHERE active = 1");
$totalUsers    = (int) scalar($db, "SELECT COUNT(*) FROM users WHERE active = 1");
$totalAthletes = (int) scalar($db, "SELECT COUNT(*) FROM athletes WHERE active = 1");

// Στη δική σου βάση ΔΕΝ υπάρχει subscription_transactions.
// Για revenue σχολών χρησιμοποιούμε school_plan_payments.
$totalPayments = (int) scalar($db, "SELECT COUNT(*) FROM school_plan_payments");
$revenueTotal  = (float) scalar($db, "SELECT COALESCE(SUM(amount),0) FROM school_plan_payments", [], 0);

$trialSchools = (int) scalar($db, "SELECT COUNT(*) FROM schools WHERE plan_status = 'trial' AND active = 1");
$proSchools   = (int) scalar($db, "SELECT COUNT(*) FROM schools WHERE plan_status = 'active' AND active = 1");
$suspSchools  = (int) scalar(
    $db,
    "SELECT COUNT(*) 
     FROM schools 
     WHERE subscription_status IN ('suspended','past_due','cancelled')
       AND active = 1"
);

// ── Recent audit events ──────────────────────────────────────
// audit_log, users, schools υπάρχουν στο dump
$recentEvents = rows(
    $db,
    "SELECT 
        a.*,
        u.name  AS user_name,
        u.email AS user_email,
        s.name  AS school_name
     FROM audit_log a
     LEFT JOIN users u   ON u.id = a.user_id
     LEFT JOIN schools s ON s.id = a.school_id
     ORDER BY a.created_at DESC
     LIMIT 12"
);

// ── Schools with issues (suspended/past_due/cancelled) ───────
// schools.plan_id και plans.id υπάρχουν, ενώ από τον αρχικό κώδικα θεωρούμε ότι το plans.name υπάρχει
$issueSchools = rows(
    $db,
    "SELECT 
        s.*,
        p.name AS plan_name
     FROM schools s
     LEFT JOIN plans p ON p.id = s.plan_id
     WHERE s.subscription_status IN ('suspended','past_due','cancelled')
       AND s.active = 1
     ORDER BY 
        FIELD(s.subscription_status, 'suspended', 'past_due', 'cancelled'),
        s.name ASC
     LIMIT 10"
);

// ── Backups list ─────────────────────────────────────────────
$backupDir   = __DIR__ . '/../backups/';
$backupFiles = [];

if (is_dir($backupDir)) {
    foreach (glob($backupDir . '*.sql') as $f) {
        $backupFiles[] = [
            'name'  => basename($f),
            'size'  => @filesize($f) ?: 0,
            'mtime' => @filemtime($f) ?: 0,
        ];
    }

    usort($backupFiles, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

$lastBackup = $backupFiles[0] ?? null;

renderEmpHead('Dashboard');
?>
<body>
<?php renderEmpSidebar('dash'); ?>

<div class="emp-main">
  <?php renderEmpTopbar('Dashboard'); ?>

  <div class="emp-content">

    <div class="section-title">
      Καλωσήρθες, <?= h(currentUser()['name'] ?? 'Maintainer') ?> 👋
    </div>
    <div class="section-sub">
      Επισκόπηση συστήματος — Read-only παρακολούθηση &amp; δημιουργία backups.
    </div>

    <?php
    $backupAge = $lastBackup ? (time() - (int)$lastBackup['mtime']) : PHP_INT_MAX;
    if ($backupAge > 86400 * 2):
    ?>
      <div class="alert alert-warn">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
          <strong>Προσοχή:</strong>
          Το τελευταίο backup έγινε
          <?= $lastBackup ? date('d/m/Y H:i', (int)$lastBackup['mtime']) : 'ποτέ' ?>.
          Σκέψου να δημιουργήσεις νέο backup τώρα.
          <a href="<?= APP_URL ?>/employee/backups.php" style="margin-left:.5rem">→ Backups</a>
        </div>
      </div>
    <?php endif; ?>

    <!-- Stats grid -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">
          <i class="fa-solid fa-school" style="color:var(--blue)"></i> Ενεργές Σχολές
        </div>
        <div class="stat-val"><?= number_format($totalSchools) ?></div>
        <div class="stat-sub"><?= number_format($proSchools) ?> Pro · <?= number_format($trialSchools) ?> Trial</div>
      </div>

      <div class="stat-card">
        <div class="stat-label">
          <i class="fa-solid fa-users" style="color:var(--accent)"></i> Χρήστες
        </div>
        <div class="stat-val"><?= number_format($totalUsers) ?></div>
        <div class="stat-sub">Ενεργοί λογαριασμοί</div>
      </div>

      <div class="stat-card">
        <div class="stat-label">
          <i class="fa-solid fa-person-running" style="color:var(--green)"></i> Αθλητές
        </div>
        <div class="stat-val"><?= number_format($totalAthletes) ?></div>
        <div class="stat-sub">Ενεργοί αθλητές</div>
      </div>

      <div class="stat-card">
        <div class="stat-label">
          <i class="fa-solid fa-euro-sign" style="color:var(--gold)"></i> Συνολικά Έσοδα
        </div>
        <div class="stat-val">€<?= number_format($revenueTotal, 2) ?></div>
        <div class="stat-sub"><?= number_format($totalPayments) ?> πληρωμές πλάνων</div>
      </div>

      <div class="stat-card" style="<?= $suspSchools > 0 ? 'border-color:rgba(230,57,70,.3)' : '' ?>">
        <div class="stat-label">
          <i class="fa-solid fa-circle-exclamation" style="color:var(--red)"></i> Προβληματικές
        </div>
        <div class="stat-val" style="color:<?= $suspSchools > 0 ? 'var(--red)' : 'var(--green)' ?>">
          <?= number_format($suspSchools) ?>
        </div>
        <div class="stat-sub">Suspended / Past due / Cancelled</div>
      </div>

      <div class="stat-card">
        <div class="stat-label">
          <i class="fa-solid fa-database" style="color:var(--teal)"></i> Τελευταίο Backup
        </div>
        <div class="stat-val" style="font-size:1rem;padding-top:.3rem">
          <?= $lastBackup ? date('d/m/Y', (int)$lastBackup['mtime']) : '—' ?>
        </div>
        <div class="stat-sub">
          <?= $lastBackup ? date('H:i', (int)$lastBackup['mtime']) : 'Δεν υπάρχει' ?>
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">

      <!-- Recent Activity -->
      <div class="card">
        <div class="card-title">
          <span class="icon"><i class="fa-solid fa-wave-square"></i></span>
          Πρόσφατη Δραστηριότητα
        </div>

        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>Ενέργεια</th>
                <th>Χρήστης</th>
                <th>Σχολή</th>
                <th>Ώρα</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($recentEvents)): ?>
              <tr>
                <td colspan="4" style="text-align:center;color:var(--muted);padding:1rem">
                  Δεν υπάρχουν πρόσφατα events.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentEvents as $ev): ?>
                <tr>
                  <td>
                    <?php
                    $action = (string)($ev['action'] ?? '');

                    $icon = match (true) {
                      str_contains($action, 'login')  => '<i class="fa-solid fa-right-to-bracket" style="color:var(--green)"></i>',
                      str_contains($action, 'logout') => '<i class="fa-solid fa-right-from-bracket" style="color:var(--muted)"></i>',
                      str_contains($action, 'backup') => '<i class="fa-solid fa-database" style="color:var(--blue)"></i>',
                      str_contains($action, 'delete') => '<i class="fa-solid fa-trash" style="color:var(--red)"></i>',
                      str_contains($action, 'edit') || str_contains($action, 'update')
                        => '<i class="fa-solid fa-pen" style="color:var(--gold)"></i>',
                      str_contains($action, 'add')
                        => '<i class="fa-solid fa-plus" style="color:var(--teal)"></i>',
                      str_contains($action, 'payment')
                        => '<i class="fa-solid fa-money-bill-wave" style="color:var(--green)"></i>',
                      default
                        => '<i class="fa-solid fa-circle-dot" style="color:var(--muted)"></i>',
                    };
                    ?>
                    <?= $icon ?>
                    <span style="font-size:.82rem"><?= h($action) ?></span>
                  </td>

                  <td style="font-size:.82rem;color:var(--muted)">
                    <?= h($ev['user_name'] ?? '—') ?>
                  </td>

                  <td style="font-size:.82rem;color:var(--muted)">
                    <?= h($ev['school_name'] ?? '—') ?>
                  </td>

                  <td style="font-size:.78rem;color:var(--muted2)">
                    <?= !empty($ev['created_at']) ? date('d/m H:i', strtotime($ev['created_at'])) : '—' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div style="margin-top:.75rem">
          <a href="<?= APP_URL ?>/employee/logs.php" class="btn btn-ghost" style="font-size:.82rem">
            <i class="fa-solid fa-list"></i> Όλα τα logs
          </a>
        </div>
      </div>

      <!-- Schools with issues -->
      <div class="card">
        <div class="card-title">
          <span class="icon">
            <i class="fa-solid fa-triangle-exclamation" style="color:var(--red)"></i>
          </span>
          Σχολές με Πρόβλημα
        </div>

        <?php if (empty($issueSchools)): ?>
          <div style="text-align:center;padding:2rem 0;color:var(--green)">
            <i class="fa-solid fa-circle-check" style="font-size:2rem;margin-bottom:.5rem;display:block"></i>
            Όλες οι σχολές είναι ενεργές!
          </div>
        <?php else: ?>
          <div class="tbl-wrap">
            <table>
              <thead>
                <tr>
                  <th>Σχολή</th>
                  <th>Status</th>
                  <th>Πλάνο</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($issueSchools as $sc): ?>
                <tr>
                  <td style="font-size:.85rem"><?= h($sc['name'] ?? '—') ?></td>
                  <td>
                    <?php
                    $subscriptionStatus = (string)($sc['subscription_status'] ?? '');

                    $badgeClass = match ($subscriptionStatus) {
                      'suspended' => 'badge-red',
                      'past_due'  => 'badge-gold',
                      'cancelled' => 'badge-muted',
                      default     => 'badge-muted',
                    };
                    ?>
                    <span class="badge <?= $badgeClass ?>">
                      <?= h($subscriptionStatus ?: '—') ?>
                    </span>
                  </td>
                  <td style="font-size:.82rem;color:var(--muted)">
                    <?= h($sc['plan_name'] ?? '—') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div style="margin-top:.75rem">
          <a href="<?= APP_URL ?>/employee/schools.php" class="btn btn-ghost" style="font-size:.82rem">
            <i class="fa-solid fa-school"></i> Όλες οι Σχολές
          </a>
        </div>
      </div>

    </div>

  </div><!-- .emp-content -->

  <?php renderEmpClose(); ?>
</div>
</body>
</html>