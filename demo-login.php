<?php
/**
 * demo-login.php — one-click login into the seeded demo school.
 * ----------------------------------------------------------------
 * Logs the visitor in as demo@master-app.gr (owner of "Demo Σύλλογος
 * MAster", seeded by migration 014). No password required — this
 * endpoint IS the demo pass. Sets a session flag so the dashboard
 * can show a "Demo Mode" banner and future guardrails can suppress
 * destructive actions if desired.
 * ----------------------------------------------------------------
 */

require_once __DIR__ . '/includes/config.php';

$db = getDB();
$stmt = $db->prepare("
    SELECT u.*, s.name AS school_name
      FROM users u
      LEFT JOIN schools s ON s.id = u.school_id
     WHERE u.email = 'demo@master-app.gr' AND u.active = 1
     LIMIT 1
");
$stmt->execute();
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) {
    // Migration 014 hasn't run yet, or was rolled back.
    flash('Ο demo λογαριασμός δεν έχει ρυθμιστεί ακόμα. Δοκιμάστε ξανά σε λίγο.', 'danger');
    redirect(APP_URL . '/');
}

session_regenerate_id(true);
$_SESSION['user_id']     = (int)$u['id'];
$_SESSION['school_id']   = (int)$u['school_id'];
$_SESSION['school_name'] = $u['school_name'] ?? 'Demo Σύλλογος';
$_SESSION['user'] = [
    'id'    => (int)$u['id'],
    'name'  => $u['name'],
    'email' => $u['email'],
    'role'  => $u['role'],
    'avatar'=> null,
];
$_SESSION['is_demo'] = true;

try {
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
       ->execute([(int)$u['id']]);
} catch (Throwable $e) { /* non-fatal */ }

redirect(APP_URL . '/dashboard/');
