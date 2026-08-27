<?php
/**
 * ============================================================
 * pages/notifications.php — Κέντρο Ειδοποιήσεων Σχολής
 * IMPROVED: Pre-filled templates with highlighted inline tokens
 * UPDATED:  Red scrollbars + scroll hint arrows on both modals
 * UPDATED:  SMS-first defaults + friendlier SMS segment warnings
 * UPDATED:  Default rule = immediate past-debt notification
 * UPDATED:  History pagination + live search (mobile-friendly)
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/overage_popup.php';
requireLogin();
renderPaymentWall();

$db  = getDB();
$sid = schoolId();

$schoolStmt = $db->prepare("SELECT name, email FROM schools WHERE id=?");
$schoolStmt->execute([$sid]);
$schoolRow  = $schoolStmt->fetch() ?: ['name' => APP_NAME, 'email' => null];
$schoolName = $schoolRow['name'] ?: APP_NAME;
$hasSms     = schoolCanSendSms();

$isAdminUser = (($_SESSION['user']['role_name'] ?? '') === 'admin');

// ── Δημιουργία πινάκων αν δεν υπάρχουν ──────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS notification_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    rule_name VARCHAR(120) NOT NULL,
	trigger_type ENUM('days_before','on_due','days_after','after_payment','has_debt') NOT NULL,    
	trigger_days INT NOT NULL DEFAULT 0,
    channels VARCHAR(20) NOT NULL DEFAULT 'sms',
    active TINYINT(1) NOT NULL DEFAULT 1,
    subject_tpl VARCHAR(255) DEFAULT NULL,
    body_tpl TEXT DEFAULT NULL,
    include_name TINYINT(1) NOT NULL DEFAULT 1,
    include_date TINYINT(1) NOT NULL DEFAULT 1,
    include_amount TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_school (school_id)
)");

$db->exec("CREATE TABLE IF NOT EXISTS school_meta (
    school_id INT NOT NULL,
    meta_key VARCHAR(80) NOT NULL,
    meta_val VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (school_id, meta_key)
)");

$db->exec("CREATE TABLE IF NOT EXISTS broadcast_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    body TEXT NOT NULL,
    channels VARCHAR(20) NOT NULL DEFAULT 'sms',
    recipient_filter VARCHAR(50) NOT NULL DEFAULT 'all',
    total_sent INT NOT NULL DEFAULT 0,
    total_failed INT NOT NULL DEFAULT 0,
    status ENUM('sending','done','failed') NOT NULL DEFAULT 'sending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_school (school_id)
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

// ── Seed αρχικών κανόνων αν δεν υπάρχουν ────────────────────
$seedStmt = $db->prepare("SELECT COUNT(*) FROM school_meta WHERE school_id=? AND meta_key='notif_seeded'");
$seedStmt->execute([$sid]);
$seeded = (int)$seedStmt->fetchColumn();

if (!$seeded) {
    // Default rule: notify ALL athletes who have a debt (once per month, any channel)
    $defaultSeedChannels = 'email';

    $ins = $db->prepare("INSERT INTO notification_rules (school_id,rule_name,trigger_type,trigger_days,channels,active,subject_tpl,body_tpl,include_name,include_date,include_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)");

    $ins->execute([
        $sid,
        'Ειδοποίηση εκκρεμούς οφειλής',
        'has_debt',
        0,
        $defaultSeedChannels,
        1,
        'Εκκρεμής οφειλή συνδρομής — ' . $schoolName,
        "Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε ότι εκκρεμεί η συνδρομή του/της {{athlete_name}}.\nΠοσό: {{amount}}€\n\nΠαρακαλούμε να τακτοποιηθεί. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{{school_name}}",
        1,
        0,
        1
    ]);

    $newDefaultId = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO school_meta (school_id,meta_key,meta_val) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE meta_val=VALUES(meta_val)
    ")->execute([$sid, 'notif_seeded', '1']);

    $db->prepare("
        INSERT INTO school_meta (school_id,meta_key,meta_val) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE meta_val=VALUES(meta_val)
    ")->execute([$sid, 'default_rule_id', $newDefaultId]);
}

// ── Fetch the protected default rule ID ──────────────────────
$defRuleStmt = $db->prepare("SELECT meta_val FROM school_meta WHERE school_id=? AND meta_key='default_rule_id'");
$defRuleStmt->execute([$sid]);
$defaultRuleId = (int)($defRuleStmt->fetchColumn() ?: 0);

// Safety / self-healing: ensure there is always a valid has_debt (or days_after) rule as the default.
if (!$defaultRuleId) {
    // Prefer has_debt; fall back to days_after for older setups
    $daStmt = $db->prepare("SELECT id FROM notification_rules WHERE school_id=? AND trigger_type IN ('has_debt','days_after') ORDER BY FIELD(trigger_type,'has_debt','days_after'), id ASC LIMIT 1");
    $daStmt->execute([$sid]);
    $daId = (int)($daStmt->fetchColumn() ?: 0);

    if (!$daId) {
        $db->prepare("INSERT INTO notification_rules (school_id,rule_name,trigger_type,trigger_days,channels,active,subject_tpl,body_tpl,include_name,include_date,include_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $sid,
               'Ειδοποίηση εκκρεμούς οφειλής',
               'has_debt', 0, 'email', 1,
               'Εκκρεμής οφειλή συνδρομής — ' . $schoolName,
               "Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε ότι εκκρεμεί η συνδρομή του/της {{athlete_name}}.\nΠοσό: {{amount}}€\n\nΠαρακαλούμε να τακτοποιηθεί. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{{school_name}}",
               1, 0, 1,
           ]);
        $daId = (int)$db->lastInsertId();
    }

    $defaultRuleId = $daId;
    $db->prepare("
        INSERT INTO school_meta (school_id,meta_key,meta_val) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE meta_val=VALUES(meta_val)
    ")->execute([$sid, 'default_rule_id', $defaultRuleId]);
} else {
    $checkStmt = $db->prepare("SELECT trigger_type FROM notification_rules WHERE id=? AND school_id=?");
    $checkStmt->execute([$defaultRuleId, $sid]);
    $storedType = $checkStmt->fetchColumn();

    // Accept both has_debt and days_after as valid default types
    if (!in_array($storedType, ['has_debt', 'days_after'], true)) {
        $daStmt = $db->prepare("SELECT id FROM notification_rules WHERE school_id=? AND trigger_type IN ('has_debt','days_after') ORDER BY FIELD(trigger_type,'has_debt','days_after'), id ASC LIMIT 1");
        $daStmt->execute([$sid]);
        $daId = (int)($daStmt->fetchColumn() ?: 0);

        if (!$daId) {
            $db->prepare("INSERT INTO notification_rules (school_id,rule_name,trigger_type,trigger_days,channels,active,subject_tpl,body_tpl,include_name,include_date,include_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $sid,
                   'Ειδοποίηση εκκρεμούς οφειλής',
                   'has_debt', 0, 'email', 1,
                   'Εκκρεμής οφειλή συνδρομής — ' . $schoolName,
                   "Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε ότι εκκρεμεί η συνδρομή του/της {{athlete_name}}.\nΠοσό: {{amount}}€\n\nΠαρακαλούμε να τακτοποιηθεί. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{{school_name}}",
                   1, 0, 1,
               ]);
            $daId = (int)$db->lastInsertId();
        }

        $defaultRuleId = $daId;
        $db->prepare("
            INSERT INTO school_meta (school_id,meta_key,meta_val) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE meta_val=VALUES(meta_val)
        ")->execute([$sid, 'default_rule_id', $defaultRuleId]);
    }
}

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_rule') {
        $a  = $_POST;
        $id = (int)($a['id'] ?? 0);

        $chs = [];
        if (!empty($a['ch_sms']) && $hasSms) $chs[] = 'sms';
        if (!empty($a['ch_email']))          $chs[] = 'email';
        if (!$chs) $chs = [$hasSms ? 'sms' : 'email'];

        // Non-default rules can only be days_before; the default rule (has_debt/days_after) is protected
        $isEditingDefault = ($id === $defaultRuleId);
        if ($isEditingDefault) {
            // Preserve existing trigger type for the default rule (has_debt or days_after)
            $defTypeStmt = $db->prepare("SELECT trigger_type FROM notification_rules WHERE id=? AND school_id=?");
            $defTypeStmt->execute([$id, $sid]);
            $allowedTrigger = $defTypeStmt->fetchColumn() ?: 'has_debt';
        } else {
            $allowedTrigger = 'days_before';
        }

        $data = [
            trim($a['rule_name']    ?? ''),
            $allowedTrigger,
            (int)($a['trigger_days'] ?? 0),
            implode(',', $chs),
            1,
            trim($a['subject_tpl'] ?? '') ?: null,
            trim($a['body_tpl']    ?? '') ?: null,
            isset($a['include_name'])   ? 1 : 0,
            isset($a['include_date'])   ? 1 : 0,
            isset($a['include_amount']) ? 1 : 0,
        ];

        if ($id) {
            $db->prepare("UPDATE notification_rules SET rule_name=?,trigger_type=?,trigger_days=?,channels=?,active=?,subject_tpl=?,body_tpl=?,include_name=?,include_date=?,include_amount=? WHERE id=? AND school_id=?")
               ->execute([...$data, $id, $sid]);
            flash('Η υπενθύμιση ενημερώθηκε!');
        } else {
            $db->prepare("INSERT INTO notification_rules (school_id,rule_name,trigger_type,trigger_days,channels,active,subject_tpl,body_tpl,include_name,include_date,include_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$sid, ...$data]);
            flash('Νέα υπενθύμιση δημιουργήθηκε!');
        }
    }

    if ($action === 'delete_rule') {
        $ruleIdToDel = (int)($_POST['id'] ?? 0);
        if ($ruleIdToDel && $ruleIdToDel !== $defaultRuleId) {
            $db->prepare("DELETE FROM notification_rules WHERE id=? AND school_id=?")
               ->execute([$ruleIdToDel, $sid]);
            flash('Η υπενθύμιση διαγράφηκε.', 'danger');
        } else {
            flash('Η προεπιλεγμένη υπενθύμιση δεν μπορεί να διαγραφεί.', 'danger');
        }
    }

    if ($action === 'toggle_rule') {
        $ruleIdToToggle = (int)($_POST['id'] ?? 0);
        if ($ruleIdToToggle && $ruleIdToToggle !== $defaultRuleId) {
            $db->prepare("UPDATE notification_rules SET active=1-active WHERE id=? AND school_id=?")
               ->execute([$ruleIdToToggle, $sid]);
        }
    }

    if ($action === 'send_broadcast') {
        // Block sending during summer pause
        if (function_exists('isSchoolInSummerPause') && isSchoolInSummerPause($sid)) {
            flash('Η αποστολή ειδοποιήσεων είναι ανασταλμένη κατά τη διάρκεια της θερινής παύσης. Απενεργοποιήστε τη θερινή παύση από τις Ρυθμίσεις για να συνεχίσετε.', 'warning');
            redirect(APP_URL . '/pages/notifications.php');
        }

        $bcSubject = trim($_POST['bc_subject'] ?? '');
        $bcBody    = trim($_POST['bc_body']    ?? '');
        $bcFilter  = $_POST['bc_filter']       ?? 'all';
        $bcChs     = [];
        if (!empty($_POST['bc_ch_sms']) && $hasSms) $bcChs[] = 'sms';
        if (!empty($_POST['bc_ch_email']))          $bcChs[] = 'email';
        if (!$bcChs) $bcChs = [$hasSms ? 'sms' : 'email'];

        if (!$bcBody) {
            flash('Το κείμενο μηνύματος είναι υποχρεωτικό.', 'danger');
            redirect(APP_URL . '/pages/notifications.php');
        }

        // Block SMS broadcast if monthly limit already reached
        if (in_array('sms', $bcChs) && !checkMonthlyUsageLimit($sid, 'sms')) {
            flash('Έχετε φτάσει το μηνιαίο όριο SMS. Αγοράστε επιπλέον πακέτο για να συνεχίσετε.', 'danger');
            redirect(APP_URL . '/pages/notifications.php');
        }

        $bcAthletes = [];
        if ($bcFilter === 'all') {
            $st = $db->prepare("SELECT id, full_name, email, parent_email, phone, parent_phone, birthdate FROM athletes WHERE school_id=? AND active=1 ORDER BY full_name");
            $st->execute([$sid]);
            $bcAthletes = $st->fetchAll();
        } elseif ($bcFilter === 'department') {
            $deptId = (int)($_POST['bc_dept_id'] ?? 0);
            if ($deptId) {
                $st = $db->prepare("SELECT a.id, a.full_name, a.email, a.parent_email, a.phone, a.parent_phone, a.birthdate FROM athletes a JOIN departments d ON d.id=a.department_id WHERE a.school_id=? AND a.department_id=? AND a.active=1 AND d.school_id=? ORDER BY a.full_name");
                $st->execute([$sid, $deptId, $sid]);
                $bcAthletes = $st->fetchAll();
            }
        } elseif ($bcFilter === 'active_sub') {
            $st = $db->prepare("SELECT a.id, a.full_name, a.email, a.parent_email, a.phone, a.parent_phone, a.birthdate FROM athletes a JOIN subscriptions s ON s.athlete_id=a.id WHERE a.school_id=? AND a.active=1 AND s.valid_until >= CURDATE() GROUP BY a.id ORDER BY a.full_name");
            $st->execute([$sid]);
            $bcAthletes = $st->fetchAll();
        } elseif ($bcFilter === 'expired_sub') {
            $st = $db->prepare("SELECT a.id, a.full_name, a.email, a.parent_email, a.phone, a.parent_phone, a.birthdate FROM athletes a LEFT JOIN subscriptions s ON s.athlete_id=a.id WHERE a.school_id=? AND a.active=1 GROUP BY a.id HAVING (MAX(s.valid_until) IS NULL OR MAX(s.valid_until) < CURDATE()) ORDER BY a.full_name");
            $st->execute([$sid]);
            $bcAthletes = $st->fetchAll();
        } elseif ($bcFilter === 'custom') {
            $rawIds = isset($_POST['bc_athletes']) && is_array($_POST['bc_athletes'])
                ? array_map('intval', $_POST['bc_athletes']) : [];
            $rawIds = array_filter($rawIds);
            if ($rawIds) {
                $ph2 = implode(',', array_fill(0, count($rawIds), '?'));
                $st  = $db->prepare("SELECT id, full_name, email, parent_email, phone, parent_phone, birthdate FROM athletes WHERE school_id=? AND id IN ($ph2) AND active=1 ORDER BY full_name");
                $st->execute([$sid, ...$rawIds]);
                $bcAthletes = $st->fetchAll();
            }
        }

        $bcStmt = $db->prepare("INSERT INTO broadcast_messages (school_id, subject, body, channels, recipient_filter, status) VALUES (?,?,?,?,?,?)");
        $bcStmt->execute([$sid, $bcSubject ?: null, $bcBody, implode(',', $bcChs), $bcFilter, 'sending']);
        $broadcastId = (int)$db->lastInsertId();

        $totalSent = 0;
        $totalFailed = 0;
        // Track WHY each send failed so the user sees a truthful summary
        // instead of a vague "ελλιπή στοιχεία επικοινωνίας" catch-all.
        $failReasons = ['missing' => 0, 'limit' => 0, 'provider' => 0];
        $failSamples = [];   // Up to 5 rows for the user-facing hint

        foreach ($bcAthletes as $ath) {
            $isAdult = true;
            if (!empty($ath['birthdate'])) {
                try {
                    $isAdult = ((new DateTime())->diff(new DateTime($ath['birthdate']))->y >= 18);
                } catch (Exception $e) {}
            }

            $personalBody    = str_replace(['{{athlete_name}}', '{{school_name}}'], [$ath['full_name'], $schoolName], $bcBody);
            $personalSubject = str_replace(['{{athlete_name}}', '{{school_name}}'], [$ath['full_name'], $schoolName], $bcSubject);

            foreach ($bcChs as $ch) {
                $sent      = false;
                $reasonKey = null;
                $reasonMsg = null;

                if ($ch === 'email') {
                    $toEmail = $isAdult
                        ? (trim($ath['email'] ?? '') ?: trim($ath['parent_email'] ?? ''))
                        : (trim($ath['parent_email'] ?? '') ?: trim($ath['email'] ?? ''));

                    if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                        $reasonKey = 'missing';
                        $reasonMsg = 'χωρίς έγκυρο email';
                    } else {
                        $htmlBody = function_exists('buildEmailHtml')
                            ? buildEmailHtml($personalBody, $schoolName, $personalSubject ?: 'Ανακοίνωση — ' . $schoolName)
                            : nl2br(htmlspecialchars($personalBody, ENT_QUOTES, 'UTF-8'));
                        $emailDbg = null;
                        $sent = sendEmail($toEmail, $personalSubject ?: 'Ανακοίνωση — ' . $schoolName, $htmlBody, $personalBody, $ath['full_name'], $emailDbg, null, $schoolName);
                        if (!$sent) {
                            $reasonKey = 'provider';
                            $reasonMsg = 'σφάλμα αποστολής email' . ($emailDbg ? ' (' . mb_substr($emailDbg, 0, 60) . ')' : '');
                            error_log('[notifications.php] Email failed to ' . $toEmail . ': ' . ($emailDbg ?? 'unknown'));
                        }
                    }
                } else {
                    $phone = $isAdult
                        ? (trim($ath['phone'] ?? '') ?: trim($ath['parent_phone'] ?? ''))
                        : (trim($ath['parent_phone'] ?? '') ?: trim($ath['phone'] ?? ''));

                    if (!$phone) {
                        $reasonKey = 'missing';
                        $reasonMsg = 'χωρίς τηλέφωνο';
                    } elseif (!checkMonthlyUsageLimit($sid, 'sms')) {
                        $reasonKey = 'limit';
                        $reasonMsg = 'εξαντλήθηκε το μηνιαίο όριο SMS';
                    } else {
                        $smsErr = null;
                        $sent = sendSms($phone, strip_tags($personalBody), $smsErr);
                        if (!$sent) {
                            $reasonKey = 'provider';
                            $reasonMsg = 'σφάλμα αποστολής SMS' . ($smsErr ? ' (' . mb_substr($smsErr, 0, 60) . ')' : '');
                            error_log('[notifications.php] SMS failed to ' . $phone . ': ' . ($smsErr ?? 'unknown'));
                        }
                    }
                }

                if ($sent) {
                    $totalSent++;
                } else {
                    $totalFailed++;
                    if ($reasonKey) $failReasons[$reasonKey]++;
                    if (count($failSamples) < 5 && $reasonMsg) {
                        $failSamples[] = h($ath['full_name']) . ' — ' . h($reasonMsg);
                    }
                }
            }
        }

        $db->prepare("UPDATE broadcast_messages SET status='done', total_sent=?, total_failed=?, sent_at=NOW() WHERE id=?")
           ->execute([$totalSent, $totalFailed, $broadcastId]);

        $msg = "Η ανακοίνωση εστάλη σε <strong>{$totalSent}</strong> παραλήπτες!";
        if ($totalFailed > 0) {
            // Build a truthful reason breakdown
            $parts = [];
            if ($failReasons['missing']  > 0) $parts[] = $failReasons['missing']  . ' χωρίς στοιχεία επικοινωνίας';
            if ($failReasons['limit']    > 0) $parts[] = $failReasons['limit']    . ' εκτός ορίου SMS';
            if ($failReasons['provider'] > 0) $parts[] = $failReasons['provider'] . ' αποτυχία αποστολής παρόχου';
            $reasonSummary = $parts ? implode(' · ', $parts) : 'άγνωστη αιτία';
            $msg .= " (<strong>{$totalFailed}</strong> απέτυχαν — {$reasonSummary})";
            if ($failSamples) {
                $msg .= '<br><small style="display:block;margin-top:.35rem;opacity:.85">Παραδείγματα: ' . implode(' · ', $failSamples) . '</small>';
            }
        }
        flash($msg, $totalFailed > 0 && $totalSent === 0 ? 'danger' : 'success');
    }

    redirect(APP_URL . '/pages/notifications.php');
}

// ── Summer pause state for this school ───────────────────────
$schoolInSummerPause = function_exists('isSchoolInSummerPause') && isSchoolInSummerPause($sid);

// ── Δεδομένα σελίδας ─────────────────────────────────────────
$rulesStmt = $db->prepare("SELECT * FROM notification_rules WHERE school_id=? ORDER BY FIELD(trigger_type,'days_before','on_due','days_after','after_payment'), trigger_days");
$rulesStmt->execute([$sid]);
$rules = $rulesStmt->fetchAll();

$activeCount = count(array_filter($rules, fn($r) => $r['active']));

$sentStmt = $db->prepare("SELECT COUNT(*) FROM reminder_logs WHERE school_id=? AND status='sent' AND sent_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
$sentStmt->execute([$sid]);
$sentCount = (int)$sentStmt->fetchColumn();

$failStmt = $db->prepare("SELECT COUNT(*) FROM reminder_logs WHERE school_id=? AND status='failed' AND sent_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
$failStmt->execute([$sid]);
$failCount = (int)$failStmt->fetchColumn();

$runStmt = $db->prepare("SELECT * FROM cron_runs WHERE job_name='reminders' ORDER BY started_at DESC, id DESC LIMIT 1");
$runStmt->execute();
$lastCronRun = $runStmt->fetch();

$lastRun = $lastCronRun['finished_at'] ?? $lastCronRun['started_at'] ?? null;
$cronHealthy = $lastRun && ((time() - strtotime($lastRun)) <= 25 * 3600);
$lastCronStatus = $lastCronRun['status'] ?? null;

$showSystemStatus = $isAdminUser;
$systemStatusClass = 'warning';
$systemStatusDot = 'gold';
$systemStatusTitle = 'Υπάρχει καθυστέρηση στην ενημέρωση';
$systemStatusSub = $lastRun ? 'Τελευταία ενημέρωση: ' . date('d/m/Y H:i', strtotime($lastRun)) : 'Δεν υπάρχει ακόμη διαθέσιμη ενημέρωση.';

if ($cronHealthy && $lastCronStatus === 'success') {
    $systemStatusClass = 'healthy';
    $systemStatusDot = 'green';
    $systemStatusTitle = 'Το σύστημα λειτουργεί κανονικά';
    $systemStatusSub = $lastRun ? 'Τελευταία ενημέρωση: ' . date('d/m/Y H:i', strtotime($lastRun)) : 'Το σύστημα λειτουργεί κανονικά';
}

// History: fetch all, paginate in JS (10 per page)
$historyStmt = $db->prepare("
    SELECT rl.*, a.full_name
    FROM reminder_logs rl
    LEFT JOIN athletes a ON a.id = rl.athlete_id
    WHERE rl.school_id = ?
    ORDER BY rl.sent_at DESC
    LIMIT 200
");
$historyStmt->execute([$sid]);
$history = $historyStmt->fetchAll();

// Broadcasts: fetch all, paginate in JS (10 per page)
$bcStmt = $db->prepare("SELECT * FROM broadcast_messages WHERE school_id=? ORDER BY created_at DESC LIMIT 200");
$bcStmt->execute([$sid]);
$broadcasts = $bcStmt->fetchAll();

$bcCountStmt = $db->prepare("SELECT COUNT(*) FROM broadcast_messages WHERE school_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
$bcCountStmt->execute([$sid]);
$broadcastCount = (int)$bcCountStmt->fetchColumn();

$allAthletesStmt = $db->prepare("
    SELECT a.id, a.full_name, a.email, a.parent_email, a.phone, a.parent_phone, a.birthdate,
           a.department_id, MAX(s.valid_until) as valid_until
    FROM athletes a
    LEFT JOIN subscriptions s ON s.athlete_id=a.id AND s.status='paid'
    WHERE a.school_id=? AND a.active=1
    GROUP BY a.id
    ORDER BY a.full_name
");
$allAthletesStmt->execute([$sid]);
$allAthletes = $allAthletesStmt->fetchAll();

$bcDepts = $db->prepare("SELECT id, name FROM departments WHERE school_id=? AND active=1 ORDER BY name");
$bcDepts->execute([$sid]);
$broadcastDepts = $bcDepts->fetchAll();

function triggerLabel(string $t, int $d): string {
    return match($t) {
        'days_before'   => $d . ' μέρες πριν τη λήξη',
        'days_after'    => $d . ' μέρες μετά τη λήξη',
        'on_due'        => 'Την ημέρα λήξης',
        'after_payment' => 'Μετά από κάθε πληρωμή',
        'has_debt'      => 'Αυτόματη — εκκρεμείς οφειλές (1×/μήνα)',
        default         => $t,
    };
}
function triggerPillClass(string $t): string {
    return match($t) {
        'days_before'   => 'pill-before',
        'on_due'        => 'pill-due',
        'days_after'    => 'pill-after',
        'after_payment' => 'pill-payment',
        'has_debt'      => 'pill-after',
        default         => 'pill-before',
    };
}
function triggerIcon(string $t): string {
    return match($t) {
        'days_before'   => 'fa-clock',
        'on_due'        => 'fa-bell',
        'days_after'    => 'fa-triangle-exclamation',
        'after_payment' => 'fa-circle-check',
        'has_debt'      => 'fa-triangle-exclamation',
        default         => 'fa-bell',
    };
}

renderHead('Αυτόματες Ειδοποιήσεις');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ────────────────────────────────────────────────────────────
   BASE + LAYOUT
──────────────────────────────────────────────────────────── */
.topbar{position:relative!important;top:auto!important;z-index:auto!important}
@media(max-width:900px){
  #menuBtn{display:inline-flex!important;min-width:44px!important;min-height:44px!important;align-items:center!important;justify-content:center!important;font-size:1.2rem!important;cursor:pointer!important}
  .sidebar{position:fixed!important;top:0!important;left:0!important;bottom:0!important;width:min(280px,80vw)!important;z-index:9999!important;transform:translateX(-110%)!important;transition:transform .28s cubic-bezier(.2,.8,.2,1)!important;overflow-y:auto}
  .sidebar.open{transform:translateX(0)!important;box-shadow:6px 0 40px rgba(0,0,0,.6)!important}
  .main-content{margin-left:0!important;width:100%!important}
}
#dm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9998;cursor:pointer}
#dm-overlay.on{display:block}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
@keyframes checkPop{0%{transform:scale(0)}70%{transform:scale(1.2)}100%{transform:scale(1)}}
@keyframes modalIn{from{opacity:0;transform:translateY(20px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes spin{to{transform:rotate(360deg)}}
.page-body{animation:fadeIn .35s ease both}
.anim-1{opacity:0;animation:fadeUp .42s ease-out .05s both}
.anim-2{opacity:0;animation:fadeUp .42s ease-out .12s both}
.anim-3{opacity:0;animation:fadeUp .42s ease-out .19s both}
@media(prefers-reduced-motion:reduce){.page-body,.anim-1,.anim-2,.anim-3{animation:none!important;opacity:1}}

/* ────────────────────────────────────────────────────────────
   STAT CARDS
──────────────────────────────────────────────────────────── */
.stat-cards-row{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.3rem}
.stat-card{border-radius:18px;padding:1rem 1.1rem;display:flex;flex-direction:row;align-items:center;justify-content:flex-start;gap:.85rem;text-align:left}
.stat-icon{width:48px;height:48px;min-width:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.icon-blue{background:rgba(59,130,246,.15);color:#3b82f6}
.icon-green{background:rgba(45,198,83,.15);color:var(--green,#2dc653)}
.icon-red{background:rgba(230,57,70,.15);color:var(--red,#e63946)}
.icon-purple{background:rgba(139,92,246,.15);color:#8b5cf6}
.stat-text{display:flex;flex-direction:column;gap:.1rem;min-width:0}
.stat-lbl{font-size:.88rem!important;color:var(--muted,#8892b0);font-weight:600;line-height:1.2;white-space:nowrap}
.stat-val{font-size:2rem!important;font-weight:800;line-height:1}
@media(max-width:800px){.stat-cards-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.stat-cards-row{grid-template-columns:repeat(2,1fr);gap:.5rem}.stat-card{padding:.75rem .8rem;gap:.5rem}.stat-icon{width:36px;height:36px;min-width:36px;font-size:.95rem;border-radius:10px}}

/* ────────────────────────────────────────────────────────────
   PAGE HEADER
──────────────────────────────────────────────────────────── */
.page-hd{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem}
.page-hd h2{font-size:1.4rem!important;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0}

/* ────────────────────────────────────────────────────────────
   CRON BANNER
──────────────────────────────────────────────────────────── */
.cron-banner{border-radius:16px;padding:1rem 1.2rem;display:flex;gap:1rem;align-items:center;margin-bottom:1.3rem;flex-wrap:wrap}
.cron-banner.healthy{background:rgba(45,198,83,.07);border:1px solid rgba(45,198,83,.25)}
.cron-banner.warning{background:rgba(240,165,0,.07);border:1px solid rgba(240,165,0,.3)}
.cron-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.cron-dot.green{background:#2dc653;box-shadow:0 0 8px rgba(45,198,83,.6);animation:pulse 2s infinite}
.cron-dot.gold{background:#f0a500;animation:pulse 2s infinite}

/* ────────────────────────────────────────────────────────────
   TABS
──────────────────────────────────────────────────────────── */
/* Tabs — enlarged & white for accessibility (matches events.php) */
.tabs-bar{
  display:flex;gap:.5rem;margin-bottom:1.4rem;flex-wrap:wrap;
  background:#0d1117;border:1px solid #1e2536;border-radius:14px;padding:.45rem;
  width:fit-content;max-width:100%
}
.tab-btn{
  min-height:48px;padding:.85rem 1.5rem;border-radius:11px;
  border:none;background:transparent;
  color:#ffffff !important;
  font-size:1.08rem !important;font-weight:800;letter-spacing:.01em;
  cursor:pointer;transition:all .18s;font-family:inherit;
  display:inline-flex;align-items:center;gap:.6rem;line-height:1.2
}
.tab-btn i{ color:#ffffff !important;font-size:1.1rem }
.tab-btn:hover{ background:rgba(255,255,255,.06);transform:translateY(-1px) }
.tab-btn.active{
  background:linear-gradient(135deg,#e63946 0%,#c72832 100%) !important;
  color:#ffffff !important;
  box-shadow:0 6px 18px -6px rgba(230,57,70,.65), inset 0 0 0 1px rgba(255,255,255,.15)
}
.tab-btn.active i{ color:#ffffff !important }
/* Count pills inside tabs — inherit visibility so numbers read cleanly */
.tab-btn > span{
  background:rgba(255,255,255,.16) !important;
  color:#ffffff !important;
  border-radius:50px !important;
  padding:.2rem .65rem !important;
  font-size:.85rem !important;font-weight:900 !important;
  min-width:26px;text-align:center
}
.tab-btn.active > span{ background:rgba(0,0,0,.28) !important }
@media (max-width:520px){
  .tab-btn{padding:.7rem 1rem;font-size:1rem !important}
  .tab-btn > span{font-size:.78rem !important}
}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* ────────────────────────────────────────────────────────────
   RULES TABLE (was: cards)
──────────────────────────────────────────────────────────── */
.rules-list{border:1px solid var(--border,#1e2536);border-radius:14px;overflow:hidden;background:var(--card-bg,#131929)}
.rules-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.rules-table{width:100%;border-collapse:collapse;font-size:.94rem;min-width:720px}
.rules-table thead th{
  background:rgba(255,255,255,.04)!important;
  color:#ffffff!important;
  font-size:clamp(.86rem,2.4vw,.98rem)!important;
  font-weight:800!important;
  letter-spacing:.05em!important;
  text-transform:uppercase!important;
  padding:.75rem .85rem!important;
  text-align:left;
  border-bottom:1px solid rgba(255,255,255,.12);
  white-space:nowrap;
}
.rules-table tbody tr{cursor:pointer;transition:background .15s}
.rules-table tbody tr:hover{background:rgba(255,255,255,.04)}
.rules-table tbody tr.is-off{opacity:.5}
.rules-table td{padding:.7rem .85rem;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}
.rules-table tbody tr:last-child td{border-bottom:none}
.rule-toggle-btn{background:none;border:none;cursor:pointer;padding:0;display:inline-flex;align-items:center}
.rule-toggle-btn i{font-size:1.65rem;transition:color .2s}
.rule-name-cell{font-weight:800;color:#ffffff;font-size:1rem;display:flex;flex-direction:column;gap:.25rem}
.rule-name-cell .default-tag{
  display:inline-flex;align-items:center;gap:.25rem;
  background:rgba(240,165,0,.15);color:#f0a500;
  padding:.1rem .5rem;border-radius:50px;
  font-size:.68rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;
  width:fit-content;
}
.rule-body-preview{font-size:.86rem;color:var(--muted,#8892b0);line-height:1.4;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;
  overflow:hidden;max-width:340px}
.rule-meta-inline{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap}
.rule-actions{display:flex;gap:.35rem;flex-wrap:nowrap;justify-content:flex-end}
.rule-actions .btn-sm{padding:.4rem .65rem;font-size:.8rem;white-space:nowrap}
@media (max-width:768px){
  .rules-table{font-size:.88rem}
  .rules-table thead th{font-size:.78rem!important;padding:.55rem .6rem!important}
  .rules-table td{padding:.55rem .6rem}
  .col-hide-md{display:none!important}
}

/* ────────────────────────────────────────────────────────────
   PILLS
──────────────────────────────────────────────────────────── */
.pill{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:999px;font-size:.82rem!important;font-weight:800;white-space:nowrap}
.pill-before{background:rgba(59,130,246,.15);color:#3b82f6}
.pill-due{background:rgba(240,165,0,.15);color:var(--gold,#f0a500)}
.pill-after{background:rgba(230,57,70,.15);color:var(--red,#e63946)}
.pill-payment{background:rgba(45,198,83,.15);color:var(--green,#2dc653)}
.pill-ch{background:rgba(255,255,255,.07);color:var(--muted,#8892b0)}

/* ────────────────────────────────────────────────────────────
   HISTORY TOOLBAR (search + pagination)
──────────────────────────────────────────────────────────── */
.hist-toolbar{display:flex;align-items:center;gap:.6rem;margin-bottom:.7rem;flex-wrap:wrap}
.hist-search-wrap{position:relative;flex:1;min-width:180px;max-width:320px}
.hist-search-wrap input{padding-left:2.3rem!important;min-height:40px;font-size:.9rem!important}
.hist-search-wrap .si{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:var(--muted,#8892b0);font-size:.82rem;pointer-events:none}
.pager{display:flex;align-items:center;gap:.3rem;flex-wrap:wrap}
.pager-btn{min-width:34px;height:34px;border-radius:8px;border:1.5px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0);font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;padding:0 .5rem}
.pager-btn:hover{border-color:var(--red,#e63946);color:var(--red,#e63946)}
.pager-btn.active{border-color:var(--red,#e63946);background:rgba(230,57,70,.12);color:var(--red,#e63946)}
.pager-btn:disabled{opacity:.35;cursor:default}
.pager-info{font-size:.8rem;color:var(--muted,#8892b0);font-weight:600;white-space:nowrap}

/* ────────────────────────────────────────────────────────────
   HISTORY TABLE
──────────────────────────────────────────────────────────── */
.history-wrap{border-radius:16px;border:1px solid var(--border,#1e2536);overflow:hidden}
.history-table{width:100%;border-collapse:collapse}
.history-table th{font-size:.8rem!important;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--muted,#8892b0);padding:.55rem .9rem;border-bottom:1px solid var(--border,#1e2536);background:rgba(0,0,0,.2);text-align:left;white-space:nowrap}
.history-table td{font-size:.93rem!important;padding:.6rem .9rem;vertical-align:middle;border-bottom:1px solid rgba(255,255,255,.04)}
.history-table tbody tr:last-child td{border-bottom:none}
.history-table tbody tr:hover td{background:rgba(255,255,255,.02)}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:.3rem}
.dot-sent{background:#2dc653}
.dot-failed{background:#e63946}
.empty-box{text-align:center;padding:3rem 1.5rem;border-radius:18px;border:2px dashed var(--border,#1e2536)}
.empty-box .ei{font-size:2.75rem;opacity:.35;margin-bottom:.75rem}
.empty-box p{font-size:1rem!important;color:var(--muted,#8892b0);margin:0}

/* ────────────────────────────────────────────────────────────
   BUTTONS
──────────────────────────────────────────────────────────── */
.btn{min-height:42px;font-size:.98rem!important;font-weight:700!important;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;transition:all .18s;text-decoration:none;padding:.45rem .95rem;cursor:pointer;border:none;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-sm{min-height:36px;padding:.3rem .75rem;font-size:.88rem!important}

/* ────────────────────────────────────────────────────────────
   MODAL — base
──────────────────────────────────────────────────────────── */
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);z-index:10000;align-items:center;justify-content:center;padding:1rem}
.modal-backdrop.open{display:flex}
.modal-box{background:var(--card-bg,#131929);border:1px solid var(--border,#1e2536);border-radius:22px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;-webkit-overflow-scrolling:touch;animation:modalIn .28s cubic-bezier(.2,.8,.2,1) both;box-shadow:0 28px 80px rgba(0,0,0,.65)}
.modal-hd{display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border,#1e2536);position:sticky;top:0;background:var(--card-bg,#131929);z-index:1;border-radius:22px 22px 0 0}
.modal-title{font-size:1.1rem!important;font-weight:800;display:flex;align-items:center;gap:.5rem}
.modal-close{width:36px;height:36px;border-radius:9px;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:all .18s;flex-shrink:0}
.modal-close:hover{background:rgba(230,57,70,.12);border-color:var(--red,#e63946);color:var(--red,#e63946)}
.modal-body{padding:1.25rem}
.modal-ft{padding:.9rem 1.25rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.6rem;justify-content:flex-end;flex-wrap:wrap}
.m-sec{margin-bottom:1.1rem}
.m-sec:last-child{margin-bottom:0}
.m-lbl{font-size:1rem!important;font-weight:800;display:block;margin-bottom:.45rem}
.m-divider{border:none;border-top:1px solid var(--border,#1e2536);margin:1rem 0}
.form-control{font-size:1rem!important;min-height:46px;padding:.65rem 1rem;border-radius:11px!important;transition:border-color .2s,box-shadow .2s;width:100%;background:var(--input-bg,#0f1219);border:1.5px solid var(--border,#1e2536);color:var(--text,#e2e8f0)}
.form-control:focus{outline:none;border-color:var(--red,#e63946)!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
textarea.form-control{min-height:120px;resize:vertical;line-height:1.6}

/* ────────────────────────────────────────────────────────────
   ★★★  MODAL RED SCROLLBAR  ★★★
──────────────────────────────────────────────────────────── */
@keyframes scrollbarPulse{
    0%,100%{background:#e63946}
    50%{background:#ff2233}
}
@keyframes scrollHintBounce{
    0%,100%{transform:translateY(0)}
    50%{transform:translateY(5px)}
}
.modal-box{
    scrollbar-width:thick;
    scrollbar-color:#e63946 rgba(230,57,70,.15);
}
.modal-box::-webkit-scrollbar{width:11px}
.modal-box::-webkit-scrollbar-track{background:rgba(230,57,70,.10);border-radius:0 22px 22px 0}
.modal-box::-webkit-scrollbar-thumb{background:#e63946;border-radius:10px;border:2px solid transparent;background-clip:padding-box;min-height:52px;animation:scrollbarPulse 1.8s ease-in-out 3}
.modal-box::-webkit-scrollbar-thumb:hover{background:#ff4455;background-clip:padding-box}
.modal-box::-webkit-scrollbar-corner{background:transparent}

.modal-scroll-hint{position:sticky;bottom:0;left:0;right:0;pointer-events:none;display:flex;align-items:center;justify-content:center;gap:.45rem;height:52px;background:linear-gradient(to bottom, transparent 0%, rgba(19,25,41,.97) 55%);border-radius:0 0 22px 22px;font-size:.8rem;font-weight:800;color:#e63946;letter-spacing:.04em;text-transform:uppercase;transition:opacity .25s;z-index:2}
.modal-scroll-hint.hidden{opacity:0}
.modal-scroll-hint i{font-size:.85rem;animation:scrollHintBounce 1s ease-in-out infinite}

/* ────────────────────────────────────────────────────────────
   TIMING GRID
──────────────────────────────────────────────────────────── */
.timing-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
@media(max-width:400px){.timing-grid{grid-template-columns:1fr}}
.timing-opt{display:flex;align-items:center;gap:.6rem;cursor:pointer;padding:.7rem .85rem;border-radius:11px;border:2px solid var(--border,#1e2536);transition:border-color .2s,background .2s;min-height:54px}
.timing-opt input[type="radio"]{width:18px;height:18px;accent-color:var(--red,#e63946);flex-shrink:0;cursor:pointer}
.timing-opt:has(input:checked){border-color:var(--red,#e63946);background:rgba(230,57,70,.07)}
.t-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.t-title{font-size:.96rem!important;font-weight:800;display:block}
.t-sub{font-size:.8rem!important;color:var(--muted,#8892b0)}

/* ────────────────────────────────────────────────────────────
   DAYS STEPPER
──────────────────────────────────────────────────────────── */
.days-row{display:flex;align-items:center;gap:.85rem;margin-top:.45rem}
.step-btn{width:44px;height:44px;border-radius:10px;border:1.5px solid var(--border,#1e2536);background:none;color:var(--text,#e2e8f0);font-size:1.4rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;line-height:1}
.step-btn:hover{border-color:var(--red,#e63946);color:var(--red,#e63946)}
.step-btn:active{transform:scale(.9)}
.days-val{font-size:2.2rem!important;font-weight:900;color:var(--red,#e63946);min-width:2.5rem;text-align:center;line-height:1}
.days-lbl{font-size:.95rem!important;color:var(--muted,#8892b0);font-weight:600}

/* ────────────────────────────────────────────────────────────
   CHANNEL CHECKBOXES
──────────────────────────────────────────────────────────── */
.ch-grid{display:flex;gap:.55rem;flex-wrap:wrap}
.ch-opt{display:flex;align-items:center;gap:.5rem;cursor:pointer;padding:.6rem .9rem;border-radius:10px;border:1.5px solid var(--border,#1e2536);transition:border-color .2s,background .2s;font-size:.98rem!important;font-weight:700}
.ch-opt input[type="checkbox"]{width:18px;height:18px;accent-color:var(--red,#e63946);cursor:pointer}
.ch-opt:has(input:checked){border-color:var(--red,#e63946);background:rgba(230,57,70,.07)}

/* ────────────────────────────────────────────────────────────
   SUCCESS OVERLAY
──────────────────────────────────────────────────────────── */
.modal-success{display:none;text-align:center;padding:2.5rem 1rem}
.modal-success.show{display:block}
.modal-success .cc{width:68px;height:68px;background:rgba(45,198,83,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;color:var(--green,#2dc653);margin:0 auto 1rem;animation:checkPop .35s cubic-bezier(.2,.8,.2,1) both}

@media(max-width:520px){
  .modal-box{border-radius:20px 20px 0 0;position:fixed;bottom:0;left:0;right:0;max-width:100%;max-height:94vh}
  .modal-backdrop.open{align-items:flex-end;padding:0}
}

/* ────────────────────────────────────────────────────────────
   BROADCAST MODAL
──────────────────────────────────────────────────────────── */
.bc-modal-box{max-width:640px!important}
@media(max-width:640px){.bc-modal-box{border-radius:20px 20px 0 0!important;position:fixed!important;bottom:0!important;left:0!important;right:0!important;max-width:100%!important;max-height:96vh!important}}

.bc-step-bar{display:flex;align-items:center;gap:0;margin-bottom:1.4rem}
.bc-step-dot{width:32px;height:32px;border-radius:50%;border:2px solid var(--border,#1e2536);background:var(--card-bg,#131929);display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;color:var(--muted,#8892b0);z-index:1;position:relative;flex-shrink:0;transition:all .2s}
.bc-step-dot.active{border-color:#3b82f6;background:rgba(59,130,246,.15);color:#3b82f6}
.bc-step-dot.done{border-color:var(--green,#2dc653);background:rgba(45,198,83,.15);color:var(--green,#2dc653)}
.bc-step-line{flex:1;height:2px;background:var(--border,#1e2536);transition:background .3s}
.bc-step-line.done{background:var(--green,#2dc653)}
.bc-step{display:none}
.bc-step.active{display:block}

.filter-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.8rem}
@media(max-width:420px){.filter-grid{grid-template-columns:1fr}}
.filter-opt{display:flex;align-items:center;gap:.6rem;cursor:pointer;padding:.7rem .85rem;border-radius:12px;border:2px solid var(--border,#1e2536);transition:all .2s;min-height:62px}
.filter-opt input[type="radio"]{width:17px;height:17px;accent-color:#3b82f6;flex-shrink:0;cursor:pointer}
.filter-opt:has(input:checked){border-color:#3b82f6;background:rgba(59,130,246,.07)}
.f-icon{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.f-title{font-size:.93rem!important;font-weight:800;display:block;line-height:1.2}
.f-sub{font-size:.77rem!important;color:var(--muted,#8892b0);line-height:1.3}

.ath-search-wrap{position:relative;margin-bottom:.6rem}
.ath-search-wrap input{padding-left:2.4rem!important}
.ath-search-wrap .si{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--muted,#8892b0);font-size:.85rem;pointer-events:none}

.ath-list{max-height:210px;overflow-y:auto;border:1.5px solid var(--border,#1e2536);border-radius:12px;background:var(--input-bg,#0f1219);scrollbar-width:thin;scrollbar-color:#e63946 rgba(230,57,70,.08)}
.ath-list::-webkit-scrollbar{width:7px}
.ath-list::-webkit-scrollbar-track{background:rgba(230,57,70,.08);border-radius:0 12px 12px 0}
.ath-list::-webkit-scrollbar-thumb{background:#e63946;border-radius:7px;min-height:32px}
.ath-list::-webkit-scrollbar-thumb:hover{background:#ff4455}

.ath-row{display:flex;align-items:center;gap:.65rem;padding:.5rem .85rem;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.03);transition:background .15s}
.ath-row:last-child{border-bottom:none}
.ath-row:hover{background:rgba(59,130,246,.05)}
.ath-row input[type="checkbox"]{width:16px;height:16px;accent-color:#3b82f6;cursor:pointer;flex-shrink:0}
.ath-av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,rgba(59,130,246,.3),rgba(99,179,237,.15));border:1px solid rgba(59,130,246,.2);display:flex;align-items:center;justify-content:center;font-size:.66rem;font-weight:900;color:#3b82f6;flex-shrink:0}
.ath-nm{font-size:.87rem;font-weight:700;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ath-bdg{display:flex;gap:.2rem;margin-left:auto;flex-shrink:0}
.bdg{font-size:.62rem;font-weight:800;padding:.1rem .3rem;border-radius:999px}
.bdg-e{background:rgba(59,130,246,.15);color:#3b82f6}
.bdg-s{background:rgba(45,198,83,.15);color:var(--green,#2dc653)}
.bdg-n{background:rgba(230,57,70,.12);color:var(--red,#e63946)}
.sel-ctr{font-size:.82rem;font-weight:700;color:#3b82f6;padding:.25rem .6rem;background:rgba(59,130,246,.1);border-radius:999px}

.char-ctr{font-size:.75rem;color:var(--muted,#8892b0);text-align:right;margin-top:.2rem}
.char-ctr.warn{color:var(--gold,#f0a500)}
.char-ctr.over{color:var(--red,#e63946)}

.preview-box{background:var(--input-bg,#0f1219);border:1.5px solid var(--border,#1e2536);border-radius:12px;padding:.9rem 1rem;font-size:.87rem;line-height:1.65;color:var(--muted,#8892b0);white-space:pre-wrap;word-break:break-word;max-height:180px;overflow-y:auto;min-height:48px}
.sum-row{display:flex;align-items:center;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.87rem}
.sum-row:last-child{border-bottom:none}
.sum-k{color:var(--muted,#8892b0);font-weight:600}
.sum-v{font-weight:800;color:var(--text,#e2e8f0);text-align:right;max-width:55%;word-break:break-word}

.bc-hist-hd{display:flex;align-items:center;justify-content:space-between;margin:1.6rem 0 .75rem;flex-wrap:wrap;gap:.5rem}
.bc-hist-hd h3{font-size:1.05rem;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0;color:var(--text,#e2e8f0)}

.nav-item{min-height:46px!important;font-size:1rem!important;font-weight:600!important;padding:.65rem .9rem!important;border-radius:10px!important;display:flex!important;align-items:center!important;gap:.7rem!important;transition:background .15s,color .15s!important;text-decoration:none}
.nav-item .icon{width:22px;text-align:center;font-size:1rem;flex-shrink:0}
.sidebar-school{margin:.25rem 1rem!important;padding:0!important;display:flex!important;align-items:center!important;font-weight:700!important;font-size:.92rem!important;color:var(--text,#f0f2ff)!important;background:none!important;border:none!important;word-break:break-word!important}
.sidebar-school:hover,.sidebar-school:focus,.sidebar-school:active{background:none!important;outline:none!important}

/* ────────────────────────────────────────────────────────────
   ★★★  RICH TEMPLATE EDITOR  ★★★
──────────────────────────────────────────────────────────── */
.tpl-editor{outline:none;font-family:inherit;font-size:1rem!important;color:var(--text,#e2e8f0);line-height:1.75;padding:.7rem .9rem;border-radius:11px;border:1.5px solid var(--border,#1e2536);background:var(--input-bg,#0f1219);transition:border-color .2s,box-shadow .2s;white-space:pre-wrap;word-break:break-word;cursor:text;min-height:46px}
.tpl-editor:focus{border-color:var(--red,#e63946)!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
.tpl-editor[data-field="body"],.tpl-editor[data-field="bc_body"]{min-height:130px}
.tpl-editor:empty:before{content:attr(data-placeholder);color:var(--muted,#8892b0);pointer-events:none}
.tpl-token{display:inline-flex;align-items:center;gap:.3em;vertical-align:middle;background:linear-gradient(135deg,rgba(99,179,237,.18),rgba(59,130,246,.12));border:1.5px solid rgba(99,179,237,.5);color:#7ec8e3;border-radius:7px;padding:.08em .5em .1em .35em;font-size:.86em;font-weight:800;letter-spacing:.01em;line-height:1.6;white-space:nowrap;cursor:default;position:relative;-webkit-user-modify:read-only;box-shadow:0 1px 4px rgba(59,130,246,.12)}
.tpl-token::before{content:'🔒';font-size:.72em;opacity:.7;filter:grayscale(.3)}
.tpl-preset-row{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.65rem}
.tpl-preset-btn{display:inline-flex;align-items:center;gap:.3rem;background:rgba(255,255,255,.04);border:1px solid var(--border,#1e2536);color:var(--muted,#8892b0);border-radius:8px;padding:.28rem .65rem;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap}
.tpl-preset-btn:hover{background:rgba(139,92,246,.1);border-color:rgba(139,92,246,.35);color:#a78bfa}
</style>

<body>
<?php renderOveragePopup(); ?>
<div class="app-layout">
<?php renderSidebar('notifications'); ?>
<div id="dm-overlay"></div>

<div class="main-content">
<?php renderTopbar('Αυτόματες Ειδοποιήσεις'); ?>
<div class="page-body">

<?php if ($schoolInSummerPause): ?>
<div style="background:linear-gradient(135deg,rgba(240,165,0,.12),rgba(230,120,0,.08));border:1.5px solid rgba(240,165,0,.35);border-radius:16px;padding:1.1rem 1.4rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
    <div style="width:44px;height:44px;border-radius:12px;background:rgba(240,165,0,.15);border:1px solid rgba(240,165,0,.3);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="fa-solid fa-sun" style="color:#f0a500;font-size:1.3rem"></i>
    </div>
    <div style="flex:1;min-width:0">
        <div style="font-size:1rem;font-weight:800;color:#f0a500">Θερινή Παύση Ενεργή</div>
        <div style="font-size:.88rem;color:#b0bcd4;margin-top:.2rem">
            Οι αυτόματες ειδοποιήσεις SMS &amp; email στους αθλητές σας <strong>είναι ανασταλμένες</strong> κατά τη διάρκεια της θερινής παύσης.
            Η αποστολή ανακοινώσεων είναι επίσης απενεργοποιημένη.
        </div>
    </div>
    <a href="<?= APP_URL ?>/pages/settings.php?tab=school" style="background:rgba(240,165,0,.15);color:#f0a500;border:1.5px solid rgba(240,165,0,.4);padding:.45rem 1rem;border-radius:10px;font-size:.85rem;font-weight:700;text-decoration:none;white-space:nowrap;flex-shrink:0">
        <i class="fa-solid fa-sliders"></i> Ρυθμίσεις Παύσης
    </a>
</div>
<?php endif; ?>

<?php if ($showSystemStatus): ?>
<div class="cron-banner <?= h($systemStatusClass) ?> anim-2">
    <span class="cron-dot <?= h($systemStatusDot) ?>"></span>
    <div style="flex:1;min-width:0">
        <div style="font-weight:800;font-size:1rem"><?= h($systemStatusTitle) ?></div>
        <div style="font-size:.87rem;color:var(--muted,#8892b0);margin-top:.1rem"><?= h($systemStatusSub) ?></div>
    </div>
</div>
<?php endif; ?>

<div class="tabs-bar anim-2">
    <button class="tab-btn active" onclick="switchTab('rules',this)">
        <i class="fa-solid fa-bell"></i> Υπενθυμίσεις
        <span style="background:rgba(230,57,70,.2);color:#e63946;border-radius:999px;padding:.05rem .45rem;font-size:.76rem"><?= count($rules) ?></span>
    </button>
    <button class="tab-btn" onclick="switchTab('history',this)">
        <i class="fa-solid fa-clock-rotate-left"></i> Ιστορικό
        <?php if ($sentCount > 0): ?>
        <span style="background:rgba(59,130,246,.2);color:#3b82f6;border-radius:999px;padding:.05rem .45rem;font-size:.76rem"><?= $sentCount ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- ── TAB: RULES ──────────────────────────────────────────── -->
<div id="tab-rules" class="tab-panel active">
    <div class="page-hd anim-2">
        <h2><i class="fa-solid fa-bell" style="color:var(--red,#e63946)"></i> Αυτόματες Υπενθυμίσεις</h2>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
<button type="button" id="openBroadcastBtn" class="btn"
    style="background:linear-gradient(135deg,rgba(139,92,246,.15),rgba(99,102,241,.08));
           border:1.5px solid rgba(139,92,246,.35);
           color:#8b5cf6">
    <i class="fa-solid fa-bullhorn"></i> Μαζική ενημέρωση μελλών
</button>

<button type="button" id="openAddModal" class="btn btn-primary">
    <i class="fa-solid fa-plus"></i> Αυτόματες Υπενθυμίσεις
</button>

<form method="POST" action="<?= APP_URL ?>/pages/run_reminders_now.php" style="display:inline"
      onsubmit="return confirm('Θα εκτελεστεί τώρα το nightly cron των υπενθυμίσεων για τη σχολή σας. Συνέχεια;')">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  <button type="submit" class="btn"
          style="background:linear-gradient(135deg,rgba(45,198,83,.15),rgba(34,197,94,.06));
                 border:1.5px solid rgba(45,198,83,.4);color:#2dc653"
          title="Εκτελεί άμεσα το cron για να δεις τα αποτελέσματα χωρίς να περιμένεις τη νύχτα">
    <i class="fa-solid fa-play"></i> Δοκιμαστική Εκτέλεση
  </button>
</form>
        </div>
    </div>

    <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:.85rem 1.1rem;margin-bottom:1rem;color:#c8dbff;font-size:.9rem;line-height:1.6">
      <strong style="color:#ffffff"><i class="fa-solid fa-clock" style="color:#3b82f6;margin-right:.3rem"></i>Πότε τρέχει το cron;</strong>
      Οι αυτόματες υπενθυμίσεις πρέπει να καλούνται από εξωτερικό scheduler (Coolify scheduled task ή external cron). Ο ενδεικτικός χρόνος είναι <strong style="color:#ffffff">κάθε πρωί στις 09:00</strong>. Για άμεση δοκιμή, πάτησε <em>Δοκιμαστική Εκτέλεση</em>. Οι αποστολές θα εμφανιστούν στο tab <em>Ιστορικό</em>.
    </div>

    <div class="rules-list anim-3">
        <?php if (!$rules): ?>
        <div class="empty-box" style="padding:2rem 1rem">
            <div class="ei"><i class="fa-solid fa-bell-slash"></i></div>
            <p>Δεν υπάρχουν υπενθυμίσεις ακόμα.<br>Πάτα «Αυτόματες Υπενθυμίσεις» για να ξεκινήσεις.</p>
        </div>
        <?php else: ?>
        <div class="rules-scroll">
          <table class="rules-table">
            <thead>
              <tr>
                <th style="width:52px">Ενεργή</th>
                <th>Όνομα</th>
                <th class="col-hide-md">Trigger</th>
                <th class="col-hide-md">Κανάλια</th>
                <th class="col-hide-md">Προεπισκόπηση</th>
                <th style="text-align:right;width:220px">Ενέργειες</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rules as $r):
                  $isOn      = (bool)($r['active'] ?? 0);
                  $ttype     = $r['trigger_type'] ?? 'days_before';
                  $tdays     = (int)($r['trigger_days'] ?? 0);
                  $chs       = array_filter(array_map('trim', explode(',', $r['channels'] ?? 'sms')));
                  $isDefault = ((int)$r['id'] === $defaultRuleId);
                  $bodyPreview = strtr($r['body_tpl'] ?? '', [
                      '{{athlete_name}}' => 'Όνομα αθλητή',
                      '{{valid_until}}'  => 'Ημ. λήξης',
                      '{{amount}}'       => 'Ποσό',
                      '{{school_name}}'  => $schoolName,
                  ]);
                  $rowClass = ($isOn || $isDefault) ? 'rule-row' : 'rule-row is-off';
              ?>
              <tr class="<?= $rowClass ?>" onclick="openRuleDetail(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
                <td onclick="event.stopPropagation()">
                  <?php if ($isDefault): ?>
                    <span title="Προεπιλεγμένη — πάντα ενεργή" style="color:#f0a500;font-size:1.4rem">
                      <i class="fa-solid fa-star"></i>
                    </span>
                  <?php else: ?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="_action" value="toggle_rule">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="rule-toggle-btn" title="<?= $isOn ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?>">
                        <i class="fa-solid fa-toggle-<?= $isOn ? 'on' : 'off' ?>"
                           style="color:<?= $isOn ? '#2dc653' : '#6b7494' ?>"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="rule-name-cell">
                    <span><?= h($r['rule_name'] ?? '') ?></span>
                    <?php if ($isDefault): ?>
                      <span class="default-tag"><i class="fa-solid fa-shield"></i> Προεπιλογή</span>
                    <?php elseif (!$isOn): ?>
                      <span style="font-size:.72rem;color:var(--muted,#8892b0);font-style:italic;font-weight:600">Ανενεργή</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="col-hide-md">
                  <span class="pill <?= triggerPillClass($ttype) ?>">
                    <i class="fa-solid <?= triggerIcon($ttype) ?>"></i>
                    <?= triggerLabel($ttype, $tdays) ?>
                  </span>
                </td>
                <td class="col-hide-md">
                  <div class="rule-meta-inline">
                    <?php if (in_array('email', $chs)): ?><span class="pill pill-ch"><i class="fa-solid fa-envelope"></i> Email</span><?php endif; ?>
                    <?php if (in_array('sms', $chs) && $hasSms): ?><span class="pill pill-ch"><i class="fa-solid fa-mobile-screen"></i> SMS</span><?php endif; ?>
                  </div>
                </td>
                <td class="col-hide-md">
                  <?php if (!empty($r['body_tpl'])): ?>
                    <div class="rule-body-preview" title="<?= h($bodyPreview) ?>"><?= h($bodyPreview) ?></div>
                  <?php else: ?>
                    <span style="color:var(--muted,#8892b0);font-size:.85rem">—</span>
                  <?php endif; ?>
                </td>
                <td onclick="event.stopPropagation()" style="text-align:right">
                  <div class="rule-actions">
                    <button type="button" class="btn btn-ghost btn-sm" title="Επεξεργασία"
                            onclick="openEditModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
                      <i class="fa-solid fa-pen-to-square"></i> Επεξεργασία
                    </button>
                    <?php if (!$isDefault): ?>
                    <form method="POST" onsubmit="return confirmDel(event,this)" style="display:inline">
                      <input type="hidden" name="_action" value="delete_rule">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red,#e63946)" title="Διαγραφή">
                        <i class="fa-solid fa-trash"></i>
                      </button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── TAB: HISTORY ────────────────────────────────────────── -->
<div id="tab-history" class="tab-panel">

    <!-- Reminder logs section -->
    <div style="margin-bottom:.6rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap">
        <h2 style="font-size:1.2rem;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0">
            <i class="fa-solid fa-clock-rotate-left" style="color:#3b82f6"></i> Ιστορικό Αποστολών
        </h2>
    </div>

    <!-- Toolbar: search + pagination info -->
    <div class="hist-toolbar">
        <div class="hist-search-wrap">
            <i class="fa-solid fa-magnifying-glass si"></i>
            <input type="text" class="form-control" id="histSearch"
                   placeholder="Αναζήτηση αθλητή…"
                   oninput="histFilter(this.value)"
                   onkeydown="if(event.key==='Enter'){this.blur();}"
                   inputmode="search"
                   enterkeyhint="search">
        </div>
        <div class="pager" id="histPager"></div>
        <span class="pager-info" id="histInfo"></span>
    </div>

    <?php if ($history): ?>
    <div class="history-wrap">
        <table class="history-table">
            <thead>
                <tr>
                    <th>Κατάσταση</th><th>Αθλητής</th><th>Κανάλι</th><th>Παραλήπτης</th><th>Ημερομηνία</th>
                </tr>
            </thead>
            <tbody id="histTbody">
            <?php foreach ($history as $hi):
                $isSent = ($hi['status'] === 'sent');
            ?>
             <tr class="hist-row"
                 data-name="<?= h(mb_strtolower($hi['full_name'] ?? '')) ?>"
                 data-recipient="<?= h(mb_strtolower($hi['recipient'] ?? '')) ?>">
                 <td>
                    <span class="status-dot <?= $isSent ? 'dot-sent' : 'dot-failed' ?>"></span>
                    <span style="font-size:.85rem;font-weight:700;color:<?= $isSent ? '#2dc653' : '#e63946' ?>"><?= $isSent ? 'Εστάλη' : 'Απέτυχε' ?></span>
                 </td>
                <td style="font-weight:700;font-size:.88rem"><?= h($hi['full_name'] ?? '—') ?></td>
                <td><span style="font-size:.8rem;font-weight:700;color:<?= ($hi['type']==='sms') ? '#2dc653' : '#3b82f6' ?>"><i class="fa-solid <?= ($hi['type']==='sms') ? 'fa-mobile-screen' : 'fa-envelope' ?>" style="font-size:.7rem"></i> <?= strtoupper($hi['type'] ?? '') ?></span></td>
                <td style="font-size:.82rem;color:var(--muted,#8892b0);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($hi['recipient'] ?? '—') ?></td>
                <td style="font-size:.82rem;color:var(--muted,#8892b0);white-space:nowrap"><?= $hi['sent_at'] ? date('d/m/Y H:i', strtotime($hi['sent_at'])) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
         </table>
    </div>
    <div id="histEmpty" style="display:none" class="empty-box" style="margin-top:.5rem">
        <div class="ei"><i class="fa-solid fa-magnifying-glass"></i></div>
        <p>Δεν βρέθηκαν αποστολές με αυτά τα κριτήρια.</p>
    </div>
    <?php else: ?>
    <div class="empty-box">
        <div class="ei"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <p>Δεν υπάρχουν αποστολές ακόμα.<br>Μόλις γίνει η αυτόματη ενημέρωση, θα εμφανίζεται εδώ το ιστορικό.</p>
    </div>
    <?php endif; ?>

    <?php if ($broadcasts): ?>
    <div class="bc-hist-hd" style="margin-top:1.8rem">
        <h3><i class="fa-solid fa-bullhorn" style="color:#8b5cf6"></i> Μαζικές Ανακοινώσεις</h3>
    </div>

    <!-- Broadcast toolbar -->
    <div class="hist-toolbar">
        <div class="hist-search-wrap">
            <i class="fa-solid fa-magnifying-glass si"></i>
            <input type="text" class="form-control" id="bcHistSearch"
                   placeholder="Αναζήτηση θέματος…"
                   oninput="bcHistFilter(this.value)"
                   onkeydown="if(event.key==='Enter'){this.blur();}"
                   inputmode="search"
                   enterkeyhint="search">
        </div>
        <div class="pager" id="bcHistPager"></div>
        <span class="pager-info" id="bcHistInfo"></span>
    </div>

    <div class="history-wrap">
        <table class="history-table">
            <thead><tr><th>Κατάσταση</th><th>Θέμα</th><th>Φίλτρο</th><th>Εστάλη/Απέτυχε</th><th>Ημερομηνία</th></tr></thead>
            <tbody id="bcHistTbody">
            <?php
            $filterLbls = ['all'=>'Όλοι','active_sub'=>'Ενεργές','expired_sub'=>'Ληξιπρόθ.','custom'=>'Επιλογή','department'=>'Ανά Τμήμα'];
            foreach ($broadcasts as $bc):
                $bcDone = ($bc['status'] === 'done');
                $subjectSlug = mb_strtolower($bc['subject'] ?? '');
            ?>
             <tr class="bc-hist-row"
                 data-subject="<?= h($subjectSlug) ?>">
                 <td>
                    <span class="status-dot <?= $bcDone ? 'dot-sent' : 'dot-failed' ?>"></span>
                    <span style="font-size:.85rem;font-weight:700;color:<?= $bcDone ? '#2dc653' : '#e63946' ?>"><?= $bcDone ? 'Εστάλη' : ucfirst(h($bc['status'])) ?></span>
                 </td>
                <td style="font-weight:700;font-size:.87rem;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($bc['subject'] ?? 'Χωρίς θέμα') ?></td>
                <td><span class="pill pill-ch" style="font-size:.72rem"><?= h($filterLbls[$bc['recipient_filter']] ?? $bc['recipient_filter']) ?></span></td>
                <td style="font-size:.83rem;white-space:nowrap">
                    <span style="color:#2dc653;font-weight:800"><?= (int)$bc['total_sent'] ?></span>
                    <?php if ((int)$bc['total_failed'] > 0): ?><span style="color:var(--muted,#8892b0)"> / </span><span style="color:var(--red,#e63946);font-weight:700"><?= (int)$bc['total_failed'] ?> ✗</span><?php endif; ?>
                 </td>
                <td style="font-size:.82rem;color:var(--muted,#8892b0);white-space:nowrap"><?= $bc['created_at'] ? date('d/m/Y H:i', strtotime($bc['created_at'])) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
         </table>
    </div>
    <div id="bcHistEmpty" style="display:none" class="empty-box" style="margin-top:.5rem">
        <div class="ei"><i class="fa-solid fa-magnifying-glass"></i></div>
        <p>Δεν βρέθηκαν ανακοινώσεις με αυτά τα κριτήρια.</p>
    </div>
    <?php endif; ?>
</div><!-- /tab-history -->

</div><!-- /page-body -->
</div><!-- /main-content -->
</div><!-- /app-layout -->

<!-- ══════════════════════════════════════════════════════════
     RULE MODAL
══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="ruleModal" role="dialog" aria-modal="true">
    <div class="modal-box">
        <div class="modal-hd">
            <div class="modal-title">
                <i class="fa-solid fa-bell" id="mTitleIcon" style="color:var(--green,#2dc653)"></i>
                <span id="mTitleText">Νέα Υπενθύμιση</span>
            </div>
            <button type="button" class="modal-close" id="closeRuleModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="ruleFormWrap">
        <form method="POST" id="ruleForm">
            <input type="hidden" name="_action" value="save_rule">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="id" id="m_id" value="">
            <input type="hidden" name="active" value="1">
            <input type="hidden" name="subject_tpl" id="m_subject_tpl">
            <input type="hidden" name="body_tpl" id="m_body_tpl">

            <div class="modal-body">
                <!-- Title -->
                <div class="m-sec">
                    <label class="m-lbl" for="m_rule_name">Τίτλος υπενθύμισης</label>
                    <input id="m_rule_name" name="rule_name" class="form-control" placeholder="π.χ. Υπενθύμιση πληρωμής 5 μέρες μετά" required>
                </div>

                <hr class="m-divider">

                <!-- Timing — only "days_before" is available for custom rules -->
                <input type="hidden" name="trigger_type" value="days_before">
                <div class="m-sec">
                    <label class="m-lbl">Τύπος υπενθύμισης</label>
                    <div style="display:flex;align-items:center;gap:.75rem;padding:.7rem .9rem;border-radius:11px;border:1.5px solid rgba(59,130,246,.35);background:rgba(59,130,246,.07)">
                        <div style="width:34px;height:34px;border-radius:9px;background:rgba(59,130,246,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <i class="fa-solid fa-clock" style="color:#3b82f6"></i>
                        </div>
                        <div>
                            <span style="display:block;font-size:.96rem;font-weight:800;color:var(--text,#e2e8f0)">Πριν λήξει</span>
                            <span style="display:block;font-size:.8rem;color:var(--muted,#8892b0)">Αποστέλλεται X μέρες πριν τη λήξη της συνδρομής</span>
                        </div>
                    </div>
                </div>

                <div class="m-sec" id="daysGroup">
                    <label class="m-lbl">Πόσες μέρες πριν τη λήξη;</label>
                    <div class="days-row">
                        <button type="button" class="step-btn" id="daysMinus">−</button>
                        <span class="days-val" id="daysDisplay">5</span>
                        <span class="days-lbl">μέρες</span>
                        <button type="button" class="step-btn" id="daysPlus">+</button>
                    </div>
                    <input type="hidden" name="trigger_days" id="m_trigger_days" value="5">
                </div>

                <hr class="m-divider">

                <!-- Subject -->
                <div class="m-sec">
                    <label class="m-lbl">
                        <i class="fa-solid fa-tag" style="color:#3b82f6;margin-right:.35rem;font-size:.85em"></i>
                        Θέμα ειδοποίησης
                    </label>
                    <div id="m_subject_vis"
                         contenteditable="true"
                         spellcheck="true"
                         class="tpl-editor"
                         data-field="subject"
                         data-placeholder="π.χ. Υπενθύμιση πληρωμής συνδρομής"></div>
                </div>

                <!-- Body -->
                <div class="m-sec">
                    <label class="m-lbl">
                        <i class="fa-solid fa-pen-nib" style="color:var(--gold,#f0a500);margin-right:.35rem;font-size:.85em"></i>
                        Κείμενο μηνύματος
                    </label>
                    <div id="m_body_vis"
                         contenteditable="true"
                         spellcheck="true"
                         class="tpl-editor"
                         data-field="body"
                         data-placeholder="Γράψε το μήνυμα εδώ…"></div>
                    <div id="ruleCharBar" style="display:none;margin-top:.5rem">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.4rem">
                            <div id="ruleCharInfo" style="font-size:.78rem;font-weight:700;color:var(--muted,#8892b0)">0 χαρακτήρες</div>
                            <div id="ruleSmsBadge" style="font-size:.72rem;font-weight:800;padding:.2rem .55rem;border-radius:6px;background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.25)">1 SMS</div>
                        </div>
                        <div style="margin-top:.35rem;background:rgba(255,255,255,.06);border-radius:99px;height:5px;overflow:hidden">
                            <div id="ruleCharBar_fill" style="height:100%;border-radius:99px;transition:width .2s,background .2s;width:0%"></div>
                        </div>
                    </div>
                </div>

                <hr class="m-divider">

                <!-- Channel (SMS-first) -->
                <div class="m-sec" style="margin-bottom:0">
                    <label class="m-lbl">Τρόπος αποστολής</label>
                    <div class="ch-grid">
                        <?php if ($hasSms): ?>
                        <label class="ch-opt">
                            <input type="checkbox" name="ch_sms" id="m_ch_sms" checked>
                            <i class="fa-solid fa-mobile-screen" style="color:var(--green,#2dc653)"></i> SMS
                        </label>
                        <label class="ch-opt">
                            <input type="checkbox" name="ch_email" id="m_ch_email">
                            <i class="fa-solid fa-envelope" style="color:#3b82f6"></i> Email
                        </label>
                        <?php else: ?>
                        <label class="ch-opt">
                            <input type="checkbox" name="ch_email" id="m_ch_email" checked>
                            <i class="fa-solid fa-envelope" style="color:#3b82f6"></i> Email
                        </label>
                        <div class="ch-opt" onclick="openProModal()" style="cursor:pointer;opacity:.75">
                            <i class="fa-solid fa-mobile-screen" style="color:var(--green,#2dc653)"></i> SMS
                            <span style="font-size:.7rem;color:var(--gold,#f0a500);background:rgba(240,165,0,.14);border:1px solid rgba(240,165,0,.3);padding:.1rem .45rem;border-radius:999px;font-weight:800;margin-left:.25rem">⭐ Pro</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /modal-body -->

            <div class="modal-ft">
                <button type="button" id="cancelRuleModal" class="btn btn-ghost"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
                <button type="submit" class="btn btn-primary" style="min-height:46px">
                    <i class="fa-solid fa-floppy-disk"></i> <span id="mSubmitLabel">Αποθήκευση Υπενθύμισης</span>
                </button>
            </div>
        </form>
        </div>

        <div class="modal-success" id="ruleSuccess">
            <div class="cc"><i class="fa-solid fa-check"></i></div>
            <p style="font-weight:800;font-size:1.1rem!important;color:var(--text,#e2e8f0)" id="mSuccessMsg">Η υπενθύμιση αποθηκεύτηκε!</p>
            <p style="color:var(--muted,#8892b0)">Γίνεται ανακατεύθυνση...</p>
        </div>
    </div>
</div>

<!-- Delete confirm modal -->
<div id="delModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10500;align-items:center;justify-content:center;padding:1rem"
     onclick="if(event.target===this)closeDelModal()">
    <div style="background:var(--card-bg,#131929);border:1px solid var(--border,#1e2536);border-radius:18px;width:100%;max-width:360px;box-shadow:0 24px 80px rgba(0,0,0,.6)">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.2rem;border-bottom:1px solid var(--border,#1e2536)">
            <div style="font-weight:800;color:var(--red,#e63946);display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-trash"></i> Διαγραφή Υπενθύμισης</div>
            <button onclick="closeDelModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:32px;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="padding:1.1rem 1.2rem">
            <p style="margin:0 0 .4rem;font-size:.95rem;color:var(--text,#e2e8f0)">Να διαγραφεί αυτή η υπενθύμιση;</p>
            <p style="margin:0;font-size:.84rem;color:var(--muted,#8892b0)">Η ενέργεια δεν αναιρείται.</p>
        </div>
        <div style="padding:.85rem 1.2rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.5rem;justify-content:flex-end">
            <button onclick="closeDelModal()" style="min-height:36px;font-size:.9rem;font-weight:700;padding:.4rem .85rem;border-radius:9px;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)">Ακύρωση</button>
            <button id="delConfirmBtn" style="min-height:36px;font-size:.9rem;font-weight:700;padding:.4rem .85rem;border-radius:9px;cursor:pointer;border:none;background:var(--red,#e63946);color:#fff"><i class="fa-solid fa-trash"></i> Διαγραφή</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     BROADCAST MODAL
══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="broadcastModal" role="dialog" aria-modal="true">
<div class="modal-box bc-modal-box">
    <div class="modal-hd">
        <div class="modal-title">
            <i class="fa-solid fa-bullhorn" style="color:#8b5cf6"></i>
            Ενημέρωση Μελών
        </div>
        <button type="button" class="modal-close" id="closeBroadcastModal"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div id="bcSending" style="display:none;text-align:center;padding:3rem 1.5rem">
        <div style="width:56px;height:56px;border:4px solid rgba(139,92,246,.15);border-top-color:#8b5cf6;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 1rem"></div>
        <div style="font-size:1rem;font-weight:800;color:var(--text,#e2e8f0)">Αποστολή σε εξέλιξη...</div>
        <div style="font-size:.86rem;color:var(--muted,#8892b0);margin-top:.3rem">Παρακαλώ περίμενε.</div>
    </div>

    <form method="POST" id="broadcastForm">
        <input type="hidden" name="_action" value="send_broadcast">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="bc_body" id="bc_body">

        <div class="modal-body" id="bcBody">
            <!-- Step bar -->
            <div class="bc-step-bar" id="bcStepBar">
                <div class="bc-step-dot active" id="bsd1">1</div>
                <div class="bc-step-line" id="bsl1"></div>
                <div class="bc-step-dot" id="bsd2">2</div>
                <div class="bc-step-line" id="bsl2"></div>
                <div class="bc-step-dot" id="bsd3">3</div>
                <div class="bc-step-line" id="bsl3"></div>
                <div class="bc-step-dot" id="bsd4"><i class="fa-solid fa-paper-plane" style="font-size:.68rem"></i></div>
            </div>

            <!-- STEP 1: Recipients -->
            <div class="bc-step active" id="bcStep1">
                <div class="m-sec">
                    <label class="m-lbl"><i class="fa-solid fa-users" style="color:#8b5cf6;margin-right:.35rem"></i>Σε ποιους να σταλεί;</label>
                    <div class="filter-grid">
                        <label class="filter-opt">
                            <input type="radio" name="bc_filter" value="all" checked>
                            <div class="f-icon" style="background:rgba(139,92,246,.12)"><i class="fa-solid fa-users" style="color:#8b5cf6"></i></div>
                            <div><span class="f-title">Όλοι</span><span class="f-sub">Όλοι οι ενεργοί αθλητές</span></div>
                        </label>
                        <label class="filter-opt">
                            <input type="radio" name="bc_filter" value="department">
                            <div class="f-icon" style="background:rgba(59,130,246,.12)"><i class="fa-solid fa-folder-open" style="color:#3b82f6"></i></div>
                            <div><span class="f-title">Ανά Τμήμα</span><span class="f-sub">Συγκεκριμένο τμήμα</span></div>
                        </label>
                        <label class="filter-opt" style="grid-column:span 2">
                            <input type="radio" name="bc_filter" value="custom">
                            <div class="f-icon" style="background:rgba(240,165,0,.12)"><i class="fa-solid fa-sliders" style="color:var(--gold,#f0a500)"></i></div>
                            <div><span class="f-title">Χειροκίνητη Επιλογή</span><span class="f-sub">Επιλογή συγκεκριμένων αθλητών</span></div>
                        </label>
                    </div>

                    <div id="bcDeptPickerWrap" style="display:none;margin-top:.6rem">
                        <?php if ($broadcastDepts): ?>
                        <select name="bc_dept_id" id="bcDeptId" class="form-control">
                            <option value="">— Επιλέξτε τμήμα —</option>
                            <?php foreach ($broadcastDepts as $dept): ?>
                            <option value="<?= (int)$dept['id'] ?>"><?= h($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <div style="font-size:.85rem;color:var(--muted,#8892b0);padding:.5rem .75rem;background:rgba(255,255,255,.04);border-radius:10px;border:1px solid var(--border,#1e2536)">
                            Δεν υπάρχουν ενεργά τμήματα.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="bcAthPicker" style="display:none" class="m-sec">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;gap:.35rem;flex-wrap:wrap">
                        <label class="m-lbl" style="margin:0">Επιλογή αθλητών</label>
                        <div style="display:flex;gap:.3rem;align-items:center">
                            <span class="sel-ctr" id="bcSelCtr">0 επιλεγμένοι</span>
                            <button type="button" onclick="bcSelAll()" style="font-size:.75rem;font-weight:700;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.3);color:#3b82f6;border-radius:7px;padding:.2rem .5rem;cursor:pointer">Όλοι</button>
                            <button type="button" onclick="bcSelNone()" style="font-size:.75rem;font-weight:700;background:rgba(255,255,255,.05);border:1px solid var(--border,#1e2536);color:var(--muted,#8892b0);border-radius:7px;padding:.2rem .5rem;cursor:pointer">Καθαρισμός</button>
                        </div>
                    </div>
                    <div class="ath-search-wrap">
                        <i class="fa-solid fa-magnifying-glass si"></i>
                        <input type="text" class="form-control" id="bcAthSearch" placeholder="Αναζήτηση..." oninput="bcFilterAth(this.value)">
                    </div>
                    <div class="ath-list" id="bcAthList">
                        <?php
                        $deptNameMap = [];
                        foreach ($broadcastDepts as $d) $deptNameMap[$d['id']] = $d['name'];
                        foreach ($allAthletes as $ath):
                            $ini = '';
                            foreach (explode(' ', trim($ath['full_name'] ?? 'X')) as $w) {
                                if ($w) $ini .= mb_strtoupper(mb_substr($w,0,1));
                                if (mb_strlen($ini)>=2) break;
                            }
                            $hasE = !empty($ath['parent_email']) || !empty($ath['email']);
                            $hasP = !empty($ath['parent_phone']) || !empty($ath['phone']);
                            $expired = $ath['valid_until'] && $ath['valid_until'] < date('Y-m-d');
                            $deptName = $ath['department_id'] ? ($deptNameMap[$ath['department_id']] ?? '') : '';
                        ?>
                        <label class="ath-row" data-name="<?= h(mb_strtolower($ath['full_name'] ?? '')) ?>" data-dept="<?= (int)($ath['department_id'] ?? 0) ?>">
                            <input type="checkbox" name="bc_athletes[]" value="<?= (int)$ath['id'] ?>" onchange="bcUpdSel()">
                            <div class="ath-av"><?= h($ini) ?></div>
                            <div class="ath-nm">
                                <?= h($ath['full_name'] ?? '') ?>
                                <?php if ($deptName): ?><span style="font-size:.68rem;color:var(--muted,#8892b0);font-weight:500;margin-left:.2rem"><?= h($deptName) ?></span><?php endif; ?>
                            </div>
                            <div class="ath-bdg">
                                <?php if ($hasE): ?><span class="bdg bdg-e" title="Email"><i class="fa-solid fa-envelope" style="font-size:.55rem"></i></span><?php endif; ?>
                                <?php if ($hasP): ?><span class="bdg bdg-s" title="Τηλέφωνο"><i class="fa-solid fa-mobile-screen" style="font-size:.55rem"></i></span><?php endif; ?>
                                <?php if (!$hasE && !$hasP): ?><span class="bdg bdg-n"><i class="fa-solid fa-xmark" style="font-size:.55rem"></i></span><?php endif; ?>
                                <?php if ($expired): ?><span class="bdg" style="background:rgba(230,57,70,.12);color:var(--red,#e63946)">Ληξ.</span><?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php if (!$allAthletes): ?>
                        <div style="padding:1.5rem;text-align:center;color:var(--muted,#8892b0);font-size:.86rem">Δεν υπάρχουν ενεργοί αθλητές.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Broadcast channel section (SMS-first) -->
                <div class="m-sec" style="margin-bottom:0">
                    <label class="m-lbl"><i class="fa-solid fa-paper-plane" style="color:var(--gold,#f0a500);margin-right:.35rem"></i>Μέσο αποστολής</label>
                    <div class="ch-grid">
                        <?php if ($hasSms): ?>
                        <label class="ch-opt">
                            <input type="checkbox" name="bc_ch_sms" id="bc_ch_sms" checked>
                            <i class="fa-solid fa-mobile-screen" style="color:var(--green,#2dc653)"></i> SMS
                        </label>
                        <label class="ch-opt">
                            <input type="checkbox" name="bc_ch_email" id="bc_ch_email">
                            <i class="fa-solid fa-envelope" style="color:#3b82f6"></i> Email
                        </label>
                        <?php else: ?>
                        <label class="ch-opt">
                            <input type="checkbox" name="bc_ch_email" id="bc_ch_email" checked>
                            <i class="fa-solid fa-envelope" style="color:#3b82f6"></i> Email
                        </label>
                        <div class="ch-opt" onclick="openProModal()" style="cursor:pointer;opacity:.75">
                            <i class="fa-solid fa-mobile-screen" style="color:var(--green,#2dc653)"></i> SMS
                            <span style="font-size:.7rem;color:var(--gold,#f0a500);background:rgba(240,165,0,.14);border:1px solid rgba(240,165,0,.3);padding:.1rem .45rem;border-radius:999px;font-weight:800;margin-left:.25rem">⭐ Pro</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /bcStep1 -->

            <!-- STEP 2: Message -->
            <div class="bc-step" id="bcStep2">
                <div class="m-sec">
                    <label class="m-lbl" for="bc_subject">
                        <i class="fa-solid fa-envelope-open-text" style="color:#3b82f6;margin-right:.35rem"></i>
                        Θέμα <span style="font-size:.77rem;color:var(--muted,#8892b0);font-weight:500">(προαιρετικό για SMS)</span>
                    </label>
                    <input id="bc_subject" name="bc_subject" class="form-control" placeholder="π.χ. Σημαντική ανακοίνωση — <?= h($schoolName) ?>">
                </div>
                <div class="m-sec" style="margin-bottom:0">
                    <label class="m-lbl">
                        <i class="fa-solid fa-pen-nib" style="color:var(--gold,#f0a500);margin-right:.35rem"></i>
                        Μήνυμα <span style="color:var(--red,#e63946)">*</span>
                    </label>
                    <div class="tpl-preset-row">
                        <span style="font-size:.76rem;color:var(--muted,#8892b0);font-weight:700;align-self:center;flex-shrink:0">Έτοιμα κείμενα:</span>
                        <?php
$tpls = [
                            ['🏛️ Κλειστό', "Αγαπητέ/ή,\n\nΣας ενημερώνουμε ότι η σχολή {{school_name}} θα παραμείνει κλειστή την περίοδο __/__/__ έως __/__/__.\n\nΘα επαναλειτουργήσουμε στις __/__/__.\n\nΜε εκτίμηση,\n{{school_name}}"],
                            ['📅 Εκδήλωση', "Αγαπητέ/ή,\n\nΘα θέλαμε να σας ενημερώσουμε για εκδήλωση που διοργανώνει η {{school_name}}.\n\nΗμερομηνία: __/__/__\nΏρα & τόπος: ___\n\nΣας αναμένουμε!\n\nΜε εκτίμηση,\n{{school_name}}"],
                            ['⚠️ Αλλαγή', "Αγαπητέ/ή,\n\nΘέλουμε να σας ενημερώσουμε για σημαντική αλλαγή στο πρόγραμμα.\n\n[Περιγραφή αλλαγής]\n\nΓια απορίες επικοινωνήστε μαζί μας.\n\nΜε εκτίμηση,\n{{school_name}}"],
                            ['💳 Πληρωμές', "Αγαπητέ/ή,\n\nΣας υπενθυμίζουμε ότι οι πληρωμές συνδρομής τρέχουσας περιόδου πρέπει να γίνουν μέχρι __/__/__.\n\nΠαρακαλούμε φροντίστε για την έγκαιρη εξόφληση.\n\nΕυχαριστούμε!\n\nΜε εκτίμηση,\n{{school_name}}"],
                        ];
                        foreach ($tpls as [$lbl, $txt]):
                        ?>
                        <button type="button" class="tpl-preset-btn" onclick="bcApplyTpl(<?= htmlspecialchars(json_encode($txt), ENT_QUOTES) ?>)"><?= $lbl ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div id="bc_body_vis"
                         contenteditable="true"
                         spellcheck="true"
                         class="tpl-editor"
                         data-field="bc_body"
                         data-placeholder="Γράψε το μήνυμά σου εδώ…&#10;&#10;Ή επίλεξε ένα έτοιμο κείμενο από πάνω."></div>
                    <div id="bcCharBar" style="display:none;margin-top:.5rem">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.4rem">
                            <div id="bcCharInfo" style="font-size:.78rem;font-weight:700;color:var(--muted,#8892b0)">0 χαρακτήρες</div>
                            <div id="bcSmsBadge" style="font-size:.72rem;font-weight:800;padding:.2rem .55rem;border-radius:6px;background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.25)">1 SMS</div>
                        </div>
                        <div style="margin-top:.35rem;background:rgba(255,255,255,.06);border-radius:99px;height:5px;overflow:hidden">
                            <div id="bcCharBar_fill" style="height:100%;border-radius:99px;transition:width .2s,background .2s;width:0%"></div>
                        </div>
                    </div>
                    <div class="char-ctr" id="bcCharCtr" style="display:none">0 χαρακτήρες</div>
                </div>
            </div><!-- /bcStep2 -->

            <!-- STEP 3: Preview -->
            <div class="bc-step" id="bcStep3">
                <div class="m-sec">
                    <label class="m-lbl"><i class="fa-solid fa-eye" style="color:#3b82f6;margin-right:.35rem"></i>Προεπισκόπηση</label>
                    <div id="bcPrevSubj" style="font-size:.78rem;font-weight:700;color:var(--muted,#8892b0);margin-bottom:.3rem;display:none">
                        Θέμα: <span id="bcPrevSubjTxt" style="color:var(--text,#e2e8f0)"></span>
                    </div>
                    <div class="preview-box" id="bcPrevBody">—</div>
                </div>
                <div class="m-sec" style="margin-bottom:0">
                    <label class="m-lbl"><i class="fa-solid fa-list-check" style="color:var(--green,#2dc653);margin-right:.35rem"></i>Σύνοψη</label>
                    <div style="border:1.5px solid var(--border,#1e2536);border-radius:12px;padding:.5rem 1rem;background:var(--input-bg,#0f1219)">
                        <div class="sum-row"><span class="sum-k">Παραλήπτες</span><span class="sum-v" id="bcSumRecip">—</span></div>
                        <div class="sum-row"><span class="sum-k">Αποστολή μέσω</span><span class="sum-v" id="bcSumCh">—</span></div>
                        <div class="sum-row"><span class="sum-k">Μήκος</span><span class="sum-v" id="bcSumLen">—</span></div>
                    </div>
                </div>
            </div><!-- /bcStep3 -->

            <!-- STEP 4: Confirm -->
            <div class="bc-step" id="bcStep4">
                <div style="text-align:center;padding:1.5rem .5rem">
                    <div style="width:72px;height:72px;background:rgba(139,92,246,.12);border:2px solid rgba(139,92,246,.3);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;margin:0 auto 1rem">
                        <i class="fa-solid fa-paper-plane" style="color:#8b5cf6"></i>
                    </div>
                    <div style="font-size:1.05rem;font-weight:900;color:var(--text,#e2e8f0);margin-bottom:.4rem">Έτοιμο για αποστολή!</div>
                    <div style="font-size:.87rem;color:var(--muted,#8892b0);margin-bottom:1.3rem">
                        Προς: <strong id="bcFinalRecip" style="color:#8b5cf6">—</strong> &nbsp;|&nbsp;
                        Μέσω: <strong id="bcFinalCh" style="color:var(--gold,#f0a500)">—</strong>
                    </div>
                    <div style="background:rgba(230,57,70,.07);border:1px solid rgba(230,57,70,.2);border-radius:12px;padding:.7rem 1rem;font-size:.81rem;color:var(--muted,#8892b0);display:flex;align-items:center;gap:.5rem;text-align:left">
                        <i class="fa-solid fa-triangle-exclamation" style="color:var(--red,#e63946);flex-shrink:0"></i>
                        Η αποστολή δεν μπορεί να αναιρεθεί. Βεβαιώσου ότι το μήνυμα είναι σωστό.
                    </div>
                </div>
            </div><!-- /bcStep4 -->
        </div><!-- /bcBody -->

        <div class="modal-ft" id="bcFooter">
            <button type="button" id="bcPrev" class="btn btn-ghost" onclick="bcNav(-1)" style="display:none">
                <i class="fa-solid fa-chevron-left"></i> Πίσω
            </button>
            <div style="flex:1"></div>
            <button type="button" id="bcNext" class="btn btn-primary" onclick="bcNav(1)">
                Επόμενο <i class="fa-solid fa-chevron-right"></i>
            </button>
            <button type="submit" id="bcSend" class="btn" style="display:none;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;font-weight:800!important;min-height:46px">
                <i class="fa-solid fa-paper-plane"></i> Αποστολή τώρα!
            </button>
        </div>
    </form>
</div>
</div><!-- /broadcastModal -->

<!-- Pro upgrade modal -->
<div id="proModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(8px);z-index:10600;align-items:center;justify-content:center;padding:1rem"
     onclick="if(event.target===this)closeProModal()">
    <div style="background:var(--card-bg,#131929);border:1px solid rgba(240,165,0,.3);border-radius:22px;width:100%;max-width:400px;box-shadow:0 28px 80px rgba(0,0,0,.65);overflow:hidden;animation:modalIn .28s cubic-bezier(.2,.8,.2,1) both">
        <div style="background:linear-gradient(135deg,rgba(240,165,0,.15),rgba(230,57,70,.08));padding:1.5rem 1.5rem 1.2rem;text-align:center;border-bottom:1px solid rgba(240,165,0,.2)">
            <div style="font-size:2.2rem;margin-bottom:.5rem">⭐</div>
            <div style="font-size:1.15rem;font-weight:900;color:var(--text,#e2e8f0)">Αναβάθμιση σε Pro</div>
            <div style="font-size:.85rem;color:var(--muted,#8892b0);margin-top:.3rem">Το SMS απαιτεί Pro πλάνο</div>
        </div>
        <div style="padding:1.2rem 1.5rem">
            <a href="<?= APP_URL ?>/pages/upgrade.php" class="btn btn-primary" style="width:100%;justify-content:center;min-height:46px;font-size:1rem!important;background:linear-gradient(135deg,#f0a500,#e67e00)">
                <i class="fa-solid fa-rocket"></i> Αναβάθμιση σε Pro — 25,00€/μήνα
            </a>
            <button onclick="closeProModal()" style="width:100%;margin-top:.6rem;background:none;border:none;color:var(--muted,#8892b0);font-size:.88rem;cursor:pointer;padding:.4rem">Παράλειψη</button>
        </div>
    </div>
</div>

<!-- Custom warning modal for rule limit -->
<div id="ruleLimitWarningModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:10700;align-items:center;justify-content:center;padding:1rem"
     onclick="if(event.target===this)closeRuleLimitWarning()">
    <div style="background:var(--card-bg,#131929);border:1px solid rgba(240,165,0,.4);border-radius:22px;width:100%;max-width:420px;box-shadow:0 28px 80px rgba(0,0,0,.7);animation:modalIn .28s cubic-bezier(.2,.8,.2,1) both">
        <div style="background:linear-gradient(135deg,rgba(240,165,0,.12),rgba(230,57,70,.06));padding:1.2rem 1.5rem;border-bottom:1px solid rgba(240,165,0,.2)">
            <div style="display:flex;align-items:center;gap:.6rem">
                <i class="fa-solid fa-triangle-exclamation" style="color:#f0a500;font-size:1.3rem"></i>
                <div style="font-weight:900;font-size:1rem;color:var(--text,#e2e8f0)">Προσοχή — Πολλές Ειδοποιήσεις</div>
            </div>
        </div>
        <div style="padding:1.2rem 1.5rem">
            <p style="margin:0 0 .8rem;font-size:.95rem;color:var(--text,#e2e8f0)">Έχετε ήδη <strong id="warningActiveCount">2</strong> ενεργές αυτόματες ειδοποιήσεις.</p>
            <p style="margin:0 0 1rem;font-size:.88rem;color:var(--muted,#8892b0);line-height:1.5">
                Η προσθήκη περισσότερων ειδοποιήσεων μπορεί να γίνει <strong>ενοχλητική για τους παραλήπτες</strong> και να μειώσει την αποτελεσματικότητά τους.
            </p>
            <p style="margin:0 0 1.2rem;font-size:.88rem;color:var(--muted,#8892b0);line-height:1.5">
                Θέλετε να προχωρήσετε παρά την προειδοποίηση;
            </p>
            <div style="display:flex;gap:.7rem;justify-content:flex-end">
                <button onclick="closeRuleLimitWarning()" class="btn btn-ghost" style="min-height:40px">
                    <i class="fa-solid fa-ban"></i> Ακύρωση
                </button>
                <button id="ruleLimitProceedBtn" class="btn" style="background:#f0a500;color:#1e1e2a;font-weight:800;min-height:40px">
                    <i class="fa-solid fa-exclamation-triangle"></i> Αγνόηση & Προχώρησε
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     RULE DETAIL MODAL
══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="ruleDetailModal" role="dialog" aria-modal="true" onclick="if(event.target===this)closeRuleDetail()">
<div class="modal-box" style="max-width:500px">

    <div class="modal-hd">
        <div class="modal-title">
            <i class="fa-solid fa-bell" id="rd_icon" style="color:var(--green,#2dc653)"></i>
            <span id="rd_title">Λεπτομέρειες Υπενθύμισης</span>
        </div>
        <button type="button" class="modal-close" onclick="closeRuleDetail()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="modal-body" style="padding-bottom:.75rem">

        <!-- Status banner -->
        <div id="rd_status_banner" style="display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem;border-radius:13px;margin-bottom:1.1rem;border:1px solid">
            <i id="rd_status_icon" class="fa-solid fa-circle-check" style="font-size:1.15rem;flex-shrink:0"></i>
            <div>
                <div id="rd_status_text" style="font-weight:800;font-size:.95rem"></div>
                <div id="rd_status_sub" style="font-size:.78rem;margin-top:.08rem;opacity:.75"></div>
            </div>
        </div>

        <!-- Timing pill + channel pills -->
        <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:1.1rem" id="rd_pills"></div>

        <!-- Subject -->
        <div id="rd_subj_wrap" style="margin-bottom:.75rem">
            <div style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#8892b0);margin-bottom:.35rem;display:flex;align-items:center;gap:.35rem">
                <i class="fa-solid fa-tag" style="color:#3b82f6;font-size:.7rem"></i> Θέμα Ειδοποίησης
            </div>
            <div id="rd_subject" style="background:var(--input-bg,#0f1219);border:1.5px solid var(--border,#1e2536);border-radius:10px;padding:.6rem .85rem;font-size:.9rem;font-weight:600;color:var(--text,#e2e8f0);word-break:break-word"></div>
        </div>

        <!-- Body -->
        <div id="rd_body_wrap">
            <div style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#8892b0);margin-bottom:.35rem;display:flex;align-items:center;gap:.35rem">
                <i class="fa-solid fa-pen-nib" style="color:var(--gold,#f0a500);font-size:.7rem"></i> Κείμενο Μηνύματος
            </div>
            <div id="rd_body" style="background:var(--input-bg,#0f1219);border:1.5px solid var(--border,#1e2536);border-radius:10px;padding:.75rem .85rem;font-size:.88rem;line-height:1.7;color:var(--muted,#8892b0);white-space:pre-wrap;word-break:break-word;max-height:220px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#e63946 rgba(230,57,70,.08)"></div>
        </div>

    </div>

    <!-- Footer: always Edit + Κλείσιμο side by side -->
    <div class="modal-ft" style="justify-content:flex-end;gap:.5rem">
        <button type="button" id="rd_edit_btn" class="btn btn-sm"
            style="background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.3);font-weight:700">
            <i class="fa-solid fa-pen-to-square"></i> Επεξεργασία
        </button>
        <button type="button" onclick="closeRuleDetail()" class="btn btn-sm"
            style="background:none;color:var(--red,#e63946);border:1.5px solid var(--red,#e63946);font-weight:700">
            <i class="fa-solid fa-xmark"></i> Κλείσιμο
        </button>
    </div>
</div>
</div><!-- /ruleDetailModal -->

<script>
/* PHP → JS constants */
var DEFAULT_RULE_ID = <?= (int)$defaultRuleId ?>;
var SCHOOL_HAS_SMS  = <?= $hasSms ? 'true' : 'false' ?>;

/* ════════════════════════════════════════════════════════════
   SIDEBAR TOGGLE
══════════════════════════════════════════════════════════ */
(function(){
    var sb=document.getElementById('sidebar'),ov=document.getElementById('dm-overlay'),mb=document.getElementById('menuBtn');
    if(!sb||!mb)return;
    function o(){sb.classList.add('open');ov&&ov.classList.add('on');document.body.style.overflow='hidden';}
    function c(){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}
    mb.onclick=function(e){e.stopPropagation();sb.classList.contains('open')?c():o();};
    ov&&ov.addEventListener('click',c);
    sb.querySelectorAll('a.nav-item').forEach(function(l){l.addEventListener('click',function(){if(window.innerWidth<=900)setTimeout(c,80);});});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')c();});
    window.addEventListener('resize',function(){if(window.innerWidth>900){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}});
})();

/* ════════════════════════════════════════════════════════════
   TAB SWITCH
══════════════════════════════════════════════════════════ */
function switchTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(function(p){p.classList.remove('active');});
    document.querySelectorAll('.tab-btn').forEach(function(b){b.classList.remove('active');});
    document.getElementById('tab-' + id).classList.add('active');
    btn.classList.add('active');
}

/* ════════════════════════════════════════════════════════════
   DELETE CONFIRM
══════════════════════════════════════════════════════════ */
var _delForm=null;
function confirmDel(e,form){e.preventDefault();_delForm=form;document.getElementById('delModal').style.display='flex';document.body.style.overflow='hidden';return false;}
function closeDelModal(){document.getElementById('delModal').style.display='none';document.body.style.overflow='';_delForm=null;}
document.getElementById('delConfirmBtn').onclick=function(){if(_delForm)_delForm.submit();};

/* ════════════════════════════════════════════════════════════
   ★★★  MODAL SCROLL HINT ENGINE  ★★★
══════════════════════════════════════════════════════════ */
(function(){
    function attachScrollHint(modalBoxEl) {
        if (!modalBoxEl) return;
        var hint = document.createElement('div');
        hint.className = 'modal-scroll-hint hidden';
        hint.innerHTML = '';
        modalBoxEl.appendChild(hint);

        function update() {
            var canScroll = modalBoxEl.scrollHeight > modalBoxEl.clientHeight + 12;
            var atBottom  = modalBoxEl.scrollTop + modalBoxEl.clientHeight >= modalBoxEl.scrollHeight - 20;
            if (canScroll && !atBottom) hint.classList.remove('hidden');
            else hint.classList.add('hidden');
        }

        modalBoxEl.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);

        var backdrop = modalBoxEl.closest('.modal-backdrop');
        if (backdrop) {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    if (m.type === 'attributes' && m.attributeName === 'class') {
                        if (backdrop.classList.contains('open')) setTimeout(update, 220);
                    }
                });
            });
            observer.observe(backdrop, { attributes: true });
        }
        setTimeout(update, 300);
    }
    document.querySelectorAll('.modal-box').forEach(attachScrollHint);
})();

/* ════════════════════════════════════════════════════════════
   ★★★  RICH TEMPLATE EDITOR ENGINE  ★★★
══════════════════════════════════════════════════════════ */
(function(){
    var TOKEN_TO_LABEL = {
        'athlete_name': 'Όνομα αθλητή',
        'valid_until':  'Ημ. λήξης',
        'amount':       'Ποσό οφειλής',
        'school_name':  'Όνομα συλλόγου'
    };

    function makeTokenSpan(key) {
        var label = TOKEN_TO_LABEL[key] || key;
        var sp = document.createElement('span');
        sp.className = 'tpl-token';
        sp.contentEditable = 'false';
        sp.dataset.token = key;
        sp.textContent = label;
        return sp;
    }

    function loadTemplateIntoEditor(el, raw) {
        el.innerHTML = '';
        if (!raw) return;
        var parts = raw.split(/(\{\{[a-z_]+\}\})/);
        parts.forEach(function(part) {
            var m = part.match(/^\{\{([a-z_]+)\}\}$/);
            if (m && TOKEN_TO_LABEL[m[1]]) {
                el.appendChild(makeTokenSpan(m[1]));
            } else {
                var lines = part.split('\n');
                lines.forEach(function(line, i) {
                    if (line) el.appendChild(document.createTextNode(line));
                    if (i < lines.length - 1) el.appendChild(document.createElement('br'));
                });
            }
        });
    }

    function readEditorToTemplate(el) {
        var result = '';
        el.childNodes.forEach(function(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                result += node.textContent;
            } else if (node.nodeName === 'BR') {
                result += '\n';
            } else if (node.nodeName === 'SPAN' && node.dataset.token) {
                result += '{{' + node.dataset.token + '}}';
            } else if (node.nodeName === 'DIV') {
                result += '\n' + readEditorToTemplate(node);
            } else {
                result += node.textContent;
            }
        });
        return result;
    }

    window.syncTplField = function(field) {
        var vis, hid;
        if (field === 'subject') {
            vis = document.getElementById('m_subject_vis');
            hid = document.getElementById('m_subject_tpl');
        } else if (field === 'body') {
            vis = document.getElementById('m_body_vis');
            hid = document.getElementById('m_body_tpl');
        } else if (field === 'bc_body') {
            vis = document.getElementById('bc_body_vis');
            hid = document.getElementById('bc_body');
        }
        if (!vis || !hid) return;
        hid.value = readEditorToTemplate(vis);

        if (field === 'bc_body') updateSmsCounter('bc');
        if (field === 'body')    updateSmsCounter('rule');
    };

    window.loadTemplate = function(field, raw) {
        var vis;
        if (field === 'subject') vis = document.getElementById('m_subject_vis');
        else if (field === 'body') vis = document.getElementById('m_body_vis');
        else if (field === 'bc_body') vis = document.getElementById('bc_body_vis');
        if (!vis) return;
        // Enforce 250-char cap when loading (truncate raw text, preserve tokens)
        if (raw && raw.length > MAX_CHARS) {
            var parts  = raw.split(/(\{\{[a-z_]+\}\})/);
            var capped = '';
            for (var pi = 0; pi < parts.length; pi++) {
                if (capped.length + parts[pi].length <= MAX_CHARS) {
                    capped += parts[pi];
                } else {
                    capped += parts[pi].slice(0, MAX_CHARS - capped.length);
                    break;
                }
            }
            raw = capped;
        }
        loadTemplateIntoEditor(vis, raw);
        syncTplField(field);
        setTimeout(function(){ updateLimitDot(vis); }, 50);
    };

    var MAX_CHARS = 250;

    /** Get raw template length from hidden field */
    function getRawLenFor(el) {
        var field = el.dataset.field;
        var hid = field === 'subject' ? document.getElementById('m_subject_tpl')
                : field === 'body'    ? document.getElementById('m_body_tpl')
                : field === 'bc_body' ? document.getElementById('bc_body')
                : null;
        return hid ? hid.value.length : 0;
    }

    /** Check if a key adds a character (not navigation/control) */
    function isCharKey(e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return false;
        if (e.key.length !== 1) return false;   // Enter, Backspace, Arrow keys etc. are >1 char
        return true;
    }

    /**
     * Prevent Backspace / Delete / Cut from destroying token chips.
     * Also enforces MAX_CHARS hard limit — blocks typing and truncates pastes.
     * Shows "…" at the bottom-right of the editor when at limit.
     */
    function guardTokens(el) {
        el.addEventListener('keydown', function(e) {
            // ── Hard char limit ──────────────────────────────────────────
            if (isCharKey(e) && getRawLenFor(el) >= MAX_CHARS) {
                e.preventDefault();
                showLimitDot(el);
                return;
            }

            var isBs  = e.key === 'Backspace';
            var isDel = e.key === 'Delete';
            var isCut = (e.key === 'x' || e.key === 'X') && (e.ctrlKey || e.metaKey);
            if (!isBs && !isDel && !isCut) return;

            var sel = window.getSelection();
            if (!sel || !sel.rangeCount) return;
            var range = sel.getRangeAt(0);

            // ── Non-collapsed selection: block if any token is inside ──────
            if (!range.collapsed) {
                var frag = range.cloneContents();
                if (frag.querySelector('span[data-token]')) {
                    e.preventDefault();
                    // Collapse to anchor so user can retype around the token
                    range.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(range);
                }
                return;
            }

            // ── Collapsed cursor: check the adjacent node ─────────────────
            var node   = range.startContainer;
            var offset = range.startOffset;
            var adjacent = null;

            if (isBs) {
                adjacent = (node.nodeType === Node.TEXT_NODE)
                    ? (offset === 0 ? node.previousSibling : null)
                    : node.childNodes[offset - 1];
            } else if (isDel) {
                adjacent = (node.nodeType === Node.TEXT_NODE)
                    ? (offset === node.textContent.length ? node.nextSibling : null)
                    : node.childNodes[offset];
            }

            if (adjacent && adjacent.nodeName === 'SPAN' && adjacent.dataset && adjacent.dataset.token) {
                e.preventDefault();
            }
        });

        // ── Paste: strip tokens from pasted content, truncate to fit ──
        el.addEventListener('paste', function(e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
            var field = el.dataset.field;
            var hid = field === 'subject' ? document.getElementById('m_subject_tpl')
                    : field === 'body'    ? document.getElementById('m_body_tpl')
                    : field === 'bc_body' ? document.getElementById('bc_body')
                    : null;
            var currentLen = hid ? hid.value.length : 0;
            var remaining  = Math.max(0, MAX_CHARS - currentLen);
            if (remaining === 0) { showLimitDot(el); return; }
            text = text.slice(0, remaining);
            document.execCommand('insertText', false, text);
            var field2 = el.dataset.field || '';
            if (field2 === 'subject') syncTplField('subject');
            else if (field2 === 'body') syncTplField('body');
            else if (field2 === 'bc_body') syncTplField('bc_body');
            updateLimitDot(el);
        });

        // Tokens must not be draggable out of the editor
        el.addEventListener('dragstart', function(e) {
            if (e.target && e.target.nodeName === 'SPAN' && e.target.dataset && e.target.dataset.token) {
                e.preventDefault();
            }
        });

        // Update dot on every input
        el.addEventListener('input', function() { updateLimitDot(el); });
    }

    /** Show/hide the "…" limit indicator on the editor */
    function showLimitDot(el) {
        el.dataset.atLimit = '1';
        el.style.borderColor = 'rgba(240,165,0,.6)';
        el.style.boxShadow   = '0 0 0 3px rgba(240,165,0,.12)';
        setTimeout(function() {
            el.style.borderColor = '';
            el.style.boxShadow   = '';
        }, 600);
    }

    function updateLimitDot(el) {
        var len  = getRawLenFor(el);
        var wrap = el.parentNode;
        if (!wrap) return;
        var dotId = 'lim_' + (el.id || el.dataset.field);
        var dot   = document.getElementById(dotId);
        if (len >= MAX_CHARS) {
            if (!dot) {
                dot = document.createElement('div');
                dot.id = dotId;
                dot.setAttribute('aria-hidden', 'true');
                dot.style.cssText = 'position:absolute;bottom:.5rem;right:.7rem;font-size:.95rem;color:rgba(240,165,0,.7);font-weight:900;pointer-events:none;user-select:none;line-height:1';
                dot.textContent = '…';
                // Make parent relative so the dot positions correctly
                var pos = getComputedStyle(wrap).position;
                if (pos === 'static') wrap.style.position = 'relative';
                wrap.appendChild(dot);
            }
            dot.style.display = '';
        } else {
            if (dot) dot.style.display = 'none';
        }
    }

    function wireEditors() {
        [['m_subject_vis','subject'], ['m_body_vis','body'], ['bc_body_vis','bc_body']].forEach(function(pair) {
            var el = document.getElementById(pair[0]);
            if (!el || el.dataset.tplWired === '1') return;
            el.dataset.tplWired = '1';
            el.addEventListener('input',   function(){ syncTplField(pair[1]); });
            el.addEventListener('blur',    function(){ syncTplField(pair[1]); });
            if (pair[1] === 'subject') {
                el.addEventListener('keydown', function(e){ if (e.key === 'Enter') e.preventDefault(); });
            }
            guardTokens(el);
        });
        var bodyVis = document.getElementById('m_body_vis');
        if (bodyVis) bodyVis.addEventListener('input', function(){ if (window.updateSmsCounter) updateSmsCounter('rule'); });
        var bcVis = document.getElementById('bc_body_vis');
        if (bcVis) bcVis.addEventListener('input', function(){ if (window.updateSmsCounter) updateSmsCounter('bc'); });
    }

    document.addEventListener('DOMContentLoaded', function() {
        wireEditors();
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function() {
                syncTplField('subject');
                syncTplField('body');
                syncTplField('bc_body');
            });
        });
    });
    wireEditors();

    window._tplEngine = { loadTemplateIntoEditor: loadTemplateIntoEditor, readEditorToTemplate: readEditorToTemplate };
})();

/* ════════════════════════════════════════════════════════════
   SMS CHARACTER COUNTER
   Simplified warning: just "Το μήνυμα είναι πολύ μεγάλο"
══════════════════════════════════════════════════════════ */
(function(){
    function getRawLen(field) {
        var hid;
        if (field === 'bc_body') hid = document.getElementById('bc_body');
        else if (field === 'body') hid = document.getElementById('m_body_tpl');
        if (!hid) return 0;
        return hid.value.length;
    }

    function smsActive(prefix) {
        var cb = document.getElementById(prefix === 'bc' ? 'bc_ch_sms' : 'm_ch_sms');
        return cb && cb.checked;
    }

    window.updateSmsCounter = function(prefix) {
        var field   = prefix === 'bc' ? 'bc_body' : 'body';
        var barEl   = document.getElementById(prefix + 'CharBar');
        var infoEl  = document.getElementById(prefix + 'CharInfo');
        var badgeEl = document.getElementById(prefix + 'SmsBadge');
        var fillEl  = document.getElementById(prefix + 'CharBar_fill');
        var warnEl  = document.getElementById(prefix + 'CharWarn');
        var warnTxt = document.getElementById(prefix + 'CharWarnTxt');

        if (!barEl) return;

        var active = smsActive(prefix);
        barEl.style.display = active ? '' : 'none';
        if (!active) return;

        var n       = getRawLen(field);
        var MAX_CHARS_DISPLAY = 250;
        var SEG     = 160;
        var SEG_MUL = 153;

        var segments = n <= SEG ? 1 : Math.ceil(n / SEG_MUL);
        var pct      = Math.min(100, Math.round((n / MAX_CHARS_DISPLAY) * 100));

        var fillColor = '#2dc653';
        if (n > 200) fillColor = '#f0a500';
        if (n >= 250) fillColor = '#e63946';

        var badgeBg  = 'rgba(45,198,83,.12)';
        var badgeClr = '#2dc653';
        var badgeBdr = 'rgba(45,198,83,.25)';
        if (segments === 2) { badgeBg = 'rgba(240,165,0,.12)'; badgeClr = '#f0a500'; badgeBdr = 'rgba(240,165,0,.3)'; }
        if (segments >= 3) { badgeBg = 'rgba(230,57,70,.12)'; badgeClr = '#e63946'; badgeBdr = 'rgba(230,57,70,.3)'; }

        infoEl.textContent = n + ' / 250 χαρακτήρες';
        infoEl.style.color = fillColor;

        badgeEl.textContent = segments + ' SMS';
        badgeEl.style.background = badgeBg;
        badgeEl.style.color = badgeClr;
        badgeEl.style.borderColor = badgeBdr;

        fillEl.style.width = pct + '%';
        fillEl.style.background = fillColor;

        /* After 250 chars show a subtle "..." indicator — no warning text */
        if (warnEl) warnEl.style.display = 'none';

        var vis = document.getElementById(prefix === 'bc' ? 'bc_body_vis' : 'm_body_vis');
        var dotsId = prefix + 'CharDots';
        var dotsEl = document.getElementById(dotsId);
        if (n > 250) {
            if (!dotsEl) {
                dotsEl = document.createElement('div');
                dotsEl.id = dotsId;
                dotsEl.style.cssText = 'font-size:.8rem;color:var(--muted,#8892b0);letter-spacing:.18em;text-align:right;margin-top:.2rem;user-select:none';
                dotsEl.textContent = '…';
                if (barEl.parentNode) barEl.parentNode.insertBefore(dotsEl, barEl.nextSibling);
            }
            dotsEl.style.display = '';
        } else {
            if (dotsEl) dotsEl.style.display = 'none';
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        var bcSms = document.getElementById('bc_ch_sms');
        if (bcSms) bcSms.addEventListener('change', function() { updateSmsCounter('bc'); });
        var ruleSms = document.getElementById('m_ch_sms');
        if (ruleSms) ruleSms.addEventListener('change', function() { updateSmsCounter('rule'); });
    });
})();

/* ════════════════════════════════════════════════════════════
   RULE MODAL
══════════════════════════════════════════════════════════ */
(function(){
    var modal     = document.getElementById('ruleModal');
    var openBtn   = document.getElementById('openAddModal');
    var closeBtn  = document.getElementById('closeRuleModal');
    var cancelBtn = document.getElementById('cancelRuleModal');
    var form      = document.getElementById('ruleForm');
    var formWrap  = document.getElementById('ruleFormWrap');
    var success   = document.getElementById('ruleSuccess');
    var daysDisp  = document.getElementById('daysDisplay');
    var daysInput = document.getElementById('m_trigger_days');
    var daysGroup = document.getElementById('daysGroup');
    if (!modal) return;

    var DEFAULT_SUBJECT = {
        days_before: 'Υπενθύμιση ανανέωσης συνδρομής — {{school_name}}'
    };

    var DEFAULT_BODY = {
        days_before: 'Αγαπητέ/ή κηδεμόνα,\n\nΣας ενημερώνουμε ότι η συνδρομή του/της {{athlete_name}} πρόκειται να λήξει σύντομα.\nΠοσό συνδρομής: {{amount}}€\n\nΠαρακαλούμε φροντίστε για την έγκαιρη ανανέωσή της.\n\nΜε εκτίμηση,\n{{school_name}}'
    };

    function getDays(){ return Math.max(1, parseInt(daysInput.value)||1); }
    function setDays(v){ v=Math.max(1,Math.min(365,v)); daysInput.value=v; daysDisp.textContent=v; }

    document.getElementById('daysMinus').addEventListener('click', function(){ setDays(getDays()-1); });
    document.getElementById('daysPlus').addEventListener('click',  function(){ setDays(getDays()+1); });

    // Only days_before remains — days group is always visible for new rules
    window.onTimingChange = function() {
        if (daysGroup) daysGroup.style.display = '';
    };

    function resetForm() {
        form.reset();
        document.getElementById('m_id').value = '';

        var emailCb = document.getElementById('m_ch_email');
        var smsCb   = document.getElementById('m_ch_sms');
        if (smsCb && !smsCb.disabled) {
            smsCb.checked = false;
            if (emailCb) emailCb.checked = true;
        } else {
            if (smsCb) smsCb.checked = false;
            if (emailCb) emailCb.checked = true;
        }

        setDays(5);
        onTimingChange();
        window.loadTemplate('subject', DEFAULT_SUBJECT['days_before']);
        window.loadTemplate('body',    DEFAULT_BODY['days_before']);
        document.getElementById('mTitleIcon').style.color = 'var(--green,#2dc653)';
        document.getElementById('mTitleText').textContent = 'Νέα Υπενθύμιση';
        document.getElementById('mSubmitLabel').textContent = 'Αποθήκευση Υπενθύμισης';
        document.getElementById('mSuccessMsg').textContent = 'Η υπενθύμιση αποθηκεύτηκε!';
        formWrap.style.display = '';
        success.classList.remove('show');
        if (window.updateSmsCounter) updateSmsCounter('rule');
    }

    function countActiveRules() {
        return document.querySelectorAll('.rules-list .rule-row:not(.is-off)').length;
    }

    var warningModal = document.getElementById('ruleLimitWarningModal');
    var pendingSubmitCallback = null;

    window.closeRuleLimitWarning = function() {
        if (warningModal) warningModal.style.display = 'none';
        document.body.style.overflow = '';
        pendingSubmitCallback = null;
    };

    window.showRuleLimitWarning = function(callback) {
        var cnt = countActiveRules();
        var cntSpan = document.getElementById('warningActiveCount');
        if (cntSpan) cntSpan.textContent = cnt;
        pendingSubmitCallback = callback;
        if (warningModal) {
            warningModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    };

    var proceedBtn = document.getElementById('ruleLimitProceedBtn');
    if (proceedBtn) {
        proceedBtn.onclick = function() {
            if (pendingSubmitCallback) pendingSubmitCallback();
            closeRuleLimitWarning();
        };
    }

    function openModal() {
        resetForm();
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        var box = modal.querySelector('.modal-box');
        if (box) box.scrollTop = 0;
        setTimeout(function(){ document.getElementById('m_rule_name').focus(); }, 150);
    }
    function closeModal() {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    openBtn  && openBtn.addEventListener('click',  openModal);
    closeBtn && closeBtn.addEventListener('click', closeModal);
    cancelBtn&& cancelBtn.addEventListener('click',closeModal);
    modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape'&&modal.classList.contains('open')) closeModal(); });

    form && form.addEventListener('submit', function(e){
        e.preventDefault();
        var isNew = !document.getElementById('m_id').value;
        var activeRules = countActiveRules();
        if (isNew && activeRules >= 2) {
            showRuleLimitWarning(function() {
                syncTplField('subject');
                syncTplField('body');
                formWrap.style.display = 'none';
                success.classList.add('show');
                setTimeout(function(){ form.submit(); }, 900);
            });
        } else {
            syncTplField('subject');
            syncTplField('body');
            formWrap.style.display = 'none';
            success.classList.add('show');
            setTimeout(function(){ form.submit(); }, 900);
        }
    });

    window.openEditModal = function(r) {
        form.reset();
        var isDefaultRule = (parseInt(r.id) === DEFAULT_RULE_ID);
        document.getElementById('m_id').value = r.id;
        document.getElementById('m_rule_name').value = r.rule_name || '';
        window.loadTemplate('subject', r.subject_tpl || '');
        window.loadTemplate('body',    r.body_tpl || '');
        var chs = (r.channels || 'email').split(',');
        document.getElementById('m_ch_email').checked = chs.indexOf('email') >= 0;
        var smsEl = document.getElementById('m_ch_sms');
        if (smsEl && !smsEl.disabled) smsEl.checked = chs.indexOf('sms') >= 0;

        // For the default rule: hide all timing/naming (fixed rule — only text+channels editable)
        var nameField = document.getElementById('m_rule_name').closest('.m-sec');
        var daysGrp   = document.getElementById('daysGroup');
        // The static timing display section (the "Πριν λήξει" card + the daysGroup)
        var timingCard = daysGrp ? daysGrp.previousElementSibling : null;

        if (isDefaultRule) {
            if (timingCard) timingCard.style.display = 'none';
            if (daysGrp)    daysGrp.style.display    = 'none';
            if (nameField)  nameField.style.display   = 'none';
            if (!document.getElementById('defaultRuleInfo')) {
                var banner = document.createElement('div');
                banner.id = 'defaultRuleInfo';
                banner.style.cssText = 'background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.35);border-radius:12px;padding:.75rem 1rem;font-size:.86rem;color:#7ec8e3;display:flex;align-items:center;gap:.6rem;margin-bottom:.9rem';
                banner.innerHTML = '<i class="fa-solid fa-lock" style="flex-shrink:0;color:#3b82f6"></i><span>Προεπιλεγμένη ειδοποίηση — μπορείτε να αλλάξετε μόνο το κείμενο και τον τρόπο αποστολής.</span>';
                var modalBodyEl = document.querySelector('#ruleFormWrap .modal-body');
                if (modalBodyEl) modalBodyEl.insertBefore(banner, modalBodyEl.firstChild);
            } else {
                document.getElementById('defaultRuleInfo').style.display = 'flex';
            }
        } else {
            if (timingCard) timingCard.style.display = '';
            if (daysGrp)    daysGrp.style.display    = '';
            if (nameField)  nameField.style.display   = '';
            var inf = document.getElementById('defaultRuleInfo');
            if (inf) inf.style.display = 'none';
            setDays(parseInt(r.trigger_days) || 5);
        }

        document.getElementById('mTitleIcon').style.color = isDefaultRule ? '#3b82f6' : 'var(--gold,#f0a500)';
        document.getElementById('mTitleText').textContent  = isDefaultRule ? 'Επεξεργασία Προεπιλεγμένης Ειδοποίησης' : 'Επεξεργασία Υπενθύμισης';
        document.getElementById('mSubmitLabel').textContent = 'Αποθήκευση Αλλαγών';
        document.getElementById('mSuccessMsg').textContent  = 'Οι αλλαγές αποθηκεύτηκαν!';
        formWrap.style.display = '';
        success.classList.remove('show');
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        var box = modal.querySelector('.modal-box');
        if (box) box.scrollTop = 0;
        setTimeout(function(){
            var focusEl = isDefaultRule ? document.getElementById('m_subject_vis') : document.getElementById('m_rule_name');
            if (focusEl) focusEl.focus();
        }, 150);
        if (window.updateSmsCounter) setTimeout(function(){ updateSmsCounter('rule'); }, 50);
    };

    onTimingChange();
})();

/* ════════════════════════════════════════════════════════════
   BROADCAST MODAL
══════════════════════════════════════════════════════════ */
(function(){
    var modal    = document.getElementById('broadcastModal');
    var openBtn  = document.getElementById('openBroadcastBtn');
    var closeBtn = document.getElementById('closeBroadcastModal');
    var form     = document.getElementById('broadcastForm');
    var bcBody   = document.getElementById('bcBody');
    var bcSend   = document.getElementById('bcSend');
    var bcNext   = document.getElementById('bcNext');
    var bcPrev   = document.getElementById('bcPrev');
    var bcFoot   = document.getElementById('bcFooter');
    var bcSending= document.getElementById('bcSending');
    var step     = 1;
    var STEPS    = 4;
    if (!modal) return;

    function resetBroadcastForm() {
        if (form) form.reset();
        step = 1;
        var filterAll = document.querySelector('input[name="bc_filter"][value="all"]');
        if (filterAll) filterAll.checked = true;
        var deptWrap = document.getElementById('bcDeptPickerWrap');
        var athWrap  = document.getElementById('bcAthPicker');
        var deptSel  = document.getElementById('bcDeptId');
        var subj     = document.getElementById('bc_subject');
        var bodyHid  = document.getElementById('bc_body');
        var bodyVis  = document.getElementById('bc_body_vis');
        var athSrch  = document.getElementById('bcAthSearch');
        var sending  = document.getElementById('bcSending');
        if (deptWrap) deptWrap.style.display = 'none';
        if (athWrap)  athWrap.style.display  = 'none';
        if (deptSel)  deptSel.value = '';
        if (subj)     subj.value = '';
        if (bodyHid)  bodyHid.value = '';
        if (bodyVis)  bodyVis.innerHTML = '';
        if (athSrch)  athSrch.value = '';
        if (bcBody)   bcBody.style.display = '';
        if (bcFoot)   bcFoot.style.display = '';
        if (sending)  sending.style.display = 'none';
        document.querySelectorAll('input[name="bc_athletes[]"]').forEach(function(cb){ cb.checked = false; });
        document.querySelectorAll('.ath-row').forEach(function(el){ el.style.display = ''; });

        var emailCb = document.getElementById('bc_ch_email');
        var smsCb   = document.getElementById('bc_ch_sms');
        if (smsCb && !smsCb.disabled) {
            smsCb.checked = false;
            if (emailCb) emailCb.checked = true;
        } else {
            if (smsCb) smsCb.checked = false;
            if (emailCb) emailCb.checked = true;
        }

        bcUpdSel();
        syncTplField('bc_body');
        renderStep();
        if (window.updateSmsCounter) updateSmsCounter('bc');
    }

    function open() {
        resetBroadcastForm();
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        var box = modal.querySelector('.modal-box');
        if (box) box.scrollTop = 0;
    }
    function close() {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    openBtn  && openBtn.addEventListener('click', open);
    closeBtn && closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function(e){ if(e.target===modal) close(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape'&&modal.classList.contains('open')) close(); });

    function renderStep() {
        for (var i=1; i<=STEPS; i++) {
            var el  = document.getElementById('bcStep'+i);
            var dot = document.getElementById('bsd'+i);
            var ln  = document.getElementById('bsl'+i);
            if (el)  el.classList.toggle('active', i===step);
            if (dot) { dot.classList.toggle('active', i===step); dot.classList.toggle('done', i<step); }
            if (ln)  ln.classList.toggle('done', i<step);
        }
        bcPrev.style.display = step>1 ? '' : 'none';
        bcNext.style.display = step<STEPS ? '' : 'none';
        bcSend.style.display = step===STEPS ? '' : 'none';
        if (step===2) { if (window.updateSmsCounter) setTimeout(function(){ updateSmsCounter('bc'); }, 50); }
        if (step===3) populatePreview();
        if (step===4) populateFinal();
        var box = modal.querySelector('.modal-box');
        if (box) { box.scrollTop = 0; setTimeout(function(){ box.dispatchEvent(new Event('scroll')); }, 120); }
    }

    window.bcNav = function(dir) {
        if (dir===1) {
            if (step===1) {
                var emailEl = document.getElementById('bc_ch_email');
                var smsEl   = document.getElementById('bc_ch_sms');
                var emailC  = emailEl && emailEl.checked;
                var smsC    = smsEl && smsEl.checked;
                if (!emailC && !smsC) { alert('Επίλεξε τουλάχιστον ένα κανάλι αποστολής.'); return; }
                var filter = (document.querySelector('input[name="bc_filter"]:checked')||{}).value;
                if (filter==='custom') {
                    var checked = document.querySelectorAll('input[name="bc_athletes[]"]:checked');
                    if (checked.length===0) { alert('Επίλεξε τουλάχιστον έναν αθλητή.'); return; }
                }
                if (filter==='department') {
                    var dsel = document.getElementById('bcDeptId');
                    if (!dsel||!dsel.value) { alert('Επίλεξε ένα τμήμα.'); return; }
                }
            }
            if (step===2) {
                syncTplField('bc_body');
                var body = document.getElementById('bc_body').value.trim();
                if (!body) { document.getElementById('bc_body_vis').focus(); alert('Το κείμενο μηνύματος είναι υποχρεωτικό.'); return; }
            }
        }
        step = Math.max(1, Math.min(STEPS, step+dir));
        renderStep();
    };

    document.querySelectorAll('input[name="bc_filter"]').forEach(function(r){
        r.addEventListener('change', function(){
            var wrap = document.getElementById('bcAthPicker');
            if (wrap) {
                wrap.style.display = (this.value==='custom') ? '' : 'none';
                if (this.value!=='custom') bcSelNone();
            }
            var deptWrap = document.getElementById('bcDeptPickerWrap');
            if (deptWrap) deptWrap.style.display = (this.value==='department') ? '' : 'none';
        });
    });

    window.bcFilterAth = function(q) {
        q = q.toLowerCase().trim();
        document.querySelectorAll('.ath-row').forEach(function(el){
            el.style.display = (!q || (el.getAttribute('data-name')||'').includes(q)) ? '' : 'none';
        });
    };
    window.bcSelAll  = function(){ document.querySelectorAll('input[name="bc_athletes[]"]').forEach(function(cb){cb.checked=true;}); bcUpdSel(); };
    window.bcSelNone = function(){ document.querySelectorAll('input[name="bc_athletes[]"]').forEach(function(cb){cb.checked=false;}); bcUpdSel(); };
    window.bcUpdSel  = function(){
        var n = document.querySelectorAll('input[name="bc_athletes[]"]:checked').length;
        var el = document.getElementById('bcSelCtr');
        if (el) el.textContent = n + ' επιλεγμένοι';
    };

    function getRecipLabel() {
        var f = (document.querySelector('input[name="bc_filter"]:checked')||{}).value || 'all';
        if (f==='custom') {
            var n = document.querySelectorAll('input[name="bc_athletes[]"]:checked').length;
            return n + ' αθλητές (χειροκίνητη επιλογή)';
        }
        if (f==='department') {
            var sel = document.getElementById('bcDeptId');
            return sel && sel.value ? 'Τμήμα: ' + (sel.options[sel.selectedIndex]||{}).text : 'Τμήμα (δεν επιλέχθηκε)';
        }
        return {all:'Όλοι οι ενεργοί αθλητές', active_sub:'Ενεργές Συνδρομές', expired_sub:'Ληξιπρόθεσμες'}[f] || f;
    }
    function getChLabel() {
        var a=[];
        if (document.getElementById('bc_ch_email')&&document.getElementById('bc_ch_email').checked) a.push('Email');
        var sms = document.getElementById('bc_ch_sms');
        if (sms&&sms.checked) a.push('SMS');
        return a.join(' + ') || '—';
    }
    function previewText(raw) {
        return (raw||'')
            .replace(/\{\{athlete_name\}\}/g, '(Όνομα αθλητή)')
            .replace(/\{\{school_name\}\}/g,  '(Όνομα συλλόγου)')
            .replace(/\{\{valid_until\}\}/g,   '(Ημ. λήξης)')
            .replace(/\{\{amount\}\}/g,        '(Ποσό)');
    }

    function populatePreview() {
        syncTplField('bc_body');
        var subj = document.getElementById('bc_subject').value.trim();
        var body = document.getElementById('bc_body').value.trim();
        var ps   = document.getElementById('bcPrevSubj');
        var pst  = document.getElementById('bcPrevSubjTxt');
        if (subj&&ps&&pst) { ps.style.display=''; pst.textContent=previewText(subj); }
        else if (ps) ps.style.display='none';
        var pb = document.getElementById('bcPrevBody');
        if (pb) pb.textContent = previewText(body) || '(κενό)';
        var sr = document.getElementById('bcSumRecip');
        var sc = document.getElementById('bcSumCh');
        var sl = document.getElementById('bcSumLen');
        if (sr) sr.textContent = getRecipLabel();
        if (sc) sc.textContent = getChLabel();
        if (sl) sl.textContent = body.length + ' χαρακτήρες';
    }
    function populateFinal() {
        var fr = document.getElementById('bcFinalRecip');
        var fc = document.getElementById('bcFinalCh');
        if (fr) fr.textContent = getRecipLabel();
        if (fc) fc.textContent = getChLabel();
    }

    form && form.addEventListener('submit', function(){
        syncTplField('bc_body');
        bcBody.style.display = 'none';
        bcFoot.style.display = 'none';
        bcSending.style.display = 'block';
    });

    renderStep();
})();

function openProModal()  { document.getElementById('proModal').style.display='flex'; document.body.style.overflow='hidden'; }
function closeProModal() { document.getElementById('proModal').style.display='none'; document.body.style.overflow=''; }

window.bcApplyTpl = function(txt) { window.loadTemplate('bc_body', txt); };

/* ════════════════════════════════════════════════════════════
   RULE DETAIL MODAL
══════════════════════════════════════════════════════════ */
(function(){
    var TRIGGER_LABELS = {
        days_before:   function(d){ return d + ' μέρες πριν τη λήξη'; },
        days_after:    function(d){ return d + ' μέρες μετά τη λήξη'; },
        has_debt:      function(){ return 'Αυτόματη — εκκρεμείς οφειλές (1×/μήνα)'; },
        has_debt:      function(){ return 'Εκκρεμής οφειλή'; },
        on_due:        function(){ return 'Την ημέρα λήξης'; },
        after_payment: function(){ return 'Μετά από κάθε πληρωμή'; }
    };
    var TRIGGER_PILL = {
        days_before: 'pill-before', on_due: 'pill-due',
        days_after: 'pill-after', after_payment: 'pill-payment',
        has_debt: 'pill-after'
    };
    var TRIGGER_ICON = {
        days_before: 'fa-clock', on_due: 'fa-bell',
        days_after: 'fa-triangle-exclamation', after_payment: 'fa-circle-check',
        has_debt: 'fa-triangle-exclamation'
    };

    function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function renderToken(raw) {
        return (raw||'')
            .replace(/\{\{athlete_name\}\}/g, '<span style="display:inline-block;background:rgba(99,179,237,.15);border:1px solid rgba(99,179,237,.4);color:#7ec8e3;border-radius:5px;padding:.02em .38em;font-size:.85em;font-weight:700">Όνομα αθλητή</span>')
            .replace(/\{\{valid_until\}\}/g,  '<span style="display:inline-block;background:rgba(45,198,83,.12);border:1px solid rgba(45,198,83,.35);color:#2dc653;border-radius:5px;padding:.02em .38em;font-size:.85em;font-weight:700">Ημ. λήξης</span>')
            .replace(/\{\{amount\}\}/g,       '<span style="display:inline-block;background:rgba(240,165,0,.12);border:1px solid rgba(240,165,0,.35);color:#f0a500;border-radius:5px;padding:.02em .38em;font-size:.85em;font-weight:700">Ποσό</span>')
            .replace(/\{\{school_name\}\}/g,  '<span style="display:inline-block;background:rgba(139,92,246,.12);border:1px solid rgba(139,92,246,.35);color:#a78bfa;border-radius:5px;padding:.02em .38em;font-size:.85em;font-weight:700">Σύλλογος</span>');
    }

    window.openRuleDetail = function(r) {
        var isOn  = parseInt(r.active) === 1;
        var ttype = r.trigger_type || 'days_before';
        var tdays = parseInt(r.trigger_days) || 0;
        var chs   = (r.channels || 'email').split(',').map(function(s){ return s.trim(); });

        var icon = document.getElementById('rd_icon');
        icon.className = 'fa-solid ' + (TRIGGER_ICON[ttype] || 'fa-bell');
        icon.style.color = isOn ? 'var(--green,#2dc653)' : 'var(--muted,#8892b0)';
        document.getElementById('rd_title').textContent = r.rule_name || 'Υπενθύμιση';

        var banner = document.getElementById('rd_status_banner');
        var sIcon  = document.getElementById('rd_status_icon');
        var sText  = document.getElementById('rd_status_text');
        var sSub   = document.getElementById('rd_status_sub');
        if (isOn) {
            banner.style.background    = 'rgba(45,198,83,.07)';
            banner.style.borderColor   = 'rgba(45,198,83,.28)';
            sIcon.className = 'fa-solid fa-circle-check';
            sIcon.style.color = '#2dc653';
            sText.style.color = '#2dc653';
            sText.textContent = 'Ενεργή υπενθύμιση';
            sSub.textContent  = 'Στέλνεται αυτόματα όταν πληρούνται οι προϋποθέσεις.';
        } else {
            banner.style.background    = 'rgba(255,255,255,.04)';
            banner.style.borderColor   = 'rgba(255,255,255,.1)';
            sIcon.className = 'fa-solid fa-circle-pause';
            sIcon.style.color = 'var(--muted,#8892b0)';
            sText.style.color = 'var(--muted,#8892b0)';
            sText.textContent = 'Ανενεργή — δεν αποστέλλεται';
            sSub.textContent  = 'Ενεργοποιήστε την για αυτόματη αποστολή.';
        }

        var pillHtml = '<span class="pill ' + (TRIGGER_PILL[ttype]||'pill-before') + '">' +
            '<i class="fa-solid ' + (TRIGGER_ICON[ttype]||'fa-bell') + '"></i> ' +
            (TRIGGER_LABELS[ttype] ? TRIGGER_LABELS[ttype](tdays) : ttype) +
        '</span>';
        if (chs.indexOf('email') >= 0) pillHtml += '<span class="pill pill-ch"><i class="fa-solid fa-envelope"></i> Email</span>';
        if (chs.indexOf('sms') >= 0 && SCHOOL_HAS_SMS) pillHtml += '<span class="pill pill-ch"><i class="fa-solid fa-mobile-screen"></i> SMS</span>';
        document.getElementById('rd_pills').innerHTML = pillHtml;

        var subjWrap = document.getElementById('rd_subj_wrap');
        var subjEl   = document.getElementById('rd_subject');
        if (r.subject_tpl) {
            subjWrap.style.display = '';
            subjEl.innerHTML = renderToken(esc(r.subject_tpl));
        } else {
            subjWrap.style.display = 'none';
        }

        var bodyEl = document.getElementById('rd_body');
        if (r.body_tpl) {
            bodyEl.innerHTML = renderToken(esc(r.body_tpl));
        } else {
            bodyEl.textContent = '(Χωρίς κείμενο)';
        }

        /* Edit button */
        document.getElementById('rd_edit_btn').onclick = function() {
            closeRuleDetail();
            setTimeout(function(){ window.openEditModal(r); }, 140);
        };

        var modal = document.getElementById('ruleDetailModal');
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        var box = modal.querySelector('.modal-box');
        if (box) box.scrollTop = 0;
    };

    window.closeRuleDetail = function() {
        document.getElementById('ruleDetailModal').classList.remove('open');
        document.body.style.overflow = '';
    };

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeRuleDetail();
    });
})();

/* ════════════════════════════════════════════════════════════
   ★★★  HISTORY PAGINATION + LIVE SEARCH  ★★★
   10 items per page. Mobile: blurs input on Enter (hides keyboard)
   but search remains live (fires on input event).
══════════════════════════════════════════════════════════ */
(function(){
    var PER_PAGE = 10;

    /* ── Reminder logs ── */
    (function(){
        var rows       = Array.from(document.querySelectorAll('.hist-row'));
        var pager      = document.getElementById('histPager');
        var infoEl     = document.getElementById('histInfo');
        var emptyEl    = document.getElementById('histEmpty');
        var tbody      = document.getElementById('histTbody');
        if (!rows.length || !pager) return;

        var filtered = rows.slice();
        var page = 1;

        function totalPages(){ return Math.max(1, Math.ceil(filtered.length / PER_PAGE)); }

        function render() {
            var tp = totalPages();
            var start = (page - 1) * PER_PAGE;
            var end   = start + PER_PAGE;

            rows.forEach(function(r){ r.style.display = 'none'; });
            filtered.slice(start, end).forEach(function(r){ r.style.display = ''; });

            if (emptyEl) emptyEl.style.display = filtered.length === 0 ? '' : 'none';
            if (tbody) tbody.closest('.history-wrap').style.display = filtered.length === 0 ? 'none' : '';

            /* Info */
            if (infoEl) {
                if (filtered.length > 0) {
                    infoEl.textContent = (start+1) + '–' + Math.min(end, filtered.length) + ' / ' + filtered.length;
                } else {
                    infoEl.textContent = '0 αποτελέσματα';
                }
            }

            /* Pager buttons */
            pager.innerHTML = '';
            if (tp <= 1) return;

            function mkBtn(label, targetPage, disabled, active) {
                var btn = document.createElement('button');
                btn.className = 'pager-btn' + (active ? ' active' : '');
                btn.textContent = label;
                btn.disabled = disabled;
                btn.onclick = function(){ page = targetPage; render(); };
                return btn;
            }

            pager.appendChild(mkBtn('‹', page-1, page===1, false));

            for (var i=1; i<=tp; i++) {
                if (tp > 7 && Math.abs(i - page) > 2 && i !== 1 && i !== tp) {
                    if (i === 2 || i === tp-1) {
                        var sp = document.createElement('span');
                        sp.className = 'pager-info';
                        sp.textContent = '…';
                        pager.appendChild(sp);
                    }
                    continue;
                }
                pager.appendChild(mkBtn(i, i, false, i===page));
            }
            pager.appendChild(mkBtn('›', page+1, page===tp, false));
        }

        window.histFilter = function(q) {
            q = q.trim().toLowerCase();
            page = 1;
            if (!q) {
                filtered = rows.slice();
            } else {
                filtered = rows.filter(function(r){
                    return (r.getAttribute('data-name')||'').includes(q) ||
                           (r.getAttribute('data-recipient')||'').includes(q);
                });
            }
            render();
        };

        render();
    })();

    /* ── Broadcast messages ── */
    (function(){
        var rows   = Array.from(document.querySelectorAll('.bc-hist-row'));
        var pager  = document.getElementById('bcHistPager');
        var infoEl = document.getElementById('bcHistInfo');
        var emptyEl= document.getElementById('bcHistEmpty');
        var tbody  = document.getElementById('bcHistTbody');
        if (!rows.length || !pager) return;

        var filtered = rows.slice();
        var page = 1;

        function totalPages(){ return Math.max(1, Math.ceil(filtered.length / PER_PAGE)); }

        function render() {
            var tp = totalPages();
            var start = (page - 1) * PER_PAGE;
            var end   = start + PER_PAGE;

            rows.forEach(function(r){ r.style.display = 'none'; });
            filtered.slice(start, end).forEach(function(r){ r.style.display = ''; });

            if (emptyEl) emptyEl.style.display = filtered.length === 0 ? '' : 'none';
            if (tbody) tbody.closest('.history-wrap').style.display = filtered.length === 0 ? 'none' : '';

            if (infoEl) {
                if (filtered.length > 0) {
                    infoEl.textContent = (start+1) + '–' + Math.min(end, filtered.length) + ' / ' + filtered.length;
                } else {
                    infoEl.textContent = '0 αποτελέσματα';
                }
            }

            pager.innerHTML = '';
            if (tp <= 1) return;

            function mkBtn(label, targetPage, disabled, active) {
                var btn = document.createElement('button');
                btn.className = 'pager-btn' + (active ? ' active' : '');
                btn.textContent = label;
                btn.disabled = disabled;
                btn.onclick = function(){ page = targetPage; render(); };
                return btn;
            }

            pager.appendChild(mkBtn('‹', page-1, page===1, false));
            for (var i=1; i<=tp; i++) {
                if (tp > 7 && Math.abs(i - page) > 2 && i !== 1 && i !== tp) {
                    if (i === 2 || i === tp-1) {
                        var sp = document.createElement('span');
                        sp.className = 'pager-info';
                        sp.textContent = '…';
                        pager.appendChild(sp);
                    }
                    continue;
                }
                pager.appendChild(mkBtn(i, i, false, i===page));
            }
            pager.appendChild(mkBtn('›', page+1, page===tp, false));
        }

        window.bcHistFilter = function(q) {
            q = q.trim().toLowerCase();
            page = 1;
            if (!q) {
                filtered = rows.slice();
            } else {
                filtered = rows.filter(function(r){
                    return (r.getAttribute('data-subject')||'').includes(q);
                });
            }
            render();
        };

        render();
    })();
})();
</script>
</body>
</html>