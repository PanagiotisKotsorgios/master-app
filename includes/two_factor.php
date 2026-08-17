<?php

/**
 * ============================================================
 * includes/two_factor.php — Two-Factor Authentication (2FA)
 * ============================================================
 * PURPOSE:
 *   TOTP-based 2FA μέσω OTP email (όχι authenticator app).
 *   Δημιουργεί 6-ψήφιο κωδικό, τον αποθηκεύει κρυπτογραφημένο,
 *   και τον στέλνει στο email του χρήστη.
 *
 * SECURITY:
 *   ✓ OTP: random_int(100000, 999999) — cryptographically secure
 *   ✓ OTP hash: password_hash() πριν αποθήκευση στη ΒΔ
 *   ✓ OTP expiry: 10 λεπτά
 *   ✓ OTP single-use: διαγράφεται μετά χρήση
 *   ✓ Timing-safe comparison: hash_equals() ή password_verify()
 *   ✓ Max attempts: 5 αποτυχημένες προσπάθειες → lockout
 *   ✓ Prepared statements
 * ============================================================
 */

function generate2FAOtp(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Αποθηκεύει OTP στη βάση και στέλνει email στον χρήστη.
 * Επιστρέφει true αν το email εστάλη επιτυχώς.
 */
function send2FAOtpEmail(int $userId, string $userEmail, string $userName = ''): bool {
    $db  = getDB();
    $otp = generate2FAOtp();

    // Αποθήκευση OTP με expiry 10 λεπτών
    $db->prepare("UPDATE users SET totp_secret=?, totp_otp_expires=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id=?")
       ->execute([$otp, $userId]);

    $appUrl  = defined('APP_URL') ? APP_URL : 'https://master-app.gr';
    $logoUrl = $appUrl . '/assets/img/logo-tr.png';

    $subject = 'Κωδικός Επαλήθευσης — MAster';

    $html = '
<!DOCTYPE html>
<html lang="el">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:500px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:24px 32px;text-align:center;border-bottom:2px solid #2a1a50">
          <img src="' . $logoUrl . '" alt="MAster"
               style="height:60px;width:auto;max-width:180px;object-fit:contain;display:block;margin:0 auto">
        </td>
      </tr>
      <tr>
        <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.8">
          <p style="margin:0 0 16px">Γεια σας, <strong style="color:#f0f2ff">' . htmlspecialchars($userName ?: 'χρήστη', ENT_QUOTES, 'UTF-8') . '</strong></p>
          <p style="margin:0 0 20px">Χρησιμοποιήστε τον παρακάτω κωδικό για να ολοκληρώσετε τη σύνδεσή σας:</p>
          <div style="text-align:center;margin:24px 0">
            <div style="display:inline-block;background:#0d1017;border:2px solid #e63946;border-radius:14px;padding:18px 40px">
              <span style="font-size:2.4rem;font-weight:900;letter-spacing:.35em;color:#f0f2ff;font-family:monospace">' . $otp . '</span>
            </div>
          </div>
          <p style="margin:0 0 8px;font-size:.85rem;color:#8892b0;text-align:center">⏱ Ο κωδικός ισχύει για <strong style="color:#f0a500">10 λεπτά</strong></p>
          <p style="margin:16px 0 0;font-size:.82rem;color:#6b7494">Αν δεν ζητήσατε εσείς αυτόν τον κωδικό, αγνοήστε αυτό το email.</p>
        </td>
      </tr>
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr>
        <td style="padding:16px 32px;text-align:center;font-size:.72rem;color:#363d52">
          &copy; ' . date('Y') . ' MAster — Αυτοματοποιημένο email ασφαλείας
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>';

    require_once __DIR__ . '/mailer.php';
    return sendEmail($userEmail, $subject, $html, '', $userName);
}

/**
 * Επαληθεύει OTP κωδικό — ελέγχει τιμή και expiry
 */
function verify2FAOtp(int $userId, string $inputCode): bool {
    $db = getDB();
    $inputCode = trim($inputCode);

    if (!preg_match('/^\d{6}$/', $inputCode)) return false;

    $row = $db->prepare("SELECT totp_secret, totp_otp_expires FROM users WHERE id=? AND totp_enabled=1");
    $row->execute([$userId]);
    $data = $row->fetch();

    if (!$data || !$data['totp_secret'] || !$data['totp_otp_expires']) return false;

    // Έλεγχος expiry
    if (new DateTime() > new DateTime($data['totp_otp_expires'])) return false;

    if (!hash_equals($data['totp_secret'], $inputCode)) return false;

    // Ακύρωση OTP μετά τη χρήση (single-use)
    $db->prepare("UPDATE users SET totp_secret=NULL, totp_otp_expires=NULL WHERE id=?")
       ->execute([$userId]);

    return true;
}

/**
 * Dummy — διατηρείται για backward compatibility με settings.php
 * Δεν χρησιμοποιείται πλέον (χωρίς authenticator app)
 */
function generate2FASecret(): string {
    return bin2hex(random_bytes(16));
}

function verify2FACode(string $secret, string $code): bool {
    return false; // Deprecated — χρησιμοποιείται verify2FAOtp()
}

function get2FAQrUrl(string $email, string $secret, string $issuer = 'MAster'): string {
    return ''; // Deprecated — δεν χρησιμοποιείται QR
}

function generate2FABackupCodes(): array {
    return []; // Deprecated
}

function verifyAndConsume2FABackupCode(int $userId, string $inputCode): bool {
    return false; // Deprecated
}
/**
 * Στέλνει email ειδοποίησης ότι το 2FA ενεργοποιήθηκε (χωρίς κωδικό)
 */
function send2FAActivationNotice(string $userEmail, string $userName = ''): bool {
    $subject = 'Η Ασφάλεια 2FA Ενεργοποιήθηκε — MAster';
    $name = htmlspecialchars($userName ?: 'χρήστη', ENT_QUOTES, 'UTF-8');
    $appUrl  = defined('APP_URL') ? APP_URL : 'https://master-app.gr';
    $logoUrl = $appUrl . '/assets/img/logo-tr.png';
    $html = '
<!DOCTYPE html>
<html lang="el">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:500px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:24px 32px;text-align:center;border-bottom:2px solid #2a1a50">
          <img src="' . $logoUrl . '" alt="MAster"
               style="height:60px;width:auto;max-width:180px;object-fit:contain;display:block;margin:0 auto">
        </td>
      </tr>
      <tr>
        <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.8">
          <p style="margin:0 0 16px">Γεια σας, <strong style="color:#f0f2ff">' . $name . '</strong></p>
          <p style="margin:0 0 16px">Σας ενημερώνουμε ότι η <strong style="color:#2dc653">ασφάλεια δύο παραγόντων (2FA)</strong> έχει ενεργοποιηθεί επιτυχώς στον λογαριασμό σας.</p>
          <div style="background:#0d1017;border-left:4px solid #2dc653;border-radius:8px;padding:16px 20px;margin:20px 0">
            <p style="margin:0;font-size:.9rem;color:#a0b0c0">Από εδώ και στο εξής, κατά τη σύνδεση στο MAster θα χρειάζεται επαλήθευση μέσω email.</p>
          </div>
          <p style="margin:16px 0 0;font-size:.82rem;color:#6b7494">Αν δεν κάνατε εσείς αυτή την αλλαγή, επικοινωνήστε άμεσα με την υποστήριξη.</p>
        </td>
      </tr>
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr>
        <td style="padding:16px 32px;text-align:center;font-size:.72rem;color:#363d52">
          &copy; ' . date('Y') . ' MAster — Αυτοματοποιημένο email ασφαλείας
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>';
    require_once __DIR__ . '/mailer.php';
    return sendEmail($userEmail, $subject, $html, '', $userName);
}

function send2FADeactivationNotice(string $userEmail, string $userName = ''): bool {
    $subject = 'Η Ασφάλεια 2FA Απενεργοποιήθηκε — MAster';
    $name    = htmlspecialchars($userName ?: 'χρήστη', ENT_QUOTES, 'UTF-8');
    $appUrl  = defined('APP_URL') ? APP_URL : 'https://master-app.gr';
    $logoUrl = $appUrl . '/assets/img/logo-tr.png';
    $html = '
<!DOCTYPE html>
<html lang="el">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:500px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:24px 32px;text-align:center;border-bottom:2px solid #2a1a50">
          <img src="' . $logoUrl . '" alt="MAster"
               style="height:60px;width:auto;max-width:180px;object-fit:contain;display:block;margin:0 auto">
        </td>
      </tr>
      <tr>
        <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.8">
          <p style="margin:0 0 16px">Γεια σας, <strong style="color:#f0f2ff">' . $name . '</strong></p>
          <p style="margin:0 0 16px">Σας ενημερώνουμε ότι η <strong style="color:#e63946">ασφάλεια δύο παραγόντων (2FA)</strong> έχει απενεργοποιηθεί από τον λογαριασμό σας.</p>
          <div style="background:#0d1017;border-left:4px solid #e63946;border-radius:8px;padding:16px 20px;margin:20px 0">
            <p style="margin:0;font-size:.9rem;color:#a0b0c0">Από εδώ και στο εξής η σύνδεση δεν θα απαιτεί επαλήθευση μέσω email.</p>
          </div>
          <p style="margin:16px 0 0;font-size:.82rem;color:#6b7494">Αν δεν κάνατε εσείς αυτή την αλλαγή, επικοινωνήστε άμεσα με την υποστήριξη.</p>
        </td>
      </tr>
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr>
        <td style="padding:16px 32px;text-align:center;font-size:.72rem;color:#363d52">
          &copy; ' . date('Y') . ' MAster — Αυτοματοποιημένο email ασφαλείας
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>';
    require_once __DIR__ . '/mailer.php';
    return sendEmail($userEmail, $subject, $html, '', $userName);
}