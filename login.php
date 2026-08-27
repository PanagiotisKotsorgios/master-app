<?php
/**
 * ============================================================
 * login.php — Σελίδα Σύνδεσης Χρήστη
 * ============================================================
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/two_factor.php';

// ── 2FA Verification step ──
if (isset($_SESSION['pending_2fa_user_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_verify'])) {
    $pending = $_SESSION['pending_2fa_user_id'];
    $db = getDB();
    $uStmt = $db->prepare("SELECT * FROM users WHERE id = ? AND active = 1 LIMIT 1");
    $uStmt->execute([(int)$pending]);
    $u = $uStmt->fetch();
    if (!$u) {
        unset($_SESSION['pending_2fa_user_id']);
        redirect(APP_URL . '/login.php');
    }
    $code  = trim($_POST['totp_code'] ?? '');
    $valid = verify2FAOtp($pending, $code);
    if ($valid) {
        session_regenerate_id(true);
        $_SESSION['user_id']     = $u['id'];
        $_SESSION['school_id']   = $u['school_id'];
        $_SESSION['school_name'] = $u['school_name'] ?? 'MAster';
        $_SESSION['user']        = ['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'avatar'=>$u['avatar_url']??null];
        unset($_SESSION['pending_2fa_user_id']);
        $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
        redirect($u['role'] === 'superadmin' ? APP_URL.'/admin/' : APP_URL.'/dashboard/');
    } else {
        $twoFAError = 'Λάθος ή ληγμένος κωδικός. Δοκιμάστε ξανά ή ζητήστε νέο.';
    }
}

// SECURITY: Διαχωρισμός parent και club sessions
if (isset($_SESSION['is_parent']) && $_SESSION['is_parent'] === true) {
    redirect(APP_URL . '/parent/index.php');
} elseif (isClubLoggedIn()) {
    redirect(isSuperAdmin() ? APP_URL.'/admin/' : APP_URL.'/dashboard/');
}

$error = '';

if (!empty($_SESSION['oauth_error'])) {
    $error = $_SESSION['oauth_error'];
    unset($_SESSION['oauth_error']);
}

// ── POST: σύνδεση ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email    = trim(strtolower($_POST['email']    ?? ''));
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember_me']);

    if (!$email || !$password) {
        $error = 'Παρακαλώ συμπληρώστε και τα δύο πεδία.';
    } else {
        require_once __DIR__ . '/includes/rate_limit.php';
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
                 ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                 ?? $_SERVER['REMOTE_ADDR']
                 ?? '0.0.0.0';
        $clientIp = trim(explode(',', $clientIp)[0]);
        checkLoginRateLimit($email, $clientIp);
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT u.*, s.name AS school_name
               FROM users u
               LEFT JOIN schools s ON s.id = u.school_id
              WHERE u.email = ? AND u.active = 1 LIMIT 1"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['password'] && password_verify($password, $user['password'])) {

            // ── Block employee / maintainer accounts from regular login ──
            if (in_array($user['role'], ['employee', 'maintainer'], true)) {
                require_once __DIR__ . '/includes/rate_limit.php';
                $clientIpOk = $_SERVER['HTTP_CF_CONNECTING_IP']
                           ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                           ?? $_SERVER['REMOTE_ADDR']
                           ?? '0.0.0.0';
                $clientIpOk = trim(explode(',', $clientIpOk)[0]);
                clearLoginAttempts($email, $clientIpOk);
                $error = 'Αυτός ο λογαριασμός είναι λογαριασμός εργαζομένου. Παρακαλώ συνδεθείτε από τη σελίδα Employee Login.';
            } else {

            $has2FA = false;
            try {
                $u2faStmt = $db->prepare("SELECT totp_enabled FROM users WHERE id = ? LIMIT 1");
                $u2faStmt->execute([(int)$user['id']]);
                $u2fa   = $u2faStmt->fetch();
                $has2FA = !empty($u2fa['totp_enabled']);
            } catch(Exception $e) {}

            if ($has2FA) {
                $_SESSION['pending_2fa_user_id'] = $user['id'];
                require_once __DIR__ . '/includes/mailer.php';
                send2FAOtpEmail($user['id'], $user['email'], $user['name'] ?? '');
                redirect(APP_URL.'/login.php?step=2fa');
            }

            session_regenerate_id(true);
            $_SESSION['user_id']     = $user['id'];
            $_SESSION['school_id']   = $user['school_id'];
            $_SESSION['school_name'] = $user['school_name'] ?? 'MAster';
            $_SESSION['user']        = [
                'id'     => $user['id'],
                'name'   => $user['name'],
                'email'  => $user['email'],
                'role'   => $user['role'],
                'avatar' => $user['avatar_url'] ?? null,
            ];

            if ($remember) {
                $token = bin2hex(random_bytes(32));
                setcookie('rm_email', $email, time() + 60*60*24*30, '/', '', true, true);
                setcookie('rm_token', $token, time() + 60*60*24*30, '/', '', true, true);
                try {
                    $db->prepare("UPDATE users SET remember_token=? WHERE id=?")
                       ->execute([hash('sha256', $token), $user['id']]);
                } catch(Exception $e) {}
            }

            require_once __DIR__ . '/includes/rate_limit.php';
            $loginIp = $_SERVER['HTTP_CF_CONNECTING_IP']
                    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                    ?? $_SERVER['REMOTE_ADDR']
                    ?? '0.0.0.0';
            $loginIp = trim(explode(',', $loginIp)[0]);
            clearLoginAttempts($email, $loginIp);
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            auditLog('login');
            redirect($user['role'] === 'superadmin' ? APP_URL.'/admin/' : APP_URL.'/dashboard/');
            } // end else (not employee)
        } else {
            require_once __DIR__ . '/includes/rate_limit.php';
            $clientIp2 = $_SERVER['HTTP_CF_CONNECTING_IP']
                      ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                      ?? $_SERVER['REMOTE_ADDR']
                      ?? '0.0.0.0';
            $clientIp2 = trim(explode(',', $clientIp2)[0]);
            recordFailedLogin($email, $clientIp2);
            $error = 'Λάθος email ή κωδικός. Παρακαλώ δοκιμάστε ξανά.';
        }
    }
}

$rememberedEmail = $_COOKIE['rm_email'] ?? '';
$isRemembered    = !empty($rememberedEmail);

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>Σύνδεση - MAster - Εφαρμογή Διαχείρισης Αθλητικών Συλλόγων / Συνδρομών Μελλών</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="shortcut icon" href="./assets/img/favicon.png" type="image/png">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:       #07090f;
      --card:     #111520;
      --input-bg: #181e2e;
      --border:   #2a3248;
      --red:      #e63946;
      --red-dark: #c0303b;
      --white:    #f0f2ff;
      --muted:    #8892b0;
      --green:    #2dc653;
      --radius:   14px;
    }
    html { -webkit-text-size-adjust: 100%; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg); color: var(--white);
      min-height: 100vh; min-height: 100dvh;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      padding: 1rem; padding-top: 4.5rem; padding-bottom: 1.5rem;
    }

    /* ── Back button ── */
    .back-home {
      position: fixed; top: .85rem; left: .85rem;
      display: flex; align-items: center; gap: .45rem;
      color: var(--muted); font-size: .95rem; font-weight: 700;
      text-decoration: none;
      background: rgba(255,255,255,.05); border: 1px solid var(--border);
      border-radius: 50px; padding: .55rem 1rem .55rem .8rem;
      transition: all .2s; z-index: 100; min-height: 44px;
    }
    .back-home:hover, .back-home:active {
      color: var(--white); background: rgba(255,255,255,.1);
      border-color: rgba(255,255,255,.2);
    }
    .back-home i { font-size: .9rem; }

    /* ── Card ── */
    .card {
      background: var(--card); border: 2px solid #1e2536;
      border-radius: 20px; padding: 1.75rem 1.5rem;
      width: 100%; max-width: 460px;
      box-shadow: 0 20px 60px rgba(0,0,0,.7);
    }
    .logo { text-align: center; margin-bottom: 1.4rem; }
    .logo a { display: inline-block; text-decoration: none; }
    .logo-img { height: clamp(64px,18vw,110px); width: auto; object-fit: contain; display: block; margin: 0 auto .5rem; }
    .page-heading { font-size: clamp(1.2rem,5vw,1.5rem); font-weight: 800; text-align: center; margin-bottom: .75rem; line-height: 1.3; }

    /* ── Club-only notice ── */
    .club-only-notice {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .45rem;
      background: rgba(230,57,70,.1);
      border: 1.5px solid rgba(230,57,70,.35);
      border-radius: 10px;
      padding: .6rem .9rem;
      margin-bottom: 1.25rem;
      font-size: clamp(.82rem, 3.6vw, .92rem);
      font-weight: 700;
      color: #ff6b74;
      text-align: center;
      line-height: 1.4;
    }
    .club-only-notice i {
      color: var(--red);
      font-size: 1rem;
      flex-shrink: 0;
    }

    /* ── Alert ── */
    .alert { padding: .9rem 1rem; border-radius: var(--radius); font-size: clamp(.95rem,4vw,1.05rem); font-weight: 600; margin-bottom: 1.25rem; display: flex; gap: .65rem; align-items: flex-start; line-height: 1.55; }
    .alert i { font-size: 1.1rem; flex-shrink: 0; margin-top: .1rem; }
    .alert-error   { background: rgba(230,57,70,.12); border: 2px solid rgba(230,57,70,.4); color: #ffb3b8; }
    .alert-success { background: rgba(45,198,83,.1);  border: 2px solid rgba(45,198,83,.35); color: #90f0aa; }

    /* ── Form ── */
    .form-group { margin-bottom: 1.2rem; }
    label { display: flex; align-items: center; gap: .5rem; font-size: clamp(1rem,4.5vw,1.1rem); font-weight: 800; margin-bottom: .55rem; color: var(--white); }
    label i { color: var(--muted); font-size: 1rem; width: 18px; text-align: center; }
    .input-wrap { position: relative; }
    input[type="email"], input[type="password"], input[type="text"] {
      width: 100%; padding: .95rem 3.5rem .95rem 1rem;
      background: var(--input-bg); border: 2px solid var(--border);
      border-radius: var(--radius); color: var(--white);
      font-size: clamp(1rem,4.5vw,1.15rem); font-family: inherit;
      transition: border-color .2s, box-shadow .2s;
      -webkit-appearance: none; line-height: 1.4;
    }
    input::placeholder { color: #4a5270; }
    input:focus { outline: none; border-color: var(--red); box-shadow: 0 0 0 4px rgba(230,57,70,.18); }
    .eye-btn {
      position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: #4a5270;
      font-size: 1.15rem; width: 44px; height: 44px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 8px; transition: color .2s, background .2s;
    }
    .eye-btn:hover, .eye-btn:active { color: var(--white); background: rgba(255,255,255,.06); }

    /* ════════════════════════════════════════════
       Remember Me + Forgot row
       ── ALWAYS side by side, never wraps ──
       Left:  checkbox + label  (flex-shrink:0 so it never squishes)
       Right: forgot link       (text-align:right, shrinks text if needed)
    ════════════════════════════════════════════ */
    .remember-forgot-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .5rem;
      flex-wrap: nowrap;       /* ← never wrap to second line */
      margin-top: .65rem;
      margin-bottom: 1.25rem;
    }

    /* Left side — checkbox + "Να με θυμάσαι" */
    .remember-wrap {
      display: flex;
      align-items: center;
      gap: .5rem;
      flex-shrink: 0;          /* ← never compress the checkbox side */
      cursor: pointer;
      user-select: none;
    }
    .remember-wrap input[type="checkbox"] {
      appearance: none;
      -webkit-appearance: none;
      width: 22px; height: 22px; min-width: 22px;
      background: var(--input-bg);
      border: 2px solid var(--border);
      border-radius: 7px;
      padding: 0;
      cursor: pointer;
      position: relative;
      transition: border-color .2s, background .2s, box-shadow .2s;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .remember-wrap input[type="checkbox"]:checked {
      background: var(--red);
      border-color: var(--red);
      box-shadow: 0 0 0 3px rgba(230,57,70,.2);
    }
    .remember-wrap input[type="checkbox"]::after {
      content: '';
      display: block;
      width: 5px; height: 9px;
      border: 2px solid transparent;
      border-top: none; border-left: none;
      border-right-color: #fff; border-bottom-color: #fff;
      transform: rotate(45deg) translate(-1px,-1px);
      opacity: 0;
      transition: opacity .15s;
    }
    .remember-wrap input[type="checkbox"]:checked::after { opacity: 1; }
    .remember-wrap input[type="checkbox"]:focus {
      outline: none;
      border-color: var(--red);
      box-shadow: 0 0 0 3px rgba(230,57,70,.2);
    }
    .remember-lbl {
      font-size: clamp(.85rem, 3.8vw, 1rem);
      font-weight: 600;
      color: var(--muted);
      transition: color .15s;
      line-height: 1;
      white-space: nowrap;     /* ← label stays on one line */
    }
    .remember-wrap:hover .remember-lbl { color: var(--white); }

    /* Right side — forgot password link */
    .forgot-link {
      font-size: clamp(.85rem, 3.8vw, 1rem); /* exactly same as .remember-lbl */
      color: var(--muted);
      text-decoration: none;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: 0;
      transition: color .2s;
      white-space: nowrap;
      text-align: right;
      flex-shrink: 0;
      line-height: 1;          /* match remember-lbl line-height */
    }
    .forgot-link:hover, .forgot-link:active { color: var(--white); }
    .forgot-link i {
      font-size: 1em;          /* same size as the text */
      flex-shrink: 0;
      color: var(--muted);
    }
    .forgot-link:hover i { color: var(--white); }

    @media (max-width: 340px) {
      .forgot-link   { font-size: .75rem; }
      .remember-lbl  { font-size: .75rem; }
    }

    /* ── Submit ── */
    .btn-submit {
      width: 100%; padding: 1.1rem 1rem;
      background: var(--red); color: #fff; border: none;
      border-radius: var(--radius);
      font-size: clamp(1.1rem,5vw,1.25rem); font-weight: 800; font-family: inherit;
      cursor: pointer; margin-top: .4rem;
      box-shadow: 0 4px 20px rgba(230,57,70,.4);
      transition: background .2s, transform .15s, box-shadow .2s;
      display: flex; align-items: center; justify-content: center; gap: .65rem;
      min-height: 56px; letter-spacing: .01em;
    }
    .btn-submit:hover { background: var(--red-dark); box-shadow: 0 6px 28px rgba(230,57,70,.55); transform: translateY(-1px); }
    .btn-submit:active { transform: translateY(0); background: var(--red-dark); }

    /* ── Footer ── */
    .card-footer { text-align: center; margin-top: 1.4rem; font-size: clamp(.95rem,4vw,1.05rem); color: var(--muted); line-height: 1.75; }
    .card-footer a { color: var(--white); font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; gap: .4rem; padding: .2rem 0; }
    .card-footer a:hover, .card-footer a:active { color: var(--red); text-decoration: underline; }

    /* ── Responsive ── */
@media (max-width: 480px) {
  body { padding: 1rem; padding-top: 4.25rem; padding-bottom: 1.25rem; justify-content: flex-start; }
  .card { padding: 1.5rem 1.25rem; border-radius: 18px; }
}
    @media (max-width: 375px) {
      body { padding: .75rem; padding-top: 4rem; padding-bottom: 1rem; }
      .card { padding: 1.25rem 1rem; border-radius: 16px; }
      .form-group { margin-bottom: 1rem; }
      .page-heading { margin-bottom: 1.1rem; }
      .logo { margin-bottom: 1rem; }
      .btn-submit { min-height: 54px; }
    }
    @media (max-width: 320px) {
      body { padding: .6rem; padding-top: 3.75rem; padding-bottom: .75rem; }
      .card { padding: 1rem .9rem; border-radius: 14px; border-width: 1px; }
      .logo { margin-bottom: .85rem; }
      .page-heading { margin-bottom: 1rem; }
      .form-group { margin-bottom: .85rem; }
      .card-footer { margin-top: 1.1rem; }
    }
    @media (max-height: 600px) and (orientation: landscape) {
      body { justify-content: flex-start; padding-top: 4rem; padding-bottom: 1rem; }
      .logo-img { height: 52px; }
      .logo { margin-bottom: .75rem; }
      .page-heading { font-size: 1.1rem; margin-bottom: .9rem; }
      .form-group { margin-bottom: .85rem; }
    }

    /* ── Stagger animation ── */
    @keyframes itemIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .card > * { opacity:0; animation: itemIn .45s ease-out both; }
    .card > *:nth-child(1){ animation-delay:.08s; }
    .card > *:nth-child(2){ animation-delay:.14s; }
    .card > *:nth-child(3){ animation-delay:.20s; }
    .card > *:nth-child(4){ animation-delay:.26s; }
    .card > *:nth-child(5){ animation-delay:.32s; }
    @media (prefers-reduced-motion: reduce){ .card > *{ animation:none!important; opacity:1; } }
  </style>
<?php include __DIR__ . '/includes/prelogin_polish.php'; ?>
</head>
<body>

<a href="<?= APP_URL ?>/index.php" class="back-home">
  <i class="fas fa-arrow-left"></i>
  <span>Αρχική Σελίδα</span>
</a>

<div class="card">

  <div class="logo">
    <a href="<?= APP_URL ?>/index.php">
      <img src="<?= APP_URL ?>/assets/img/logo-tr.png" alt="MAster" class="logo-img">
    </a>
  </div>

  <div class="page-heading">Σύνδεση στο σύστημα</div>

<!-- ── Club-only notice ── -->
  <div class="club-only-notice">
    <i class="fas fa-shield-halved"></i>
    <span>Αποκλειστικά για <strong>Αθλητικά Σωματεία</strong>. Γονείς συνδέονται <a href="https://master-app.gr/parent/login.php" style="color:#ff6b74;text-decoration:underline;font-weight:800;white-space:nowrap;">εδώ →</a></span>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-error">
    <i class="fas fa-circle-exclamation"></i>
    <div>
      <?= h($error) ?>
      <?php if (str_contains($error, 'Employee Login') || str_contains($error, 'εργαζομένου')): ?>
        <div style="margin-top:.55rem;">
          <a href="<?= APP_URL ?>/employee/login.php" style="color:#ffb3b8;font-weight:800;text-decoration:underline;">
            Μετάβαση στο Employee Login →
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($flash && $flash['type'] === 'success'): ?>
  <div class="alert alert-success">
    <i class="fas fa-circle-check"></i>
    <span><?= h($flash['msg']) ?></span>
  </div>
  <?php endif; ?>

  <!-- ── 2FA Step ── -->
  <?php if (isset($_SESSION['pending_2fa_user_id']) || (isset($_GET['step']) && $_GET['step'] === '2fa')): ?>
  <div style="text-align:center;margin-bottom:1.5rem">
    <div style="width:64px;height:64px;border-radius:50%;background:rgba(230,57,70,.1);border:2px solid rgba(230,57,70,.3);display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;color:#e63946">
      <i class="fas fa-envelope-open-text"></i>
    </div>
    <h3 style="margin:.75rem 0 .25rem;font-size:1.1rem;color:#f0f2ff">Επαλήθευση με Email</h3>
    <p style="font-size:.82rem;color:#6b7494">Σας στείλαμε έναν 6-ψήφιο κωδικό στο email σας.<br>Ισχύει για <strong style="color:#f0a500">10 λεπτά</strong>.</p>
  </div>
  <?php if (isset($twoFAError)): ?>
  <div style="background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.3);border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;color:#e63946;font-size:.875rem">
    <i class="fas fa-triangle-exclamation"></i> <?= h($twoFAError) ?>
  </div>
  <?php endif; ?>
  <form method="POST" novalidate>
    <input type="hidden" name="totp_verify" value="1">
    <div style="margin-bottom:1rem">
      <label style="display:block;font-size:.82rem;color:#6b7494;margin-bottom:.4rem"><i class="fas fa-key"></i> Κωδικός από Email</label>
      <input type="text" name="totp_code" placeholder="000000" maxlength="6" autofocus autocomplete="one-time-code"
        style="width:100%;padding:.85rem 1rem;border-radius:10px;border:1px solid #1e2536;background:#0a0d16;color:#f0f2ff;font-size:1.5rem;text-align:center;letter-spacing:.3em;font-family:monospace">
    </div>
    <button type="submit" style="width:100%;padding:.9rem;border-radius:10px;background:linear-gradient(135deg,#e63946,#d62f3d);color:#fff;font-size:1rem;font-weight:700;border:none;cursor:pointer;box-shadow:0 0 22px rgba(230,57,70,.35)">
      <i class="fas fa-shield-check"></i> Επαλήθευση
    </button>
    <p style="text-align:center;margin-top:.75rem;font-size:.78rem;color:#6b7494">Δεν λάβατε κωδικό; Ελέγξτε spam ή επιστρέψτε και συνδεθείτε ξανά.</p>
    <p style="text-align:center;margin-top:.25rem;font-size:.75rem"><a href="<?= APP_URL ?>/logout.php" style="color:#6b7494">← Επιστροφή στη σύνδεση</a></p>
  </form>

  <?php else: ?>
  <!-- ── Login Form ── -->
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

    <div class="form-group">
      <label for="email">
        <i class="fas fa-envelope"></i>
        Διεύθυνση Email
      </label>
      <input
        type="email" id="email" name="email"
        placeholder="π.χ. giannis@gmail.com"
        value="<?= h($_POST['email'] ?? $rememberedEmail) ?>"
        autocomplete="email" autofocus required>
    </div>

    <div class="form-group">
      <label for="password">
        <i class="fas fa-lock"></i>
        Κωδικός Πρόσβασης
      </label>
      <div class="input-wrap">
        <input
          type="password" id="password" name="password"
          placeholder="••••••••"
          autocomplete="current-password" required>
        <button type="button" class="eye-btn" onclick="togglePwd()" title="Εμφάνιση/απόκρυψη κωδικού" aria-label="Εμφάνιση κωδικού">
          <i class="fas fa-eye" id="eyeIcon"></i>
        </button>
      </div>

      <!-- ══ Remember Me + Forgot — always on one row ══ -->
      <div class="remember-forgot-row">

        <!-- Left: checkbox -->
        <label class="remember-wrap" for="remember_me">
          <input
            type="checkbox"
            id="remember_me"
            name="remember_me"
            <?= $isRemembered ? 'checked' : '' ?>>
          <span class="remember-lbl">Να με θυμάσαι</span>
        </label>

        <!-- Right: forgot link -->
        <a href="<?= APP_URL ?>/forgot-password.php" class="forgot-link">
          Ξεχάσατε τον κωδικό;
        </a>

      </div>
    </div>

    <button type="submit" class="btn-submit">
      <i class="fas fa-right-to-bracket"></i>
      Σύνδεση
    </button>
  </form>
  <?php endif; ?>

  <div class="card-footer">
    Δεν έχετε λογαριασμό ακόμα;<br>
    <a href="<?= APP_URL ?>/register.php">
      <i class="fas fa-user-plus"></i>
      Εγγραφή δωρεάν - 14 ημέρες δοκιμή
    </a>
  </div>

</div>

<script>
function togglePwd() {
  var inp  = document.getElementById('password');
  var icon = document.getElementById('eyeIcon');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'fas fa-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'fas fa-eye';
  }
}
</script>
</body>
</html>