<?php
/**
 * ============================================================
 * logout.php — Αποσύνδεση Χρήστη
 * ============================================================
 * PURPOSE:
 *   Καθαρίζει εντελώς την session και τα remember-me cookies,
 *   καταγράφει την αποσύνδεση, και ανακατευθύνει στο login.
 *
 * SECURITY:
 *   ✓ session_unset() + session_destroy() — πλήρης εκκαθάριση
 *   ✓ Διαγραφή remember-me cookies (server-side invalidation)
 *   ✓ auditLog() — καταγράφει αποσύνδεση πριν destroy
 *   ✓ Δεν απαιτεί CSRF: logout-via-GET είναι αποδεκτό
 * ============================================================
 */

require_once __DIR__ . '/includes/config.php';

// Detect parent portal session before destroying
$isParentSession = isset($_SESSION['is_parent']) && $_SESSION['is_parent'] === true;

// Καταγραφή αποσύνδεσης ΠΡΙΝ την καταστροφή της session
// (μετά το session_destroy δεν υπάρχει userId/schoolId)
if (isLoggedIn()) {
    auditLog('logout');
}

// ── Εκκαθάριση remember-me token από ΒΔ ────────────────────
// Αποτρέπει auto-login μετά από logout
if (isLoggedIn()) {
    try {
        $uid = userId();
        if ($uid) {
            getDB()->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")
                   ->execute([$uid]);
        }
    } catch (Exception $e) {
        // Silent — δεν πρέπει να εμποδίσει το logout
    }
}

// ── Διαγραφή remember-me cookies ────────────────────────────
// Expire στο παρελθόν: ο browser τα αφαιρεί αμέσως
foreach (['rm_email', 'rm_token'] as $cookieName) {
    if (isset($_COOKIE[$cookieName])) {
        setcookie($cookieName, '', time() - 3600, '/', '', true, true);
    }
}

// ── Πλήρης καταστροφή session ───────────────────────────────
// session_unset(): αδειάζει τα data
// session_destroy(): καταστρέφει την session αρχείο/record
session_unset();
session_destroy();

// ── Redirect στο login ──────────────────────────────────────
// Parents are redirected back to the parent portal login
if ($isParentSession) {
    header('Location: ' . APP_URL . '/parent/login.php');
} else {
    header('Location: ' . APP_URL . '/login.php');
}
exit;
