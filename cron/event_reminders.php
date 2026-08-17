#!/usr/bin/env php
<?php
/**
 * ============================================================
 * cron/event_reminders.php — Event-related notifications
 * ============================================================
 * Fires:
 *   1. event_starts_in_24h    → registered clubs (once per event)
 *   2. match_in_60min         → athlete's parent + club owner (once per match)
 *   3. match_in_15min         → athlete's parent + club owner (once per match)
 *   4. event_update_delivery  → email to all event_followers of new updates
 *
 * Run every 5 minutes:  * / 5 * * * *   php /path/to/cron/event_reminders.php
 * Or with token via web (defense in depth): ?token=CRON_SECRET
 * ============================================================
 */

$allowInternalWebRun = defined('INTERNAL_CRON_ALLOWED') && INTERNAL_CRON_ALLOWED === true;
if (PHP_SAPI !== 'cli' && !$allowInternalWebRun) {
    $cronSecret = defined('CRON_SECRET') ? CRON_SECRET : '';
    if (!$cronSecret || ($_GET['token'] ?? '') !== $cronSecret) {
        http_response_code(403); die('Unauthorized');
    }
}

define('RUNNING_AS_CRON', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';
require_once __DIR__ . '/../includes/mailer.php';

$db = getDB();
function evLog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    error_log('[MAster event-reminders] ' . $msg);
}

evLog('═══ event-reminders START ═══');

$stats = ['starts_24h' => 0, 'match_60' => 0, 'match_15' => 0, 'update_deliv' => 0];

// ─────────────────────────────────────────────────────────────
// 1. event_starts_in_24h — email participants that their event is tomorrow
// ─────────────────────────────────────────────────────────────
try {
    $events = $db->query("
        SELECT * FROM events
        WHERE status IN ('open','in_progress')
          AND starts_at IS NOT NULL
          AND starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
    ")->fetchAll();

    foreach ($events as $ev) {
        // De-dup via cron_runs message tag
        $tag = "starts_24h:{$ev['id']}";
        $dup = $db->prepare("SELECT 1 FROM cron_runs WHERE job_name='event_reminders' AND message = ? LIMIT 1");
        $dup->execute([$tag]);
        if ($dup->fetchColumn()) continue;

        // Gather all registered clubs' contact emails
        $regs = $db->prepare("
            SELECT DISTINCT s.email, s.name
            FROM event_registrations r
            JOIN schools s ON s.id = r.registering_school_id
            WHERE r.event_id = ? AND r.status IN ('approved','pending','checked_in') AND s.email IS NOT NULL AND s.email <> ''
        ");
        $regs->execute([(int)$ev['id']]);
        $recipients = $regs->fetchAll();

        foreach ($recipients as $r) {
            eventNotify('event_starts_soon', $ev, [
                'to_email' => $r['email'],
                'to_name'  => $r['name'],
                'body'     => "Το event «{$ev['title']}» ξεκινά σε λιγότερο από 24 ώρες.\n\nΤοποθεσία: " . ($ev['venue_name'] ?: '—') . "\nΈναρξη: " . date('d/m/Y H:i', strtotime($ev['starts_at'])),
            ]);
            $stats['starts_24h']++;
        }

        // record we sent
        $db->prepare("INSERT INTO cron_runs (job_name, started_at, finished_at, status, message)
                      VALUES ('event_reminders', NOW(), NOW(), 'success', ?)")->execute([$tag]);
        evLog("  starts_24h → event #{$ev['id']} ({$ev['title']}) — " . count($recipients) . " emails");
    }
} catch (Throwable $e) { evLog('starts_24h error: ' . $e->getMessage()); }

// ─────────────────────────────────────────────────────────────
// 2 + 3. match_in_60 / match_in_15
// ─────────────────────────────────────────────────────────────
function fireMatchReminder(PDO $db, string $kind, int $minutes, array &$stats): void {
    $matches = $db->prepare("
        SELECT m.*, e.title AS event_title, e.slug,
               ra.full_name AS red_name, rs.email AS red_school_email, rs.name AS red_school_name,
               ba.full_name AS blue_name, bs.email AS blue_school_email, bs.name AS blue_school_name,
               c.name AS cat_name
        FROM event_matches m
        JOIN events e ON e.id = m.event_id
        LEFT JOIN event_categories c ON c.id = m.category_id
        LEFT JOIN event_registrations rr ON rr.id = m.red_registration_id
        LEFT JOIN athletes ra ON ra.id = rr.athlete_id
        LEFT JOIN schools rs  ON rs.id = rr.registering_school_id
        LEFT JOIN event_registrations br ON br.id = m.blue_registration_id
        LEFT JOIN athletes ba ON ba.id = br.athlete_id
        LEFT JOIN schools bs  ON bs.id = br.registering_school_id
        WHERE m.status IN ('scheduled','live')
          AND m.scheduled_at IS NOT NULL
          AND m.scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? MINUTE)
          AND NOT EXISTS (SELECT 1 FROM event_match_reminders r WHERE r.match_id = m.id AND r.kind = ?)
    ");
    $matches->execute([$minutes, $kind]);
    $rows = $matches->fetchAll();

    $mark = $db->prepare("INSERT IGNORE INTO event_match_reminders (match_id, kind) VALUES (?, ?)");
    foreach ($rows as $m) {
        $when = date('H:i', strtotime($m['scheduled_at']));
        $body = "Αγώνας σε $minutes λεπτά στο τερέν {$m['ring_number']} ($when).\n\n"
              . "Κατηγορία: {$m['cat_name']}\n"
              . "Γύρος: " . ($m['round_label'] ?: '—') . "\n"
              . "🟥 " . ($m['red_name'] ?: '—') . " ({$m['red_school_name']})\n"
              . "🟦 " . ($m['blue_name'] ?: '—') . " ({$m['blue_school_name']})";

        $ev = ['id' => $m['event_id'], 'title' => $m['event_title'], 'slug' => $m['slug']];
        foreach ([$m['red_school_email'], $m['blue_school_email']] as $to) {
            if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                eventNotify('match_soon', $ev, ['to_email' => $to, 'body' => $body]);
                $stats[$kind === 't_minus_60' ? 'match_60' : 'match_15']++;
            }
        }
        $mark->execute([(int)$m['id'], $kind]);
    }
}

try { fireMatchReminder($db, 't_minus_60', 60, $stats); } catch (Throwable $e) { evLog('t_60 error: ' . $e->getMessage()); }
try { fireMatchReminder($db, 't_minus_15', 15, $stats); } catch (Throwable $e) { evLog('t_15 error: ' . $e->getMessage()); }

// ─────────────────────────────────────────────────────────────
// 4. event_updates fanout → event_followers
// ─────────────────────────────────────────────────────────────
try {
    $pending = $db->query("
        SELECT u.id AS update_id, u.event_id, u.title, u.body_md, u.published_at,
               e.title AS event_title, e.slug
        FROM event_updates u
        JOIN events e ON e.id = u.event_id
        WHERE u.published_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
    ")->fetchAll();

    $fetchFollowers = $db->prepare("
        SELECT f.id, COALESCE(f.email, u.email, pu.parent_email) AS to_email,
               COALESCE(u.name, pu.parent_name) AS to_name
        FROM event_followers f
        LEFT JOIN users u ON u.id = f.user_id
        LEFT JOIN parent_users pu ON pu.id = f.parent_user_id
        WHERE f.event_id = ? AND f.channel = 'email'
    ");
    $delivChk  = $db->prepare("SELECT 1 FROM event_update_deliveries WHERE update_id = ? AND follower_id = ? LIMIT 1");
    $delivMark = $db->prepare("INSERT IGNORE INTO event_update_deliveries (update_id, follower_id, status) VALUES (?,?,?)");

    foreach ($pending as $u) {
        $fetchFollowers->execute([(int)$u['event_id']]);
        $followers = $fetchFollowers->fetchAll();
        foreach ($followers as $f) {
            if (!$f['to_email'] || !filter_var($f['to_email'], FILTER_VALIDATE_EMAIL)) continue;
            $delivChk->execute([(int)$u['update_id'], (int)$f['id']]);
            if ($delivChk->fetchColumn()) continue;

            $ev = ['id' => $u['event_id'], 'title' => $u['event_title'], 'slug' => $u['slug']];
            eventNotify('event_update', $ev, [
                'to_email' => $f['to_email'],
                'to_name'  => $f['to_name'] ?? '',
                'body'     => $u['title'] . "\n\n" . $u['body_md'],
            ]);
            $delivMark->execute([(int)$u['update_id'], (int)$f['id'], 'sent']);
            $stats['update_deliv']++;
        }
    }
} catch (Throwable $e) { evLog('updates fanout error: ' . $e->getMessage()); }

evLog('═══ DONE ═══ ' . json_encode($stats));
