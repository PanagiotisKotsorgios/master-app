<?php
/**
 * ============================================================
 * employee/privileges.php — Employee Privileges Helper
 * ============================================================
 * Loaded after auth.php. Fetches the current employee's
 * privilege row from employee_privileges and exposes:
 *
 *   empCan(string $priv): bool   — check a single privilege
 *   empRequire(string $priv)     — redirect with error if denied
 *   getEmpPrivileges(): array    — full privilege row
 *
 * Usage in employee pages:
 *   require_once __DIR__ . '/privileges.php';
 *   empRequire('schools_view');        // gate page
 *   if (empCan('schools_edit')) { … } // gate UI element
 *
 * ── WHY NO SESSION CACHE ────────────────────────────────────
 * Session caching caused a critical bug: if the admin granted
 * privileges while the employee was already logged in, the
 * employee's session held a stale empty [] forever until they
 * manually logged out and back in. The DB query is cheap
 * (single indexed lookup by user_id) so we simply query fresh
 * on every request using a static variable scoped to the
 * current request only.
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';

function _loadEmpPrivileges(): array {
    // Static cache: valid only for this single HTTP request.
    // No session caching — avoids stale privileges when admin
    // grants/revokes access while the employee is logged in.
    static $loaded = null;
    if ($loaded !== null) return $loaded;

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        $loaded = [];
        return [];
    }

    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM employee_privileges WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row    = $stmt->fetch(PDO::FETCH_ASSOC);
        $loaded = $row ?: [];
    } catch (Throwable $e) {
        error_log('employee/privileges.php: failed to load privileges — ' . $e->getMessage());
        $loaded = [];
    }

    return $loaded;
}

/**
 * Check whether the current employee has a specific privilege.
 * Always returns true for superadmin (fallback safety).
 */
function empCan(string $priv): bool {
    if (isSuperAdmin()) return true;
    $privs = _loadEmpPrivileges();
    return !empty($privs[$priv]);
}

/**
 * Require a privilege or redirect with a flash error.
 */
function empRequire(string $priv): void {
    if (!empCan($priv)) {
        flash('Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη λειτουργία.', 'danger');
        redirect(APP_URL . '/employee/');
    }
}

/**
 * Get full privilege array for current user.
 */
function getEmpPrivileges(): array {
    return _loadEmpPrivileges();
}

/**
 * @deprecated No longer needed — session caching was removed.
 * Kept for backwards compatibility if called anywhere.
 */
function clearEmpPrivilegesCache(): void {
    // No-op: session cache removed. Privileges are always read
    // fresh from DB on each request via the static variable.
}