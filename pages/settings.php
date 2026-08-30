<?php

/**
 * ============================================================
 * pages/settings.php — Ρυθμίσεις Σχολής & Χρήστη
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/two_factor.php';
require_once __DIR__ . '/../includes/billing_pauses.php';
requireLogin();
renderPaymentWall();

$db = getDB();
$sid = schoolId();

function usersUsernameColumn(PDO $db): string {
    static $col = null;
    if ($col !== null) return $col;

    try {
        $st = $db->query("SHOW COLUMNS FROM users");
        $cols = $st ? $st->fetchAll(PDO::FETCH_COLUMN, 0) : [];
        if (in_array('username', $cols, true)) {
            $col = 'username';
        } elseif (in_array('name', $cols, true)) {
            $col = 'name';
        } else {
            $col = 'name';
        }
    } catch (Exception $e) {
        $col = 'name';
    }

    return $col;
}

function currentUsernameValue(array $user, string $col): string {
    if ($col === 'username') {
        return (string)($user['username'] ?? $user['name'] ?? '');
    }
    return (string)($user['name'] ?? $user['username'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST;
    verifyCsrf();

    if (($a['_action'] ?? '') === 'save_school') {
        $f = ['name','address','city','phone','email'];
        $data = array_map(fn($k)=>trim($a[$k]??'')?:null, $f);

        $db->prepare("UPDATE schools SET ".implode(',',array_map(fn($k)=>"$k=?",$f))." WHERE id=?")
           ->execute([...$data,$sid]);

        $uid = userId();
        $usernameCol = usersUsernameColumn($db);
        $newUsername = trim($a['username'] ?? '');

        if ($newUsername !== '') {
            $existingStmt = $db->prepare("SELECT id FROM users WHERE {$usernameCol}=? AND id != ?");
            $existingStmt->execute([$newUsername, $uid]);

            if ($existingStmt->fetchColumn()) {
                flash('Το username χρησιμοποιείται ήδη.', 'danger');
            } else {
                $db->prepare("UPDATE users SET {$usernameCol}=? WHERE id=?")->execute([$newUsername, $uid]);

                if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
                    $_SESSION['user'] = [];
                }
                $_SESSION['user'][$usernameCol] = $newUsername;
                $_SESSION['user']['name'] = $newUsername;
                $_SESSION['user']['username'] = $newUsername;

                flash('Τα στοιχεία ενημερώθηκαν επιτυχώς!');
            }
        } else {
            flash('Τα στοιχεία σχολής ενημερώθηκαν!');
        }
    }

    if (($a['_action'] ?? '') === 'save_password') {
        $uid = userId();
        $stPw = $db->prepare("SELECT password FROM users WHERE id=? LIMIT 1"); $stPw->execute([$uid]); $current = $stPw->fetchColumn();

        if (!password_verify($a['current_password'] ?? '', $current)) {
            flash('Λάθος τρέχων κωδικός.', 'danger');
        } elseif (strlen($a['new_password'] ?? '') < 8) {
            flash('Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.', 'danger');
        } elseif (($a['new_password'] ?? '') !== ($a['confirm_password'] ?? '')) {
            flash('Οι κωδικοί δεν ταιριάζουν.', 'danger');
        } else {
            $db->prepare("UPDATE users SET password=? WHERE id=?")
               ->execute([password_hash($a['new_password'], PASSWORD_DEFAULT), $uid]);
            flash('Κωδικός ενημερώθηκε!');
        }
    }

    // ── DELETE ACCOUNT REQUEST ──
    if (($a['_action'] ?? '') === 'request_delete_account') {
        $uid = userId();
        $stPw2 = $db->prepare("SELECT password FROM users WHERE id=? LIMIT 1"); $stPw2->execute([$uid]); $current = $stPw2->fetchColumn();

        if (!password_verify($a['confirm_password_delete'] ?? '', $current)) {
            flash('Λάθος κωδικός επαλήθευσης. Το αίτημα δεν εστάλη.', 'danger');
            redirect(APP_URL.'/pages/settings.php?tab=school');
        }

        $u      = currentUser();
        $stSc2 = $db->prepare("SELECT * FROM schools WHERE id=? LIMIT 1"); $stSc2->execute([$sid]); $school = $stSc2->fetch();

        $adminEmail = 'pkotsorgios654@gmail.com';

        $subject  = '=?UTF-8?B?'.base64_encode('Αίτημα Διαγραφής Λογαριασμού - MAster').'?=';
        $htmlBody = '<!DOCTYPE html>
<html lang="el">
<head><meta charset="UTF-8"><title>Αίτημα Διαγραφής</title></head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
    <tr>
      <td style="text-align:center">
        <table width="100%" cellpadding="0" cellspacing="0"
               style="max-width:560px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
            <tr>
            <td style="background:linear-gradient(135deg,#1a0505,#2a1010);padding:28px 32px 24px;text-align:center;border-bottom:2px solid #5a1a1a">
              <h1 style="margin:0 0 4px;font-size:1.2rem;color:#ff6b6b;font-weight:800">⚠️ Αίτημα Διαγραφής Λογαριασμού</h1>
              <p style="margin:0;font-size:.82rem;color:#8892b0">MAster Platform</p>
             </td>
            </tr>
            <tr>
            <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.85">
              <p>Ένας χρήστης υπέβαλε αίτημα <strong style="color:#ff6b6b">διαγραφής λογαριασμού</strong>.</p>
              <table style="width:100%;border-collapse:collapse;margin:1rem 0;font-size:.9rem">
                <tr style="border-bottom:1px solid #1e2536">
                  <td style="padding:.55rem 0;color:#8892b0;font-weight:700;width:35%">Σχολή</td>
                  <td style="padding:.55rem 0;color:#fff;font-weight:700">'.htmlspecialchars($school['name'] ?? '—', ENT_QUOTES, 'UTF-8').'</td>
                </tr>
                <tr style="border-bottom:1px solid #1e2536">
                  <td style="padding:.55rem 0;color:#8892b0;font-weight:700">Email Χρήστη</td>
                  <td style="padding:.55rem 0;color:#fff">'.htmlspecialchars($u['email'] ?? '—', ENT_QUOTES, 'UTF-8').'</td>
                </tr>
                <tr style="border-bottom:1px solid #1e2536">
                  <td style="padding:.55rem 0;color:#8892b0;font-weight:700">Όνομα Χρήστη</td>
                  <td style="padding:.55rem 0;color:#fff">'.htmlspecialchars($u['name'] ?? $u['username'] ?? '—', ENT_QUOTES, 'UTF-8').'</td>
                </tr>
                <tr style="border-bottom:1px solid #1e2536">
                  <td style="padding:.55rem 0;color:#8892b0;font-weight:700">School ID</td>
                  <td style="padding:.55rem 0;color:#fff">'.htmlspecialchars((string)$sid, ENT_QUOTES, 'UTF-8').'</td>
                </tr>
                <tr style="border-bottom:1px solid #1e2536">
                  <td style="padding:.55rem 0;color:#8892b0;font-weight:700">User ID</td>
                  <td style="padding:.55rem 0;color:#fff">'.htmlspecialchars((string)$uid, ENT_QUOTES, 'UTF-8').'</td>
                </tr>
                <tr>
                  <td style="padding:.55rem 0;color:#8892b0;font-weight:700">Ημερομηνία</td>
                  <td style="padding:.55rem 0;color:#fff">'.date('d/m/Y H:i:s').'</td>
                </tr>
              </table>
              <p style="background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);border-radius:10px;padding:.85rem 1rem;color:#fca5a5;font-size:.88rem;margin:1rem 0 0">
                <strong>Ενέργεια απαιτείται:</strong> Επικοινωνήστε με τον χρήστη και προχωρήστε στη διαγραφή εφόσον επιβεβαιωθεί το αίτημα.
              </p>
             </td>
            </tr>
            <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
            <tr>
            <td style="padding:20px 32px;text-align:center;font-size:.74rem;color:#4a5270">
              &copy; '.date('Y').' MAster &nbsp;&middot;&nbsp; Αυτόματο σύστημα ειδοποιήσεων
             </td>
            </tr>
         </table>
       </td>
    </tr>
</table>
</body>
</html>';

        $textBody = "Αίτημα Διαγραφής Λογαριασμού - MAster\n\n"
                  . "Σχολή: " . ($school['name'] ?? '—') . "\n"
                  . "Email Χρήστη: " . ($u['email'] ?? '—') . "\n"
                  . "Όνομα Χρήστη: " . ($u['name'] ?? $u['username'] ?? '—') . "\n"
                  . "School ID: " . $sid . "\n"
                  . "User ID: " . $uid . "\n"
                  . "Ημερομηνία: " . date('d/m/Y H:i:s') . "\n\n"
                  . "Απαιτείται χειροκίνητη διαγραφή από τον διαχειριστή.";

        require_once __DIR__ . '/../includes/mailer.php';

        $sent = sendEmail(
            $adminEmail,
            'Αίτημα Διαγραφής Λογαριασμού - MAster',
            $htmlBody,
            $textBody,
            'MAster Admin'
        );

        if ($sent) {
            flash('Το αίτημα διαγραφής εστάλη. Θα επικοινωνήσουμε μαζί σας σύντομα.');
        } else {
            $mailSubject = '=?UTF-8?B?'.base64_encode('Αίτημα Διαγραφής Λογαριασμού - MAster').'?=';
            @mail(
                $adminEmail,
                $mailSubject,
                $textBody,
                "From: noreply@master-app.gr\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            );
            flash('Το αίτημα διαγραφής καταχωρήθηκε. Θα επικοινωνήσουμε μαζί σας σύντομα.');
        }

        redirect(APP_URL.'/pages/settings.php?tab=school');
    }

    if (($a['_action'] ?? '') === 'activate_sms_addon') {
        $plan = schoolPlan();
        if (($plan['slug'] ?? '') === 'basic') {
            try {
                $db->prepare("UPDATE schools SET sms_addon=1, sms_addon_expires=DATE_ADD(CURDATE(), INTERVAL 1 MONTH) WHERE id=?")->execute([$sid]);
                flash('SMS Πακέτο ενεργοποιήθηκε! Ισχύει έως: '.date('d/m/Y', strtotime('+1 month')));
            } catch (Exception $e) {
                flash('Σφάλμα ενεργοποίησης. Παρακαλώ ελέγξτε τη βάση δεδομένων (migration).', 'danger');
            }
        } else {
            flash('Το SMS πακέτο είναι διαθέσιμο μόνο για το Basic Plan.', 'danger');
        }
    }

    if (($a['_action'] ?? '') === 'deactivate_sms_addon') {
        try {
            $db->prepare("UPDATE schools SET sms_addon=0, sms_addon_expires=NULL WHERE id=?")->execute([$sid]);
            flash('SMS Πακέτο απενεργοποιήθηκε.');
        } catch (Exception $e) {
            flash('Σφάλμα.', 'danger');
        }
    }

    if (($a['_action'] ?? '') === 'cancel_subscription') {
        try {
            $db->prepare("UPDATE schools SET subscription_status='cancelled' WHERE id=?")->execute([$sid]);
            flash('Η συνδρομή σας ακυρώθηκε. Παραμένει ενεργή έως τη λήξη της τρέχουσας περιόδου.');
        } catch (Exception $e) {
            flash('Σφάλμα ακύρωσης.', 'danger');
        }
        redirect(APP_URL.'/pages/settings.php?tab=subscription');
    }

    if (($a['_action'] ?? '') === 'toggle_autorenew') {
        try {
            $newVal = ($a['autorenew'] ?? '0') === '1' ? 1 : 0;
            $db->prepare("UPDATE schools SET auto_renew=? WHERE id=?")->execute([$newVal, $sid]);
            flash($newVal ? 'Η αυτόματη ανανέωση ενεργοποιήθηκε.' : 'Η αυτόματη ανανέωση απενεργοποιήθηκε.');
        } catch (Exception $e) {
            $_SESSION['autorenew_'.$sid] = ($a['autorenew'] ?? '0') === '1' ? 1 : 0;
            flash(($a['autorenew'] ?? '0') === '1' ? 'Η αυτόματη ανανέωση ενεργοποιήθηκε.' : 'Η αυτόματη ανανέωση απενεργοποιήθηκε.');
        }
        redirect(APP_URL.'/pages/settings.php?tab=subscription');
    }

    if (($a['_action'] ?? '') === 'renew_subscription') {
        try {
            $u  = currentUser();
            $stSc3 = $db->prepare("SELECT * FROM schools WHERE id=? LIMIT 1"); $stSc3->execute([$sid]); $sc = $stSc3->fetch();
            $cycle = ($a['billing_cycle'] ?? 'monthly') === 'annual' ? 'Ετήσια' : 'Μηνιαία';
            $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'pkotsorgios654@gmail.com';
            $subject = '=?UTF-8?B?'.base64_encode('Αίτημα Ανανέωσης Συνδρομής - MAster').'?=';
            $body    = "Νέο αίτημα ανανέωσης συνδρομής:\n\n"
                     . "Σχολή: " . ($sc['name'] ?? '') . "\n"
                     . "Email: " . ($u['email'] ?? '') . "\n"
                     . "Κύκλος: " . $cycle . "\n"
                     . "Ημερομηνία: " . date('d/m/Y H:i') . "\n";
            @mail($adminEmail, $subject, $body, "From: noreply@master.gr\r\nContent-Type: text/plain; charset=UTF-8\r\n");
            flash('Το αίτημα ανανέωσης εστάλη! Θα επικοινωνήσουμε μαζί σας σύντομα.');
        } catch (Exception $e) {
            flash('Το αίτημα καταγράφηκε. Επικοινωνήστε μαζί μας για να ολοκληρώσετε την ανανέωση.');
        }
        redirect(APP_URL.'/pages/settings.php?tab=subscription');
    }

    if (($a['_action'] ?? '') === '2fa_generate') {
        $_SESSION['pending_2fa_secret'] = 'email_otp_pending';
        redirect(APP_URL.'/pages/settings.php?tab=security&setup2fa=1');
    }

    if (($a['_action'] ?? '') === '2fa_enable') {
        $uid2 = (int)userId();
        $db->prepare("UPDATE users SET totp_enabled=1 WHERE id=?")->execute([$uid2]);
        unset($_SESSION['pending_2fa_secret']);
        require_once __DIR__ . '/../includes/mailer.php';
        $stU2a = $db->prepare("SELECT email, name FROM users WHERE id=? LIMIT 1"); $stU2a->execute([$uid2]); $u2 = $stU2a->fetch();
        send2FAActivationNotice($u2['email'], $u2['name'] ?? '');
        flash('2FA ενεργοποιήθηκε!');
        redirect(APP_URL.'/pages/settings.php?tab=security');
    }

    if (($a['_action'] ?? '') === '2fa_disable') {
        $uid = userId();
        $stPw3 = $db->prepare("SELECT password FROM users WHERE id=? LIMIT 1"); $stPw3->execute([$uid]); $current = $stPw3->fetchColumn();
        if (password_verify($a['confirm_password'] ?? '', $current)) {
            $db->prepare("UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_backup_codes=NULL WHERE id=?")->execute([$uid]);
            require_once __DIR__ . '/../includes/two_factor.php';
            $stU2b = $db->prepare("SELECT email, name FROM users WHERE id=? LIMIT 1"); $stU2b->execute([$uid]); $u2 = $stU2b->fetch();
            send2FADeactivationNotice($u2['email'], $u2['name'] ?? '');
            flash('2FA απενεργοποιήθηκε. Στάλθηκε ενημερωτικό email.');
        } else {
            flash('Λάθος κωδικός επαλήθευσης.', 'danger');
        }
        redirect(APP_URL.'/pages/settings.php?tab=security');
    }

    // ── PRIVACY MODE (only for pro users) ──
    if (($a['_action'] ?? '') === 'toggle_privacy_mode') {
        $currentPlan = schoolPlan();
        $currentPlanSlug = strtolower(trim((string)($currentPlan['slug'] ?? 'basic')));

        if ($currentPlanSlug !== 'pro') {
            flash('Η απόκρυψη οικονομικών είναι διαθέσιμη μόνο στο Pro πλάνο.', 'danger');
            redirect(APP_URL.'/pages/settings.php?tab=subscription');
        }

        $key = 'privacy_mode_' . $sid;
        $_SESSION[$key] = (($a['privacy_mode'] ?? '0') === '1');
        redirect(APP_URL.'/pages/settings.php?tab=privacy');
    }

    if (($a['_action'] ?? '') === 'save_payment_details') {
        $fields = ['payment_iban', 'payment_iris', 'payment_afm', 'payment_beneficiary', 'payment_bank', 'payment_notes'];
        $stmt = $db->prepare("INSERT INTO school_meta (school_id,meta_key,meta_val) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_val=VALUES(meta_val)");
        foreach ($fields as $fk) {
            $val = trim($a[$fk] ?? '');
            $stmt->execute([$sid, $fk, $val]);
        }
        flash('Στοιχεία πληρωμής ενημερώθηκαν!');
        redirect(APP_URL.'/pages/settings.php?tab=school');
    }

    if (($a['_action'] ?? '') === 'save_school_billing_pauses') {
        $pauseMonths = normaliseBillingPauseMonths((array)($a['school_billing_pause_months'] ?? []));
        try {
            ensureBillingPauseSchema($db);
            $db->beginTransaction();
            replaceSchoolBillingPauseMonths($db, $sid, $pauseMonths);
            auditLog('school_billing_pause_updated', 'school', $sid, json_encode(['months' => $pauseMonths], JSON_UNESCAPED_UNICODE));
            $db->commit();
            flash($pauseMonths
                ? 'Οι μήνες χωρίς χρέωση αποθηκεύτηκαν. Ο κανόνας θα εφαρμόζεται κάθε χρόνο.'
                : 'Ο γενικός κανόνας μηνών χωρίς χρέωση απενεργοποιήθηκε.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[MAster settings] billing pause save failed: ' . $e->getMessage());
            flash('Δεν ήταν δυνατή η αποθήκευση των μηνών διακοπής. Δοκιμάστε ξανά μετά την ανανέωση της εφαρμογής.', 'danger');
        }
        redirect(APP_URL.'/pages/settings.php?tab=school');
    }

    if (($a['_action'] ?? '') === 'toggle_summer_pause') {
        $val = (($a['summer_pause_opted_in'] ?? '0') === '1') ? '1' : '0';
        $db->prepare("INSERT INTO school_meta (school_id,meta_key,meta_val) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_val=VALUES(meta_val)")
           ->execute([$sid, 'summer_pause_opted_in', $val]);
        flash($val === '1' ? 'Η θερινή παύση ενεργοποιήθηκε για τη σχολή σας.' : 'Η θερινή παύση απενεργοποιήθηκε.');
        redirect(APP_URL.'/pages/settings.php?tab=school');
    }

    // ── MANUAL OPT-OUT (handled inline in tab, skip redirect) ──
    if (($a['_action'] ?? '') === 'manual_opt_out') {
        // Intentionally fall through — processed in the data-prep section below.
    } else {
        redirect(APP_URL.'/pages/settings.php');
    }
}

$stScMain = $db->prepare("SELECT * FROM schools WHERE id=? LIMIT 1"); $stScMain->execute([$sid]); $school = $stScMain->fetch();
$user   = currentUser();
$uid    = userId();

try {
    $stUf = $db->prepare("SELECT totp_enabled, totp_secret, totp_backup_codes FROM users WHERE id=? LIMIT 1"); $stUf->execute([$uid]); $userFull = $stUf->fetch();
} catch(Exception $e) {
    $userFull = [];
}
$twoFAEnabled     = (bool)($userFull['totp_enabled'] ?? false);
$pending2FASecret = $_SESSION['pending_2fa_secret'] ?? null;
$backupCodes      = $_SESSION['2fa_backup_codes'] ?? null;
if ($backupCodes) unset($_SESSION['2fa_backup_codes']);

$plan            = schoolPlan();
$planSlug        = strtolower(trim((string)($plan['slug'] ?? 'basic')));
$isProPlan       = ($planSlug === 'pro');
$subStatus       = $school['subscription_status'] ?? 'trial';
$planExpires     = $school['plan_expires'] ?? '';
$trialEnds       = $school['trial_ends'] ?? '';
$billingCycle    = $school['billing_cycle'] ?? 'monthly';
$nextBillingDate = $school['next_billing_date'] ?? '';
$autoRenew = isset($school['auto_renew']) ? (bool)$school['auto_renew'] : (bool)($_SESSION['autorenew_'.$sid] ?? true);
$expiryDate = $planExpires ?: $trialEnds;
$daysLeft = $expiryDate ? max(0, (int)ceil((strtotime($expiryDate) - time()) / 86400)) : null;

$smsAddonActive  = schoolHasSmsAddon();
$smsAddonExpires = '';
try {
    $smsRow = $db->prepare("SELECT sms_addon_expires FROM schools WHERE id=?");
    $smsRow->execute([$sid]);
    $smsAddonExpires = $smsRow->fetchColumn() ?? '';
} catch (Exception $e) {}

// Summer pause: superadmin config + per-school opt-in
$summerCfg = [];
try {
    $summerKeys = ['summer_pause_enabled','summer_pause_month','summer_pause_end_month','summer_pause_message','summer_pause_popup_days','summer_pause_reopening_message'];
    $placeholders = implode(',', array_fill(0, count($summerKeys), '?'));
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($summerKeys);
    foreach ($stmt->fetchAll() as $r) $summerCfg[$r['setting_key']] = $r['setting_value'];
} catch (Exception $e) {}
$summerPauseGlobalEnabled = ($summerCfg['summer_pause_enabled'] ?? '0') === '1';

$schoolSummerOptIn = false;
try {
    $smStmt = $db->prepare("SELECT meta_val FROM school_meta WHERE school_id=? AND meta_key='summer_pause_opted_in'");
    $smStmt->execute([$sid]);
    $schoolSummerOptIn = ($smStmt->fetchColumn() === '1');
} catch (Exception $e) {}

$billingPauseContext = loadBillingPauseContext($db, $sid);
$billingMonthLabels = billingMonthLabels();
$schoolBillingPauseMonths = array_map('intval', array_keys($billingPauseContext['school'] ?? []));

$planLabel  = $planSlug === 'pro' ? 'Pro' : 'Basic';
$cycleLabel = $billingCycle === 'annual' ? 'Ετήσια' : 'Μηνιαία';
$usernameCol = usersUsernameColumn($db);
$currentUsername = currentUsernameValue($user, $usernameCol);

$privacyMode = (bool)($_SESSION['privacy_mode_' . $sid] ?? false);

// ── Opt-out tab: auto-create consent_log if missing ─────────────────────────
$optOutMigrationNeeded = false;
try {
    $db->query("SELECT 1 FROM `consent_log` LIMIT 1");
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), '1146')) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `consent_log` (
              `id`             int(11)      NOT NULL AUTO_INCREMENT,
              `parent_user_id` int(11)      DEFAULT NULL,
              `event_type`     varchar(100) NOT NULL,
              `ip_hash`        varchar(64)  DEFAULT NULL,
              `terms_version`  varchar(20)  NOT NULL DEFAULT '1.0',
              `created_at`     timestamp    NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_parent_user_id` (`parent_user_id`),
              KEY `idx_event_type`     (`event_type`),
              KEY `idx_created_at`     (`created_at`),
              CONSTRAINT `fk_consent_log_parent`
                FOREIGN KEY (`parent_user_id`)
                REFERENCES `parent_users` (`id`)
                ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $optOutMigrationNeeded = true;
    }
}

// ── Opt-out tab: handle POST ─────────────────────────────────────────────────
$optOutSuccess = '';
$optOutError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'manual_opt_out') {
    // CSRF already verified above in the main POST block — but we redirected
    // for all other actions, so if we get here the CSRF was valid.
    $optEmail   = strtolower(trim($_POST['opt_email'] ?? ''));
    $optChannel = $_POST['opt_channel'] ?? 'email';
    $optReason  = $_POST['opt_reason'] ?? 'manual_admin';

    if (!$optEmail || !filter_var($optEmail, FILTER_VALIDATE_EMAIL)) {
        $optOutError = 'Εισάγετε έγκυρο email.';
    } else {
        $optCol  = ($optChannel === 'sms') ? 'sms_opt_out' : 'email_opt_out';
        $optStmt = $db->prepare("UPDATE parent_users SET {$optCol} = 1 WHERE parent_email = ?");
        $optStmt->execute([$optEmail]);
        $optAffected = $optStmt->rowCount();

        if ($optAffected > 0) {
            $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0') . date('Y-m-d'));
            $db->prepare("INSERT INTO consent_log (parent_user_id, event_type, ip_hash, terms_version)
                          SELECT id, ?, ?, '1.0' FROM parent_users WHERE parent_email = ? LIMIT 1")
               ->execute([$optCol . '_' . $optReason, $ipHash, $optEmail]);
            $optOutSuccess = "Opt-out ({$optChannel}) καταχωρήθηκε για {$optEmail}. Επηρεάστηκαν {$optAffected} εγγραφές.";
        } else {
            $optOutError = "Δεν βρέθηκε γονέας με email: {$optEmail}";
        }
    }
}

// ── Opt-out tab: fetch recent log ────────────────────────────────────────────
$optOutLog = [];
try {
    $optOutLog = $db->query("
        SELECT cl.event_type, cl.created_at, pu.parent_email
        FROM consent_log cl
        LEFT JOIN parent_users pu ON pu.id = cl.parent_user_id
        WHERE cl.event_type LIKE '%opt_out%'
        ORDER BY cl.created_at DESC
        LIMIT 50
    ")->fetchAll();
} catch (Exception $e) {}

// ── Payment details (IBAN / IRIS) ────────────────────────────────────────────
$paymentMeta = [];
try {
    $pmKeys = ['payment_iban','payment_iris','payment_afm','payment_beneficiary','payment_bank','payment_notes'];
    $pmPh   = implode(',', array_fill(0, count($pmKeys), '?'));
    $pmStmt = $db->prepare("SELECT meta_key, meta_val FROM school_meta WHERE school_id=? AND meta_key IN ($pmPh)");
    $pmStmt->execute([$sid, ...$pmKeys]);
    foreach ($pmStmt->fetchAll() as $r) $paymentMeta[$r['meta_key']] = $r['meta_val'];
} catch (Exception $e) {}

renderHead('Ρυθμίσεις');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
.topbar { position: relative !important; top: auto !important; z-index: auto !important; }
.main-content > div[style*="border-bottom"] { position: relative !important; top: auto !important; }

@media (max-width: 900px) {
    #menuBtn { display: inline-flex !important; min-width: 44px !important; min-height: 44px !important; align-items: center !important; justify-content: center !important; font-size: 1.2rem !important; cursor: pointer !important; }
    .sidebar { position: fixed !important; top: 0 !important; left: 0 !important; bottom: 0 !important; width: min(280px,80vw) !important; z-index: 9999 !important; transform: translateX(-110%) !important; transition: transform .28s cubic-bezier(.2,.8,.2,1) !important; overflow-y: auto; -webkit-overflow-scrolling: touch; }
    .sidebar.open { transform: translateX(0) !important; box-shadow: 6px 0 40px rgba(0,0,0,.6) !important; }
    .main-content { margin-left: 0 !important; width: 100% !important; }
}
#dm-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9998; cursor: pointer; }
#dm-overlay.on { display: block; }

@keyframes fadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
.page-body { animation: fadeIn .35s ease both; }
.anim-1 { opacity:0; animation: fadeUp .42s ease-out .05s both; }
.anim-2 { opacity:0; animation: fadeUp .42s ease-out .12s both; }
@media (prefers-reduced-motion: reduce) { .page-body,.anim-1,.anim-2 { animation:none!important; opacity:1; } }

@keyframes copyPop {
    0%   { transform: scale(1); }
    40%  { transform: scale(1.12); }
    100% { transform: scale(1); }
}
.copy-btn-success {
    animation: copyPop .25s ease both;
    background: rgba(45,198,83,.18) !important;
    border-color: rgba(45,198,83,.5) !important;
    color: #2dc653 !important;
}

.page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem; margin-bottom: 1.2rem; }
.page-header h2 { font-size: clamp(1.15rem,4vw,1.5rem) !important; font-weight: 800; display: flex; align-items: center; gap: .5rem; margin: 0; }

.tab-nav { display: flex; gap: .5rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
.tab-btn {
    display: inline-flex; align-items: center; gap: .55rem;
    min-height: 52px; padding: .65rem 1.3rem;
    border-radius: 14px; border: 2px solid var(--border,#1e2536);
    background: transparent; cursor: pointer;
    font-size: clamp(.95rem,3.5vw,1.05rem) !important;
    font-weight: 700; color: var(--muted,#8892b0);
    transition: all .18s; white-space: nowrap;
}
.tab-btn i { font-size: 1.1rem; }
.tab-btn:hover { border-color: var(--red,#e63946); color: var(--text,#e2e8f0); background: rgba(230,57,70,.06); }
.tab-btn.active { border-color: var(--red,#e63946); color: var(--text,#e2e8f0); background: rgba(230,57,70,.10); }

@media (max-width: 480px) {
    .page-body { padding: .65rem !important; }
    .card { border-radius: 14px; }

    .tab-nav {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .6rem;
    }

    .tab-btn {
        width: 100%;
        min-height: 56px;
        padding: .7rem .6rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        text-align: center;
        white-space: normal;
        font-size: .88rem !important;
        line-height: 1.3;
    }

    .tab-btn span.tab-label {
        display: inline !important;
    }

    .tab-btn i {
        font-size: 1rem;
        flex-shrink: 0;
    }

    .extra-row { flex-direction: column; align-items: flex-start; }
    .small-btn { width: 100%; justify-content: center; }
}

@media (max-width: 380px) {
    .page-body { padding: .5rem !important; }

    .tab-btn {
        min-height: 52px;
        padding: .55rem .4rem;
        font-size: .76rem !important;
        gap: .3rem;
    }

    .tab-btn i {
        font-size: .88rem;
    }
}

.card { border-radius: 18px; overflow: hidden; margin-bottom: 1.1rem; }
.card-header { display: flex; align-items: center; gap: .5rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border,#1e2536); }
.card-title { font-size: clamp(1rem,3.5vw,1.15rem) !important; font-weight: 800; display: flex; align-items: center; gap: .45rem; margin: 0; }
.card-body { padding: 1.25rem; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.form-grid .col-full { grid-column: span 2; }
.form-group { display: flex; flex-direction: column; gap: .4rem; }
.form-label { font-size: clamp(.92rem,3.5vw,1rem) !important; font-weight: 700; color: var(--text,#e2e8f0); display: flex; align-items: center; gap: .4rem; }
.form-label .req { color: var(--red,#e63946); }
.form-help {
    font-size: .84rem;
    line-height: 1.6;
    color: var(--muted,#8892b0);
    background: rgba(59,130,246,.08);
    border: 1px solid rgba(59,130,246,.18);
    border-radius: 12px;
    padding: .8rem .95rem;
    margin-top: .35rem;
}
.form-help strong { color: #dbeafe; }
.form-control { font-size: clamp(.95rem,3.5vw,1.05rem) !important; min-height: 50px; padding: .7rem 1rem; border-radius: 12px !important; transition: border-color .2s, box-shadow .2s; width: 100%; }
.form-control:focus { outline: none; border-color: var(--red,#e63946) !important; box-shadow: 0 0 0 3px rgba(230,57,70,.15) !important; }
.settings-month-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.55rem; margin:1rem 0; }
.settings-month-option { position:relative; }
.settings-month-option input { position:absolute; opacity:0; pointer-events:none; }
.settings-month-option span { display:flex; align-items:center; justify-content:center; min-height:44px; padding:.55rem .4rem; border:1px solid var(--border,#1e2536); border-radius:11px; background:rgba(255,255,255,.025); color:var(--muted,#8892b0); font-size:.85rem; font-weight:800; cursor:pointer; transition:.18s; }
.settings-month-option input:checked + span { border-color:#f0a500; background:rgba(240,165,0,.16); color:#ffe099; box-shadow:0 0 0 1px rgba(240,165,0,.08); }
@media(max-width:700px){.settings-month-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:430px){.settings-month-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}

.btn { min-height: 50px; font-size: clamp(.95rem,3.5vw,1.05rem) !important; font-weight: 800 !important; display: inline-flex; align-items: center; justify-content: center; gap: .5rem; border-radius: 12px; transition: all .18s; text-decoration: none; padding: .65rem 1.3rem; cursor: pointer; border: none; white-space: nowrap; }
.btn:active { transform: scale(.97); }
.btn-sm { min-height: 40px; padding: .45rem 1rem; font-size: clamp(.88rem,3vw,.95rem) !important; border-radius: 10px; }
.btn-block { width: 100%; }

.btn-primary { background: linear-gradient(135deg,#e63946,#c92e3a); color:#fff; }
.btn-secondary { background: rgba(255,255,255,.06); color:#dbe4ff; border:1px solid rgba(255,255,255,.08); }
.btn-danger { background: #e63946; color:#fff; }

.pw-wrap { position: relative; }
.pw-wrap .form-control { padding-right: 3rem !important; }
.pw-eye { position: absolute; right: 0; top: 0; bottom: 0; width: 48px; display: flex; align-items: center; justify-content: center; background: none; border: none; cursor: pointer; color: var(--muted,#8892b0); font-size: 1.1rem; border-radius: 0 12px 12px 0; transition: color .15s; }
.pw-eye:hover { color: var(--text,#e2e8f0); }

.alert { border-radius: 12px; padding: 1rem 1.1rem; font-size: clamp(.92rem,3.5vw,1rem) !important; line-height: 1.55; display: flex; align-items: flex-start; gap: .65rem; }
.alert-success { background: rgba(45,198,83,.12); color: #86efac; border: 1px solid rgba(45,198,83,.25); }
.alert-success i { color: #4ade80; flex-shrink: 0; margin-top: .1rem; }
.alert-warning { background: rgba(240,165,0,.1); color: #fde68a; border: 1px solid rgba(240,165,0,.25); }
.alert-warning i { color: #f0a500; flex-shrink: 0; margin-top: .1rem; }
.alert-danger  { background: rgba(230,57,70,.1); color: #fca5a5; border: 1px solid rgba(230,57,70,.25); }
.alert-danger  i { color: #e63946; flex-shrink: 0; margin-top: .1rem; }

.nav-item { min-height: 46px !important; font-size: clamp(.92rem,3vw,1rem) !important; font-weight: 600 !important; padding: .65rem .9rem !important; border-radius: 10px !important; display: flex !important; align-items: center !important; gap: .7rem !important; transition: background .15s, color .15s !important; text-decoration: none; }
.sidebar-school { margin:.25rem 1rem !important; padding:0 !important; display:flex !important; align-items:center !important; font-weight:700 !important; font-size:clamp(.82rem,3vw,.92rem) !important; color:var(--text,#f0f2ff) !important; background:none !important; border:none !important; box-shadow:none !important; overflow-wrap:anywhere !important; word-break:break-word !important; }
.sidebar-school:hover,.sidebar-school:focus { background:none !important; outline:none !important; }

.text-green { color: var(--green,#2dc653) !important; }
.text-gold  { color: var(--gold,#f0a500) !important; }
.text-red   { color: var(--red,#e63946) !important; }
.text-muted { color: var(--muted,#8892b0) !important; }
.mt-3 { margin-top: 1.1rem !important; }

/* Danger zone */
.danger-zone-card {
    border-radius: 18px;
    border: 1.5px solid rgba(230,57,70,.35);
    background: rgba(230,57,70,.04);
    overflow: hidden;
    margin-top: 1.5rem;
}
.danger-zone-card .card-header {
    background: rgba(230,57,70,.07);
    border-bottom: 1px solid rgba(230,57,70,.25);
}
.danger-zone-card .card-title {
    color: #e63946;
}

/* ── Subscription compact styles ── */
.sub-status-card {
    border-radius: 18px;
    padding: 1.1rem;
    margin-bottom: .9rem;
    display: flex;
    flex-direction: column;
    gap: .75rem;
}
.sub-status-row {
    display: flex;
    align-items: center;
    gap: .85rem;
}
.sub-icon-wrap {
    width: 46px; height: 46px;
    border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 1.25rem;
}
.sub-main-info { flex: 1; min-width: 0; }
.sub-main-info h3 { margin: 0 0 .2rem; font-size: clamp(1rem,3.8vw,1.18rem); font-weight: 900; color: #e2e8f0; line-height: 1.3; }
.sub-main-info p  { margin: 0; font-size: clamp(.84rem,3vw,.92rem); color: #a9b4d0; line-height: 1.5; }
.sub-main-info strong { color: #fff; }

.plan-chip {
    display:inline-flex;align-items:center;gap:.3rem;border-radius:999px;padding:.22rem .7rem;
    font-size:.75rem;font-weight:900;margin-top:.35rem;
}
.plan-chip.pro { background:rgba(240,165,0,.14);color:#f0a500;border:1px solid rgba(240,165,0,.3); }
.plan-chip.basic { background:rgba(255,255,255,.06);color:#b8c3df;border:1px solid rgba(255,255,255,.12); }

.sub-meta-row {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
}
.sub-meta-pill {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .28rem .7rem;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 700;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.09);
    color: #9fb0d4;
}
.sub-meta-pill i { font-size: .7rem; }

.sub-progress-bar {
    background: rgba(255,255,255,.06);
    border-radius: 99px;
    height: 6px;
    overflow: hidden;
}
.sub-progress-fill {
    height: 100%;
    border-radius: 99px;
    transition: width .5s ease;
}

.big-action-btn { display: flex; align-items: center; justify-content: center; gap: .6rem; width: 100%; min-height: 52px; border-radius: 14px; border: none; cursor: pointer; font-size: clamp(.95rem,3.5vw,1.05rem); font-weight: 900; text-decoration: none; transition: opacity .15s, transform .1s; }
.big-action-btn:active { transform: scale(.98); opacity: .9; }

.simple-extras { display: flex; flex-direction: column; gap: .65rem; margin-bottom: .9rem; }
.extra-row {
    display: flex; align-items: center; justify-content: space-between; gap: .9rem;
    padding: .85rem 1rem; border-radius: 14px;
    background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.07); flex-wrap: wrap;
}
.extra-row-left { display: flex; align-items: center; gap: .65rem; }
.extra-row-left i { font-size: 1.05rem; width: 22px; text-align: center; }
.extra-row-label { font-size: .95rem; font-weight: 800; color: #e7eefc; }
.extra-row-sub { font-size: .82rem; color: #90a0c2; margin-top: .12rem; line-height:1.5; }

.small-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .9rem; border-radius: 10px; font-size: .85rem; font-weight: 800; cursor: pointer; border: none; text-decoration: none; white-space: nowrap; transition: opacity .15s; flex-shrink: 0; }

/* Compact payment card */
.pay-card { border-radius: 16px; overflow: hidden; margin-bottom: .9rem; }
.pay-card .card-header { padding: .8rem 1rem; }
.pay-card .card-body  { padding: .9rem 1rem; }

.copy-box {
    background:#0a0d16;
    border:1px solid #1e2536;
    border-radius:12px;
    padding:.75rem;
}
.copy-row {
    display:flex;
    gap:.6rem;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    padding:.42rem 0;
    border-bottom:1px solid rgba(255,255,255,.045);
}
.copy-row:last-child { border-bottom:none; padding-bottom:0; }
.copy-row:first-child { padding-top:0; }
.copy-label {
    min-width:80px;
    color:#7f8db0;
    font-size:.78rem;
    font-weight:800;
    flex-shrink: 0;
}
.copy-value {
    flex:1;
    min-width:140px;
    color:#fff;
    font-size:.9rem;
    line-height:1.55;
    word-break:break-word;
}
.copy-actions { display:flex;align-items:center;gap:.4rem; }
.copy-chip-btn {
    background:rgba(59,130,246,.1);
    border:1px solid rgba(59,130,246,.25);
    color:#60a5fa;
    border-radius:7px;
    padding:.28rem .55rem;
    cursor:pointer;
    font-size:.72rem;
    display:inline-flex;
    align-items:center;
    gap:.25rem;
    transition:all .2s;
    font-weight:800;
    white-space: nowrap;
}

.cancel-link { display: block; text-align: center; margin-top: .75rem; color: #6d7798; font-size: .84rem; cursor: pointer; background: none; border: none; padding: .4rem; width: 100%; transition: color .15s; }
.cancel-link:hover { color: #a7b1ca; }

/* Modals */
.simple-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.78); z-index: 10500; align-items: center; justify-content: center; padding: 1rem; opacity: 0; transition: opacity .22s ease; }
.simple-modal-overlay.modal-visible { opacity: 1; }
@keyframes modalSlideUp { from { opacity: 0; transform: translateY(18px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes modalSlideDown { from { opacity: 1; transform: translateY(0) scale(1); } to { opacity: 0; transform: translateY(12px) scale(.97); } }
.modal-visible .simple-modal-box { animation: modalSlideUp .26s cubic-bezier(.2,.8,.2,1) both; }
.modal-hiding .simple-modal-box { animation: modalSlideDown .2s ease both; }

.simple-modal-box {
    background: var(--card-bg, #131929);
    border: 1px solid var(--border, #1e2536);
    border-radius: 20px;
    width: 100%;
    max-width: 430px;
    max-height: 92vh;
    overflow-y: auto;
    box-shadow: 0 24px 80px rgba(0,0,0,.6);
}
.simple-modal-head {
    padding: .95rem 1rem;
    border-bottom: 1px solid var(--border, #1e2536);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    background: var(--card-bg, #131929);
    z-index: 2;
}
.simple-modal-body { padding: 1rem; }
.modal-close-btn {
    background: none;
    border: 1px solid var(--border,#1e2536);
    border-radius: 10px;
    color: #8892b0;
    width: 36px;
    height: 36px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal-btn-row { display: flex; gap: .6rem; justify-content: flex-end; margin-top: 1rem; flex-wrap:wrap; }
.modal-cancel { min-height: 42px; font-size: .9rem; font-weight: 700; display: inline-flex; align-items: center; gap: .4rem; border-radius: 10px; padding: .45rem .9rem; cursor: pointer; border: 1px solid var(--border,#1e2536); background: none; color: #8892b0; }
.modal-confirm { min-height: 42px; font-size: .9rem; font-weight: 800; display: inline-flex; align-items: center; gap: .4rem; border-radius: 10px; padding: .45rem 1.1rem; cursor: pointer; border: none; }

@media (max-width: 900px) { .page-body { padding: 1rem !important; } }
@media (max-width: 700px) {
    .page-body { padding: .85rem !important; }
    .form-grid { grid-template-columns: 1fr !important; }
    .form-grid .col-full { grid-column: span 1 !important; }
}
@media (max-width: 640px) {
    .simple-modal-overlay { align-items: flex-end !important; padding: 0 !important; }
    .simple-modal-box { max-width: 100%; width: 100%; border-radius: 18px 18px 0 0; max-height: 94vh; }
    .simple-modal-head { padding: .9rem 1rem; }
    .simple-modal-body { padding: 1rem; }
    .modal-btn-row { flex-direction: column; }
    .modal-btn-row .modal-cancel,
    .modal-btn-row .modal-confirm,
    .simple-modal-body .btn,
    .simple-modal-body button[type="submit"],
    .simple-modal-body a.btn { width: 100%; justify-content: center; }
}
@media (max-width: 320px) { .page-body { padding: .4rem !important; } }
</style>

<body>
<div class="app-layout">
<?php renderSidebar('settings'); ?>
<div id="dm-overlay"></div>

<div class="main-content">
<?php renderTopbar('Ρυθμίσεις'); ?>
<div class="page-body">

<div class="page-header anim-1">
    <h2><i class="fa-solid fa-gear" style="color:var(--red,#e63946)"></i> Ρυθμίσεις</h2>
</div>

<div class="tab-nav anim-1">
    <button class="tab-btn active" data-tab="school" onclick="switchTab(this.dataset.tab,this)">
        <i class="fa-solid fa-sliders"></i> <span class="tab-label">Γενικές</span>
    </button>
    <button class="tab-btn" data-tab="account" onclick="switchTab(this.dataset.tab,this)">
        <i class="fa-solid fa-lock"></i> <span class="tab-label">Κωδικός</span>
    </button>
    <button class="tab-btn" id="tabBtnSecurity" data-tab="security" onclick="switchTab(this.dataset.tab,this)">
        <i class="fa-solid fa-shield-halved"></i> <span class="tab-label">Ασφάλεια 2FA</span>
        <?php if ($twoFAEnabled): ?>
        <span style="background:#2dc653;color:#000;font-size:.6rem;padding:.1rem .4rem;border-radius:4px;font-weight:800">ON</span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="subscription" onclick="switchTab(this.dataset.tab,this)">
        <i class="fa-solid fa-calendar-check"></i> <span class="tab-label">Συνδρομή</span>
        <?php if ($daysLeft !== null && $daysLeft <= 14 && in_array($subStatus, ['active','trial'])): ?>
        <span style="background:#e63946;color:#fff;font-size:.6rem;padding:.1rem .4rem;border-radius:4px;font-weight:800"><?= $daysLeft ?>μ</span>
        <?php endif; ?>
    </button>
    <?php if ($isProPlan): ?>
    <button class="tab-btn" data-tab="privacy" onclick="switchTab(this.dataset.tab,this)">
        <i class="fa-solid fa-eye-slash"></i> <span class="tab-label">Απόκρυψη</span>
        <?php if ($privacyMode): ?>
        <span style="background:#f0a500;color:#000;font-size:.6rem;padding:.1rem .4rem;border-radius:4px;font-weight:800">ON</span>
        <?php endif; ?>
    </button>
    <?php endif; ?>
</div>

<!-- ══ TAB: SCHOOL ══ -->
<div id="tab-school" class="anim-2">
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-school" style="color:#3b82f6"></i> Στοιχεία Σχολής</div>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="_action" value="save_school">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="form-grid">
                <div class="form-group col-full">
                    <label class="form-label"><i class="fa-solid fa-building"></i> Όνομα Σχολής <span class="req">*</span></label>
                    <input name="name" class="form-control" value="<?= h($school['name']??'') ?>" required placeholder="π.χ. Σχολή Ταεκβοντό Αθήνας">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-phone"></i> Τηλέφωνο</label>
                    <input name="phone" class="form-control" value="<?= h($school['phone']??'') ?>" placeholder="210 0000000">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-envelope"></i> Email Σχολής</label>
                    <input type="email" name="email" class="form-control" value="<?= h($school['email']??'') ?>" placeholder="info@school.gr">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-city"></i> Πόλη</label>
                    <input name="city" class="form-control" value="<?= h($school['city']??'') ?>" placeholder="π.χ. Αθήνα">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-location-dot"></i> Διεύθυνση</label>
                    <input name="address" class="form-control" value="<?= h($school['address']??'') ?>" placeholder="π.χ. Λεωφόρος Αθηνών 10">
                </div>

                <div class="form-group col-full" style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border,#1e2536)">
                    <label class="form-label"><i class="fa-solid fa-at"></i> Username Λογαριασμού</label>
                    <input name="username" class="form-control" value="<?= h($currentUsername) ?>" placeholder="Το username σύνδεσής σας" autocomplete="username">
                    <div class="form-help">
                        <strong>Χρήσιμη πληροφορία:</strong>
                        Εδώ αλλάζετε το όνομα με το οποίο συνδέεστε στην εφαρμογή.
                        Αν δεν θέλετε να το αλλάξετε, αφήστε το όπως είναι.
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση Αλλαγών</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SCHOOL-WIDE NO-CHARGE MONTHS ── -->
<div class="card" style="margin-bottom:1.1rem;border:1px solid rgba(240,165,0,.28)">
    <div class="card-header" style="background:rgba(240,165,0,.06)">
        <div>
            <div class="card-title"><i class="fa-solid fa-umbrella-beach" style="color:#f0a500"></i> Μήνες Διακοπής / Χωρίς Χρέωση</div>
            <div style="font-size:.82rem;color:#aeb8d0;margin-top:.3rem">Γενικός κανόνας για όλη τη σχολή</div>
        </div>
    </div>
    <div class="card-body">
        <p style="color:#c0c9dc;font-size:.9rem;line-height:1.65;margin:0">
            Επιλέξτε τους μήνες που η σχολή παραμένει κλειστή. Ο κανόνας επαναλαμβάνεται κάθε χρόνο,
            εφαρμόζεται σε όλους τους αθλητές και <strong style="color:#ffe099">υπερισχύει από τους κανόνες των τμημάτων</strong>.
            Οι μήνες αυτοί δεν υπολογίζονται ως οφειλή και δεν αποστέλλονται αυτόματα email ή SMS υπενθύμισης.
        </p>
        <form method="POST">
            <input type="hidden" name="_action" value="save_school_billing_pauses">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="settings-month-grid">
                <?php foreach ($billingMonthLabels as $monthNumber => $monthLabel): ?>
                <label class="settings-month-option">
                    <input type="checkbox" name="school_billing_pause_months[]" value="<?= (int)$monthNumber ?>"
                        <?= in_array((int)$monthNumber, $schoolBillingPauseMonths, true) ? 'checked' : '' ?>>
                    <span><?= h($monthLabel) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap">
                <div style="font-size:.8rem;color:var(--muted,#8892b0)">
                    Αποθήκευση χωρίς επιλεγμένο μήνα = απενεργοποίηση του γενικού κανόνα.
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Αποθήκευση Μηνών
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── PAYMENT DETAILS (IBAN / IRIS) ── -->
<div class="card" style="margin-bottom:1.1rem">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-building-columns" style="color:#6b5ce7"></i> Στοιχεία Πληρωμής για Γονείς</div>
        <div class="card-subtitle" style="font-size:.82rem;color:#8892b0;margin-top:.25rem">
            Τα στοιχεία αυτά εμφανίζονται στους γονείς στο Portal τους ώστε να γνωρίζουν πώς να πληρώσουν τη συνδρομή.
        </div>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="_action" value="save_payment_details">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-hashtag"></i> IBAN</label>
                    <input name="payment_iban" class="form-control" value="<?= h($paymentMeta['payment_iban'] ?? '') ?>"
                           placeholder="GR00 0000 0000 0000 0000 0000 000"
                           style="font-family:monospace;letter-spacing:.04em">
                    <div class="form-help">Ο αριθμός τραπεζικού λογαριασμού της σχολής.</div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-bolt"></i> IRIS (Αριθμός Κινητού ή ΑΦΜ)</label>
                    <input name="payment_iris" class="form-control" value="<?= h($paymentMeta['payment_iris'] ?? '') ?>"
                           placeholder="69XXXXXXXX">
                    <div class="form-help">Κινητό ή ΑΦΜ που είναι καταχωρημένο στο IRIS της τράπεζας.</div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-id-card"></i> ΑΦΜ Σωματείου</label>
                    <input name="payment_afm" class="form-control" value="<?= h($paymentMeta['payment_afm'] ?? '') ?>"
                           placeholder="π.χ. 123456789"
                           style="font-family:monospace;letter-spacing:.04em">
                    <div class="form-help">Εναλλακτικά για IRIS μέσω ΑΦΜ.</div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-user"></i> Δικαιούχος</label>
                    <input name="payment_beneficiary" class="form-control" value="<?= h($paymentMeta['payment_beneficiary'] ?? '') ?>"
                           placeholder="π.χ. Σωματείο Τ.Ν. Αθήνας">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-landmark"></i> Τράπεζα</label>
                    <input name="payment_bank" class="form-control" value="<?= h($paymentMeta['payment_bank'] ?? '') ?>"
                           placeholder="π.χ. Πειραιώς, Alpha, Εθνική">
                </div>
                <div class="form-group col-full">
                    <label class="form-label"><i class="fa-solid fa-circle-info"></i> Οδηγίες / Σημειώσεις για γονείς</label>
                    <textarea name="payment_notes" class="form-control" rows="2"
                              placeholder="π.χ. Αναφέρετε ως αιτία: Ονοματεπώνυμο αθλητή + Μήνας πληρωμής"
                              style="resize:vertical"><?= h($paymentMeta['payment_notes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση Στοιχείων</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SUMMER PAUSE OPT-IN ── -->
<?php if ($summerPauseGlobalEnabled): ?>
<div class="card" style="margin-bottom:1.1rem;border:1px solid rgba(240,165,0,.25)">
    <div class="card-header" style="background:rgba(240,165,0,.06)">
        <div class="card-title"><i class="fa-solid fa-sun" style="color:#f0a500"></i> Θερινή Παύση</div>
    </div>
    <div class="card-body">
        <p style="color:#b0bcd4;font-size:.9rem;line-height:1.6;margin:0 0 1rem">
            Η θερινή παύση αναστέλλει τις αυτόματες ειδοποιήσεις κατά τους καλοκαιρινούς μήνες και ενημερώνει τους καθηγητές σας.
            <?php if (!empty($summerCfg['summer_pause_message'])): ?>
            <br><em style="color:#f0a500"><?= h($summerCfg['summer_pause_message']) ?></em>
            <?php endif; ?>
        </p>
        <form method="POST">
            <input type="hidden" name="_action" value="toggle_summer_pause">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
                    <label class="toggle">
                        <input type="checkbox" name="summer_pause_opted_in" value="1"
                            id="summerOptIn" onchange="this.form.submit()"
                            <?= $schoolSummerOptIn ? 'checked' : '' ?>>
                        <div class="toggle-track"></div>
                    </label>
                    <span style="font-size:.95rem;font-weight:700;color:#e2e8f0">
                        <?= $schoolSummerOptIn ? 'Ενεργοποιημένη για τη σχολή μου' : 'Ενεργοποίηση για τη σχολή μου' ?>
                    </span>
                </label>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── DANGER ZONE: Delete Account ── -->
<div class="danger-zone-card">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-triangle-exclamation"></i> Απαιτείται Προσοχή
        </div>
    </div>
    <div class="card-body">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
            <div style="flex:1;min-width:220px">
                <div style="font-size:1rem;font-weight:800;color:#e2e8f0;margin-bottom:.3rem">
                    <i class="fa-solid fa-trash-can" style="color:#e63946;margin-right:.4rem"></i>Διαγραφή Λογαριασμού
                </div>
                <div style="font-size:.88rem;color:#8892b0;line-height:1.6">
                    Υποβολή αιτήματος διαγραφής όλων των δεδομένων σας.
                    Το αίτημα αποστέλλεται στον διαχειριστή για χειροκίνητη επεξεργασία.
                </div>
            </div>
            <button type="button"
                onclick="openModal('deleteAccountModal')"
                style="display:inline-flex;align-items:center;gap:.5rem;padding:.65rem 1.3rem;border-radius:12px;background:rgba(230,57,70,.1);color:#e63946;border:1.5px solid rgba(230,57,70,.35);font-size:.95rem;font-weight:800;cursor:pointer;white-space:nowrap;transition:all .18s;min-height:48px;"
                onmouseover="this.style.background='rgba(230,57,70,.2)'"
                onmouseout="this.style.background='rgba(230,57,70,.1)'">
                <i class="fa-solid fa-trash-can"></i> Αίτημα Διαγραφής
            </button>
        </div>
    </div>
</div>

</div><!-- /tab-school -->

<!-- ══ TAB: PASSWORD ══ -->
<div id="tab-account" style="display:none" class="anim-2">
<div class="card" style="max-width:540px">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-lock" style="color:var(--gold,#f0a500)"></i> Αλλαγή Κωδικού</div>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="_action" value="save_password">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="form-grid" style="grid-template-columns:1fr">
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-key"></i> Τρέχων Κωδικός</label>
                    <div class="pw-wrap">
                        <input type="password" name="current_password" id="pw_current" class="form-control" required placeholder="••••••••">
                        <button type="button" class="pw-eye" onclick="togglePw('pw_current',this)" tabindex="-1"><i class="fa-solid fa-eye"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-lock"></i> Νέος Κωδικός <span class="text-muted" style="font-size:clamp(.8rem,3vw,.88rem)!important;font-weight:600">(τουλάχιστον 8 χαρακτήρες)</span></label>
                    <div class="pw-wrap">
                        <input type="password" name="new_password" id="pw_new" class="form-control" required placeholder="••••••••">
                        <button type="button" class="pw-eye" onclick="togglePw('pw_new',this)" tabindex="-1"><i class="fa-solid fa-eye"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-shield-check"></i> Επαλήθευση Νέου Κωδικού</label>
                    <div class="pw-wrap">
                        <input type="password" name="confirm_password" id="pw_confirm" class="form-control" required placeholder="••••••••">
                        <button type="button" class="pw-eye" onclick="togglePw('pw_confirm',this)" tabindex="-1"><i class="fa-solid fa-eye"></i></button>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> Ενημέρωση Κωδικού</button>
            </div>
        </form>
    </div>
</div>
</div><!-- /tab-account -->

<!-- ══ TAB: SUBSCRIPTION ══ -->
<div id="tab-subscription" style="display:none" class="anim-2">
<?php
$isActive    = in_array($subStatus, ['active', 'trial']);
$isTrial     = $subStatus === 'trial';
$isCancelled = $subStatus === 'cancelled';
$isPastDue   = in_array($subStatus, ['past_due', 'suspended']);
$isPro       = $isProPlan;

$barColor = '#2dc653';
if ($daysLeft !== null) {
    if ($daysLeft <= 7)  $barColor = '#e63946';
    elseif ($daysLeft <= 14) $barColor = '#f0a500';
}
$totalDays = ($billingCycle === 'annual') ? 365 : 30;
$barPct = $daysLeft !== null ? min(100, round(($daysLeft / $totalDays) * 100)) : 100;

if ($isPastDue) {
    $cardBg = 'rgba(230,57,70,.07)'; $cardBorder = 'rgba(230,57,70,.3)';
    $iconBg = 'rgba(230,57,70,.15)'; $iconColor = '#e63946';
    $iconChar = '<i class="fa-solid fa-triangle-exclamation"></i>';
} elseif ($isCancelled) {
    $cardBg = 'rgba(100,100,120,.06)'; $cardBorder = 'rgba(100,100,120,.2)';
    $iconBg = 'rgba(100,100,120,.12)'; $iconColor = '#8892b0';
    $iconChar = '<i class="fa-solid fa-circle-pause"></i>';
} elseif ($daysLeft !== null && $daysLeft <= 7) {
    $cardBg = 'rgba(230,57,70,.06)'; $cardBorder = 'rgba(230,57,70,.25)';
    $iconBg = 'rgba(230,57,70,.12)'; $iconColor = '#e63946';
    $iconChar = '<i class="fa-solid fa-fire"></i>';
} elseif ($daysLeft !== null && $daysLeft <= 14) {
    $cardBg = 'rgba(240,165,0,.06)'; $cardBorder = 'rgba(240,165,0,.25)';
    $iconBg = 'rgba(240,165,0,.12)'; $iconColor = '#f0a500';
    $iconChar = '<i class="fa-solid fa-clock"></i>';
} else {
    $cardBg = 'rgba(45,198,83,.05)'; $cardBorder = 'rgba(45,198,83,.2)';
    $iconBg = 'rgba(45,198,83,.12)'; $iconColor = '#2dc653';
    $iconChar = '<i class="fa-solid fa-circle-check"></i>';
}

if ($isPastDue) {
    $statusHeadline = 'Η συνδρομή σας έχει λήξει';
    $statusDetail   = 'Χρειάζεται ανανέωση για να συνεχίσετε.';
} elseif ($isCancelled) {
    $statusHeadline = 'Συνδρομή ακυρωμένη';
    $statusDetail   = $expiryDate ? 'Ενεργή έως <strong>'.date('d/m/Y', strtotime($expiryDate)).'</strong>.' : 'Δεν ανανεώνεται αυτόματα.';
} elseif ($isTrial) {
    $statusHeadline = 'Δοκιμαστική περίοδος';
    $statusDetail   = $expiryDate ? 'Απομένουν <strong>'.$daysLeft.' μέρες</strong> δωρεάν χρήσης.' : 'Δοκιμάζετε όλες τις δυνατότητες.';
} elseif ($daysLeft !== null && $daysLeft <= 7) {
    $statusHeadline = 'Η συνδρομή λήγει σύντομα!';
    $statusDetail   = 'Απομένουν μόνο <strong>'.$daysLeft.' μέρες</strong>. Ανανεώστε τώρα.';
} elseif ($daysLeft !== null && $daysLeft <= 14) {
    $statusHeadline = 'Η συνδρομή λήγει σύντομα';
    $statusDetail   = 'Απομένουν <strong>'.$daysLeft.' μέρες</strong>.';
} else {
    $statusHeadline = $isPro ? 'Pro πλάνο — Ενεργό' : 'Basic πλάνο — Ενεργό';
    $statusDetail   = $expiryDate ? 'Ισχύει έως <strong>'.date('d/m/Y', strtotime($expiryDate)).'</strong>.' : 'Όλα λειτουργούν κανονικά.';
}
?>

<!-- Status card (compact) -->
<div class="sub-status-card" style="background:<?= $cardBg ?>;border:1.5px solid <?= $cardBorder ?>">

    <div class="sub-status-row">
        <div class="sub-icon-wrap" style="background:<?= $iconBg ?>;color:<?= $iconColor ?>"><?= $iconChar ?></div>
        <div class="sub-main-info">
            <h3><?= $statusHeadline ?></h3>
            <p><?= $statusDetail ?></p>
            <?php if ($isPro): ?>
            <span class="plan-chip pro"><i class="fa-solid fa-star"></i> Pro</span>
            <?php else: ?>
            <span class="plan-chip basic">Basic</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($daysLeft !== null && $isActive): ?>
    <div class="sub-progress-bar">
        <div class="sub-progress-fill" style="width:<?= $barPct ?>%;background:<?= $barColor ?>"></div>
    </div>
    <?php endif; ?>

    <?php if ($isPastDue || ($daysLeft !== null && $daysLeft <= 14 && !$isCancelled)): ?>
    <button type="button" class="big-action-btn" onclick="openModal('simpleRenewModal')" style="background:#e63946;color:#fff">
        <i class="fa-solid fa-rotate-right"></i> Ανανέωση Συνδρομής
    </button>
    <?php elseif ($isActive && !$isCancelled): ?>
    <button type="button" class="big-action-btn" onclick="openModal('simpleRenewModal')" style="background:rgba(59,130,246,.15);color:#3b82f6;border:1.5px solid rgba(59,130,246,.3)">
        <i class="fa-solid fa-rotate-right"></i> Ανανέωση Συνδρομής
    </button>
    <?php elseif ($isCancelled): ?>
    <button type="button" class="big-action-btn" onclick="openModal('simpleRenewModal')" style="background:#3b82f6;color:#fff">
        <i class="fa-solid fa-rotate-right"></i> Επανενεργοποίηση Συνδρομής
    </button>
    <?php endif; ?>

    <?php if (!$isPro): ?>
    <a href="<?= APP_URL ?>/pages/upgrade.php" class="big-action-btn" style="background:linear-gradient(135deg,#f0a500,#ff7c00);color:#0a0d16">
        <i class="fa-solid fa-star"></i> Αναβάθμιση σε Pro
    </a>
    <?php endif; ?>
</div>

<?php if ($planSlug === 'basic'): ?>
<div class="simple-extras">
    <div class="extra-row">
        <div class="extra-row-left">
            <?php if ($smsAddonActive): ?>
            <i class="fa-solid fa-comment-sms" style="color:#2dc653"></i>
            <div>
                <div class="extra-row-label">SMS <span style="background:#2dc653;color:#000;font-size:.62rem;padding:.1rem .38rem;border-radius:4px;font-weight:800;vertical-align:middle">ΕΝΕΡΓΟ</span></div>
                <div class="extra-row-sub"><?= $smsAddonExpires ? 'Ισχύει έως '.date('d/m/Y', strtotime($smsAddonExpires)) : 'Ενεργό πακέτο' ?></div>
            </div>
            <?php else: ?>
            <i class="fa-solid fa-comment-sms" style="color:#4a5580"></i>
            <div>
                <div class="extra-row-label">SMS Ειδοποιήσεις</div>
                <div class="extra-row-sub">Υπενθυμίσεις στους αθλητές — <strong style="color:#2dc653">8€/μήνα</strong></div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($smsAddonActive): ?>
        <form method="POST" onsubmit="return confirm('Να απενεργοποιηθεί το SMS πακέτο;')">
            <input type="hidden" name="_action" value="deactivate_sms_addon">
            <button type="submit" class="small-btn" style="background:rgba(255,255,255,.05);color:#9aa7c2;border:1px solid rgba(255,255,255,.08)">
                <i class="fa-solid fa-xmark"></i> Απενεργοποίηση
            </button>
        </form>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="_action" value="activate_sms_addon">
            <button type="submit" class="small-btn" style="background:rgba(45,198,83,.15);color:#2dc653;border:1.5px solid rgba(45,198,83,.35)">
                <i class="fa-solid fa-plus"></i> Ενεργοποίηση
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Payment Info (compact) ── -->
<div class="card pay-card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-building-columns" style="color:#f0a500"></i> Στοιχεία Πληρωμής</div>
    </div>
    <div class="card-body">

        <div class="copy-box">
            <!-- Bank section -->
            <div style="font-size:.7rem;font-weight:900;color:#6b7494;text-transform:uppercase;letter-spacing:.06em;padding-bottom:.4rem;margin-bottom:.1rem">
                <i class="fa-solid fa-building-columns" style="margin-right:.3rem"></i>Τράπεζα Πειραιώς
            </div>
            <div class="copy-row">
                <div class="copy-label">IBAN</div>
                <div class="copy-value"><code id="ibanCode" style="color:#f0a500;font-family:monospace;font-size:.88rem">GR18 0172 1610 0051 6111 8162 881</code></div>
                <div class="copy-actions">
                    <button class="copy-chip-btn" onclick="copyAnimated('ibanCode',this)"><i class="fa-solid fa-copy"></i> Αντιγραφή</button>
                </div>
            </div>
            <div class="copy-row">
                <div class="copy-label">Δικαιούχος</div>
                <div class="copy-value" style="font-size:.85rem">ΚΟΤΣΟΡΓΙΟΣ ΠΑΝΑΓΙΩΤΗΣ ΔΗΜΗΤΡΙΟΥ</div>
            </div>
            <div class="copy-row">
                <div class="copy-label">Αιτιολογία</div>
                <div class="copy-value">
                    <code id="transferRef" style="color:#2dc653;font-family:monospace;font-size:.78rem;background:rgba(45,198,83,.07);padding:.35rem .6rem;border-radius:7px;display:block;line-height:1.55;word-break:break-all">Συνδρομή MAster <?= h($planLabel) ?> <?= h($cycleLabel) ?> - <?= h($school['name'] ?? '') ?> (<?= h($user['email'] ?? '') ?>)</code>
                </div>
                <div class="copy-actions">
                    <button class="copy-chip-btn" onclick="copyAnimated('transferRef',this)" style="background:rgba(45,198,83,.1);border-color:rgba(45,198,83,.25);color:#2dc653"><i class="fa-solid fa-copy"></i> Αντιγραφή</button>
                </div>
            </div>

            <!-- Divider -->
            <div style="border-top:1px dashed rgba(255,255,255,.08);margin:.6rem 0 .5rem"></div>

            <!-- IRIS section -->
            <div style="font-size:.7rem;font-weight:900;color:#6b7494;text-transform:uppercase;letter-spacing:.06em;padding-bottom:.4rem">
                <i class="fa-solid fa-mobile-screen-button" style="margin-right:.3rem"></i>IRIS
            </div>
            <div class="copy-row">
                <div class="copy-label">Κινητό</div>
                <div class="copy-value"><code id="irisPhone" style="color:#5078ff;font-family:monospace;font-size:.95rem;font-weight:700;letter-spacing:.08em">6986788178</code></div>
                <div class="copy-actions">
                    <button class="copy-chip-btn" onclick="copyAnimated('irisPhone',this)" style="background:rgba(80,120,255,.1);border-color:rgba(80,120,255,.28);color:#5078ff"><i class="fa-solid fa-copy"></i> Αντιγραφή</button>
                </div>
            </div>
            <div class="copy-row">
                <div class="copy-label">Δικαιούχος</div>
                <div class="copy-value" style="font-size:.85rem">ΚΟΤΣΟΡΓΙΟΣ ΠΑΝΑΓΙΩΤΗΣ ΔΗΜΗΤΡΙΟΥ</div>
            </div>
        </div>

        <p style="margin:.75rem 0 0;font-size:.82rem;color:#8892b0;line-height:1.6">
            Στείλτε αποδεικτικό στο <a href="mailto:pkotsorgios654@gmail.com" style="color:#e63946;font-weight:700">pkotsorgios654@gmail.com</a>.
            Ενεργοποίηση σε <strong style="color:#fff">1–2 εργάσιμες μέρες</strong>.
        </p>
    </div>
</div>

<?php if ($subStatus === 'active'): ?>
<button type="button" onclick="openModal('simpleCancelModal')" class="cancel-link">
    Ακύρωση συνδρομής
</button>
<?php endif; ?>

<!-- Renew Modal -->
<div id="simpleRenewModal" class="simple-modal-overlay" onclick="if(event.target===this)closeModal('simpleRenewModal')">
<div class="simple-modal-box" style="max-width:480px">
    <div class="simple-modal-head">
        <div style="font-size:1rem;font-weight:800;color:#e2e8f0;display:flex;align-items:center;gap:.5rem">
            <i class="fa-solid fa-rotate-right"></i> Ανανέωση Συνδρομής
        </div>
        <button class="modal-close-btn" onclick="closeModal('simpleRenewModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <?php
    $basicMonthly = 15.00;
    $basicAnnualMonthlyEquivalent = 12.50;
    $basicAnnualTotal = 150.00;

    $proMonthly = 25.00;
    $proAnnualMonthlyEquivalent = 20.00;
    $proAnnualTotal = 240.00;
    ?>

    <div class="simple-modal-body" style="padding:1.2rem;font-size:.93rem;color:#d0d8f0;line-height:1.6">
        <p style="margin:0 0 .6rem;font-weight:700;color:#fff">Διαθέσιμα πλάνα:</p>

        <div style="display:flex;flex-direction:column;gap:.75rem;margin:0 0 1.2rem">
            <div style="border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:.85rem 1rem;background:rgba(255,255,255,.03)">
                <div style="font-size:.95rem;font-weight:800;color:#fff;margin-bottom:.45rem">
                    Basic
                </div>
                <div style="color:#b0bcd4;font-size:.88rem;line-height:1.8">
                    <div><strong style="color:#fff">Μηνιαίο:</strong> <?= number_format($basicMonthly, 2, ',', '.') ?>€ / μήνα</div>
                    <div><strong style="color:#fff">Ετήσιο:</strong> <?= number_format($basicAnnualTotal, 2, ',', '.') ?>€ / έτος <span style="color:#2dc653">(<?= number_format($basicAnnualMonthlyEquivalent, 2, ',', '.') ?>€ / μήνα)</span></div>
                    <div style="color:#8892b0;font-size:.75rem;letter-spacing:.04em;margin-top:.15rem">συμπ. ΦΠΑ</div>
                </div>
            </div>

            <div style="border:1px solid rgba(240,165,0,.18);border-radius:12px;padding:.85rem 1rem;background:rgba(240,165,0,.05)">
                <div style="font-size:.95rem;font-weight:800;color:#f0a500;margin-bottom:.45rem">
                    Pro
                </div>
                <div style="color:#d7c39a;font-size:.88rem;line-height:1.8">
                    <div><strong style="color:#fff">Μηνιαίο:</strong> <?= number_format($proMonthly, 2, ',', '.') ?>€ / μήνα</div>
                    <div><strong style="color:#fff">Ετήσιο:</strong> <?= number_format($proAnnualTotal, 2, ',', '.') ?>€ / έτος <span style="color:#2dc653">(<?= number_format($proAnnualMonthlyEquivalent, 2, ',', '.') ?>€ / μήνα)</span></div>
                    <div style="color:#8892b0;font-size:.75rem;letter-spacing:.04em;margin-top:.15rem">συμπ. ΦΠΑ</div>
                </div>
            </div>
        </div>

        <p style="margin:0 0 .75rem;color:#b0bcd4">
            Πληρώστε με <strong style="color:#fff">IRIS</strong> ή <strong style="color:#fff">κατάθεση</strong> και στείλτε απόδειξη πληρωμής με email στο: <strong>pkotsorgios654@gmail.com</strong> για άμεση ενεργοποίηση τουλογαριασμού σας.
        </p>

        <table style="width:100%;border-collapse:collapse;margin-bottom:1.1rem;font-size:.88rem">
            <tr style="border-bottom:1px solid rgba(255,255,255,.07)">
                <td style="padding:.42rem .5rem;color:#8892b0;width:38%;white-space:nowrap">Δικαιούχος</td>
                <td style="padding:.42rem .5rem;color:#fff;font-weight:700">ΚΟΤΣΟΡΓΙΟΣ ΠΑΝΑΓΙΩΤΗΣ</td>
            </tr>
            <tr style="border-bottom:1px solid rgba(255,255,255,.07)">
                <td style="padding:.42rem .5rem;color:#8892b0">IBAN</td>
                <td style="padding:.42rem .5rem;color:#fff;font-family:monospace" id="renewIban">GR18 0172 1610 0051 6111 8162 881</td>
            </tr>
<tr style="border-bottom:1px solid rgba(255,255,255,.07)">
    <td style="padding:.42rem .5rem;color:#8892b0">IRIS</td>
    <td style="padding:.42rem .5rem;color:#fff;font-family:monospace">
        <div><strong>6986788178</strong> ή ΑΦΜ: <strong>176091030</strong></div>
    </td>
</tr>
            <tr>
                <td style="padding:.42rem .5rem;color:#8892b0;vertical-align:top">Αιτιολογία</td>
                <td style="padding:.42rem .5rem;font-size:.88rem;line-height:1.4;color:#f0a500;font-weight:700">
                    Πληρωμή συνδρομής MAster — <?= h($school['name'] ?? '') ?>
                </td>
            </tr>
        </table>

        <p style="margin:0 0 .9rem;font-size:.84rem;color:#b0bcd4">
            Αποστείλετε το αποδεικτικό στο: <a href="mailto:pkotsorgios654@gmail.com" style="color:#fff;font-weight:700">pkotsorgios654@gmail.com</a>
        </p>

        <div class="modal-btn-row">
            <button type="button" class="modal-cancel" onclick="closeModal('simpleRenewModal')">Κλείσιμο</button>
        </div>
    </div>
</div>
</div>

<script>
var APP_URL  = '<?= APP_URL ?>';
var planSlug = '<?= addslashes($planSlug) ?>';
</script>

<!-- Cancel Modal -->
<div id="simpleCancelModal" class="simple-modal-overlay" onclick="if(event.target===this)closeModal('simpleCancelModal')">
<div class="simple-modal-box">
    <div class="simple-modal-head">
        <div style="font-size:1rem;font-weight:800;color:#e63946;display:flex;align-items:center;gap:.5rem">
            <i class="fa-solid fa-stop-circle"></i> Ακύρωση Συνδρομής
        </div>
        <button class="modal-close-btn" onclick="closeModal('simpleCancelModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="simple-modal-body">
        <p style="color:#d0d8f0;font-size:.95rem;line-height:1.6;margin:0 0 .75rem">
            Η συνδρομή θα μείνει ενεργή έως
            <strong><?= $expiryDate ? date('d/m/Y', strtotime($expiryDate)) : 'τη λήξη της' ?></strong>.
        </p>
        <form method="POST">
            <input type="hidden" name="_action" value="cancel_subscription">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="modal-btn-row">
                <button type="button" class="modal-cancel" onclick="closeModal('simpleCancelModal')">Ακύρωση</button>
                <button type="submit" class="modal-confirm" style="background:#e63946;color:#fff">
                    <i class="fa-solid fa-stop-circle"></i> Επιβεβαίωση
                </button>
            </div>
        </form>
    </div>
</div>
</div>

</div><!-- /tab-subscription -->

<!-- ══ TAB: SECURITY / 2FA ══ -->
<div id="tab-security" style="display:none">
<?php if ($backupCodes): ?>
<div class="card" style="border-color:rgba(240,165,0,.4);background:rgba(240,165,0,.04);margin-bottom:1rem">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-key" style="color:#f0a500"></i> Backup Κωδικοί — Αποθηκεύστε τους ΤΩΡΑ</div>
    </div>
    <div class="card-body">
        <p style="color:#d0d8f0;margin-bottom:.75rem;font-size:.875rem">Αυτοί οι κωδικοί εμφανίζονται μόνο μία φορά. Αποθηκεύστε τους σε ασφαλές μέρος.</p>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.4rem;margin-bottom:1rem">
            <?php foreach ($backupCodes as $bc): ?>
            <code style="background:#0d1017;border:1px solid #1e2536;border-radius:6px;padding:.4rem .75rem;font-size:.9rem;font-family:monospace;color:#f0a500;text-align:center"><?= h($bc) ?></code>
            <?php endforeach; ?>
        </div>
        <p style="font-size:.78rem;color:#6b7494"><i class="fa-solid fa-circle-info"></i> Κάθε κωδικός μπορεί να χρησιμοποιηθεί μία μόνο φορά για σύνδεση χωρίς 2FA.</p>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-shield-halved" style="color:#e63946"></i> Δύο Παράγοντες Αυθεντικοποίησης (2FA)</div>
    </div>
    <div class="card-body">
        <?php if ($twoFAEnabled): ?>
        <div style="background:rgba(45,198,83,.08);border:1px solid rgba(45,198,83,.3);border-radius:10px;padding:1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem">
            <i class="fa-solid fa-circle-check" style="color:#2dc653;font-size:1.5rem;flex-shrink:0"></i>
            <div><strong style="color:#2dc653;display:block">2FA Ενεργό</strong><span style="font-size:.82rem;color:#6b7494">Ο λογαριασμός σας προστατεύεται με επαλήθευση μέσω email (2FA).</span></div>
        </div>
        <form method="POST">
            <input type="hidden" name="_action" value="2fa_disable">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <button type="button" class="btn btn-danger" onclick="showDisable2FAModal(event)"><i class="fa-solid fa-shield-xmark"></i> Απενεργοποίηση 2FA</button>
        </form>
        <?php else: ?>
        <div style="background:rgba(230,57,70,.07);border:1px solid rgba(230,57,70,.2);border-radius:10px;padding:1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem">
            <i class="fa-solid fa-shield" style="color:#e63946;font-size:1.5rem;flex-shrink:0"></i>
            <div><strong style="color:#ffffff;display:block;font-size:.98rem">2FA Ανενεργό</strong><span style="font-size:.88rem;color:#d5dcec">Ο λογαριασμός σας δεν προστατεύεται με 2FA. Συνιστάται ιδιαίτερα η ενεργοποίηση.</span></div>
        </div>
        <p style="font-size:.92rem;color:#e2e8f0;line-height:1.65;margin-bottom:1rem">Το 2FA προσθέτει ένα επιπλέον επίπεδο ασφαλείας. Κατά τη σύνδεση, θα σας αποστέλλεται αυτόματα ένας μοναδικός κωδικός <strong style="color:#ffffff">στο email σας</strong>.</p>
        <form method="POST">
            <input type="hidden" name="_action" value="2fa_enable">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-shield-halved"></i> Ενεργοποίηση 2FA</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</div><!-- /tab-security -->

<!-- ══ TAB: PRIVACY (only for pro users) ══ -->
<?php if ($isProPlan): ?>
<div id="tab-privacy" style="display:none" class="anim-2">
<div class="card" style="max-width:100%">
    <div class="card-header" style="padding:1.1rem 1.4rem">
        <div class="card-title"><i class="fa-solid fa-eye-slash" style="color:#f0a500"></i> Απόκρυψη Οικονομικών Στατιστικών</div>
        <span style="display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .85rem;border-radius:999px;font-size:.82rem;font-weight:800;
            background:<?= $privacyMode ? 'rgba(240,165,0,.14)' : 'rgba(45,198,83,.12)' ?>;
            color:<?= $privacyMode ? '#f0a500' : '#2dc653' ?>;
            border:1px solid <?= $privacyMode ? 'rgba(240,165,0,.3)' : 'rgba(45,198,83,.28)' ?>">
            <i class="fa-solid fa-<?= $privacyMode ? 'eye-slash' : 'eye' ?>"></i>
            <?= $privacyMode ? 'Ενεργή' : 'Ανενεργή' ?>
        </span>
    </div>
    <div class="card-body" style="padding:1.25rem 1.4rem">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.2rem">
            <div style="background:rgba(230,57,70,.05);border:1px solid rgba(230,57,70,.15);border-radius:14px;padding:.9rem 1rem">
                <div style="font-size:.78rem;font-weight:800;color:#e63946;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:.05em">
                    <i class="fa-solid fa-eye-slash" style="margin-right:.3rem"></i>Αποκρύπτεται
                </div>
                <ul style="margin:0;padding:0;list-style:none;color:#d0d8f0;font-size:.88rem;line-height:1.9">
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-xmark" style="color:#e63946;font-size:.7rem;flex-shrink:0"></i> Έσοδα Μήνα</li>
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-xmark" style="color:#e63946;font-size:.7rem;flex-shrink:0"></i> Αθλητές με Οφειλή</li>
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-xmark" style="color:#e63946;font-size:.7rem;flex-shrink:0"></i> Top 5 Οφειλές (πίνακας)</li>
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-xmark" style="color:#e63946;font-size:.7rem;flex-shrink:0"></i> Sidebar Οικονομικά</li>
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-xmark" style="color:#e63946;font-size:.7rem;flex-shrink:0"></i> Sidebar Στατιστικά</li>
                </ul>
            </div>

            <div style="background:rgba(45,198,83,.04);border:1px solid rgba(45,198,83,.15);border-radius:14px;padding:.9rem 1rem">
                <div style="font-size:.78rem;font-weight:800;color:#2dc653;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:.05em">
                    <i class="fa-solid fa-eye" style="margin-right:.3rem"></i>Παραμένει ορατό
                </div>
                <ul style="margin:0;padding:0;list-style:none;color:#d0d8f0;font-size:.88rem;line-height:1.9">
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-check" style="color:#2dc653;font-size:.7rem;flex-shrink:0"></i> Πληρωμές αθλητών</li>
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-check" style="color:#2dc653;font-size:.7rem;flex-shrink:0"></i> Portal Γονέων</li>
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-check" style="color:#2dc653;font-size:.7rem;flex-shrink:0"></i> Ειδοποιήσεις</li>
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-check" style="color:#2dc653;font-size:.7rem;flex-shrink:0"></i> Χρεώσεις συνδρομής</li>
                    <li style="display:flex;align-items:center;gap:.4rem"><i class="fa-solid fa-check" style="color:#2dc653;font-size:.7rem;flex-shrink:0"></i> Όλες οι υπόλοιπες σελίδες</li>
                </ul>
            </div>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;padding:1rem 1.1rem;background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.07);border-radius:14px">
            <div style="font-size:.95rem;font-weight:700;color:#c8d4f0">
                <i class="fa-solid fa-eye-slash" style="color:#f0a500;margin-right:.4rem"></i>
                Μη καταγραφή οικονομικών στατιστικών στην οθόνη
            </div>
            <form method="POST">
                <input type="hidden" name="_action" value="toggle_privacy_mode">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="privacy_mode" value="<?= $privacyMode ? '0' : '1' ?>">
                <?php if ($privacyMode): ?>
                <button type="submit" class="btn" style="background:rgba(45,198,83,.15);color:#2dc653;border:1.5px solid rgba(45,198,83,.35);min-height:44px">
                    <i class="fa-solid fa-eye"></i> Απενεργοποίηση
                </button>
                <?php else: ?>
                <button type="submit" class="btn" style="background:rgba(240,165,0,.15);color:#f0a500;border:1.5px solid rgba(240,165,0,.35);min-height:44px">
                    <i class="fa-solid fa-eye-slash"></i> Ενεργοποίηση
                </button>
                <?php endif; ?>
            </form>
        </div>

    </div>
</div>
</div><!-- /tab-privacy -->
<style>
@media (max-width:560px) {
    #tab-privacy .card > .card-body > div:first-child {
        grid-template-columns: 1fr !important;
    }
}
</style>
<?php endif; ?>

<!-- ══ TAB: OPT-OUT ΕΙΔΟΠΟΙΗΣΕΩΝ ══
     Moved to the admin panel (pages/opt-out-manual.php). Only the
     platform admin processes STOP requests — the club shouldn't have
     to think about it.
-->
<div id="tab-opt_out" style="display:none" class="anim-2" hidden>

<?php if ($optOutMigrationNeeded): ?>
<div class="alert alert-warning" style="margin-bottom:1rem">
    <i class="fas fa-wrench"></i>
    <span><strong>Αυτόματη migration:</strong> Ο πίνακας <code>consent_log</code> δεν υπήρχε και δημιουργήθηκε αυτόματα. Η καρτέλα είναι πλέον πλήρως λειτουργική.</span>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;align-items:start">

    <!-- Form card -->
    <div class="card" style="margin-bottom:0">
        <div class="card-header">
            <div class="card-title">
                <i class="fa-solid fa-hand-point-right" style="color:#f0a500"></i>
                Καταχώρηση Opt-out
            </div>
        </div>
        <div class="card-body">

            <?php if ($optOutSuccess): ?>
            <div class="alert alert-success" style="margin-bottom:1rem">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($optOutSuccess) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($optOutError): ?>
            <div class="alert alert-danger" style="margin-bottom:1rem">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= htmlspecialchars($optOutError) ?></span>
            </div>
            <?php endif; ?>

            <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.18);border-radius:12px;padding:.85rem 1rem;margin-bottom:1rem;font-size:.85rem;color:#b0c4de;line-height:1.7">
                <strong style="color:#dbeafe;display:block;margin-bottom:.4rem"><i class="fa-solid fa-circle-info" style="margin-right:.3rem"></i>Πότε να χρησιμοποιήσετε αυτή την καρτέλα:</strong>
                Μόνο κατόπιν ενημέρωσης από την <strong style="color:#fff">εξυπηρέτηση πελατών του MAster</strong> — όταν σας γνωστοποιηθεί ότι κάποιος γονέας έχει ζητήσει διαγραφή από τις ειδοποιήσεις (STOP).
            </div>

            <form method="POST">
                <input type="hidden" name="_action" value="manual_opt_out">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label"><i class="fa-solid fa-envelope"></i> Email γονέα <span class="req">*</span></label>
                    <input type="email" name="opt_email" class="form-control" placeholder="parent@example.com" required>
                </div>

                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label"><i class="fa-solid fa-tower-broadcast"></i> Κανάλι opt-out</label>
                    <select name="opt_channel" class="form-control" style="min-height:50px;border-radius:12px">
                        <option value="email">Email ειδοποιήσεις</option>
                        <option value="sms">SMS ειδοποιήσεις</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label"><i class="fa-solid fa-clipboard-list"></i> Αιτία (audit log)</label>
                    <select name="opt_reason" class="form-control" style="min-height:50px;border-radius:12px">
                        <option value="stop_sms">Εισερχόμενο STOP SMS</option>
                        <option value="stop_email">Εισερχόμενο STOP Email</option>
                        <option value="manual_admin">Χειροκίνητη ενέργεια διαχειριστή</option>
                        <option value="complaint">Καταγγελία</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-block" style="background:linear-gradient(135deg,#f0a500,#d48800);color:#0a0d16;min-height:50px;font-weight:900">
                    <i class="fa-solid fa-bell-slash"></i> Καταχώρηση Opt-out
                </button>
            </form>
        </div>
    </div>

    <!-- Log card -->
    <div class="card" style="margin-bottom:0">
        <div class="card-header">
            <div class="card-title">
                <i class="fa-solid fa-list-check" style="color:#3b82f6"></i>
                Ιστορικό Opt-out
            </div>
            <span style="margin-left:auto;font-size:.78rem;color:#6b7494">τελευταίες 50</span>
        </div>
        <div class="card-body" style="padding:0;max-height:420px;overflow-y:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.84rem">
                <thead>
                    <tr style="background:rgba(255,255,255,.03);border-bottom:1px solid var(--border,#1e2536)">
                        <th style="padding:.55rem .85rem;text-align:left;font-weight:800;color:#8892b0;white-space:nowrap">Email</th>
                        <th style="padding:.55rem .85rem;text-align:left;font-weight:800;color:#8892b0;white-space:nowrap">Τύπος</th>
                        <th style="padding:.55rem .85rem;text-align:left;font-weight:800;color:#8892b0;white-space:nowrap">Ημερομηνία</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($optOutLog as $ol): ?>
                    <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
                        <td style="padding:.5rem .85rem;color:#d0d8f0;word-break:break-all">
                            <?= htmlspecialchars($ol['parent_email'] ?? '—') ?>
                        </td>
                        <td style="padding:.5rem .85rem">
                            <code style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);border-radius:5px;padding:.15rem .45rem;font-size:.78rem;color:#a9b4d0">
                                <?= htmlspecialchars($ol['event_type']) ?>
                            </code>
                        </td>
                        <td style="padding:.5rem .85rem;color:#6b7494;white-space:nowrap;font-size:.8rem">
                            <?= htmlspecialchars($ol['created_at']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($optOutLog)): ?>
                    <tr>
                        <td colspan="3" style="padding:2rem;text-align:center;color:#4a5270;font-size:.88rem">
                            <i class="fa-solid fa-inbox" style="font-size:1.4rem;display:block;margin-bottom:.5rem;opacity:.4"></i>
                            Δεν υπάρχουν εγγραφές opt-out.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /grid -->
</div><!-- /tab-opt_out -->

<style>
@media (max-width: 700px) {
    #tab-opt_out > div[style*="grid"] {
        grid-template-columns: 1fr !important;
    }
}
</style>
</div><!-- /main-content -->
</div><!-- /app-layout -->

<!-- ══ DELETE ACCOUNT MODAL ══ -->
<div id="deleteAccountModal" class="simple-modal-overlay"
     onclick="if(event.target===this)closeModal('deleteAccountModal')"
     style="z-index:10600">
<div class="simple-modal-box" style="border-color:rgba(230,57,70,.4);max-width:460px">
    <div class="simple-modal-head" style="background:#1a0505;border-bottom:1px solid rgba(230,57,70,.25)">
        <div style="font-size:1rem;font-weight:800;color:#e63946;display:flex;align-items:center;gap:.5rem">
            <i class="fa-solid fa-skull-crossbones"></i> Αίτημα Διαγραφής Λογαριασμού
        </div>
        <button class="modal-close-btn" onclick="closeModal('deleteAccountModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="simple-modal-body">

        <div style="background:rgba(230,57,70,.1);border:1.5px solid rgba(230,57,70,.35);border-radius:14px;padding:1rem 1.1rem;margin-bottom:1.1rem">
            <div style="font-size:.95rem;font-weight:800;color:#ff8a8a;margin-bottom:.4rem;display:flex;align-items:center;gap:.5rem">
                <i class="fa-solid fa-triangle-exclamation"></i> Προσοχή — Μη αναστρέψιμη ενέργεια
            </div>
            <ul style="margin:.3rem 0 0;padding:0 0 0 1.2rem;color:#fca5a5;font-size:.87rem;line-height:1.8">
                <li>Θα διαγραφούν <strong>όλα τα δεδομένα</strong> της σχολής σας</li>
                <li>Πληρωμές, αθλητές, εξετάσεις — <strong>δεν ανακτώνται</strong></li>
                <li>Το αίτημα θα σταλεί στον διαχειριστή για επεξεργασία</li>
                <li>Θα επικοινωνήσουμε μαζί σας πριν την οριστική διαγραφή</li>
            </ul>
        </div>

        <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:.85rem 1rem;margin-bottom:1rem;font-size:.88rem;color:#8892b0;line-height:1.7">
            <div style="display:flex;gap:.5rem;margin-bottom:.2rem">
                <i class="fa-solid fa-school" style="color:#6b7494;flex-shrink:0;margin-top:.1rem"></i>
                <span>Σχολή: <strong style="color:#e2e8f0"><?= h($school['name'] ?? '—') ?></strong></span>
            </div>
            <div style="display:flex;gap:.5rem">
                <i class="fa-solid fa-envelope" style="color:#6b7494;flex-shrink:0;margin-top:.1rem"></i>
                <span>Email: <strong style="color:#e2e8f0"><?= h($user['email'] ?? '—') ?></strong></span>
            </div>
        </div>

        <form method="POST" id="deleteAccountForm">
            <input type="hidden" name="_action" value="request_delete_account">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

            <div style="margin-bottom:1rem">
                <label style="display:block;font-size:.9rem;font-weight:700;color:#e2e8f0;margin-bottom:.4rem">
                    <i class="fa-solid fa-key" style="color:#e63946;margin-right:.3rem"></i>
                    Επαλήθευση — Εισάγετε τον κωδικό σας
                </label>
                <div class="pw-wrap">
                    <input type="password" name="confirm_password_delete" id="pw_delete_confirm"
                           class="form-control"
                           style="min-height:46px;background:#0d1017;border-color:rgba(230,57,70,.3)"
                           required placeholder="Κωδικός λογαριασμού">
                    <button type="button" class="pw-eye" onclick="togglePw('pw_delete_confirm',this)" tabindex="-1"><i class="fa-solid fa-eye"></i></button>
                </div>
            </div>

            <div style="margin-bottom:1rem">
                <label style="display:block;font-size:.88rem;font-weight:700;color:#8892b0;margin-bottom:.4rem">
                    Πληκτρολογήστε <strong style="color:#e63946;font-family:monospace">ΔΙΑΓΡΑΦΗ</strong> για επιβεβαίωση
                </label>
                <input type="text" id="deleteConfirmText"
                       class="form-control"
                       style="min-height:46px;background:#0d1017;border-color:rgba(230,57,70,.3);font-family:monospace;letter-spacing:.05em"
                       placeholder="ΔΙΑΓΡΑΦΗ"
                       oninput="checkDeleteConfirm()">
            </div>

            <div class="modal-btn-row">
                <button type="button" class="modal-cancel" onclick="closeModal('deleteAccountModal')">
                    <i class="fa-solid fa-xmark"></i> Ακύρωση
                </button>
                <button type="submit" id="deleteAccountSubmitBtn"
                        class="modal-confirm"
                        disabled
                        style="background:rgba(230,57,70,.2);color:#9b4040;cursor:not-allowed;transition:all .2s"
                        title="Πληκτρολογήστε ΔΙΑΓΡΑΦΗ για να ενεργοποιηθεί το κουμπί">
                    <i class="fa-solid fa-trash-can"></i> Αποστολή Αιτήματος
                </button>
            </div>
        </form>

    </div>
</div>
</div>

<!-- 2FA Disable Confirmation Modal -->
<div id="disable2faModal" class="simple-modal-overlay" style="z-index:10500" onclick="if(event.target===this)closeDisable2faModal()">
<div class="simple-modal-box" style="max-width:420px">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem;border-bottom:1px solid var(--border,#1e2536)">
        <div style="font-size:1.05rem;font-weight:800;color:var(--red,#e63946);display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-shield-xmark"></i> Απενεργοποίηση 2FA</div>
        <button onclick="closeDisable2faModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:34px;height:34px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div style="padding:1rem">
        <p style="margin:0 0 1rem;font-size:.95rem;color:var(--text,#e2e8f0)">Γράψτε τον κωδικό σας:</p>
        <form method="POST" id="disable2faForm">
            <input type="hidden" name="_action" value="2fa_disable">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="password" name="confirm_password" placeholder="Κωδικός λογαριασμού" required style="width:100%;padding:.65rem 1rem;border-radius:10px;border:1px solid var(--border,#1e2536);background:var(--input-bg,#0f1219);color:var(--text,#e2e8f0);font-size:.95rem;margin-bottom:1rem">
            <div style="display:flex;gap:.6rem;justify-content:flex-end;flex-wrap:wrap">
                <button type="button" onclick="closeDisable2faModal()" style="min-height:38px;font-size:.9rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)">Ακύρωση</button>
                <button type="submit" style="min-height:38px;font-size:.9rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:none;background:var(--red,#e63946);color:#fff"><i class="fa-solid fa-shield-xmark"></i> Απενεργοποίηση</button>
            </div>
        </form>
    </div>
</div>
</div>

<script>
(function(){
    document.querySelectorAll('.topbar').forEach(function(el){
        var pos=window.getComputedStyle(el).position;
        if(pos==='fixed'||pos==='sticky'){
            el.style.setProperty('position','relative','important');
            el.style.setProperty('top','auto','important');
        }
    });
})();

(function(){
    var sidebar=document.getElementById('sidebar'),
        overlay=document.getElementById('dm-overlay'),
        menuBtn=document.getElementById('menuBtn');
    if(!sidebar||!menuBtn)return;

    function open(){
        sidebar.classList.add('open');
        overlay&&overlay.classList.add('on');
        document.body.style.overflow='hidden';
    }
    function close(){
        sidebar.classList.remove('open');
        overlay&&overlay.classList.remove('on');
        document.body.style.overflow='';
    }

    menuBtn.onclick=function(e){
        e.stopPropagation();
        sidebar.classList.contains('open')?close():open();
    };
    overlay&&overlay.addEventListener('click',close);
    sidebar.querySelectorAll('a.nav-item').forEach(function(link){
        link.addEventListener('click',function(){
            if(window.innerWidth<=900)setTimeout(close,80);
        });
    });
    document.addEventListener('keydown',function(e){
        if(e.key==='Escape')close();
    });
    window.addEventListener('resize',function(){
        if(window.innerWidth>900){
            sidebar.classList.remove('open');
            overlay&&overlay.classList.remove('on');
            document.body.style.overflow='';
        }
    });
})();

function togglePw(id, btn) {
    var input = document.getElementById(id);
    if (!input) return;
    var icon = btn ? btn.querySelector('i') : null;

    if (input.type === 'password') {
        input.type = 'text';
        if (icon) icon.className = 'fa-solid fa-eye-slash';
    } else {
        input.type = 'password';
        if (icon) icon.className = 'fa-solid fa-eye';
    }
}

function switchTab(tabName, el) {
    var panels = ['school', 'account', 'security', 'subscription'];
    if (document.getElementById('tab-privacy')) {
        panels.push('privacy');
    }

    panels.forEach(function(name) {
        var panel = document.getElementById('tab-' + name);
        if (panel) panel.style.display = 'none';
    });

    var activePanel = document.getElementById('tab-' + tabName);
    if (activePanel) activePanel.style.display = 'block';

    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });

    if (el) {
        el.classList.add('active');
    } else {
        document.querySelectorAll('.tab-btn').forEach(function(btn) {
            if ((btn.dataset.tab || '') === tabName) {
                btn.classList.add('active');
            }
        });
    }

    var url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url.toString());
}

document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var tabName = params.get('tab') || window.location.hash.replace('#', '') || 'school';

    if (!document.getElementById('tab-' + tabName)) {
        tabName = 'school';
    }

    switchTab(tabName, null);
});

function copyAnimated(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var text = el.textContent.trim();

    navigator.clipboard.writeText(text).then(function() {
        var origHTML  = btn.innerHTML;
        var origBg    = btn.style.background;
        var origBorder= btn.style.borderColor;
        var origColor = btn.style.color;

        btn.innerHTML = '<i class="fa-solid fa-check"></i> Αντιγράφηκε!';
        btn.style.background    = 'rgba(45,198,83,.22)';
        btn.style.borderColor   = 'rgba(45,198,83,.6)';
        btn.style.color         = '#2dc653';
        btn.classList.add('copy-btn-success');

        setTimeout(function() {
            btn.innerHTML       = origHTML;
            btn.style.background  = origBg;
            btn.style.borderColor = origBorder;
            btn.style.color       = origColor;
            btn.classList.remove('copy-btn-success');
        }, 2000);
    }).catch(function() {
        var range = document.createRange();
        range.selectNode(el);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        document.execCommand('copy');
        window.getSelection().removeAllRanges();

        var origHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Αντιγράφηκε!';
        btn.style.color = '#2dc653';
        setTimeout(function(){ btn.innerHTML = origHTML; btn.style.color = ''; }, 2000);
    });
}

function showDisable2FAModal(e){e.preventDefault();openModal('disable2faModal');return false;}
function closeDisable2faModal(){closeModal('disable2faModal');}

function openModal(id){
    var m=document.getElementById(id);
    if(!m)return;
    m.style.display='flex';
    document.body.style.overflow='hidden';
    requestAnimationFrame(function(){
        requestAnimationFrame(function(){
            m.classList.add('modal-visible');
        });
    });
}
function closeModal(id){
    var m=document.getElementById(id);
    if(!m)return;
    m.classList.add('modal-hiding');
    m.classList.remove('modal-visible');
    m.style.opacity='0';
    setTimeout(function(){
        m.style.display='none';
        m.classList.remove('modal-hiding');
        m.style.opacity='';
        document.body.style.overflow='';
    },220);
}

function checkDeleteConfirm() {
    var input = document.getElementById('deleteConfirmText');
    var btn   = document.getElementById('deleteAccountSubmitBtn');
    if (!input || !btn) return;

    var isMatch = input.value.trim() === 'ΔΙΑΓΡΑΦΗ';

    btn.disabled = !isMatch;
    if (isMatch) {
        btn.style.background  = '#e63946';
        btn.style.color       = '#fff';
        btn.style.cursor      = 'pointer';
        btn.title             = '';
    } else {
        btn.style.background  = 'rgba(230,57,70,.2)';
        btn.style.color       = '#9b4040';
        btn.style.cursor      = 'not-allowed';
        btn.title             = 'Πληκτρολογήστε ΔΙΑΓΡΑΦΗ για να ενεργοποιηθεί το κουμπί';
    }
}
</script>
</body>
</html>
