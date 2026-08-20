<?php
/**
 * parent/unsubscribe.php — One-click email unsubscribe (RFC 8058 compliant)
 * Accessible without login via signed token in query string.
 * Used in the List-Unsubscribe header and footer link of all automated emails.
 */
require_once __DIR__ . '/../includes/config.php';

$token  = trim($_GET['token'] ?? '');
$type   = trim($_GET['type']  ?? 'email'); // 'email' or 'sms'
$done   = false;
$error  = '';
$email  = '';

if (!$token || strlen($token) < 32) {
    $error = 'Μη έγκυρος σύνδεσμος διακοπής ειδοποιήσεων.';
} else {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, parent_email, email_opt_out, sms_opt_out FROM parent_users WHERE unsubscribe_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row  = $stmt->fetch();

    if (!$row) {
        $error = 'Ο σύνδεσμος δεν είναι έγκυρος ή έχει ήδη χρησιμοποιηθεί.';
    } else {
        $email = $row['parent_email'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['confirm'])) {
            // POST (RFC 8058 one-click) or GET with confirm flag
            $col = ($type === 'sms') ? 'sms_opt_out' : 'email_opt_out';
            $db->prepare("UPDATE parent_users SET {$col} = 1 WHERE id = ?")
               ->execute([$row['id']]);
            // Audit log
            try {
                $rawIp  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $ipHash = hash('sha256', $rawIp . date('Y-m-d'));
                $evType = ($type === 'sms') ? 'sms_opt_out' : 'email_opt_out';
                $db->prepare("INSERT INTO consent_log (parent_user_id, event_type, ip_hash, terms_version) VALUES (?, ?, ?, '1.0')")
                   ->execute([$row['id'], $evType . '_link', $ipHash]);
            } catch (\Throwable $e) { /* non-critical */ }
            $done = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Διακοπή Ειδοποιήσεων — MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
:root { --bg:#07090f; --card:#111520; --brd:#1e2536; --red:#e63946; --green:#2dc653; --text:#f0f2ff; --muted:#b0bdd6; }
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'DM Sans',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; min-height:100dvh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:1.5rem; }
.card { background:var(--card); border:1px solid var(--brd); border-radius:18px; padding:2.5rem 2rem; max-width:480px; width:100%; text-align:center; }
.logo { font-family:'Bebas Neue',sans-serif; font-size:1.8rem; letter-spacing:.08em; margin-bottom:1.5rem; }
.logo span { color:var(--red); }
.icon { font-size:3rem; margin-bottom:1rem; }
.icon.success { color:var(--green); }
.icon.warn { color:var(--red); }
h1 { font-family:'Bebas Neue',sans-serif; font-size:1.8rem; letter-spacing:.04em; margin-bottom:.75rem; }
p { font-size:.95rem; color:var(--muted); line-height:1.65; margin-bottom:1rem; }
.btn { display:inline-flex; align-items:center; gap:.5rem; padding:.75rem 1.75rem; border-radius:12px; font-size:.95rem; font-weight:800; cursor:pointer; border:none; font-family:inherit; text-decoration:none; transition:all .2s; margin-top:.5rem; }
.btn-red { background:var(--red); color:#fff; }
.btn-red:hover { background:#c0303b; }
.btn-outline { background:rgba(255,255,255,.06); border:1px solid var(--brd); color:var(--muted); }
.btn-outline:hover { background:rgba(255,255,255,.1); color:var(--text); }
.email-badge { display:inline-block; background:rgba(255,255,255,.06); border:1px solid var(--brd); border-radius:8px; padding:.35rem .9rem; font-size:.85rem; font-family:monospace; margin:.5rem 0 1rem; }
</style>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/postlogin-portal-theme.css?v=<?= @filemtime(__DIR__ . "/../assets/css/postlogin-portal-theme.css") ?: time() ?>">
</head>
<body>
<div class="card">
  <div class="logo">MA<span>ster</span></div>

  <?php if ($error): ?>
    <div class="icon warn"><i class="fas fa-circle-xmark"></i></div>
    <h1>Σφάλμα</h1>
    <p><?= htmlspecialchars($error) ?></p>
    <a href="<?= APP_URL ?>/parent/login.php" class="btn btn-outline">Σύνδεση</a>

  <?php elseif ($done): ?>
    <div class="icon success"><i class="fas fa-circle-check"></i></div>
    <h1>Απεγγράφηκες</h1>
    <?php if ($type === 'sms'): ?>
      <p>Έχετε διακόψει τις <strong>SMS ειδοποιήσεις</strong> για τον λογαριασμό:</p>
    <?php else: ?>
      <p>Έχετε διακόψει τις <strong>Email ειδοποιήσεις</strong> για τον λογαριασμό:</p>
    <?php endif; ?>
    <div class="email-badge"><?= htmlspecialchars($email) ?></div>
    <p style="font-size:.85rem">Μπορείτε να επαναενεργοποιήσετε τις ειδοποιήσεις ανά πάσα στιγμή από τις <strong>Ρυθμίσεις</strong> της Πύλης.</p>
    <a href="<?= APP_URL ?>/parent/login.php" class="btn btn-outline">Σύνδεση στην Πύλη</a>

  <?php else: ?>
    <div class="icon warn"><i class="fas fa-bell-slash"></i></div>
    <h1>Διακοπή Ειδοποιήσεων</h1>
    <?php if ($type === 'sms'): ?>
      <p>Πρόκειται να σταματήσετε τις <strong>SMS ειδοποιήσεις</strong> για:</p>
    <?php else: ?>
      <p>Πρόκειται να σταματήσετε τις <strong>Email ειδοποιήσεις</strong> για:</p>
    <?php endif; ?>
    <div class="email-badge"><?= htmlspecialchars($email) ?></div>
    <form method="POST">
      <button type="submit" class="btn btn-red">
        <i class="fas fa-bell-slash"></i> Επιβεβαίωση Διακοπής
      </button>
    </form>
    <br>
    <a href="<?= APP_URL ?>/parent/index.php" class="btn btn-outline">Ακύρωση</a>
  <?php endif; ?>
</div>
</body>
</html>
