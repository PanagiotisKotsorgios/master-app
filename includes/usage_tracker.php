<?php

/**
 * ============================================================
 * includes/usage_tracker.php — Email/SMS Usage Tracking
 * ============================================================
 * PURPOSE:
 *   Παρακολουθεί και περιορίζει την κατανάλωση email/SMS
 *   ανά σχολή. Αποτρέπει abuse και κόστος εκτός ελέγχου.
 *
 * HOW IT WORKS:
 *   1. logMessageUsage(): καταγράφει κάθε αποστολή στο usage_log
 *   2. canSendEmail/canSendSms(): ελέγχει αν επιτρέπεται αποστολή
 *   3. getAllSchoolsUsageStats(): για admin reporting
 *
 * LIMITS (configurable σε system_settings):
 *   - daily_email_limit: default 200/ημέρα ανά σχολή
 *   - daily_sms_limit: default 100/ημέρα ανά σχολή
 *
 * SECURITY:
 *   ✓ School-level isolation: usage_log.school_id
 *   ✓ Silent fail: δεν crash η εφαρμογή αν αποτύχει logging
 *   ✓ Prepared statements
 * ============================================================
 */

function logMessageUsage(
    int    $schoolId,
    int    $userId,
    string $type,         // 'email' | 'sms'
    string $recipient,
    string $subject = '',
    string $status = 'sent' // 'sent' | 'failed'
): void {
    try {
        getDB()->prepare(
            "INSERT INTO usage_log (school_id, user_id, type, recipient, subject, status, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        )->execute([$schoolId, $userId, $type, $recipient, $subject, $status]);
    } catch (Exception $e) {
        error_log('[usage_tracker] logMessageUsage failed: ' . $e->getMessage());
    }
}

/**
 * Ελέγχει αν ο χρήστης/σχολή έχει υπερβεί το daily limit
 * Επιστρέφει true αν επιτρέπεται η αποστολή, false αν έχει μπλοκαριστεί
 */
function checkUsageLimit(int $schoolId, string $type = 'email'): bool {
    try {
        $db = getDB();

        // Παίρνουμε το όριο από τις ρυθμίσεις του admin
        $limitKey = "daily_{$type}_limit";
        $limit = (int)getSetting($limitKey, '200'); // default 200/day
        if ($limit <= 0) return true; // 0 = unlimited

        // Μετράμε αποστολές σήμερα για αυτή τη σχολή
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM usage_log
             WHERE school_id = ? AND type = ?
               AND DATE(sent_at) = CURDATE()
               AND status = 'sent'"
        );
        $stmt->execute([$schoolId, $type]);
        $todayCount = (int)$stmt->fetchColumn();

        return $todayCount < $limit;
    } catch (Exception $e) {
        error_log('[usage_tracker] checkUsageLimit error: ' . $e->getMessage());
        return true; // fail-open: αν υπάρχει πρόβλημα με τη βάση, επιτρέπουμε
    }
}

/**
 * Ελέγχει μηνιαίο όριο SMS/email ανά σχολή (configurable από superadmin)
 * Επιστρέφει true αν επιτρέπεται, false αν έχει υπερβεί το μηνιαίο όριο
 */
function checkMonthlyUsageLimit(int $schoolId, string $type = 'sms'): bool {
    try {
        $db = getDB();
        $limitKey = "monthly_{$type}_limit";
        $limit = (int)getSetting($limitKey, '0'); // 0 = unlimited
        if ($limit <= 0) return true;

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM usage_log
             WHERE school_id = ? AND type = ?
               AND MONTH(sent_at) = MONTH(CURDATE())
               AND YEAR(sent_at)  = YEAR(CURDATE())
               AND status = 'sent'"
        );
        $stmt->execute([$schoolId, $type]);
        $monthCount = (int)$stmt->fetchColumn();

        return $monthCount < $limit;
    } catch (Exception $e) {
        error_log('[usage_tracker] checkMonthlyUsageLimit error: ' . $e->getMessage());
        return true;
    }
}

/**
 * Επιστρέφει το μηνιαίο usage για μια σχολή (μόνο τρέχον μήνα)
 * @return array ['sms' => int, 'email' => int, 'sms_limit' => int, 'email_limit' => int]
 */
function getMonthlyUsage(int $schoolId): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
              SUM(type='sms'   AND status='sent') AS month_sms,
              SUM(type='email' AND status='sent') AS month_email
            FROM usage_log
            WHERE school_id = ?
              AND MONTH(sent_at) = MONTH(CURDATE())
              AND YEAR(sent_at)  = YEAR(CURDATE())
        ");
        $stmt->execute([$schoolId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'sms'         => (int)($row['month_sms']   ?? 0),
            'email'       => (int)($row['month_email'] ?? 0),
            'sms_limit'   => (int)getSetting('monthly_sms_limit', '0'),
            'email_limit' => (int)getSetting('monthly_email_limit', '0'),
        ];
    } catch (Exception $e) {
        return ['sms' => 0, 'email' => 0, 'sms_limit' => 0, 'email_limit' => 0];
    }
}

/**
 * Ελέγχει αν η σχολή έχει ανοιχτό αίτημα αγοράς πακέτου SMS/email
 */
function hasPendingOverageRequest(int $schoolId, string $type = 'sms'): bool {
    try {
        $db = getDB();
        ensureOverageRequestsTable();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM overage_requests
             WHERE school_id=? AND type=? AND status='pending'"
        );
        $stmt->execute([$schoolId, $type]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Δημιουργεί αίτημα αγοράς επιπλέον πακέτου SMS/email
 */
function createOverageRequest(int $schoolId, string $type, int $qty, float $amount): int {
    try {
        $db = getDB();
        ensureOverageRequestsTable();
        $db->prepare(
            "INSERT INTO overage_requests (school_id, type, qty, amount, status, created_at)
             VALUES (?, ?, ?, ?, 'pending', NOW())"
        )->execute([$schoolId, $type, $qty, $amount]);
        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        error_log('[usage_tracker] createOverageRequest error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Migration: δημιουργεί τον πίνακα overage_requests αν δεν υπάρχει
 */
function ensureOverageRequestsTable(): void {
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS `overage_requests` (
              `id`          INT NOT NULL AUTO_INCREMENT,
              `school_id`   INT NOT NULL,
              `type`        ENUM('sms','email') NOT NULL DEFAULT 'sms',
              `qty`         INT NOT NULL DEFAULT 0,
              `amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `commission`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `status`      ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
              `notes`       TEXT DEFAULT NULL,
              `created_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              `paid_at`     TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_school_type_status` (`school_id`,`type`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log('[usage_tracker] ensureOverageRequestsTable: ' . $e->getMessage());
    }
}

// Εκτέλεση migration για overage_requests
ensureOverageRequestsTable();

/**
 * Επιστρέφει την κατανάλωση email/SMS για μια σχολή (για admin stats)
 * @return array ['today_email', 'today_sms', 'month_email', 'month_sms', 'total_email', 'total_sms']
 */
function getSchoolUsageStats(int $schoolId): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
              SUM(type='email' AND DATE(sent_at)=CURDATE() AND status='sent')     AS today_email,
              SUM(type='sms'   AND DATE(sent_at)=CURDATE() AND status='sent')     AS today_sms,
              SUM(type='email' AND MONTH(sent_at)=MONTH(NOW()) AND YEAR(sent_at)=YEAR(NOW()) AND status='sent') AS month_email,
              SUM(type='sms'   AND MONTH(sent_at)=MONTH(NOW()) AND YEAR(sent_at)=YEAR(NOW()) AND status='sent') AS month_sms,
              SUM(type='email' AND status='sent')  AS total_email,
              SUM(type='sms'   AND status='sent')  AS total_sms
            FROM usage_log WHERE school_id = ?
        ");
        $stmt->execute([$schoolId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'today_email' => (int)($row['today_email'] ?? 0),
            'today_sms'   => (int)($row['today_sms']   ?? 0),
            'month_email' => (int)($row['month_email'] ?? 0),
            'month_sms'   => (int)($row['month_sms']   ?? 0),
            'total_email' => (int)($row['total_email'] ?? 0),
            'total_sms'   => (int)($row['total_sms']   ?? 0),
        ];
    } catch (Exception $e) {
        return ['today_email'=>0,'today_sms'=>0,'month_email'=>0,'month_sms'=>0,'total_email'=>0,'total_sms'=>0];
    }
}

/**
 * Επιστρέφει συγκεντρωτικά στατιστικά ΟΛΩΝ των σχολών (μόνο για superadmin)
 */
function getAllSchoolsUsageStats(): array {
    try {
        $db = getDB();
        $rows = $db->query("
            SELECT
              ul.school_id,
              s.name AS school_name,
              SUM(ul.type='email' AND DATE(ul.sent_at)=CURDATE() AND ul.status='sent')  AS today_email,
              SUM(ul.type='sms'   AND DATE(ul.sent_at)=CURDATE() AND ul.status='sent')  AS today_sms,
              SUM(ul.type='email' AND MONTH(ul.sent_at)=MONTH(NOW()) AND YEAR(ul.sent_at)=YEAR(NOW()) AND ul.status='sent') AS month_email,
              SUM(ul.type='sms'   AND MONTH(ul.sent_at)=MONTH(NOW()) AND YEAR(ul.sent_at)=YEAR(NOW()) AND ul.status='sent') AS month_sms,
              SUM(ul.type='email' AND ul.status='sent') AS total_email,
              SUM(ul.type='sms'   AND ul.status='sent') AS total_sms,
              MAX(ul.sent_at) AS last_activity
            FROM usage_log ul
            LEFT JOIN schools s ON s.id = ul.school_id
            GROUP BY ul.school_id
            ORDER BY month_email DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * SQL migration — εκτελείται μια φορά για να δημιουργήσει τον πίνακα
 */
function ensureUsageLogTable(): void {
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS `usage_log` (
              `id`          INT NOT NULL AUTO_INCREMENT,
              `school_id`   INT DEFAULT NULL,
              `user_id`     INT DEFAULT NULL,
              `type`        ENUM('email','sms') NOT NULL DEFAULT 'email',
              `recipient`   VARCHAR(200) DEFAULT NULL,
              `subject`     VARCHAR(500) DEFAULT NULL,
              `status`      ENUM('sent','failed') NOT NULL DEFAULT 'sent',
              `sent_at`     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_school_type_date` (`school_id`,`type`,`sent_at`),
              KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log('[usage_tracker] ensureUsageLogTable: ' . $e->getMessage());
    }
}

// Εκτέλεση migration κατά το load
ensureUsageLogTable();
