<?php
/**
 * pages/opt-out-manual.php — Manual opt-out handler for admin
 * Use this when a parent sends STOP via SMS (+30 6986788178) or email
 * (pkotsorgios654@gmail.com) and you need to process it manually.
 *
 * Access: Admin/employee only.
 */

// ── Render ALL PHP errors as HTML instead of crashing ──────────────────────
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    $type = match($errno) {
        E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR         => 'Fatal Error',
        E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING  => 'Warning',
        E_NOTICE, E_USER_NOTICE                                        => 'Notice',
        E_DEPRECATED, E_USER_DEPRECATED                                => 'Deprecated',
        default                                                         => "Error #{$errno}",
    };
    _renderPhpError($type, $errstr, $errfile, $errline);
    return true;
});

set_exception_handler(function(Throwable $e): void {
    _renderPhpError(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
});

register_shutdown_function(function(): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        _renderPhpError('Fatal Shutdown Error', $err['message'], $err['file'], $err['line']);
    }
});

function _renderPhpError(string $type, string $msg, string $file, int $line, string $trace = ''): void {
    $shortFile = defined('APP_ROOT') ? str_replace(APP_ROOT, '', $file) : $file;
    echo '<div style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#1a0a0a;border-bottom:3px solid #e63946;font-family:monospace;font-size:13px;color:#f0f2ff;padding:1rem 1.5rem;max-height:60vh;overflow-y:auto;box-shadow:0 4px 32px rgba(230,57,70,.4);">'
        . '<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.6rem;">'
        . '<span style="background:#e63946;color:#fff;font-weight:700;padding:.2rem .6rem;border-radius:4px;font-size:.8rem;letter-spacing:.05em;">' . htmlspecialchars($type) . '</span>'
        . '<span style="color:#ff6b76;font-weight:600;">' . htmlspecialchars($msg) . '</span>'
        . '</div>'
        . '<div style="color:#6b7494;font-size:.8rem;">📄 <span style="color:#f0a500;">' . htmlspecialchars($shortFile) . '</span> · line <strong style="color:#fff;">' . $line . '</strong></div>';
    if ($trace) {
        echo '<details style="margin-top:.75rem;"><summary style="cursor:pointer;color:#6b7494;font-size:.78rem;">Stack trace</summary>'
            . '<pre style="margin-top:.5rem;color:#6b7494;font-size:.75rem;white-space:pre-wrap;word-break:break-all;">' . htmlspecialchars($trace) . '</pre></details>';
    }
    echo '</div><div style="height:80px;"></div>';
}
// ── End error handler ───────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
renderPaymentWall();

$db      = getDB();
$success = '';
$error   = '';
$migrationNeeded = false;

// ── Auto-create consent_log if missing ─────────────────────────────────────
try {
    $db->query("SELECT 1 FROM `consent_log` LIMIT 1");
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), '1146')) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `consent_log` (
              `id`             int(11)      NOT NULL AUTO_INCREMENT,
              `parent_user_id` int(11)      DEFAULT NULL,
              `event_type`     varchar(100) NOT NULL,
              `ip_hash`        varchar(64)  DEFAULT NULL,
              `terms_version`  varchar(20)  NOT NULL DEFAULT '1.0',
              `created_at`     timestamp    NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_parent_user_id` (`parent_user_id`),
              KEY `idx_event_type`     (`event_type`),
              KEY `idx_created_at`     (`created_at`),
              CONSTRAINT `fk_consent_log_parent`
                FOREIGN KEY (`parent_user_id`)
                REFERENCES `parent_users` (`id`)
                ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $migrationNeeded = true;
    } else {
        throw $e;
    }
}
// ───────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email   = strtolower(trim($_POST['parent_email'] ?? ''));
    $channel = $_POST['channel'] ?? 'email';
    $reason  = trim($_POST['reason'] ?? 'manual_admin');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Εισάγετε έγκυρο email.';
    } else {
        $col  = ($channel === 'sms') ? 'sms_opt_out' : 'email_opt_out';
        $stmt = $db->prepare("UPDATE parent_users SET {$col} = 1 WHERE parent_email = ?");
        $stmt->execute([$email]);
        $affected = $stmt->rowCount();

        if ($affected > 0) {
            $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0') . date('Y-m-d'));
            $db->prepare("INSERT INTO consent_log (parent_user_id, event_type, ip_hash, terms_version)
                          SELECT id, ?, ?, '1.0' FROM parent_users WHERE parent_email = ? LIMIT 1")
               ->execute([$col . '_' . $reason, $ipHash, $email]);
            $success = "Opt-out ({$channel}) καταχωρήθηκε για {$email}. Επηρεάστηκαν {$affected} εγγραφές.";
        } else {
            $error = "Δεν βρέθηκε γονέας με email: {$email}";
        }
    }
}

// Recent opt-out log
$log = $db->query("
    SELECT cl.event_type, cl.created_at, pu.parent_email
    FROM consent_log cl
    LEFT JOIN parent_users pu ON pu.id = cl.parent_user_id
    WHERE cl.event_type LIKE '%opt_out%'
    ORDER BY cl.created_at DESC
    LIMIT 50
")->fetchAll();

// ── Render ─────────────────────────────────────────────────────────────────
renderHead('Διαχείριση Opt-out');
?>
<body>
<div class="app-layout">
<?php renderSidebar('opt_out'); ?>
<div id="dm-overlay"></div>

<div class="main-content">
<?php renderTopbar('Διαχείριση Opt-out'); ?>
<div class="page-body">

<div class="page-header">
  <h1 class="page-title"><i class="fas fa-bell-slash"></i> Διαχείριση Opt-out Ειδοποιήσεων</h1>
  <p class="page-sub">Χρησιμοποιήστε αυτή τη σελίδα όταν ένας γονέας στείλει STOP μέσω SMS ή email.</p>
</div>

<?php if ($migrationNeeded): ?>
<div class="alert alert-warning mb-4">
  <i class="fas fa-wrench me-2"></i>
  <strong>Migration αυτόματη εκτέλεση:</strong> Ο πίνακας <code>consent_log</code> δεν υπήρχε και δημιουργήθηκε αυτόματα. Η σελίδα είναι πλέον πλήρως λειτουργική.
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">
        <h5 class="card-title mb-0"><i class="fas fa-hand-point-right text-warning me-2"></i>Καταχώρηση Opt-out</h5>
      </div>
      <div class="card-body">
        <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="alert alert-info mb-3" style="font-size:.85rem">
          <strong>Πότε να χρησιμοποιήσετε αυτή τη σελίδα:</strong><br>
          • Ο γονέας έστειλε SMS <strong>STOP</strong> στον αριθμό <strong>+30 6986788178</strong><br>
          • Ο γονέας έστειλε email με θέμα <strong>STOP</strong> στο <strong>pkotsorgios654@gmail.com</strong>
        </div>

        <form method="POST">
          <?= csrfField() ?>
          <div class="mb-3">
            <label class="form-label fw-bold">Email γονέα</label>
            <input type="email" name="parent_email" class="form-control" placeholder="parent@example.com" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Κανάλι opt-out</label>
            <select name="channel" class="form-select">
              <option value="email">Email ειδοποιήσεις</option>
              <option value="sms">SMS ειδοποιήσεις</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Αιτία (για audit log)</label>
            <select name="reason" class="form-select">
              <option value="stop_sms">Εισερχόμενο STOP SMS</option>
              <option value="stop_email">Εισερχόμενο STOP Email</option>
              <option value="manual_admin">Χειροκίνητη ενέργεια διαχειριστή</option>
              <option value="complaint">Καταγγελία</option>
            </select>
          </div>
          <button type="submit" class="btn btn-warning fw-bold w-100">
            <i class="fas fa-bell-slash me-2"></i>Καταχώρηση Opt-out
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <h5 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Ιστορικό Opt-out (τελευταίες 50)</h5>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Email</th><th>Τύπος</th><th>Ημερομηνία</th></tr></thead>
          <tbody>
            <?php foreach ($log as $l): ?>
            <tr>
              <td><?= htmlspecialchars($l['parent_email'] ?? '—') ?></td>
              <td><code><?= htmlspecialchars($l['event_type']) ?></code></td>
              <td><?= htmlspecialchars($l['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($log)): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">Δεν υπάρχουν εγγραφές.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

</div><!-- /page-body -->
</div><!-- /main-content -->
</div><!-- /app-layout -->
</body>
</html>