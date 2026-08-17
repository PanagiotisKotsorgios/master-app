<?php
/**
 * ============================================================
 * api/belts.php — REST API: Ζώνες ανά Άθλημα
 * ============================================================
 * PURPOSE:
 *   Επιστρέφει JSON array με τις ζώνες του επιλεγμένου
 *   αθλήματος. Χρησιμοποιείται από το frontend (athletes.php)
 *   για δυναμικό populate του belt dropdown όταν αλλάζει
 *   το sport select.
 *
 * ENDPOINT:
 *   GET /api/belts.php?sport=taekwondo
 *
 * PARAMETERS:
 *   sport (string) — taekwondo|karate|kickboxing|boxing|mma|bjj|other
 *                    Default: 'taekwondo' αν λείπει ή invalid
 *
 * RESPONSE:
 *   Content-Type: application/json
 *   200 OK: ["Λευκή","Κίτρινη","Πράσινη",...] ή [] για sports χωρίς ζώνες
 *
 * SECURITY:
 *   - requireLogin(): μόνο authenticated users (αποτρέπει enumeration)
 *   - ALLOWED_SPORTS whitelist: αποτρέπει arbitrary input
 *   - JSON output: δεν υπάρχει HTML rendering, XSS δεν εφαρμόζεται
 *   - Rate limit: κληρονομείται από session (δεν χρειάζεται extra)
 *
 * CALLED BY:
 *   pages/athletes.php — inline <script> sport change handler
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';

// ── Authentication: μόνο logged-in users ────────────────────
// Αποτρέπει unauthorized enumeration του API
requireLogin();

// ── Response headers ─────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// Απαγορεύει caching — τα belt lists είναι static αλλά
// αποφεύγουμε stale data αν αλλάξουν τα plans
header('Cache-Control: no-cache, no-store');

// ── Input validation ─────────────────────────────────────────
// Whitelist: δεχόμαστε ΜΟΝΟ αυτά τα sports
// Αποτρέπει injection ή unexpected behavior από arbitrary input
const ALLOWED_SPORTS = ['taekwondo', 'karate', 'kickboxing', 'boxing', 'mma', 'bjj', 'other'];

$sport = trim(strtolower($_GET['sport'] ?? ''));

// Αν το sport δεν είναι στη whitelist → fallback σε taekwondo
// Δεν πετάμε error για UX reasons (dropdown auto-select)
if (!in_array($sport, ALLOWED_SPORTS, true)) {
    $sport = 'taekwondo';
}

// ── Response ─────────────────────────────────────────────────
// getBelts() ορίζεται στο includes/config.php
// Επιστρέφει [] για sports χωρίς belt system (boxing, kickboxing κλπ)
echo json_encode(getBelts($sport), JSON_UNESCAPED_UNICODE);
