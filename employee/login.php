<?php
/**
 * ============================================================
 * employee/login.php — Employee Login
 * ============================================================
 * Ξεχωριστή σελίδα login για το Employee panel.
 * Υποστηρίζει role = "employee" και legacy role = "maintainer".
 * ============================================================
 */

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/config.php';

function employeeLoginRole(?string $role = null): bool {
    $role = $role ?? (string)($_SESSION['user']['role'] ?? '');
    return in_array($role, ['employee', 'maintainer'], true);
}

// SECURITY: Αν είναι parent session → πίσω στο parent portal
if (isset($_SESSION['is_parent']) && $_SESSION['is_parent'] === true) {
    redirect(APP_URL . '/parent/index.php');
}

if (isClubLoggedIn() && employeeLoginRole()) {
    redirect(APP_URL . '/employee/');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email    = trim(mb_strtolower($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Συμπλήρωσε email και κωδικό.';
    } else {
        $db = getDB();

        $stmt = $db->prepare("
            SELECT u.*, s.name AS school_name
            FROM users u
            LEFT JOIN schools s ON s.id = u.school_id
            WHERE u.email = ?
              AND u.role IN ('employee', 'maintainer')
              AND u.active = 1
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user_id']     = (int)$user['id'];
            $_SESSION['school_id']   = $user['school_id'] ? (int)$user['school_id'] : null;
            $_SESSION['school_name'] = $user['school_name'] ?? 'MAster';
            $_SESSION['user']        = [
                'id'     => (int)$user['id'],
                'name'   => $user['name'],
                'email'  => $user['email'],
                'role'   => $user['role'] === 'maintainer' ? 'employee' : $user['role'],
                'avatar' => $user['avatar_url'] ?? null,
            ];
            $_SESSION['last_activity'] = time();

            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
               ->execute([(int)$user['id']]);

            auditLog('employee_login', '', 0, 'Employee panel login');

            $redirectTo = $_SESSION['intended_url'] ?? '';
            unset($_SESSION['intended_url']);

            if ($redirectTo && str_contains($redirectTo, '/employee/')) {
                redirect($redirectTo);
            }

            redirect(APP_URL . '/employee/');
        }

        $error = 'Λανθασμένα στοιχεία ή δεν έχεις πρόσβαση σε αυτό το panel.';
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>Employee Login - MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
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
      background: var(--bg);
      color: var(--white);
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      padding-top: 4.5rem;
      padding-bottom: 1.5rem;
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
    .back-home:hover { color: var(--white); background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.2); }
    .back-home i { font-size: .9rem; }

    /* ── Card ── */
    .card {
      background: var(--card);
      border: 2px solid #1e2536;
      border-radius: 20px;
      padding: 1.75rem 1.5rem;
      width: 100%; max-width: 460px;
      box-shadow: 0 20px 60px rgba(0,0,0,.7);
    }

    /* ── Logo / heading ── */
    .logo { text-align: center; margin-bottom: 1.4rem; }
    .logo a { display: inline-block; text-decoration: none; }
    .logo-img { height: clamp(64px,18vw,110px); width: auto; object-fit: contain; display: block; margin: 0 auto .5rem; }
    .page-heading { font-size: clamp(1.2rem,5vw,1.5rem); font-weight: 800; text-align: center; margin-bottom: .4rem; line-height: 1.3; }
    .page-sub { text-align: center; color: var(--muted); font-size: .92rem; margin-bottom: 1.4rem; }

    /* ── Employee badge ── */
    .emp-badge {
      display: inline-flex; align-items: center; gap: .45rem;
      background: rgba(230,57,70,.1); border: 1px solid rgba(230,57,70,.3);
      color: #ffb3b8; font-size: .8rem; font-weight: 800;
      padding: .3rem .75rem; border-radius: 999px;
      text-transform: uppercase; letter-spacing: .05em;
      margin: 0 auto 1.4rem; display: flex; width: fit-content;
    }

    /* ── Alert ── */
    .alert { padding: .9rem 1rem; border-radius: var(--radius); font-size: clamp(.95rem,4vw,1.05rem); font-weight: 600; margin-bottom: 1.25rem; display: flex; gap: .65rem; align-items: flex-start; line-height: 1.55; }
    .alert i { font-size: 1.1rem; flex-shrink: 0; margin-top: .1rem; }
    .alert-error { background: rgba(230,57,70,.12); border: 2px solid rgba(230,57,70,.4); color: #ffb3b8; }

    /* ── Form ── */
    .form-group { margin-bottom: 1.2rem; }
    label { display: flex; align-items: center; gap: .5rem; font-size: clamp(1rem,4.5vw,1.1rem); font-weight: 800; margin-bottom: .55rem; color: var(--white); }
    label i { color: var(--muted); font-size: 1rem; width: 18px; text-align: center; }
    .input-wrap { position: relative; }
    input[type="email"], input[type="password"] {
      width: 100%; padding: .95rem 3.5rem .95rem 1rem;
      background: var(--input-bg); border: 2px solid var(--border);
      border-radius: var(--radius); color: var(--white);
      font-size: clamp(1rem,4.5vw,1.15rem); font-family: inherit;
      transition: border-color .2s, box-shadow .2s;
      -webkit-appearance: none; line-height: 1.4;
    }
    input::placeholder { color: #4a5270; }
    input:focus { outline: none; border-color: var(--red); box-shadow: 0 0 0 4px rgba(230,57,70,.18); }

    /* eye toggle */
    .eye-btn {
      position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: #4a5270;
      font-size: 1.15rem; width: 44px; height: 44px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 8px; transition: color .2s, background .2s;
    }
    .eye-btn:hover { color: var(--white); background: rgba(255,255,255,.06); }

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

    /* ── Footer links ── */
    .card-footer { text-align: center; margin-top: 1.4rem; font-size: clamp(.95rem,4vw,1.05rem); color: var(--muted); line-height: 1.75; }
    .card-footer a { color: var(--white); font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; gap: .4rem; padding: .2rem .5rem; }
    .card-footer a:hover { color: var(--red); }
    .card-footer .sep { margin: 0 .2rem; opacity: .4; }

    /* ── Responsive ── */
    @media (max-width: 480px) {
      body { padding: 1rem; padding-top: 4.25rem; padding-bottom: 1.25rem; justify-content: flex-start; }
      .card { padding: 1.5rem 1.25rem; border-radius: 18px; }
      .back-home span { display: none; }
      .back-home { padding: .55rem .8rem; }
    }

    /* ── Stagger animation ── */
    @keyframes itemIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .card > * { opacity:0; animation: itemIn .45s ease-out both; }
    .card > *:nth-child(1){ animation-delay:.08s; }
    .card > *:nth-child(2){ animation-delay:.14s; }
    .card > *:nth-child(3){ animation-delay:.18s; }
    .card > *:nth-child(4){ animation-delay:.22s; }
    .card > *:nth-child(5){ animation-delay:.26s; }
    .card > *:nth-child(6){ animation-delay:.30s; }
    @media (prefers-reduced-motion: reduce){ .card > *{ animation:none!important; opacity:1; } }
  </style>
<?php include __DIR__ . "/../includes/prelogin_polish.php"; ?>
</head>
<body>

<a href="<?= APP_URL ?>/login.php" class="back-home">
  <i class="fas fa-arrow-left"></i>
  <span>Κεντρικό Login</span>
</a>

<div class="card">

  <div class="logo">
    <a href="<?= APP_URL ?>/">
      <img src="<?= APP_URL ?>/assets/img/logo-tr.png" alt="MAster" class="logo-img">
    </a>
  </div>

  <div class="page-heading">Employee Panel</div>
  <div class="page-sub">Σύνδεση αποκλειστικά για λογαριασμούς employee.</div>

  <div class="emp-badge">
    <i class="fa-solid fa-id-badge"></i> Employee Access
  </div>

  <?php if ($error): ?>
  <div class="alert alert-error">
    <i class="fas fa-circle-exclamation"></i>
    <span><?= h($error) ?></span>
  </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

    <div class="form-group">
      <label for="email"><i class="fa-solid fa-envelope"></i> Email</label>
      <div class="input-wrap">
        <input
          type="email"
          id="email"
          name="email"
          placeholder="employee@example.com"
          value="<?= h($_POST['email'] ?? '') ?>"
          autocomplete="username"
          required
          autofocus
        >
      </div>
    </div>

    <div class="form-group">
      <label for="password"><i class="fa-solid fa-lock"></i> Κωδικός</label>
      <div class="input-wrap">
        <input
          type="password"
          id="password"
          name="password"
          placeholder="••••••••"
          autocomplete="current-password"
          required
        >
        <button type="button" class="eye-btn" onclick="togglePw()" aria-label="Εμφάνιση κωδικού">
          <i class="fa-solid fa-eye" id="eyeIcon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn-submit">
      <i class="fa-solid fa-right-to-bracket"></i> Είσοδος
    </button>
  </form>

  <div class="card-footer">
    <a href="<?= APP_URL ?>/login.php"><i class="fa-solid fa-users"></i> Κεντρικό login</a>
    <span class="sep">·</span>
    <a href="<?= APP_URL ?>/"><i class="fa-solid fa-house"></i> Αρχική</a>
  </div>

</div>

<script>
function togglePw() {
  var inp  = document.getElementById('password');
  var icon = document.getElementById('eyeIcon');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'fa-solid fa-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'fa-solid fa-eye';
  }
}
</script>
</body>
</html>