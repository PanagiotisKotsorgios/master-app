#!/usr/bin/env php
<?php
/**
 * cron/monthly_digest.php — Once-a-month summary of automated
 * reminders sent by cron/reminders.php.
 *
 * • Emails each school owner: "Στον μήνα Αύγουστος 2026 στάλθηκαν
 *   Ν emails και Μ SMS από τις αυτόματες υπενθυμίσεις".
 * • Also records into cron_runs so admins can see it happened.
 *
 * Triggered by docker-entrypoint.sh once every calendar month.
 */

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../includes/config.php';
    if (!defined('CRON_SECRET') || CRON_SECRET === '' || ($_GET['token'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        die('Unauthorized');
    }
}

define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mailer.php';

$db = getDB();

if (!function_exists('greekMonthName')) {
    function greekMonthName(int $m): string {
        return [1=>'Ιανουάριος',2=>'Φεβρουάριος',3=>'Μάρτιος',4=>'Απρίλιος',5=>'Μάιος',6=>'Ιούνιος',
                7=>'Ιούλιος',8=>'Αύγουστος',9=>'Σεπτέμβριος',10=>'Οκτώβριος',11=>'Νοέμβριος',12=>'Δεκέμβριος'][$m] ?? '';
    }
}

// Track this run so admins see it
$db->exec("CREATE TABLE IF NOT EXISTS cron_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job VARCHAR(60) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    stats JSON NULL,
    INDEX idx_job (job),
    INDEX idx_started (started_at)
)");

$runStart = date('Y-m-d H:i:s');

// Look at last calendar month (from = first day of prev month, to = last day of prev month)
$now = new DateTime();
$firstOfThis = new DateTime($now->format('Y-m-01') . ' 00:00:00');
$fromDt = (clone $firstOfThis)->modify('-1 month');
$toDt   = (clone $firstOfThis)->modify('-1 second');
$from   = $fromDt->format('Y-m-d H:i:s');
$to     = $toDt->format('Y-m-d H:i:s');
$monthLabel = greekMonthName((int)$fromDt->format('n')) . ' ' . $fromDt->format('Y');

// Aggregate per-school counts
$sql = "SELECT s.id, s.name, s.email,
               SUM(rl.type='email' AND rl.status='sent') AS emails_sent,
               SUM(rl.type='sms'   AND rl.status='sent') AS sms_sent,
               SUM(rl.status='failed') AS failures
          FROM schools s
          LEFT JOIN reminder_logs rl
                 ON rl.school_id = s.id
                AND rl.sent_at BETWEEN ? AND ?
         WHERE s.active = 1
         GROUP BY s.id, s.name, s.email
         HAVING (emails_sent + sms_sent + failures) > 0";
$st = $db->prepare($sql);
$st->execute([$from, $to]);
$rows = $st->fetchAll();

$totalEmails = 0; $totalSms = 0; $totalFail = 0; $sentTo = 0;

foreach ($rows as $r) {
    $eN = (int)$r['emails_sent'];
    $sN = (int)$r['sms_sent'];
    $fN = (int)$r['failures'];
    $totalEmails += $eN; $totalSms += $sN; $totalFail += $fN;

    if (!$r['email']) continue;
    if (($eN + $sN) === 0) continue;

    $subject = "MAster · Σύνοψη αυτόματων υπενθυμίσεων — {$monthLabel}";
    $body = "Αγαπητοί/ές,\n\n"
          . "Για τον μήνα {$monthLabel} το σύστημα MAster απέστειλε αυτόματα:\n"
          . "  • {$eN} email υπενθυμίσεις\n"
          . "  • {$sN} SMS υπενθυμίσεις\n"
          . ($fN > 0 ? "  • {$fN} αποτυχημένες αποστολές\n" : "")
          . "\nΓια αναλυτικό ιστορικό, ανοίξτε: " . APP_URL . "/pages/notifications.php\n\n"
          . "Ευχαριστούμε,\nMAster\n";

    try {
        sendEmail($r['email'], $subject, $body);
        $sentTo++;
    } catch (Throwable $e) {
        error_log('[monthly_digest] send failed for school '.$r['id'].': '.$e->getMessage());
    }
}

$stats = [
    'month'         => $fromDt->format('Y-m'),
    'schools_hit'   => count($rows),
    'digests_sent'  => $sentTo,
    'total_emails'  => $totalEmails,
    'total_sms'     => $totalSms,
    'total_failed'  => $totalFail,
];

$db->prepare("INSERT INTO cron_runs (job, started_at, finished_at, stats) VALUES (?, ?, NOW(), ?)")
   ->execute(['monthly_digest', $runStart, json_encode($stats, JSON_UNESCAPED_UNICODE)]);

echo "[monthly_digest] " . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
