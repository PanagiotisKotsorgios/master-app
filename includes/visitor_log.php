<?php
// ════════════════════════════════════════════════════
// includes/visitor_log.php  (cookie-only έκδοση)
// Δεν χρειάζεται βάση δεδομένων.
// Επιστρέφει true αν ο επισκέπτης πρέπει να δει το popup σήμερα.
// ════════════════════════════════════════════════════

function logVisitorAndCheckPopup(): bool {
    $cookieName = 'master_popup_seen';

    if (!isset($_COOKIE[$cookieName])) {
        setcookie(
            $cookieName,
            date('Y-m-d'),
            [
                'expires'  => time() + 86400,   // 24 ώρες
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => false,
                'samesite' => 'Lax',
            ]
        );
        return true;  // Δείξε popup
    }

    return false; // Το έχει ήδη δει σήμερα
}