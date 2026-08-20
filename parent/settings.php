<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
requireParentLogin();

$db          = getDB();
$pid         = parentUserId();
$sid         = parentSchoolId();
$parentEmail = $_SESSION['parent_email'] ?? '';
$schoolName  = $_SESSION['school_name']  ?? 'MAster';

$success = '';
$error   = '';

$notifRow = $db->prepare("SELECT sms_opt_out, email_opt_out FROM parent_users WHERE id=? LIMIT 1");
$notifRow->execute([$pid]);
$notifData = $notifRow->fetch() ?: ['sms_opt_out' => 0, 'email_opt_out' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
    } catch (Throwable $e) {
        $error = 'Σφάλμα επαλήθευσης αιτήματος. Παρακαλώ προσπαθήστε ξανά.';
    }

    if (empty($error)) {
        $action = $_POST['_action'] ?? '';

        if ($action === 'change_password') {
            try {
                $current = $_POST['current_password'] ?? '';
                $new1    = $_POST['new_password']     ?? '';
                $new2    = $_POST['new_password2']    ?? '';

                $stmt = $db->prepare("SELECT password_hash FROM parent_users WHERE id=? LIMIT 1");
                $stmt->execute([$pid]);
                $row = $stmt->fetch();

                if (!$row || !password_verify($current, $row['password_hash'])) {
                    $error = 'Ο τρέχων κωδικός είναι λανθασμένος.';
                } elseif (strlen($new1) < 8) {
                    $error = 'Τουλάχιστον 8 χαρακτήρες.';
                } elseif ($new1 !== $new2) {
                    $error = 'Οι κωδικοί δεν ταιριάζουν.';
                } else {
                    changeParentPassword($new1);
                    $success = 'Ο κωδικός άλλαξε!';
                }
            } catch (Throwable $e) {
                error_log('[settings.php] change_password: ' . $e->getMessage());
                $error = 'Εσωτερικό σφάλμα κατά αλλαγή κωδικού.';
            }
        }

        if ($action === 'gdpr_request') {
            try {
                $requesterName   = trim($_POST['gdpr_name']   ?? '');
                $requesterReason = trim($_POST['gdpr_reason']  ?? '');
                $requestType     = trim($_POST['gdpr_type']    ?? 'export');

                if (empty($requesterName) || empty($requesterReason)) {
                    $error = 'Συμπληρώστε όλα τα πεδία.';
                } else {
                    $typeLabel = ($requestType === 'delete') ? 'Διαγραφή Δεδομένων' : 'Εξαγωγή Δεδομένων';
                    $subject   = "[MAster GDPR] $typeLabel — $parentEmail";
                    $html      = "<h2>GDPR Αίτημα</h2><p>Email: $parentEmail<br>Όνομα: " . htmlspecialchars($requesterName) . "<br>Σχολή: " . htmlspecialchars($schoolName) . "<br>Αιτιολογία: " . nl2br(htmlspecialchars($requesterReason)) . "</p>";
                    $text      = "GDPR Αίτημα\nEmail: $parentEmail\nΌνομα: $requesterName\nΣχολή: $schoolName\nΑιτιολογία: $requesterReason";

                    require_once __DIR__ . '/../includes/mailer.php';
                    $mailDebug = null;
                    $sent = sendEmail(
                        'pkotsorgios654@gmail.com',
                        $subject,
                        $html,
                        $text,
                        'Admin MAster',
                        $mailDebug,
                        null,
                        null,
                        $parentEmail,
                        $requesterName
                    );

                    if ($sent) {
                        $success = 'Το αίτημα υποβλήθηκε!';
                    } else {
                        error_log('[settings.php] GDPR sendEmail failed: ' . $mailDebug);
                        $error = 'Σφάλμα κατά την αποστολή. Παρακαλώ προσπαθήστε ξανά.';
                    }
                }
            } catch (Throwable $e) {
                error_log('[settings.php] gdpr_request: ' . $e->getMessage());
                $error = 'Εσωτερικό σφάλμα GDPR.';
            }
        }

        if ($action === 'save_notifications') {
            try {
                $smsOut   = !empty($_POST['sms_opt_out'])   ? 1 : 0;
                $emailOut = !empty($_POST['email_opt_out']) ? 1 : 0;
                $db->prepare("UPDATE parent_users SET sms_opt_out=?, email_opt_out=? WHERE id=?")
                   ->execute([$smsOut, $emailOut, $pid]);
                $_SESSION['parent_sms_opt_out']   = (bool)$smsOut;
                $_SESSION['parent_email_opt_out'] = (bool)$emailOut;
                $notifData = ['sms_opt_out' => $smsOut, 'email_opt_out' => $emailOut];
                $success = 'Οι προτιμήσεις ειδοποιήσεων αποθηκεύτηκαν.';
                try {
                    $rawIp  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $ipHash = hash('sha256', $rawIp . date('Y-m-d'));
                    if ($smsOut) {
                        $db->prepare("INSERT INTO consent_log (parent_user_id, event_type, ip_hash, terms_version) VALUES (?, 'sms_opt_out', ?, '1.0')")->execute([$pid, $ipHash]);
                    }
                    if ($emailOut) {
                        $db->prepare("INSERT INTO consent_log (parent_user_id, event_type, ip_hash, terms_version) VALUES (?, 'email_opt_out', ?, '1.0')")->execute([$pid, $ipHash]);
                    }
                } catch (\Throwable $e) { /* non-critical */ }
            } catch (Throwable $e) {
                error_log('[settings.php] save_notifications: ' . $e->getMessage());
                $error = 'Σφάλμα αποθήκευσης προτιμήσεων.';
            }
        }

        if ($action === 'delete_account') {
            try {
                $confirmEmail = trim(strtolower($_POST['confirm_email'] ?? ''));
                if ($confirmEmail !== strtolower($parentEmail)) {
                    $error = 'Το email δεν ταιριάζει.';
                } else {
                    $db->prepare("DELETE FROM parent_children WHERE parent_id=?")->execute([$pid]);
                    $db->prepare("DELETE FROM parent_users WHERE id=? AND school_id=?")->execute([$pid, $sid]);
                    session_unset();
                    session_destroy();
                    redirect(APP_URL . '/parent/login.php');
                    exit;
                }
            } catch (Throwable $e) {
                error_log('[settings.php] delete_account: ' . $e->getMessage());
                $error = 'Εσωτερικό σφάλμα διαγραφής.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title>Ρυθμίσεις — Portal Γονέων — MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
:root {
  --bg:    #07090f;
  --card:  #111520;
  --brd:   #1e2536;
  --red:   #e63946;
  --green: #2dc653;
  --text:  #f0f2ff;
  --muted: #b0bdd6;
  --inp:   #181e2e;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }
body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; font-size: 1rem; }

.pp-topbar {
  background: var(--card); border-bottom: 2px solid var(--brd);
  padding: 1rem 2rem; display: flex; align-items: center;
  justify-content: space-between; position: sticky; top: 0; z-index: 50;
  gap: 1rem;
}
.pp-logo {
  font-family: 'DM Sans', sans-serif;
  font-size: 1.8rem; letter-spacing: -.01em; color: var(--text);
  display: flex; align-items: baseline; gap: 0;
  text-decoration: none; flex-shrink: 0;
}
.pp-logo .logo-ma {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.8rem; letter-spacing: .03em; color: var(--text);
}
.pp-logo .logo-ster {
  font-family: 'DM Sans', sans-serif;
  font-size: 1.3rem; font-weight: 800; letter-spacing: .01em;
  color: var(--red); text-transform: lowercase;
}
.pp-nav { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.pp-nav a { font-size: 0.95rem; font-weight: 700; color: var(--muted); text-decoration: none; display: flex; align-items: center; gap: .4rem; padding: .5rem .7rem; border-radius: 10px; transition: all .2s; min-height: 44px; }
.pp-nav a:hover { color: var(--text); background: rgba(255,255,255,.06); }
.pp-nav a.active { color: var(--text); background: rgba(255,255,255,.08); }
.pp-nav a.nav-logout { color: var(--red); }
.pp-nav a.nav-logout:hover { background: rgba(230,57,70,.08); color: #ff6b76; }
.pp-nav a i { font-size: 1rem; }

.pp-body { max-width: 900px; width: 100%; margin: 0 auto; padding: 2rem 1.5rem; }

.page-hero { margin-bottom: 1.5rem; }
.page-hero-tag { font-size: .8rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--red); display: flex; align-items: center; gap: .4rem; margin-bottom: .5rem; }
.page-hero h1 { font-family: 'Bebas Neue', sans-serif; font-size: clamp(1.5rem, 5vw, 3rem); letter-spacing: .04em; line-height: .92; }
.page-hero h1 em { font-style: normal; color: var(--red); }

.pp-alert { padding: 0.9rem 1rem; border-radius: 12px; font-size: 0.9rem; font-weight: 600; margin-bottom: 1.25rem; display: flex; gap: .55rem; align-items: flex-start; line-height: 1.55; }
.pp-alert i { font-size: 1rem; flex-shrink: 0; margin-top: .05rem; }
.pp-alert.success { background: rgba(45,198,83,.1); border: 1.5px solid rgba(45,198,83,.35); color: #90f0aa; }
.pp-alert.error   { background: rgba(230,57,70,.1); border: 1.5px solid rgba(230,57,70,.35); color: #ffb3b8; }

.pp-settings-card {
  background: var(--card); border: 1px solid var(--brd);
  border-radius: 16px; overflow: hidden; margin-bottom: 1.25rem;
}
.pp-settings-header {
  padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--brd);
  display: flex; align-items: center; gap: .6rem;
}
.pp-settings-header-icon {
  width: 44px; height: 44px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem; flex-shrink: 0;
}
.pp-settings-header-icon.blue   { background: rgba(59,130,246,.12); color: #3b82f6; }
.pp-settings-header-icon.green  { background: rgba(45,198,83,.12);  color: var(--green); }
.pp-settings-header-icon.red    { background: rgba(230,57,70,.12);  color: var(--red); }
.pp-settings-header-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.2rem; letter-spacing: .05em; color: var(--text); }
.pp-settings-header-sub { font-size: 0.75rem; color: var(--muted); margin-top: .1rem; }
.pp-settings-body { padding: 1.5rem; }

.pp-form-group { margin-bottom: 1.2rem; }
.pp-form-group label { display: flex; align-items: center; gap: .4rem; font-size: 0.9rem; font-weight: 800; margin-bottom: .5rem; color: var(--text); }
.pp-form-group label i { color: var(--muted); font-size: .85rem; width: 16px; text-align: center; }
.pp-form-group input, .pp-form-group textarea {
  width: 100%; padding: .8rem 0.9rem;
  background: var(--inp); border: 2px solid var(--brd);
  border-radius: 10px; color: var(--text);
  font-size: 0.9rem; font-family: inherit;
  transition: border-color .2s, box-shadow .2s;
  -webkit-appearance: none;
}
.pp-form-group input:focus, .pp-form-group textarea:focus { outline: none; border-color: var(--red); box-shadow: 0 0 0 3px rgba(230,57,70,.12); }
.pp-form-group input::placeholder, .pp-form-group textarea::placeholder { color: #4a5270; }

.profile-info-row {
  display: flex; align-items: center; gap: 1rem;
  padding: 0.9rem 0; border-bottom: 1px solid var(--brd);
}
.profile-info-row:last-child { border-bottom: none; }
.profile-info-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); width: 140px; flex-shrink: 0; }
.profile-info-value { font-size: 0.95rem; font-weight: 700; color: var(--text); word-break: break-all; }

.pp-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .65rem 1.25rem; border-radius: 12px; font-size: 0.9rem; font-weight: 800; cursor: pointer; text-decoration: none; transition: all .2s; border: none; font-family: inherit; min-height: 44px; }
.pp-btn-primary { background: linear-gradient(135deg, var(--red), #b52a35); color: #fff; box-shadow: 0 0 20px rgba(230,57,70,.3); }
.pp-btn-primary:hover { background: linear-gradient(135deg, #b52a35, #8c1e27); box-shadow: 0 0 32px rgba(230,57,70,.5); transform: translateY(-1px); }
.pp-btn-danger { background: rgba(230,57,70,.15); color: var(--red); border: 2px solid rgba(230,57,70,.35); }
.pp-btn-danger:hover { background: rgba(230,57,70,.25); }

.danger-zone {
  border: 2px solid rgba(230,57,70,.25); border-radius: 14px;
  padding: 1.25rem; background: rgba(230,57,70,.04);
}
.danger-zone-title { font-size: 1rem; font-weight: 800; color: var(--red); margin-bottom: .4rem; display: flex; align-items: center; gap: .4rem; }
.danger-zone-desc { font-size: 0.85rem; color: var(--muted); margin-bottom: 1rem; line-height: 1.6; }

.pp-modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:9999; align-items:center; justify-content:center; padding:1rem; }
.pp-modal-bg.open { display:flex; }
.pp-modal { background:var(--card); border:1px solid rgba(230,57,70,.35); border-radius:18px; padding:2rem; width:100%; max-width:480px; box-shadow:0 30px 80px rgba(0,0,0,.6); }
.pp-modal h3 { font-size:1.2rem; font-weight:800; margin-bottom:0.8rem; display:flex; align-items:center; gap:.5rem; color:var(--red); }
.pp-modal p { font-size:0.9rem; color:var(--muted); margin-bottom:1.25rem; line-height:1.6; }
.pp-modal-footer { display:flex; gap:0.8rem; justify-content:flex-end; margin-top:1.25rem; flex-wrap: wrap; }

/* ── Bottom Tab Bar ── */
.pp-bottom-nav {
  display: none;
  position: fixed;
  bottom: 0; left: 0; right: 0;
  background: var(--card);
  border-top: 2px solid var(--brd);
  z-index: 100;
  padding-bottom: env(safe-area-inset-bottom, 0px);
}
.pp-bottom-nav-inner {
  display: flex;
  align-items: stretch;
}
.pp-bottom-nav a {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 10px 4px 10px;
  color: var(--muted);
  text-decoration: none;
  font-size: 0.6rem;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
  transition: color .2s;
  position: relative;
  min-height: 56px;
}
.pp-bottom-nav a i {
  font-size: 1.35rem;
  transition: color .2s, transform .2s, filter .2s;
}
.pp-bottom-nav a.active { color: var(--red); }
.pp-bottom-nav a.active::before {
  content: '';
  position: absolute;
  top: 0; left: 20%; right: 20%;
  height: 2px;
  background: var(--red);
  border-radius: 0 0 4px 4px;
}
.pp-bottom-nav a.active i {
  color: var(--red);
  filter: drop-shadow(0 0 6px rgba(230,57,70,.6));
  transform: translateY(-1px);
}
.pp-bottom-nav a.nav-logout { color: var(--red); opacity: .7; }
.pp-bottom-nav a.nav-logout:hover,
.pp-bottom-nav a.nav-logout:active { opacity: 1; }

@media (max-width: 768px) {
  .pp-nav { display: none; }
  .pp-bottom-nav { display: block; }
  .pp-body { padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)); }
  .pp-topbar { padding: .75rem 1rem; gap: 0.75rem; }
  .pp-body { padding-top: 1.5rem; padding-left: 1rem; padding-right: 1rem; }
  .profile-info-row { flex-direction: column; align-items: flex-start; gap: .25rem; }
  .profile-info-label { width: auto; }
}

@media (max-width: 480px) {
  .pp-topbar { padding: 0.65rem 0.75rem; }
  .pp-body { padding-top: 1rem; padding-left: 0.75rem; padding-right: 0.75rem; }
  .page-hero h1 { font-size: clamp(1.2rem, 4vw, 2rem); }
  .pp-settings-header { padding: 1rem 1.25rem; }
  .pp-settings-header-title { font-size: 1.05rem; }
  .pp-settings-header-icon { width: 40px; height: 40px; font-size: 1rem; }
  .pp-settings-body { padding: 1.25rem; }
  .pp-form-group label { font-size: 0.85rem; }
  .pp-form-group input, .pp-form-group textarea { padding: 0.7rem 0.8rem; font-size: 0.85rem; }
  .pp-btn { padding: 0.55rem 1rem; font-size: 0.8rem; }
  .pp-modal { padding: 1.5rem; }
  .pp-modal h3 { font-size: 1.05rem; }
  .pp-modal p { font-size: 0.8rem; }
}
</style>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/postlogin-portal-theme.css?v=<?= @filemtime(__DIR__ . "/../assets/css/postlogin-portal-theme.css") ?: time() ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;flex-direction:column">

  <header class="pp-topbar">
    <a href="index.php" class="pp-logo"><span class="logo-ma">MA</span><span class="logo-ster">ster</span></a>
    <nav class="pp-nav">
      <a href="index.php"><i class="fas fa-house"></i><span class="nav-label">Αρχική</span></a>
      <a href="children.php"><i class="fas fa-children"></i><span class="nav-label">Παιδιά</span></a>
      <a href="settings.php" class="active"><i class="fas fa-gear"></i><span class="nav-label">Ρυθμίσεις</span></a>
      <a href="<?= APP_URL ?>/logout.php" class="nav-logout"><i class="fas fa-right-from-bracket"></i><span class="nav-label">Έξοδος</span></a>
    </nav>
  </header>

  <main class="pp-body" style="flex:1">

    <div class="page-hero">
      <div class="page-hero-tag"><i class="fas fa-gear"></i> Ρυθμίσεις</div>
      <h1>ΡΥΘΜΙΣΕΙΣ<br><em>ΛΟΓΑΡΙΑΣΜΟΥ</em></h1>
    </div>

    <?php if ($success): ?>
    <div class="pp-alert success">
      <i class="fas fa-circle-check"></i>
      <span><?= htmlspecialchars($success) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="pp-alert error">
      <i class="fas fa-circle-exclamation"></i>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Profile Info -->
    <div class="pp-settings-card">
      <div class="pp-settings-header">
        <div class="pp-settings-header-icon green"><i class="fas fa-user"></i></div>
        <div>
          <div class="pp-settings-header-title">Στοιχεία Λογαριασμού</div>
          <div class="pp-settings-header-sub">Τα τρέχοντα στοιχεία σας</div>
        </div>
      </div>
      <div class="pp-settings-body">
        <div class="profile-info-row">
          <div class="profile-info-label">Email</div>
          <div class="profile-info-value"><?= htmlspecialchars($parentEmail) ?></div>
        </div>
        <div class="profile-info-row">
          <div class="profile-info-label">Σχολή</div>
          <div class="profile-info-value"><?= htmlspecialchars($schoolName) ?></div>
        </div>
      </div>
    </div>

    <!-- Change Password -->
    <div class="pp-settings-card">
      <div class="pp-settings-header">
        <div class="pp-settings-header-icon blue"><i class="fas fa-lock"></i></div>
        <div>
          <div class="pp-settings-header-title">Αλλαγή Κωδικού</div>
          <div class="pp-settings-header-sub">Αλλάξτε τον κωδικό πρόσβασής σας</div>
        </div>
      </div>
      <div class="pp-settings-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
          <input type="hidden" name="_action" value="change_password">

          <div class="pp-form-group">
            <label><i class="fas fa-lock"></i> Τρέχων Κωδικός</label>
            <input type="password" name="current_password" placeholder="••••••••••" required>
          </div>
          <div class="pp-form-group">
            <label><i class="fas fa-lock-open"></i> Νέος Κωδικός</label>
            <input type="password" name="new_password" placeholder="Τουλάχιστον 8 χαρακτήρες" required minlength="8">
          </div>
          <div class="pp-form-group">
            <label><i class="fas fa-lock-open"></i> Επαλήθευση</label>
            <input type="password" name="new_password2" placeholder="Επαναλάβετε τον νέο κωδικό" required minlength="8">
          </div>

          <button type="submit" class="pp-btn pp-btn-primary">
            <i class="fas fa-check"></i> Αποθήκευση
          </button>
        </form>
      </div>
    </div>

    <!-- GDPR -->
    <div class="pp-settings-card">
      <div class="pp-settings-header">
        <div class="pp-settings-header-icon" style="background:rgba(59,130,246,.15)"><i class="fas fa-shield-halved" style="color:#3b82f6"></i></div>
        <div>
          <div class="pp-settings-header-title">Τα Δεδομένα Μου</div>
          <div class="pp-settings-header-sub">GDPR - Άρθρα 15 & 20</div>
        </div>
      </div>
      <div class="pp-settings-body">
        <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;line-height:1.6">
          Μπορείτε να υποβάλετε αίτημα εξαγωγής ή διαγραφής των δεδομένων σας.
        </p>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <button type="button" onclick="openGdprModal('export')" class="pp-btn" style="background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);color:#3b82f6">
            <i class="fas fa-file-export"></i> Εξαγωγή
          </button>
          <button type="button" onclick="openGdprModal('delete')" class="pp-btn" style="background:rgba(230,57,70,.08);border:1px solid rgba(230,57,70,.25);color:var(--red)">
            <i class="fas fa-trash-can"></i> Διαγραφή
          </button>
        </div>
      </div>
    </div>

    <!-- Notifications Opt-out -->
    <div class="pp-settings-card">
      <div class="pp-settings-header">
        <div class="pp-settings-header-icon" style="background:rgba(240,165,0,.12)"><i class="fas fa-bell" style="color:#f0a500"></i></div>
        <div>
          <div class="pp-settings-header-title">Ειδοποιήσεις</div>
          <div class="pp-settings-header-sub">Διαχείριση SMS &amp; Email ειδοποιήσεων</div>
        </div>
      </div>
      <div class="pp-settings-body">
        <p style="font-size:.85rem;color:var(--muted);margin-bottom:1.2rem;line-height:1.6">
          Μπορείτε να σταματήσετε να λαμβάνετε ειδοποιήσεις πληρωμών. Εναλλακτικά, στείλτε <strong style="color:var(--text)">STOP</strong> μέσω email ή SMS στη σχολή σας.
        </p>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
          <input type="hidden" name="_action" value="save_notifications">
          <div style="display:flex;flex-direction:column;gap:.85rem;margin-bottom:1.25rem">
            <label style="display:flex;align-items:center;gap:.85rem;cursor:pointer;padding:.9rem 1rem;background:rgba(255,255,255,.03);border:1px solid var(--brd);border-radius:12px">
              <input type="checkbox" name="email_opt_out" value="1"
                <?= $notifData['email_opt_out'] ? 'checked' : '' ?>
                style="width:18px;height:18px;accent-color:var(--red);cursor:pointer;flex-shrink:0">
              <div>
                <div style="font-weight:700;color:var(--text);font-size:.9rem"><i class="fas fa-envelope" style="color:#3b82f6;margin-right:.35rem"></i>Διακοπή Email ειδοποιήσεων</div>
                <div style="font-size:.78rem;color:var(--muted);margin-top:.2rem">Δεν θα λαμβάνετε email για πληρωμές &amp; συνδρομές</div>
              </div>
            </label>
            <label style="display:flex;align-items:center;gap:.85rem;cursor:pointer;padding:.9rem 1rem;background:rgba(255,255,255,.03);border:1px solid var(--brd);border-radius:12px">
              <input type="checkbox" name="sms_opt_out" value="1"
                <?= $notifData['sms_opt_out'] ? 'checked' : '' ?>
                style="width:18px;height:18px;accent-color:var(--red);cursor:pointer;flex-shrink:0">
              <div>
                <div style="font-weight:700;color:var(--text);font-size:.9rem"><i class="fas fa-message-sms" style="color:#2dc653;margin-right:.35rem"></i>Διακοπή SMS ειδοποιήσεων</div>
                <div style="font-size:.78rem;color:var(--muted);margin-top:.2rem">Δεν θα λαμβάνετε SMS για πληρωμές &amp; συνδρομές</div>
              </div>
            </label>
          </div>
          <button type="submit" class="pp-btn pp-btn-primary">
            <i class="fas fa-floppy-disk"></i> Αποθήκευση
          </button>
        </form>
      </div>
    </div>

    <!-- Danger Zone -->
    <div class="pp-settings-card">
      <div class="pp-settings-header">
        <div class="pp-settings-header-icon red"><i class="fas fa-triangle-exclamation"></i></div>
        <div>
          <div class="pp-settings-header-title">Επικίνδυνες Ενέργειες</div>
          <div class="pp-settings-header-sub">Μη αναστρέψιμες ενέργειες</div>
        </div>
      </div>
      <div class="pp-settings-body">
        <div class="danger-zone">
          <div class="danger-zone-title">
            <i class="fas fa-user-xmark"></i> Διαγραφή Λογαριασμού
          </div>
          <div class="danger-zone-desc">
            Η διαγραφή είναι <strong style="color:#ffb3b8">μόνιμη</strong>. Θα χαθεί η πρόσβαση σας.
          </div>
          <button type="button" class="pp-btn pp-btn-danger" onclick="document.getElementById('deleteModal').classList.add('open')">
            <i class="fas fa-trash"></i> Διαγραφή
          </button>
        </div>
      </div>
    </div>

  </main>
</div>

<!-- Delete Modal -->
<div class="pp-modal-bg" id="deleteModal">
  <div class="pp-modal">
    <h3><i class="fas fa-triangle-exclamation"></i> Επιβεβαίωση</h3>
    <p>Επιβεβαιώστε την διαγραφή εισάγοντας το email σας:</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="_action" value="delete_account">
      <div class="pp-form-group">
        <input type="email" name="confirm_email" placeholder="Εισάγετε το email σας" required>
      </div>
      <div class="pp-modal-footer">
        <button type="button" class="pp-btn pp-btn-danger" onclick="document.getElementById('deleteModal').classList.remove('open')" style="background:rgba(255,255,255,.06);border-color:var(--brd);color:var(--muted)">
          Ακύρωση
        </button>
        <button type="submit" class="pp-btn pp-btn-danger">
          <i class="fas fa-trash"></i> Διαγραφή
        </button>
      </div>
    </form>
  </div>
</div>

<!-- GDPR Modal -->
<div class="pp-modal-bg" id="gdprModal">
  <div class="pp-modal">
    <h3 id="gdprModalTitle"><i class="fas fa-shield-halved" style="color:#3b82f6"></i> GDPR Αίτημα</h3>
    <p id="gdprModalDesc" style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;line-height:1.6"></p>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="gdpr_request">
      <input type="hidden" name="gdpr_type" id="gdprTypeInput" value="export">

      <div class="pp-form-group">
        <label><i class="fas fa-user"></i> Ονοματεπώνυμο *</label>
        <input type="text" name="gdpr_name" id="gdprName" placeholder="Εισάγετε το ονοματεπώνυμό σας" required>
      </div>

      <div class="pp-form-group">
        <label><i class="fas fa-comment-lines"></i> Αιτιολογία *</label>
        <textarea name="gdpr_reason" id="gdprReason" rows="3" placeholder="Περιγράψτε εν συντομία..." required></textarea>
      </div>

      <div class="pp-modal-footer">
        <button type="button" class="pp-btn" onclick="document.getElementById('gdprModal').classList.remove('open')"
          style="background:rgba(255,255,255,.06);border:1px solid var(--brd);color:var(--muted)">
          Ακύρωση
        </button>
        <button type="submit" class="pp-btn" id="gdprSubmitBtn" style="background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.4);color:#3b82f6">
          <i class="fas fa-paper-plane"></i> Υποβολή
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Bottom Tab Bar (mobile only) -->
<nav class="pp-bottom-nav">
  <div class="pp-bottom-nav-inner">
    <a href="index.php"><i class="fas fa-house"></i>Αρχική</a>
    <a href="children.php"><i class="fas fa-children"></i>Παιδιά</a>
    <a href="settings.php" class="active"><i class="fas fa-gear"></i>Ρυθμίσεις</a>
    <a href="<?= APP_URL ?>/logout.php" class="nav-logout"><i class="fas fa-right-from-bracket"></i>Έξοδος</a>
  </div>
</nav>

<script>
document.querySelectorAll('.pp-modal-bg').forEach(function(bg){
  bg.addEventListener('click', function(e){ if(e.target===bg) bg.classList.remove('open'); });
});

function openGdprModal(type) {
  var modal   = document.getElementById('gdprModal');
  var title   = document.getElementById('gdprModalTitle');
  var desc    = document.getElementById('gdprModalDesc');
  var typeInp = document.getElementById('gdprTypeInput');
  var btn     = document.getElementById('gdprSubmitBtn');

  document.getElementById('gdprName').value   = '';
  document.getElementById('gdprReason').value = '';

  if (type === 'delete') {
    title.innerHTML   = '<i class="fas fa-trash-can" style="color:#e63946"></i> Διαγραφή Δεδομένων';
    desc.textContent  = 'Υποβάλετε αίτημα διαγραφής των προσωπικών σας δεδομένων (ΓΚΠΔ Άρθρο 17).';
    btn.style.cssText = 'background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.35);color:#e63946';
  } else {
    title.innerHTML   = '<i class="fas fa-file-export" style="color:#3b82f6"></i> Εξαγωγή Δεδομένων';
    desc.textContent  = 'Υποβάλετε αίτημα για αντίγραφο των προσωπικών σας δεδομένων (ΓΚΠΔ Άρθρα 15 & 20).';
    btn.style.cssText = 'background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.4);color:#3b82f6';
  }

  typeInp.value = type;
  modal.classList.add('open');
}
</script>

</body>
</html>
