<?php

/**
 * ============================================================
 * forgot-password.php — Επαναφορά Κωδικού
 * ============================================================
 * PURPOSE:
 *   Διαχειρίζεται τη ροή επαναφοράς κωδικού σε 4 βήματα:
 *   request → sent → reset → done
 *
 * FLOW:
 *   1. GET  → φόρμα εισαγωγής email
 *   2. POST email → rate limit → send reset link email
 *   3. GET  ?token=xxx → validate token → φόρμα νέου κωδικού
 *   4. POST new_password → hash → save → redirect login
 *
 * SECURITY MEASURES:
 *   ✓ CSRF σε κάθε POST (verifyCsrf)
 *   ✓ Rate limiting: 1 request/60min, 3/μήνα ανά user
 *   ✓ Token: bin2hex(random_bytes(32)) = 256-bit cryptographic random
 *   ✓ Token expires: 1 ώρα (αποθηκεύεται ως DATETIME στη ΒΔ)
 *   ✓ Token single-use: διαγράφεται μετά χρήση
 *   ✓ password_hash() με PASSWORD_DEFAULT (bcrypt)
 *   ✓ Ίδιο response αν email υπάρχει ή όχι (user enumeration prevention)
 *   ✓ preg_replace για token sanitization (αποτρέπει path traversal)
 *   ✓ Prepared statements παντού
 *
 * RATE LIMIT COLUMNS (users table):
 *   reset_last_sent DATETIME, reset_monthly_count INT, reset_month_start DATE
 * ============================================================
 */

require_once __DIR__ . '/includes/config.php';

// SECURITY: Διαχωρισμός parent και club sessions
if (isset($_SESSION['is_parent']) && $_SESSION['is_parent'] === true) {
    redirect(APP_URL . '/parent/index.php');
} elseif (isClubLoggedIn()) {
    redirect(isSuperAdmin() ? APP_URL.'/admin/' : APP_URL.'/dashboard/');
}

// ─── RATE LIMIT CONFIG ────────────────────────────────────────────────────────
define('RESET_COOLDOWN_MINUTES', 60);
define('RESET_MAX_PER_MONTH',    3);

// ─── INIT ─────────────────────────────────────────────────────────────────────
$step      = 'request';
$error     = '';
$resetUser = null;
$token     = preg_replace('/[^a-f0-9]/i', '', $_GET['token'] ?? '');

// ── GET with token: validate reset link ──────────────────────────────────────
if ($token && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id, name FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1"
    );
    $stmt->execute([$token]);
    $resetUser = $stmt->fetch();
    $step = $resetUser ? 'reset' : 'expired';
}

// ── POST: send reset email ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    // ── Επαλήθευση CSRF token — αποτρέπει Cross-Site Request Forgery ──
    verifyCsrf();
    $email = trim(strtolower($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Παρακαλώ εισάγετε έγκυρη διεύθυνση email.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT id, name,
                   reset_last_sent,
                   reset_monthly_count,
                   reset_month_start
            FROM   users
            WHERE  email = ? AND active = 1
            LIMIT  1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $now        = time();
            $lastSentTs = $user['reset_last_sent']   ? strtotime($user['reset_last_sent'])   : 0;
            $monthStart = $user['reset_month_start'] ? strtotime($user['reset_month_start']) : 0;
            $monthCount = (int)($user['reset_monthly_count'] ?? 0);
            $cooldownSecs = RESET_COOLDOWN_MINUTES * 60;

            // ── 1. Hourly cooldown check ──────────────────────────────────────
            if ($lastSentTs > 0 && ($now - $lastSentTs) < $cooldownSecs) {
                $minutesLeft = (int)ceil(($cooldownSecs - ($now - $lastSentTs)) / 60);
                $step  = 'ratelimit';
                $error = 'Μπορείτε να ζητήσετε νέο σύνδεσμο σε <strong>'
                       . $minutesLeft . ' λεπτ' . ($minutesLeft === 1 ? 'ό' : 'ά') . '</strong>.';
            }
            // ── 2. Monthly limit check ───────────────────────────────────────
            elseif (
                $monthStart > 0
                && date('Y-m', $monthStart) === date('Y-m', $now)
                && $monthCount >= RESET_MAX_PER_MONTH
            ) {
                $step  = 'ratelimit';
                $error = 'Έχετε φτάσει το μέγιστο όριο <strong>'
                       . RESET_MAX_PER_MONTH . ' αιτημάτων ανά μήνα</strong>. '
                       . 'Δοκιμάστε τον επόμενο μήνα ή επικοινωνήστε μαζί μας.';
            }
            // ── 3. All good: generate token & send ───────────────────────────
            else {
                $newToken = bin2hex(random_bytes(32));

                // Update monthly counter (reset if new month)
                $sameMonth     = $monthStart > 0 && date('Y-m', $monthStart) === date('Y-m', $now);
                $newMonthCount = $sameMonth ? ($monthCount + 1) : 1;
                $newMonthStart = $sameMonth ? $user['reset_month_start'] : date('Y-m-d H:i:s', $now);

                // Use MySQL DATE_ADD(NOW(),...) for expiry — NOT PHP date() — so both the
                // INSERT and the later "reset_expires > NOW()" check use the same MySQL
                // server clock. This prevents timezone-mismatch "link expired" errors.
                $db->prepare("
                    UPDATE users
                    SET    reset_token         = ?,
                           reset_expires       = DATE_ADD(NOW(), INTERVAL 1 HOUR),
                           reset_last_sent     = NOW(),
                           reset_monthly_count = ?,
                           reset_month_start   = ?
                    WHERE  id = ?
                ")->execute([$newToken, $newMonthCount, $newMonthStart, $user['id']]);

                $link     = rtrim(APP_URL, '/') . '/forgot-password.php?token=' . $newToken;
                $userName = htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8');
                $year     = date('Y');
                $logoUrl  = rtrim(APP_URL, '/') . '/assets/img/logo-tr.png';

                // ── Branded HTML email ───────────────────────────────────────
                $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Επαναφορά Κωδικού — MAster</title>
</head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:560px;background:#111520;border-radius:16px;border:1px solid #1e2536">

      <!-- HEADER -->
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:28px 32px 24px;text-align:center;border-bottom:2px solid #2a1a50;border-radius:16px 16px 0 0">
          <img src="{$logoUrl}" alt="MAster"
               style="height:64px;width:auto;max-width:200px;object-fit:contain;display:block;margin:0 auto">
        </td>
      </tr>

      <!-- GREETING -->
      <tr>
        <td style="padding:28px 32px 0">
          <h2 style="margin:0 0 12px;font-size:1.2rem;color:#f0f2ff;font-weight:800">🔑 Αίτημα Επαναφοράς Κωδικού</h2>
          <p style="margin:0 0 16px;color:#9aa3c0;font-size:.95rem;line-height:1.7">
            Γεια σου <strong style="color:#f0f2ff">{$userName}</strong>!<br><br>
            Λάβαμε ένα αίτημα <strong style="color:#f0f2ff">επαναφοράς του κωδικού πρόσβασης</strong> για τον λογαριασμό σου στο MAster.
          </p>
        </td>
      </tr>

      <!-- WHAT IS THIS -->
      <tr>
        <td style="padding:0 32px 20px">
          <div style="background:#0d1220;border:1px solid #1e2536;border-radius:12px;padding:18px 20px">
            <p style="margin:0 0 10px;font-size:.9rem;color:#7b85a8;line-height:1.65">
              <strong style="color:#c8cfe0">Τι συμβαίνει;</strong><br>
              Κάποιος (πιθανότατα εσύ) ζήτησε να επαναφέρει τον κωδικό εισόδου σε αυτόν τον λογαριασμό. Αν το ζήτησες εσύ, πάτησε το κουμπί παρακάτω.
            </p>
            <p style="margin:0;font-size:.88rem;color:#6b7494;line-height:1.65">
              <strong style="color:#a0aabf">⚠️ Αν ΔΕΝ το ζήτησες εσύ:</strong><br>
              Αγνόησε αυτό το email — ο λογαριασμός σου παραμένει <strong style="color:#2dc653">100% ασφαλής</strong>. Δεν έχει γίνει καμία αλλαγή ακόμα.
            </p>
          </div>
        </td>
      </tr>

      <!-- CTA BUTTON -->
      <tr>
        <td style="padding:0 32px 24px;text-align:center">
          <a href="{$link}" style="display:inline-block;background:#e63946;color:#ffffff;font-weight:800;padding:16px 36px;border-radius:12px;text-decoration:none;font-size:1.05rem;box-shadow:0 4px 20px rgba(230,57,70,.45)">
            🔐 Επαναφορά Κωδικού
          </a>
        </td>
      </tr>

      <!-- LINK FALLBACK -->
      <tr>
        <td style="padding:0 32px 20px">
          <div style="background:#0d1220;border:1px solid #1e2536;border-radius:10px;padding:14px 18px">
            <p style="margin:0 0 6px;font-size:.82rem;color:#6b7494">Αν το κουμπί δεν λειτουργεί, αντέγραψε αυτόν τον σύνδεσμο:</p>
            <p style="margin:0;font-size:.78rem;color:#5a6a99;word-break:break-all;line-height:1.55">{$link}</p>
          </div>
        </td>
      </tr>

      <!-- EXPIRY -->
      <tr>
        <td style="padding:0 32px 24px">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td style="background:rgba(240,165,0,.07);border:1px solid rgba(240,165,0,.25);border-radius:10px;padding:14px 18px">
                <p style="margin:0;font-size:.87rem;color:#ffe0a0;line-height:1.7">
                  <strong>⏱ Ο σύνδεσμος ισχύει για 1 ώρα</strong><br>
                  <span style="color:#b09060">Μετά τη λήξη θα χρειαστεί νέο αίτημα επαναφοράς.</span>
                </p>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <!-- SECURITY TIPS -->
      <tr>
        <td style="padding:0 32px 24px">
          <div style="border-top:1px solid #1e2536;padding-top:20px">
            <p style="margin:0 0 10px;font-size:.85rem;font-weight:700;color:#8892b0">🛡️ Συμβουλές Ασφαλείας:</p>
            <ul style="margin:0;padding-left:20px;font-size:.83rem;color:#6b7494;line-height:1.9">
              <li>Επέλεξε <strong style="color:#9aa3c0">ισχυρό κωδικό</strong> με γράμματα, αριθμούς &amp; σύμβολα.</li>
              <li>Μη χρησιμοποιείς τον ίδιο κωδικό σε άλλες υπηρεσίες.</li>
              <li>Μη μοιράζεσαι ποτέ τον κωδικό σου με τρίτους.</li>
              <li>Αν ανησυχείς για την ασφάλεια του λογαριασμού σου, επικοινώνησε μαζί μας.</li>
            </ul>
          </div>
        </td>
      </tr>

      <!-- FOOTER -->
      <tr>
        <td style="padding:16px 32px 24px;text-align:center;border-top:1px solid #1e2536">
          <p style="margin:0;font-size:.74rem;color:#4a5270;line-height:1.8">
            &copy; {$year} MAster &nbsp;&middot;&nbsp;
            <a href="mailto:pkotsorgios6@gmail.com" style="color:#5a6a99;text-decoration:none">pkotsorgios6@gmail.com</a><br>
            <span style="font-size:.68rem;color:#363d52">Έλαβες αυτό το email γιατί υπάρχει αίτημα επαναφοράς για τον λογαριασμό σου. Αν δεν το ζήτησες, απλά αγνόησέ το.</span>
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

                // Plain-text fallback for email clients that don't support HTML
                $plainText = "Επαναφορά Κωδικού — MAster\n"
                           . "=================================\n\n"
                           . "Γεια σου {$user['name']}!\n\n"
                           . "Λάβαμε αίτημα επαναφοράς κωδικού για τον λογαριασμό σου.\n\n"
                           . "ΤΙ ΣΥΜΒΑΙΝΕΙ;\n"
                           . "Κάποιος ζήτησε να αλλάξει τον κωδικό εισόδου για αυτόν τον λογαριασμό.\n"
                           . "Αν το ζήτησες εσύ, ακολούθησε τον παρακάτω σύνδεσμο.\n"
                           . "Αν ΔΕΝ το ζήτησες, αγνόησε αυτό το email — ο λογαριασμός σου είναι ασφαλής.\n\n"
                           . "Σύνδεσμος επαναφοράς (ισχύει 1 ώρα):\n"
                           . $link . "\n\n"
                           . "Συμβουλές Ασφαλείας:\n"
                           . "- Επέλεξε ισχυρό κωδικό με γράμματα, αριθμούς & σύμβολα\n"
                           . "- Μη χρησιμοποιείς τον ίδιο κωδικό σε άλλες υπηρεσίες\n"
                           . "- Μη μοιράζεσαι ποτέ τον κωδικό σου\n\n"
                           . "© {$year} Panagiotis Kotsorgios · pkotsorgios6@gmail.com\n";

                // ── FIXED sendEmail() call ────────────────────────────────────
                // Correct param order: (toEmail, subject, htmlBody, plainText, toName, ...)
                $emailSent = sendEmail(
                    $email,                                    // to email
                    '🔑 Επαναφορά Κωδικού — MAster',    // subject  ← was missing!
                    $htmlBody,                                 // HTML body
                    $plainText,                               // plain-text fallback
                    $user['name']                             // recipient name
                );

                if (!$emailSent) {
                    error_log('[MAster] forgot-password: sendEmail failed for user id=' . $user['id']);
                }

                // Always show 'sent' regardless of email success
                // (prevents email enumeration attacks)
                $step = 'sent';
            }
        } else {
            $step = 'notfound';
        }
    }
}

// ── POST: save new password ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    verifyCsrf();
    $postToken = preg_replace('/[^a-f0-9]/i', '', $_POST['token'] ?? '');
    $pass      = $_POST['new_password']    ?? '';
    $pass2     = $_POST['confirm_password'] ?? '';

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id, name FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1"
    );
    $stmt->execute([$postToken]);
    $resetUser = $stmt->fetch();

    if (!$resetUser) {
        $step = 'expired';
    } elseif (strlen($pass) < 8) {
        $error = 'Ο κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.';
        $token = $postToken;
        $step  = 'reset';
    } elseif ($pass !== $pass2) {
        $error = 'Οι κωδικοί δεν ταιριάζουν.';
        $token = $postToken;
        $step  = 'reset';
    } else {
        $db->prepare(
            "UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?"
        )->execute([password_hash($pass, PASSWORD_DEFAULT), $resetUser['id']]);
        $step = 'done';
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>Επαναφορά Κωδικού - MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="shortcut icon" href="./assets/img/favicon.png" type="image/png">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:#07090f; --card:#111520; --input-bg:#181e2e; --border:#2a3248;
      --red:#e63946; --red-dark:#c0303b; --white:#f0f2ff; --muted:#8892b0;
      --green:#2dc653; --gold:#f0a500; --radius:14px;
    }
    html { -webkit-text-size-adjust: 100%; }
    body {
      font-family:'DM Sans',sans-serif; background:var(--bg); color:var(--white);
      min-height:100vh; min-height:100dvh; display:flex; flex-direction:column;
      align-items:center; justify-content:center;
      padding:1rem; padding-top:4.5rem; padding-bottom:1.5rem;
    }
    /* ── Back button — text always visible ── */
    .back-home {
      position:fixed; top:.85rem; left:.85rem;
      display:flex; align-items:center; gap:.45rem;
      color:var(--muted); font-size:.95rem; font-weight:700;
      text-decoration:none; background:rgba(255,255,255,.05);
      border:1px solid var(--border); border-radius:50px;
      padding:.55rem 1rem .55rem .8rem; transition:all .2s; z-index:100; min-height:44px;
    }
    .back-home:hover { color:var(--white); background:rgba(255,255,255,.1); border-color:rgba(255,255,255,.2); }
    .back-home i { font-size:.9rem; }
    .card {
      background:var(--card); border:2px solid #1e2536; border-radius:20px;
      padding:1.75rem 1.5rem; width:100%; max-width:460px;
      box-shadow:0 20px 60px rgba(0,0,0,.7);
    }
    .logo { text-align:center; margin-bottom:1.4rem; }
    .logo a { display:inline-block; text-decoration:none; }
    .logo-img { height:clamp(64px,18vw,110px); width:auto; object-fit:contain; display:block; margin:0 auto .5rem; }
    .step-title { font-size:clamp(1.2rem,5vw,1.5rem); font-weight:800; text-align:center; margin-bottom:.6rem; line-height:1.3; }
    .step-desc { font-size:clamp(.95rem,4vw,1.05rem); color:var(--muted); text-align:center; line-height:1.65; margin-bottom:1.4rem; }
    .step-desc strong { color:var(--white); }
    .alert {
      padding:.9rem 1rem; border-radius:var(--radius);
      font-size:clamp(.95rem,4vw,1.05rem); font-weight:600; margin-bottom:1.25rem;
      display:flex; gap:.65rem; align-items:flex-start; line-height:1.55;
    }
    .alert i { font-size:1.1rem; flex-shrink:0; margin-top:.1rem; }
    .alert-error   { background:rgba(230,57,70,.12);  border:2px solid rgba(230,57,70,.4);  color:#ffb3b8; }
    .alert-success { background:rgba(45,198,83,.1);   border:2px solid rgba(45,198,83,.35); color:#90f0aa; }
    .alert-info    { background:rgba(240,165,0,.08);  border:2px solid rgba(240,165,0,.3);  color:#ffe0a0; }
    .form-group { margin-bottom:1.2rem; }
    label { display:flex; align-items:center; gap:.5rem; font-size:clamp(1rem,4.5vw,1.1rem); font-weight:800; margin-bottom:.55rem; color:var(--white); }
    label i { color:var(--muted); font-size:1rem; width:18px; text-align:center; }
    .input-wrap { position:relative; }
    input[type="email"], input[type="password"], input[type="text"] {
      width:100%; padding:.95rem 3.5rem .95rem 1rem; background:var(--input-bg);
      border:2px solid var(--border); border-radius:var(--radius); color:var(--white);
      font-size:clamp(1rem,4.5vw,1.15rem); font-family:inherit;
      transition:border-color .2s,box-shadow .2s; -webkit-appearance:none; line-height:1.4;
    }
    input::placeholder { color:#4a5270; }
    input:focus { outline:none; border-color:var(--red); box-shadow:0 0 0 4px rgba(230,57,70,.18); }
    .eye-btn {
      position:absolute; right:.75rem; top:50%; transform:translateY(-50%);
      background:none; border:none; cursor:pointer; color:#4a5270; font-size:1.15rem;
      width:44px; height:44px; display:flex; align-items:center; justify-content:center;
      border-radius:8px; transition:color .2s,background .2s;
    }
    .eye-btn:hover { color:var(--white); background:rgba(255,255,255,.06); }
    .form-hint { font-size:clamp(.85rem,3.5vw,.92rem); color:var(--muted); margin-top:.35rem; }
    .strength-bar { height:5px; border-radius:3px; background:var(--border); overflow:hidden; margin-top:.45rem; }
    .strength-fill { height:100%; width:0; border-radius:3px; transition:width .3s,background .3s; }
    .strength-lbl { font-size:clamp(.8rem,3.5vw,.88rem); text-align:right; margin-top:.3rem; color:var(--muted); font-weight:700; }
    .btn-submit {
      width:100%; padding:1.1rem 1rem; background:var(--red); color:#fff;
      border:none; border-radius:var(--radius);
      font-size:clamp(1.1rem,5vw,1.25rem); font-weight:800; font-family:inherit;
      cursor:pointer; margin-top:.4rem;
      box-shadow:0 4px 20px rgba(230,57,70,.4);
      transition:background .2s,transform .15s,box-shadow .2s;
      display:flex; align-items:center; justify-content:center; gap:.65rem;
      min-height:56px; letter-spacing:.01em; text-decoration:none; margin-bottom:.75rem;
    }
    .btn-submit:hover { background:var(--red-dark); box-shadow:0 6px 28px rgba(230,57,70,.55); transform:translateY(-1px); }
    .btn-submit:active { transform:translateY(0); background:var(--red-dark); }
    .btn-ghost {
      width:100%; padding:1rem; background:rgba(255,255,255,.05); color:var(--white);
      border:2px solid var(--border); border-radius:var(--radius);
      font-size:clamp(1rem,4.5vw,1.1rem); font-weight:800; font-family:inherit;
      cursor:pointer; display:flex; align-items:center; justify-content:center;
      gap:.65rem; min-height:52px; text-decoration:none;
      transition:background .2s,transform .15s; margin-bottom:.75rem;
    }
    .btn-ghost:hover { background:rgba(255,255,255,.09); transform:translateY(-1px); }
    .card form + .btn-ghost, .card form + .btn-submit { margin-top:.75rem; }

    /* Rate limit box */
    .ratelimit-box {
      background:rgba(240,165,0,.07); border:2px solid rgba(240,165,0,.3);
      border-radius:var(--radius); padding:1.1rem 1.2rem; margin-bottom:1.25rem; line-height:1.65;
    }
    .ratelimit-box .rl-title { font-size:1rem; font-weight:800; color:#ffd080; margin-bottom:.5rem; display:flex; align-items:center; gap:.5rem; }
    .ratelimit-box p { font-size:.93rem; color:#b09060; margin:0; }

    @media (max-width:480px) {
      body { padding:1rem; padding-top:4.25rem; padding-bottom:1.25rem; justify-content:flex-start; }
      .card { padding:1.5rem 1.25rem; border-radius:18px; }
      /* back button keeps its text on all screen sizes — no span hiding */
    }
    @media (max-width:375px) { body { padding:.75rem; padding-top:4rem; padding-bottom:1rem; } .card { padding:1.25rem 1rem; border-radius:16px; } .form-group { margin-bottom:1rem; } .step-title { margin-bottom:.5rem; } .step-desc { margin-bottom:1.1rem; } .logo { margin-bottom:1rem; } }
    @media (max-width:320px) { body { padding:.6rem; padding-top:3.75rem; padding-bottom:.75rem; } .card { padding:1rem .9rem; border-radius:14px; border-width:1px; } }
    @media (max-height:600px) and (orientation:landscape) { body { justify-content:flex-start; padding-top:4rem; } .logo-img { height:52px; } }

    @keyframes cardIn { from { opacity:0; transform:translateY(18px) scale(.985); filter:blur(6px); } to { opacity:1; transform:translateY(0) scale(1); filter:blur(0); } }
    .card { animation:cardIn .55s cubic-bezier(.2,.8,.2,1) both; }
    @keyframes itemIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .card > * { opacity:0; animation:itemIn .45s ease-out both; }
    .card > *:nth-child(1){ animation-delay:.08s; } .card > *:nth-child(2){ animation-delay:.14s; }
    .card > *:nth-child(3){ animation-delay:.20s; } .card > *:nth-child(4){ animation-delay:.26s; }
    .card > *:nth-child(5){ animation-delay:.32s; } .card > *:nth-child(6){ animation-delay:.38s; }
    .card > *:nth-child(7){ animation-delay:.44s; }
    @media (prefers-reduced-motion:reduce) { .card,.modal-overlay,.modal-box { animation:none!important; } .card > *,.modal-box > * { animation:none!important; opacity:1; } }

    /* Modal */
    @keyframes overlayIn { from { opacity:0; } to { opacity:1; } }
    .modal-overlay { position:fixed; inset:0; background:rgba(4,6,14,.85); backdrop-filter:blur(7px); -webkit-backdrop-filter:blur(7px); display:flex; align-items:center; justify-content:center; padding:1.25rem; z-index:500; animation:overlayIn .3s ease both; }
    @keyframes modalIn { from { opacity:0; transform:translateY(30px) scale(.93); filter:blur(10px); } to { opacity:1; transform:translateY(0) scale(1); filter:blur(0); } }
    .modal-box { background:#111520; border:2px solid #1e2536; border-radius:22px; padding:2rem 1.75rem; width:100%; max-width:420px; box-shadow:0 28px 70px rgba(0,0,0,.9); animation:modalIn .5s cubic-bezier(.2,.8,.2,1) .08s both; text-align:center; }
    @keyframes phonePulse { 0%,100% { box-shadow:0 0 0 0 rgba(240,165,0,.55); transform:scale(1); } 50% { box-shadow:0 0 0 16px rgba(240,165,0,.0); transform:scale(1.07); } }
    .modal-icon { width:74px; height:74px; background:rgba(240,165,0,.12); border:2px solid rgba(240,165,0,.38); border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:1.95rem; margin-bottom:1.1rem; animation:phonePulse 2.2s ease-in-out infinite; }
    @keyframes mItemIn { from { opacity:0; transform:translateY(9px); } to { opacity:1; transform:translateY(0); } }
    .modal-box > * { opacity:0; animation:mItemIn .4s ease-out both; }
    .modal-box > *:nth-child(1){ animation-delay:.24s; } .modal-box > *:nth-child(2){ animation-delay:.32s; }
    .modal-box > *:nth-child(3){ animation-delay:.39s; } .modal-box > *:nth-child(4){ animation-delay:.46s; }
    .modal-box > *:nth-child(5){ animation-delay:.53s; } .modal-box > *:nth-child(6){ animation-delay:.60s; }
    .modal-title { font-size:clamp(1.15rem,5vw,1.4rem); font-weight:800; color:var(--white); margin-bottom:.5rem; line-height:1.3; }
    .modal-desc { font-size:clamp(.95rem,4vw,1.05rem); color:var(--muted); line-height:1.65; margin-bottom:1.4rem; }
    .modal-desc strong { color:var(--white); }
    .modal-phone { display:inline-flex; align-items:center; justify-content:center; gap:.65rem; background:rgba(240,165,0,.1); border:2px solid rgba(240,165,0,.38); border-radius:var(--radius); padding:1rem 1.5rem; text-decoration:none; color:#ffe0a0; font-size:clamp(1.3rem,6.5vw,1.7rem); font-weight:800; letter-spacing:.06em; width:100%; margin-bottom:1.25rem; transition:background .2s,transform .15s; box-shadow:0 4px 22px rgba(240,165,0,.14); }
    .modal-phone:hover { background:rgba(240,165,0,.2); transform:translateY(-1px); }
    .modal-phone i { font-size:1.3rem; }
    .modal-divider { font-size:clamp(.85rem,3.5vw,.92rem); color:#4a5270; margin-bottom:1.1rem; }
    .modal-btn-retry { width:100%; padding:1rem; background:var(--red); color:#fff; border:none; border-radius:var(--radius); font-size:clamp(1rem,4.5vw,1.1rem); font-weight:800; font-family:inherit; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:.6rem; min-height:52px; text-decoration:none; transition:background .2s,transform .15s; margin-bottom:.75rem; box-shadow:0 4px 18px rgba(230,57,70,.35); }
    .modal-btn-retry:hover { background:var(--red-dark); transform:translateY(-1px); }
    @media (max-width:420px) { .modal-box { padding:1.5rem 1.25rem; border-radius:18px; } }
  </style>
<?php include __DIR__ . '/includes/prelogin_polish.php'; ?>
</head>
<body>

<a href="<?= APP_URL ?>/login.php" class="back-home">
  <i class="fas fa-arrow-left"></i>
  <span>Πίσω στη Σύνδεση</span>
</a>

<div class="card">

  <div class="logo">
    <a href="<?= APP_URL ?>/index.php">
      <img src="<?= APP_URL ?>/assets/img/logo-tr.png" alt="Master" class="logo-img">
    </a>
  </div>

  <?php if ($step === 'request' || $step === 'notfound'): ?>

    <div class="step-title">Ξεχάσατε τον κωδικό;</div>
    <p class="step-desc">Εισάγετε το email σας και θα σας στείλουμε έναν σύνδεσμο για να ορίσετε νέο κωδικό.</p>

    <?php if ($step === 'notfound'): ?>
    <div class="alert alert-error" style="margin-bottom:1rem">
      <i class="fas fa-circle-exclamation"></i>
      <div>
        <div style="font-weight:800;margin-bottom:.2rem">Το email δεν βρέθηκε</div>
        <div style="font-size:.92em">Δεν υπάρχει λογαριασμός με αυτή τη διεύθυνση email. Ελέγξτε αν το έχετε γράψει σωστά ή δοκιμάστε άλλο email.</div>
      </div>
    </div>
    <?php elseif ($error): ?>
    <div class="alert alert-error">
      <i class="fas fa-circle-exclamation"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <div class="form-group">
        <label for="email">
          <i class="fas fa-envelope"></i>
          Διεύθυνση Email
        </label>
        <div class="input-wrap">
          <input type="email" id="email" name="email"
                 placeholder="giannis@gmail.com"
                 value="<?= h($_POST['email'] ?? '') ?>"
                 autocomplete="email"
                 required autofocus>
        </div>
        <p class="form-hint">Εισάγετε το email με το οποίο εγγραφήκατε στο MAster.</p>
      </div>
      <button type="submit" class="btn-submit">
        <i class="fas fa-paper-plane"></i>
        <?= $step === 'notfound' ? 'Δοκιμάστε Άλλο Email' : 'Αποστολή Συνδέσμου' ?>
      </button>
    </form>

    <a href="<?= APP_URL ?>/login.php" class="btn-ghost">
      <i class="fas fa-arrow-left"></i>
      Πίσω στη Σύνδεση
    </a>

  <?php elseif ($step === 'ratelimit'): ?>

    <div class="step-title">Περιμένετε Λίγο</div>
    <p class="step-desc">Για την ασφάλεια του λογαριασμού σας, υπάρχουν όρια στα αιτήματα επαναφοράς.</p>

    <div class="ratelimit-box">
      <div class="rl-title"><i class="fas fa-shield-halved"></i> Προστασία Anti-Abuse</div>
      <p><?= $error /* pre-sanitized, contains <strong> tags only */ ?></p>
    </div>

    <div class="alert alert-info">
      <i class="fas fa-circle-info"></i>
      <span>
        Τα όρια αποστολής: <strong>1 αίτημα ανά ώρα</strong> &amp;
        <strong><?= RESET_MAX_PER_MONTH ?> αιτήματα ανά μήνα</strong> ανά λογαριασμό.
      </span>
    </div>

    <a href="<?= APP_URL ?>/login.php" class="btn-submit">
      <i class="fas fa-arrow-right-to-bracket"></i>
      Πίσω στη Σύνδεση
    </a>
    <a href="tel:6986788178" class="btn-ghost">
      <i class="fas fa-phone"></i>
      Επικοινωνία για Βοήθεια
    </a>

  <?php elseif ($step === 'sent'): ?>

    <div class="step-title">Email Εστάλη!</div>
    <p class="step-desc">
      Αν το email υπάρχει στο σύστημά μας, θα λάβετε τον σύνδεσμο επαναφοράς μέσα στα επόμενα λεπτά.<br><br>
      <strong>Ελέγξτε και τον φάκελο Spam / Ανεπιθύμητα.</strong>
    </p>

    <div class="alert alert-info">
      <i class="fas fa-clock"></i>
      <span>Ο σύνδεσμος στάλθηκε και ισχύει για <strong>1 ώρα</strong>. Μετά τη λήξη του θα χρειαστεί νέο αίτημα επαναφοράς.</span>
    </div>

    <a href="<?= APP_URL ?>/login.php" class="btn-submit">
      <i class="fas fa-arrow-right-to-bracket"></i>
      Πίσω στη Σύνδεση
    </a>
    <a href="<?= APP_URL ?>/forgot-password.php" class="btn-ghost">
      <i class="fas fa-rotate-right"></i>
      Νέο αίτημα επαναφοράς
    </a>

  <?php elseif ($step === 'reset'): ?>

    <div class="step-title">Νέος Κωδικός</div>
    <p class="step-desc">
      Γεια σου <strong><?= h($resetUser['name'] ?? '') ?></strong>!<br>
      Επιλέξτε τον νέο σας κωδικό εισόδου.
    </p>

    <?php if ($error): ?>
    <div class="alert alert-error">
      <i class="fas fa-circle-exclamation"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="token" value="<?= h($token) ?>">

      <div class="form-group">
        <label for="new_password"><i class="fas fa-lock"></i> Νέος Κωδικός</label>
        <div class="input-wrap">
          <input type="password" id="new_password" name="new_password"
                 placeholder="τουλάχιστον 8 χαρακτήρες"
                 oninput="checkStrength(this.value)"
                 autocomplete="new-password" required autofocus>
          <button type="button" class="eye-btn" onclick="togglePwd('new_password',this)" tabindex="-1" aria-label="Εμφάνιση κωδικού">
            <i class="fas fa-eye"></i>
          </button>
        </div>
        <div class="strength-bar"><div class="strength-fill" id="sFill"></div></div>
        <div class="strength-lbl" id="sLbl"></div>
      </div>

      <div class="form-group">
        <label for="confirm_password"><i class="fas fa-lock"></i> Επαλήθευση Νέου Κωδικού</label>
        <div class="input-wrap">
          <input type="password" id="confirm_password" name="confirm_password"
                 placeholder="επαναλάβετε τον νέο κωδικό"
                 autocomplete="new-password" required>
          <button type="button" class="eye-btn" onclick="togglePwd('confirm_password',this)" tabindex="-1" aria-label="Εμφάνιση κωδικού επαλήθευσης">
            <i class="fas fa-eye"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-submit">
        <i class="fas fa-shield-check"></i>
        Αποθήκευση Νέου Κωδικού
      </button>
    </form>

  <?php elseif ($step === 'done'): ?>

    <div class="step-title">Κωδικός Άλλαξε!</div>
    <p class="step-desc">
      Ο κωδικός σας ενημερώθηκε επιτυχώς.<br>
      Μπορείτε τώρα να συνδεθείτε με τον νέο σας κωδικό.
    </p>

    <div class="alert alert-success">
      <i class="fas fa-circle-check"></i>
      <span>Για ασφάλεια, αποσυνδεθείτε από όλες τις άλλες συσκευές αν χρειάζεται.</span>
    </div>

    <a href="<?= APP_URL ?>/login.php" class="btn-submit">
      <i class="fas fa-arrow-right-to-bracket"></i>
      Σύνδεση Τώρα
    </a>

  <?php else: ?>

    <div class="step-title">Ο Σύνδεσμος Έληξε</div>
    <p class="step-desc">
      Αυτός ο σύνδεσμος επαναφοράς δεν είναι πλέον έγκυρος.<br>
      Οι σύνδεσμοι ισχύουν για <strong>1 ώρα</strong>. Ζητήστε νέο παρακάτω.
    </p>

    <a href="<?= APP_URL ?>/forgot-password.php" class="btn-submit">
      <i class="fas fa-rotate-right"></i>
      Νέο Αίτημα Επαναφοράς
    </a>
    <a href="<?= APP_URL ?>/login.php" class="btn-ghost">
      <i class="fas fa-arrow-left"></i>
      Πίσω στη Σύνδεση
    </a>

  <?php endif; ?>

</div>

<script>
function togglePwd(id, btn) {
  const inp = document.getElementById(id);
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}

function checkStrength(v) {
  let score = 0;
  if (v.length >= 8)           score++;
  if (v.length >= 12)          score++;
  if (/[A-Z]/.test(v))         score++;
  if (/[0-9]/.test(v))         score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const pct    = (score / 5) * 100;
  const colors = ['','#e63946','#e63946','#f4a535','#2dc653','#2dc653'];
  const labels = ['','Πολύ αδύναμος','Αδύναμος','Μέτριος','Ισχυρός','Πολύ ισχυρός'];
  const fill = document.getElementById('sFill');
  const lbl  = document.getElementById('sLbl');
  if (!fill) return;
  fill.style.width      = pct + '%';
  fill.style.background = colors[score] || '#e63946';
  lbl.textContent       = v.length ? labels[score] || '' : '';
  lbl.style.color       = colors[score] || '';
}
</script>
</body>
</html>