<?php
/**
 * ============================================================
 * includes/rate_limit.php
 * ============================================================
 * PURPOSE:
 *   Προστασία από Brute-Force attacks στο login endpoint.
 *   Περιορίζει τον αριθμό αποτυχημένων προσπαθειών ανά IP/email.
 *
 * HOW IT WORKS:
 *   1. Κάθε αποτυχημένη προσπάθεια καταγράφεται στη ΒΔ
 *   2. Αν IP ή email έχει >= MAX_ATTEMPTS σε WINDOW_SECONDS → lockout
 *   3. Lockout διαρκεί LOCKOUT_SECONDS
 *   4. Μετά από επιτυχές login → reset counter
 *
 * THREAT MODEL:
 *   - Online brute force: επιτιθέμενος δοκιμάζει passwords
 *   - Credential stuffing: leaked credentials από άλλα sites
 *   - Password spraying: 1 password σε πολλούς accounts
 *
 * DEFENSE LAYERS:
 *   - IP-based: αποτρέπει attacks από 1 IP
 *   - Email-based: αποτρέπει distributed attacks (πολλά IPs, 1 account)
 *   - Timing-safe: ίδιο response time για valid/invalid email
 *     (αποτρέπει user enumeration μέσω timing differences)
 *
 * DB TABLE REQUIRED:
 *   CREATE TABLE login_attempts (
 *     id           INT AUTO_INCREMENT PRIMARY KEY,
 *     ip           VARCHAR(45)  NOT NULL,
 *     email        VARCHAR(255) NOT NULL,
 *     attempted_at INT          NOT NULL,
 *     INDEX idx_ip    (ip),
 *     INDEX idx_email (email),
 *     INDEX idx_time  (attempted_at)
 *   );
 *
 * TUNING:
 *   Αύξησε MAX_ATTEMPTS αν οι users κλειδώνονται συχνά.
 *   Μείωσε WINDOW_SECONDS για πιο aggressive blocking.
 * ============================================================
 */

// ── Configuration Constants ───────────────────────────────────
// Διάστημα παρακολούθησης σε seconds (15 λεπτά)
define('RL_WINDOW_SECONDS',  900);
// Μέγιστος αριθμός αποτυχιών εντός του window
define('RL_MAX_ATTEMPTS',    10);
// Διάρκεια lockout σε seconds (30 λεπτά)
define('RL_LOCKOUT_SECONDS', 1800);

/**
 * Ελέγχει αν το IP ή email έχει υπερβεί το όριο.
 * Αν ναι, επιστρέφει HTTP 429 Too Many Requests και τερματίζει.
 *
 * TIMING ATTACK PREVENTION:
 *   Αυτή η function καλείται ΠΡΙΝ τον έλεγχο password.
 *   Έτσι, attackers δεν μαθαίνουν αν το email υπάρχει
 *   (αποτρέπει user enumeration).
 *
 * @param string $email  Email που επιχειρεί login
 * @param string $ip     IP address του αιτήματος
 */
function checkLoginRateLimit(string $email, string $ip): void {
    $db  = getDB();
    $now = time();

    // ── Cleanup: αφαίρεση παλιών εγγραφών ───────────────────
    // Τρέχει σε κάθε check (probabilistic cleanup).
    // Εναλλακτικά: cron job για καλύτερη performance.
    $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
       ->execute([$now - RL_LOCKOUT_SECONDS]);

    // ── Count recent failures για IP ή email ─────────────────
    // Ελέγχουμε ΚΑΙ τα δύο:
    //   - IP check: αποτρέπει 1 IP → πολλά emails
    //   - Email check: αποτρέπει πολλά IPs → 1 email
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE (ip = ? OR email = ?) AND attempted_at > ?'
    );
    $stmt->execute([$ip, strtolower($email), $now - RL_WINDOW_SECONDS]);
    $attempts = (int)$stmt->fetchColumn();

    if ($attempts >= RL_MAX_ATTEMPTS) {
        // ── Υπολογισμός χρόνου μέχρι ξεμπλοκαρίσματος ───────
        $firstStmt = $db->prepare(
            'SELECT MIN(attempted_at) FROM login_attempts
             WHERE (ip = ? OR email = ?) AND attempted_at > ?'
        );
        $firstStmt->execute([$ip, strtolower($email), $now - RL_WINDOW_SECONDS]);
        $firstAttempt = (int)$firstStmt->fetchColumn();
        $retryAfter   = max(0, ($firstAttempt + RL_LOCKOUT_SECONDS) - $now);

        // ── Log για monitoring ────────────────────────────────
        error_log(sprintf(
            '[MAster Security] Login rate limit exceeded — IP: %s, Email: %s, Attempts: %d',
            $ip, $email, $attempts
        ));

        // ── HTTP 429 Response ─────────────────────────────────
        // Retry-After: πότε μπορεί να ξαναδοκιμάσει (RFC 6585)
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        header('Content-Type: application/json; charset=utf-8');

        die(json_encode([
            'error'       => 'Πολλές αποτυχημένες προσπάθειες σύνδεσης. '
                           . 'Δοκιμάστε ξανά σε ' . ceil($retryAfter / 60) . ' λεπτά.',
            'retry_after' => $retryAfter,
        ], JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Καταγράφει αποτυχημένη προσπάθεια login.
 *
 * ΚΑΛΕΙΤΑΙ ΜΕΤΑ από αποτυχία password verification.
 * ΟΧΙ πριν (αποτρέπει timing attacks).
 *
 * @param string $email  Email που χρησιμοποιήθηκε
 * @param string $ip     IP address του αιτήματος
 */
function recordFailedLogin(string $email, string $ip): void {
    try {
        getDB()->prepare(
            'INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, ?, ?)'
        )->execute([$ip, strtolower($email), time()]);
    } catch (Exception $e) {
        // Silent fail — μην crash το login flow για DB error
        error_log('[MAster] recordFailedLogin failed: ' . $e->getMessage());
    }
}

/**
 * Καθαρίζει τα failed attempts μετά από επιτυχές login.
 * Επιτρέπει τον user να συνδεθεί ξανά χωρίς να περιμένει
 * το lockout να λήξει.
 *
 * @param string $email  Email που συνδέθηκε επιτυχώς
 * @param string $ip     IP address
 */
function clearLoginAttempts(string $email, string $ip): void {
    try {
        getDB()->prepare(
            'DELETE FROM login_attempts WHERE (ip = ? OR email = ?)'
        )->execute([$ip, strtolower($email)]);
    } catch (Exception $e) {
        error_log('[MAster] clearLoginAttempts failed: ' . $e->getMessage());
    }
}
