#!/usr/bin/env php
<?php

/**
 * ============================================================
 * cron/reminders.php — Αυτόματες Ειδοποιήσεις (Cron Job)
 * ============================================================
 */

$allowInternalWebRun = defined('INTERNAL_CRON_ALLOWED') && INTERNAL_CRON_ALLOWED === true;

if (PHP_SAPI !== 'cli' && !$allowInternalWebRun) {
    // config.php defines CRON_SECRET from env; refuse if not configured
    require_once __DIR__ . '/../includes/config.php';
    if (!defined('CRON_SECRET') || CRON_SECRET === '' || ($_GET['token'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        die('Unauthorized');
    }
}

define('RUNNING_AS_CRON', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/usage_tracker.php';

$db = getDB();

// ── Ensure consent_log table exists ──
$db->exec("CREATE TABLE IF NOT EXISTS consent_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NULL,
    parent_user_id INT NULL,
    event_type VARCHAR(40) NOT NULL COMMENT 'terms_accepted, sms_consent_given, sms_opt_out, email_opt_out, unsub_link',
    ip_hash VARCHAR(128) NULL COMMENT 'SHA-256 of IP+daily_salt — pseudonymized',
    user_agent VARCHAR(512) NULL,
    terms_version VARCHAR(10) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pid (parent_user_id),
    INDEX idx_type (event_type),
    INDEX idx_created (created_at)
)");

$db->exec("CREATE TABLE IF NOT EXISTS reminder_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    athlete_id INT NOT NULL,
    subscription_id INT NULL,
    type ENUM('email','sms') NOT NULL,
    trigger_type VARCHAR(40) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    sent_at DATETIME NOT NULL,
    INDEX idx_school (school_id),
    INDEX idx_athlete (athlete_id),
    INDEX idx_subscription (subscription_id),
    INDEX idx_sent_at (sent_at)
)");

$db->exec("CREATE TABLE IF NOT EXISTS cron_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    school_id INT NULL DEFAULT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_name (job_name),
    INDEX idx_started_at (started_at),
    INDEX idx_school (school_id)
)");

function cronLog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    error_log('[MAster cron] ' . $msg);
}

function cronSchoolCanSendSms(PDO $db, int $schoolId): bool {
    try {
        $stmt = $db->prepare("
            SELECT p.sms_enabled, s.sms_addon, s.sms_addon_expires
            FROM schools s
            LEFT JOIN plans p ON p.id = s.plan_id
            WHERE s.id = ?
        ");
        $stmt->execute([$schoolId]);
        $row = $stmt->fetch();
        if (!$row) return false;
        if (!empty($row['sms_enabled'])) return true;
        if (!empty($row['sms_addon'])) {
            if (empty($row['sms_addon_expires'])) return true;
            if (strtotime($row['sms_addon_expires']) >= time()) return true;
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function cronIsAdult(array $athlete): bool {
    if (empty($athlete['birthdate'])) return true;
    try {
        return ((new DateTime())->diff(new DateTime($athlete['birthdate']))->y >= 18);
    } catch (Exception $e) {
        return true;
    }
}

function cronRecipientEmail(array $athlete): string {
    if (cronIsAdult($athlete)) {
        return trim($athlete['email'] ?? '') ?: trim($athlete['parent_email'] ?? '');
    }
    return trim($athlete['parent_email'] ?? '') ?: trim($athlete['email'] ?? '');
}

function cronRecipientPhone(array $athlete): string {
    if (cronIsAdult($athlete)) {
        return trim($athlete['phone'] ?? '') ?: trim($athlete['parent_phone'] ?? '');
    }
    return trim($athlete['parent_phone'] ?? '') ?: trim($athlete['phone'] ?? '');
}

function cronAlreadySentToday(PDO $db, int $subId, string $triggerType, string $type): bool {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM reminder_logs
            WHERE subscription_id = ?
              AND trigger_type = ?
              AND type = ?
              AND DATE(sent_at) = CURDATE()
              AND status = 'sent'
        ");
        $stmt->execute([$subId, $triggerType, $type]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function cronLogReminder(PDO $db, int $schoolId, int $athleteId, ?int $subId, string $type, string $triggerType, string $recipient, bool $ok): void {
    try {
        $db->prepare("
            INSERT INTO reminder_logs (school_id, athlete_id, subscription_id, type, trigger_type, recipient, status, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([$schoolId, $athleteId, $subId, $type, $triggerType, $recipient, $ok ? 'sent' : 'failed']);
    } catch (Exception $e) {
        cronLog("⚠ reminder_logs insert failed: " . $e->getMessage());
    }
}

function cronUpdateAthleteNotified(PDO $db, int $athleteId, int $schoolId, string $notifType): void {
    try {
        $db->prepare("
            UPDATE athletes
            SET debt_notified_at = NOW(), debt_notified_type = ?
            WHERE id = ? AND school_id = ?
        ")->execute([$notifType, $athleteId, $schoolId]);
    } catch (Exception $e) {}
}

function cronFormatDate(?string $date): string {
    if (!$date) return '—';
    try {
        return (new DateTime($date))->format('d/m/Y');
    } catch (Exception $e) {
        return $date;
    }
}

function cronFormatAmount($amount): string {
    return number_format((float)$amount, 2, ',', '.') . '€';
}

function cronRenderTextTemplate(string $tpl, array $athlete, array $sub, string $schoolName): string {
    $fullName  = trim((string)($athlete['full_name'] ?? ''));
    $nameParts = preg_split('/\s+/', $fullName);
    $lastName  = count($nameParts) > 1 ? end($nameParts) : $fullName;

    $repl = [
        '{{athlete_name}}'      => $fullName,
        '{{athlete_last_name}}' => $lastName,
        '{{school_name}}'       => (string)$schoolName,
        '{{valid_until}}'       => cronFormatDate($sub['valid_until'] ?? null),
        '{{amount}}'            => cronFormatAmount($sub['amount'] ?? 0),
        '{{debt_months}}'       => isset($sub['debt_months']) ? (int)$sub['debt_months'] . ' ' . ((int)$sub['debt_months'] === 1 ? 'μήνα' : 'μήνες') : '',
        '{{monthly_amount}}'    => isset($sub['monthly_amount']) ? cronFormatAmount($sub['monthly_amount']) : '',
    ];
    return strtr($tpl, $repl);
}

/**
 * Returns true if a debt-related notification (has_debt OR days_after with no sub)
 * was already successfully sent to this athlete this calendar month.
 * Covers both trigger types so a single monthly send via either rule counts for both.
 */
function cronHasDebtAlreadySentThisMonth(PDO $db, int $athleteId, string $type): bool {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM reminder_logs
            WHERE athlete_id = ?
              AND trigger_type IN ('has_debt', 'days_after')
              AND type = ?
              AND YEAR(sent_at)  = YEAR(NOW())
              AND MONTH(sent_at) = MONTH(NOW())
              AND status = 'sent'
        ");
        $stmt->execute([$athleteId, $type]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function cronBuildDefaultSubject(string $triggerType, string $schoolName): string {
    return match($triggerType) {
        'days_before'   => 'Υπενθύμιση πληρωμής — ' . $schoolName,
        'on_due'        => 'Η συνδρομή λήγει σήμερα — ' . $schoolName,
        'days_after'    => 'Εκκρεμής πληρωμή — ' . $schoolName,
        'after_payment' => 'Επιβεβαίωση πληρωμής — ' . $schoolName,
        'has_debt'      => 'Εκκρεμής οφειλή συνδρομής — ' . $schoolName,
        default         => 'Ειδοποίηση — ' . $schoolName,
    };
}

function cronBuildDefaultBody(string $triggerType, array $athlete, array $sub, string $schoolName): string {
    $name   = trim($athlete['full_name'] ?? '');
    $date   = cronFormatDate($sub['valid_until'] ?? null);
    $amount = cronFormatAmount($sub['amount'] ?? 0);

    // Debt detail lines for has_debt / days_after
    $debtDetail = '';
    if (!empty($sub['debt_months']) && !empty($sub['monthly_amount'])) {
        $months = (int)$sub['debt_months'];
        $monthly = cronFormatAmount($sub['monthly_amount']);
        $debtDetail = "Μηνιαία χρέωση: {$monthly} × {$months} " . ($months === 1 ? 'μήνας' : 'μήνες') . "\nΣυνολική οφειλή: {$amount}";
    } elseif ((float)($sub['amount'] ?? 0) > 0) {
        $debtDetail = "Οφειλόμενο ποσό: {$amount}";
    }

    $stopNote = "\n\nΓια διακοπή email: χρησιμοποιήστε τον σύνδεσμο στο footer του email.";

    return match($triggerType) {
        'days_before' => "Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΜια φιλική υπενθύμιση ότι η συνδρομή του/της {$name} λήγει στις {$date}.\nΠαρακαλούμε φροντίστε για την ανανέωση. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{$schoolName}",
        'on_due' => "Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΜια φιλική υπενθύμιση: η συνδρομή του/της {$name} λήγει σήμερα ({$date}).\nΠαρακαλούμε φροντίστε για την ανανέωση. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{$schoolName}",
        'days_after' => "Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε ότι η συνδρομή του/της {$name} έχει λήξει από τις {$date}.\n" . ($debtDetail ?: "Ποσό: {$amount}") . "\n\nΠαρακαλούμε να τακτοποιηθεί. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{$schoolName}",
        'after_payment' => "Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΛάβαμε την πληρωμή για τον/την {$name}.\nΠοσό: {$amount}\n\nΣας ευχαριστούμε πολύ!\n{$schoolName}",
        'has_debt' => "Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε ότι εκκρεμεί η συνδρομή του/της {$name}.\n" . ($debtDetail ?: "Ποσό: {$amount}") . "\n\nΠαρακαλούμε να τακτοποιηθεί. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{$schoolName}",
        default => "Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΠαρακαλούμε δείτε την ενημέρωσή σας.\n\n{$schoolName}",
    };
}

/**
 * Check if the parent linked to this athlete's email has opted out of a channel.
 */
function cronIsParentOptedOut(PDO $db, string $parentEmail, int $schoolId, string $channel): bool {
    if (!$parentEmail) return false;
    $col = ($channel === 'sms') ? 'sms_opt_out' : 'email_opt_out';
    try {
        $stmt = $db->prepare("SELECT {$col} FROM parent_users WHERE parent_email = ? AND school_id = ? LIMIT 1");
        $stmt->execute([$parentEmail, $schoolId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (bool)$row[$col] : false;
    } catch (\Exception $e) {
        return false;
    }
}

function cronSendRuleNotification(PDO $db, array $school, array $rule, array $athlete, array $sub, string $channel): array {
    $schoolName = $school['name'] ?: 'MAster';
    $triggerType = (string)$rule['trigger_type'];

    $subjectTpl = trim((string)($rule['subject_tpl'] ?? ''));
    $bodyTpl    = trim((string)($rule['body_tpl'] ?? ''));

    $subject = $subjectTpl !== ''
        ? cronRenderTextTemplate($subjectTpl, $athlete, $sub, $schoolName)
        : cronBuildDefaultSubject($triggerType, $schoolName);

    $body = $bodyTpl !== ''
        ? cronRenderTextTemplate($bodyTpl, $athlete, $sub, $schoolName)
        : cronBuildDefaultBody($triggerType, $athlete, $sub, $schoolName);

    if ($channel === 'email') {
        $recipient = cronRecipientEmail($athlete);
        if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'recipient' => $recipient, 'reason' => 'no_email'];
        }

        // Generate/fetch unsubscribe URL for this parent
        $unsubUrl = function_exists('getParentUnsubscribeUrl')
            ? getParentUnsubscribeUrl($db, $recipient, (int)$school['id'], 'email')
            : '';

        $htmlBody = function_exists('buildEmailHtml')
            ? buildEmailHtml($body, $schoolName, $subject, $unsubUrl)
            : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        $ok = sendEmail($recipient, $subject, $htmlBody, $body, $athlete['full_name'] ?? '');
        return ['ok' => (bool)$ok, 'recipient' => $recipient, 'reason' => $ok ? 'sent' : 'send_failed'];
    }

    if ($channel === 'sms') {
        $recipient = cronRecipientPhone($athlete);
        if (!$recipient) {
            return ['ok' => false, 'recipient' => $recipient, 'reason' => 'no_phone'];
        }

        // Hard block: enforce monthly SMS limit before sending
        if (function_exists('checkMonthlyUsageLimit') && !checkMonthlyUsageLimit((int)$school['id'], 'sms')) {
            return ['ok' => false, 'recipient' => $recipient, 'reason' => 'monthly_sms_limit'];
        }

        $smsText = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
        $ok = sendSms($recipient, $smsText, $smsErr);
        return ['ok' => (bool)$ok, 'recipient' => $recipient, 'reason' => $ok ? 'sent' : 'send_failed'];
    }

    return ['ok' => false, 'recipient' => '', 'reason' => 'invalid_channel'];
}

$runInsert = $db->prepare("
    INSERT INTO cron_runs (job_name, school_id, started_at, status, message)
    VALUES ('reminders', NULL, NOW(), 'running', 'Cron started')
");
$runInsert->execute();
$runId = (int)$db->lastInsertId();

cronLog("═══ MAster Automated Reminders START ═══");

// ── Log Retention Cleanup (per Privacy Policy §6) ────────────────────────────
// consent_log  → 12 months
// audit_log    → 6 months (user actions)
// login_attempts → 24 hours (already pruned during rate-limit check, cleanup here too)
// reminder_logs → 12 months
try {
    $delConsent = $db->exec("DELETE FROM consent_log  WHERE created_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)");
    cronLog("Retention: consent_log pruned $delConsent rows (>12mo)");

    $delAudit = $db->exec("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)");
    cronLog("Retention: audit_log pruned $delAudit rows (>6mo)");

    $delAttempts = $db->exec("DELETE FROM login_attempts WHERE attempted_at < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 24 HOUR))");
    cronLog("Retention: login_attempts pruned $delAttempts rows (>24h)");

    $delReminders = $db->exec("DELETE FROM reminder_logs WHERE sent_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)");
    cronLog("Retention: reminder_logs pruned $delReminders rows (>12mo)");

    $delCronRuns = $db->exec("DELETE FROM cron_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)");
    cronLog("Retention: cron_runs pruned $delCronRuns rows (>6mo)");
} catch (Throwable $retEx) {
    cronLog("⚠ Retention cleanup error: " . $retEx->getMessage());
}
// ─────────────────────────────────────────────────────────────────────────────

$totalSent    = 0;
$totalFailed  = 0;
$totalSkipped = 0;

try {
    $schools = $db->query("
        SELECT s.id, s.name, s.email, s.plan_id, s.sms_addon, s.sms_addon_expires
        FROM schools s
        WHERE s.active = 1 AND s.plan_status IN ('active', 'trial')
    ")->fetchAll();

    cronLog("Schools to process: " . count($schools));

    foreach ($schools as $school) {
        $sid        = (int)$school['id'];
        $schoolName = $school['name'] ?: 'MAster';
        $hasSms     = cronSchoolCanSendSms($db, $sid);

        cronLog("─── School #{$sid}: {$schoolName} (SMS: " . ($hasSms ? 'YES' : 'NO') . ")");

        // ── Summer pause: skip ALL athlete notifications for this school ──
        if (function_exists('isSchoolInSummerPause') && isSchoolInSummerPause($sid)) {
            cronLog("  School #{$sid}: SUMMER PAUSE ACTIVE — all notifications suspended");
            $totalSkipped++;
            continue;
        }

        $rulesStmt = $db->prepare("
            SELECT *
            FROM notification_rules
            WHERE school_id = ? AND active = 1
            ORDER BY FIELD(trigger_type, 'days_before', 'on_due', 'days_after', 'after_payment', 'has_debt'), trigger_days ASC
        ");
        $rulesStmt->execute([$sid]);
        $ruleList = $rulesStmt->fetchAll();

        if (!$ruleList) {
            cronLog("  (no active rules)");
            continue;
        }

        cronLog("  Active rules: " . count($ruleList));

        foreach ($ruleList as $rule) {
            $ruleId      = (int)$rule['id'];
            $triggerType = (string)$rule['trigger_type'];
            $triggerDays = (int)$rule['trigger_days'];
            $channels    = array_filter(array_map('trim', explode(',', $rule['channels'] ?? 'email')));
            $triggerKey  = $triggerType . ($triggerDays > 0 ? '_' . $triggerDays : '');

            $subscriptions = [];

            switch ($triggerType) {
                case 'days_before':
                    $stmt = $db->prepare("
                        SELECT s.*, a.*, s.id as sub_id, a.id as athlete_id
                        FROM subscriptions s
                        JOIN athletes a ON a.id = s.athlete_id
                        WHERE s.school_id = ?
                          AND a.school_id = ?
                          AND a.active = 1
                          AND s.status IN ('pending', 'active', 'paid')
                          AND s.valid_until = DATE_ADD(CURDATE(), INTERVAL ? DAY)
                    ");
                    $stmt->execute([$sid, $sid, $triggerDays]);
                    $subscriptions = $stmt->fetchAll();
                    break;

                case 'on_due':
                    $stmt = $db->prepare("
                        SELECT s.*, a.*, s.id as sub_id, a.id as athlete_id
                        FROM subscriptions s
                        JOIN athletes a ON a.id = s.athlete_id
                        WHERE s.school_id = ?
                          AND a.school_id = ?
                          AND a.active = 1
                          AND s.status IN ('pending', 'active', 'paid', 'overdue')
                          AND s.valid_until = CURDATE()
                    ");
                    $stmt->execute([$sid, $sid]);
                    $subscriptions = $stmt->fetchAll();

                    foreach ($subscriptions as $subRow) {
                        $db->prepare("UPDATE subscriptions SET status='overdue' WHERE id=? AND school_id=?")
                           ->execute([(int)$subRow['sub_id'], $sid]);
                    }
                    break;

                case 'days_after':
                    // Original query for subscriptions that expired exactly triggerDays ago
                    $stmt = $db->prepare("
                        SELECT s.*, a.*, s.id as sub_id, a.id as athlete_id
                        FROM subscriptions s
                        JOIN athletes a ON a.id = s.athlete_id
                        WHERE s.school_id = ?
                          AND a.school_id = ?
                          AND a.active = 1
                          AND s.status IN ('pending', 'active', 'paid', 'overdue')
                          AND s.valid_until = DATE_SUB(CURDATE(), INTERVAL ? DAY)
                    ");
                    $stmt->execute([$sid, $sid, $triggerDays]);
                    $subscriptions = $stmt->fetchAll();

                    // ---- Also catch athletes with debt (explicit debt_from_month OR dynamic) ----
                    $debtStmt = $db->prepare("
                        SELECT a.*,
                               a.id as athlete_id,
                               NULL as sub_id,
                               COALESCE(a.monthly_fee, 0) as amount,
                               NULL as valid_until,
                               NULL as valid_from,
                               NULL as paid_at,
                               'overdue' as status
                        FROM athletes a
                        WHERE a.school_id = ?
                          AND a.active = 1
                          AND (
                              -- Explicit debt marker
                              (a.debt_from_month IS NOT NULL AND a.debt_from_month < DATE_FORMAT(CURDATE(), '%Y-%m'))
                              OR
                              -- Dynamic debt: registered at least triggerDays ago, has fee, no paid sub this month
                              (
                                  a.monthly_fee > 0
                                  AND a.debt_from_month IS NULL
                                  AND a.registration_date IS NOT NULL
                                  AND a.registration_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                                  AND NOT EXISTS (
                                      SELECT 1 FROM subscriptions s
                                      WHERE s.athlete_id = a.id
                                        AND s.school_id = a.school_id
                                        AND s.status = 'paid'
                                        AND s.valid_from <= LAST_DAY(CURDATE())
                                        AND s.valid_until >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                  )
                              )
                          )
                    ");
                    $debtStmt->execute([$sid, $triggerDays]);
                    $debtRows = $debtStmt->fetchAll();

                    // Merge, avoiding duplicates by athlete_id
                    $existingIds = array_column($subscriptions, 'athlete_id');
                    foreach ($debtRows as $dr) {
                        if (!in_array((int)$dr['athlete_id'], $existingIds)) {
                            $subscriptions[] = $dr;
                        }
                    }
                    // ----------------------------------------------------------------

                    foreach ($subscriptions as $subRow) {
                        if (!empty($subRow['sub_id'])) {
                            $db->prepare("UPDATE subscriptions SET status='overdue' WHERE id=? AND school_id=?")
                               ->execute([(int)$subRow['sub_id'], $sid]);
                        }
                    }
                    break;

                case 'after_payment':
                    $stmt = $db->prepare("
                        SELECT s.*, a.*, s.id as sub_id, a.id as athlete_id
                        FROM subscriptions s
                        JOIN athletes a ON a.id = s.athlete_id
                        WHERE s.school_id = ?
                          AND a.school_id = ?
                          AND a.active = 1
                          AND s.status = 'paid'
                          AND DATE(s.paid_at) = CURDATE()
                    ");
                    $stmt->execute([$sid, $sid]);
                    $subscriptions = $stmt->fetchAll();
                    break;

                case 'has_debt':
                    $stmt = $db->prepare("
                        SELECT a.*,
                               a.id as athlete_id,
                               NULL as sub_id,
                               COALESCE(a.monthly_fee, 0) as amount,
                               NULL as valid_until,
                               NULL as valid_from,
                               NULL as paid_at,
                               'overdue' as status
                        FROM athletes a
                        WHERE a.school_id = ?
                          AND a.active = 1
                          AND (
                              -- Explicit debt marker
                              (a.debt_from_month IS NOT NULL AND a.debt_from_month < DATE_FORMAT(CURDATE(), '%Y-%m'))
                              OR
                              -- Dynamic debt: monthly_fee set, registered before this month,
                              -- no paid subscription covering current month
                              (
                                  a.monthly_fee > 0
                                  AND a.debt_from_month IS NULL
                                  AND a.registration_date IS NOT NULL
                                  AND a.registration_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                  AND NOT EXISTS (
                                      SELECT 1 FROM subscriptions s
                                      WHERE s.athlete_id = a.id
                                        AND s.school_id = a.school_id
                                        AND s.status = 'paid'
                                        AND s.valid_from <= LAST_DAY(CURDATE())
                                        AND s.valid_until >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                  )
                              )
                          )
                    ");
                    $stmt->execute([$sid]);
                    $subscriptions = $stmt->fetchAll();
                    break;
            }

            if (!$subscriptions) {
                cronLog("  Rule #{$ruleId} ({$triggerKey}): no matching subscriptions");
                continue;
            }

            cronLog("  Rule #{$ruleId} ({$triggerKey}): " . count($subscriptions) . " subscriptions found");

            foreach ($subscriptions as $row) {
                $athleteId = (int)$row['athlete_id'];
                $subId     = (int)$row['sub_id'];
                $isAdult   = cronIsAdult($row);

                $athlete = [
                    'id'           => $athleteId,
                    'school_id'    => $sid,
                    'full_name'    => $row['full_name'] ?? '',
                    'father_name'  => $row['father_name'] ?? '',
                    'birthdate'    => $row['birthdate'] ?? null,
                    'email'        => $row['email'] ?? '',
                    'parent_email' => $row['parent_email'] ?? '',
                    'phone'        => $row['phone'] ?? '',
                    'parent_phone' => $row['parent_phone'] ?? '',
                    'sport'        => $row['sport'] ?? '',
                    'debt_from_month'  => $row['debt_from_month'] ?? null,
                    'debt_until_month' => $row['debt_until_month'] ?? null,
                ];

                $sub = [
                    'id'           => $subId,
                    'amount'       => $row['amount'] ?? 0,
                    'valid_until'  => $row['valid_until'] ?? null,
                    'valid_from'   => $row['valid_from'] ?? null,
                    'status'       => $row['status'] ?? '',
                    'paid_at'      => $row['paid_at'] ?? null,
                    'trigger_type' => $triggerType,
                    'trigger_days' => $triggerDays,
                ];

                $debtFrom  = $row['debt_from_month'] ?? null;
                $debtUntil = $row['debt_until_month'] ?? null;
                // If debt_until_month is not set, use current month as the end
                $effectiveDebtUntil = $debtUntil ?: date('Y-m');
                if ($debtFrom && (float)($row['monthly_fee'] ?? $sub['amount']) > 0) {
                    try {
                        $d1 = new DateTime($debtFrom . '-01');
                        $d2 = new DateTime($effectiveDebtUntil . '-01');
                        $diff = $d1->diff($d2);
                        $debtMonths = max(1, $diff->y * 12 + $diff->m + 1);
                        $monthlyFee = (float)($row['monthly_fee'] ?? $sub['amount']);
                        if ($debtMonths > 0 && $monthlyFee > 0) {
                            $sub['debt_months']    = $debtMonths;
                            $sub['monthly_amount'] = $monthlyFee;
                            $sub['total_debt']     = $debtMonths * $monthlyFee;
                            $sub['amount']         = $sub['total_debt'];
                        }
                    } catch (Exception $e) {}
                } elseif ($debtFrom && $debtUntil) {
                    // Fallback: no monthly_fee but both months are set — count months without amount
                    try {
                        $d1 = new DateTime($debtFrom . '-01');
                        $d2 = new DateTime($debtUntil . '-01');
                        $diff = $d1->diff($d2);
                        $debtMonths = max(1, $diff->y * 12 + $diff->m + 1);
                        $sub['debt_months'] = $debtMonths;
                    } catch (Exception $e) {}
                }

                $sentTypes = [];

                // Debt-based notifications (no linked subscription OR has_debt rule):
                // limit to once per calendar month per channel so athletes are not flooded.
                $useMonthlyDedup = ($subId === 0 || $triggerType === 'has_debt');

                $emailAlreadySent = $useMonthlyDedup
                    ? cronHasDebtAlreadySentThisMonth($db, $athleteId, 'email')
                    : cronAlreadySentToday($db, $subId, $triggerType, 'email');
                $smsAlreadySent = $useMonthlyDedup
                    ? cronHasDebtAlreadySentThisMonth($db, $athleteId, 'sms')
                    : cronAlreadySentToday($db, $subId, $triggerType, 'sms');

                if (in_array('email', $channels, true)) {
                    $recipEmail = cronRecipientEmail($athlete);
                    $parentEmailForCheck = trim($athlete['parent_email'] ?? '');
                    $emailOptedOut = cronIsParentOptedOut($db, $parentEmailForCheck ?: $recipEmail, $sid, 'email');

                    if (!$recipEmail) {
                        cronLog("    Athlete #{$athleteId} ({$athlete['full_name']}): no email → skip");
                        $totalSkipped++;
                    } elseif ($emailOptedOut) {
                        cronLog("    Athlete #{$athleteId} ({$athlete['full_name']}): email opt-out → skip");
                        $totalSkipped++;
                    } elseif ($emailAlreadySent) {
                        cronLog("    Athlete #{$athleteId} ({$athlete['full_name']}): email already sent " . ($useMonthlyDedup ? 'this month' : 'today') . " → skip");
                        $totalSkipped++;
                    } else {
                        $res = cronSendRuleNotification($db, $school, $rule, $athlete, $sub, 'email');
                        cronLogReminder($db, $sid, $athleteId, $subId, 'email', $triggerType, $res['recipient'] ?: $recipEmail, $res['ok']);

                        if ($res['ok']) {
                            $totalSent++;
                            $sentTypes[] = 'email';
                            cronLog("    ✓ Email → {$res['recipient']} (" . ($isAdult ? 'adult' : 'minor→parent') . ")");
                        } else {
                            $totalFailed++;
                            cronLog("    ✗ Email FAILED → " . ($res['recipient'] ?: '[empty]') . " ({$res['reason']})");
                        }
                    }
                }

                if (in_array('sms', $channels, true) && $hasSms) {
                    $recipPhone = cronRecipientPhone($athlete);
                    $smsOptedOut = cronIsParentOptedOut($db, $parentEmailForCheck ?: cronRecipientEmail($athlete), $sid, 'sms');

                    if (!$recipPhone) {
                        cronLog("    Athlete #{$athleteId} ({$athlete['full_name']}): no phone → skip SMS");
                        $totalSkipped++;
                    } elseif ($smsOptedOut) {
                        cronLog("    Athlete #{$athleteId} ({$athlete['full_name']}): SMS opt-out → skip");
                        $totalSkipped++;
                    } elseif ($smsAlreadySent) {
                        cronLog("    Athlete #{$athleteId} ({$athlete['full_name']}): SMS already sent " . ($useMonthlyDedup ? 'this month' : 'today') . " → skip");
                        $totalSkipped++;
                    } else {
                        $res = cronSendRuleNotification($db, $school, $rule, $athlete, $sub, 'sms');
                        cronLogReminder($db, $sid, $athleteId, $subId, 'sms', $triggerType, $res['recipient'] ?: $recipPhone, $res['ok']);

                        if ($res['ok']) {
                            $totalSent++;
                            $sentTypes[] = 'sms';
                            cronLog("    ✓ SMS → {$res['recipient']} (" . ($isAdult ? 'adult' : 'minor→parent') . ")");
                        } else {
                            $totalFailed++;
                            cronLog("    ✗ SMS FAILED → " . ($res['recipient'] ?: '[empty]') . " ({$res['reason']})");
                        }
                    }
                } elseif (in_array('sms', $channels, true) && !$hasSms) {
                    cronLog("    SMS skipped (plan/addon not enabled for school #{$sid})");
                    $totalSkipped++;
                }

                if (!empty($sentTypes) && in_array($triggerType, ['on_due', 'days_after', 'has_debt'], true)) {
                    $notifTypeStr = (count($sentTypes) > 1) ? 'both' : $sentTypes[0];
                    cronUpdateAthleteNotified($db, $athleteId, $sid, $notifTypeStr);
                }
            }
        }
    }

    if (date('j') == 1 && function_exists('sendMonthlyIncomeReport')) {
        cronLog("═══ Sending monthly income reports ═══");
        $prevYear  = (int)date('Y', strtotime('first day of last month'));
        $prevMonth = (int)date('m', strtotime('first day of last month'));

        foreach ($schools as $school) {
            $sid = (int)$school['id'];
            try {
                $chk = $db->prepare("SELECT id FROM monthly_income_reports WHERE school_id=? AND report_year=? AND report_month=?");
                $chk->execute([$sid, $prevYear, $prevMonth]);
                if (!$chk->fetchColumn()) {
                    $ok = sendMonthlyIncomeReport($sid, $prevYear, $prevMonth);
                    cronLog("  School #{$sid}: monthly report " . ($ok ? '✓ sent' : '✗ failed'));
                } else {
                    cronLog("  School #{$sid}: monthly report already sent → skip");
                }
            } catch (Exception $e) {
                cronLog("  School #{$sid}: monthly report ERROR — " . $e->getMessage());
            }
        }
    }

    // Summer pause notifications (unchanged)
    try {
        $spRows = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('summer_pause_enabled','summer_pause_month','summer_pause_message','summer_pause_notify_sent_year')")->fetchAll(PDO::FETCH_ASSOC);
        $spCfg = [];
        foreach ($spRows as $r) $spCfg[$r['setting_key']] = $r['setting_value'];

        $spEnabled   = ($spCfg['summer_pause_enabled'] ?? '0') === '1';
        $spMonth     = (int)($spCfg['summer_pause_month'] ?? 7);
        $spSentYear  = (int)($spCfg['summer_pause_notify_sent_year'] ?? 0);
        $curYear     = (int)date('Y');
        $curMonth    = (int)date('n');
        $curDay      = (int)date('j');

        if ($spEnabled && $curMonth === $spMonth && $curDay === 1 && $spSentYear !== $curYear) {
            cronLog("═══ Summer pause notifications START ═══");
            require_once __DIR__ . '/../includes/mailer.php';

            $spMsg = $spCfg['summer_pause_message'] ?? 'Η πλατφόρμα βρίσκεται σε θερινή παύση.';

            $optedSchools = $db->query("
                SELECT s.id, s.name, s.email
                FROM schools s
                JOIN school_meta sm ON sm.school_id = s.id AND sm.meta_key = 'summer_pause_opted_in' AND sm.meta_val = '1'
                WHERE s.active = 1
            ")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($optedSchools as $sc) {
                $scId   = (int)$sc['id'];
                $scName = $sc['name'] ?: 'MAster';

                $teachers = $db->prepare("
                    SELECT u.email, u.name, u.phone
                    FROM users u
                    JOIN user_schools us ON us.user_id = u.id
                    WHERE us.school_id = ? AND u.active = 1
                ");
                $teachers->execute([$scId]);
                $teacherList = $teachers->fetchAll(PDO::FETCH_ASSOC);

                foreach ($teacherList as $teacher) {
                    $tEmail = trim($teacher['email'] ?? '');
                    $tName  = $teacher['name'] ?? '';

                    if ($tEmail && filter_var($tEmail, FILTER_VALIDATE_EMAIL)) {
                        $subject = "Θερινή Παύση — {$scName}";
                        $body    = "Αγαπητέ/ή {$tName},\n\n{$spMsg}\n\nΗ πλατφόρμα MAster έχει μπει σε θερινή παύση για τη σχολή σας.\nΟι αυτόματες ειδοποιήσεις έχουν ανασταλεί μέχρι το τέλος του καλοκαιριού.\n\nΜε εκτίμηση,\nΗ ομάδα MAster";
                        $htmlBody = function_exists('buildEmailHtml') ? buildEmailHtml($body, $scName, $subject) : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
                        $ok = sendEmail($tEmail, $subject, $htmlBody, $body, $tName);
                        cronLog("  Summer pause email → {$tEmail} (" . ($ok ? '✓' : '✗') . ")");
                    }

                    if (cronSchoolCanSendSms($db, $scId)) {
                        $tPhone = trim($teacher['phone'] ?? '');
                        if ($tPhone) {
                            if (function_exists('checkMonthlyUsageLimit') && !checkMonthlyUsageLimit($scId, 'sms')) {
                                cronLog("  Summer pause SMS → {$tPhone} (SKIPPED: monthly SMS limit reached)");
                            } else {
                                $smsText = "Θερινή Παύση MAster: {$spMsg}";
                                $smsErr = null;
                                $ok = sendSms($tPhone, $smsText, $smsErr);
                                cronLog("  Summer pause SMS → {$tPhone} (" . ($ok ? '✓' : '✗') . ")");
                            }
                        }
                    }
                }
            }

            $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('summer_pause_notify_sent_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
               ->execute([$curYear]);

            cronLog("═══ Summer pause notifications DONE (" . count($optedSchools) . " schools) ═══");
        }
    } catch (Throwable $spEx) {
        cronLog("⚠ Summer pause cron error: " . $spEx->getMessage());
    }

    // ── Summer pause END — "Welcome back / Επαναλειτουργία" email ──────────────
    // Fires on day 1 of the month AFTER the pause end month, once per year.
    try {
        $spEndRows = $db->query("
            SELECT setting_key, setting_value FROM system_settings
            WHERE setting_key IN (
                'summer_pause_enabled','summer_pause_end_month',
                'summer_pause_reopening_message','summer_pause_resume_sent_year'
            )
        ")->fetchAll(PDO::FETCH_ASSOC);
        $spEndCfg = [];
        foreach ($spEndRows as $r) $spEndCfg[$r['setting_key']] = $r['setting_value'];

        $spEndEnabled   = ($spEndCfg['summer_pause_enabled'] ?? '0') === '1';
        $spEndMonth     = (int)($spEndCfg['summer_pause_end_month'] ?? 8);
        $spResumeSentYr = (int)($spEndCfg['summer_pause_resume_sent_year'] ?? 0);
        // Resume = day 1 of the month AFTER end month (wraps Dec→Jan)
        $resumeMonth    = $spEndMonth === 12 ? 1 : $spEndMonth + 1;

        if ($spEndEnabled && $curMonth === $resumeMonth && $curDay === 1 && $spResumeSentYr !== $curYear) {
            cronLog("═══ Summer pause RESUME notifications START ═══");
            require_once __DIR__ . '/../includes/mailer.php';

            $spReopeningMsg = trim($spEndCfg['summer_pause_reopening_message'] ?? '')
                ?: 'Καλώς ήρθατε πίσω! Η πλατφόρμα επαναλειτουργεί μετά τη θερινή παύση. Ελέγξτε τους αθλητές σας και ενεργοποιήστε ξανά τις ειδοποιήσεις.';

            $resumeOptedSchools = $db->query("
                SELECT s.id, s.name, s.email
                FROM schools s
                JOIN school_meta sm
                  ON sm.school_id = s.id
                 AND sm.meta_key  = 'summer_pause_opted_in'
                 AND sm.meta_val  = '1'
                WHERE s.active = 1
            ")->fetchAll(PDO::FETCH_ASSOC);

            $resumeSent = 0;
            foreach ($resumeOptedSchools as $sc) {
                $scId   = (int)$sc['id'];
                $scName = $sc['name'] ?: 'MAster';

                $teachStmt = $db->prepare("
                    SELECT u.email, u.name
                    FROM users u
                    JOIN user_schools us ON us.user_id = u.id
                    WHERE us.school_id = ? AND u.active = 1
                ");
                $teachStmt->execute([$scId]);
                $teacherList = $teachStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($teacherList as $teacher) {
                    $tEmail = trim($teacher['email'] ?? '');
                    $tName  = trim($teacher['name']  ?? '');
                    if (!$tEmail || !filter_var($tEmail, FILTER_VALIDATE_EMAIL)) continue;

                    $subject  = "Επαναλειτουργία Πλατφόρμας — {$scName}";
                    $body     = "Αγαπητέ/ή {$tName},\n\n"
                              . $spReopeningMsg
                              . "\n\nΗ πλατφόρμα MAster επαναλειτουργεί κανονικά για τη σχολή σας.\n"
                              . "Οι αυτόματες ειδοποιήσεις SMS/email στους αθλητές ξεκινούν ξανά αμέσως.\n\n"
                              . "Με εκτίμηση,\nΗ ομάδα MAster";
                    $htmlBody = function_exists('buildEmailHtml')
                        ? buildEmailHtml($body, $scName, $subject)
                        : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

                    $ok = sendEmail($tEmail, $subject, $htmlBody, $body, $tName);
                    cronLog("  Resume email → {$tEmail} [{$scName}] (" . ($ok ? '✓' : '✗') . ")");
                    if ($ok) $resumeSent++;
                }
            }

            // Mark as sent for this year
            $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value) VALUES ('summer_pause_resume_sent_year', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute([$curYear]);

            cronLog("═══ Summer pause RESUME done — {$resumeSent} emails sent to " . count($resumeOptedSchools) . " schools ═══");
        }
    } catch (Throwable $spResEx) {
        cronLog("⚠ Summer pause resume error: " . $spResEx->getMessage());
    }
    // ─────────────────────────────────────────────────────────────────────────────

    $summary = "Sent={$totalSent} | Failed={$totalFailed} | Skipped={$totalSkipped}";
    cronLog("═══ DONE ═══ {$summary}");

    $db->prepare("
        UPDATE cron_runs
        SET finished_at = NOW(), status = 'success', message = ?
        WHERE id = ?
    ")->execute([$summary, $runId]);

} catch (Throwable $e) {
    $msg = 'Cron crashed: ' . $e->getMessage();
    cronLog("✗ FATAL: {$msg}");

    $db->prepare("
        UPDATE cron_runs
        SET finished_at = NOW(), status = 'failed', message = ?
        WHERE id = ?
    ")->execute([$msg, $runId]);

    if (PHP_SAPI === 'cli') {
        exit(1);
    }

    http_response_code(500);
    echo $msg;
    exit;
}