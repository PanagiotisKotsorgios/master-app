<?php
/**
 * ============================================================
 * employee/auth.php — Employee Panel Auth Guard
 * ============================================================
 * Φορτώνεται στην αρχή κάθε σελίδας του /employee panel.
 * Επιτρέπει access σε role = 'employee' και legacy 'maintainer'.
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';

function isEmployeeRole(?string $role = null): bool {
    $role = $role ?? (string)($_SESSION['user']['role'] ?? '');
    return in_array($role, ['employee', 'maintainer'], true);
}

// SECURITY: Parent sessions δεν έχουν πρόσβαση στο employee panel.
// Απόρριψη ΠΡΙΝ το isLoggedIn() check — parent sessions έχουν user_id
// στο SESSION και θα περνούσαν τον έλεγχο χωρίς αυτή την προστασία.
if (isset($_SESSION['is_parent']) && $_SESSION['is_parent'] === true) {
    redirect(APP_URL . '/parent/index.php');
}

if (!isClubLoggedIn()) {
    $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? '';
    redirect(APP_URL . '/employee/login.php');
}

if (!isEmployeeRole()) {
    if (isSuperAdmin()) {
        redirect(APP_URL . '/admin/');
    }
    redirect(APP_URL . '/dashboard/');
}

function isEmployee(): bool {
    return isEmployeeRole();
}