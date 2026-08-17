<?php
/**
 * events/og.php — Dynamic Open Graph image (1200×630 PNG) per event
 * URL: /events/og.php?slug=<slug>
 * Uses GD (bundled with most PHP builds) — no external deps.
 * Falls back to text-only card if a font file is missing.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

$slug = trim($_GET['slug'] ?? '');
$ev = $slug ? eventGetBySlug($slug) : null;
if (!$ev || $ev['visibility'] === 'invite_only') {
    http_response_code(404); exit;
}

// Cache: 6 hours in the browser, immutable at the CDN
$etag = 'W/"og-' . md5($ev['id'] . '|' . $ev['updated_at']) . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Type: image/png');
header('Cache-Control: public, max-age=21600');
header('ETag: ' . $etag);

if (!function_exists('imagecreatetruecolor')) {
    // GD missing → 1×1 transparent placeholder
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}

// ── Canvas ────────────────────────────────────────────
$W = 1200; $H = 630;
$im = imagecreatetruecolor($W, $H);

// gradient background (top-left → bottom-right)
for ($y = 0; $y < $H; $y++) {
    $t = $y / $H;
    $r = (int)(13  + (26  - 13)  * $t);
    $g = (int)(13  + (13  - 13)  * $t);
    $b = (int)(26  + (64  - 26)  * $t);
    $c = imagecolorallocate($im, $r, $g, $b);
    imageline($im, 0, $y, $W, $y, $c);
}

// Red accent bar on top
$red = imagecolorallocate($im, 230, 57, 70);
imagefilledrectangle($im, 0, 0, $W, 12, $red);
imagefilledrectangle($im, 0, $H - 12, $W, $H, $red);

// Vertical red stripe left
imagefilledrectangle($im, 0, 12, 20, $H - 12, $red);

$white  = imagecolorallocate($im, 240, 242, 255);
$muted  = imagecolorallocate($im, 136, 146, 176);
$accent = imagecolorallocate($im, 240, 165, 0);

// Try to find any TTF font bundled or system
$fonts = [
    __DIR__ . '/../assets/fonts/DMSans-Bold.ttf',
    __DIR__ . '/../assets/fonts/dm-sans.ttf',
    'C:/Windows/Fonts/arial.ttf',
    'C:/Windows/Fonts/arialbd.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVu-Sans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
];
$font = null;
foreach ($fonts as $f) if (is_file($f)) { $font = $f; break; }

// Text helpers
$typeLabel  = mb_strtoupper(eventTypeLabel($ev['type']), 'UTF-8');
$title      = $ev['title'];
$subtitle   = $ev['subtitle'] ?? '';
$dateVenue  = trim(($ev['starts_at'] ? formatDate(substr($ev['starts_at'], 0, 10)) : '') . ($ev['venue_name'] ? '  ·  ' . $ev['venue_name'] : ''));
$brand      = 'MAster';

// Fetch organiser
$on = getDB()->prepare("SELECT name FROM schools WHERE id = ?");
$on->execute([$ev['organiser_school_id']]);
$org = $on->fetchColumn() ?: '';

if ($font) {
    // TTF-rendered — real typography
    imagettftext($im, 20, 0,  70, 110, $accent, $font, $typeLabel);
    // Word-wrap title to ≤2 lines
    $lines = ogWrapText($font, 56, 1020, $title);
    $y = 200;
    foreach (array_slice($lines, 0, 2) as $line) {
        imagettftext($im, 56, 0, 70, $y, $white, $font, $line);
        $y += 80;
    }
    if ($subtitle) {
        $subLines = ogWrapText($font, 26, 1020, $subtitle);
        imagettftext($im, 26, 0, 70, $y + 10, $muted, $font, $subLines[0]);
    }
    if ($org) imagettftext($im, 22, 0, 70, $H - 130, $muted, $font, 'από ' . $org);
    if ($dateVenue) imagettftext($im, 22, 0, 70, $H - 90, $white, $font, $dateVenue);
    imagettftext($im, 34, 0, $W - 220, $H - 60, $red, $font, $brand);
} else {
    // Fallback: GD built-in bitmap font (no accents)
    $safeTitle = strtoupper(preg_replace('/[^\x20-\x7E]/', '', $title));
    imagestring($im, 5, 70, 100, $typeLabel, $accent);
    imagestring($im, 5, 70, 200, substr($safeTitle, 0, 40), $white);
    imagestring($im, 5, 70, 260, substr($safeTitle, 40, 40), $white);
    if ($dateVenue) imagestring($im, 4, 70, $H - 100, substr($dateVenue, 0, 80), $white);
    imagestring($im, 5, $W - 150, $H - 60, $brand, $red);
}

imagepng($im, null, 6);
imagedestroy($im);
exit;

function ogWrapText(string $font, int $size, int $maxWidth, string $text): array {
    $words = preg_split('/\s+/', trim($text));
    $lines = []; $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : $cur . ' ' . $w;
        $bbox = imagettfbbox($size, 0, $font, $try);
        $width = abs($bbox[2] - $bbox[0]);
        if ($width > $maxWidth && $cur !== '') { $lines[] = $cur; $cur = $w; }
        else { $cur = $try; }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}
