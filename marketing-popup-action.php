<?php
/**
 * marketing-popup-action.php
 * ----------------------------------------------------------------
 * POST handler for the admin-configured one-time popup.
 *
 *   popup_id  int
 *   action    'interested' | 'dismissed'
 *   csrf      current session CSRF token
 *
 * On 'interested' → notifies the configured admin email via Brevo.
 * Always records the action so the popup does not reappear.
 * ----------------------------------------------------------------
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

// Accept either the club or parent session
$userId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$submitted = $_POST['csrf'] ?? '';
$expected  = $_SESSION['csrf_token'] ?? '';
if ($expected && !hash_equals((string)$expected, (string)$submitted)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$popupId = (int)($_POST['popup_id'] ?? 0);
$action  = $_POST['action']   ?? '';
if ($popupId <= 0 || !in_array($action, ['interested','dismissed'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_input']);
    exit;
}

$db = getDB();

// Load popup
$pStmt = $db->prepare("SELECT * FROM marketing_popups WHERE id=? LIMIT 1");
$pStmt->execute([$popupId]);
$popup = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$popup) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

// Load user snapshot (parent or club user)
$uName = $_SESSION['user']['name']  ?? '';
$uMail = $_SESSION['user']['email'] ?? '';
$uPhone = '';
$schoolId = (int)($_SESSION['school_id'] ?? 0);
$schoolName = $_SESSION['school_name'] ?? '';

try {
    $uStmt = $db->prepare("SELECT name, email, phone FROM users WHERE id=? LIMIT 1");
    $uStmt->execute([$userId]);
    if ($row = $uStmt->fetch(PDO::FETCH_ASSOC)) {
        $uName  = $row['name']  ?: $uName;
        $uMail  = $row['email'] ?: $uMail;
        $uPhone = $row['phone'] ?? '';
    }
} catch (Throwable $e) { /* phone col may not exist on all schemas */ }

$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
   ?? $_SERVER['HTTP_X_FORWARDED_FOR']
   ?? $_SERVER['REMOTE_ADDR']
   ?? '';
$ip = trim(explode(',', $ip)[0]);
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

// Insert (or update) action row. Ignore duplicates silently.
try {
    $ins = $db->prepare("
        INSERT INTO marketing_popup_actions
              (popup_id, user_id, school_id, action, user_email, user_name, user_phone, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE action = VALUES(action)
    ");
    $ins->execute([
        $popupId, $userId, $schoolId ?: null, $action,
        $uMail ?: null, $uName ?: null, $uPhone ?: null,
        $ip ?: null, $ua ?: null,
    ]);
} catch (Throwable $e) {
    error_log('[MAster mkt_popup] insert failed: ' . $e->getMessage());
    // Continue anyway — email is more important than log row
}

// If interested → notify admin via Brevo
if ($action === 'interested') {
    $notify = trim((string)($popup['notify_email'] ?? ''));
    if ($notify === '') {
        $notify = function_exists('getSetting')
            ? getSetting('marketing_popup_notify_email', getMailFromEmail())
            : (function_exists('getMailFromEmail') ? getMailFromEmail() : '');
    }
    if ($notify && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
        $subject = '[MAster] Νέο ενδιαφέρον: ' . ($popup['title'] ?? 'popup');
        $safeName   = htmlspecialchars($uName   ?: '—', ENT_QUOTES, 'UTF-8');
        $safeMail   = htmlspecialchars($uMail   ?: '—', ENT_QUOTES, 'UTF-8');
        $safePhone  = htmlspecialchars($uPhone  ?: '—', ENT_QUOTES, 'UTF-8');
        $safeSchool = htmlspecialchars($schoolName ?: '—', ENT_QUOTES, 'UTF-8');
        $safeTitle  = htmlspecialchars($popup['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $when       = date('Y-m-d H:i');
        $html = "
          <div style='font-family:Inter,system-ui,sans-serif;color:#0f172a;max-width:560px'>
            <h2 style='color:#e63946;margin:0 0 .5rem'>Νέο ενδιαφέρον από popup</h2>
            <p style='color:#475569;margin:0 0 1rem'>Ένας χρήστης πάτησε <strong>Ενδιαφέρομαι</strong> στο popup «{$safeTitle}».</p>
            <table cellpadding='8' cellspacing='0' style='border-collapse:collapse;font-size:14px;width:100%'>
              <tr><td style='background:#f8fafc;border:1px solid #e2e8f0;width:130px'><strong>Όνομα</strong></td><td style='border:1px solid #e2e8f0'>{$safeName}</td></tr>
              <tr><td style='background:#f8fafc;border:1px solid #e2e8f0'><strong>Email</strong></td><td style='border:1px solid #e2e8f0'><a href='mailto:{$safeMail}'>{$safeMail}</a></td></tr>
              <tr><td style='background:#f8fafc;border:1px solid #e2e8f0'><strong>Τηλέφωνο</strong></td><td style='border:1px solid #e2e8f0'>{$safePhone}</td></tr>
              <tr><td style='background:#f8fafc;border:1px solid #e2e8f0'><strong>Σχολή</strong></td><td style='border:1px solid #e2e8f0'>{$safeSchool}</td></tr>
              <tr><td style='background:#f8fafc;border:1px solid #e2e8f0'><strong>Ημερομηνία</strong></td><td style='border:1px solid #e2e8f0'>{$when}</td></tr>
            </table>
            <p style='color:#94a3b8;font-size:12px;margin-top:1rem'>Το email στάλθηκε αυτόματα από το MAster όταν ο χρήστης πάτησε στο popup.</p>
          </div>";
        $text = "Νέο ενδιαφέρον από popup «{$popup['title']}»\n"
              . "Όνομα: {$uName}\nEmail: {$uMail}\nΤηλέφωνο: {$uPhone}\nΣχολή: {$schoolName}\nΗμ/νία: {$when}\n";
        $dbg = null;
        sendEmail($notify, $subject, $html, $text, 'MAster Admin', $dbg, null, null, $uMail ?: null, $uName ?: null);
    }
}

echo json_encode(['ok' => true]);
