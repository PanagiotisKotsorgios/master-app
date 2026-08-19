<?php
/**
 * prelogin_polish.php — includes the modern polish stylesheet
 * on every pre-login page. Call once inside <head>, AFTER the
 * page's own inline <style> block so this layer wins the cascade.
 *
 * Usage in each pre-login page:
 *   <?php include __DIR__ . '/includes/prelogin_polish.php'; ?>
 * or from /legal/:
 *   <?php include __DIR__ . '/../includes/prelogin_polish.php'; ?>
 *
 * We compute the correct URL from APP_URL (falls back to relative
 * if APP_URL is unavailable). Version query = file mtime, so
 * browsers always fetch a fresh copy after a deploy without any
 * cache config changes needed.
 */

$__polish_file = __DIR__ . '/../assets/css/prelogin-polish.css';
$__polish_ver  = @filemtime($__polish_file) ?: time();

$__polish_base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
$__polish_href = $__polish_base !== ''
    ? $__polish_base . '/assets/css/prelogin-polish.css?v=' . $__polish_ver
    : '/assets/css/prelogin-polish.css?v=' . $__polish_ver;
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__polish_href, ENT_QUOTES, 'UTF-8') ?>">
