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
 * We also load prelogin-portal-theme.css AFTER the polish so the
 * light navy+gold tkd_portal look wins the cascade. To revert to
 * the dark theme, remove the portal-theme <link> tag or revert
 * the commit that added it.
 *
 * Version query = file mtime, so browsers always fetch a fresh
 * copy after a deploy without any cache config changes needed.
 */

$__polish_file  = __DIR__ . '/../assets/css/prelogin-polish.css';
$__portal_file  = __DIR__ . '/../assets/css/prelogin-portal-theme.css';
$__polish_ver   = @filemtime($__polish_file) ?: time();
$__portal_ver   = @filemtime($__portal_file) ?: $__polish_ver;

$__polish_base  = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
$__polish_href  = $__polish_base !== ''
    ? $__polish_base . '/assets/css/prelogin-polish.css?v=' . $__polish_ver
    : '/assets/css/prelogin-polish.css?v=' . $__polish_ver;
$__portal_href  = $__polish_base !== ''
    ? $__polish_base . '/assets/css/prelogin-portal-theme.css?v=' . $__portal_ver
    : '/assets/css/prelogin-portal-theme.css?v=' . $__portal_ver;
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__polish_href, ENT_QUOTES, 'UTF-8') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">
<link rel="stylesheet" href="<?= htmlspecialchars($__portal_href, ENT_QUOTES, 'UTF-8') ?>">
