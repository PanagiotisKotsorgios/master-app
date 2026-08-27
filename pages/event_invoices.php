<?php
/**
 * pages/event_invoices.php — legacy redirect
 * ============================================================
 * The invoices view has been merged into pages/events.php as the
 * "Τιμολόγια" tab. This file exists only so old links / bookmarks
 * still land on the right place.
 */

require_once __DIR__ . '/../includes/config.php';
redirect(APP_URL . '/pages/events.php?tab=invoices');
