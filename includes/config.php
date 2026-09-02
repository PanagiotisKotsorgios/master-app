<?php
/**
 * ============================================================
 * includes/config.php
 * ============================================================
 * PURPOSE:
 *   Κεντρικό αρχείο ρυθμίσεων και utility functions.
 *   Φορτώνεται ΠΡΩΤΟ σε κάθε PHP σελίδα της εφαρμογής.
 *
 * WHAT THIS FILE DOES:
 *   1. Ορίζει σταθερές σύνδεσης βάσης δεδομένων
 *   2. Παρέχει singleton PDO connection (getDB)
 *   3. Φορτώνει ρυθμίσεις από system_settings (DB-driven)
 *   4. Ρυθμίζει secure PHP sessions
 *   5. Παρέχει auth helpers (isLoggedIn, requireLogin κλπ)
 *   6. Παρέχει utility functions (h, redirect, flash κλπ)
 *   7. Φορτώνει security headers, mailer, usage tracker
 *
 * SECURITY NOTES:
 *   - DB credentials: αλλαγή σε production (ποτέ root/root)
 *   - APP_URL: χωρίς trailing slash, HTTPS σε production
 *   - SESSION_LIFETIME: 8 ώρες — adjust για security needs
 *   - Όλα τα sensitive settings έρχονται από DB, ΟΧΙ από εδώ
 * ============================================================
 */

// ══════════════════════════════════════════════════════════════
// 1. DATABASE CREDENTIALS  — loaded from environment
//    Set these in Coolify (or your .env file):
//      DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET
//    NEVER hardcode secrets here — this file is committed to git.
// ══════════════════════════════════════════════════════════════
if (!defined('DB_HOST'))    define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    getenv('DB_NAME')    ?: 'master_db');
if (!defined('DB_USER'))    define('DB_USER',    getenv('DB_USER')    ?: 'master');
if (!defined('DB_PASS'))    define('DB_PASS',    getenv('DB_PASS')    ?: '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// ══════════════════════════════════════════════════════════════
// 2. APPLICATION CONSTANTS
//    APP_URL: χωρίς trailing slash, HTTPS σε production
//    Χρησιμοποιείται ως fallback — πραγματική τιμή έρχεται
//    από system_settings table (admin-configurable)
// ══════════════════════════════════════════════════════════════
if (!defined('APP_NAME'))         define('APP_NAME',         getenv('APP_NAME')  ?: 'MAster');

// APP_URL resolution — priority chain:
//   1. Auto-detect from the actual request (works behind Traefik/Coolify)
//   2. APP_URL env var (if user pinned one explicitly)
//   3. Coolify's COOLIFY_URL (auto-injected by Coolify when a domain is set)
//   4. Coolify's COOLIFY_FQDN
//   5. Hard fallback for CLI / cron contexts
// Rationale: hardcoding a wrong APP_URL breaks every link on the site.
// Auto-detection from headers means the URL always matches what the user
// typed, whether that's master-app.gr, www.master-app.gr, or a dev domain.
if (!defined('APP_URL')) {
    $_master_app_url = null;

    // Try request headers first (only in web context, not CLI)
    if (!empty($_SERVER['HTTP_HOST'])) {
        // Trust X-Forwarded-* from Traefik/Coolify's reverse proxy
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO']
              ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'];
        // X-Forwarded-Host may contain a comma-separated chain; take first
        $host  = trim(explode(',', $host)[0]);
        if ($host && $host !== 'localhost' && $host !== '127.0.0.1') {
            $_master_app_url = $proto . '://' . $host;
        }
    }

    // Env var overrides only if it's not a useless localhost default
    if ($_master_app_url === null) {
        $envUrl = getenv('APP_URL') ?: '';
        if ($envUrl && !preg_match('#^https?://(localhost|127\.0\.0\.1)#i', $envUrl)) {
            $_master_app_url = $envUrl;
        }
    }

    // Coolify auto-injected env vars
    if ($_master_app_url === null) {
        $coolifyUrl = getenv('COOLIFY_URL') ?: '';
        if ($coolifyUrl) {
            // COOLIFY_URL can be a comma-separated list of domains
            $_master_app_url = trim(explode(',', $coolifyUrl)[0]);
        }
    }
    if ($_master_app_url === null) {
        $coolifyFqdn = getenv('COOLIFY_FQDN') ?: '';
        if ($coolifyFqdn) {
            $fqdn = trim(explode(',', $coolifyFqdn)[0]);
            if ($fqdn && $fqdn !== 'http') {
                $_master_app_url = 'https://' . preg_replace('#^https?://#i', '', $fqdn);
            }
        }
    }

    // Final fallback
    if ($_master_app_url === null) {
        $_master_app_url = 'https://master-app.gr';
    }

    define('APP_URL', rtrim($_master_app_url, '/'));
    unset($_master_app_url);
}

if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', (int)(getenv('SESSION_LIFETIME') ?: 3600 * 8));
// Shared secret required to trigger cron endpoints over HTTP. MUST be set in production.
if (!defined('CRON_SECRET'))      define('CRON_SECRET',      getenv('CRON_SECRET') ?: '');

// ══════════════════════════════════════════════════════════════
// 3. SECURE SESSION CONFIGURATION
//    Πρέπει να οριστεί ΠΡΙΝ το session_start()
//    Αποτρέπει: session hijacking, fixation, XSS cookie theft
// ══════════════════════════════════════════════════════════════
if (session_status() === PHP_SESSION_NONE) {

    // HttpOnly: η cookie δεν διαβάζεται από JavaScript
    // Αποτρέπει κλοπή session μέσω XSS
    ini_set('session.cookie_httponly', '1');

    // Secure: η cookie στέλνεται μόνο μέσω HTTPS
    // Αποτρέπει κλοπή μέσω HTTP interception
    // ΣΗΜΕΙΩΣΗ: σε localhost (HTTP) αυτό θα εμποδίσει login
    // Εναλλακτικά: ενεργοποίησε μόνο σε production
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');

    // SameSite=Lax: προστασία από CSRF σε cross-site requests
    // Strict θα έσπαγε OAuth redirects, Lax είναι ισορροπία
    ini_set('session.cookie_samesite', 'Lax');

    // Χρήση μόνο cookies (όχι ?PHPSESSID= στο URL)
    // Αποτρέπει session fixation μέσω URL manipulation
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid',    '0');

    // Entropy για session IDs: SHA-256 hash
    // Αυξάνει το entropy του generated session ID
    ini_set('session.hash_function', 'sha256');

    // Διάρκεια cookie: ίδια με SESSION_LIFETIME
    ini_set('session.cookie_lifetime', (string)SESSION_LIFETIME);
    ini_set('session.gc_maxlifetime',  (string)SESSION_LIFETIME);

    // Session name: αλλάζει από default 'PHPSESSID'
    // Αποτρέπει automated scanners που ψάχνουν για PHPSESSID
    session_name('MSID');

    session_start();
}

// ── Session Timeout Enforcement ──────────────────────────────
// Αν ο χρήστης είναι ανενεργός > SESSION_LIFETIME → logout
// Αυτό ελέγχεται server-side, ανεξάρτητα από cookie expiry
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        // Καθαρισμός session data
        session_unset();
        session_destroy();
        // Restart για να μπορεί να γίνει νέο login
        session_start();
    }
}
// Ενημέρωση timestamp τελευταίας δραστηριότητας
$_SESSION['last_activity'] = time();

// ══════════════════════════════════════════════════════════════
// 4. DATABASE CONNECTION (Singleton Pattern)
//    Μία σύνδεση ανά request — επαναχρησιμοποιείται
//    PDO με ATTR_EMULATE_PREPARES = false: χρήση native
//    prepared statements (αποτρέπει SQL injection)
// ══════════════════════════════════════════════════════════════
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            // Πετάει exception αντί για silent error
            // Κρίσιμο: εντοπίζει DB errors αντί να τους αγνοεί
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

            // Όλα τα fetchAll/fetch επιστρέφουν associative array
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // FALSE = native prepared statements (true SQL injection protection)
            // TRUE = εξομοιωμένα (λιγότερη ασφάλεια)
            PDO::ATTR_EMULATE_PREPARES   => false,

            // Persistent connections: OFF (safer for web servers)
            PDO::ATTR_PERSISTENT         => false,
        ]);
    }

    return $pdo;
}

// ══════════════════════════════════════════════════════════════
// 5. SYSTEM SETTINGS (DB-driven, cached per request)
//    Ρυθμίσεις που αλλάζουν από το Admin UI χωρίς deploy.
//    Cache: static array — φορτώνεται μία φορά per request.
//    Fallback: αν η ΒΔ δεν είναι διαθέσιμη, χρησιμοποιείται
//    η default τιμή που δίνεται στη getSetting() κλήση.
// ══════════════════════════════════════════════════════════════
function getSetting(string $key, string $default = ''): string {
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            $rows = getDB()
                ->query('SELECT setting_key, setting_value FROM system_settings')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                // Null-coalesce: αν setting_value είναι NULL → κενό string
                $cache[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (Exception $e) {
            // Η πίνακας system_settings μπορεί να μην υπάρχει ακόμα
            // (πρώτη εγκατάσταση, migration pending) — silent fallback
            error_log('[MAster] getSetting failed: ' . $e->getMessage());
        }
    }

    return $cache[$key] ?? $default;
}

// ══════════════════════════════════════════════════════════════
// 6. API KEY GETTERS
//    DB-first με fallback σε constants.
//    Pattern: getSetting('key', CONSTANT_FALLBACK)
//    Αυτό επιτρέπει override μέσω Admin UI χωρίς code change.
// ══════════════════════════════════════════════════════════════

// ─── Brevo (Email) ────────────────────────────────────────────
// Fallback constants — real keys ΠΑΝΤΑ στη ΒΔ ή σε env vars
if (!defined('BREVO_API_KEY'))   define('BREVO_API_KEY',   getenv('BREVO_API_KEY')   ?: '');
if (!defined('MAIL_FROM_EMAIL')) define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: 'noreply@example.com');
if (!defined('MAIL_FROM_NAME'))  define('MAIL_FROM_NAME',  getenv('MAIL_FROM_NAME')  ?: 'MAster');

function getBrevoApiKey(): string   { return getSetting('brevo_api_key',   BREVO_API_KEY); }
function getMailFromEmail(): string { return getSetting('mail_from_email', MAIL_FROM_EMAIL); }
function getMailFromName(): string  { return getSetting('mail_from_name',  MAIL_FROM_NAME); }

// ─── SMS — bulker.gr HTTP API ────────────────────────────────
// Χρησιμοποιείται για SMS υπενθυμίσεις (Pro plan)
function getBulkerAuthKey(): string  { return getSetting('bulker_auth_key',  ''); }
function getBulkerProfileId(): string{ return getSetting('bulker_profile_id',''); }
function getBulkerSender(): string   { return getSetting('bulker_sender',    ''); }

// ─── Viva.com (IRIS / Smart Checkout) ────────────────────────
// Τα πραγματικά credentials ΠΟΤΕ hardcoded — μόνο στη system_settings ΒΔ
// Εγγραφή: https://www.viva.com/en-gr/business
// My Sales → API Access → Smart Checkout → Client ID / Client Secret
// My Sales → API Access → Generate API Key → API Key (= Source Code)
function getVivaClientId(): string     { return getSetting('viva_client_id',     ''); }
function getVivaClientSecret(): string { return getSetting('viva_client_secret', ''); }
function getVivaMerchantId(): string   { return getSetting('viva_merchant_id',   ''); }
function getVivaApiKey(): string       { return getSetting('viva_api_key',       ''); }
function getVivaWebhookKey(): string   { return getSetting('viva_webhook_key',   ''); }
// Demo mode: '1' = demo.vivapayments.com, '0' = www.vivapayments.com (live)
function isVivaDemoMode(): bool        { return getSetting('viva_demo_mode', '1') === '1'; }
function getVivaBaseUrl(): string      { return isVivaDemoMode() ? 'https://demo.vivapayments.com'         : 'https://www.vivapayments.com'; }
function getVivaAccountsUrl(): string  { return isVivaDemoMode() ? 'https://demo-accounts.vivapayments.com' : 'https://accounts.vivapayments.com'; }

// ─── Bank Transfer / IRIS (Τραπεζικό Έμβασμα & IRIS) ─────────
// Εμφανίζεται στο upgrade.php όταν ο χρήστης επιλέξει "Τραπεζικό Έμβασμα"
function getBankName(): string          { return getSetting('bank_name',           ''); }
function getBankIban(): string          { return getSetting('bank_iban',           ''); }
function getBankBeneficiary(): string   {
    $v = getSetting('bank_beneficiary', '');
    // Drop patronymic "ΔΗΜΗΤΡΙΟΥ" (any case) from the displayed name.
    $v = preg_replace('/\s*ΔΗΜΗΤΡΙΟΥ\s*/ui', ' ', $v);
    return trim(preg_replace('/\s+/', ' ', $v));
}
function getBankReference(): string     { return getSetting('bank_reference_hint', 'MASTER-{SCHOOL_NAME}'); }
function getBankReceiptEmail(): string  { return getSetting('bank_receipt_email',  getMailFromEmail()); }
function getBankInstructions(): string  { return getSetting('bank_instructions',   ''); }
function getIrisAfm(): string           { return getSetting('iris_afm',            ''); }
function getIrisPhone(): string         { return getSetting('iris_phone',          ''); }

// ══════════════════════════════════════════════════════════════
// 7. AUTHENTICATION & AUTHORIZATION HELPERS
//    Χρησιμοποιούνται σε κάθε protected σελίδα.
//    ΚΑΝΟΝΑΣ: κάθε σελίδα που χρειάζεται login καλεί
//    requireLogin() ή requireSuperAdmin() στην αρχή.
// ══════════════════════════════════════════════════════════════

/** Ελέγχει αν υπάρχει active session */
function isLoggedIn(): bool   { return isset($_SESSION['user_id']); }

/**
 * Ελέγχει αν η τρέχουσα session ανήκει σε Parent Portal χρήστη.
 * ΚΡΙΣΙΜΟ: Parent sessions έχουν user_id αλλά ΔΕΝ πρέπει
 * να έχουν πρόσβαση στο club dashboard.
 */
function isParentSession(): bool {
    return isset($_SESSION['is_parent']) && $_SESSION['is_parent'] === true;
}

/**
 * Ελέγχει αν υπάρχει active club/admin session (όχι parent).
 * Χρησιμοποιείται σε login/register για redirect αν ήδη logged in.
 */
function isClubLoggedIn(): bool {
    return isLoggedIn() && !isParentSession();
}

/** Ελέγχει αν ο logged-in user είναι superadmin */
function isSuperAdmin(): bool { return ($_SESSION['user']['role'] ?? '') === 'superadmin'; }

/** ID της σχολής του logged-in user (0 αν δεν υπάρχει) */
function schoolId(): int      { return (int)($_SESSION['school_id'] ?? 0); }

/** ID του logged-in user */
function userId(): int        { return (int)($_SESSION['user_id'] ?? 0); }

/** Role του logged-in user */
function userRole(): string   { return $_SESSION['user']['role'] ?? ''; }

/** Πλήρη δεδομένα logged-in user από session */
function currentUser(): array { return $_SESSION['user'] ?? []; }

/**
 * Απαιτεί login club/admin — αν όχι, redirect στη login σελίδα.
 * Καλείται στην αρχή κάθε protected σελίδας του dashboard.
 *
 * SECURITY: Αποτρέπει parent sessions από το να παρακάμψουν
 * τον έλεγχο επειδή έχουν user_id στο session. Ο έλεγχος
 * isParentSession() εξασφαλίζει ότι parents ανακατευθύνονται
 * στο δικό τους login αντί να αποκτούν πρόσβαση στο club dashboard.
 */
function requireLogin(): void {
    // Parent sessions have user_id set but must NOT access the club dashboard.
    // Redirect them to their own portal instead of letting them in.
    // Note: admin impersonation also uses is_parent — those users should
    // stay in the parent portal and use "Exit Impersonation" to return.
    if (isParentSession()) {
        redirect(APP_URL . '/parent/index.php');
    }
    if (!isLoggedIn()) {
        // Αποθήκευση intended URL για redirect μετά login
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect(APP_URL . '/login.php');
    }
}

/**
 * Απαιτεί superadmin role.
 * Χρησιμοποιείται σε όλες τις /admin/ σελίδες.
 * Double check: και login AND role.
 */
function requireSuperAdmin(): void {
    requireLogin();
    if (!isSuperAdmin()) {
        redirect(APP_URL . '/dashboard/');
    }
}

/**
 * Role-based access control.
 * Ιεραρχία: coach < secretary < admin < owner < superadmin
 * Επιστρέφει true αν ο user έχει τουλάχιστον το minRole.
 *
 * @param string $minRole  Ελάχιστο απαιτούμενο role
 */
function canAccess(string $minRole): bool {
    $hierarchy = ['coach' => 1, 'secretary' => 2, 'admin' => 3, 'owner' => 4, 'superadmin' => 5];
    return ($hierarchy[userRole()] ?? 0) >= ($hierarchy[$minRole] ?? 99);
}

// ══════════════════════════════════════════════════════════════
// 8. CSRF PROTECTION
//    Cross-Site Request Forgery: attacker παγιδεύει authenticated
//    user να υποβάλει form χωρίς να το ξέρει.
//    Άμυνα: token στο session που πρέπει να ταιριάζει με POST.
// ══════════════════════════════════════════════════════════════

/**
 * Δημιουργεί ή επιστρέφει το CSRF token της session.
 * Χρησιμοποιείται ως hidden input σε κάθε form.
 *
 * @return string  32-byte hex token
 */
function csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        // cryptographically secure random bytes
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Επαληθεύει CSRF token σε POST requests.
 * Καλείται στην αρχή κάθε POST handler.
 * Αποτυχία: die() — δεν επιτρέπεται καμία επεξεργασία.
 *
 * ΣΗΜΕΙΩΣΗ: χρησιμοποιούμε hash_equals() αντί για == για να
 * αποτρέψουμε timing attacks (το == μπορεί να "κοιτά" πόσα
 * bytes ταιριάζουν πριν επιστρέψει false).
 */
function verifyCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submitted = $_POST['csrf_token'] ?? '';
        $expected  = $_SESSION['csrf_token'] ?? '';

        if (!$expected || !hash_equals($expected, $submitted)) {
            http_response_code(403);
            error_log('[MAster Security] CSRF mismatch from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            die('Security check failed. Please go back and try again.');
        }
    }
}

// ══════════════════════════════════════════════════════════════
// 9. INPUT SANITIZATION HELPERS
// ══════════════════════════════════════════════════════════════

/**
 * HTML-escape για output — ΠΑΝΤΑ χρησιμοποιείται για user data
 * που εμφανίζεται σε HTML context.
 * Αποτρέπει XSS: <script>alert()</script> → &lt;script&gt;
 *
 * @param  string|null $s  Input string (null-safe)
 * @return string          Escaped string
 */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Καθαρίζει string για χρήση ως plain text (strips all tags).
 * Χρησιμοποιείται για input που αποθηκεύεται, όχι εμφανίζεται.
 *
 * @param  string $s  Raw input
 * @return string     Sanitized string
 */
function sanitizeString(string $s): string {
    return trim(strip_tags($s));
}

/**
 * Validates and sanitizes an email address.
 * Returns empty string if invalid.
 *
 * @param  string $email  Raw email input
 * @return string         Validated email or ''
 */
function sanitizeEmail(string $email): string {
    $clean = strtolower(trim($email));
    return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : '';
}

/**
 * Sanitizes integer input with optional range check.
 * Η πιο ασφαλής μέθοδος για numeric input από GET/POST.
 *
 * @param  mixed $val    Input value
 * @param  int   $min    Minimum allowed value
 * @param  int   $max    Maximum allowed value (0 = no limit)
 * @return int           Sanitized integer
 */
function sanitizeInt($val, int $min = 0, int $max = 0): int {
    $i = (int)$val;
    if ($i < $min) return $min;
    if ($max > 0 && $i > $max) return $max;
    return $i;
}

// ══════════════════════════════════════════════════════════════
// 10. PLAN & FEATURE ACCESS CONTROL
//     Ελέγχει αν η σχολή έχει πρόσβαση σε specific features
//     βάσει του ενεργού πλάνου της.
// ══════════════════════════════════════════════════════════════

/**
 * Επιστρέφει τα δεδομένα πλάνου της τρέχουσας σχολής.
 * Static cached: τρέχει μία φορά per request.
 */
function schoolPlan(): array {
    static $plan = null;

    if ($plan === null && schoolId()) {
        $db   = getDB();
        $stmt = $db->prepare('
            SELECT p.*, s.plan_status, s.plan_expires, s.trial_ends
            FROM plans p
            JOIN schools s ON s.plan_id = p.id
            WHERE s.id = ?
            LIMIT 1
        ');
        $stmt->execute([schoolId()]);
        $plan = $stmt->fetch() ?: [];
    }

    return $plan ?: [];
}

/**
 * Ελέγχει αν η σχολή έχει ενεργοποιημένο feature.
 * Superadmin: πάντα true (bypass για development/testing).
 *
 * @param  string $feature  Column name στο plans table (π.χ. 'sms_enabled')
 * @return bool
 */
function planHas(string $feature): bool {
    if (isSuperAdmin()) return true;
    $plan = schoolPlan();
    return !empty($plan[$feature]);
}

/**
 * Ελέγχει αν το πλάνο της σχολής είναι ενεργό (δεν έχει λήξει).
 * Αφορά: payment walls, feature locks.
 */
function isPlanActive(): bool {
    if (isSuperAdmin()) return true;
    $s = getSchoolStatus();
    return !$s['expired'];
}

// ══════════════════════════════════════════════════════════════
// 10b. SCHOOL APPROVAL WORKFLOW
//     Layered on top of plan_status. Grandfathered to 'approved'
//     for every existing school by migration 006. Self-registration
//     via /register.php is now unconditional — no admin gate.
//
//     Gate any page that should require approved status with:
//         requireApprovedSchool();
//     — it's OFF by default; existing pages are unchanged.
// ══════════════════════════════════════════════════════════════

/** Fetch the current school's approval status (safe default 'approved'). */
function schoolApprovalStatus(): string {
    static $cache = null;
    if ($cache !== null) return $cache;

    $sid = schoolId();
    if (!$sid) return $cache = 'approved';
    try {
        $st = getDB()->prepare('SELECT approval_status FROM schools WHERE id = ? LIMIT 1');
        $st->execute([$sid]);
        $v = $st->fetchColumn();
        return $cache = ($v ?: 'approved');
    } catch (Throwable $e) {
        // Column may not exist yet (pre-migration deploy) — treat as approved.
        return $cache = 'approved';
    }
}

function schoolIsApproved(): bool {
    if (isSuperAdmin()) return true;
    return schoolApprovalStatus() === 'approved';
}

/** Redirect to a "pending approval" landing page if not approved. */
function requireApprovedSchool(): void {
    if (schoolIsApproved()) return;
    $status = schoolApprovalStatus();
    // Attach status to session so the pending page can show context
    $_SESSION['school_approval_reason'] = $status;
    redirect(APP_URL . '/pending-approval.php');
}

/**
 * Admin action — flip a school's approval status and log audit row.
 *
 * @param int    $schoolId
 * @param string $newStatus  one of: pending, approved, rejected, suspended
 * @param string $reason     optional note
 */
function schoolSetApproval(int $schoolId, string $newStatus, string $reason = ''): void {
    $allowed = ['pending', 'approved', 'rejected', 'suspended'];
    if (!in_array($newStatus, $allowed, true)) {
        throw new InvalidArgumentException('Invalid approval status');
    }
    $db  = getDB();
    $cur = $db->prepare('SELECT approval_status FROM schools WHERE id = ? LIMIT 1');
    $cur->execute([$schoolId]);
    $from = $cur->fetchColumn() ?: null;
    if ($from === $newStatus) return; // no-op

    $ts = ($newStatus === 'approved') ? 'NOW()' : 'NULL';
    $db->prepare("UPDATE schools
                     SET approval_status = ?,
                         approved_at     = $ts,
                         approved_by     = ?
                   WHERE id = ?")
       ->execute([$newStatus, $newStatus === 'approved' ? userId() : null, $schoolId]);

    try {
        $db->prepare('INSERT INTO school_approval_history (school_id, actor_id, from_status, to_status, reason) VALUES (?, ?, ?, ?, ?)')
           ->execute([$schoolId, userId() ?: null, $from, $newStatus, mb_substr($reason, 0, 500)]);
    } catch (Throwable $e) {
        error_log('[MAster] approval history insert failed: ' . $e->getMessage());
    }

    auditLog('school_approval_' . $newStatus, 'school', $schoolId, $reason);
}

// ══════════════════════════════════════════════════════════════
// 11. SCHOOL STATUS
//     Υπολογίζει κατάσταση πλάνου: active/trial/expired
//     Χρησιμοποιείται για payment wall rendering.
// ══════════════════════════════════════════════════════════════

function getSchoolStatus(): array {
    // Superadmin: πάντα active (bypass)
    if (isSuperAdmin()) {
        return ['status' => 'active', 'days_left' => null, 'expired' => false];
    }

    $db   = getDB();
    $sid  = schoolId();
    $stmt = $db->prepare('SELECT plan_status, trial_ends, plan_expires FROM schools WHERE id = ? LIMIT 1');
    $stmt->execute([$sid]);
    $school = $stmt->fetch();

    if (!$school) {
        return ['status' => 'expired', 'days_left' => 0, 'expired' => true];
    }

    $status = $school['plan_status'];

    if ($status === 'trial') {
        if (empty($school['trial_ends'])) {
            $defaultDays = (int)getSetting('trial_days', '14');
            return ['status' => 'trial', 'days_left' => $defaultDays, 'expired' => false];
        }
        $daysLeft = (int)floor((strtotime($school['trial_ends']) - time()) / 86400);
        return ['status' => 'trial', 'days_left' => max(0, $daysLeft), 'expired' => $daysLeft < 0];
    }

    if ($status === 'active') {
        if (!empty($school['plan_expires']) && strtotime($school['plan_expires']) < time()) {
            // Auto-expire: ενημέρωση ΒΔ και επιστροφή expired
            $db->prepare("UPDATE schools SET plan_status = 'expired' WHERE id = ?")->execute([$sid]);
            return ['status' => 'expired', 'days_left' => 0, 'expired' => true];
        }
        return ['status' => 'active', 'days_left' => null, 'expired' => false];
    }

    return ['status' => $status, 'days_left' => 0, 'expired' => true];
}

// ══════════════════════════════════════════════════════════════
// 12. AUDIT LOGGING
//     Καταγράφει κρίσιμες ενέργειες για security auditing.
//     Αποτυχίες: silent (δεν crash η εφαρμογή).
// ══════════════════════════════════════════════════════════════

/**
 * Καταγράφει ενέργεια στο audit_log.
 * Αποθηκεύει: who (user_id), from where (IP), what (action),
 * on what (entity_type + entity_id), details.
 *
 * @param string $action      Τι έγινε (π.χ. 'login', 'athlete_deleted')
 * @param string $entityType  Τύπος αντικειμένου (π.χ. 'athlete', 'school')
 * @param int    $entityId    ID αντικειμένου
 * @param string $details     Επιπλέον πληροφορίες
 */
function auditLog(string $action, string $entityType = '', int $entityId = 0, string $details = ''): void {
    try {
        // Φέρνουμε real IP — λαμβάνουμε υπόψη reverse proxies
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']         // Cloudflare
           ?? $_SERVER['HTTP_X_FORWARDED_FOR']           // Load balancer (πρώτο IP)
           ?? $_SERVER['REMOTE_ADDR']
           ?? 'unknown';
        // X-Forwarded-For μπορεί να περιέχει chain: "1.2.3.4, 5.6.7.8"
        $ip = trim(explode(',', $ip)[0]);
        // Περιορισμός μήκους για varchar(45)
        $ip = substr($ip, 0, 45);

        getDB()->prepare(
            'INSERT INTO audit_log (school_id, user_id, action, entity_type, entity_id, details, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([schoolId(), userId(), $action, $entityType, $entityId, $details, $ip]);
    } catch (Exception $e) {
        // Silent fail — audit logging δεν πρέπει να crash την εφαρμογή
        error_log('[MAster] auditLog failed: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
// 13. REDIRECT & FLASH MESSAGES
// ══════════════════════════════════════════════════════════════

/**
 * Safe redirect με validation.
 * Αποτρέπει open redirect vulnerabilities:
 * redirect($_GET['url']) → attacker μπορεί να στείλει
 * redirect('https://evil.com') για phishing.
 * Εδώ ελέγχουμε ότι το target είναι στο ίδιο domain.
 *
 * @param string $url  Target URL (πρέπει να ξεκινά με APP_URL)
 */
function redirect(string $url): never {
    // Validate: επιτρέπουμε absolute URLs στο ίδιο host (APP_URL host ή
    // το τρέχον request host) OR relative paths (ξεκινούν με /)
    $appUrl  = rtrim(APP_URL, '/');
    $appHost = strtolower(parse_url($appUrl, PHP_URL_HOST) ?: '');

    $isSafe = str_starts_with($url, '/') || str_starts_with($url, $appUrl);

    // Also allow same-host absolute URLs (handles master-app.gr vs www.master-app.gr,
    // or when APP_URL was set to https://x but the user is on https://y that also
    // points to this app — both should be considered same-origin).
    if (!$isSafe) {
        $targetHost = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        $currentHost = strtolower($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $currentHost = trim(explode(',', $currentHost)[0]);
        if ($targetHost && ($targetHost === $appHost || $targetHost === $currentHost)) {
            $isSafe = true;
        }
    }

    if (!$isSafe) {
        error_log('[MAster Security] Blocked suspicious redirect to: ' . $url);
        $url = $appUrl . '/';
    }

    // Flush session BEFORE the Location header goes out. Without this,
    // if the caller has been mutating $_SESSION (e.g. login setting
    // user_id + session_regenerate_id), some browsers race the
    // redirect against the pending Set-Cookie / session-file write —
    // the next page loads without the updated session and bounces the
    // user back to login. A single refresh then "works" because the
    // regenerated cookie is finally sent. Safe to call unconditionally.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Αποθηκεύει flash message στο session.
 * Εμφανίζεται στην επόμενη σελίδα (post-redirect-get pattern).
 *
 * @param string $msg   Μήνυμα (μπορεί να περιέχει HTML)
 * @param string $type  'success' | 'error' | 'warning' | 'info'
 */
function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

/**
 * Διαβάζει και αφαιρεί το flash message (single-use).
 * Επιστρέφει null αν δεν υπάρχει.
 */
function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// ══════════════════════════════════════════════════════════════
// 14. FORMATTING HELPERS
// ══════════════════════════════════════════════════════════════

/** Μορφοποιεί ημερομηνία από Y-m-d σε d/m/Y */
function formatDate(?string $d): string {
    return $d ? date('d/m/Y', strtotime($d)) : '—';
}

/** Μορφοποιεί ποσό σε ευρώ: 1234.5 → "1.234,50 €" */
function formatMoney(float $a): string {
    return number_format($a, 2, ',', '.') . ' €';
}

// ══════════════════════════════════════════════════════════════
// 15. SPORT & BELT HELPERS
// ══════════════════════════════════════════════════════════════

function getBelts(string $sport): array {
    $TKD_WTF       = ['10 Γκουπ — Λευκή','9 Γκουπ — Κίτρινη','8 Γκουπ — Κίτρινη / Πράσινο τόξο','7 Γκουπ — Πράσινη','6 Γκουπ — Πράσινη / Μπλε τόξο','5 Γκουπ — Μπλε','4 Γκουπ — Μπλε / Κόκκινο τόξο','3 Γκουπ — Κόκκινη','2 Γκουπ — Κόκκινη / Μαύρο τόξο','1 Γκουπ — Κόκκινη / 2 Μαύρα τόξα','1ο Νταν — Μαύρη (Il Dan)','2ο Νταν — Μαύρη (Yi Dan)','3ο Νταν — Μαύρη (Sam Dan)','4ο Νταν — Μαύρη (Sa Dan)','5ο Νταν — Μαύρη — Μάστερ (Oh Dan)','6ο Νταν — Κόκκινη / Μαύρη — Μάστερ (Yuk Dan)','7ο Νταν — Κόκκινη / Μαύρη — Μάστερ (Chil Dan)','8ο Νταν — Κόκκινη / Μαύρη — Μεγάλος Μάστερ (Pal Dan)','9ο Νταν — Κόκκινη — Μεγάλος Μάστερ (Ku Dan)'];
    $TKD_ITF       = ['10 CUP — Λευκή Ζώνη','9 CUP — Μισή Κίτρινη Ζώνη','8 CUP — Κίτρινη Ζώνη','7 CUP — Μισή Πράσινη Ζώνη','6 CUP — Πράσινη Ζώνη','5 CUP — Μισή Μπλε Ζώνη','4 CUP — Μπλε Ζώνη','3 CUP — Μισή Κόκκινη Ζώνη','2 CUP — Κόκκινη Ζώνη','1 CUP — Μισή Μαύρη Ζώνη','1 Dan — Μαύρη Ζώνη (Chodan)','2 Dan — Μαύρη Ζώνη (Yidan)','3 Dan — Μαύρη Ζώνη (Samdan)','4 Dan — Μαύρη Ζώνη (Sadan)','5 Dan — Μαύρη Ζώνη (Ohdan)','6 Dan — Μαύρη Ζώνη (Yukdan)','7 Dan — Μαύρη Ζώνη (Childan)','8 Dan — Μαύρη Ζώνη (Paldan)','9 Dan — Μαύρη Ζώνη (Kudan)'];
    $KAR_SHOTOKAN  = ['9ο Κιου — Λευκή','8ο Κιου — Κίτρινη','7ο Κιου — Πορτοκαλί','6ο Κιου — Πράσινη','5ο Κιου — Μωβ','4ο Κιου — Μωβ / 1 ρίγα','3ο Κιου — Καφέ','2ο Κιου — Καφέ / 1 ρίγα','1ο Κιου — Καφέ / 2 ρίγες','1ο Νταν — Μαύρη (Shodan)','2ο Νταν — Μαύρη (Nidan)','3ο Νταν — Μαύρη (Sandan)','4ο Νταν — Μαύρη (Yondan)','5ο Νταν — Μαύρη (Godan)','6ο Νταν — Μαύρη (Rokudan)','7ο Νταν — Μαύρη (Shichidan)','8ο Νταν — Μαύρη (Hachidan)','9ο Νταν — Μαύρη (Kudan)','10ο Νταν — Μαύρη (Judan)'];
    $KAR_KYOKUSHIN = ['10ο Κιου — Λευκή','9ο Κιου — Πορτοκαλί','8ο Κιου — Πορτοκαλί / 1 ρίγα','7ο Κιου — Μπλε','6ο Κιου — Μπλε / 1 ρίγα','5ο Κιου — Κίτρινη','4ο Κιου — Κίτρινη / 1 ρίγα','3ο Κιου — Πράσινη','2ο Κιου — Πράσινη / 1 ρίγα','1ο Κιου — Καφέ','1ο Νταν — Μαύρη (Shodan)','2ο Νταν — Μαύρη (Nidan)','3ο Νταν — Μαύρη (Sandan)','4ο Νταν — Μαύρη (Yondan)','5ο Νταν — Μαύρη (Godan)','6ο Νταν — Μαύρη (Rokudan)','7ο Νταν — Μαύρη (Shichidan)','8ο Νταν — Μαύρη (Hachidan)','9ο Νταν — Μαύρη (Kudan)'];
    $BJJ           = ['Λευκή','Λευκή / 1 ρίγα','Λευκή / 2 ρίγες','Λευκή / 3 ρίγες','Λευκή / 4 ρίγες','Μπλε','Μπλε / 1 ρίγα','Μπλε / 2 ρίγες','Μπλε / 3 ρίγες','Μπλε / 4 ρίγες','Μωβ','Μωβ / 1 ρίγα','Μωβ / 2 ρίγες','Μωβ / 3 ρίγες','Μωβ / 4 ρίγες','Καφέ','Καφέ / 1 ρίγα','Καφέ / 2 ρίγες','Καφέ / 3 ρίγες','Καφέ / 4 ρίγες','Μαύρη','Μαύρη / 1 ρίγα','Μαύρη / 2 ρίγες','Μαύρη / 3 ρίγες','Μαύρη / 4 ρίγες','Μαύρη / 5 ρίγες','Μαύρη / 6 ρίγες','Κόκκινη / Μαύρη — 7ο Νταν (Coral)','Κόκκινη / Μαύρη — 8ο Νταν (Coral)','Κόκκινη / Λευκή — 9ο Νταν','Κόκκινη — 10ο Νταν'];
    $JUDO          = ['6ο Κιου — Λευκή','5ο Κιου — Κίτρινη','4ο Κιου — Πορτοκαλί','3ο Κιου — Πράσινη','2ο Κιου — Μπλε','1ο Κιου — Καφέ','1ο Νταν — Μαύρη (Shodan)','2ο Νταν — Μαύρη (Nidan)','3ο Νταν — Μαύρη (Sandan)','4ο Νταν — Μαύρη (Yondan)','5ο Νταν — Μαύρη (Godan)','6ο Νταν — Κόκκινη / Λευκή (Rokudan)','7ο Νταν — Κόκκινη / Λευκή (Shichidan)','8ο Νταν — Κόκκινη / Λευκή (Hachidan)','9ο Νταν — Κόκκινη (Kudan)','10ο Νταν — Κόκκινη (Judan)'];
    $GRAPPLING     = ['Αρχάριος','Προχωρημένος Α','Προχωρημένος Β','Ανταγωνιστικός','Εθνικό Επίπεδο','Διεθνές Επίπεδο'];
    $KICKBOXING    = ['Λευκή','Κίτρινη','Κίτρινη / Πορτοκαλί ρίγα','Πορτοκαλί','Πορτοκαλί / Πράσινη ρίγα','Πράσινη','Πράσινη / Μπλε ρίγα','Μπλε','Μπλε / Καφέ ρίγα','Καφέ','Καφέ / Μαύρη ρίγα','Μαύρη','Μαύρη / 1 ρίγα (1ο Νταν)','Μαύρη / 2 ρίγες (2ο Νταν)','Μαύρη / 3 ρίγες (3ο Νταν)','Μαύρη / 4 ρίγες (4ο Νταν)','Μαύρη / 5 ρίγες (5ο Νταν)'];
    return [
        'taekwondo_wtf'    => $TKD_WTF,
        'taekwondo_itf'    => $TKD_ITF,
        'karate_shotokan'  => $KAR_SHOTOKAN,
        'karate_kyokushin' => $KAR_KYOKUSHIN,
        'bjj'              => $BJJ,
        'judo'             => $JUDO,
        'wrestling'        => $GRAPPLING,
        'pankration'       => $GRAPPLING,
        'sambo'            => $GRAPPLING,
        'kickboxing'       => $KICKBOXING,
    ][$sport] ?? [];
}

function sportHasBelts(string $sport): bool {
    return !empty(getBelts($sport));
}

// ══════════════════════════════════════════════════════════════
// 16. STATUS BADGES & PLAN BADGES
// ══════════════════════════════════════════════════════════════

function statusBadge(string $status): string {
    [$cls, $lbl] = match($status) {
        'paid'    => ['badge-paid',    'Πληρωμένη'],
        'pending' => ['badge-pending', 'Εκκρεμής'],
        'overdue' => ['badge-overdue', 'Ληξιπρόθεσμη'],
        default   => ['badge-pending', h($status)],
    };
    return '<span class="badge ' . $cls . '">' . $lbl . '</span>';
}

function planBadge(string $slug): string {
    return '<span class="badge ' . ($slug === 'pro' ? 'badge-pro' : 'badge-basic') . '">'
         . ($slug === 'pro' ? '⭐ Pro' : 'Basic')
         . '</span>';
}

// ══════════════════════════════════════════════════════════════
// 17. ATHLETE LIMIT HELPERS
// ══════════════════════════════════════════════════════════════

function getAthleteLimit(): int {
    if (isSuperAdmin()) return 99999;
    $plan = schoolPlan();
    return (int)($plan['max_athletes'] ?? 60);
}

function getAthleteCount(): int {
    if (!schoolId()) return 0;
    try {
        $stmt = getDB()->prepare('SELECT COUNT(*) FROM athletes WHERE school_id = ? AND active = 1');
        $stmt->execute([schoolId()]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function isAthleteLimit(): bool {
    $limit = getAthleteLimit();
    if ($limit >= 9999) return false;
    return getAthleteCount() >= $limit;
}

// ══════════════════════════════════════════════════════════════
// 18. OVERDUE HELPERS
// ══════════════════════════════════════════════════════════════

function getAthleteOverdueMonths(int $athleteId): int {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT valid_until FROM subscriptions WHERE athlete_id = ? AND status = 'paid' ORDER BY valid_until DESC LIMIT 1");
        $stmt->execute([$athleteId]);
        $lastPaid = $stmt->fetchColumn();

        if (!$lastPaid) {
            $reg = $db->prepare('SELECT registration_date FROM athletes WHERE id = ?');
            $reg->execute([$athleteId]);
            $lastPaid = $reg->fetchColumn();
            if (!$lastPaid) return 0;
        }

        $now  = new DateTime();
        $last = new DateTime($lastPaid);
        if ($last >= $now) return 0;
        $diff = $now->diff($last);
        return max(0, $diff->y * 12 + $diff->m);
    } catch (Exception $e) {
        return 0;
    }
}

function getHeavilyOverdueAthletes(int $schoolId, int $minMonths = 2): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT a.id, a.full_name, a.phone, a.parent_phone, a.email, a.parent_email,
                   MAX(s.valid_until) AS last_paid_until
            FROM athletes a
            LEFT JOIN subscriptions s ON s.athlete_id = a.id AND s.status = 'paid'
            WHERE a.school_id = ? AND a.active = 1
            GROUP BY a.id
            HAVING (last_paid_until IS NULL OR last_paid_until < DATE_SUB(CURDATE(), INTERVAL ? MONTH))
            ORDER BY last_paid_until ASC
            LIMIT 20
        ");
        $stmt->execute([$schoolId, $minMonths]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ══════════════════════════════════════════════════════════════
// 19. SMS ADDON CHECK
// ══════════════════════════════════════════════════════════════

function schoolHasSmsAddon(): bool {
    if (isSuperAdmin()) return true;
    $plan = schoolPlan();
    if (($plan['slug'] ?? '') === 'pro') return true;
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT sms_addon, sms_addon_expires FROM schools WHERE id = ? LIMIT 1');
        $stmt->execute([schoolId()]);
        $row  = $stmt->fetch();
        if (!$row || !$row['sms_addon']) return false;
        if (!empty($row['sms_addon_expires']) && strtotime($row['sms_addon_expires']) < time()) return false;
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function schoolCanSendSms(): bool {
    return planHas('sms_enabled') || schoolHasSmsAddon();
}

// ══════════════════════════════════════════════════════════════
// 20. SUMMER PAUSE HELPER
// ══════════════════════════════════════════════════════════════

/**
 * Returns true if the given school is currently inside its summer pause window.
 * Conditions: global feature ON + school opted-in + today within pause months.
 * Used by cron (to skip athlete notifications) and manual send pages (to block sending).
 */
function isSchoolInSummerPause(int $schoolId): bool {
    if (!$schoolId) return false;
    try {
        $db = getDB();

        // Load global summer pause settings
        $keys = ['summer_pause_enabled', 'summer_pause_month', 'summer_pause_end_month'];
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($ph)");
        $stmt->execute($keys);
        $cfg = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cfg[$r['setting_key']] = $r['setting_value'];

        if (($cfg['summer_pause_enabled'] ?? '0') !== '1') return false;

        // Check school opted-in
        $sm = $db->prepare("SELECT meta_val FROM school_meta WHERE school_id=? AND meta_key='summer_pause_opted_in'");
        $sm->execute([$schoolId]);
        if ($sm->fetchColumn() !== '1') return false;

        // Check date window
        $pauseMonth = (int)($cfg['summer_pause_month'] ?? 7);
        $endMonth   = (int)($cfg['summer_pause_end_month'] ?? 8);
        $now        = new DateTimeImmutable();
        $year       = (int)$now->format('Y');
        $pauseStart = new DateTimeImmutable("{$year}-{$pauseMonth}-01");
        $pauseEnd   = (new DateTimeImmutable("{$year}-{$endMonth}-01"))->modify('last day of this month');

        return ($now >= $pauseStart && $now <= $pauseEnd);
    } catch (Throwable $e) {
        return false;
    }
}

// ══════════════════════════════════════════════════════════════
// 21. MAINTENANCE MODE
// ══════════════════════════════════════════════════════════════

function isMaintenanceMode(): bool {
    return getSetting('maintenance_mode', '0') === '1';
}

function checkMaintenance(): void {
    if (!isMaintenanceMode()) return;         // Fast path: maintenance is off

    // Superadmin bypasses maintenance completely
    if (isSuperAdmin()) return;

    // Pages exempt from maintenance redirect (to avoid loops + allow superadmin login)
    $exemptPages = ['maintenance.php', 'login.php', 'logout.php'];
    $currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    if (in_array($currentPage, $exemptPages, true)) return;

    // Redirect everyone else to the maintenance page
    header('HTTP/1.1 503 Service Unavailable');
    header('Retry-After: 3600');
    header('Location: ' . rtrim(APP_URL, '/') . '/maintenance.php');
    exit;
}

// ══════════════════════════════════════════════════════════════
// 21. MONTHLY INCOME REPORT
//     Στέλνεται αυτόματα από cron την 1η κάθε μήνα
// ══════════════════════════════════════════════════════════════

function sendMonthlyIncomeReport(int $schoolId, int $year, int $month): bool {
    $db = getDB();
    $school = $db->prepare('SELECT * FROM schools WHERE id = ? LIMIT 1');
    $school->execute([$schoolId]);
    $sc = $school->fetch();
    if (!$sc || !$sc['email']) return false;

    $inc = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id = ? AND type = 'income' AND YEAR(transaction_date) = ? AND MONTH(transaction_date) = ?");
    $inc->execute([$schoolId, $year, $month]);
    $income = (float)$inc->fetchColumn();

    $exp = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id = ? AND type = 'expense' AND YEAR(transaction_date) = ? AND MONTH(transaction_date) = ?");
    $exp->execute([$schoolId, $year, $month]);
    $expenses = (float)$exp->fetchColumn();

    $sub = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE school_id = ? AND status = 'paid' AND YEAR(paid_at) = ? AND MONTH(paid_at) = ?");
    $sub->execute([$schoolId, $year, $month]);
    $subs = (int)$sub->fetchColumn();

    $monthNames = ['','Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος',
                   'Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
    $monthName  = $monthNames[$month] ?? $month;
    $net        = $income - $expenses;
    $netColor   = $net >= 0 ? '#2dc653' : '#e63946';

    $html = <<<HTML
<!DOCTYPE html><html lang="el"><head><meta charset="UTF-8"><title>Μηνιαία Αναφορά</title></head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
<tr><td style="text-align:center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden;margin:0 auto">
<tr><td style="padding:28px 32px;text-align:center;border-bottom:2px solid #2a1520">
  <h1 style="margin:0;font-size:1.5rem;color:#f0f2ff;font-weight:800">{$sc['name']}</h1>
  <p style="margin:8px 0 0;font-size:.9rem;color:#8892b0">Μηνιαία Αναφορά — {$monthName} {$year}</p>
</td></tr>
<tr><td style="padding:32px">
  <table width="100%" cellpadding="12" style="border-collapse:collapse">
    <tr><td style="background:#0d1628;border-radius:10px;color:#8892b0;font-size:.85rem">💰 Έσοδα</td>
        <td style="background:#0d1628;border-radius:10px;color:#2dc653;font-size:1.3rem;font-weight:800;text-align:right">+{$income}€</td></tr>
    <tr><td style="height:8px"></td></tr>
    <tr><td style="background:#0d1628;border-radius:10px;color:#8892b0;font-size:.85rem">💸 Έξοδα</td>
        <td style="background:#0d1628;border-radius:10px;color:#e63946;font-size:1.3rem;font-weight:800;text-align:right">-{$expenses}€</td></tr>
    <tr><td style="height:8px"></td></tr>
    <tr><td style="background:#0d1628;border-radius:10px;color:#8892b0;font-size:.85rem">🎯 Καθαρό</td>
        <td style="background:#0d1628;border-radius:10px;color:{$netColor};font-size:1.4rem;font-weight:800;text-align:right">{$net}€</td></tr>
    <tr><td style="height:8px"></td></tr>
    <tr><td style="background:#0d1628;border-radius:10px;color:#8892b0;font-size:.85rem">✅ Συνδρομές</td>
        <td style="background:#0d1628;border-radius:10px;color:#f0a500;font-size:1.2rem;font-weight:800;text-align:right">{$subs}</td></tr>
  </table>
</td></tr>
</table>
</td></tr>
</table>
</body></html>
HTML;

    require_once __DIR__ . '/mailer.php';
    $result = sendEmail(
        $sc['email'],
        "Μηνιαία Αναφορά {$monthName} {$year} — {$sc['name']}",
        $html,
        "Έσοδα: {$income}€ | Έξοδα: {$expenses}€ | Καθαρό: {$net}€ | Συνδρομές: {$subs}",
        $sc['name']
    );

    try {
        $db->prepare("INSERT INTO monthly_income_reports (school_id,report_year,report_month,total_income,total_expenses,total_subscriptions)
                      VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sent_at = NOW()")
           ->execute([$schoolId, $year, $month, $income, $expenses, $subs]);
    } catch (Exception $e) {
        error_log('[MAster] Monthly report DB log failed: ' . $e->getMessage());
    }

    return $result;
}

// ══════════════════════════════════════════════════════════════
// 22. REQUIRE OTHER MODULES
//     Φορτώνονται ΤΕΛΕΥΤΑΙΑ — μετά από όλες τις functions
//     ώστε να έχουν διαθέσιμες τις παραπάνω helpers.
// ══════════════════════════════════════════════════════════════
require_once __DIR__ . '/mailer.php';

if (!function_exists('getCookieConsent')) {
    require_once __DIR__ . '/cookie_consent.php';
}

require_once __DIR__ . '/usage_tracker.php';

// Security headers — ΠΡΙΝ από οποιαδήποτε output
require_once __DIR__ . '/security_headers.php';

// ── Maintenance mode gate — runs on EVERY page automatically ──
// Superadmin bypasses. login.php / logout.php / maintenance.php are exempt.
// checkMaintenance() is defined in section 20 above.
checkMaintenance();