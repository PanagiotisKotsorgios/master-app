<?php
/**
 * api/athletes_search.php — Public athlete → upcoming events search
 * ============================================================
 * GET /api/athletes_search.php?q=<name>
 *
 * Only lists athletes registered to PUBLIC events with show_public=1
 * AND from the organiser's public participant view (respects minor privacy).
 * Returns aggregated: athlete + list of events they'll appear in.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=120');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit;
}

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q, 'UTF-8') < 2) {
    echo json_encode(['ok'=>true,'data'=>[],'note'=>'q must be ≥ 2 chars']);
    exit;
}

try {
    $db = getDB();
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';

    $sql = "
        SELECT r.id AS reg_id, r.athlete_id, a.full_name, a.birthdate,
               e.id AS event_id, e.slug, e.title AS event_title,
               e.starts_at, e.venue_name, e.type,
               c.name AS cat_name,
               s.name AS school_name
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        JOIN athletes a ON a.id = r.athlete_id
        LEFT JOIN event_categories c ON c.id = r.category_id
        LEFT JOIN schools s ON s.id = r.registering_school_id
        WHERE e.visibility = 'public'
          AND e.status IN ('open','in_progress','completed')
          AND r.status NOT IN ('rejected','withdrawn')
          AND r.show_public = 1
          AND a.full_name LIKE ?
        ORDER BY e.starts_at DESC
        LIMIT 100
    ";
    $st = $db->prepare($sql);
    $st->execute([$like]);
    $rows = $st->fetchAll();

    // Group by athlete_id and apply minor privacy
    $byAthlete = [];
    foreach ($rows as $r) {
        $name = $r['full_name'];
        // Minor-safe: first name + last initial if under 18
        if (!empty($r['birthdate']) && $r['birthdate'] !== '0000-00-00') {
            try {
                $age = (new DateTime())->diff(new DateTime($r['birthdate']))->y;
                if ($age < 18) {
                    $p = preg_split('/\s+/', trim($name));
                    if (count($p) >= 2) $name = $p[0] . ' ' . mb_substr(end($p), 0, 1, 'UTF-8') . '.';
                }
            } catch (Exception $e) {}
        }
        $key = (int)$r['athlete_id'];
        if (!isset($byAthlete[$key])) {
            $byAthlete[$key] = [
                'athlete_id' => $key,
                'name'       => $name,
                'club'       => $r['school_name'],
                'events'     => [],
            ];
        }
        $byAthlete[$key]['events'][] = [
            'event_id'  => (int)$r['event_id'],
            'slug'      => $r['slug'],
            'title'     => $r['event_title'],
            'type'      => $r['type'],
            'starts_at' => $r['starts_at'],
            'venue'     => $r['venue_name'],
            'category'  => $r['cat_name'],
            'url'       => rtrim(APP_URL, '/') . '/events/view.php?slug=' . urlencode($r['slug']),
        ];
    }

    echo json_encode(['ok'=>true,'data'=>array_values($byAthlete)], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[api/athletes_search] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'internal']);
}
