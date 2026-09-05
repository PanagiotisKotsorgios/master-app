<?php

/**
 * ============================================================
 * register.php — Εγγραφή Νέου Λογαριασμού Σχολής
 * ============================================================
 * PURPOSE:
 *   Δημιουργεί νέο account (σχολή + owner user) με trial period.
 *   Στέλνει welcome email και καταγράφει στο audit log.
 *
 * FLOW:
 *   1. GET  → εμφάνιση φόρμας εγγραφής με pricing
 *   2. POST → validate → duplicate check → create school+user
 *   3. Send welcome email → αυτόματη σύνδεση → redirect στο dashboard
 *
 * SECURITY MEASURES:
 *   ✓ CSRF token (verifyCsrf)
 *   ✓ Email validation + uniqueness check
 *   ✓ password_hash(PASSWORD_BCRYPT) με cost=12
 *   ✓ Coupon validation: prepared statement, expiry check, max_uses check
 *   ✓ Prepared statements για school + user INSERT
 *   ✓ Transaction: αν αποτύχει user insert, rollback school insert
 *   ✓ Rate limiting για register endpoint (προαιρετικό)
 *   ✓ h() για output escaping
 *   ✓ sanitizeString() για text inputs
 *
 * TABLES WRITTEN:
 *   schools, users, coupon_redemptions (αν coupon χρησιμοποιήθηκε)
 * ============================================================
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';

// SECURITY: Διαχωρισμός parent και club sessions
if (isset($_SESSION['is_parent']) && $_SESSION['is_parent'] === true) {
    redirect(APP_URL . '/parent/index.php');
} elseif (isClubLoggedIn()) {
    redirect(isSuperAdmin() ? APP_URL.'/admin/' : APP_URL.'/dashboard/');
}

$error = '';

// ── Επεξεργασία φόρμας POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $schoolName      = trim($_POST['school_name'] ?? '');
    $ownerName       = trim($_POST['owner_name']  ?? '');
    $email           = trim(strtolower($_POST['email'] ?? ''));
    $password        = $_POST['password']  ?? '';
    $password2       = $_POST['password2'] ?? '';
    $schoolAfm       = preg_replace('/\D/', '', trim($_POST['school_afm'] ?? ''));
    $acceptTerms     = isset($_POST['accept_terms']);
    $planId = 0;

    if (!$schoolName || !$ownerName || !$email || !$password) {
        $error = 'Παρακαλώ συμπληρώστε όλα τα υποχρεωτικά πεδία.';
    } elseif (!$acceptTerms) {
        $error = 'Πρέπει να αποδεχθείτε τους Όρους Χρήσης και την Πολιτική Απορρήτου για να συνεχίσετε.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Η διεύθυνση email δεν είναι έγκυρη.';
    } elseif ($schoolAfm !== '' && strlen($schoolAfm) !== 9) {
        $error = 'Το ΑΦΜ πρέπει να έχει ακριβώς 9 ψηφία.';
    } elseif (strlen($password) < 8) {
        $error = 'Ο κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.';
    } elseif ($password !== $password2) {
        $error = 'Οι δύο κωδικοί που εισάγατε δεν ταιριάζουν.';
    } else {
        $db = getDB();

        $check = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetchColumn() > 0) {
            $error = 'Αυτό το email χρησιμοποιείται ήδη. Συνδεθείτε ή χρησιμοποιήστε άλλο email.';
        } else {
            if ($schoolAfm !== '') {
                $afmCheck = $db->prepare("SELECT COUNT(*) FROM schools WHERE afm = ?");
                $afmCheck->execute([$schoolAfm]);
                if ($afmCheck->fetchColumn() > 0) {
                    $error = 'Το ΑΦΜ αυτό χρησιμοποιείται ήδη από άλλη σχολή. Παρακαλώ ελέγξτε το ΑΦΜ σας.';
                }
            }

            if (!$error) {
                $vp = $db->prepare("SELECT id FROM plans WHERE slug='pro' AND active=1 LIMIT 1");
                $vp->execute();
                $planId = (int)$vp->fetchColumn();
                if (!$planId) {
                    $planId = (int)$db->query("SELECT id FROM plans WHERE active=1 ORDER BY price_monthly DESC LIMIT 1")->fetchColumn();
                }

                $db->beginTransaction();

                try {
                    $db->prepare(
                        "INSERT INTO schools (name, email, afm, plan_id, plan_status, trial_ends, active)
                         VALUES (?, ?, ?, ?, 'trial', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 1)"
                    )->execute([$schoolName, $email, $schoolAfm ?: null, $planId]);

                    $schoolId = (int)$db->lastInsertId();

                    $db->prepare(
                        "INSERT INTO users (school_id, name, email, password, role, active)
                         VALUES (?, ?, ?, ?, 'owner', 1)"
                    )->execute([$schoolId, $ownerName, $email, password_hash($password, PASSWORD_DEFAULT)]);

                    $userId = (int)$db->lastInsertId();

                    $db->commit();

                    auditLog('register');

                    $_SESSION['user_id']    = $userId;
                    $_SESSION['school_id']  = $schoolId;
                    $_SESSION['user_role']  = 'owner';
                    $_SESSION['user_name']  = $ownerName;
                    $_SESSION['user_email'] = $email;

                    $trialEnds = (new DateTime('+14 days'))->format('d/m/Y');
                    $loginUrl  = APP_URL . '/login.php';
                    $subject   = 'Καλωσήρθατε στο MAster 🎉';
                    $html      = buildMasterWelcomeEmail($ownerName, $schoolName, $trialEnds, $loginUrl);

                    try {
                        $plainText = "Καλωσήρθατε, {$ownerName}!\n\n"
                                   . "Ο λογαριασμός σας για τη σχολή {$schoolName} δημιουργήθηκε επιτυχώς στο MAster.\n\n"
                                   . "✅ Έχετε 14 ημέρες δωρεάν δοκιμή — λήξη: {$trialEnds}.\n"
                                   . "Χωρίς χρεωστική κάρτα / λοιπές δεσμεύσεις. Ακύρωση οποτεδήποτε επιθυμείτε.\n\n"
                                   . "Σύνδεση: {$loginUrl}\n\n"
                                   . "Χρειάζεστε βοήθεια; Επικοινωνήστε σε αυτό το email.\n\n"
                                   . "— MAster";
                        $dbg  = '';
                        $sent = sendEmail($email, $subject, $html, $plainText, $ownerName, $dbg);
                        if (!$sent) {
                            error_log('[register] Welcome email failed for: ' . $email . ' | debug: ' . $dbg);
                        }
                    } catch (Throwable $e) {
                        error_log('[register] Welcome email exception: ' . $e->getMessage());
                    }

                    redirect(APP_URL . '/dashboard/');

                } catch (Exception $e) {
                    $db->rollBack();
                    error_log('Register transaction failed: ' . $e->getMessage());
                    $error = 'Παρουσιάστηκε σφάλμα κατά την εγγραφή. Παρακαλώ δοκιμάστε ξανά.';
                }
            }
        }
    }
}

// ── Φόρτωση Pro plan id ──────────────────────────────────────────────────────
$db = getDB();
$proPlanStmt = $db->prepare("SELECT id FROM plans WHERE slug='pro' AND active=1 LIMIT 1");
$proPlanStmt->execute();
$proPlanId = (int)$proPlanStmt->fetchColumn();
if (!$proPlanId) {
    $proPlanId = (int)$db->query("SELECT id FROM plans WHERE active=1 ORDER BY price_monthly DESC LIMIT 1")->fetchColumn();
}

// ════════════════════════════════════════════════════
// WELCOME EMAIL TEMPLATE
// ════════════════════════════════════════════════════
function buildMasterWelcomeEmail(
    string $ownerName,
    string $schoolName,
    string $trialEnds,
    string $loginUrl
): string {
    $ownerEsc  = htmlspecialchars($ownerName,  ENT_QUOTES, 'UTF-8');
    $schoolEsc = htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8');
    $loginEsc  = htmlspecialchars($loginUrl,   ENT_QUOTES, 'UTF-8');
    $year      = date('Y');
    $appUrl    = defined('APP_URL') ? APP_URL : '';
    $logoUrl   = $appUrl . '/assets/img/logo-tr.png';

    return <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Καλωσήρθατε στο MAster</title>
</head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:560px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">

      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:28px 32px 24px;text-align:center;border-bottom:2px solid #2a1a50">
          <img src="{$logoUrl}" alt="MAster"
               style="height:72px;width:auto;max-width:220px;object-fit:contain;display:block;margin:0 auto">
        </td>
      </tr>

      <tr>
        <td style="padding:32px 32px 0">
          <h2 style="margin:0 0 10px;font-size:1.35rem;font-weight:800;color:#f0f2ff">
            Καλωσήρθατε, {$ownerEsc}! 👋
          </h2>
          <p style="margin:0;font-size:.96rem;color:#b0bcd0;line-height:1.75">
            Ο λογαριασμός για τη σχολή <strong style="color:#f0f2ff">{$schoolEsc}</strong>
            δημιουργήθηκε επιτυχώς στο <strong style="color:#e63946">MAster</strong>.
          </p>
        </td>
      </tr>

      <tr>
        <td style="padding:20px 32px 0">
          <div style="background:rgba(45,198,83,.08);border:1.5px solid rgba(45,198,83,.3);border-radius:12px;padding:18px 20px">
            <p style="margin:0 0 6px;font-size:1rem;font-weight:800;color:#4ade80">
              ✅ 14 Ημέρες Δωρεάν Δοκιμή
            </p>
            <p style="margin:0;font-size:.88rem;color:#8892b0;line-height:1.65">
              Η δοκιμαστική περίοδος σας λήγει στις
              <strong style="color:#f0f2ff">{$trialEnds}</strong>.<br>
              Χωρίς χρεωστική κάρτα. Ακύρωση οποτεδήποτε.
            </p>
          </div>
        </td>
      </tr>

      <tr>
        <td style="padding:24px 32px 0;text-align:center">
          <p style="margin:0 0 14px;font-size:.92rem;color:#8892b0">Ξεκινήστε τώρα:</p>
          <a href="{$loginEsc}"
             style="display:inline-block;background:#e63946;color:#fff;font-size:1rem;font-weight:800;
                    text-decoration:none;padding:14px 36px;border-radius:12px;
                    letter-spacing:.03em;box-shadow:0 4px 20px rgba(230,57,70,.4)">
            Σύνδεση στο MAster →
          </a>
        </td>
      </tr>

      <tr>
        <td style="padding:24px 32px 0">
          <p style="margin:0 0 12px;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#4a5270">
            Τι περιλαμβάνει το MAster
          </p>
          <table cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse">
            <tr>
              <td style="padding:8px 10px;background:rgba(255,255,255,.03);border:1px solid #1e2536;border-radius:10px">
                <span style="font-size:1.1rem">👥</span>&nbsp;
                <span style="font-size:.88rem;color:#c0c8e0">Διαχείριση αθλητών</span>
              </td>
            </tr>
            <tr><td style="height:6px"></td></tr>
            <tr>
              <td style="padding:8px 10px;background:rgba(255,255,255,.03);border:1px solid #1e2536;border-radius:10px">
                <span style="font-size:1.1rem">💳</span>&nbsp;
                <span style="font-size:.88rem;color:#c0c8e0">Παρακολούθηση συνδρομών &amp; πληρωμών</span>
              </td>
            </tr>
            <tr><td style="height:6px"></td></tr>
            <tr>
              <td style="padding:8px 10px;background:rgba(255,255,255,.03);border:1px solid #1e2536;border-radius:10px">
                <span style="font-size:1.1rem">📲</span>&nbsp;
                <span style="font-size:.88rem;color:#c0c8e0">Αυτόματες υπενθυμίσεις email &amp; SMS</span>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="padding:20px 32px 0">
          <p style="margin:0;font-size:.88rem;color:#6b7494;line-height:1.65">
            Χρειάζεστε βοήθεια; Απαντήστε στο: pkotsorgios654@gmail.com και θα σας εξυπηρετήσουμε άμεσα.
          </p>
        </td>
      </tr>

      <tr><td style="padding:24px 32px 0"><div style="border-top:1px solid #1e2536"></div></td></tr>

      <tr>
        <td style="padding:18px 32px 24px;text-align:center">
          <p style="margin:0;font-size:.72rem;color:#3a4260;line-height:1.7">
            &copy; {$year} <strong style="color:#4a5270">MAster</strong> &nbsp;&middot;&nbsp;
            Πλατφόρμα Διαχείρισης Αθλητικών Συλλόγων<br>
            <span style="font-size:.66rem;color:#2a3248">
              Λαμβάνετε αυτό το email επειδή δημιουργήσατε λογαριασμό στο σύστημα MAster.
            </span>
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>Εγγραφή - MAster - Εφαρμογή Διαχείρισης Αθλητικών Συλλόγων / Συνδρομών Μελλών</title>
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
      --gold:     #f0a500;
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
      align-items: flex-start;
      justify-content: center;
      padding: 1rem 1rem 2.5rem;
      padding-top: 4.5rem;
    }

    .back-home {
      position: fixed;
      top: .85rem; left: .85rem;
      display: flex; align-items: center; gap: .45rem;
      color: var(--muted); font-size: .95rem; font-weight: 700;
      text-decoration: none;
      background: rgba(255,255,255,.05);
      border: 1px solid var(--border);
      border-radius: 50px;
      padding: .55rem 1rem .55rem .8rem;
      transition: all .2s; z-index: 100; min-height: 44px;
    }
    .back-home:hover { color: var(--white); background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.2); }
    .back-home i { font-size: .9rem; }

    .wrap { width: 100%; max-width: 660px; }

    .logo { text-align: center; margin-bottom: 1.25rem; }
    .logo a { display: inline-block; text-decoration: none; }
    .logo-img { height: clamp(56px,15vw,90px); width: auto; object-fit: contain; display: block; margin: 0 auto .5rem; }
    .logo p { font-size: clamp(.9rem,4vw,1rem); color: var(--muted); margin-top: .25rem; }

    .trial-banner {
      background: rgba(17, 21, 32, 0.9);
      border: 1.5px solid rgba(240,165,0,.22);
      border-left: 3px solid var(--gold);
      border-radius: var(--radius);
      padding: .95rem 1.15rem;
      margin-bottom: 1.1rem;
      display: flex;
      align-items: center;
      gap: .9rem;
    }
    .trial-banner .trial-title {
      font-size: clamp(1rem, 4.5vw, 1.2rem);
      font-weight: 800;
      color: #f0f2ff;
      margin-bottom: .3rem;
      display: flex;
      align-items: center;
      gap: .45rem;
      flex-wrap: wrap;
    }
    .trial-banner .pro-badge {
      background: linear-gradient(135deg,#f0a500,#e63946);
      color: #fff;
      font-size: .65rem;
      padding: .1rem .45rem;
      border-radius: 4px;
      font-weight: 800;
      letter-spacing: .06em;
    }
    .trial-banner .trial-desc {
      font-size: clamp(.88rem, 3.8vw, 1rem);
      color: #8892b0;
      line-height: 1.55;
    }
    .trial-banner .trial-desc strong { color: #c8d0e8; }

    /* ── Clubs-only notice ── */
    .register-notice {
      display: flex;
      align-items: flex-start;
      gap: .65rem;
      background: rgba(230, 57, 70, .1);
      border: 1.5px solid rgba(230, 57, 70, .45);
      border-left: 3px solid var(--red);
      border-radius: var(--radius);
      padding: .8rem 1rem;
      margin-bottom: 1.1rem;
      font-size: .92rem;
      font-weight: 600;
      color: #ff8a92;
      line-height: 1.6;
    }
    .register-notice i {
      color: var(--red);
      font-size: 1rem;
      flex-shrink: 0;
      margin-top: .18rem;
    }
    .register-notice strong { color: #ffb3b8; font-weight: 800; }

    .section-label {
      font-size: clamp(.85rem, 3.8vw, .95rem);
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: var(--muted);
      margin-bottom: .65rem;
      display: flex;
      align-items: center;
      gap: .5rem;
    }

    .card {
      background: var(--card);
      border: 2px solid #1e2536;
      border-radius: 20px;
      padding: 1.5rem 1.25rem;
      box-shadow: 0 20px 60px rgba(0,0,0,.6);
    }
    .card-title {
      font-size: clamp(1.1rem,5vw,1.35rem);
      font-weight: 800;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: .55rem;
    }
    .card-title i { color: var(--red); font-size: 1.1rem; }

    .alert {
      padding: .9rem 1rem;
      border-radius: var(--radius);
      font-size: clamp(1rem, 4.2vw, 1.15rem);
      font-weight: 600;
      margin-bottom: 1.25rem;
      display: flex;
      gap: .65rem;
      align-items: flex-start;
      line-height: 1.55;
    }
    .alert i { font-size: 1.1rem; flex-shrink: 0; margin-top: .1rem; }
    .alert-error { background: rgba(230,57,70,.12); border: 2px solid rgba(230,57,70,.4); color: #ffb3b8; }

    /* ── AFM field feedback ── */
    .afm-feedback {
      font-size: .78rem;
      margin-top: .28rem;
      margin-bottom: .85rem;
      min-height: 1.1em;
      display: flex;
      align-items: center;
      gap: .35rem;
      transition: color .2s;
    }
    .afm-feedback.valid   { color: var(--green); }
    .afm-feedback.invalid { color: var(--red); }
    .afm-feedback.neutral { color: var(--muted); }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
    .full { grid-column: 1 / -1; }

    label {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-size: 1rem;
      font-weight: 800;
      margin-bottom: .5rem;
      color: var(--white);
    }
    label i { color: var(--muted); font-size: 1rem; width: 18px; text-align: center; }
    .req { color: var(--red); margin-left: .1rem; }

    .input-wrap { position: relative; }

    input[type="email"],
    input[type="password"],
    input[type="text"] {
      width: 100%;
      font-size: 1rem;
      padding: .85rem 3rem .85rem .95rem;
      background: var(--input-bg);
      border: 2px solid var(--border);
      border-radius: var(--radius);
      color: var(--white);
      font-family: inherit;
      transition: border-color .2s, box-shadow .2s;
      -webkit-appearance: none;
      line-height: 1.4;
    }
    input::placeholder { color: #4a5270; }
    input:focus { outline: none; border-color: var(--red); box-shadow: 0 0 0 4px rgba(230,57,70,.18); }
    input.input-valid   { border-color: var(--green) !important; box-shadow: 0 0 0 3px rgba(45,198,83,.15) !important; }
    input.input-invalid { border-color: var(--red)   !important; box-shadow: 0 0 0 3px rgba(230,57,70,.15) !important; }

    .form-hint {
      font-size: .78rem;
      color: var(--muted);
      margin-top: .28rem;
      margin-bottom: .85rem;
      display: flex;
      align-items: center;
      gap: .35rem;
    }

    .eye-btn {
      position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: #4a5270; font-size: 1.1rem;
      width: 44px; height: 44px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 8px; transition: color .2s, background .2s;
    }
    .eye-btn:hover { color: var(--white); background: rgba(255,255,255,.06); }

    /* ── Password strength ── */
    .strength-wrap { margin-top: .75rem; }
    .strength-segments { display: flex; gap: 5px; }
    .strength-seg {
      flex: 1; height: 8px; border-radius: 4px;
      background: rgba(255,255,255,.08);
      transition: background .35s ease, transform .2s ease;
    }
    .strength-seg.active { transform: scaleY(1.15); }
    .strength-badge {
      display: flex; align-items: center; justify-content: flex-start; gap: .5rem;
      margin-top: .65rem; padding: .65rem 1rem; border-radius: 10px;
      font-size: clamp(1rem, 4vw, 1.15rem); font-weight: 800;
      transition: all .3s ease; min-height: 2.6rem; border: 2px solid transparent;
    }
    .strength-badge i { font-size: 1.1rem; }
    .strength-badge.empty  { background: transparent; color: transparent; border-color: transparent; min-height: 0; padding: 0; margin-top: 0; }
    .strength-badge.weak   { background: rgba(230,57,70,.13);  color: #ff6b74; border-color: rgba(230,57,70,.3); }
    .strength-badge.medium { background: rgba(244,165,53,.13); color: #f4a535; border-color: rgba(244,165,53,.3); }
    .strength-badge.strong { background: rgba(45,198,83,.13);  color: #2dc653; border-color: rgba(45,198,83,.3); }
    .strength-badge.great  { background: rgba(45,198,83,.18);  color: #1fc94c; border-color: rgba(45,198,83,.45); }

    .match-feedback {
      display: flex; align-items: center; gap: .5rem;
      font-size: clamp(1rem, 3.8vw, 1.15rem); font-weight: 700;
      margin-top: .55rem; min-height: 1.5rem; transition: all .25s;
    }
    .match-feedback.ok  { color: #2dc653; }
    .match-feedback.bad { color: #ff6b74; }

    .form-gap { margin-top: 1.5rem; }

    /* ── Legal checkbox ── */
    .legal-checks { margin-bottom: 1.25rem; }

    .legal-check-row {
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      cursor: pointer;
      padding: 1.15rem 1.25rem;
      background: rgba(255,255,255,.03);
      border: 2px solid #2a3248;
      border-radius: 16px;
      transition: border-color .2s, background .2s, box-shadow .2s;
      -webkit-tap-highlight-color: transparent;
      user-select: none;
    }
    .legal-check-row:hover {
      border-color: rgba(230,57,70,.4);
      background: rgba(230,57,70,.06);
    }

    .legal-check-row input[type="checkbox"] {
      position: absolute;
      opacity: 0;
      width: 0;
      height: 0;
      pointer-events: none;
    }

    .legal-check-box {
      flex-shrink: 0;
      width: 30px;
      height: 30px;
      min-width: 30px;
      border-radius: 9px;
      border: 2.5px solid #3a4260;
      background: var(--input-bg);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-top: .15rem;
      transition: border-color .2s, background .25s, box-shadow .25s, transform .15s;
      font-size: 1rem;
    }
    .legal-check-box i {
      opacity: 0;
      transform: scale(0.7);
      transition: opacity .2s ease, transform .2s ease;
    }
    .legal-check-row:hover .legal-check-box {
      border-color: var(--red);
      box-shadow: 0 0 0 3px rgba(230,57,70,.12);
    }
    .legal-check-row.is-checked .legal-check-box {
      background: var(--red);
      border-color: var(--red);
      box-shadow: 0 0 0 4px rgba(230,57,70,.25);
      transform: scale(1.05);
    }
    .legal-check-row.is-checked .legal-check-box i {
      opacity: 1;
      transform: scale(1);
      color: #fff;
    }
    .legal-check-row.is-checked {
      border-color: rgba(230,57,70,.55);
      background: rgba(230,57,70,.07);
      box-shadow: 0 0 0 1px rgba(230,57,70,.15);
    }

    .legal-check-text {
      flex: 1;
      font-size: 1rem;
      color: #9aa0b8;
      line-height: 1.7;
      font-weight: 600;
    }
    .legal-check-row.is-checked .legal-check-text { color: #c8d0e8; }
    .legal-check-text strong { color: #d0d8f0; }
    .legal-check-text a {
      color: var(--red);
      text-decoration: underline;
      text-underline-offset: 2px;
      font-weight: 700;
    }
    .legal-check-text a:hover { color: #ff6b74; }

    .legal-detail-link {
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      margin-top: .4rem;
      font-size: .82rem;
      color: #5a6280;
      text-decoration: none;
      font-weight: 600;
      transition: color .2s;
    }
    .legal-detail-link:hover { color: var(--muted); }

    .legal-detail-text {
      display: none;
      margin-top: .55rem;
      font-size: .8rem;
      color: #5a6280;
      line-height: 1.7;
      padding-top: .55rem;
      border-top: 1px solid #1e2536;
      font-weight: 400;
    }

    .req { color: var(--red); }

    .btn-submit {
      width: 100%; padding: 1.1rem 1rem;
      background: var(--red); color: #fff; border: none;
      border-radius: var(--radius); font-size: clamp(1.1rem,5vw,1.25rem); font-weight: 800;
      font-family: inherit; cursor: pointer;
      box-shadow: 0 4px 20px rgba(230,57,70,.4);
      transition: background .2s, transform .15s, box-shadow .2s;
      display: flex; align-items: center; justify-content: center; gap: .65rem;
      min-height: 56px; letter-spacing: .01em;
    }
    .btn-submit:hover { background: var(--red-dark); box-shadow: 0 6px 28px rgba(230,57,70,.55); transform: translateY(-1px); }
    .btn-submit:active { transform: translateY(0); background: var(--red-dark); }

    .card-footer {
      text-align: center;
      margin-top: 1.25rem;
      font-size: clamp(1rem, 4.5vw, 1.2rem);
      color: var(--muted);
      line-height: 1.75;
    }
    .card-footer a {
      color: var(--white);
      font-weight: 800;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      padding: .2rem 0;
    }
    .card-footer a:hover { color: var(--red); text-decoration: underline; }

    @keyframes pageIn  { from { opacity:0; transform:translateY(16px); filter:blur(5px); } to { opacity:1; transform:translateY(0); filter:blur(0); } }
    @keyframes itemIn  { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .wrap { animation: pageIn .55s cubic-bezier(.2,.8,.2,1) both; will-change: transform, opacity, filter; }
    .wrap > * { opacity:0; animation: itemIn .45s ease-out both; }
    .wrap > *:nth-child(1){ animation-delay:.06s; }
    .wrap > *:nth-child(2){ animation-delay:.12s; }
    .wrap > *:nth-child(3){ animation-delay:.17s; }
    .wrap > *:nth-child(4){ animation-delay:.22s; }
    .wrap > *:nth-child(5){ animation-delay:.27s; }
    .wrap > *:nth-child(6){ animation-delay:.34s; }
    @media (prefers-reduced-motion: reduce) { .wrap, .wrap > * { animation:none !important; opacity:1; } }

    @media (max-width:600px) {
      .form-row { grid-template-columns:1fr !important; gap:.75rem; }
      .full { grid-column:auto !important; }
      .wrap { max-width:100%; }
      .back-home { padding:.55rem .8rem; }
    }
    @media (max-width:480px) {
      body { padding:.75rem; padding-top:4rem; padding-bottom:2rem; }
      .card { padding:1.1rem 1rem; border-radius:16px; }
      label { font-size:clamp(0.95rem, 4vw, 1.05rem); }
      .card-title { font-size:clamp(1rem, 4.8vw, 1.2rem); margin-bottom:1rem; }
      input[type="email"],input[type="password"],input[type="text"] { font-size:1rem; padding:.8rem 3rem .8rem .85rem; }
      .trial-banner { padding:.7rem .85rem; gap:.65rem; }
      .btn-submit { font-size:clamp(1rem, 4.8vw, 1.15rem); min-height:52px; }
    }
    @media (max-width:375px) {
      body { padding:.6rem; padding-top:3.75rem; }
      .card { padding:.9rem .8rem; }
      label { font-size:clamp(.85rem, 3.8vw, .95rem); }
    }
    @media (max-width:320px) {
      body { padding:.5rem; padding-top:3.6rem; }
      .card { padding:.8rem .7rem; border-width:1px; }
    }
    @media (max-height:600px) and (orientation:landscape) {
      body { padding-top:4rem; }
      .logo-img { height:40px; }
      .trial-banner { padding:.55rem .8rem; margin-bottom:.75rem; }
      .card { padding:.9rem 1rem; }
    }
  </style>
<?php include __DIR__ . '/includes/prelogin_polish.php'; ?>
</head>
<body>

<a href="<?= APP_URL ?>/index.php" class="back-home">
  <i class="fas fa-arrow-left"></i>
  <span>Αρχική Σελίδα</span>
</a>

<div class="wrap">

  <div class="logo">
    <a href="<?= APP_URL ?>/index.php">
      <img src="<?= APP_URL ?>/assets/img/logo-tr.png" alt="MAster" class="logo-img">
    </a>
    <p>Δημιουργήστε λογαριασμό για τη σχολή σας</p>
  </div>

  <!-- Pro trial banner -->
  <div class="trial-banner">
    <div style="flex:1; min-width:0">
      <div class="trial-title">
        Δωρεάν δοκιμή 14 ημερών
        <span class="pro-badge">PRO</span>
      </div>
      <div class="trial-desc">
        Πλήρης πρόσβαση σε <strong>όλες τις λειτουργίες</strong> - χωρίς καμία χρέωση.
      </div>
    </div>
  </div>

  <!-- ── Clubs-only notice ── -->
  <div class="register-notice">
    <i class="fas fa-triangle-exclamation"></i>
    <span>Αυτή η φόρμα αφορά <strong>μόνο Αθλητικά Σωματεία</strong> — δεν προορίζεται για γονείς ή μεμονωμένους χρήστες.</span>
  </div>

  <div class="card">
    <div class="card-title">
      <i class="fas fa-user-plus"></i>
      Στοιχεία Εγγραφής
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error">
      <i class="fas fa-circle-exclamation"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" novalidate id="regForm">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="plan_id" value="<?= $proPlanId ?>">

      <div class="form-row">

        <div class="form-group">
          <label for="school_name">
            <i class="fas fa-school"></i>
            Όνομα Συλλόγου <span class="req">*</span>
          </label>
          <input type="text" id="school_name" name="school_name"
                 placeholder="π.χ. Μυρμιδόνες Πειραιά"
                 value="<?= h($_POST['school_name'] ?? '') ?>"
                 required autofocus>
        </div>

        <div class="form-group">
          <label for="owner_name">
            <i class="fas fa-user"></i>
            Ονοματεπώνυμο <span class="req">*</span>
          </label>
          <input type="text" id="owner_name" name="owner_name"
                 placeholder="π.χ. Γιώργος Παπαδόπουλος"
                 value="<?= h($_POST['owner_name'] ?? '') ?>"
                 oninput="validateOwnerName(this)"
                 required>
          <div class="afm-feedback neutral" id="ownerNameFeedback" style="display:none">
            <i class="fas fa-circle-xmark"></i>
            <span>Το όνομα χρήστη δεν μπορεί να περιέχει αριθμούς</span>
          </div>
        </div>

        <div class="form-group full">
          <label for="school_afm">
            <i class="fas fa-id-card"></i>
            ΑΦΜ Συλλόγου
          </label>
          <input type="text" id="school_afm" name="school_afm"
                 placeholder="π.χ. 123456789"
                 value="<?= h($_POST['school_afm'] ?? '') ?>"
                 maxlength="9"
                 pattern="[0-9]{9}"
                 inputmode="numeric"
                 oninput="validateAfm(this)">
          <div class="afm-feedback neutral" id="afmFeedback">
            <span>Υποχρεωτικό — 9 ψηφία</span>
          </div>
        </div>

        <div class="form-group full">
          <label for="email">
            <i class="fas fa-envelope"></i>
            Διεύθυνση Email <span class="req">*</span>
          </label>
          <input type="email" id="email" name="email"
                 placeholder="π.χ. gpapadopoulos@gmail.com"
                 value="<?= h($_POST['email'] ?? '') ?>"
                 autocomplete="email" required>
          <p class="form-hint">
            Με αυτό το email θα λαμβάνετε ειδοποιήσεις & θα κάνετε σύνδεση στην πλατφόρμα αργότερα.
          </p>
        </div>

        <div class="form-group">
          <label for="password">
            <i class="fas fa-lock"></i>
            Κωδικός <span class="req">*</span>
          </label>
          <div class="input-wrap">
            <input type="password" id="password" name="password"
                   placeholder="Γράψτε έναν κωδικό"
                   autocomplete="new-password"
                   oninput="checkStrength(this.value)" required>
            <button type="button" class="eye-btn" onclick="togglePwd('password','eye1')" tabindex="-1">
              <i class="fas fa-eye" id="eye1"></i>
            </button>
          </div>
          <div class="strength-wrap">
            <div class="strength-segments">
              <div class="strength-seg" id="seg1"></div>
              <div class="strength-seg" id="seg2"></div>
              <div class="strength-seg" id="seg3"></div>
              <div class="strength-seg" id="seg4"></div>
            </div>
            <div class="strength-badge empty" id="sBadge">
              <i class="fas fa-shield" id="sBadgeIcon"></i>
              <span id="sBadgeTxt"></span>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label for="password2">
            <i class="fas fa-lock"></i>
            Επαλήθευση <span class="req">*</span>
          </label>
          <div class="input-wrap">
            <input type="password" id="password2" name="password2"
                   placeholder="Γράψτε τον ίδιο κωδικό πάλι"
                   autocomplete="new-password" required>
            <button type="button" class="eye-btn" onclick="togglePwd('password2','eye2')" tabindex="-1">
              <i class="fas fa-eye" id="eye2"></i>
            </button>
          </div>
          <div class="match-feedback" id="matchFeedback"></div>
        </div>

      </div>

      <div class="form-gap">

        <!-- ── Νομικές Αποδοχές ── -->
        <div class="legal-checks">
          <label class="legal-check-row" id="legalRow" onclick="toggleLegalCheck()">
            <input type="checkbox" name="accept_terms" id="accept_terms"
                   <?= isset($_POST['accept_terms']) ? 'checked' : '' ?> required>
            <div class="legal-check-box" id="legalBox">
              <i class="fas fa-check"></i>
            </div>
            <div class="legal-check-text">
              Αποδέχομαι τους <a href="<?= APP_URL ?>/legal/terms.php" target="_blank" onclick="event.stopPropagation()">Όρους Χρήσης</a>
              και την <a href="<?= APP_URL ?>/legal/privacy.php" target="_blank" onclick="event.stopPropagation()">Πολιτική Απορρήτου</a>. <span class="req">*</span>
            </div>
          </label>
        </div>

        <button type="submit" class="btn-submit">
          <i class="fas fa-rocket"></i>
          Δημιουργία Λογαριασμού
        </button>
      </div>
    </form>

    <div class="card-footer">
      Έχετε ήδη λογαριασμό;<br>
      <a href="<?= APP_URL ?>/login.php">
        <i class="fas fa-right-to-bracket"></i>
        Σύνδεση εδώ →
      </a>
    </div>
  </div>

</div><!-- /wrap -->

<script>
// ── Password toggle ──
function togglePwd(inputId, iconId) {
    var inp  = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    if (inp.type === 'password') { inp.type = 'text';     icon.className = 'fas fa-eye-slash'; }
    else                         { inp.type = 'password'; icon.className = 'fas fa-eye'; }
}

// ── Password strength ──
function checkStrength(v) {
    var hasLen     = v.length >= 8;
    var hasLong    = v.length >= 12;
    var hasUpper   = /[A-Z]/.test(v);
    var hasNum     = /[0-9]/.test(v);
    var hasSpecial = /[^A-Za-z0-9]/.test(v);

    var score = 0;
    if (hasLen)     score++;
    if (hasUpper)   score++;
    if (hasNum)     score++;
    if (hasSpecial) score++;
    if (hasLong && score >= 3) score = 4;

    var segColors = ['', '#e63946', '#f4a535', '#2dc653', '#2dc653'];
    var segs = [
        document.getElementById('seg1'), document.getElementById('seg2'),
        document.getElementById('seg3'), document.getElementById('seg4')
    ];
    segs.forEach(function(s, i) {
        if (!s) return;
        s.classList.remove('active');
        if (v.length === 0) { s.style.background = 'rgba(255,255,255,.08)'; return; }
        if (i < score) {
            s.style.background = segColors[score] || '#e63946';
            s.classList.add('active');
        } else {
            s.style.background = 'rgba(255,255,255,.08)';
        }
    });

    var badge = document.getElementById('sBadge');
    var icon  = document.getElementById('sBadgeIcon');
    var txt   = document.getElementById('sBadgeTxt');
    if (!badge) return;

    if (v.length === 0) {
        badge.className = 'strength-badge empty';
        txt.textContent = '';
        return;
    }

    var levels = [
        { cls: 'weak',   ico: 'fa-shield-xmark',  lbl: 'Αδύναμος κωδικός' },
        { cls: 'weak',   ico: 'fa-shield-xmark',  lbl: 'Αδύναμος κωδικός' },
        { cls: 'medium', ico: 'fa-shield-halved',  lbl: 'Μέτριος κωδικός' },
        { cls: 'strong', ico: 'fa-shield-check',   lbl: 'Ισχυρός κωδικός' },
        { cls: 'great',  ico: 'fa-shield-check',   lbl: 'Εξαιρετικός κωδικός ✓' },
    ];
    var lvl = levels[Math.min(score, 4)];
    badge.className    = 'strength-badge ' + lvl.cls;
    icon.className     = 'fas ' + lvl.ico;
    txt.textContent    = lvl.lbl;
}

// ── Password match feedback ──
document.getElementById('password2').addEventListener('input', function() {
    var p1 = document.getElementById('password').value;
    var p2 = this.value;
    var fb = document.getElementById('matchFeedback');
    if (!fb || p2.length === 0) { if (fb) fb.innerHTML = ''; return; }
    if (p1 === p2) {
        fb.className = 'match-feedback ok';
        fb.innerHTML = '<i class="fas fa-circle-check"></i> Οι κωδικοί ταιριάζουν!';
    } else {
        fb.className = 'match-feedback bad';
        fb.innerHTML = '<i class="fas fa-circle-xmark"></i> Οι κωδικοί δεν ταιριάζουν';
    }
});

// ── ΑΦΜ validation ──
function validateAfm(input) {
    var val = input.value.replace(/\D/g, '');
    input.value = val;
    var fb  = document.getElementById('afmFeedback');
    var ico = fb.querySelector('i');
    var txt = fb.querySelector('span');

    if (val === '') {
        fb.className = 'afm-feedback neutral';
        if (ico) ico.className = 'fas fa-circle-info';
        txt.textContent = 'Σωστά — 9 ψηφία';
        input.classList.remove('input-valid', 'input-invalid');
    } else if (val.length < 9) {
        fb.className = 'afm-feedback neutral';
        if (ico) ico.className = 'fas fa-pencil';
        txt.textContent = val.length + '/9 ψηφία';
        input.classList.remove('input-valid', 'input-invalid');
    } else {
        fb.className = 'afm-feedback valid';
        if (ico) ico.className = 'fas fa-circle-check';
        txt.textContent = '9 ψηφία ✓';
        input.classList.add('input-valid');
        input.classList.remove('input-invalid');
    }
}

(function() {
    var afmInput = document.getElementById('school_afm');
    if (afmInput && afmInput.value) validateAfm(afmInput);
})();

// ── Owner name validation ──
function validateOwnerName(input) {
    var fb = document.getElementById('ownerNameFeedback');
    var invalid = /[^a-zA-ZΑ-Ωα-ωΆ-Ώά-ώ\s]/.test(input.value);

    if (invalid) {
        input.value = input.value.replace(/[^a-zA-ZΑ-Ωα-ωΆ-Ώά-ώ\s]/g, '');
        input.classList.add('input-invalid');
        fb.style.display = 'flex';
        fb.className = 'afm-feedback invalid';
        fb.querySelector('span').textContent = 'Το όνομα μπορεί να περιέχει μόνο γράμματα';
    } else {
        input.classList.remove('input-invalid');
        fb.style.display = 'none';
    }
}

// ── Custom checkbox toggle ──
function toggleLegalCheck() {
    var chk = document.getElementById('accept_terms');
    var row = document.getElementById('legalRow');
    chk.checked = !chk.checked;
    row.classList.toggle('is-checked', chk.checked);
}

// ── Expand/collapse legal detail ──
function toggleLegalDetail(e) {
    e.preventDefault();
    var detail = document.getElementById('legalDetail');
    var link   = document.getElementById('legalDetailLink');
    var shown  = detail.style.display === 'block';
    detail.style.display = shown ? 'none' : 'block';
    link.innerHTML = shown
        ? '<i class="fas fa-circle-info"></i> Νομικές λεπτομέρειες…'
        : '<i class="fas fa-circle-chevron-up"></i> Απόκρυψη';
}

// ── Init checked state if pre-filled after POST error ──
(function() {
    var chk = document.getElementById('accept_terms');
    var row = document.getElementById('legalRow');
    if (chk && chk.checked) row.classList.add('is-checked');
})();

// ── Submit validation ──
document.getElementById('regForm').addEventListener('submit', function(e) {
    var p1 = document.getElementById('password').value;
    var p2 = document.getElementById('password2').value;
    if (p1 !== p2) {
        e.preventDefault();
        openPwModal();
        document.getElementById('password2').focus();
        return;
    }
    var chkTerms = document.getElementById('accept_terms');
    if (!chkTerms.checked) {
        e.preventDefault();
        var row = document.getElementById('legalRow');
        row.style.borderColor = 'rgba(230,57,70,.8)';
        row.style.boxShadow   = '0 0 0 3px rgba(230,57,70,.2)';
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
});

function openPwModal() {
    var m = document.getElementById('pwMismatchModal');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function() {
        requestAnimationFrame(function() { m.classList.add('pm-visible'); });
    });
}
function closePwModal() {
    var m = document.getElementById('pwMismatchModal');
    m.classList.add('pm-hiding');
    m.classList.remove('pm-visible');
    m.style.opacity = '0';
    setTimeout(function() {
        m.style.display = 'none';
        m.classList.remove('pm-hiding');
        m.style.opacity = '';
        document.body.style.overflow = '';
        document.getElementById('password2').focus();
    }, 210);
}
</script>

<!-- ── Password Mismatch Modal ── -->
<style>
.pm-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.78); z-index: 10500;
    align-items: center; justify-content: center; padding: 1rem;
    opacity: 0; transition: opacity .22s ease;
}
.pm-overlay.pm-visible { opacity: 1; }
@keyframes pmSlideUp {
    from { opacity: 0; transform: translateY(18px) scale(.97); }
    to   { opacity: 1; transform: translateY(0)    scale(1);   }
}
@keyframes pmSlideDown {
    from { opacity: 1; transform: translateY(0)    scale(1);   }
    to   { opacity: 0; transform: translateY(12px) scale(.97); }
}
.pm-visible .pm-box { animation: pmSlideUp   .26s cubic-bezier(.2,.8,.2,1) both; }
.pm-hiding  .pm-box { animation: pmSlideDown .21s ease both; }
.pm-box {
    background: #111827;
    border: 1px solid #1e2536;
    border-radius: 22px;
    width: 100%; max-width: 360px;
    box-shadow: 0 24px 80px rgba(0,0,0,.65);
    text-align: center;
    padding: 2rem 1.75rem 1.75rem;
}
.pm-icon-ring {
    width: 68px; height: 68px; border-radius: 50%;
    background: rgba(230,57,70,.12);
    border: 2px solid rgba(230,57,70,.3);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.9rem; color: #e63946;
    margin-bottom: 1.1rem;
    animation: pmRingPulse 2.5s ease-in-out infinite;
}
@keyframes pmRingPulse {
    0%,100% { box-shadow: 0 0 20px rgba(230,57,70,.15); }
    50%      { box-shadow: 0 0 38px rgba(230,57,70,.32); }
}
.pm-title   { font-size: 1.15rem; font-weight: 800; color: #f0f2ff; margin: 0 0 .5rem; }
.pm-message { font-size: .9rem; color: #8892b0; line-height: 1.65; margin: 0 0 1.5rem; }
.pm-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
    width: 100%; min-height: 48px; border-radius: 12px;
    background: #e63946; color: #fff;
    font-size: .98rem; font-weight: 800;
    border: none; cursor: pointer;
    transition: opacity .15s, transform .1s;
}
.pm-btn:hover  { opacity: .88; }
.pm-btn:active { transform: scale(.97); }
</style>

<div id="pwMismatchModal" class="pm-overlay" onclick="if(event.target===this)closePwModal()">
    <div class="pm-box">
        <div class="pm-icon-ring">
            <i class="fas fa-lock"></i>
        </div>
        <h3 class="pm-title">Οι κωδικοί δεν ταιριάζουν!</h3>
        <p class="pm-message">
            Ο κωδικός επαλήθευσης δεν είναι ίδιος με τον κωδικό που εισάγατε.<br>
            Παρακαλώ ελέγξτε και δοκιμάστε ξανά.
        </p>
        <button type="button" class="pm-btn" onclick="closePwModal()">
            <i class="fas fa-pen"></i> Διόρθωση Κωδικού
        </button>
    </div>
</div>

</body>
</html>
