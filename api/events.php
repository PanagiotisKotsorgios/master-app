<?php
/**
 * api/events.php — Public JSON API for events
 * ============================================================
 * Actions (via ?action= or path-style ?slug=):
 *   GET /api/events.php                         → list (filters: q, sport, type, upcoming, limit, offset)
 *   GET /api/events.php?slug=<slug>             → single event detail + categories
 *   GET /api/events.php?slug=<slug>&participants=1 → participants (privacy-respecting)
 *   GET /api/events.php?slug=<slug>&brackets=<cat_id> → bracket data for a category
 *   GET /api/events.php?slug=<slug>&results=1   → published results
 *
 * All responses JSON. Public — no auth required.
 * CORS: same-origin only unless the caller sends Origin from a whitelisted domain.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events_bracket.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');   // 1-minute edge cache
header('X-Content-Type-Options: nosniff');

// Basic CORS (readonly public data): allow all, but only GET
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

function apiEventShape(array $ev): array {
    return [
        'id'         => (int)$ev['id'],
        'slug'       => $ev['slug'],
        'title'      => $ev['title'],
        'subtitle'   => $ev['subtitle'],
        'type'       => $ev['type'],
        'type_label' => eventTypeLabel($ev['type']),
        'status'     => $ev['status'],
        'visibility' => $ev['visibility'],
        'sport'      => $ev['sport'],
        'sport_style'=> $ev['sport_style'],
        'venue'      => [
            'name'    => $ev['venue_name'],
            'address' => $ev['venue_address'],
            'url'     => $ev['venue_url'],
        ],
        'starts_at'                => $ev['starts_at'],
        'ends_at'                  => $ev['ends_at'],
        'registration_opens_at'    => $ev['registration_opens_at'],
        'registration_closes_at'   => $ev['registration_closes_at'],
        'fee_model'                => $ev['fee_model'],
        'fee_amount'               => (float)$ev['fee_amount'],
        'currency'                 => $ev['currency'],
        'payment_methods'          => explode(',', $ev['payment_methods'] ?? ''),
        'organiser_name'           => $ev['organiser_name'] ?? null,
        'url'                      => eventPublicUrl($ev),
    ];
}

try {
    $slug = trim($_GET['slug'] ?? '');

    // ── LIST ─────────────────────────────────────────────
    if (!$slug) {
        $filters = ['upcoming' => 1];
        foreach (['q','sport','type'] as $k) if (!empty($_GET[$k])) $filters[$k] = trim($_GET[$k]);
        $limit  = max(1, min(100, (int)($_GET['limit']  ?? 40)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $rows = eventsPublicSearch($filters, $limit, $offset);
        $out  = array_map('apiEventShape', $rows);

        echo json_encode([
            'ok'    => true,
            'total' => eventsPublicCount($filters),
            'limit' => $limit,
            'offset'=> $offset,
            'data'  => $out,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── SINGLE / SUB-RESOURCES ───────────────────────────
    $ev = eventGetBySlug($slug);
    if (!$ev) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
    if ($ev['visibility'] === 'invite_only') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'private']); exit; }

    // Attach organiser name
    $on = getDB()->prepare("SELECT name FROM schools WHERE id = ?");
    $on->execute([$ev['organiser_school_id']]);
    $ev['organiser_name'] = $on->fetchColumn() ?: null;

    // Participants
    if (!empty($_GET['participants'])) {
        $rows = eventPublicParticipants((int)$ev['id']);
        echo json_encode(['ok'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Brackets for a specific category
    if (!empty($_GET['brackets'])) {
        $catId = (int)$_GET['brackets'];
        $matches = bracketFull((int)$ev['id'], $catId);
        $out = [];
        foreach ($matches as $m) {
            $out[] = [
                'id'            => (int)$m['id'],
                'pool_id'       => $m['pool_id'] ? (int)$m['pool_id'] : null,
                'round_label'   => $m['round_label'],
                'position'      => (int)$m['bracket_position'],
                'ring'          => (int)$m['ring_number'],
                'scheduled_at'  => $m['scheduled_at'],
                'status'        => $m['status'],
                'result_type'   => $m['result_type'],
                'red'  => $m['red_name']  ? ['name'=>$m['red_name'],  'school'=>$m['red_school'],  'score'=>(int)$m['red_score']]  : null,
                'blue' => $m['blue_name'] ? ['name'=>$m['blue_name'], 'school'=>$m['blue_school'], 'score'=>(int)$m['blue_score']] : null,
                'winner_registration_id' => $m['winner_registration_id'] ? (int)$m['winner_registration_id'] : null,
            ];
        }
        echo json_encode(['ok'=>true,'data'=>$out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Results
    if (!empty($_GET['results'])) {
        $rows = bracketResultsFor((int)$ev['id']);
        echo json_encode(['ok'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Default: event detail + categories
    $cats = eventCategories((int)$ev['id']);
    $catsOut = [];
    foreach ($cats as $c) {
        $catsOut[] = [
            'id'         => (int)$c['id'],
            'name'       => $c['name'],
            'gender'     => $c['gender'],
            'min_age'    => $c['min_age'] !== null ? (int)$c['min_age'] : null,
            'max_age'    => $c['max_age'] !== null ? (int)$c['max_age'] : null,
            'min_weight' => $c['min_weight'] !== null ? (float)$c['min_weight'] : null,
            'max_weight' => $c['max_weight'] !== null ? (float)$c['max_weight'] : null,
            'format'     => $c['format'],
            'fee'        => $c['fee_override'] !== null ? (float)$c['fee_override'] : null,
        ];
    }

    echo json_encode([
        'ok'         => true,
        'data'       => apiEventShape($ev),
        'categories' => $catsOut,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[api/events] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'internal']);
}
