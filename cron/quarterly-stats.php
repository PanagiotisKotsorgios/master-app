#!/usr/bin/env php
<?php
/**
 * ============================================================
 * cron/quarterly-stats.php — Τριμηνιαία Αναφορά Στατιστικών
 * ============================================================
 * PURPOSE:
 *   Αποστολή email κάθε 3 μήνες σε κάθε club owner με:
 *   - Αριθμός ενεργών αθλητών
 *   - Έσοδα τριμήνου
 *   - Email & SMS που στάλθηκαν
 *   - Ποσοστό πληρωμένων συνδρομών
 *
 * SCHEDULE (crontab):
 *   0 9 1 1,4,7,10 * /usr/bin/php /path/to/cron/quarterly-stats.php
 *
 * MANUAL RUN (web, with token):
 *   https://your-site.gr/cron/quarterly-stats.php?token=YOUR_CRON_SECRET
 * ============================================================
 */

$allowInternalWebRun = defined('INTERNAL_CRON_ALLOWED') && INTERNAL_CRON_ALLOWED === true;

if (PHP_SAPI !== 'cli' && !$allowInternalWebRun) {
    require_once __DIR__ . '/../includes/config.php';
    if (!defined('CRON_SECRET') || CRON_SECRET === '' || ($_GET['token'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        die('Unauthorized');
    }
}

define('RUNNING_AS_CRON', true);

require_once __DIR__ . '/../includes/config.php';

$db = getDB();

// ── Ensure quarterly_stats_log table exists ───────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS quarterly_stats_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    quarter VARCHAR(7) NOT NULL,
    status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    sent_at DATETIME NOT NULL,
    INDEX idx_school (school_id),
    INDEX idx_quarter (quarter)
)");

function qLog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    error_log('[MAster quarterly-stats] ' . $msg);
}

// ── Determine current quarter ─────────────────────────────────────────────
$currentMonth  = (int)date('n');
$currentYear   = (int)date('Y');
$quarterNum    = (int)ceil($currentMonth / 3);
$quarterLabel  = "Q{$quarterNum} {$currentYear}";

// Quarter date range (3 full months back)
$quarterStart  = date('Y-m-d', mktime(0,0,0, ($quarterNum - 1) * 3 + 1, 1, $currentYear));
$quarterEnd    = date('Y-m-t', mktime(0,0,0, $quarterNum * 3, 1, $currentYear));

$monthNames = ['','Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος',
               'Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
$qMonthStart = ($quarterNum - 1) * 3 + 1;
$qMonthEnd   = $quarterNum * 3;
$quarterRange = $monthNames[$qMonthStart] . ' – ' . $monthNames[$qMonthEnd] . ' ' . $currentYear;

qLog("=== Quarterly Stats: $quarterLabel ($quarterRange) ===");
qLog("Quarter period: $quarterStart → $quarterEnd");

// ── Fetch all active schools with owner email ─────────────────────────────
$schoolStmt = $db->query("
    SELECT s.id, s.name, u.email, u.name AS owner_name,
           p.name AS plan_name
    FROM schools s
    JOIN users u ON u.school_id = s.id AND u.role = 'owner'
    LEFT JOIN plans p ON p.id = s.plan_id
    WHERE s.active = 1
      AND u.email IS NOT NULL AND u.email != ''
    ORDER BY s.name
");
$schools = $schoolStmt->fetchAll();
qLog("Found " . count($schools) . " active schools");

$totalSent = 0; $totalFailed = 0;

foreach ($schools as $school) {
    $sid        = (int)$school['id'];
    $schoolName = $school['name'];
    $ownerEmail = $school['email'];
    $ownerName  = $school['owner_name'] ?? 'Διαχειριστή';
    $planName   = $school['plan_name'] ?? '—';

    // ── Stats for this school (all via prepared statements) ──────────────

    $stAthletes   = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id=? AND active=1");
    $stAthletes->execute([$sid]);
    $athletes = (int)$stAthletes->fetchColumn();

    $stNew = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id=? AND active=1 AND registration_date BETWEEN ? AND ?");
    $stNew->execute([$sid, $quarterStart, $quarterEnd]);
    $newAthletes = (int)$stNew->fetchColumn();

    $stRev = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM subscriptions WHERE school_id=? AND status='paid' AND paid_at BETWEEN ? AND ?");
    $stRev->execute([$sid, $quarterStart, $quarterEnd]);
    $revenue = (float)$stRev->fetchColumn();

    $stPaid = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE school_id=? AND status='paid' AND valid_from <= CURDATE() AND valid_until >= CURDATE()");
    $stPaid->execute([$sid]);
    $paidCount = (int)$stPaid->fetchColumn();

    $stUnpaid = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE school_id=? AND status IN('overdue','pending') AND valid_until < CURDATE()");
    $stUnpaid->execute([$sid]);
    $unpaidCount = (int)$stUnpaid->fetchColumn();

    $stEmail = $db->prepare("SELECT COUNT(*) FROM reminder_logs WHERE school_id=? AND type='email' AND status='sent' AND sent_at BETWEEN ? AND ?");
    $stEmail->execute([$sid, $quarterStart, $quarterEnd]);
    $emailsSent = (int)$stEmail->fetchColumn();

    $stSms = $db->prepare("SELECT COUNT(*) FROM reminder_logs WHERE school_id=? AND type='sms' AND status='sent' AND sent_at BETWEEN ? AND ?");
    $stSms->execute([$sid, $quarterStart, $quarterEnd]);
    $smsSent = (int)$stSms->fetchColumn();

    $payRate = ($paidCount + $unpaidCount > 0)
        ? round($paidCount / ($paidCount + $unpaidCount) * 100)
        : 100;

    // ── Build email ───────────────────────────────────────────────────────
    $revenueFormatted = number_format($revenue, 2, ',', '.');

    $subject = "📊 Τριμηνιαία Αναφορά $quarterLabel — $schoolName";

    $bodyText = "Αγαπητέ/ή $ownerName,\n\n"
        . "Εδώ είναι η τριμηνιαία αναφορά της σχολής σας για την περίοδο $quarterRange.\n\n"
        . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "  ΑΘΛΗΤΕΣ\n"
        . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "  Ενεργοί αθλητές   : $athletes\n"
        . "  Νέες εγγραφές     : $newAthletes (αυτό το τρίμηνο)\n\n"
        . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "  ΟΙΚΟΝΟΜΙΚΑ\n"
        . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "  Έσοδα τριμήνου    : €$revenueFormatted\n"
        . "  Ποσοστό πληρωμών  : $payRate%\n"
        . "  Εκκρεμείς         : $unpaidCount συνδρομές\n\n"
        . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "  ΕΠΙΚΟΙΝΩΝΙΕΣ\n"
        . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "  Email αποστολές   : $emailsSent\n"
        . "  SMS αποστολές     : $smsSent\n\n"
        . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
        . "Μπορείτε να δείτε αναλυτικές αναφορές από το dashboard σας.\n\n"
        . "— Η ομάδα MAster";

    $logoUrl  = rtrim(APP_URL, '/') . '/assets/img/logo-tr.png';
    $appUrl   = rtrim(APP_URL, '/');
    $year     = date('Y');

    $html = '<!DOCTYPE html><html lang="el"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:#111520;border-radius:18px;border:1px solid #1e2536;overflow:hidden;margin:0 auto">
    <tr>
      <td style="background:linear-gradient(135deg,#1a0d2e,#0d1117);padding:30px 36px 24px;text-align:center;border-bottom:2px solid #e63946">
        <img src="'.$logoUrl.'" alt="MAster" style="height:56px;width:auto;display:block;margin:0 auto 10px">
        <p style="margin:0;font-size:.8rem;color:#b0bdd6;letter-spacing:.08em;text-transform:uppercase">Τριμηνιαία Αναφορά</p>
        <h2 style="margin:.4rem 0 0;font-size:1.4rem;color:#f0f2ff;letter-spacing:.04em">'.$quarterLabel.' — '.htmlspecialchars($schoolName).'</h2>
        <p style="margin:.35rem 0 0;font-size:.88rem;color:#b0bdd6">'.$quarterRange.'</p>
      </td>
    </tr>
    <tr><td style="padding:28px 36px 0">
      <!-- Athletes section -->
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px">
        <tr>
          <td style="padding-bottom:10px;border-bottom:1px solid #1e2536">
            <span style="font-size:.75rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#e63946">Αθλητές</span>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 0 0">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="width:50%;padding:10px 8px;background:#0d1117;border-radius:10px;text-align:center">
                  <div style="font-size:2rem;font-weight:900;color:#f0f2ff;line-height:1">'.$athletes.'</div>
                  <div style="font-size:.75rem;color:#b0bdd6;margin-top:4px">Ενεργοί</div>
                </td>
                <td style="width:4px"></td>
                <td style="width:50%;padding:10px 8px;background:#0d1117;border-radius:10px;text-align:center">
                  <div style="font-size:2rem;font-weight:900;color:#2dc653;line-height:1">+'.$newAthletes.'</div>
                  <div style="font-size:.75rem;color:#b0bdd6;margin-top:4px">Νέες εγγραφές</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
      <!-- Revenue section -->
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px">
        <tr>
          <td style="padding-bottom:10px;border-bottom:1px solid #1e2536">
            <span style="font-size:.75rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#e63946">Οικονομικά</span>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 0 0">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="width:33%;padding:10px 6px;background:#0d1117;border-radius:10px;text-align:center">
                  <div style="font-size:1.4rem;font-weight:900;color:#f0a500;line-height:1">€'.$revenueFormatted.'</div>
                  <div style="font-size:.72rem;color:#b0bdd6;margin-top:4px">Έσοδα</div>
                </td>
                <td style="width:3px"></td>
                <td style="width:33%;padding:10px 6px;background:#0d1117;border-radius:10px;text-align:center">
                  <div style="font-size:1.4rem;font-weight:900;color:#2dc653;line-height:1">'.$payRate.'%</div>
                  <div style="font-size:.72rem;color:#b0bdd6;margin-top:4px">Πληρωμένα</div>
                </td>
                <td style="width:3px"></td>
                <td style="width:33%;padding:10px 6px;background:rgba(230,57,70,.06);border:1px solid rgba(230,57,70,.2);border-radius:10px;text-align:center">
                  <div style="font-size:1.4rem;font-weight:900;color:#e63946;line-height:1">'.$unpaidCount.'</div>
                  <div style="font-size:.72rem;color:#b0bdd6;margin-top:4px">Εκκρεμείς</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
      <!-- Communications section -->
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
        <tr>
          <td style="padding-bottom:10px;border-bottom:1px solid #1e2536">
            <span style="font-size:.75rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#e63946">Επικοινωνίες (τρίμηνο)</span>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 0 0">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="width:50%;padding:10px 8px;background:#0d1117;border-radius:10px;text-align:center">
                  <div style="font-size:1.8rem;font-weight:900;color:#3b82f6;line-height:1">'.$emailsSent.'</div>
                  <div style="font-size:.75rem;color:#b0bdd6;margin-top:4px">Emails</div>
                </td>
                <td style="width:4px"></td>
                <td style="width:50%;padding:10px 8px;background:#0d1117;border-radius:10px;text-align:center">
                  <div style="font-size:1.8rem;font-weight:900;color:#a78bfa;line-height:1">'.$smsSent.'</div>
                  <div style="font-size:.75rem;color:#b0bdd6;margin-top:4px">SMS</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
      <!-- CTA -->
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
        <tr>
          <td style="text-align:center">
            <a href="'.$appUrl.'/dashboard/" style="display:inline-block;background:linear-gradient(135deg,#e63946,#b52a35);color:#fff;font-weight:800;font-size:.9rem;padding:.75rem 2rem;border-radius:10px;text-decoration:none;box-shadow:0 0 20px rgba(230,57,70,.3)">Δείτε αναλυτικές αναφορές →</a>
          </td>
        </tr>
      </table>
    </td></tr>
    <tr>
      <td style="padding:16px 36px 24px;border-top:1px solid #1e2536;text-align:center;font-size:.73rem;color:#4a5270;line-height:1.7">
        &copy; '.$year.' MAster &nbsp;&middot;&nbsp; Αυτόματη αναφορά συστήματος &nbsp;&middot;&nbsp; <a href="'.$appUrl.'/dashboard/" style="color:#e63946;text-decoration:none">Dashboard</a>
      </td>
    </tr>
  </table>
  </td></tr>
</table>
</body></html>';

    $dbg = null;
    $ok  = sendEmail($ownerEmail, $subject, $html, $bodyText, $ownerName, $dbg, null, 'MAster');
    $ok ? $totalSent++ : $totalFailed++;

    // Log to DB
    try {
        $db->prepare("INSERT INTO quarterly_stats_log (school_id, quarter, status, sent_at) VALUES (?,?,?,NOW())")
           ->execute([$sid, $quarterLabel, $ok ? 'sent' : 'failed']);
    } catch (Throwable $e) {}

    qLog("  [{$sid}] $schoolName → $ownerEmail: " . ($ok ? 'SENT' : 'FAILED'));
}

qLog("=== Done: {$totalSent} sent, {$totalFailed} failed ===");
