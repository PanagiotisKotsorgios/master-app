<?php

/**
 * ============================================================
 * includes/mailer.php — Email & SMS Αποστολή
 * ============================================================
 * PURPOSE:
 *   Κεντρικές functions για αποστολή email (Brevo API) και
 *   SMS (bulker.gr API). Χρησιμοποιείται από:
 *   - cron/reminders.php (αυτόματες ειδοποιήσεις)
 *   - pages/notifications.php (manual αποστολή)
 *   - register.php (welcome email)
 *   - forgot-password.php (reset email)
 *   - includes/two_factor.php (OTP email)
 *
 * SECURITY:
 *   ✓ FILTER_VALIDATE_EMAIL πριν κάθε αποστολή
 *   ✓ API keys: getSetting() από ΒΔ, ποτέ hardcoded
 *   ✓ cURL TLS: CURLOPT_SSL_VERIFYPEER = true
 *   ✓ Timeout: 15s για αποφυγή hanging requests
 *   ✓ Error logging: κάθε αποτυχία καταγράφεται
 *   ✓ to/from validation: αποτρέπει header injection
 *   ✓ logMessageUsage(): usage tracking για rate limiting
 *
 * FUNCTIONS:
 *   sendEmail(to, subject, html, text, name) → bool
 *   sendSms(phone, message) → bool   [bulker.gr]
 *   send2FAOtpEmail(userId, email, name) → bool
 * ============================================================
 */

function sendEmail(
    string $toEmail,
    string $subject,
    string $htmlBody,
    string $textBody = '',
    string $toName   = '',
    ?string &$debug  = null,
    ?string $fromEmailOverride = null,
    ?string $fromNameOverride  = null,
    ?string $replyToEmail      = null,
    ?string $replyToName       = null
): bool {

    $apiKey = function_exists('getBrevoApiKey')
        ? getBrevoApiKey()
        : (defined('BREVO_API_KEY') ? BREVO_API_KEY : '');

    if (!$apiKey) {
        $debug = 'Missing Brevo API key';
        error_log('[MAster mailer] sendEmail: Brevo API key not configured');
        return false;
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $debug = "Invalid email: $toEmail";
        error_log("[MAster mailer] sendEmail: invalid address '$toEmail'");
        return false;
    }

    if (!$textBody) {
        $textBody = trim(strip_tags(
            str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</tr>'], "\n", $htmlBody)
        ));
    }

    $defaultFromName  = function_exists('getMailFromName')
        ? getMailFromName()
        : (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'MAster');

    $defaultFromEmail = function_exists('getMailFromEmail')
        ? getMailFromEmail()
        : (defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'noreply@master-app.gr');

    $fromName  = trim($fromNameOverride ?? '')  ?: $defaultFromName;
    $fromEmail = trim($fromEmailOverride ?? '') ?: $defaultFromEmail;

    $resolvedToName = trim($toName) ?: strstr($toEmail, '@', true);

    $replyToEmail = trim($replyToEmail ?? '');
    $replyToName  = trim($replyToName  ?? '');

    $payload = [
        'sender'      => ['name' => $fromName, 'email' => $fromEmail],
        'to'          => [['email' => $toEmail, 'name' => $resolvedToName]],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
        'textContent' => $textBody,
    ];

    if ($replyToEmail && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        $payload['replyTo'] = [
            'email' => $replyToEmail,
            'name'  => $replyToName ?: $fromName,
        ];
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $debug = "CURL error: $curlErr";
        error_log("[MAster mailer] sendEmail curl error: $curlErr");
        // Track failed send
        if (function_exists('logMessageUsage') && function_exists('schoolId')) {
            logMessageUsage(schoolId(), userId(), 'email', $toEmail, $subject, 'failed');
        }
        return false;
    }

    $debug = "HTTP $httpCode | $response";

    if ($httpCode !== 201) {
        error_log("[MAster mailer] sendEmail Brevo rejected ($httpCode): $response — to=$toEmail");
        // Track failed send
        if (function_exists('logMessageUsage') && function_exists('schoolId')) {
            logMessageUsage(schoolId(), userId(), 'email', $toEmail, $subject, 'failed');
        }
        return false;
    }

    // Track successful send
    if (function_exists('logMessageUsage') && function_exists('schoolId')) {
        logMessageUsage(schoolId(), userId(), 'email', $toEmail, $subject, 'sent');
    }

    return true;
}


// =============================================================================
// 2) SEND SMS — bulker.gr HTTP API
// =============================================================================

/**
 * Transliterate Greek characters to their Latin equivalents.
 * SMS sender names only support GSM-7 (basic Latin); Greek letters
 * sent as a sender ID get garbled or silently dropped by carriers.
 */
function transliterateGreekToLatin(string $text): string
{
    $map = [
        // Uppercase
        'Α'=>'A','Β'=>'B','Γ'=>'G','Δ'=>'D','Ε'=>'E','Ζ'=>'Z','Η'=>'I',
        'Θ'=>'TH','Ι'=>'I','Κ'=>'K','Λ'=>'L','Μ'=>'M','Ν'=>'N','Ξ'=>'X',
        'Ο'=>'O','Π'=>'P','Ρ'=>'R','Σ'=>'S','Τ'=>'T','Υ'=>'Y','Φ'=>'F',
        'Χ'=>'CH','Ψ'=>'PS','Ω'=>'O',
        // Uppercase with accents/diacritics
        'Ά'=>'A','Έ'=>'E','Ή'=>'I','Ί'=>'I','Ό'=>'O','Ύ'=>'Y','Ώ'=>'O',
        'Ϊ'=>'I','Ϋ'=>'Y',
        // Lowercase
        'α'=>'a','β'=>'b','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i',
        'θ'=>'th','ι'=>'i','κ'=>'k','λ'=>'l','μ'=>'m','ν'=>'n','ξ'=>'x',
        'ο'=>'o','π'=>'p','ρ'=>'r','σ'=>'s','ς'=>'s','τ'=>'t','υ'=>'y',
        'φ'=>'f','χ'=>'ch','ψ'=>'ps','ω'=>'o',
        // Lowercase with accents/diacritics
        'ά'=>'a','έ'=>'e','ή'=>'i','ί'=>'i','ό'=>'o','ύ'=>'y','ώ'=>'o',
        'ϊ'=>'i','ϋ'=>'y','ΐ'=>'i','ΰ'=>'y',
    ];

    // Replace Greek letters
    $result = strtr($text, $map);

    // Remove any remaining non-GSM-7 characters (keep alphanumeric, spaces, hyphens)
    $result = preg_replace('/[^A-Za-z0-9\-_ ]/', '', $result);

    // Collapse multiple spaces/hyphens and trim
    $result = trim(preg_replace('/\s+/', ' ', $result));

    return $result;
}

/**
 * sendSms() — αποστολή SMS μέσω bulker.gr HTTP API v1.2
 *
 * Παράμετροι API (βάσει official doc):
 *   auth_key : string  — authentication key (υποχρεωτικό)
 *   id       : numeric — μοναδικός αριθμητικός ID ανά SMS (υποχρεωτικό)
 *   from     : string  — αποστολέας: numeric ≤15 ψηφία ή alphanumeric ≤11 chars
 *   to       : numeric — αποδέκτης με country code χωρίς + (π.χ. 306912345678)
 *   text     : string  — κείμενο μηνύματος UTF-8
 *   coding   : 0|1     — 0=GSM-7 (default), 1=Unicode (απαιτείται για ελληνικά/emoji)
 *
 * ΣΗΜΑΝΤΙΚΟ: Ο αποστολέας (from) πρέπει να είναι authorized στο bulker.gr λογαριασμό.
 */
function sendSms(
    string $phone,
    string $message,
    ?string &$error = null,
    string $senderOverride = '',
    ?string $authKeyOverride = null,
    ?string $profileIdOverride = null,  // kept for BC but not sent to API (pid is for child accounts only)
    ?string $senderCredOverride = null
): bool {
    // ── 1. Κανονικοποίηση αριθμού παραλήπτη ───────────────────────────────
    // bulker.gr: μόνο ψηφία, με country code χωρίς + ή 00
    $phone = preg_replace('/[^\d]/', '', $phone);
    if (preg_match('/^69\d{8}$/', $phone))              $phone = '30' . $phone;
    elseif (preg_match('/^0030(6\d{9})$/', $phone, $m)) $phone = '30' . $m[1];
    elseif (str_starts_with($phone, '00'))              $phone = substr($phone, 2);

    if (!preg_match('/^\d{10,15}$/', $phone)) {
        $error = "Μη έγκυρος αριθμός παραλήπτη: " . htmlspecialchars($phone);
        error_log("[MAster SMS] Invalid destination phone after normalization: '$phone'");
        return false;
    }

    // ── 2. Credentials ─────────────────────────────────────────────────────
    $authKey = ($authKeyOverride !== null && $authKeyOverride !== '')
        ? $authKeyOverride
        : (function_exists('getBulkerAuthKey') ? getBulkerAuthKey() : '');

    $defaultSender = ($senderCredOverride !== null && $senderCredOverride !== '')
        ? $senderCredOverride
        : (function_exists('getBulkerSender') ? getBulkerSender() : '');

    if (!$authKey) {
        $error = 'bulker.gr Auth Key δεν έχει οριστεί στις ρυθμίσεις SMS.';
        error_log('[MAster SMS] sendSms: auth_key not configured');
        return false;
    }

    // ── 3. Κανονικοποίηση Sender ───────────────────────────────────────────
    // senderOverride (π.χ. school name) → alphanumeric max 11 chars
    // defaultSender (από ρυθμίσεις) → χρησιμοποιείται ως έχει (numeric ή alphanumeric)
    if ($senderOverride !== '') {
        // Per-call override (e.g. school name) → always alphanumeric
        $sender = mb_substr(transliterateGreekToLatin($senderOverride), 0, 11);
    } else {
        $rawSender = trim($defaultSender);
        $numericOnly = preg_replace('/[^\d]/', '', $rawSender);

        if ($numericOnly === $rawSender && strlen($numericOnly) >= 4) {
            // Αριθμητικός sender (τηλέφωνο) — max 15 ψηφία, ΔΕΝ αλλάζουμε format
            // Ο χρήστης πρέπει να εισάγει τον αριθμό ΑΚΡΙΒΩΣ όπως τον έχει authorized στο bulker.gr
            $sender = substr($numericOnly, 0, 15);
        } else {
            // Αλφαριθμητικός sender ID — transliterate + max 11 chars
            $sender = mb_substr(transliterateGreekToLatin($rawSender), 0, 11);
        }
    }

    if (!$sender) {
        $error = 'bulker.gr Sender δεν έχει οριστεί ή είναι άκυρο.';
        error_log('[MAster SMS] sendSms: sender is empty after normalization');
        return false;
    }

    // ── 4. Encoding detection ──────────────────────────────────────────────
    // Ελληνικά, emoji, ή οποιοδήποτε non-GSM-7 χαρακτήρας → coding=1 (Unicode)
    // Χωρίς coding=1 τα ελληνικά εμφανίζονται ως σκουπίδια στο τελικό SMS
    $needsUnicode = (bool) preg_match('/[^\x00-\x7F]|[€\[\]\\\\^{}|~]/u', $message);
    $coding = $needsUnicode ? 1 : 0;

    // Max χαρακτήρες: GSM-7: 153/segment × 10 = 1530, Unicode: 67/segment × 10 = 670
    // Δεν κόβουμε τεχνητά — το bulker.gr χρεώνει ανά segment αλλά στέλνει πλήρες μήνυμα
    $maxChars = $coding === 1 ? 670 : 1530;
    $text = mb_substr($message, 0, $maxChars);

    // ── 5. Numeric message ID (API requires numeric, NOT alphanumeric) ──────
    // uniqid() παράγει αλφαριθμητικό → API επιστρέφει "id is null" ή "id is not a number"
    // Χρησιμοποιούμε microseconds * 10 + random 0-9 για μοναδικότητα χωρίς overflow
    $msgId = (string) ((intval(microtime(true) * 10) % 2000000000) + mt_rand(0, 9));

    // ── 6. Build & execute request ─────────────────────────────────────────
    $params = [
        'auth_key' => $authKey,
        'id'       => $msgId,
        'from'     => $sender,
        'to'       => $phone,
        'text'     => $text,
    ];
    if ($coding === 1) $params['coding'] = 1;

    $url = 'http://api.bulker.gr/http/sms.php?' . http_build_query($params);

    // Log request (auth_key μερικώς κρυφό)
    $logParams = $params;
    $logParams['auth_key'] = substr($authKey, 0, 6) . '***';
    error_log('[MAster SMS] Sending: ' . http_build_query($logParams));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        $error = "Σφάλμα σύνδεσης CURL: $curlErr";
        error_log("[MAster SMS] CURL error: $curlErr | to=$phone");
        return false;
    }

    $response = trim((string) $response);
    error_log("[MAster SMS] Response HTTP=$httpCode: '$response' | to=$phone from=$sender id=$msgId coding=$coding");

    // ── 7. Parse response: "OK;id;charge" ή "ERROR: message;id;0" ──────────
    if (stripos($response, 'OK') === 0) {
        if (function_exists('logMessageUsage') && function_exists('schoolId')) {
            logMessageUsage(schoolId(), userId(), 'sms', $phone, 'SMS', 'sent');
        }
        return true;
    }

    if (stripos($response, 'ERROR') === 0) {
        $errPart = trim(explode(';', $response)[0]);
        $errMsg  = trim(preg_replace('/^ERROR:\s*/i', '', $errPart));
        $error   = "bulker.gr: $errMsg";
        error_log("[MAster SMS] API Error: '$errMsg' | to=$phone from=$sender id=$msgId | full_response='$response'");
        if (function_exists('logMessageUsage') && function_exists('schoolId')) {
            logMessageUsage(schoolId(), userId(), 'sms', $phone, 'SMS', 'failed');
        }
        return false;
    }

    // Απρόσμενη/κενή απόκριση
    $preview = $response !== '' ? mb_substr($response, 0, 200) : '(κενή απόκριση)';
    $error   = "Απρόσμενη απόκριση bulker.gr (HTTP $httpCode): $preview";
    error_log("[MAster SMS] Unexpected response HTTP=$httpCode: '$response' | to=$phone from=$sender");
    if (function_exists('logMessageUsage') && function_exists('schoolId')) {
        logMessageUsage(schoolId(), userId(), 'sms', $phone, 'SMS', 'failed');
    }
    return false;
}


// =============================================================================
// 3) PLACEHOLDERS (shared)
// =============================================================================

function buildReminderPlaceholders(array $athlete, array $sub, string $schoolName): array
{
    // ── Υπολογισμός ποσού: προτεραιότητα στο debt_months × monthly_amount ──
    $amount = (float)($sub['amount'] ?? 0);

    // Αν έχουμε monthly_amount + debt_months → σωστό σύνολο
    if (!empty($sub['monthly_amount']) && !empty($sub['debt_months'])) {
        $amount = (float)$sub['monthly_amount'] * (int)$sub['debt_months'];
    }

    // Αν έχουμε total_debt απευθείας
    if (!empty($sub['total_debt'])) {
        $amount = (float)$sub['total_debt'];
    }

    // Debt months label (π.χ. "3 μήνες")
    $debtMonths = (int)($sub['debt_months'] ?? 0);
    $debtLabel  = $debtMonths > 0
        ? $debtMonths . ' ' . ($debtMonths === 1 ? 'μήνα' : 'μήνες')
        : '';

    $monthlyAmt = (float)($sub['monthly_amount'] ?? 0);

    // Extract last name: assume "Επώνυμο Όνομα" or "Όνομα Επώνυμο" — take full_name as-is for full display;
    // last_name tries to extract the last space-separated word as surname
    $fullName = trim($athlete['full_name'] ?? '');
    $nameParts = preg_split('/\s+/', $fullName);
    $lastName  = count($nameParts) > 1 ? end($nameParts) : $fullName;

    return [
        '{{athlete_name}}'      => $fullName,
        '{{athlete_last_name}}' => $lastName,
        '{{parent_name}}'       => $athlete['father_name'] ?? ($fullName),
        '{{school_name}}'       => $schoolName,
        '{{amount}}'       => number_format($amount, 2, ',', '.'),
        '{{monthly_amount}}' => $monthlyAmt > 0 ? number_format($monthlyAmt, 2, ',', '.') : '',
        '{{debt_months}}'  => $debtLabel,
        '{{valid_until}}'  => !empty($sub['valid_until']) ? date('d/m/Y', strtotime($sub['valid_until'])) : '—',
        '{{valid_from}}'   => !empty($sub['valid_from'])  ? date('d/m/Y', strtotime($sub['valid_from']))  : '—',
        '{{trigger_days}}' => (string)($sub['trigger_days'] ?? ''),
        '{{sport}}'        => function_exists('sportLabel') ? sportLabel($athlete['sport'] ?? '') : ($athlete['sport'] ?? ''),
    ];
}


// =============================================================================
// 4) PAYMENT REMINDER DISPATCHER
// =============================================================================

function sendPaymentReminder(array $athlete, array $sub, string $channel = 'email', array $opts = []): bool
{
    $db  = getDB();
    $sid = (int)($athlete['school_id'] ?? 0);

    $schoolName  = trim($opts['school_name']  ?? '');
    $schoolEmail = trim($opts['school_email'] ?? '');

    if (!$schoolName) {
        $s = $db->prepare("SELECT name, email FROM schools WHERE id=?");
        $s->execute([$sid]);
        $row         = $s->fetch() ?: ['name' => 'MAster', 'email' => null];
        $schoolName  = $row['name']  ?: 'MAster';
        $schoolEmail = $row['email'] ?: '';
    }

    $triggerType = $sub['trigger_type'] ?? 'manual';

    $ruleTypeMap = [
        '3days_before'  => 'days_before',
        'on_expiry'     => 'on_due',
        '5days_after'   => 'days_after',
        'after_payment' => 'after_payment',
        'days_before'   => 'days_before',
        'on_due'        => 'on_due',
        'days_after'    => 'days_after',
        'manual'        => null,
    ];

    $ruleType = $ruleTypeMap[$triggerType] ?? null;
    $rule     = null;

    if ($ruleType) {
        $rStmt = $db->prepare("
            SELECT subject_tpl, body_tpl
            FROM notification_rules
            WHERE school_id=? AND trigger_type=? AND active=1
            ORDER BY id ASC LIMIT 1
        ");
        $rStmt->execute([$sid, $ruleType]);
        $rule = $rStmt->fetch() ?: null;
    }

    // ── Ηλικία αθλητή → adult ή minor ──────────────────────────────────────
    $isAdult = true;
    if (!empty($athlete['birthdate'])) {
        try {
            $isAdult = ((new DateTime())->diff(new DateTime($athlete['birthdate']))->y >= 18);
        } catch (Exception $e) {}
    }

    $ph = buildReminderPlaceholders($athlete, $sub, $schoolName);

    if ($channel === 'email') {
        // Ενήλικος → στέλνουμε στον ίδιο, ανήλικος → κηδεμόνας πρώτα
        $toEmail = $isAdult
            ? (trim($athlete['email'] ?? '') ?: trim($athlete['parent_email'] ?? ''))
            : (trim($athlete['parent_email'] ?? '') ?: trim($athlete['email'] ?? ''));

        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("[MAster mailer] sendPaymentReminder: no valid email for athlete " . ($athlete['id'] ?? '—'));
            return false;
        }

        $subject   = !empty($rule['subject_tpl'])
            ? strtr($rule['subject_tpl'], $ph)
            : buildDefaultSubject($triggerType, $schoolName);

        $plainText = !empty($rule['body_tpl'])
            ? strtr($rule['body_tpl'], $ph)
            : buildDefaultPlainBody($triggerType, $ph, $isAdult);

        $htmlBody = buildEmailHtml($plainText, $schoolName, $subject);

        return sendEmail(
            $toEmail, $subject, $htmlBody, $plainText,
            $athlete['full_name'] ?? '',
            $dbg,
            $schoolEmail ?: null, $schoolName,
            $schoolEmail ?: null, $schoolName
        );
    }

    if ($channel === 'sms') {
        // Ενήλικος → δικό του τηλ., ανήλικος → κηδεμόνας πρώτα
        $phone = $isAdult
            ? (trim($athlete['phone'] ?? '') ?: trim($athlete['parent_phone'] ?? ''))
            : (trim($athlete['parent_phone'] ?? '') ?: trim($athlete['phone'] ?? ''));

        if (!$phone) {
            error_log("[MAster mailer] sendPaymentReminder: no phone for athlete " . ($athlete['id'] ?? '—'));
            return false;
        }

        $smsText = !empty($rule['body_tpl'])
            ? strip_tags(strtr($rule['body_tpl'], $ph))
            : buildDefaultSmsBody($triggerType, $ph, $isAdult);

        return sendSms($phone, $smsText, $error);
    }

    error_log("[MAster mailer] sendPaymentReminder: unknown channel '$channel'");
    return false;
}


// =============================================================================
// 5) DEFAULT CONTENT BUILDERS
// =============================================================================

function buildDefaultSubject(string $triggerType, string $schoolName): string
{
    return match(true) {
        in_array($triggerType, ['3days_before', 'days_before']) => "Υπενθύμιση πληρωμής — {$schoolName}",
        in_array($triggerType, ['on_expiry',    'on_due'])      => "Η συνδρομή σας λήγει σήμερα — {$schoolName}",
        in_array($triggerType, ['5days_after',  'days_after'])  => "Εκκρεμής πληρωμή — {$schoolName}",
        $triggerType === 'after_payment'                        => "Επιβεβαίωση πληρωμής — {$schoolName}",
        default                                                  => "Εκκρεμείς Συνδρομές — {$schoolName}",
    };
}

function buildDefaultPlainBody(string $triggerType, array $ph, bool $isAdult = false): string
{
    $name    = $ph['{{athlete_name}}'];
    $amount  = $ph['{{amount}}'];
    $until   = $ph['{{valid_until}}'];
    $school  = $ph['{{school_name}}'];
    $days    = $ph['{{trigger_days}}'];
    $months  = $ph['{{debt_months}}'];       // π.χ. "3 μήνες"
    $monthly = $ph['{{monthly_amount}}'];    // π.χ. "40,00"

    // Χαιρετισμός ανάλογα με ηλικία
    $greeting = $isAdult
        ? "Αγαπητέ/ή {$name},"
        : "Αγαπητέ/ή κηδεμόνα του/της {$name},";

    $closing = "Παρακαλούμε να τακτοποιηθεί. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{$school}";

    return match(true) {
        in_array($triggerType, ['3days_before', 'days_before']) =>
            "{$greeting}\n\nΜια φιλική υπενθύμιση ότι η συνδρομή του/της {$name} λήγει σε {$days} ημέρες ({$until}).\nΠοσό: {$amount}€\n\nΠαρακαλούμε φροντίστε για την ανανέωση. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{$school}",

        in_array($triggerType, ['on_expiry', 'on_due']) =>
            "{$greeting}\n\nΜια φιλική υπενθύμιση: η συνδρομή του/της {$name} λήγει σήμερα ({$until}).\nΠοσό: {$amount}€\n\nΠαρακαλούμε φροντίστε για την ανανέωση. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{$school}",

        in_array($triggerType, ['5days_after', 'days_after']) =>
            "{$greeting}\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε ότι η συνδρομή του/της {$name} έχει λήξει από τις {$until} ({$days} ημέρες).\nΠοσό: {$amount}€\n\nΠαρακαλούμε να τακτοποιηθεί. Αν χρειαστείτε κάτι είμαστε στην διάθεση σας.\n\nΣας ευχαριστούμε πολύ,\n{$school}",

        $triggerType === 'after_payment' =>
            "{$greeting}\n\nΛάβαμε την πληρωμή σας {$amount}€ για τον/την {$name}.\nΗ συνδρομή ισχύει έως {$until}.\n\nΣας ευχαριστούμε πολύ!\n\n{$school}",

        // ── manual: χρήση debt_months × monthly_amount ──
        default => buildManualReminderBody($greeting, $name, $months, $monthly, $amount, $closing),
    };
}

function buildManualReminderBody(
    string $greeting,
    string $name,
    string $months,
    string $monthly,
    string $total,
    string $closing
): string {
    $lines = [$greeting, ''];

    if ($months) {
        $lines[] = "Υπενθύμιση για εκκρεμείς συνδρομές {$months}.";
    } else {
        $lines[] = "Υπενθύμιση εκκρεμούς συνδρομής.";
    }

    if ($monthly && $months) {
        $lines[] = "Χρέωση: {$monthly}/μήνα × {$months} = {$total}€";
    } elseif ($total && $total !== '0,00') {
        $lines[] = "Συνολικό οφειλόμενο ποσό: {$total}€";
    }

    $lines[] = '';
    $lines[] = $closing;

    return implode("\n", $lines);
}

function buildDefaultSmsBody(string $triggerType, array $ph, bool $isAdult = false): string
{
    $name    = $ph['{{athlete_name}}'];
    $amount  = $ph['{{amount}}'];
    $until   = $ph['{{valid_until}}'];
    $school  = $ph['{{school_name}}'];
    $days    = $ph['{{trigger_days}}'];
    $months  = $ph['{{debt_months}}'];
    $monthly = $ph['{{monthly_amount}}'];

    $stopNote = ' Αποστ. STOP στο 6986788178 για διαγραφή.';

    $msg = match(true) {
        in_array($triggerType, ['3days_before', 'days_before'])
            => "{$school}: Φιλική υπενθύμιση — η συνδρομή του/της {$name} λήγει σε {$days} ημέρες ({$until}). Ποσό: {$amount}€. Ευχαριστούμε!{$stopNote}",

        in_array($triggerType, ['on_expiry', 'on_due'])
            => "{$school}: Φιλική υπενθύμιση — η συνδρομή του/της {$name} λήγει σήμερα ({$until}). Ποσό: {$amount}€. Ευχαριστούμε!{$stopNote}",

        in_array($triggerType, ['5days_after', 'days_after'])
            => "{$school}: Φιλική υπενθύμιση για τη συνδρομή του/της {$name} — ποσό {$amount}€. Παρακαλούμε να τακτοποιηθεί. Ευχαριστούμε!{$stopNote}",

        $triggerType === 'after_payment'
            => "{$school}: Λάβαμε {$amount}€ για {$name}. Ισχύει έως {$until}. Σας ευχαριστούμε πολύ!",

        // ── manual: ηλικία-aware + debt months + σωστό ποσό ──
        default => buildManualSmsBody($name, $months, $monthly, $amount, $school, $isAdult, $stopNote),
    };

    return $msg;
}

function buildManualSmsBody(
    string $name,
    string $months,
    string $monthly,
    string $total,
    string $school,
    bool $isAdult,
    string $stopNote = ''
): string {
    $intro = $isAdult ? "Αγαπητέ/ή {$name}," : "Κηδεμόνα {$name},";

    if ($monthly && $months) {
        $debt = "{$months} x {$monthly}/μηνα = {$total}€";
    } elseif ($total && $total !== '0,00') {
        $debt = "Οφειλή: {$total}€";
    } else {
        $debt = "εκκρεμής συνδρομή";
    }

    return "{$intro} {$debt}. Παρακαλουμε για την εξοφληση σας. - {$school}{$stopNote}";
}


// =============================================================================
// 6) BRANDED HTML WRAPPER
// =============================================================================

function buildEmailHtml(string $plainText, string $schoolName, string $subject, string $unsubscribeUrl = ''): string
{
    $escaped   = nl2br(htmlspecialchars($plainText, ENT_QUOTES, 'UTF-8'));
    $schEsc    = htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8');
    $subEsc    = htmlspecialchars($subject,    ENT_QUOTES, 'UTF-8');
    $year      = date('Y');
    $fromEmail = function_exists('getMailFromEmail')
        ? getMailFromEmail()
        : (defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'noreply@master-app.gr');
    $appUrl    = defined('APP_URL') ? APP_URL : 'https://master-app.gr';
    $logoUrl   = $appUrl . '/assets/img/logo-tr.png';

    $unsubFooter = '';
    if ($unsubscribeUrl) {
        $unsubEsc    = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');
        $unsubFooter = <<<UNSUB
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr>
        <td style="padding:14px 32px;text-align:center">
          <a href="{$unsubEsc}" style="font-size:.72rem;color:#4a5270;text-decoration:underline">Διακοπή ειδοποιήσεων email</a>
        </td>
      </tr>
UNSUB;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$subEsc}</title>
</head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:560px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:28px 32px 24px;text-align:center;border-bottom:2px solid #2a1a50">
          <img src="{$logoUrl}" alt="MAster"
               style="height:64px;width:auto;max-width:200px;object-fit:contain;display:block;margin:0 auto 10px">
          <h1 style="margin:0 0 4px;font-size:1.15rem;letter-spacing:.04em;color:#f0f2ff;font-weight:700">{$schEsc}</h1>
          <p style="margin:0;font-size:.78rem;color:#8892b0;letter-spacing:.04em">Αποστολή μέσω MAster</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.85">{$escaped}</td>
      </tr>
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr>
        <td style="padding:20px 32px;text-align:center;font-size:.74rem;color:#4a5270;line-height:1.7">
          &copy; {$year} Παναγιώτης Κοτσόργιος &nbsp;&middot;&nbsp;
          <a href="mailto:{$fromEmail}" style="color:#6b7494;text-decoration:none">{$fromEmail}</a><br>
          <span style="font-size:.68rem;color:#363d52">Λαμβάνετε αυτό το email ως εγγεγραμμένος γονέας/κηδεμόνας της πλατφόρμας.</span>
        </td>
      </tr>
      {$unsubFooter}
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Returns the one-click email unsubscribe URL for a parent_user identified by email.
 * Returns '' if no token is found (e.g., parent hasn't accepted terms yet).
 */
function getParentUnsubscribeUrl(PDO $db, string $parentEmail, int $schoolId, string $type = 'email'): string
{
    if (!$parentEmail) return '';
    try {
        $stmt = $db->prepare("SELECT unsubscribe_token FROM parent_users WHERE parent_email = ? AND school_id = ? LIMIT 1");
        $stmt->execute([$parentEmail, $schoolId]);
        $token = $stmt->fetchColumn();
        if (!$token) {
            // Auto-generate token for parents who haven't gone through terms yet
            $token = bin2hex(random_bytes(32));
            $db->prepare("UPDATE parent_users SET unsubscribe_token = ? WHERE parent_email = ? AND school_id = ?")
               ->execute([$token, $parentEmail, $schoolId]);
        }
        $appUrl = defined('APP_URL') ? APP_URL : 'https://master-app.gr';
        return $appUrl . '/parent/unsubscribe.php?token=' . urlencode($token) . '&type=' . urlencode($type);
    } catch (\Exception $e) {
        return '';
    }
}

// =============================================================================
// 7) NEW SUBSCRIPTION CONFIRMATION EMAIL
// =============================================================================

/**
 * Αποστέλλει email επιβεβαίωσης πληρωμής συνδρομής.
 * Χειρίζεται όλα τα σενάρια:
 *  - Πλήρης πληρωμή μήνα
 *  - Μερική πληρωμή (υπόλοιπο ποσό εκκρεμεί)
 *  - Υπερπληρωμή (πίστωση επόμενου μήνα)
 *  - Πολλαπλοί μήνες (αν debt_months > 1 στο $sub)
 */
function sendNewSubscriptionEmail(array $athlete, array $sub): bool
{
    $db = getDB();
    $sid = (int)($athlete['school_id'] ?? 0);
    $snStmt = $db->prepare("SELECT name FROM schools WHERE id=? LIMIT 1");
    $snStmt->execute([$sid]);
    $schoolName = $snStmt->fetchColumn() ?: 'MAster';

    // Prefer parent_email for minors, fall back to athlete email
    $toEmail = trim($athlete['parent_email'] ?? '') ?: trim($athlete['email'] ?? '');
    if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $name        = $athlete['full_name'] ?? 'Αθλητής';
    $monthlyFee  = (float)($athlete['monthly_fee'] ?? 0);
    $amountPaid  = (float)($sub['amount'] ?? 0);

    // Πόσοι μήνες καλύπτει αυτή η πληρωμή
    $coveredMonths = ($monthlyFee > 0) ? (int)floor($amountPaid / $monthlyFee) : 0;
    $coveredMonths = max(1, $coveredMonths); // τουλάχιστον 1 μήνας

    // Υπολειπόμενο ποσό για τον τρέχοντα μήνα
    $remaining  = ($monthlyFee > 0) ? fmod($amountPaid, $monthlyFee) : 0.0;
    // Αν πληρώθηκε ακριβώς ή παραπάνω, δεν υπάρχει υπόλοιπο
    $remainder  = ($monthlyFee > 0) ? ($monthlyFee - fmod($amountPaid, $monthlyFee)) : 0.0;
    if ($remainder < 0.005) $remainder = 0.0; // float tolerance

    // Σενάρια
    $isFullyPaid  = ($monthlyFee <= 0 || $remainder < 0.005);
    $isPartial    = (!$isFullyPaid && $amountPaid < $monthlyFee);
    $isOverpaid   = (!$isFullyPaid && $amountPaid > $monthlyFee && $coveredMonths > 1);

    // Ποσά formatted
    $amountFmt    = number_format($amountPaid, 2, ',', '.');
    $monthlyFmt   = number_format($monthlyFee, 2, ',', '.');
    $remainderFmt = number_format($remainder,  2, ',', '.');

    // Greek month name
    $gm_full = ['','Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος',
                'Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
    $monthLabel = '';
    if (!empty($sub['valid_from'])) {
        $mNum  = (int)date('n', strtotime($sub['valid_from']));
        $mYear = date('Y', strtotime($sub['valid_from']));
        $monthLabel = $gm_full[$mNum] . ' ' . $mYear;
    }

    $validFrom  = !empty($sub['valid_from'])  ? date('d/m/Y', strtotime($sub['valid_from']))  : '—';
    $validUntil = !empty($sub['valid_until']) ? date('d/m/Y', strtotime($sub['valid_until'])) : '—';

    // ── Δόμηση subject ──────────────────────────────────────────────────────
    $subject = $isPartial
        ? "Μερική Καταχώρηση Πληρωμής — $schoolName"
        : "Καταχώρηση Πληρωμής — $schoolName";

    // ── Plain text ──────────────────────────────────────────────────────────
    $bodyText  = "Αγαπητέ/ή,\n\n";
    $bodyText .= "Καταχωρήθηκε πληρωμή για τον/την $name.\n\n";
    $bodyText .= "━━━━━━━━━━━━━━━━━━━━━\n";
    if ($monthLabel)   $bodyText .= "Μήνας           : $monthLabel\n";
    $bodyText .= "Ισχύει από      : $validFrom\n";
    $bodyText .= "Ισχύει έως      : $validUntil\n";
    if ($monthlyFee > 0) $bodyText .= "Μηνιαία χρέωση : €$monthlyFmt\n";
    $bodyText .= "Ποσό πληρωμής  : €$amountFmt\n";

    if ($isPartial) {
        $bodyText .= "Υπόλοιπο μήνα  : €$remainderFmt  ← ΕΚΚΡΕΜΕΙ\n";
    } elseif ($isOverpaid) {
        $bodyText .= "Μήνες που καλύφθηκαν: $coveredMonths\n";
    }
    $bodyText .= "━━━━━━━━━━━━━━━━━━━━━\n\n";

    if ($isPartial) {
        $bodyText .= "⚠ Η πληρωμή καλύπτει μερικώς τον μήνα. "
            . "Το υπόλοιπο €$remainderFmt παραμένει οφειλόμενο.\n\n";
    } elseif ($isOverpaid) {
        $bodyText .= "✓ Η πληρωμή κάλυψε $coveredMonths μήνες.\n\n";
    } else {
        $bodyText .= "✓ Ο μήνας έχει εξοφληθεί πλήρως.\n\n";
    }

    $bodyText .= "Ευχαριστούμε!\n\n— $schoolName";

    // ── HTML ────────────────────────────────────────────────────────────────
    $schEsc    = htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8');
    $nameEsc   = htmlspecialchars($name,       ENT_QUOTES, 'UTF-8');
    $appUrl    = defined('APP_URL') ? APP_URL : 'https://master-app.gr';
    $logoUrl   = $appUrl . '/assets/img/logo-tr.png';
    $fromEmail = function_exists('getMailFromEmail') ? getMailFromEmail() : 'noreply@master-app.gr';
    $year      = date('Y');

    // Γραμμή μηνιαίας χρέωσης (εμφανίζεται μόνο αν έχει οριστεί)
    $monthlyRow = ($monthlyFee > 0) ? "
                  <tr>
                    <td style=\"color:#6b7494;font-size:.88rem;padding:5px 0;white-space:nowrap;padding-right:20px\">Μηνιαία χρέωση:</td>
                    <td style=\"color:#f0f2ff;font-size:.88rem;padding:5px 0\">€{$monthlyFmt}</td>
                  </tr>" : '';

    // Γραμμή μήνα
    $monthRow = $monthLabel ? "
                  <tr>
                    <td style=\"color:#6b7494;font-size:.88rem;padding:5px 0;white-space:nowrap;padding-right:20px\">Μήνας:</td>
                    <td style=\"color:#f0f2ff;font-size:.88rem;font-weight:700;padding:5px 0\">{$monthLabel}</td>
                  </tr>" : '';

    // Γραμμή αποτελέσματος (κάτω από ποσό)
    if ($isPartial) {
        $statusRow = "
                  <tr>
                    <td style=\"color:#6b7494;font-size:.88rem;padding:5px 0;white-space:nowrap;padding-right:20px\">Υπόλοιπο μήνα:</td>
                    <td style=\"color:#f0a500;font-size:.95rem;font-weight:800;padding:5px 0\">€{$remainderFmt} <span style=\"font-size:.75rem;opacity:.8\">(εκκρεμεί)</span></td>
                  </tr>";
    } elseif ($isOverpaid) {
        $statusRow = "
                  <tr>
                    <td style=\"color:#6b7494;font-size:.88rem;padding:5px 0;white-space:nowrap;padding-right:20px\">Μήνες που καλύφθηκαν:</td>
                    <td style=\"color:#2dc653;font-size:.88rem;font-weight:700;padding:5px 0\">{$coveredMonths} μήνες</td>
                  </tr>";
    } else {
        $statusRow = "
                  <tr>
                    <td colspan=\"2\" style=\"color:#2dc653;font-size:.88rem;font-weight:700;padding:8px 0 4px\">✓ Ο μήνας εξοφλήθηκε πλήρως</td>
                  </tr>";
    }

    // Alert box κάτω από τον πίνακα
    if ($isPartial) {
        $alertBox = "
          <div style=\"background:rgba(240,165,0,.08);border:1px solid rgba(240,165,0,.35);border-radius:10px;padding:16px 20px;margin-bottom:20px;font-size:.9rem;color:#f0a500;line-height:1.7\">
            <strong style=\"display:block;margin-bottom:4px\">⚠ Μερική Πληρωμή</strong>
            Η πληρωμή €{$amountFmt} καλύπτει μερικώς τον μήνα. Το υπόλοιπο <strong>€{$remainderFmt}</strong> παραμένει οφειλόμενο στη σχολή.
          </div>";
    } elseif ($isOverpaid) {
        $alertBox = "
          <div style=\"background:rgba(45,198,83,.07);border:1px solid rgba(45,198,83,.25);border-radius:10px;padding:16px 20px;margin-bottom:20px;font-size:.9rem;color:#2dc653;line-height:1.7\">
            ✓ Η πληρωμή κάλυψε <strong>{$coveredMonths} μήνες</strong> συνδρομής. Ευχαριστούμε!
          </div>";
    } else {
        $alertBox = "
          <div style=\"background:rgba(45,198,83,.07);border:1px solid rgba(45,198,83,.25);border-radius:10px;padding:16px 20px;margin-bottom:20px;font-size:.9rem;color:#2dc653;line-height:1.7\">
            ✓ Ο μήνας <strong>" . htmlspecialchars($monthLabel ?: 'συνδρομής', ENT_QUOTES, 'UTF-8') . "</strong> εξοφλήθηκε πλήρως. Ευχαριστούμε!
          </div>";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:580px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">

      <!-- HEADER -->
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:28px 32px 24px;text-align:center;border-bottom:2px solid #2a1a50">
          <img src="{$logoUrl}" alt="MAster"
               style="height:56px;width:auto;max-width:180px;object-fit:contain;display:block;margin:0 auto 12px">
          <h1 style="margin:0 0 4px;font-size:1.1rem;letter-spacing:.04em;color:#f0f2ff;font-weight:700">{$schEsc}</h1>
          <p style="margin:0;font-size:.75rem;color:#8892b0;letter-spacing:.05em;text-transform:uppercase">Επιβεβαίωση Πληρωμής Συνδρομής</p>
        </td>
      </tr>

      <!-- BODY -->
      <tr>
        <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.8">

          <!-- Intro -->
          <p style="margin:0 0 22px;font-size:.96rem">
            Αγαπητέ/ή,<br>
            Καταχωρήθηκε πληρωμή για τον/την <strong style="color:#f0f2ff">{$nameEsc}</strong>.
          </p>

          <!-- Payment details card -->
          <table width="100%" cellpadding="0" cellspacing="0"
                 style="background:#0d1117;border:1px solid #1e2536;border-radius:12px;margin-bottom:22px">
            <tr>
              <td style="padding:20px 24px">
                <p style="margin:0 0 14px;font-size:.72rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#6b7494">
                  Στοιχεία Πληρωμής
                </p>
                <table cellpadding="0" cellspacing="0" width="100%">
                  {$monthRow}
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:5px 0;white-space:nowrap;padding-right:20px">Ισχύει από:</td>
                    <td style="color:#f0f2ff;font-size:.88rem;padding:5px 0">{$validFrom}</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:5px 0;white-space:nowrap;padding-right:20px">Ισχύει έως:</td>
                    <td style="color:#f0f2ff;font-size:.88rem;padding:5px 0">{$validUntil}</td>
                  </tr>
                  {$monthlyRow}
                  <tr>
                    <td style="border-top:1px solid #1e2536;padding-top:10px;color:#6b7494;font-size:.88rem;padding:10px 0 5px;white-space:nowrap;padding-right:20px">Ποσό πληρωμής:</td>
                    <td style="border-top:1px solid #1e2536;padding-top:10px;color:#2dc653;font-size:1.05rem;font-weight:800;padding:10px 0 5px">€{$amountFmt}</td>
                  </tr>
                  {$statusRow}
                </table>
              </td>
            </tr>
          </table>

          <!-- Alert box -->
          {$alertBox}

          <!-- Footer note -->
          <p style="margin:0;font-size:.8rem;color:#6b7494;text-align:center;line-height:1.6">
            Για απορίες σχετικά με την πληρωμή σας<br>επικοινωνήστε απευθείας με τη σχολή.
          </p>

        </td>
      </tr>

      <!-- DIVIDER -->
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>

      <!-- FOOTER -->
      <tr>
        <td style="padding:18px 32px;text-align:center;font-size:.73rem;color:#4a5270;line-height:1.7">
          &copy; {$year} Παναγιώτης Κοτσόργιος &nbsp;&middot;&nbsp;
          <a href="mailto:{$fromEmail}" style="color:#6b7494;text-decoration:none">{$fromEmail}</a><br>
          <span style="font-size:.67rem;color:#363d52">Αποστολή από {$schEsc} μέσω MAster.</span>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    $dbg = null;
    return sendEmail($toEmail, $subject, $html, $bodyText, $name, $dbg, null, $schoolName);
}

// =============================================================================
// 8) PARENT PORTAL CREDENTIALS EMAIL
// =============================================================================

/**
 * Αποστέλλει στον γονέα τα στοιχεία σύνδεσης για το Portal Γονέων.
 * Καλείται αυτόματα κατά την εγγραφή ανήλικου αθλητή.
 *
 * @param string $toEmail      Email γονέα
 * @param string $rawPassword  Αρχικός κωδικός (plaintext, αποθηκεύεται hashed)
 * @param string $athleteName  Ονοματεπώνυμο αθλητή
 * @param string $schoolName   Όνομα σχολής
 */
function sendParentCredentials(
    string $toEmail,
    string $rawPassword,
    string $athleteName,
    string $schoolName,
    ?string &$debugOut = null
): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $appUrl   = defined('APP_URL') ? APP_URL : 'https://master-app.gr';
    $loginUrl = $appUrl . '/parent/login.php';
    $subject  = "Πρόσβαση στο Portal Γονέων — $schoolName";

    $bodyText = "Αγαπητέ/ή γονέα,\n\n"
        . "Ο/Η $athleteName καταχωρήθηκε ως μέλος της σχολής $schoolName στο σύστημα MAster και δημιουργήθηκε λογαριασμός για εσάς στο Portal Γονέων.\n\n"
        . "Μέσα από το Portal μπορείτε να παρακολουθείτε τις συνδρομές και τις πληρωμές του/της αθλητή/τριάς σας.\n\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n"
        . "Email σύνδεσης : $toEmail\n"
        . "Κωδικός        : $rawPassword\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n\n"
        . "Συνδεθείτε εδώ: $loginUrl\n\n"
        . "Συνιστούμε να αλλάξετε τον κωδικό σας αμέσως μετά την πρώτη σύνδεση.\n\n"
        . "Για οποιαδήποτε απορία επικοινωνήστε απευθείας με τη σχολή.\n\n"
        . "— $schoolName";

    // Build a richer HTML version with a login button
    $safeEmail    = htmlspecialchars($toEmail,      ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($rawPassword,  ENT_QUOTES, 'UTF-8');
    $safeAthlete  = htmlspecialchars($athleteName,  ENT_QUOTES, 'UTF-8');
    $safeSchool   = htmlspecialchars($schoolName,   ENT_QUOTES, 'UTF-8');
    $safeUrl      = htmlspecialchars($loginUrl,     ENT_QUOTES, 'UTF-8');
    $year         = date('Y');
    $logoUrl      = $appUrl . '/assets/img/logo-tr.png';
    $fromEmail    = function_exists('getMailFromEmail') ? getMailFromEmail() : 'noreply@master-app.gr';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:560px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:28px 32px 24px;text-align:center;border-bottom:2px solid #2a1a50">
          <img src="{$logoUrl}" alt="MAster" style="height:64px;width:auto;max-width:200px;object-fit:contain;display:block;margin:0 auto 10px">
          <h1 style="margin:0 0 4px;font-size:1.15rem;letter-spacing:.04em;color:#f0f2ff;font-weight:700">{$safeSchool}</h1>
          <p style="margin:0;font-size:.78rem;color:#8892b0;letter-spacing:.04em">Portal Γονέων — MAster</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.85">
          <p style="margin:0 0 16px">Αγαπητέ/ή γονέα,</p>
          <p style="margin:0 0 20px">Ο/Η <strong style="color:#f0f2ff">{$safeAthlete}</strong> καταχωρήθηκε ως μέλος της σχολής <strong style="color:#f0f2ff">{$safeSchool}</strong> στο σύστημα MAster και δημιουργήθηκε λογαριασμός για εσάς στο <strong style="color:#e63946">Portal Γονέων</strong>.</p>
          <p style="margin:0 0 24px;color:#8892b0;font-size:.9rem">Μπορείτε να παρακολουθείτε τις συνδρομές και τις πληρωμές του/της αθλητή/τριάς σας σε πραγματικό χρόνο.</p>

          <table width="100%" cellpadding="0" cellspacing="0" style="background:#0d1117;border:1px solid #1e2536;border-radius:12px;margin-bottom:28px">
            <tr>
              <td style="padding:20px 24px">
                <p style="margin:0 0 12px;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#6b7494">Στοιχεία Σύνδεσης</p>
                <table cellpadding="0" cellspacing="0">
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:4px 0;white-space:nowrap;padding-right:16px">Email:</td>
                    <td style="color:#f0f2ff;font-size:.88rem;font-weight:700;padding:4px 0">{$safeEmail}</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:4px 0;white-space:nowrap;padding-right:16px">Κωδικός:</td>
                    <td style="color:#e63946;font-size:1rem;font-weight:700;letter-spacing:.08em;padding:4px 0;font-family:monospace">{$safePassword}</td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>

          <div style="text-align:center;margin-bottom:24px">
            <a href="{$safeUrl}" style="display:inline-block;background:linear-gradient(135deg,#e63946,#b52a35);color:#fff;font-weight:700;font-size:.95rem;padding:.75rem 2rem;border-radius:10px;text-decoration:none;letter-spacing:.02em">
              Σύνδεση στο Portal Γονέων →
            </a>
          </div>

          <p style="margin:0;font-size:.82rem;color:#6b7494;text-align:center">Συνιστούμε να αλλάξετε τον κωδικό σας αμέσως μετά την πρώτη σύνδεση.<br>Για απορίες επικοινωνήστε με τη σχολή.</p>
        </td>
      </tr>
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr>
        <td style="padding:20px 32px;text-align:center;font-size:.74rem;color:#4a5270;line-height:1.7">
          &copy; {$year} Παναγιώτης Κοτσόργιος &nbsp;&middot;&nbsp;
          <a href="mailto:{$fromEmail}" style="color:#6b7494;text-decoration:none">{$fromEmail}</a><br>
          <span style="font-size:.68rem;color:#363d52">Αποστολή από {$safeSchool} μέσω MAster. Η σχολή φέρει πλήρη ευθύνη για τα δεδομένα σας.</span>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    $debugOut = null;
    $result = sendEmail($toEmail, $subject, $html, $bodyText, '', $debugOut, null, $schoolName);
    if (!$result) {
        error_log("[MAster mailer] sendParentCredentials FAILED to=$toEmail debug=" . ($debugOut ?? 'n/a'));
    }
    return $result;
}


// =============================================================================
// 8b) ATHLETE PORTAL CREDENTIALS EMAIL
// =============================================================================

/**
 * Sends login credentials to an adult athlete for their own portal.
 * Mirrors sendParentCredentials() but points at /parent/login.php
 * (the unified login page auto-routes to /athlete/index.php).
 */
function sendAthleteCredentials(
    string $toEmail,
    string $rawPassword,
    string $athleteName,
    string $schoolName,
    ?string &$debugOut = null
): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $appUrl   = defined('APP_URL') ? APP_URL : 'https://master-app.gr';
    $loginUrl = $appUrl . '/parent/login.php';
    $subject  = "Πρόσβαση στο Portal Αθλητή — $schoolName";

    $bodyText = "Γεια σου $athleteName,\n\n"
        . "Ο σύλλογος $schoolName σου έδωσε πρόσβαση στο δικό σου Portal Αθλητή στο MAster.\n\n"
        . "Μέσα από το portal μπορείς να δεις τη συνδρομή σου, τα έγγραφά σου, τις εκδηλώσεις της σχολής,\n"
        . "και να ανεβάσεις μόνος/η σου δελτίο αθλητή, πιστοποιητικά Dan, ζώνη, ιατρικό.\n\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n"
        . "Email σύνδεσης : $toEmail\n"
        . "Κωδικός        : $rawPassword\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n\n"
        . "Συνδέσου εδώ: $loginUrl\n\n"
        . "Άλλαξε τον κωδικό σου μετά την πρώτη σύνδεση.\n\n"
        . "— $schoolName";

    $safeEmail    = htmlspecialchars($toEmail,     ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($rawPassword, ENT_QUOTES, 'UTF-8');
    $safeAthlete  = htmlspecialchars($athleteName, ENT_QUOTES, 'UTF-8');
    $safeSchool   = htmlspecialchars($schoolName,  ENT_QUOTES, 'UTF-8');
    $safeUrl      = htmlspecialchars($loginUrl,    ENT_QUOTES, 'UTF-8');
    $year         = date('Y');
    $logoUrl      = $appUrl . '/assets/img/logo-tr.png';
    $fromEmail    = function_exists('getMailFromEmail') ? getMailFromEmail() : 'noreply@master-app.gr';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:560px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#2a1040);padding:28px 32px 24px;text-align:center;border-bottom:2px solid #501a30">
          <img src="{$logoUrl}" alt="MAster" style="height:64px;width:auto;max-width:200px;object-fit:contain;display:block;margin:0 auto 10px">
          <h1 style="margin:0 0 4px;font-size:1.15rem;letter-spacing:.04em;color:#f0f2ff;font-weight:700">{$safeSchool}</h1>
          <p style="margin:0;font-size:.78rem;color:#8892b0;letter-spacing:.04em">Portal Αθλητή — MAster</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.85">
          <p style="margin:0 0 16px">Γεια σου <strong style="color:#f0f2ff">{$safeAthlete}</strong>,</p>
          <p style="margin:0 0 20px">Ο σύλλογος <strong style="color:#f0f2ff">{$safeSchool}</strong> σου έδωσε πρόσβαση στο δικό σου <strong style="color:#e63946">Portal Αθλητή</strong> στο MAster.</p>
          <p style="margin:0 0 24px;color:#8892b0;font-size:.9rem">Μπορείς να δεις τη συνδρομή σου, τα έγγραφά σου, τις εκδηλώσεις της σχολής, και να ανεβάσεις δελτίο αθλητή, πιστοποιητικά Dan, ζώνη, ιατρικό.</p>

          <table width="100%" cellpadding="0" cellspacing="0" style="background:#0d1117;border:1px solid #1e2536;border-radius:12px;margin-bottom:28px">
            <tr>
              <td style="padding:20px 24px">
                <p style="margin:0 0 12px;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#6b7494">Στοιχεία Σύνδεσης</p>
                <table cellpadding="0" cellspacing="0">
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:4px 0;white-space:nowrap;padding-right:16px">Email:</td>
                    <td style="color:#f0f2ff;font-size:.88rem;font-weight:700;padding:4px 0">{$safeEmail}</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:4px 0;white-space:nowrap;padding-right:16px">Κωδικός:</td>
                    <td style="color:#e63946;font-size:1rem;font-weight:700;letter-spacing:.08em;padding:4px 0;font-family:monospace">{$safePassword}</td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>

          <div style="text-align:center;margin-bottom:24px">
            <a href="{$safeUrl}" style="display:inline-block;background:linear-gradient(135deg,#e63946,#b52a35);color:#fff;font-weight:700;font-size:.95rem;padding:.75rem 2rem;border-radius:10px;text-decoration:none;letter-spacing:.02em">
              Σύνδεση στο Portal Αθλητή →
            </a>
          </div>

          <p style="margin:0;font-size:.82rem;color:#6b7494;text-align:center">Άλλαξε τον κωδικό σου μετά την πρώτη σύνδεση.<br>Για απορίες επικοινώνησε με τη σχολή.</p>
        </td>
      </tr>
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr>
        <td style="padding:20px 32px;text-align:center;font-size:.74rem;color:#4a5270;line-height:1.7">
          &copy; {$year} Παναγιώτης Κοτσόργιος &nbsp;&middot;&nbsp;
          <a href="mailto:{$fromEmail}" style="color:#6b7494;text-decoration:none">{$fromEmail}</a><br>
          <span style="font-size:.68rem;color:#363d52">Αποστολή από {$safeSchool} μέσω MAster.</span>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    $debugOut = null;
    $result = sendEmail($toEmail, $subject, $html, $bodyText, '', $debugOut, null, $schoolName);
    if (!$result) {
        error_log("[MAster mailer] sendAthleteCredentials FAILED to=$toEmail debug=" . ($debugOut ?? 'n/a'));
    }
    return $result;
}


// =============================================================================
// 9) SCHOOL PLAN ACTIVATION EMAIL
// =============================================================================

/**
 * Αποστέλλει email στον ιδιοκτήτη σχολής όταν ο admin ενεργοποιεί/ανανεώνει πλάνο.
 *
 * @param string $toEmail     Email ιδιοκτήτη
 * @param string $ownerName   Όνομα ιδιοκτήτη
 * @param string $schoolName  Όνομα σχολής
 * @param string $planName    Όνομα πλάνου (π.χ. Pro)
 * @param string $validUntil  Ημερομηνία λήξης (Y-m-d)
 * @param float  $amount      Ποσό πληρωμής
 * @param string $method      Μέθοδος πληρωμής
 */
function sendSchoolPlanActivationEmail(
    string $toEmail,
    string $ownerName,
    string $schoolName,
    string $planName,
    string $validUntil,
    float  $amount,
    string $method = 'cash'
): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $appUrl   = defined('APP_URL') ? APP_URL : 'https://master-app.gr';
    $loginUrl = $appUrl . '/login.php';
    $subject  = "✅ Ο λογαριασμός σας ενεργοποιήθηκε — $schoolName";

    $methodLabel = match($method) {
        'cash'    => 'Μετρητά',
        'card'    => 'Κάρτα',
        'deposit' => 'Τραπεζικό Έμβασμα',
        default   => ucfirst($method),
    };

    $validUntilFmt = $validUntil ? date('d/m/Y', strtotime($validUntil)) : '—';
    $amountFmt     = number_format($amount, 2, ',', '.');
    $year          = date('Y');

    $safeOwner   = htmlspecialchars($ownerName,   ENT_QUOTES, 'UTF-8');
    $safeSchool  = htmlspecialchars($schoolName,  ENT_QUOTES, 'UTF-8');
    $safePlan    = htmlspecialchars($planName,     ENT_QUOTES, 'UTF-8');
    $safeUntil   = htmlspecialchars($validUntilFmt, ENT_QUOTES, 'UTF-8');
    $safeAmount  = htmlspecialchars($amountFmt,   ENT_QUOTES, 'UTF-8');
    $safeMethod  = htmlspecialchars($methodLabel, ENT_QUOTES, 'UTF-8');
    $safeUrl     = htmlspecialchars($loginUrl,    ENT_QUOTES, 'UTF-8');
    $logoUrl     = $appUrl . '/assets/img/logo-tr.png';
    $fromEmail   = function_exists('getMailFromEmail') ? getMailFromEmail() : 'noreply@master-app.gr';

    $bodyText = "Αγαπητέ/ή $ownerName,\n\n"
        . "Η πληρωμή για τη σχολή «$schoolName» καταχωρήθηκε επιτυχώς και ο λογαριασμός σας είναι πλέον ενεργός.\n\n"
        . "Πλάνο       : $planName\n"
        . "Ποσό        : $amountFmt€\n"
        . "Μέθοδος     : $methodLabel\n"
        . "Ισχύει έως  : $validUntilFmt\n\n"
        . "Συνδεθείτε εδώ: $loginUrl\n\n"
        . "Σας ευχαριστούμε για την εμπιστοσύνη σας!\n\n"
        . "— MAster";

    $html = <<<HTML
<!DOCTYPE html>
<html lang="el">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$subject}</title></head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:560px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
      <tr>
        <td style="background:linear-gradient(135deg,#0d1a0d,#0d2d1a);padding:28px 32px 24px;text-align:center;border-bottom:2px solid #1a4a2a">
          <img src="{$logoUrl}" alt="MAster" style="height:64px;width:auto;max-width:200px;object-fit:contain;display:block;margin:0 auto 10px">
          <h1 style="margin:0 0 4px;font-size:1.15rem;letter-spacing:.04em;color:#f0f2ff;font-weight:700">{$safeSchool}</h1>
          <p style="margin:0;font-size:.78rem;color:#8892b0;letter-spacing:.04em">Επιβεβαίωση Ενεργοποίησης Λογαριασμού</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.85">
          <p style="margin:0 0 8px">Αγαπητέ/ή <strong style="color:#f0f2ff">{$safeOwner}</strong>,</p>
          <p style="margin:0 0 24px;color:#8892b0;font-size:.9rem">
            Η πληρωμή για τη σχολή <strong style="color:#f0f2ff">{$safeSchool}</strong> καταχωρήθηκε επιτυχώς
            και ο λογαριασμός σας είναι πλέον <strong style="color:#2dc653">ενεργός</strong>.
          </p>

          <table width="100%" cellpadding="0" cellspacing="0" style="background:#0d1117;border:1px solid #1e2536;border-radius:12px;margin-bottom:28px">
            <tr>
              <td style="padding:20px 24px">
                <p style="margin:0 0 14px;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#6b7494">Στοιχεία Πληρωμής</p>
                <table cellpadding="0" cellspacing="4">
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:3px 0;white-space:nowrap;padding-right:20px">Πλάνο:</td>
                    <td style="color:#f0f2ff;font-size:.88rem;font-weight:700;padding:3px 0">{$safePlan}</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:3px 0;white-space:nowrap;padding-right:20px">Ποσό:</td>
                    <td style="color:#2dc653;font-size:1rem;font-weight:700;padding:3px 0">{$safeAmount}€</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:3px 0;white-space:nowrap;padding-right:20px">Μέθοδος:</td>
                    <td style="color:#f0f2ff;font-size:.88rem;padding:3px 0">{$safeMethod}</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7494;font-size:.88rem;padding:3px 0;white-space:nowrap;padding-right:20px">Ισχύει έως:</td>
                    <td style="color:#f0f2ff;font-size:.88rem;font-weight:700;padding:3px 0">{$safeUntil}</td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>

          <div style="text-align:center;margin-bottom:24px">
            <a href="{$safeUrl}" style="display:inline-block;background:linear-gradient(135deg,#2dc653,#1a9438);color:#fff;font-weight:700;font-size:.95rem;padding:.75rem 2rem;border-radius:10px;text-decoration:none;letter-spacing:.02em">
              Σύνδεση στο MAster →
            </a>
          </div>

          <p style="margin:0;font-size:.82rem;color:#6b7494;text-align:center">Σας ευχαριστούμε για την εμπιστοσύνη σας!<br>Για απορίες επικοινωνήστε μαζί μας.</p>
        </td>
      </tr>
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr>
        <td style="padding:20px 32px;text-align:center;font-size:.74rem;color:#4a5270;line-height:1.7">
          &copy; {$year} Παναγιώτης Κοτσόργιος &nbsp;&middot;&nbsp;
          <a href="mailto:{$fromEmail}" style="color:#6b7494;text-decoration:none">{$fromEmail}</a>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    $dbg = null;
    return sendEmail($toEmail, $subject, $html, $bodyText, $ownerName, $dbg);
}