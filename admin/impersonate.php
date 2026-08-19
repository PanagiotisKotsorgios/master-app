<?php
/**
 * admin/impersonate.php — OTP-verified impersonation flow.
 *
 * GET  ?school=ID          → step 1: send OTP to the admin's own email
 * POST code (+ token)      → step 2: verify code, flip session, redirect to /dashboard/
 *
 * Every step audit-logs to admin_impersonate_otp_* and stores a
 * row in impersonation_otp. The OTP is a 6-digit code hashed
 * with sha256, valid for 10 minutes, max 5 wrong tries.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mailer.php';
requireSuperAdmin();

$db = getDB();
$adminUserId  = userId();
$targetSchool = (int)($_GET['school'] ?? $_POST['school'] ?? 0);
$flash = null;

// ── Step 2: verify code ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['code'])) {
    verifyCsrf();
    $code    = preg_replace('/\D/', '', (string)$_POST['code']);
    $otpId   = (int)($_POST['otp_id'] ?? 0);

    if (strlen($code) !== 6 || $otpId <= 0) {
        $flash = ['type' => 'error', 'msg' => 'Άκυρος κωδικός.'];
    } else {
        $row = $db->prepare("SELECT * FROM impersonation_otp WHERE id = ? AND admin_user_id = ? LIMIT 1");
        $row->execute([$otpId, $adminUserId]);
        $otp = $row->fetch();
        if (!$otp || $otp['consumed_at'] || strtotime($otp['expires_at']) < time() || (int)$otp['attempts'] >= 5) {
            $flash = ['type' => 'error', 'msg' => 'Ο κωδικός έληξε ή δεν είναι έγκυρος. Ξεκινήστε ξανά.'];
        } else {
            $expected = hash('sha256', $code);
            $ok = hash_equals($otp['code_hash'], $expected);
            $db->prepare("UPDATE impersonation_otp SET attempts = attempts + 1 WHERE id = ?")->execute([(int)$otp['id']]);
            if (!$ok) {
                $flash = ['type' => 'error', 'msg' => 'Λάθος κωδικός. Έχετε ' . (5 - (int)$otp['attempts'] - 1) . ' προσπάθεια(ες).'];
            } else {
                // Resolve owner user of target school
                $sc = $db->prepare("SELECT * FROM schools WHERE id = ? LIMIT 1");
                $sc->execute([(int)$otp['target_school_id']]);
                $school = $sc->fetch();
                $uq = $db->prepare("SELECT * FROM users WHERE school_id = ? AND role = 'owner' ORDER BY id ASC LIMIT 1");
                $uq->execute([(int)$otp['target_school_id']]);
                $user = $uq->fetch();
                if (!$school || !$user) {
                    $flash = ['type' => 'error', 'msg' => 'Δεν βρέθηκε owner user στη σχολή.'];
                } else {
                    // Flip session
                    $_SESSION['user_id']     = $user['id'];
                    $_SESSION['school_id']   = $user['school_id'];
                    $_SESSION['school_name'] = $school['name'];
                    $_SESSION['user']        = ['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']];
                    $_SESSION['impersonating'] = true;
                    $_SESSION['impersonation_otp_id'] = (int)$otp['id'];

                    $db->prepare("UPDATE impersonation_otp SET consumed_at = NOW(), target_user_id = ? WHERE id = ?")
                       ->execute([(int)$user['id'], (int)$otp['id']]);

                    auditLog('admin_impersonate_otp_success', 'school', (int)$otp['target_school_id']);
                    flash('Impersonation ενεργή για ' . h($school['name']) . ' — <a href="' . APP_URL . '/admin/">Επιστροφή στο Admin</a>');
                    redirect(APP_URL . '/dashboard/');
                }
            }
        }
    }
}

// ── Step 1: request OTP ─────────────────────────────
$activeOtp = null;
if ($targetSchool > 0 && !$flash) {
    // Resolve target
    $sc = $db->prepare("SELECT id, name, email FROM schools WHERE id = ? LIMIT 1");
    $sc->execute([$targetSchool]);
    $school = $sc->fetch();
    if (!$school) {
        $flash = ['type' => 'error', 'msg' => 'Η σχολή δεν βρέθηκε.'];
    } else {
        // Get admin's own email
        $me = $db->prepare("SELECT id, email, name FROM users WHERE id = ? LIMIT 1");
        $me->execute([$adminUserId]);
        $meRow = $me->fetch();

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = hash('sha256', $code);
        $exp  = date('Y-m-d H:i:s', time() + 600); // 10 min
        $ip   = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0] ?? '');

        $ins = $db->prepare("INSERT INTO impersonation_otp
            (admin_user_id, target_school_id, code_hash, expires_at, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$adminUserId, $targetSchool, $hash, $exp, substr($ip, 0, 45), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
        $activeOtp = ['id' => (int)$db->lastInsertId(), 'school' => $school];

        // Email the admin with the code
        $emailTo = $meRow['email'] ?? '';
        $sent = false;
        if ($emailTo && function_exists('sendEmail')) {
            $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f5f9;padding:24px">'
                  . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;padding:28px 32px">'
                  . '<h2 style="margin:0 0 10px;color:#111827">Impersonation OTP</h2>'
                  . '<p style="color:#374151;line-height:1.55">Ζητήθηκε impersonation για τη σχολή <strong>' . htmlspecialchars($school['name']) . '</strong>.</p>'
                  . '<div style="font-size:2rem;font-weight:800;letter-spacing:.3em;text-align:center;padding:16px;background:#f3f4f6;border-radius:8px;margin:16px 0">' . $code . '</div>'
                  . '<p style="color:#6b7280;font-size:.85em">Λήγει σε 10 λεπτά. Αν δεν το ζητήσατε εσείς, αγνοήστε αυτό το email.</p>'
                  . '</div></body></html>';
            $sent = @sendEmail($emailTo, "Impersonation OTP: {$code}", $html, "OTP: {$code}");
        }
        // Also log to disk so admin can fetch it even without email
        error_log('[MAster impersonation] OTP for admin ' . $adminUserId . ' → school ' . $targetSchool . ': ' . $code);
        auditLog('admin_impersonate_otp_requested', 'school', $targetSchool, $sent ? 'email_ok' : 'email_failed');
    }
}
?><!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Impersonation OTP — MAster</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:
  radial-gradient(1000px 600px at 20% 0%, rgba(230,57,70,.12), transparent 60%),
  radial-gradient(800px 500px at 80% 100%, rgba(240,165,0,.08), transparent 60%),
  #07090f;color:#f0f2ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem}
.box{max-width:460px;width:100%;background:linear-gradient(180deg,rgba(19,23,34,.9),rgba(13,16,23,.9));border:1px solid rgba(255,255,255,.07);border-radius:20px;padding:2rem;backdrop-filter:blur(20px);box-shadow:0 30px 60px -20px rgba(0,0,0,.6)}
.icon{width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,rgba(230,57,70,.3),rgba(230,57,70,.1));color:#ffb0b8;border:1px solid rgba(230,57,70,.3);display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin:0 auto 1rem}
h1{font-size:1.35rem;font-weight:800;text-align:center;margin-bottom:.5rem;letter-spacing:-.02em}
.sub{color:#8892b0;text-align:center;font-size:.9rem;margin-bottom:1.5rem;line-height:1.5}
.flash{padding:.75rem 1rem;border-radius:10px;margin-bottom:1rem;font-weight:600;font-size:.88rem}
.flash.success{background:rgba(45,198,83,.12);border:1px solid rgba(45,198,83,.28);color:#d5ffd8}
.flash.error{background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.28);color:#ffe6e8}
input[type=text]{width:100%;padding:1rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:10px;color:#f0f2ff;font-family:inherit;font-size:1.4rem;letter-spacing:.4em;text-align:center;font-weight:800}
input[type=text]:focus{outline:none;border-color:rgba(230,57,70,.55);box-shadow:0 0 0 3px rgba(230,57,70,.12)}
button{width:100%;padding:.9rem;margin-top:.75rem;background:linear-gradient(135deg,#e63946,#c72832);color:#fff;border:none;border-radius:10px;font-family:inherit;font-weight:700;font-size:.95rem;cursor:pointer;transition:transform .15s ease}
button:hover{transform:translateY(-1px)}
.hint{color:#6b7494;font-size:.78rem;text-align:center;margin-top:1rem;line-height:1.5}
.back{display:block;margin-top:1.25rem;text-align:center;color:#8892b0;font-size:.85rem;text-decoration:none}
.back:hover{color:#c9cee1}
</style>
</head>
<body>
<div class="box">
  <div class="icon"><i class="fa-solid fa-user-secret"></i></div>
  <h1>Impersonation OTP</h1>
  <?php if ($activeOtp): ?>
    <p class="sub">Στάλθηκε 6-ψήφιος κωδικός στο email σας για επιβεβαίωση impersonation στη σχολή <strong style="color:#fff"><?= h($activeOtp['school']['name']) ?></strong>.</p>
  <?php else: ?>
    <p class="sub">Εισάγετε τον κωδικό που λάβατε.</p>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="flash <?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <?= csrfField() ?>
    <?php if ($activeOtp): ?>
      <input type="hidden" name="otp_id" value="<?= (int)$activeOtp['id'] ?>">
      <input type="hidden" name="school" value="<?= (int)$activeOtp['school']['id'] ?>">
    <?php elseif (!empty($_POST['otp_id'])): ?>
      <input type="hidden" name="otp_id" value="<?= (int)$_POST['otp_id'] ?>">
    <?php endif; ?>
    <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="000000">
    <button type="submit"><i class="fa-solid fa-check"></i> Επιβεβαίωση & Impersonate</button>
  </form>

  <p class="hint">Ο κωδικός λήγει σε 10 λεπτά. Αν δεν λάβατε email, ο κωδικός εμφανίζεται και στα container logs για fallback debugging.</p>
  <a class="back" href="<?= APP_URL ?>/admin/schools.php"><i class="fa-solid fa-arrow-left"></i> Ακύρωση</a>
</div>
</body>
</html>
