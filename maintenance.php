<?php
/**
 * maintenance.php — Σελίδα Συντήρησης
 * Εμφανίζεται σε όλους τους επισκέπτες όταν το maintenance mode είναι ενεργό.
 * Η σελίδα αυτή ΔΕΝ καλεί checkMaintenance() ώστε να αποφευχθεί redirect loop.
 */

// Minimal bootstrap — only what we need
require_once __DIR__ . '/includes/config.php';

// If maintenance is OFF, redirect to home (someone typed the URL directly)
if (!isMaintenanceMode()) {
    redirect(APP_URL . '/');
}

$msg = getSetting('maintenance_message', 'Γίνονται εργασίες μέσα στην πλατφόρμα. Ευχαριστούμε για την κατανόηση.');

http_response_code(503);
header('Retry-After: 3600');
?><!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Συντήρηση — MAster</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:    #07090f;
  --card:  #111520;
  --brd:   #1e2536;
  --red:   #e63946;
  --gold:  #f0a500;
  --white: #f0f2ff;
  --muted: #8892b0;
  --dim:   #4a5270;
}
html { -webkit-text-size-adjust: 100%; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg);
  color: var(--white);
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2rem 1.5rem;
  text-align: center;
}

/* Animated background dots */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background:
    radial-gradient(ellipse 80% 50% at 20% 20%, rgba(230,57,70,.06) 0%, transparent 60%),
    radial-gradient(ellipse 60% 40% at 80% 80%, rgba(240,165,0,.04) 0%, transparent 60%);
  pointer-events: none;
  z-index: 0;
}

.wrap {
  position: relative;
  z-index: 1;
  max-width: 540px;
  width: 100%;
}

/* Logo */
.logo {
  margin-bottom: 2.5rem;
}
.logo img {
  height: clamp(56px, 14vw, 80px);
  width: auto;
  object-fit: contain;
  opacity: .85;
}

/* Icon */
.icon-wrap {
  width: clamp(80px, 20vw, 112px);
  height: clamp(80px, 20vw, 112px);
  background: rgba(230,57,70,.1);
  border: 2px solid rgba(230,57,70,.25);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 2rem;
  animation: pulse 3s ease-in-out infinite;
}
.icon-wrap svg {
  width: clamp(36px, 9vw, 52px);
  height: clamp(36px, 9vw, 52px);
  fill: none;
  stroke: var(--red);
  stroke-width: 1.5;
  stroke-linecap: round;
  stroke-linejoin: round;
  animation: spin 8s linear infinite;
}
@keyframes pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(230,57,70,0); }
  50%       { box-shadow: 0 0 0 12px rgba(230,57,70,.08); }
}
@keyframes spin {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

/* Card */
.card {
  background: var(--card);
  border: 1px solid var(--brd);
  border-radius: 24px;
  padding: clamp(1.75rem, 5vw, 2.5rem) clamp(1.5rem, 5vw, 2.5rem);
  box-shadow: 0 24px 80px rgba(0,0,0,.6);
}

h1 {
  font-family: 'Bebas Neue', sans-serif;
  font-size: clamp(2rem, 7vw, 2.8rem);
  letter-spacing: .04em;
  color: var(--white);
  margin-bottom: .5rem;
  line-height: 1.1;
}

.subtitle {
  font-size: clamp(.85rem, 2.5vw, 1rem);
  color: var(--red);
  font-weight: 800;
  letter-spacing: .12em;
  text-transform: uppercase;
  margin-bottom: 1.25rem;
}

.message {
  font-size: clamp(.95rem, 2.8vw, 1.05rem);
  color: var(--muted);
  line-height: 1.75;
  margin-bottom: 2rem;
}

/* Progress-style decoration */
.progress-bar {
  width: 100%;
  height: 3px;
  background: var(--brd);
  border-radius: 99px;
  overflow: hidden;
  margin-bottom: 2rem;
}
.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--red), var(--gold), var(--red));
  background-size: 200% 100%;
  border-radius: 99px;
  animation: shimmer 2.5s linear infinite;
  width: 60%;
}
@keyframes shimmer {
  0%   { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* Info grid */
.info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .75rem;
  margin-bottom: 2rem;
}
.info-item {
  background: rgba(255,255,255,.03);
  border: 1px solid var(--brd);
  border-radius: 12px;
  padding: .85rem 1rem;
  display: flex;
  align-items: center;
  gap: .6rem;
  font-size: .85rem;
  color: var(--muted);
  text-align: left;
}
.info-item svg {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
  stroke: var(--gold);
  fill: none;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}

/* Admin login link (subtle, for superadmin) */
.admin-link {
  display: inline-block;
  margin-top: 1.5rem;
  font-size: .78rem;
  color: var(--dim);
  text-decoration: none;
  border-bottom: 1px solid transparent;
  transition: color .2s, border-color .2s;
}
.admin-link:hover {
  color: var(--muted);
  border-color: var(--dim);
}

@media (max-width: 480px) {
  .info-grid { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
  .icon-wrap svg, .progress-fill { animation: none; }
}
</style>
</head>
<body>

<div class="wrap">

  <div class="logo">
    <img src="<?= APP_URL ?>/assets/img/logo-tr.png" alt="MAster">
  </div>

  <div class="card">

    <div class="icon-wrap">
      <!-- Gear/settings icon -->
      <svg viewBox="0 0 24 24">
        <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>
      </svg>
    </div>

    <div class="subtitle">Προσωρινή Διακοπή</div>
    <h1>Υπό Συντήρηση</h1>

    <div class="progress-bar"><div class="progress-fill"></div></div>

    <p class="message"><?= h($msg) ?></p>

    <div class="info-grid">
      <div class="info-item">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        <span>Σύντομα<br>κοντά σας</span>
      </div>
      <div class="info-item">
        <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6 6l.94-.94a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        <span>Επικοινωνία<br>μέσω email</span>
      </div>
    </div>

    <a href="<?= APP_URL ?>/login.php" class="admin-link">
      Σύνδεση διαχειριστή
    </a>

  </div>

</div>

</body>
</html>
