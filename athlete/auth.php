<?php
/**
 * ============================================================
 * athlete/auth.php — Adult Athlete Portal auth helpers
 * ============================================================
 * Mirrors parent/auth.php but for the athlete_users table.
 * Session flag: $_SESSION['is_athlete'] = true
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';

function requireAthleteLogin(): void {
    if (!isAthlete()) {
        redirect(APP_URL . '/parent/login.php');
    }
}

function isAthlete(): bool {
    return isset($_SESSION['user_id'])
        && isset($_SESSION['is_athlete'])
        && $_SESSION['is_athlete'] === true;
}

function athleteSchoolId(): int {
    return (int)($_SESSION['school_id'] ?? 0);
}

function athleteUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function athleteRecordId(): int {
    return (int)($_SESSION['athlete_id'] ?? 0);
}

/**
 * Full row from athletes table for the logged-in athlete.
 * Cached per request.
 */
function currentAthlete(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $db  = getDB();
    $aid = athleteRecordId();
    $sid = athleteSchoolId();
    if (!$aid || !$sid) return $cached = [];

    $stmt = $db->prepare("
        SELECT a.*, d.name AS department_name
        FROM athletes a
        LEFT JOIN departments d ON d.id = a.department_id
        WHERE a.id = ? AND a.school_id = ?
        LIMIT 1
    ");
    $stmt->execute([$aid, $sid]);
    return $cached = ($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

function athleteSchoolName(): string {
    return (string)($_SESSION['school_name'] ?? 'MAster');
}

/**
 * Monthly subscription rows for the athlete (paid + due).
 * Reuses the same schema as parent portal so numbers match.
 */
function getAthleteOwnMonthlyPayments(): array {
    if (!function_exists('getAthleteMonthlyPayments')) {
        require_once __DIR__ . '/../parent/auth.php';
    }
    return getAthleteMonthlyPayments(athleteRecordId());
}

function getAthleteOwnDocuments(): array {
    $db  = getDB();
    $sid = athleteSchoolId();
    $aid = athleteRecordId();
    $stmt = $db->prepare("
        SELECT * FROM athlete_documents
        WHERE school_id = ? AND athlete_id = ?
          AND verified_by_school = 1
        ORDER BY created_at DESC
    ");
    $stmt->execute([$sid, $aid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Document type labels for the athlete portal UI.
 */
function athleteDocTypes(): array {
    require_once __DIR__ . '/../includes/athlete_documents.php';
    return athleteDocumentTypes();
}
