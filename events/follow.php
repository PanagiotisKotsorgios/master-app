<?php
/**
 * events/follow.php — One-click event subscribe (parents / visitors / logged-in users)
 * ============================================================
 * POST /events/follow.php   (form or fetch)
 *   { slug, email, channel=email|push, csrf_token }
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST only'); }

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
$respond = function(bool $ok, string $msg) use ($isAjax) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>$ok,'msg'=>$msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash($msg, $ok ? 'success' : 'error');
    redirect($_SERVER['HTTP_REFERER'] ?? APP_URL . '/events/');
};

try {
    verifyCsrf();
    $slug    = trim($_POST['slug'] ?? '');
    $email   = filter_var(strtolower(trim($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
    $channel = in_array($_POST['channel'] ?? '', ['email','push'], true) ? $_POST['channel'] : 'email';

    $ev = $slug ? eventGetBySlug($slug) : null;
    if (!$ev) throw new RuntimeException('Το event δεν βρέθηκε.');
    if ($ev['visibility'] === 'invite_only') throw new RuntimeException('Ιδιωτικό event.');

    $db = getDB();
    $userId       = isLoggedIn() && !isParentSession() ? userId() : null;
    $parentUserId = isParentSession() ? userId() : null;

    if (!$userId && !$parentUserId) {
        if (!$email) throw new RuntimeException('Απαιτείται email.');
    }

    // Try insert; unique keys make dupes a no-op
    $st = $db->prepare("INSERT IGNORE INTO event_followers (event_id, user_id, parent_user_id, email, channel) VALUES (?,?,?,?,?)");
    $st->execute([(int)$ev['id'], $userId, $parentUserId, $email ?: null, $channel]);

    $respond(true, 'Θα σας ειδοποιήσουμε για ενημερώσεις του event.');
} catch (Throwable $e) {
    $respond(false, 'Σφάλμα: ' . $e->getMessage());
}
