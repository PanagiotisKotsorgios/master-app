<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
renderPaymentWall();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/pages/notifications.php');
}

verifyCsrf();

if (!defined('INTERNAL_CRON_ALLOWED')) {
    define('INTERNAL_CRON_ALLOWED', true);
}

ob_start();

try {
    require __DIR__ . '/../cron/reminders.php';
    $output = trim(ob_get_clean());

    flash(
        'Το cron εκτελέστηκε επιτυχώς.' .
        ($output ? '<br><small style="display:block;margin-top:.35rem;white-space:pre-wrap">' . h(mb_substr($output, 0, 1200)) . '</small>' : ''),
        'success'
    );
} catch (Throwable $e) {
    ob_end_clean();
    flash('Σφάλμα εκτέλεσης cron: ' . h($e->getMessage()), 'danger');
}

redirect(APP_URL . '/pages/notifications.php');