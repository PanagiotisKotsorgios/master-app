<?php
/**
 * events/ics.php — Emit an .ics VCALENDAR file for one event.
 * ----------------------------------------------------------------
 * URL: /events/ics.php?slug=SLUG
 * Public + unlisted events only. Adds start/end/venue/description
 * plus the public URL so the invitee can click through.
 * ----------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

$slug = trim($_GET['slug'] ?? '');
$ev   = $slug ? eventGetBySlug($slug) : null;

if (!$ev || !in_array($ev['visibility'], ['public', 'unlisted'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Event not found.";
    exit;
}

// Helpers -------------------------------------------------------
function icsEscape(string $s): string {
    $s = str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], $s);
    return $s;
}
function icsDate(?string $mysqlDt): string {
    if (!$mysqlDt) return gmdate('Ymd\THis\Z');
    $t = strtotime($mysqlDt);
    return gmdate('Ymd\THis\Z', $t);
}
function icsFold(string $line): string {
    // RFC 5545 line folding — 75 octet limit
    if (strlen($line) <= 75) return $line;
    $out = '';
    while (strlen($line) > 75) {
        $out .= substr($line, 0, 75) . "\r\n ";
        $line = substr($line, 75);
    }
    return $out . $line;
}

$uid       = 'event-' . $ev['id'] . '@master-app.gr';
$now       = gmdate('Ymd\THis\Z');
$dtStart   = icsDate($ev['starts_at'] ?? null);
$dtEnd     = icsDate($ev['ends_at'] ?? $ev['starts_at'] ?? null);
$title     = icsEscape($ev['title'] ?? 'Event');
$venue     = trim(implode(', ', array_filter([$ev['venue_name'] ?? '', $ev['venue_address'] ?? ''])));
$location  = icsEscape($venue);
$descLines = array_filter([
    $ev['subtitle'] ?? '',
    trim(strip_tags($ev['description'] ?? '')),
    'Σύνδεσμος: ' . eventPublicUrl($ev),
]);
$description = icsEscape(implode("\n\n", $descLines));
$url         = icsEscape(eventPublicUrl($ev));

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//MAster//Events//EL',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' . $uid,
    'DTSTAMP:' . $now,
    'DTSTART:' . $dtStart,
    'DTEND:' . $dtEnd,
    'SUMMARY:' . $title,
    'DESCRIPTION:' . $description,
    'URL:' . $url,
];
if ($location !== '') $lines[] = 'LOCATION:' . $location;
$lines[] = 'END:VEVENT';
$lines[] = 'END:VCALENDAR';

$body = '';
foreach ($lines as $l) $body .= icsFold($l) . "\r\n";

$fname = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $ev['slug'] ?? ('event-' . $ev['id'])) . '.ics';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $body;
