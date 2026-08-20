<?php
/**
 * parent/login.php — Parent Portal Login (STANDALONE — no layout.php)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/rate_limit.php';

if (isset($_SESSION['is_parent']) && $_SESSION['is_parent'] === true) {
    redirect(APP_URL . '/parent/index.php');
}

$error  = '';
$flash  = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        $email    = trim(strtolower($_POST['email']    ?? ''));
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $error = 'Παρακαλώ συμπληρώστε email και κωδικό.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Μη έγκυρη διεύθυνση email.';
        } else {
            $clientIp = trim(explode(',', (string)(
                $_SERVER['HTTP_CF_CONNECTING_IP']   ??
                $_SERVER['HTTP_X_FORWARDED_FOR']    ??
                $_SERVER['REMOTE_ADDR']             ?? '0.0.0.0'
            ))[0]);

            // Rate limit: inline check (isRateLimited does not exist; use login_attempts table directly)
            $rateLimited = false;
            try {
                $db  = getDB();
                $rl  = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE (ip=? OR email=?) AND attempted_at>?');
                $rl->execute([$clientIp, $email, time() - 900]);
                $rateLimited = (int)$rl->fetchColumn() >= 8;
            } catch (Exception $e) { /* table may not exist — skip */ }

            if ($rateLimited) {
                $error = 'Πολλές αποτυχημένες προσπάθειες. Δοκιμάστε ξανά σε 15 λεπτά.';
            } else {
                $db   = $db ?? getDB();
                $stmt = $db->prepare("
                    SELECT pu.*, s.name AS school_name
                    FROM parent_users pu
                    JOIN schools s ON s.id = pu.school_id
                    WHERE pu.parent_email = ?
                    LIMIT 10
                ");
                $stmt->execute([$email]);
                $rows = $stmt->fetchAll();

                $matched   = null;
                $suspended = false;
                foreach ($rows as $row) {
                    if (password_verify($password, $row['password_hash'])) {
                        $matched = $row;
                        if (isset($row['active']) && !(bool)$row['active']) {
                            $suspended = true;
                            $matched   = null;
                        }
                        break;
                    }
                }

                if ($suspended) {
                    $error = 'Ο λογαριασμός σας έχει ανασταλεί. Επικοινωνήστε με τη σχολή σας.';
                } elseif ($matched) {
                    clearLoginAttempts($email, $clientIp);
                    session_regenerate_id(true);
                    $_SESSION['user_id']            = $matched['id'];
                    $_SESSION['parent_id']          = $matched['id'];
                    $_SESSION['school_id']          = $matched['school_id'];
                    $_SESSION['school_name']        = $matched['school_name'];
                    $_SESSION['is_parent']          = true;
                    $_SESSION['parent_email']       = $matched['parent_email'];
                    $_SESSION['parent_first_login']    = (bool)($matched['first_login'] ?? false);
                    $_SESSION['parent_terms_accepted'] = !empty($matched['terms_accepted_at']);
                    $_SESSION['parent_sms_opt_out']    = (bool)($matched['sms_opt_out'] ?? false);
                    $_SESSION['parent_email_opt_out']  = (bool)($matched['email_opt_out'] ?? false);
                    $db->prepare("UPDATE parent_users SET last_login = NOW() WHERE id = ?")->execute([$matched['id']]);
                    redirect(APP_URL . '/parent/index.php');
                } else {
                    recordFailedLogin($email, $clientIp);
                    $error = 'Λάθος email ή κωδικός. Παρακαλώ δοκιμάστε ξανά.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[parent/login.php] ' . $e->getMessage());
        $error = 'Παρουσιάστηκε σφάλμα. Παρακαλώ προσπαθήστε ξανά.';
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title>Portal Γονέων — Σύνδεση — MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:       #07090f;
      --card:     #111520;
      --inp:      #181e2e;
      --brd:      #2a3248;
      --red:      #e63946;
      --red-d:    #c0303b;
      --text:     #f0f2ff;
      --muted:    #b0bdd6;
      --r:        14px;
    }
    html { -webkit-text-size-adjust: 100%; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg); color: var(--text);
      min-height: 100vh; min-height: 100dvh;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      padding: 1.25rem; padding-top: 5rem; padding-bottom: 2rem;
      font-size: 1.05rem;
    }

    .back-home {
      position: fixed; top: .9rem; left: .9rem;
      display: flex; align-items: center; gap: .5rem;
      color: var(--muted); font-size: 1rem; font-weight: 700;
      text-decoration: none;
      background: rgba(255,255,255,.06); border: 1px solid var(--brd);
      border-radius: 50px; padding: .6rem 1.1rem .6rem .85rem;
      transition: all .2s; z-index: 100; min-height: 48px;
    }
    .back-home:hover { color: var(--text); background: rgba(255,255,255,.1); }

    .card {
      background: var(--card); border: 2px solid #1e2536;
      border-radius: 22px; padding: 2rem 1.75rem;
      width: 100%; max-width: 480px;
      box-shadow: 0 24px 64px rgba(0,0,0,.7);
    }

    .portal-badge {
      display: flex; align-items: center; justify-content: center; gap: .4rem;
      background: rgba(230,57,70,.1); border: 1px solid rgba(230,57,70,.3);
      border-radius: 50px; padding: .35rem .9rem;
      font-size: .8rem; font-weight: 800; letter-spacing: .1em;
      text-transform: uppercase; color: var(--red);
      margin: 0 auto .85rem;
    }

    .logo { text-align: center; margin-bottom: .6rem; }
    .logo-img { height: clamp(64px,18vw,96px); width: auto; object-fit: contain; display: block; margin: 0 auto .5rem; }
    .page-heading { font-size: clamp(1.3rem,5vw,1.6rem); font-weight: 800; text-align: center; margin-bottom: .4rem; }
    .page-sub { text-align: center; font-size: .95rem; color: var(--muted); margin-bottom: 1.75rem; line-height: 1.65; }

    .alert { padding: 1rem 1.1rem; border-radius: var(--r); font-size: 1rem; font-weight: 600; margin-bottom: 1.25rem; display: flex; gap: .65rem; align-items: flex-start; line-height: 1.55; }
    .alert i { font-size: 1.1rem; flex-shrink: 0; margin-top: .1rem; }
    .alert-error   { background: rgba(230,57,70,.12); border: 2px solid rgba(230,57,70,.4); color: #ffb3b8; }
    .alert-success { background: rgba(45,198,83,.1);  border: 2px solid rgba(45,198,83,.35); color: #90f0aa; }
    .alert-info    { background: rgba(59,130,246,.08); border: 2px solid rgba(59,130,246,.25); color: #93c5fd; }

    .form-group { margin-bottom: 1.35rem; }
    label { display: flex; align-items: center; gap: .5rem; font-size: 1.05rem; font-weight: 800; margin-bottom: .55rem; color: var(--text); }
    label i { color: var(--muted); width: 18px; text-align: center; }
    .input-wrap { position: relative; }
input[type="email"], input[type="password"], input[type="text"]#password {
	width: 100%; padding: 1rem 3.5rem 1rem 1.1rem;
      background: var(--inp); border: 2px solid var(--brd);
      border-radius: var(--r); color: var(--text);
      font-size: 1.1rem; font-family: inherit;
      transition: border-color .2s, box-shadow .2s;
      -webkit-appearance: none; line-height: 1.4; min-height: 52px;
    }
    input::placeholder { color: #4a5270; }
    input:focus { outline: none; border-color: var(--red); box-shadow: 0 0 0 4px rgba(230,57,70,.18); }
    .eye-btn {
      position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: #4a5270;
      font-size: 1.2rem; width: 44px; height: 44px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 8px; transition: color .2s;
    }
    .eye-btn:hover { color: var(--text); background: rgba(255,255,255,.06); }

    .btn-submit {
      width: 100%; padding: 1.1rem 1rem;
      background: var(--red); color: #fff; border: none;
      border-radius: var(--r);
      font-size: 1.15rem; font-weight: 800; font-family: inherit;
      cursor: pointer; margin-top: .4rem;
      box-shadow: 0 4px 24px rgba(230,57,70,.4);
      transition: background .2s, transform .15s, box-shadow .2s;
      display: flex; align-items: center; justify-content: center; gap: .65rem;
      min-height: 56px; letter-spacing: .01em;
    }
    .btn-submit:hover { background: var(--red-d); box-shadow: 0 6px 32px rgba(230,57,70,.55); transform: translateY(-1px); }
    .btn-submit:active { transform: translateY(0); }

    .consent-note {
      text-align: center; font-size: .82rem; color: #6b7494;
      margin-top: .85rem; line-height: 1.65;
    }
    .consent-note a { color: #8892b0; text-decoration: underline; }
    .consent-note a:hover { color: var(--muted); }

    .card-footer {
      text-align: center; margin-top: 1.5rem;
      font-size: .92rem; color: var(--muted); line-height: 1.8;
      padding-top: 1.25rem; border-top: 1px solid #1e2536;
    }
    .card-footer strong { color: var(--text); }

    @keyframes itemIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .card > * { opacity:0; animation: itemIn .35s ease-out both; }
    .card > *:nth-child(1){ animation-delay:.05s; }
    .card > *:nth-child(2){ animation-delay:.10s; }
    .card > *:nth-child(3){ animation-delay:.15s; }
    .card > *:nth-child(4){ animation-delay:.20s; }
    .card > *:nth-child(5){ animation-delay:.25s; }
    .card > *:nth-child(6){ animation-delay:.30s; }
    @media (prefers-reduced-motion: reduce) { .card > * { animation:none!important; opacity:1; } }

    @media (max-width: 520px) {
      body { padding: 1rem; padding-top: 4.5rem; justify-content: flex-start; }
      .card { padding: 1.6rem 1.25rem; border-radius: 20px; }
    }
  </style>
<?php include __DIR__ . "/../includes/prelogin_polish.php"; ?>
</head>
<body>

<a href="<?= APP_URL ?>/index.php" class="back-home">
  <i class="fas fa-arrow-left"></i>
  <span>Αρχική</span>
</a>

<div class="card">

  <div class="logo">
    <a href="<?= APP_URL ?>/index.php">
      <img src="<?= APP_URL ?>/assets/img/logo-tr.png" alt="MAster" class="logo-img">
    </a>
  </div>

 <div class="page-heading">Σύνδεση Γονέα</div>
  <p class="page-sub">
    Παρακολουθήστε τις συνδρομές και τις πληρωμές<br>
    των παιδιών σας σε πραγματικό χρόνο.
  </p>

  <!-- ── Parent-only notice ── -->
  <div style="display:flex;align-items:center;justify-content:center;gap:.45rem;background:rgba(230,57,70,.1);border:1.5px solid rgba(230,57,70,.35);border-radius:10px;padding:.6rem .9rem;margin-bottom:1.5rem;font-size:.9rem;font-weight:700;color:#ff6b74;text-align:center;line-height:1.45;">
    <i class="fas fa-user-shield" style="color:#e63946;font-size:1rem;flex-shrink:0;"></i>
    <span>Αποκλειστικά για <strong>Γονείς</strong>. <br>Αθλητικά Σωματεία συνδέονται <a href="https://master-app.gr/login.php" style="color:#ff6b74;text-decoration:underline;font-weight:800;white-space:nowrap;">εδώ →</a></span>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-error">
    <i class="fas fa-circle-exclamation"></i>
    <span><?= h($error) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($flash && $flash['type'] === 'success'): ?>
  <div class="alert alert-success">
    <i class="fas fa-circle-check"></i>
    <span><?= h($flash['msg']) ?></span>
  </div>
  <?php elseif ($flash && in_array($flash['type'], ['info', 'warning'])): ?>
  <div class="alert alert-info">
    <i class="fas fa-circle-info"></i>
    <span><?= h($flash['msg']) ?></span>
  </div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

    <div class="form-group">
      <label for="email">
        <i class="fas fa-envelope"></i>
        Email Γονέα
      </label>
      <div class="input-wrap">
        <input type="email" id="email" name="email"
          placeholder="π.χ. maria@gmail.com"
          value="<?= h($_POST['email'] ?? '') ?>"
          autocomplete="email" autofocus required>
      </div>
    </div>

    <div class="form-group">
      <label for="password">
        <i class="fas fa-lock"></i>
        Κωδικός Πρόσβασης
      </label>
      <div class="input-wrap">
        <input type="password" id="password" name="password"
          placeholder="••••••••••"
          autocomplete="current-password" required>
        <button type="button" class="eye-btn" id="eyeBtn" aria-label="Εμφάνιση κωδικού">
          <i class="fas fa-eye" id="eyeIcon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn-submit">
      <i class="fas fa-right-to-bracket"></i>
      Σύνδεση στο Portal
    </button>

    <p class="consent-note">
      Με τη σύνδεση αποδέχεστε τους
      <a href="<?= APP_URL ?>/legal/terms.php" target="_blank">Όρους Χρήσης</a>
      και την
      <a href="<?= APP_URL ?>/legal/privacy.php" target="_blank">Πολιτική Απορρήτου</a>.
    </p>
  </form>

  <div class="card-footer">
    <i class="fas fa-circle-info" style="color:#3b82f6;margin-right:.3rem"></i>
    Τα στοιχεία σύνδεσης εστάλησαν στο email σας<br>
    κατά την εγγραφή του παιδιού σας.<br><br>
    Πρόβλημα σύνδεσης;
    <a href="<?= APP_URL ?>/contact.php" style="color:#e63946;font-weight:700;text-decoration:underline;text-underline-offset:3px">Επικοινωνήστε μαζί μας εδώ</a>.
  </div>

</div>

<style>
  input[type="password"].show-password {
    -webkit-text-security: none;
    text-security: none;
    font-family: inherit;
  }
</style>

<script>
(function(){
  var btn = document.getElementById('eyeBtn');
  var ico = document.getElementById('eyeIcon');
  var pwd = document.getElementById('password');
  if (!btn || !pwd) return;
  btn.addEventListener('click', function(){
    var show = pwd.type === 'password';
    pwd.type = show ? 'text' : 'password';
    ico.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
  });
})();
</script>

</body>
</html>
