<?php
/**
 * parent/index.php — Parent Portal Dashboard (STANDALONE — no layout.php)
 */

ini_set("display_errors", 0);
ini_set("log_errors", 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/marketing_popup.php";

requireParentLogin();

try {
    $paidCount    = getPaidChildrenCount();
    $pendingCount = getPendingChildrenCount();
    $overdueCount = getOverdueChildrenCount();
    $children     = getParentChildren();
} catch (Throwable $e) {
    error_log('[parent/index.php] EXCEPTION in stats block: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $paidCount = $pendingCount = $overdueCount = 0;
    $children  = [];
}

$paymentDetails = [];
try {
    $pdSid  = parentSchoolId();
    $pdKeys = ['payment_iban','payment_iris','payment_beneficiary','payment_bank','payment_notes'];
    $pdPh   = implode(',', array_fill(0, count($pdKeys), '?'));
    $pdDb   = getDB();
    $pdStmt = $pdDb->prepare("SELECT meta_key, meta_val FROM school_meta WHERE school_id=? AND meta_key IN ($pdPh)");
    $pdStmt->execute([$pdSid, ...$pdKeys]);
    foreach ($pdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $paymentDetails[$r['meta_key']] = $r['meta_val'];
} catch (Throwable $e) {
    error_log('[parent/index.php] EXCEPTION in payment details: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

$schoolName  = $_SESSION['school_name'] ?? 'MAster';
$parentEmail = $_SESSION['parent_email'] ?? '';
$firstName   = ucfirst(explode('@', $parentEmail)[0]);
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title>Portal Γονέων — MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:    #07090f;
      --card:  #111520;
      --card2: #161c2d;
      --brd:   #1e2536;
      --red:   #e63946;
      --green: #2dc653;
      --gold:  #f0a500;
      --blue:  #3b82f6;
      --text:  #f0f2ff;
      --muted: #b0bdd6;
    }
    html { -webkit-text-size-adjust: 100%; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg); color: var(--text);
      min-height: 100vh; font-size: 1rem; line-height: 1.6;
    }

    /* ── Unified Topbar ── */
    .pp-topbar {
      background: var(--card); border-bottom: 2px solid var(--brd);
      padding: 1rem 2rem; display: flex; align-items: center;
      justify-content: space-between; position: sticky; top: 0; z-index: 50;
      gap: 1rem;
    }
    .pp-logo {
      font-family: 'DM Sans', sans-serif;
      font-size: 1.8rem; letter-spacing: -.01em; color: var(--text);
      display: flex; align-items: baseline; gap: 0;
      text-decoration: none; flex-shrink: 0;
    }
    .pp-logo .logo-ma {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 1.8rem; letter-spacing: .03em; color: var(--text);
    }
    .pp-logo .logo-ster {
      font-family: 'DM Sans', sans-serif;
      font-size: 1.3rem; font-weight: 800; letter-spacing: .01em;
      color: var(--red); text-transform: lowercase;
    }
    .pp-nav { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .pp-nav a {
      font-size: 0.95rem; font-weight: 700; color: var(--muted);
      text-decoration: none; display: flex; align-items: center; gap: .4rem;
      padding: .5rem .7rem; border-radius: 10px; transition: all .2s;
      min-height: 44px; white-space: nowrap;
    }
    .pp-nav a:hover { color: var(--text); background: rgba(255,255,255,.06); }
    .pp-nav a.active { color: var(--text); background: rgba(255,255,255,.08); }
    .pp-nav a.nav-logout { color: var(--red); }
    .pp-nav a.nav-logout:hover { background: rgba(230,57,70,.08); color: #ff6b76; }
    .pp-nav a i { font-size: 1rem; }

    /* ── Body ── */
    .pp-body {
      max-width: 1200px; width: 100%;
      margin: 0 auto; padding: 2rem 1.5rem;
    }

    /* ── Hero ── */
    .pp-hero {
      background: linear-gradient(135deg, rgba(230,57,70,.08), rgba(0,0,0,0) 65%);
      border: 1px solid rgba(230,57,70,.2);
      border-radius: 24px; padding: 2.5rem;
      margin-bottom: 2rem; position: relative; overflow: hidden;
    }
    .pp-hero::before {
      content: ''; position: absolute; top: -40px; right: -40px;
      width: 280px; height: 280px;
      background: radial-gradient(circle, rgba(230,57,70,.1), transparent 70%);
      border-radius: 50%; pointer-events: none;
    }
    .pp-hero-tag {
      font-size: .85rem; font-weight: 800; letter-spacing: .12em;
      text-transform: uppercase; color: var(--red);
      display: flex; align-items: center; gap: .45rem; margin-bottom: .75rem;
    }
    .pp-hero h1 {
      font-family: 'Bebas Neue', sans-serif;
      font-size: clamp(2rem, 6vw, 4rem);
      letter-spacing: .04em; line-height: .92; color: var(--text);
      margin-bottom: 1rem;
    }
    .pp-hero h1 em { font-style: normal; color: var(--red); }
    .pp-hero-sub {
      font-size: 1rem; color: var(--muted); line-height: 1.75; max-width: 540px;
    }
    .pp-hero-school {
      display: inline-flex; align-items: center; gap: .5rem; margin-top: .9rem;
      background: rgba(255,255,255,.07); border: 1px solid var(--brd);
      border-radius: 50px; padding: .45rem 1rem;
      font-size: 0.9rem; font-weight: 700; color: var(--text);
    }

    /* ── Stats ── */
    .pp-stats {
      display: grid; grid-template-columns: repeat(3, 1fr);
      gap: 1rem; margin-bottom: 2rem;
    }
    .pstat {
      background: var(--card); border: 1px solid var(--brd);
      border-radius: 18px; padding: 1.4rem 1.25rem;
      display: flex; flex-direction: column; gap: 0.75rem;
      transition: transform .2s, box-shadow .2s;
    }
    .pstat:hover { transform: translateY(-4px); box-shadow: 0 14px 32px rgba(0,0,0,.35); }
    .pstat-icon {
      width: 50px; height: 50px; border-radius: 14px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem;
    }
    .pstat-icon.green { background: rgba(45,198,83,.12); color: var(--green); }
    .pstat-icon.gold  { background: rgba(240,165,0,.12);  color: var(--gold); }
    .pstat-icon.red   { background: rgba(230,57,70,.12);  color: var(--red); }
    .pstat-num {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 2.5rem; line-height: 1; letter-spacing: .04em;
    }
    .pstat-num.green { color: var(--green); }
    .pstat-num.gold  { color: var(--gold); }
    .pstat-num.red   { color: var(--red); }
    .pstat-lbl {
      font-size: .8rem; color: var(--muted);
      font-weight: 700; text-transform: uppercase;
      letter-spacing: .07em;
    }

    /* ── Section title ── */
    .pp-section-title {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 1.6rem; letter-spacing: .06em; color: var(--text);
      display: flex; align-items: center; gap: .5rem; margin-bottom: 1.25rem;
    }
    .pp-section-title i { color: var(--red); font-size: 1.2rem; }

    /* ── Child cards ── */
    .pp-children { display: flex; flex-direction: column; gap: 1rem; margin-bottom: 2rem; }
    .pp-child-card {
      background: var(--card); border: 1px solid var(--brd);
      border-radius: 18px; overflow: hidden; transition: box-shadow .2s;
    }
    .pp-child-card:hover { box-shadow: 0 8px 32px rgba(0,0,0,.35); }
    .pp-child-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 1.4rem 1.5rem; gap: 1rem;
      border-bottom: 1px solid var(--brd);
      background: rgba(255,255,255,.025);
      flex-wrap: wrap;
    }
    .pp-child-left { display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 0; }
    .pp-child-avatar {
      width: 56px; height: 56px; flex-shrink: 0;
      background: rgba(230,57,70,.15); border: 2px solid rgba(230,57,70,.35);
      border-radius: 50%; display: flex; align-items: center; justify-content: center;
      font-family: 'Bebas Neue', sans-serif; font-size: 1.5rem; color: var(--red);
    }
    .pp-child-info { flex: 1; min-width: 0; }
    .pp-child-name { font-size: 1.2rem; font-weight: 800; color: var(--text); margin-bottom: .2rem; }
    .pp-child-fee { font-size: 0.9rem; color: var(--muted); }
    .pp-child-fee strong { color: var(--text); }
    .pp-child-right { display: flex; align-items: center; gap: 0.75rem; flex-shrink: 0; flex-wrap: wrap; }

    /* ── Badges ── */
    .pbadge {
      display: inline-flex; align-items: center; gap: .35rem;
      padding: .4rem 0.9rem; border-radius: 25px;
      font-size: 0.8rem; font-weight: 800; letter-spacing: .03em;
      white-space: nowrap;
    }
    .pbadge.paid    { background: rgba(45,198,83,.12);  color: var(--green); border: 1px solid rgba(45,198,83,.4); }
    .pbadge.pending { background: rgba(240,165,0,.12);  color: var(--gold);  border: 1px solid rgba(240,165,0,.4); }
    .pbadge.overdue { background: rgba(230,57,70,.12);  color: var(--red);   border: 1px solid rgba(230,57,70,.4); }

    /* ── Buttons ── */
    .pp-btn {
      display: inline-flex; align-items: center; gap: .45rem;
      padding: .65rem 1.25rem; border-radius: 12px;
      font-size: 0.9rem; font-weight: 800; cursor: pointer;
      text-decoration: none; transition: all .2s; white-space: nowrap;
      border: none; min-height: 44px;
    }
    .pp-btn-primary {
      background: linear-gradient(135deg, var(--red), #b52a35);
      color: #fff; box-shadow: 0 0 24px rgba(230,57,70,.35);
    }
    .pp-btn-primary:hover {
      background: linear-gradient(135deg, #b52a35, #8c1e27);
      box-shadow: 0 0 36px rgba(230,57,70,.55);
      transform: translateY(-2px); color: #fff;
    }
    .pp-btn-outline {
      background: rgba(255,255,255,.06); border: 1px solid var(--brd);
      color: var(--muted);
    }
    .pp-btn-outline:hover { background: rgba(255,255,255,.1); color: var(--text); }

    /* ── Month mini preview ── */
    .pp-child-body { padding: 1.25rem 1.5rem; }
    .pp-months-label {
      font-size: .75rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .1em; color: var(--muted); margin-bottom: .6rem;
    }
    .pp-months-mini { display: flex; flex-wrap: wrap; gap: .4rem; }
    .pp-month-mini {
      display: inline-flex; align-items: center; gap: .25rem;
      padding: .35rem .7rem; border-radius: 8px;
      font-size: 0.8rem; font-weight: 700;
    }
    .pp-month-mini.paid    { background: rgba(45,198,83,.1);  color: var(--green); border: 1px solid rgba(45,198,83,.3); }
    .pp-month-mini.unpaid  { background: rgba(230,57,70,.1);  color: var(--red);   border: 1px solid rgba(230,57,70,.3); }
    .pp-month-mini.partial { background: rgba(240,165,0,.1);  color: var(--gold);  border: 1px solid rgba(240,165,0,.3); }

    /* ── Empty state ── */
    .pp-empty {
      text-align: center; padding: 3rem 1.5rem; color: var(--muted);
      background: var(--card); border: 1px solid var(--brd); border-radius: 18px;
    }
    .pp-empty i { font-size: 2.5rem; color: rgba(230,57,70,.25); margin-bottom: 1rem; display: block; }
    .pp-empty p { font-size: 0.95rem; line-height: 1.7; max-width: 380px; margin: 0 auto; color: var(--muted); }

    /* ── Coming soon ── */
    .pp-coming-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1rem; margin-bottom: 2rem;
    }
    .pp-coming-card {
      background: var(--card); border: 1px dashed var(--brd);
      border-radius: 18px; padding: 1.5rem;
      display: flex; flex-direction: column; align-items: flex-start;
      opacity: .65;
    }
    .pp-coming-icon {
      width: 48px; height: 48px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem; margin-bottom: 0.8rem;
    }
    .pp-coming-icon.blue   { color: var(--blue);  background: rgba(59,130,246,.1); }
    .pp-coming-icon.green  { color: var(--green); background: rgba(45,198,83,.1); }
    .pp-coming-icon.gold   { color: var(--gold);  background: rgba(240,165,0,.1); }
    .pp-coming-icon.purple { color: #a78bfa;      background: rgba(167,139,250,.1); }
    .pp-coming-title { font-size: 1rem; font-weight: 800; color: var(--text); margin-bottom: .4rem; }
    .pp-coming-sub   { font-size: 0.85rem; color: var(--muted); line-height: 1.6; }
    .pp-coming-badge {
      margin-top: 0.8rem; font-size: .65rem; font-weight: 800;
      letter-spacing: .1em; text-transform: uppercase;
      padding: .25rem .6rem; border-radius: 18px;
      background: rgba(240,165,0,.12); color: var(--gold);
      border: 1px solid rgba(240,165,0,.25);
    }

    /* ── Payment Details Card ── */
    .pp-payment-card {
      background: var(--card); border: 1px solid var(--brd);
      border-radius: 18px; padding: 1.5rem; margin-bottom: 2rem;
    }
    .pp-payment-title {
      font-size: 1rem; font-weight: 800; color: var(--text);
      display: flex; align-items: center; gap: .5rem; margin-bottom: 1.25rem;
    }
    .pp-payment-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem 1.25rem;
    }
    .pp-payment-row { display: flex; flex-direction: column; gap: .15rem; }
    .pp-payment-label {
      font-size: .7rem; font-weight: 800; letter-spacing: .08em;
      text-transform: uppercase; color: #6b7a9e;
    }
    .pp-payment-value {
      font-size: 0.95rem; font-weight: 700; color: var(--text);
      font-family: monospace; letter-spacing: .03em; word-break: break-all;
    }
    .pp-payment-value.normal { font-family: inherit; letter-spacing: normal; }
    .pp-payment-notes {
      margin-top: 1rem; padding: .75rem 1rem;
      background: rgba(107,92,231,.07); border: 1px solid rgba(107,92,231,.2);
      border-radius: 10px; font-size: 0.85rem; color: var(--muted); line-height: 1.5;
    }
    .pp-payment-empty {
      color: #6b7a9e; font-size: 0.9rem; font-style: italic;
      display: flex; align-items: center; gap: .5rem;
    }

    /* ── Bottom Tab Bar (mobile only) ── */
    .pp-bottom-nav {
      display: none;
      position: fixed;
      bottom: 0; left: 0; right: 0;
      background: var(--card);
      border-top: 2px solid var(--brd);
      z-index: 200;
      padding-bottom: env(safe-area-inset-bottom, 0px);
    }
    .pp-bottom-nav-inner {
      display: flex;
      align-items: stretch;
    }
    .pp-bottom-nav a {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 4px;
      padding: 10px 4px 10px;
      color: var(--muted);
      text-decoration: none;
      font-size: 0.6rem;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
      transition: color .2s;
      position: relative;
      min-height: 56px;
    }
    .pp-bottom-nav a i {
      font-size: 1.35rem;
      transition: color .2s, transform .2s, filter .2s;
    }
    .pp-bottom-nav a.active {
      color: var(--red);
    }
    .pp-bottom-nav a.active::before {
      content: '';
      position: absolute;
      top: 0; left: 20%; right: 20%;
      height: 2px;
      background: var(--red);
      border-radius: 0 0 4px 4px;
    }
    .pp-bottom-nav a.active i {
      color: var(--red);
      filter: drop-shadow(0 0 6px rgba(230,57,70,.6));
      transform: translateY(-1px);
    }
    .pp-bottom-nav a.nav-logout { color: var(--red); opacity: .7; }
    .pp-bottom-nav a.nav-logout:hover,
    .pp-bottom-nav a.nav-logout:active { opacity: 1; }

    @media (max-width: 768px) {
      .pp-nav { display: none; }
      .pp-bottom-nav { display: block; }
      .pp-body { padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)); }
    }

    /* ── Tablet (768px) ── */
    @media (max-width: 768px) {
      .pp-topbar { padding: .75rem 1rem; gap: 0.75rem; }
      .pp-body { padding: 1.5rem 1rem; padding-bottom: calc(80px + env(safe-area-inset-bottom)); }
      .pp-hero { padding: 1.5rem 1.25rem; margin-bottom: 1.5rem; }
      .pp-stats { grid-template-columns: repeat(3, 1fr); gap: 0.8rem; margin-bottom: 1.5rem; }
      .pstat { padding: 1rem 0.9rem; }
      .pstat-num { font-size: 2rem; }
      .pp-child-header { padding: 1.1rem 1.25rem; gap: 0.75rem; }
      .pp-child-right { width: 100%; justify-content: flex-start; }
      .pp-coming-grid { grid-template-columns: repeat(2, 1fr); }
    }

    /* ── Mobile (480px) ── */
    @media (max-width: 480px) {
      .pp-topbar { padding: 0.65rem 0.75rem; }
      .pp-body { padding: 1rem 0.75rem; padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)); }
      .pp-hero { padding: 1.25rem 1rem; margin-bottom: 1.25rem; }
      .pp-hero h1 { font-size: clamp(1.5rem, 5vw, 2.5rem); }
      .pp-hero-sub { font-size: 0.85rem; }
      .pp-stats { grid-template-columns: 1fr 1fr; gap: 0.7rem; margin-bottom: 1.25rem; }
      .pstat { padding: 0.9rem 0.8rem; }
      .pstat-icon { width: 42px; height: 42px; font-size: 1.1rem; }
      .pstat-num { font-size: 1.8rem; }
      .pstat-lbl { font-size: 0.7rem; }
      .pp-section-title { font-size: 1.3rem; margin-bottom: 1rem; }
      .pp-child-avatar { width: 48px; height: 48px; font-size: 1.2rem; }
      .pp-child-name { font-size: 1rem; }
      .pp-child-fee { font-size: 0.8rem; }
      .pp-btn { padding: 0.55rem 1rem; font-size: 0.8rem; }
      .pbadge { font-size: 0.75rem; padding: 0.35rem 0.8rem; }
      .pp-coming-grid { grid-template-columns: 1fr; }
      .pp-bottom-nav a { font-size: 0.6rem; min-height: 52px; }
      .pp-bottom-nav a i { font-size: 1.45rem; }
    }

    /* ── Extra small (360px) ── */
    @media (max-width: 360px) {
      .pp-topbar { padding: 0.6rem 0.65rem; }
      .pp-body { padding: 0.9rem 0.65rem; padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)); }
      .pp-hero { padding: 1rem 0.9rem; }
      .pp-stats { gap: 0.6rem; margin-bottom: 1rem; }
      .pstat { padding: 0.8rem 0.7rem; }
      .pstat-num { font-size: 1.6rem; }
      .pp-child-left { gap: 0.75rem; }
      .pp-child-avatar { width: 44px; height: 44px; font-size: 1.1rem; }
      .pp-child-name { font-size: 0.95rem; }
    }

    /* ── Terms Button (in topbar) ── */
    .terms-fab {
      display: flex; align-items: center; justify-content: center;
      width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
      background: rgba(255,255,255,.06); border: 1px solid var(--brd);
      color: var(--muted); font-size: 1rem; cursor: pointer;
      transition: all .2s; text-decoration: none; position: relative;
    }
    .terms-fab:hover {
      background: rgba(230,57,70,.12);
      border-color: rgba(230,57,70,.4);
      color: var(--red);
    }
    .terms-fab::after {
      content: 'Όροι Χρήσης';
      position: absolute; top: calc(100% + 8px); right: 0;
      background: var(--card); border: 1px solid var(--brd);
      color: var(--text); font-size: .78rem; font-weight: 700;
      white-space: nowrap; padding: .35rem .7rem; border-radius: 8px;
      pointer-events: none; z-index: 100;
      opacity: 0; transition: opacity .15s;
    }
    .terms-fab:hover::after { opacity: 1; }

    /* ── Terms Modal (on dashboard) ── */
    .terms-modal-overlay {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.8);
      display: none;
      align-items: center; justify-content: center;
      z-index: 9999; padding: 1.25rem;
    }
    .terms-modal-overlay.is-open { display: flex; }
    .terms-modal-box {
      background: var(--card); border: 1px solid var(--brd);
      border-radius: 18px; overflow: hidden;
      width: 100%; max-width: 680px;
      max-height: 90vh; display: flex; flex-direction: column;
      animation: tmIn .22s ease-out both;
    }
    @keyframes tmIn {
      from { opacity: 0; transform: scale(.94) translateY(12px); }
      to   { opacity: 1; transform: scale(1)   translateY(0); }
    }
    .terms-modal-header {
      background: rgba(230,57,70,.08); border-bottom: 1px solid var(--brd);
      padding: 1.25rem 1.5rem;
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
      flex-shrink: 0;
    }
    .terms-modal-header h2 {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 1.5rem; letter-spacing: .05em; color: var(--text);
      display: flex; align-items: center; gap: .5rem;
    }
    .terms-modal-close {
      width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
      background: rgba(255,255,255,.06); border: 1px solid var(--brd);
      color: var(--muted); font-size: 1rem; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: all .2s;
    }
    .terms-modal-close:hover { background: rgba(230,57,70,.12); border-color: rgba(230,57,70,.4); color: var(--red); }
    .terms-modal-body {
      padding: 1.25rem 1.5rem;
      overflow-y: auto; flex: 1;
      font-size: .9rem; line-height: 1.7; color: var(--muted);
    }
    .terms-modal-body::-webkit-scrollbar { width: 5px; }
    .terms-modal-body::-webkit-scrollbar-thumb { background: var(--brd); border-radius: 99px; }
    .terms-modal-body h2 {
      font-size: 1rem; font-weight: 800; color: var(--text); margin: 1.1rem 0 .35rem;
    }
    .terms-modal-body ul { padding-left: 1.2rem; margin-top: .3rem; }
    .terms-modal-body li { margin-bottom: .25rem; }
    .terms-modal-footer {
      border-top: 1px solid var(--brd); padding: 1rem 1.5rem;
      flex-shrink: 0; display: flex; align-items: center; justify-content: flex-end; gap: .75rem;
    }
    .terms-modal-footer .tmbtn {
      display: inline-flex; align-items: center; gap: .4rem;
      padding: .65rem 1.4rem; border-radius: 10px;
      font-size: .9rem; font-weight: 800; cursor: pointer; font-family: inherit;
      border: none; transition: all .2s; min-height: 42px; text-decoration: none;
    }
    .tmbtn-close {
      background: rgba(255,255,255,.06); border: 1px solid var(--brd); color: var(--muted);
    }
    .tmbtn-close:hover { background: rgba(255,255,255,.1); color: var(--text); }

    @media (max-width: 480px) {
      .terms-modal-header { padding: 1rem 1.25rem; }
      .terms-modal-body   { padding: 1rem 1.25rem; }
      .terms-modal-footer { padding: .9rem 1.25rem; }
    }
  </style>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/postlogin-portal-theme.css?v=<?= @filemtime(__DIR__ . "/../assets/css/postlogin-portal-theme.css") ?: time() ?>">
</head>
<body>

<?php renderMarketingPopup(); ?>

<?php if (!empty($_SESSION['admin_impersonating_parent'])): ?>
<div style="background:#f0a500;color:#000;font-size:.8rem;font-weight:700;text-align:center;padding:.5rem 0.75rem;position:sticky;top:0;z-index:9998;display:flex;align-items:center;justify-content:center;gap:.75rem;flex-wrap:wrap">
  <span><i class="fas fa-user-secret"></i> ADMIN: <?= h($_SESSION['parent_email'] ?? '') ?></span>
  <a href="<?= APP_URL ?>/admin/parent-accounts.php?exit_impersonate=1" style="color:#000;text-decoration:underline;font-weight:800;white-space:nowrap;font-size:.75rem">Έξοδος</a>
</div>
<?php endif; ?>

<div style="min-height:100vh;display:flex;flex-direction:column">

  <header class="pp-topbar">
    <a href="index.php" class="pp-logo"><span class="logo-ma">MA</span><span class="logo-ster">ster</span></a>
    <nav class="pp-nav">
      <a href="index.php" class="active"><i class="fas fa-house"></i><span class="nav-label">Αρχική</span></a>
      <a href="children.php"><i class="fas fa-children"></i><span class="nav-label">Παιδιά</span></a>
      <a href="events.php"><i class="fas fa-trophy"></i><span class="nav-label">Διοργανώσεις</span></a>
      <a href="settings.php"><i class="fas fa-gear"></i><span class="nav-label">Ρυθμίσεις</span></a>
      <a href="<?= APP_URL ?>/logout.php" class="nav-logout"><i class="fas fa-right-from-bracket"></i><span class="nav-label">Έξοδος</span></a>
    </nav>
    <?php if (!empty($_SESSION['parent_terms_accepted'])): ?>
    <button class="terms-fab" id="termsFab" title="Όροι Χρήσης" aria-label="Εμφάνιση Όρων Χρήσης">
      <i class="fas fa-file-lines"></i>
    </button>
    <?php endif; ?>
  </header>

  <main class="pp-body" style="flex:1">

    <div class="pp-hero">
      <div class="pp-hero-tag"><i class="fas fa-people-roof"></i> Portal Γονέων</div>
      <h1>ΚΑΛΩΣ<br><em>ΟΡΙΣΑΤΕ</em></h1>
      <div class="pp-hero-sub">Παρακολουθήστε τις πληρωμές συνδρομών των παιδιών σας σε πραγματικό χρόνο.</div>
      <div class="pp-hero-school">
        <i class="fas fa-building" style="color:var(--red)"></i>
        <?= h($schoolName) ?>
      </div>
    </div>

    <!-- Children -->
    <div class="pp-section-title"><i class="fas fa-users"></i> Τα Παιδιά Μου</div>

    <?php if (empty($children)): ?>
      <div class="pp-empty" style="margin-bottom:1.5rem">
        <i class="fas fa-users"></i>
        <p>Δεν βρέθηκαν παιδιά. Επικοινωνήστε με τη σχολή.</p>
      </div>
    <?php else: ?>
      <div class="pp-children">
        <?php foreach ($children as $child):
          $initial    = mb_strtoupper(mb_substr($child['full_name'], 0, 1, 'UTF-8'), 'UTF-8');
          $statusInfo = match($child['status']) {
              'paid'    => ['label' => 'Εξοφλημένη',   'class' => 'paid',    'icon' => 'fa-circle-check'],
              'pending' => ['label' => 'Σε Αναμονή',   'class' => 'pending', 'icon' => 'fa-clock'],
              default   => ['label' => 'Ληξιπρόθεσμη', 'class' => 'overdue', 'icon' => 'fa-circle-exclamation'],
          };
          try {
              $recentMonths = array_slice(getAthleteMonthlyPayments((int)$child['id']), 0, 4);
          } catch (Throwable $e) {
              error_log('[parent/index.php] EXCEPTION getAthleteMonthlyPayments: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
              $recentMonths = [];
          }
        ?>
        <div class="pp-child-card">
          <div class="pp-child-header">
            <div class="pp-child-left">
              <div class="pp-child-avatar"><?= $initial ?></div>
              <div class="pp-child-info">
                <div class="pp-child-name"><?= h($child['full_name']) ?></div>
                <div class="pp-child-fee">€<?= number_format((float)($child['monthly_fee'] ?? 0), 2) ?>/μήνα</div>
              </div>
            </div>
            <div class="pp-child-right">
              <span class="pbadge <?= $statusInfo['class'] ?>">
                <i class="fas <?= $statusInfo['icon'] ?>"></i> <?= $statusInfo['label'] ?>
              </span>
              <a href="payment-history.php?athlete_id=<?= (int)$child['id'] ?>" class="pp-btn pp-btn-primary">
                <i class="fas fa-calendar-days"></i><span class="nav-label">Μήνες</span>
              </a>
            </div>
          </div>
          <?php if (!empty($recentMonths)): ?>
          <div class="pp-child-body">
            <div class="pp-months-label">Πρόσφατοι Μήνες</div>
            <div class="pp-months-mini">
              <?php foreach (array_reverse($recentMonths) as $m): ?>
              <span class="pp-month-mini <?= $m['payment_status'] ?? ($m['paid'] ? 'paid' : 'unpaid') ?>">
                <i class="fas <?= $m['paid'] ? 'fa-circle-check' : (!empty($m['partial']) ? 'fa-circle-half-stroke' : 'fa-circle-xmark') ?>"></i>
                <?= h($m['label']) ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Payment Details -->
    <?php
    $hasIban  = !empty(trim($paymentDetails['payment_iban']          ?? ''));
    $hasIris  = !empty(trim($paymentDetails['payment_iris']          ?? ''));
    $hasBenef = !empty(trim($paymentDetails['payment_beneficiary']   ?? ''));
    $hasBank  = !empty(trim($paymentDetails['payment_bank']          ?? ''));
    $hasNotes = !empty(trim($paymentDetails['payment_notes']         ?? ''));
    $hasAny   = $hasIban || $hasIris || $hasBenef || $hasBank;
    ?>
    <div class="pp-section-title" style="margin-top:1.5rem"><i class="fas fa-building-columns"></i> Τραπεζικά Στοιχεία</div>
    <div class="pp-payment-card">
      <div class="pp-payment-title">
        <i class="fas fa-building-columns" style="color:#6b5ce7"></i> <?= h($schoolName) ?>
      </div>
      <?php if ($hasAny): ?>
        <div class="pp-payment-grid">
          <?php if ($hasIban): ?>
          <div class="pp-payment-row">
            <div class="pp-payment-label">IBAN</div>
            <div class="pp-payment-value"><?= h($paymentDetails['payment_iban']) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($hasIris): ?>
          <div class="pp-payment-row">
            <div class="pp-payment-label">IRIS</div>
            <div class="pp-payment-value"><?= h($paymentDetails['payment_iris']) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($hasBenef): ?>
          <div class="pp-payment-row">
            <div class="pp-payment-label">Δικαιούχος</div>
            <div class="pp-payment-value normal"><?= h($paymentDetails['payment_beneficiary']) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($hasBank): ?>
          <div class="pp-payment-row">
            <div class="pp-payment-label">Τράπεζα</div>
            <div class="pp-payment-value normal"><?= h($paymentDetails['payment_bank']) ?></div>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($hasNotes): ?>
        <div class="pp-payment-notes">
          <i class="fas fa-circle-info"></i><?= nl2br(h($paymentDetails['payment_notes'])) ?>
        </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="pp-payment-empty">
          <i class="fas fa-circle-exclamation"></i> Δεν έχουν οριστεί στοιχεία.
        </div>
      <?php endif; ?>
    </div>

    <!-- Coming Soon -->
    <div class="pp-section-title" style="margin-top:1.5rem"><i class="fas fa-rocket"></i> Σύντομα</div>
    <div class="pp-coming-grid">
      <div class="pp-coming-card">
        <div class="pp-coming-icon blue"><i class="fas fa-newspaper"></i></div>
        <div class="pp-coming-title">Νέα & Ανακοινώσεις</div>
        <div class="pp-coming-sub">Ενημερωθείτε για νέα και ανακοινώσεις.</div>
        <div class="pp-coming-badge">Σύντομα</div>
      </div>
      <div class="pp-coming-card">
        <div class="pp-coming-icon green"><i class="fas fa-calendar-check"></i></div>
        <div class="pp-coming-title">Πρόγραμμα</div>
        <div class="pp-coming-sub">Δείτε το εβδομαδιαίο πρόγραμμα.</div>
        <div class="pp-coming-badge">Σύντομα</div>
      </div>
      <div class="pp-coming-card">
        <div class="pp-coming-icon gold"><i class="fas fa-trophy"></i></div>
        <div class="pp-coming-title">Αποτελέσματα</div>
        <div class="pp-coming-sub">Δείτε τα αποτελέσματα αγώνων.</div>
        <div class="pp-coming-badge">Σύντομα</div>
      </div>
      <div class="pp-coming-card">
        <div class="pp-coming-icon purple"><i class="fas fa-comment-dots"></i></div>
        <div class="pp-coming-title">Επικοινωνία</div>
        <div class="pp-coming-sub">Επικοινωνία με τον γυμναστή.</div>
        <div class="pp-coming-badge">Σύντομα</div>
      </div>
    </div>

  </main>
</div>

<!-- ── Terms Modal (read-only, on dashboard) ── -->
<div id="termsDashModal" class="terms-modal-overlay" role="dialog" aria-modal="true">
  <div class="terms-modal-box">
    <div class="terms-modal-header">
      <h2><i class="fas fa-file-contract" style="color:var(--red)"></i> Όροι Χρήσης Πύλης Γονέων</h2>
      <button class="terms-modal-close" onclick="closeTermsModal()" aria-label="Κλείσιμο">
        <i class="fas fa-xmark"></i>
      </button>
    </div>

    <div class="terms-modal-body">
      <h2>1. Γενικές Πληροφορίες</h2>
      <p>Η πύλη γονέων MAster ("Πύλη") παρέχει πρόσβαση σε πληροφορίες σχετικά με τις πληρωμές και τη δραστηριότητα του/της αθλητή/αθλήτριας που είναι εγγεγραμμένος/η στη σχολή σας.</p>
      <h2>2. Χρήση Υπηρεσίας</h2>
      <p>Με τη χρήση της Πύλης αποδέχεστε ότι:</p>
      <ul>
        <li>Θα χρησιμοποιείτε τα στοιχεία πρόσβασης αποκλειστικά για προσωπική χρήση.</li>
        <li>Δεν θα κοινοποιείτε τα στοιχεία σύνδεσής σας σε τρίτους.</li>
        <li>Θα ενημερώνετε τη σχολή για τυχόν αλλαγές στα στοιχεία επικοινωνίας σας.</li>
      </ul>
      <h2>3. Προσωπικά Δεδομένα (GDPR)</h2>
      <p>Τα δεδομένα σας επεξεργάζονται σύμφωνα με τον Κανονισμό (ΕΕ) 2016/679 (GDPR). Συλλέγονται αποκλειστικά τα δεδομένα που είναι απαραίτητα για τη διαχείριση της σχέσης μεταξύ γονέα και σχολής.</p>
      <p>Έχετε τα εξής δικαιώματα:</p>
      <ul>
        <li><strong>Άρθρο 15:</strong> Δικαίωμα πρόσβασης στα δεδομένα σας.</li>
        <li><strong>Άρθρο 17:</strong> Δικαίωμα διαγραφής ("δικαίωμα στη λήθη").</li>
        <li><strong>Άρθρο 20:</strong> Δικαίωμα φορητότητας δεδομένων.</li>
      </ul>
      <p>Μπορείτε να υποβάλετε σχετικό αίτημα από τις <a href="settings.php" style="color:var(--red)">Ρυθμίσεις</a>.</p>
      <h2>4. Ειδοποιήσεις SMS &amp; Email</h2>
      <p>Η σχολή δύναται να σας αποστέλλει ενημερώσεις σχετικά με πληρωμές και συνδρομές μέσω SMS και email. Μπορείτε να εξαιρεθείτε ανά πάσα στιγμή από τις Ρυθμίσεις.</p>
      <h2>5. Ευθύνες</h2>
      <p>Η πλατφόρμα MAster λειτουργεί ως εργαλείο διαχείρισης. Η σχολή είναι αποκλειστικά υπεύθυνη για την ακρίβεια των δεδομένων που εισάγει.</p>
      <h2>6. Τροποποίηση Όρων</h2>
      <p>Οι παρόντες όροι ενδέχεται να ενημερώνονται. Σε περίπτωση ουσιαστικής αλλαγής θα σας ζητηθεί εκ νέου αποδοχή.</p>
      <h2>7. Επικοινωνία</h2>
      <p>Για οποιοδήποτε ερώτημα απευθυνθείτε στη σχολή που είναι εγγεγραμμένο το παιδί σας.</p>
    </div>

    <div class="terms-modal-footer">
      <button class="tmbtn tmbtn-close" onclick="closeTermsModal()">
        <i class="fas fa-xmark"></i> Κλείσιμο
      </button>
    </div>
  </div>
</div>

<script>
(function () {
  var fab = document.getElementById('termsFab');
  if (!fab) return;
  fab.addEventListener('click', function () {
    document.getElementById('termsDashModal').classList.add('is-open');
    document.body.style.overflow = 'hidden';
  });
  document.getElementById('termsDashModal').addEventListener('click', function (e) {
    if (e.target === this) closeTermsModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeTermsModal();
  });
})();
function closeTermsModal() {
  var m = document.getElementById('termsDashModal');
  if (m) m.classList.remove('is-open');
  document.body.style.overflow = '';
}
</script>

<!-- ── Bottom Tab Bar (mobile only) ── -->
<nav class="pp-bottom-nav" aria-label="Κύρια πλοήγηση">
  <div class="pp-bottom-nav-inner">
    <a href="index.php" class="active"><i class="fas fa-house"></i>Αρχική</a>
    <a href="children.php"><i class="fas fa-children"></i>Παιδιά</a>
    <a href="events.php"><i class="fas fa-trophy"></i>Διοργανώσεις</a>
    <a href="settings.php"><i class="fas fa-gear"></i>Ρυθμίσεις</a>
    <a href="<?= APP_URL ?>/logout.php" class="nav-logout"><i class="fas fa-right-from-bracket"></i>Έξοδος</a>
  </div>
</nav>

</body>
</html>
