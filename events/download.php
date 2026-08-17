<?php
/**
 * events/download.php — Auth-gated proxy for private event files
 * ============================================================
 * Only serves proof-of-payment / doc uploads to:
 *   • the paying club (their own proof), OR
 *   • the organiser club (any proof for their event), OR
 *   • superadmin.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();

$payId = (int)($_GET['p'] ?? 0);
$docId = (int)($_GET['d'] ?? 0);

$db = getDB();
$path = null;

if ($payId) {
    $st = $db->prepare("SELECT p.proof_file_path, p.paying_school_id, e.organiser_school_id
                        FROM event_payments p
                        JOIN events e ON e.id = p.event_id
                        WHERE p.id = ? LIMIT 1");
    $st->execute([$payId]);
    $row = $st->fetch();
    if (!$row || !$row['proof_file_path']) { http_response_code(404); exit('Not found'); }
    if (!isSuperAdmin()
        && (int)$row['paying_school_id']   !== schoolId()
        && (int)$row['organiser_school_id'] !== schoolId()) {
        http_response_code(403); exit('Forbidden');
    }
    $path = $row['proof_file_path'];
}
elseif ($docId) {
    $st = $db->prepare("SELECT d.file_path, r.registering_school_id, e.organiser_school_id
                        FROM event_registration_docs d
                        JOIN event_registrations r ON r.id = d.registration_id
                        JOIN events e ON e.id = r.event_id
                        WHERE d.id = ? LIMIT 1");
    $st->execute([$docId]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); exit('Not found'); }
    if (!isSuperAdmin()
        && (int)$row['registering_school_id'] !== schoolId()
        && (int)$row['organiser_school_id']   !== schoolId()) {
        http_response_code(403); exit('Forbidden');
    }
    $path = $row['file_path'];
}
else {
    http_response_code(400); exit('Bad request');
}

$abs = eventUploadAbsolute($path);
if (!$abs || !is_file($abs)) { http_response_code(404); exit('File not found on disk'); }

// Content-type sniffing
$mimeGuess = function_exists('mime_content_type') ? @mime_content_type($abs) : null;
$mime = $mimeGuess ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . basename($abs) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($abs);
exit;
