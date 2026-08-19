<?php
/**
 * api/newsletter_subscribe.php — POST endpoint for the landing
 * page newsletter opt-in form.
 *
 * Accepts: email (required), name (optional).
 * Returns: JSON { ok: bool, message: string }.
 *
 * Behavior:
 *   • Sanitises + validates email.
 *   • If the email exists and is 'unsubscribed', reactivates it.
 *   • If it exists and is active, treats as success (idempotent).
 *   • Otherwise inserts a new row with an unsubscribe token.
 *   • Best-effort welcome email via sendEmail() when available.
 *   • Rate-limited by IP: max 6 requests / 10 minutes.
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF is optional on public marketing forms — but honor it if the
// caller sent one, so the same endpoint works from authenticated
// contexts too.
if (!empty($_POST['csrf_token'])) {
    if (($_SESSION['csrf_token'] ?? '') !== $_POST['csrf_token']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Security check failed.']);
        exit;
    }
}

// ── Rate limit ───────────────────────────────────
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
   ?? $_SERVER['HTTP_X_FORWARDED_FOR']
   ?? $_SERVER['REMOTE_ADDR']
   ?? '0.0.0.0';
$ip = trim(explode(',', $ip)[0]);
$ip = substr($ip, 0, 45);

try {
    $db = getDB();
    // Ensure table exists even if the SQL migration hasn't been run yet
    $db->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        name VARCHAR(120) NULL,
        status ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
        source VARCHAR(60) NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        consent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        unsubscribe_token CHAR(48) NOT NULL,
        unsubscribed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_email (email),
        UNIQUE KEY uk_token (unsubscribe_token),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Simple per-IP rate limit using the same table
    $rl = $db->prepare("SELECT COUNT(*) FROM newsletter_subscribers
                        WHERE ip = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
    $rl->execute([$ip]);
    if ((int)$rl->fetchColumn() >= 6) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'message' => 'Πολλά αιτήματα. Δοκιμάστε ξανά σε λίγα λεπτά.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('[MAster newsletter] init failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Προσωρινό πρόβλημα. Δοκιμάστε ξανά.']);
    exit;
}

// ── Inputs ───────────────────────────────────────
$email = sanitizeEmail($_POST['email'] ?? '');
$name  = sanitizeString($_POST['name'] ?? '');
$name  = mb_substr($name, 0, 120);

if (!$email) {
    echo json_encode(['ok' => false, 'message' => 'Παρακαλώ εισάγετε έγκυρη διεύθυνση email.']);
    exit;
}

// ── Insert / reactivate ──────────────────────────
try {
    $existing = $db->prepare("SELECT id, status, unsubscribe_token FROM newsletter_subscribers WHERE email = ? LIMIT 1");
    $existing->execute([$email]);
    $row = $existing->fetch();

    if ($row) {
        if ($row['status'] === 'active') {
            echo json_encode(['ok' => true, 'message' => 'Είστε ήδη εγγεγραμμένος. Ευχαριστούμε!']);
            exit;
        }
        // reactivate
        $db->prepare("UPDATE newsletter_subscribers
                         SET status = 'active',
                             unsubscribed_at = NULL,
                             name = COALESCE(NULLIF(?, ''), name),
                             ip = ?,
                             user_agent = ?,
                             consent_at = NOW()
                       WHERE id = ?")
           ->execute([$name, $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), (int)$row['id']]);
        $token = $row['unsubscribe_token'];
    } else {
        $token = bin2hex(random_bytes(24));
        $db->prepare("INSERT INTO newsletter_subscribers
                        (email, name, status, source, ip, user_agent, unsubscribe_token)
                      VALUES (?, ?, 'active', 'landing_footer', ?, ?, ?)")
           ->execute([$email, $name, $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $token]);
    }

    // Best-effort welcome mail — never blocks the response
    if (function_exists('sendEmail')) {
        $unsubUrl = rtrim(APP_URL, '/') . '/unsubscribe.php?t=' . urlencode($token);
        $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f5f9;padding:24px">'
              . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;padding:28px 32px">'
              . '<h2 style="margin:0 0 8px;color:#111827">Καλωσήρθατε στο MAster!</h2>'
              . '<p style="color:#374151;line-height:1.55">Εγγραφήκατε στο newsletter μας. Θα λαμβάνετε ενημερώσεις για νέα events, features και ανακοινώσεις.</p>'
              . '<p style="color:#6b7280;font-size:.85em;margin-top:22px">Δεν εγγραφήκατε εσείς; '
              . '<a href="' . htmlspecialchars($unsubUrl, ENT_QUOTES) . '" style="color:#e63946">Διαγραφή</a>.</p>'
              . '</div></body></html>';
        @sendEmail($email, 'Καλωσήρθατε στο MAster newsletter', $html, 'Καλωσήρθατε στο MAster newsletter. Διαγραφή: ' . $unsubUrl);
    }

    echo json_encode(['ok' => true, 'message' => 'Ευχαριστούμε για την εγγραφή!']);
} catch (Throwable $e) {
    error_log('[MAster newsletter] subscribe failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Προέκυψε πρόβλημα. Δοκιμάστε ξανά.']);
}
