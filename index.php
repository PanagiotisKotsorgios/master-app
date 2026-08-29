<?php
// ════════════════════════════════════════════════════
// index.php — Αρχική / Landing Page
// Δεν απαιτεί σύνδεση — δείχνει την marketing σελίδα
// Αν ο χρήστης είναι ήδη συνδεδεμένος → redirect
// ════════════════════════════════════════════════════

require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    redirect(isSuperAdmin() ? APP_URL.'/admin/' : APP_URL.'/dashboard/');
}
?><!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAster - Εφαρμογή Διαχείρισης Αθλητικών Σωματείων & Πληρωμών</title>
<meta name="description" content="Η #1 πλατφόρμα διαχείρισης για αθλητικά σωματεία στην Ελλάδα. Αθλητές, πληρωμές συνδρομών, portal γονέων, SMS & email αυτόματες υπενθυμίσεις.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@400;600;700;800;900&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="shortcut icon" href="./assets/img/favicon.png" type="image/png">

<meta property="og:type" content="website">
<meta property="og:title" content="MAster - Εφαρμογή Διαχείρισης Αθλητικών Σωματείων">
<meta property="og:description" content="Η #1 πλατφόρμα διαχείρισης για αθλητικά σωματεία στην Ελλάδα. Αθλητές, πληρωμές συνδρομών, portal γονέων, SMS & email αυτόματες υπενθυμίσεις.">
<meta property="og:url" content="<?= APP_URL ?>/">
<meta property="og:image" content="<?= APP_URL ?>/assets/img/og-image.png">
<meta property="og:image:secure_url" content="<?= APP_URL ?>/assets/img/og-image.png">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="MAster - Εφαρμογή Διαχείρισης Αθλητικών Σωματείων">
<meta name="twitter:description" content="Η #1 πλατφόρμα διαχείρισης για αθλητικά σωματεία στην Ελλάδα. Αθλητές, πληρωμές συνδρομών, portal γονέων, SMS & email αυτόματες υπενθυμίσεις.">
<meta name="twitter:image" content="<?= APP_URL ?>/assets/img/og-image.png">

<style>
/* ── Reset ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

/* ── CSS Variables ── */
:root{
  --bg:#060810;--bg2:#0d1017;--bg3:#131722;
  --red:#e63946;--red2:#d62f3d;
  --gold:#f0a500;--gold2:#e09400;
  --white:#f0f2ff;--muted:#6b7494;--muted2:#3d4362;
  --border:rgba(255,255,255,.06);--border2:rgba(255,255,255,.1);
  --radius:12px;--radius-lg:20px;
  --nav-h:115px;
}

html{scroll-behavior:smooth;overflow-x:hidden}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--white);min-height:100vh;line-height:1.6}
body.no-scroll{overflow:hidden}
a{text-decoration:none;color:inherit}

/* ── NOISE ── */
body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");opacity:.02;pointer-events:none;z-index:1}

/* ══════════════════════════════════════════
   NAV
══════════════════════════════════════════ */
.nav{position:fixed;top:0;left:0;right:0;z-index:10001;height:var(--nav-h);display:flex;align-items:center;padding:0 2rem;transition:all .3s}
.nav.scrolled{background:rgba(6,8,16,.94);backdrop-filter:blur(24px);border-bottom:1px solid var(--border)}
.nav-inner{max-width:1340px;width:100%;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:1.5rem}
.nav-logo{display:flex;align-items:center;flex-shrink:0}

/* ─── LOGO IMAGE ─── */
.nav-logo-img{
  height:clamp(100px,11vw,120px);
  width:auto;
  display:block;
  object-fit:contain;
  max-width:360px;
}
@media(max-width:480px){
  :root{--nav-h:115px;}
  .nav-logo-img{height:clamp(100px,11vw,120px);}
}

.nav-links{display:flex;align-items:center;gap:2rem;list-style:none;flex:1;justify-content:center}
.nav-links a{font-size:.88rem;font-weight:600;color:#ffffff !important;transition:color .2s;letter-spacing:.02em;white-space:nowrap}
.nav-links a:hover{color:#ffffff !important}
.nav-ctas{display:flex;align-items:center;gap:.75rem;flex-shrink:0}

/* ── PARENT PORTAL BUTTON - Red Border Only ── */
.btn-parent-portal {
    font-size: 0.9rem;
    font-weight: 600;
    color: #ffffff !important;
    padding: .45rem .95rem;
    border: 1.5px solid var(--red);
    border-radius: 8px;
    transition: all .25s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
}
.btn-parent-portal i { color: var(--red); }
.btn-parent-portal:hover {
    background: rgba(230, 57, 70, 0.08);
    color: #fff;
    border-color: #fff;
    transform: translateY(-1px);
}

.btn-nav-login{font-size:.9rem;font-weight:600;color:#ffffff !important;padding:.45rem .875rem;border-radius:8px;transition:all .2s}
.btn-nav-login:hover{color:#ffffff !important;background:rgba(255,255,255,.08)}
.btn-nav-cta{font-size:.9rem;font-weight:700;background:linear-gradient(135deg,var(--red),var(--red2));color:#ffffff !important;padding:.5rem 1.25rem;border-radius:8px;transition:all .2s;box-shadow:0 0 18px rgba(230,57,70,.4);letter-spacing:.01em}
.btn-nav-cta i{color:#ffffff !important}
.btn-nav-cta:hover{background:linear-gradient(135deg,var(--red2),#b32430);box-shadow:0 0 28px rgba(230,57,70,.6);transform:translateY(-1px);color:#ffffff !important}
.btn-nav-cta:hover i{color:#ffffff !important}

.hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer;padding:.5rem;background:none;border:none;z-index:10002;border-radius:8px;transition:background 0.2s}
.hamburger span{display:block;width:24px;height:2px;background:var(--white);border-radius:2px;transition:all .3s}
.hamburger.open{background:rgba(0,0,0,0.3);backdrop-filter:blur(4px);box-shadow:0 0 15px rgba(0,0,0,0.5)}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.hamburger.open span:nth-child(2){opacity:0}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}

/* Mobile fullscreen menu */
@keyframes mmFadeIn{from{opacity:0}to{opacity:1}}
@keyframes mmItemIn{
  from{opacity:0;transform:translateX(-22px)}
  to  {opacity:1;transform:translateX(0)}
}

.mobile-menu{
  position:fixed;top:0;left:0;width:100%;height:100vh;
  background:rgba(6,8,16,0.98);backdrop-filter:blur(16px);z-index:10000;
  flex-direction:column;justify-content:flex-start;align-items:flex-start;
  gap:1rem;
  /* ── FIX: was 2rem — now accounts for iOS home bar and ensures last button is reachable ── */
  padding:calc(var(--nav-h) + 1.25rem) 1.25rem calc(3.5rem + env(safe-area-inset-bottom, 0px));
  overflow-y:scroll;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  display:flex;
  opacity:0;
  pointer-events:none;
  transition:opacity .3s ease;
  scrollbar-width:thin;
  scrollbar-color:var(--red) rgba(255,255,255,.04);
}
.mobile-menu.open{
  opacity:1;
  pointer-events:all;
}

.mobile-menu::-webkit-scrollbar{width:6px}
.mobile-menu::-webkit-scrollbar-track{background:rgba(255,255,255,.04);border-radius:3px}
.mobile-menu::-webkit-scrollbar-thumb{background:var(--red);border-radius:3px;min-height:44px}
.mobile-menu::-webkit-scrollbar-thumb:hover{background:var(--red2)}

.mobile-menu a{
  font-family:'Outfit',sans-serif;font-size:1.4rem;font-weight:600;color:var(--white);
  text-decoration:none;padding:0.75rem 0.5rem;
  width:100%;display:flex;align-items:center;justify-content:flex-start;gap:0.75rem;
  opacity:0;
  transition:color 0.2s;
}
.mobile-menu.open a{
  animation:mmItemIn .4s cubic-bezier(.22,1,.36,1) forwards;
}
.mobile-menu.open a:nth-child(1){animation-delay:.05s}
.mobile-menu.open a:nth-child(2){animation-delay:.1s}
.mobile-menu.open a:nth-child(3){animation-delay:.15s}
.mobile-menu.open a:nth-child(4){animation-delay:.2s}
.mobile-menu.open a:nth-child(5){animation-delay:.25s}
.mobile-menu.open a:nth-child(6){animation-delay:.3s}
.mobile-menu.open a:nth-child(7){animation-delay:.35s}

.mobile-menu a i{font-size:1.6rem;width:2rem;color:var(--red)}
.mobile-menu a:hover{color:var(--red)}
.mob-divider{display:none}
.mob-cta{
  width:100%;display:flex;align-items:center;justify-content:flex-start;gap:.75rem;
  background:linear-gradient(135deg,var(--red),var(--red2));color:#fff !important;
  padding:1rem 1.25rem;border-radius:14px;font-size:1.1rem;font-weight:800;margin-top:1rem;
  /* ── FIX: added margin-bottom so button clears the padding boundary visually ── */
  margin-bottom:.5rem;
  border:1px solid rgba(255,255,255,.08);box-shadow:0 10px 28px rgba(230,57,70,.28);
  -webkit-tap-highlight-color:transparent;
}
.mob-cta i{color:#fff !important;font-size:1.25rem;width:auto}
.mobile-menu a.mob-cta:hover{transform:translateY(-1px);box-shadow:0 14px 34px rgba(230,57,70,.38)}
.mobile-menu a.mob-cta:active{transform:translateY(0);box-shadow:0 8px 22px rgba(230,57,70,.25)}
.mobile-menu a.mob-cta:hover,
.mobile-menu a.mob-cta:active,
.mobile-menu a.mob-cta:focus,
.mobile-menu a.mob-cta:focus-visible{color:#fff}
.mobile-menu a:focus-visible{outline:2px solid rgba(240,165,0,.55);outline-offset:4px;border-radius:14px}

.mob-login {
  border: 1.5px solid rgba(255,255,255,.18) !important;
  border-radius: 12px !important;
  padding: .75rem 1.1rem !important;
  color: var(--white) !important;
  margin-top: .25rem;
}
.mob-login:hover { background: rgba(255,255,255,.07) !important; }
.mob-portal {
  border: 1.5px solid var(--red) !important;
  border-radius: 12px !important;
  padding: .75rem 1.1rem !important;
  color: var(--red) !important;
  margin-top: .25rem;
}
.mob-portal i { color: var(--red) !important; }
.mob-portal:hover { background: rgba(230,57,70,.1) !important; color: #ff6b76 !important; }

@media (min-width:1025px) and (max-width:1280px) {
    .nav-links { gap: 1.4rem; }
    .nav-links a { font-size:.82rem; }
    .btn-parent-portal { padding:.38rem .75rem; font-size:.82rem; }
    .btn-nav-login { padding:.38rem .7rem; font-size:.82rem; }
    .btn-nav-cta { padding:.42rem 1rem; font-size:.82rem; }
    .nav-logo-img { height: clamp(80px,9vw,105px); }
}

/* ── HERO ── */
.hero{min-height:100vh;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;padding:calc(var(--nav-h) + clamp(2rem,6vw,5rem)) clamp(1rem,4vw,2rem) clamp(3rem,6vw,5rem);text-align:center}
.hg1,.hg2,.hg3{position:absolute;border-radius:50%;pointer-events:none}
.hg1{width:800px;height:800px;background:radial-gradient(circle,rgba(230,57,70,.11) 0%,transparent 70%);top:-20%;left:-20%;animation:fgl 9s ease-in-out infinite}
.hg2{width:600px;height:600px;background:radial-gradient(circle,rgba(240,165,0,.07) 0%,transparent 70%);bottom:-15%;right:-15%;animation:fgl 9s -3s ease-in-out infinite}
.hg3{width:400px;height:400px;background:radial-gradient(circle,rgba(58,134,255,.05) 0%,transparent 70%);top:40%;left:50%;transform:translate(-50%,-50%);animation:fgl 9s -6s ease-in-out infinite}
@keyframes fgl{0%,100%{transform:translate(0,0) scale(1)}40%{transform:translate(25px,-25px) scale(1.06)}70%{transform:translate(-20px,15px) scale(.94)}}
.hero-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.022) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.022) 1px,transparent 1px);background-size:64px 64px;mask-image:radial-gradient(ellipse 80% 60% at 50% 50%,black,transparent)}
.hero-content{position:relative;z-index:2;max-width:940px;width:100%;margin:0 auto;padding-inline:clamp(.75rem,3.5vw,1.25rem)}
.hero-h1{
  font-family:'Bebas Neue',sans-serif;font-size:clamp(2.6rem,10vw,8.5rem);line-height:.92;
  letter-spacing:.02em;margin-bottom:1.5rem;white-space:normal;overflow-wrap:normal;
  word-break:keep-all;max-width:100%;animation:fu .8s .15s ease both;text-shadow:0 2px 10px rgba(0,0,0,0.5)
}
.hero-h1 .outline{-webkit-text-stroke:clamp(1px,.35vw,2px) var(--red);color:transparent;text-shadow:none}
.hero-h1 .fill{color:var(--red);text-shadow:0 2px 15px rgba(230,57,70,0.5)}
.hero-sub{font-size:clamp(1rem,2.5vw,1.2rem);color:var(--muted);max-width:600px;margin:0 auto 2.75rem;line-height:1.8;font-weight:300;animation:fu .8s .3s ease both}
.hero-ctas{display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap;animation:fu .8s .45s ease both;margin-bottom:2.5rem}
.btn-primary{display:inline-flex;align-items:center;gap:.5rem;background:linear-gradient(135deg,var(--red),var(--red2));color:#fff;font-weight:700;font-size:1rem;padding:.9rem 2.1rem;border-radius:12px;box-shadow:0 0 32px rgba(230,57,70,.45);transition:all .25s;letter-spacing:.01em}
.btn-primary:hover{background:linear-gradient(135deg,var(--red2),#b32430);box-shadow:0 0 50px rgba(230,57,70,.65);transform:translateY(-2px)}
.btn-outline{display:inline-flex;align-items:center;gap:.5rem;border:1px solid var(--border2);color:var(--white);font-weight:500;font-size:1rem;padding:.9rem 2.1rem;border-radius:12px;background:rgba(255,255,255,.04);transition:all .25s;backdrop-filter:blur(8px)}
.btn-outline:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.2);transform:translateY(-2px)}
@keyframes fu{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:translateY(0)}}
.scroll-hint{position:absolute;bottom:2rem;left:50%;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;gap:.4rem;color:var(--muted2);font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;animation:fu 1s 1s ease both}
.scroll-line{width:1px;height:44px;background:linear-gradient(to bottom,var(--red),transparent);animation:sp 2.2s ease-in-out infinite}
@keyframes sp{0%,100%{opacity:.4;transform:scaleY(1)}50%{opacity:1;transform:scaleY(1.25)}}

/* ── STATS BAR ── */
.stats-bar{background:var(--bg2);border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:2rem}
.stats-inner{max-width:1200px;margin:0 auto;display:grid;grid-template-columns:repeat(4,1fr)}
.stat-item{text-align:center;padding:1rem 1.5rem;border-right:1px solid var(--border)}
.stat-item:last-child{border-right:none}
.stat-num{font-family:'Bebas Neue',sans-serif;font-size:2.8rem;letter-spacing:.04em;line-height:1}
.stat-num.r{color:var(--red)}.stat-num.g{color:var(--gold)}.stat-num.w{color:var(--white)}
.stat-lbl{font-size:1rem;color:var(--muted);margin-top:.3rem;letter-spacing:.05em}

/* Loading pulse for stats */
@keyframes statPulse{0%,100%{opacity:.3}50%{opacity:.7}}
.stat-num.loading{animation:statPulse 1.4s ease-in-out infinite;font-size:1.8rem;letter-spacing:.1em}

/* ── SECTION ── */
.section{padding:6rem 2rem}
.s-inner{max-width:1200px;margin:0 auto}
.s-tag{display:inline-block;font-size:.95rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--red);margin-bottom:.875rem}
.s-h2{font-family:'Outfit',sans-serif !important;font-size:clamp(2rem,5vw,3.2rem) !important;font-weight:900 !important;line-height:1.35 !important;margin-bottom:1rem;text-shadow:0 2px 6px rgba(0,0,0,0.4)}
.s-h2 em{font-style:normal;color:var(--red);text-shadow:0 2px 8px rgba(230,57,70,0.3)}
.s-desc{font-size:1.15rem;color:var(--muted);max-width:520px;line-height:1.8;font-weight:300}

/* ── FOR WHOM ── */
.whom-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.5rem;margin-top:3.5rem}
.whom-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem;transition:all .3s;position:relative;overflow:hidden;cursor:default}
.whom-card::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(230,57,70,.06),transparent);opacity:0;transition:.3s}
.whom-card:hover{border-color:rgba(230,57,70,.28);transform:translateY(-5px);box-shadow:0 24px 48px rgba(0,0,0,.5)}
.whom-card:hover::after{opacity:1}
.w-icon{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.75rem;margin-bottom:1.25rem;background:rgba(230,57,70,.1)}
.whom-card h3{font-family:'Outfit',sans-serif;font-size:1.15rem;font-weight:700;margin-bottom:.625rem}
.whom-card p{font-size:1.1rem;color:var(--muted);line-height:1.7}

/* ── FEATURES ── */
.feat-section{background:var(--bg2)}
.feat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.25rem;margin-top:3.5rem}
.feat-card{background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.75rem;display:flex;gap:1.25rem;align-items:flex-start;transition:all .3s}
.feat-card:hover{border-color:rgba(240,165,0,.18);box-shadow:0 8px 32px rgba(0,0,0,.4)}
.f-icon{width:48px;height:48px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.4rem}
.fi-r{background:rgba(230,57,70,.12);color:var(--red)}
.fi-g{background:rgba(240,165,0,.12);color:var(--gold)}
.fi-b{background:rgba(58,134,255,.12);color:#3a86ff}
.fi-gr{background:rgba(45,198,83,.12);color:#2dc653}
.fi-p{background:rgba(168,85,247,.12);color:#a855f7}
.fi-o{background:rgba(251,146,60,.12);color:#fb923c}
.feat-card h3{font-family:'Outfit',sans-serif;font-size:1.1rem;font-weight:700;margin-bottom:.35rem}
.feat-card p{font-size:1.1rem;color:var(--muted);line-height:1.65}
.pro-chip{display:inline-block;font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:rgba(240,165,0,.15);color:var(--gold);border-radius:4px;padding:.12rem .4rem;margin-left:.4rem;vertical-align:middle}

/* ── BIG FEATURE ── */
.bf{display:grid;grid-template-columns:1fr 1fr;gap:5rem;align-items:center;margin-top:5rem}
.bf.rev{direction:rtl}.bf.rev>*{direction:ltr}
.bf h2{font-family:'Outfit',sans-serif !important;font-size:clamp(1.8rem,3.5vw,2.6rem) !important;font-weight:900 !important;line-height:1.35 !important;margin-bottom:1rem;text-shadow:0 2px 6px rgba(0,0,0,0.4)}
.bf h2 em{font-style:normal;color:var(--red)}
.bf p{font-size:1.1rem;color:var(--muted);line-height:1.8;margin-bottom:1.5rem}
.bf-list{list-style:none;display:flex;flex-direction:column;gap:.625rem}
.bf-list li{display:flex;align-items:flex-start;gap:.75rem;font-size:1.1rem;color:var(--muted)}
.bf-list li::before{content:'✓';color:var(--red);font-weight:700;flex-shrink:0;margin-top:.05rem}
.bf-vis{background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;position:relative;overflow:hidden}
.bf-vis::before{content:'';position:absolute;top:-50px;right:-50px;width:180px;height:180px;background:radial-gradient(circle,rgba(230,57,70,.1),transparent);border-radius:50%}
.mock-bar{background:rgba(255,255,255,.04);border-radius:8px;padding:.625rem .875rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.dot{width:8px;height:8px;border-radius:50%}
.mock-t{font-size:1rem;font-weight:600;color:var(--muted);margin-left:.25rem}
.m-row{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:.625rem .875rem;margin-bottom:.5rem;display:flex;align-items:center;justify-content:space-between;font-size:1rem}
.m-row .mn{font-weight:600;color:var(--white)}
.m-badge{font-size:.85rem;font-weight:700;padding:.18rem .45rem;border-radius:4px}
.mb-g{background:rgba(45,198,83,.15);color:#2dc653}
.mb-r{background:rgba(230,57,70,.15);color:var(--red)}
.mb-gold{background:rgba(240,165,0,.15);color:var(--gold)}
.mb-blue{background:rgba(58,134,255,.15);color:#3a86ff}

/* ── HOW IT WORKS ── */
.steps{display:grid;grid-template-columns:repeat(4,1fr);gap:1.5rem;margin-top:3.5rem;position:relative}
.steps::before{content:'';position:absolute;top:31px;left:12%;right:12%;height:1px;background:linear-gradient(to right,transparent,var(--border),var(--border),transparent)}
.step{text-align:center}
.step-n{width:64px;height:64px;border-radius:50%;background:var(--bg3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-family:'Bebas Neue',sans-serif;font-size:1.5rem;color:var(--red);margin:0 auto 1.25rem;position:relative;z-index:1;transition:all .3s}
.step:hover .step-n{background:rgba(230,57,70,.1);border-color:rgba(230,57,70,.3);box-shadow:0 0 22px rgba(230,57,70,.2)}
.step h3{font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;margin-bottom:.5rem}
.step p{font-size:1.05rem;color:var(--muted);line-height:1.65}

/* ── PRICING ── */
.price-toggle{display:flex;align-items:center;gap:1rem;justify-content:center;margin:2rem 0 3.5rem;flex-wrap:wrap}
.p-lbl{font-size:.9rem;font-weight:500;color:var(--muted);transition:color .2s}
.p-lbl.on{color:var(--white)}

.p-switch{
  width:52px;height:28px;
  background:rgba(255,255,255,.12);
  border-radius:50px;position:relative;cursor:pointer;
  transition:background .3s,box-shadow .3s;
  border:1px solid rgba(255,255,255,.15);flex-shrink:0;
}
.p-switch.annual{background:var(--red);border-color:var(--red);box-shadow:0 0 16px rgba(230,57,70,.4)}
.p-knob{
  position:absolute;top:50%;left:3px;
  width:22px;height:22px;
  background:#fff;border-radius:50%;
  transform:translateY(-50%);
  transition:transform .3s;
  box-shadow:0 1px 4px rgba(0,0,0,.3);
}
.p-switch.annual .p-knob{transform:translateY(-50%) translateX(24px)}

.save-tag{background:rgba(45,198,83,.15);color:#2dc653;font-size:.78rem;font-weight:700;padding:.2rem .55rem;border-radius:50px;letter-spacing:.05em}
.pricing-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.5rem;max-width:860px;margin:0 auto}
.p-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);padding:2.25rem;position:relative;overflow:hidden;transition:all .3s}
.p-card.hot{border-color:rgba(240,165,0,.35);background:linear-gradient(135deg,rgba(240,165,0,.04),var(--bg2))}
.p-card:hover{transform:translateY(-4px);box-shadow:0 24px 48px rgba(0,0,0,.5)}
.ribbon{position:absolute;top:14px;right:-32px;background:var(--gold);color:#111;font-size:.8rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;padding:.3rem 2.8rem;transform:rotate(45deg)}
.p-name{font-family:'Outfit',sans-serif;font-weight:800;font-size:1.3rem;margin-bottom:.25rem}
.p-sub{font-size:1rem;color:var(--muted);margin-bottom:1.5rem}
.p-price{font-family:'Bebas Neue',sans-serif;font-size:3.8rem;letter-spacing:.02em;line-height:1;margin-bottom:.25rem}
.p-curr{font-size:1.5rem;vertical-align:top;margin-top:.55rem;margin-right:.1rem}
.p-per{font-family:'DM Sans',sans-serif;font-size:1rem;font-weight:400;color:var(--muted)}
.p-annual{font-size:1rem;color:var(--muted);margin-bottom:1.75rem;min-height:1.2em}
.p-annual span{color:#2dc653;font-weight:600}
.p-div{height:1px;background:var(--border);margin:1.5rem 0}
.p-feats{list-style:none;display:flex;flex-direction:column;gap:.7rem;margin-bottom:2rem}
.p-feats li{display:flex;align-items:flex-start;gap:.75rem;font-size:1.05rem}
.p-feats .ck{color:var(--red);font-weight:700;flex-shrink:0}
.p-feats .no{color:var(--muted2);flex-shrink:0}
.p-feats li.dim{color:var(--muted2)}
.btn-plan{display:block;text-align:center;font-weight:700;font-size:1rem;border-radius:10px;padding:.85rem;transition:all .25s;color:#ffffff !important}
.btn-plan i{color:#ffffff !important}
.btn-plan-r{background:linear-gradient(135deg,var(--red),var(--red2));color:#ffffff !important;box-shadow:0 0 22px rgba(230,57,70,.35)}
.btn-plan-r:hover{background:linear-gradient(135deg,var(--red2),#b32430);box-shadow:0 0 34px rgba(230,57,70,.55);transform:translateY(-1px);color:#ffffff !important}
.btn-plan-s{background:rgba(255,255,255,.06);color:#ffffff !important;border:1px solid var(--border2)}
.btn-plan-s:hover{background:rgba(255,255,255,.1);transform:translateY(-1px);color:#ffffff !important}

/* ── REVEAL ── */
.rev{opacity:0;transform:translateY(36px);transition:opacity .75s cubic-bezier(.22,1,.36,1),transform .75s cubic-bezier(.22,1,.36,1)}
.rev.in{opacity:1;transform:translateY(0)}
.d1{transition-delay:.1s}.d2{transition-delay:.2s}.d3{transition-delay:.3s}.d4{transition-delay:.4s}

/* ══════════════════════════════════════════
   RESPONSIVE — TABLET / SMALL DESKTOP
══════════════════════════════════════════ */
@media(max-width:1024px){
  .nav-links,.nav-ctas{display:none}
  .hamburger{display:flex}
  .stats-inner{grid-template-columns:repeat(2,1fr)}
  .stat-item:nth-child(2){border-right:none}
  .stat-item:nth-child(3){border-right:1px solid var(--border);border-top:1px solid var(--border)}
  .stat-item:nth-child(4){border-right:none;border-top:1px solid var(--border)}
  .whom-grid{grid-template-columns:1fr}
  .feat-grid{grid-template-columns:1fr}
  .bf,.bf.rev{grid-template-columns:1fr;direction:ltr;gap:2.5rem}
  .steps{grid-template-columns:repeat(2,1fr)}
  .steps::before{display:none}
  .foot-top{grid-template-columns:1fr 1fr;gap:2rem}
  .pricing-grid{max-width:100%}
}

@media(max-width:640px){
  .section{padding:4rem 1.25rem}
  .stats-bar{padding:1.25rem}
  .stat-num{font-size:2.1rem}
  .pricing-grid,.steps{grid-template-columns:1fr}
  .foot-top{grid-template-columns:1fr}
  .foot-bot{flex-direction:column;text-align:center}
}

/* ══════════════════════════════════════════
   MOBILE TEXT SIZE FIXES
══════════════════════════════════════════ */
@media(max-width:640px){
  .feat-card p  { font-size: .87rem; line-height: 1.6; }
  .feat-card h3 { font-size: .95rem; }
  .feat-card    { padding: 1.25rem; gap: .9rem; }
  .p-feats li   { font-size: .87rem; gap: .45rem; }
  .p-feats .ck,
  .p-feats .no  { font-size: .87rem; }
  .p-sub        { font-size: .87rem; }
  .p-card       { padding: 1.5rem 1.1rem; }
  .p-annual     { font-size: .87rem; }
  .step p  { font-size: .87rem; line-height: 1.6; }
  .step h3 { font-size: .92rem; }
  .whom-card p  { font-size: .87rem; line-height: 1.65; }
  .whom-card h3 { font-size: 1rem; }
  .whom-card    { padding: 1.4rem; }
  .bf-list li { font-size: .87rem; }
  .bf p       { font-size: .92rem; }
  .s-desc { font-size: .95rem; }
  .stat-lbl { font-size: .8rem; letter-spacing: .03em; }
  .foot-col ul li a { font-size: .87rem; }
  .foot-brand p     { font-size: .87rem; }

  .s-h2 { font-size: clamp(1.5rem, 7vw, 2rem) !important; line-height: 1.38 !important; font-weight: 800 !important; letter-spacing: -.01em !important; }
  .s-tag { font-size: .78rem !important; }
  .bf h2 { font-size: clamp(1.4rem, 6.5vw, 1.9rem) !important; line-height: 1.38 !important; font-weight: 800 !important; }
}

@media(max-width:400px){
  .feat-card p  { font-size: .82rem; }
  .p-feats li   { font-size: .82rem; }
  .whom-card p  { font-size: .82rem; }
  .step p       { font-size: .82rem; }
  .bf-list li   { font-size: .82rem; }
  .p-card       { padding: 1.2rem .9rem; }
  .p-feats      { gap: .5rem; }
  .feat-card    { padding: 1rem .85rem; gap: .75rem; }
}

@media(max-width:480px){
  .s-h2 { font-size: clamp(1.3rem, 6vw, 1.6rem) !important; line-height: 1.4 !important; font-weight: 800 !important; letter-spacing: -.01em !important; }
  .bf h2 { font-size: clamp(1.2rem, 5.5vw, 1.5rem) !important; line-height: 1.4 !important; font-weight: 800 !important; }
  .hero-h1 { letter-spacing: .01em !important; }
}

/* ── FOOTER ── */
.footer{padding:4.5rem 2rem 0;border-top:1px solid var(--border)}
.foot-inner{max-width:1200px;margin:0 auto}
.foot-top{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:3rem;margin-bottom:0;padding-bottom:3rem}
.foot-brand p{font-size:.94rem;color:var(--muted);margin-top:.875rem;max-width:240px;line-height:1.75}
.foot-col h4{font-family:'Outfit',sans-serif;font-weight:700;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;color:var(--white);margin-bottom:1.1rem;display:flex;align-items:center;gap:.45rem}
.foot-col h4 i{color:var(--red);font-size:.75rem}
.foot-col ul{list-style:none;display:flex;flex-direction:column;gap:.55rem}
.foot-col ul li a{font-size:1rem;color:var(--muted);transition:color .2s;display:flex;align-items:center;gap:.45rem}
.foot-col ul li a i{font-size:.75rem;width:.9rem;color:var(--muted2);flex-shrink:0;transition:color .2s}
.foot-col ul li a:hover{color:var(--white)}
.foot-col ul li a:hover i{color:var(--red)}
.foot-cta-link{color:var(--red) !important;font-weight:600}
.foot-cta-link i{color:var(--red) !important}
.foot-cta-link:hover{color:var(--white) !important}
.foot-divider{height:1px;background:var(--border);margin:0}
.foot-bot{display:flex;align-items:center;justify-content:space-between;padding:1.25rem 0 1.5rem;flex-wrap:wrap;gap:.875rem}
.foot-bot-left{display:flex;flex-direction:column;gap:.45rem}
.foot-copy{font-size:1rem;color:var(--muted2)}
.foot-copy strong{color:var(--muted);font-weight:500}
.foot-copy a{color:var(--muted);transition:color .2s}
.foot-copy a:hover{color:var(--white)}
.foot-copy a strong{color:inherit;font-weight:500}
.foot-flag{font-size:1rem;color:var(--muted2);display:flex;align-items:center;gap:.4rem;white-space:nowrap}
.foot-flag i{color:var(--red);font-size:.7rem}
@media(max-width:1024px){
  .foot-top{grid-template-columns:1fr 1fr;gap:2.5rem}
  .foot-brand{grid-column:1/-1}
}
@media(max-width:640px){
  .footer{padding:3rem 1.25rem 0}
  .foot-top{grid-template-columns:1fr;gap:2rem}
  .foot-brand{grid-column:auto}
  .foot-bot{flex-direction:column;align-items:flex-start}
}

/* ══════════════════════════════════════════════════════════
   HERO MOBILE FIX
══════════════════════════════════════════════════════════ */
@media(max-width:640px){
  :root { --nav-h: 78px; }
  .nav { height: var(--nav-h); padding: 0 1.1rem; }
  .nav-logo-img { height: 62px !important; max-width: 200px; }
}
@media(max-width:400px){
  :root { --nav-h: 66px; }
  .nav-logo-img { height: 52px !important; }
}
@media(max-width:640px){
  .hero {
    min-height: unset; height: auto;
    padding-top: calc(var(--nav-h) + 2rem) !important;
    padding-bottom: clamp(2rem, 6svh, 3.5rem);
    padding-inline: 1.1rem;
    align-items: flex-start; text-align: left;
  }
  .hero-content { padding-inline: 0; width: 100%; text-align: left; }
}
@media(max-width:640px){
  .hero-h1 {
    font-size: clamp(2rem, 13vw, 3.5rem) !important;
    line-height: .88 !important; letter-spacing: .01em !important;
    margin-bottom: 1rem; white-space: nowrap !important; overflow: visible;
  }
  /* Mobile: text-stroke outlines render as thin broken/double lines
     on Android/iOS. Fill the word solidly instead to match the design
     the user actually sees (like the third line). */
  .hero-h1 .outline{
    -webkit-text-stroke:0 !important;
    color:var(--red) !important;
    text-shadow:0 2px 15px rgba(230,57,70,0.35) !important;
  }
}
@media(max-width:400px){
  .hero-h1 {
    font-size: clamp(1.7rem, 11.5vw, 2.6rem) !important;
    line-height: .87 !important; margin-bottom: .85rem;
  }
}
@media(max-width:640px){
  .hero-sub {
    font-size: .875rem !important; line-height: 1.7; margin-bottom: 1.75rem;
    max-width: 100%; text-align: left; margin-left: 0; margin-right: 0;
  }
}
@media(max-width:640px){
  .hero-ctas {
    flex-direction: column; align-items: stretch; gap: .65rem;
    margin-bottom: 1.5rem; width: 100%;
  }
  .btn-primary, .btn-outline {
    width: 100%; justify-content: center; padding: .82rem 1rem;
    font-size: .88rem; white-space: nowrap; border-radius: 11px;
  }
  .btn-primary i, .btn-outline i { font-size: .85em; }
}
@media(max-width:640px){ .scroll-hint { display: none; } }
@media(max-width:640px){
  #dm-icon-portal, .dm-cookie-btn, .cookie-icon-btn,
  [class*="cookie-icon"], [id*="cookie-icon"] {
    bottom: 1.25rem !important; right: 1rem !important; z-index: 8999 !important;
  }
}

/* ── FOOTER LINK OVERRIDES ── */
.foot-copy a { color:var(--muted); transition:color .2s; }
.foot-copy a:hover { color:var(--white); }
.foot-copy a strong { color:inherit; font-weight:500; }
</style>
<?php include __DIR__ . '/includes/prelogin_polish.php'; ?>
</head>
<body>

<!-- ═══ NAVBAR ═══ -->
<nav class="nav" id="nav">
  <div class="nav-inner">
    <a href="index.php" class="nav-logo">
      <img src="./assets/img/logo-tr.png" alt="MAster" class="nav-logo-img" width="180">
    </a>
    <ul class="nav-links">
      <li><a href="#features">Λειτουργίες</a></li>
      <li><a href="#pricing">Τιμές</a></li>
      <li><a href="<?= APP_URL ?>/events/" style="color:#e63946;font-weight:700"><i class="fa-solid fa-trophy" style="margin-right:.35rem"></i>Διοργανώσεις</a></li>
      <li><a href="<?= APP_URL ?>/contact.php">Επικοινωνία</a></li>
    </ul>
    <div class="nav-ctas">
      <a href="<?= APP_URL ?>/parent/login.php" class="btn-parent-portal">
        <i class="fa-solid fa-house-chimney-user"></i> &nbsp; Πύλη Γονέα / Αθλητή
      </a>
      <a href="<?= APP_URL ?>/login.php" class="btn-nav-login">
        <i class="fa-solid fa-arrow-right-to-bracket"></i> &nbsp; Σύνδεση
      </a>
      <a href="<?= APP_URL ?>/register.php" class="btn-nav-cta">
        <i class="fa-solid fa-arrow-right-to-bracket"></i> &nbsp; Εγγραφείτε Δωρεάν
      </a>
    </div>
    <button class="hamburger" id="burger" aria-label="Μενού">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<!-- Mobile fullscreen menu -->
<div class="mobile-menu" id="mmenu">
  <a href="#features"><i class="fas fa-bolt"></i> Λειτουργίες</a>
  <a href="#pricing"><i class="fas fa-credit-card"></i> Τιμές</a>
  <a href="<?= APP_URL ?>/events/" style="color:#ff6b74"><i class="fas fa-trophy"></i> Διοργανώσεις · Πρωταθλήματα</a>
  <a href="<?= APP_URL ?>/events/athletes.php" style="color:#8892b0"><i class="fas fa-magnifying-glass"></i> Αναζήτηση Αθλητή</a>
  <div class="mob-divider"></div>
  <a href="<?= APP_URL ?>/contact.php"><i class="fas fa-envelope"></i> Επικοινωνία</a>
  <a href="<?= APP_URL ?>/parent/login.php" class="mob-portal"><i class="fa-solid fa-house-chimney-user"></i> Πύλη Γονέα / Αθλητή</a>
  <a href="<?= APP_URL ?>/login.php" class="mob-login"><i class="fas fa-lock"></i> Σύνδεση</a>
  <a href="<?= APP_URL ?>/register.php" class="mob-cta"><i class="fa-sharp fa-regular fa-share-from-square"></i> Εγγραφή Εδώ</a>
</div>

<!-- ═══ HERO ═══ -->
<section class="hero" id="home">
  <div class="hg1"></div><div class="hg2"></div><div class="hg3"></div>
  <div class="hero-grid"></div>
  <div class="hero-content">
    <h1 class="hero-h1">
      ΠΛΗΡΗΣ<br>
      <span class="outline">ΔΙΑΧΕΙΡΙΣΗ</span><br>
      <span class="fill">ΣΩΜΑΤΕΙΟΥ</span>
    </h1>
    <p class="hero-sub">Σταματήστε να χάνετε χρήματα από απλήρωτες συνδρομές. Η πλατφόρμα μας προσφέρει αυτόματες υπενθυμίσεις <strong style="color:#b32430;">SMS</strong> & <strong style="color:#3a86ff;">EMAIL</strong>, πλήρη διαχείριση σωματείου, αθλητών και οικονομικών - όλα σε ένα ενιαίο σύστημα διαχείρισης.</p>
    <div class="hero-ctas">
      <a href="<?= APP_URL ?>/register.php" class="btn-primary"><i class="fas fa-rocket"></i> Ξεκινήστε Δωρεάν - 14 ημέρες</a>
      <a href="<?= APP_URL ?>/demo-login.php" class="btn-outline" style="background:rgba(230,57,70,.06);border-color:rgba(230,57,70,.35);color:#ff8891"><i class="fa-solid fa-play"></i> Δείτε Live Demo — χωρίς εγγραφή</a>
      <a href="login.php" class="btn-outline"><i class="fa-solid fa-arrow-right-to-bracket"></i> Συνδεθείτε Εδώ</a>
    </div>
  </div>
</section>

<!-- ═══ STATS BAR ═══ -->
<div class="stats-bar">
  <div class="stats-inner">
    <div class="stat-item rev">
      <div class="stat-num r loading" id="stat-athletes">—</div>
      <div class="stat-lbl">Ενεργοί Αθλητές</div>
    </div>
    <div class="stat-item rev d1">
      <div class="stat-num g loading" id="stat-schools">—</div>
      <div class="stat-lbl">Συνδεδεμένα Σωματεία</div>
    </div>
    <div class="stat-item rev d2">
      <div class="stat-num w loading" id="stat-reminders">—</div>
      <div class="stat-lbl">Υπενθυμίσεις που Εστάλησαν</div>
    </div>
    <div class="stat-item rev d3">
      <div class="stat-num r">24/7</div>
      <div class="stat-lbl">Αυτόματες Υπενθυμίσεις</div>
    </div>
  </div>
</div>

<!-- ═══ FOR WHOM ═══ -->
<section class="section" id="for-whom">
  <div class="s-inner">
    <div class="rev">
      <div class="s-tag">Τι Προσφέρει</div>
      <h2 class="s-h2">Πλήρης <em>διαχείριση σχολής</em><br>σε μία εφαρμογή</h2>
      <p class="s-desc">Το <strong style="color:#d62f3d;font-weight:600;">MAster</strong> αναλαμβάνει τη γραφειοκρατία για εσάς. Δεν χρειάζεται να τηλεφωνείτε, να θυμάστε ημερομηνίες ή να κυνηγάτε πληρωμές. Εσείς ρυθμίζετε - το σύστημα κάνει τα υπόλοιπα.</p>
    </div>
    <div class="whom-grid">
      <div class="whom-card rev d1">
        <div class="w-icon"><i class="fas fa-mobile-alt" style="color:var(--red);"></i></div>
        <h3>Αυτόματες Υπενθυμίσεις Πληρωμών</h3>
        <p>Το σύστημα στέλνει αυτόματα SMS και email στους γονείς / αθλητές όταν μια συνδρομή πλησιάζει στη λήξη ή έχει καθυστερήσει — χωρίς καμία χειροκίνητη ενέργεια.</p>
      </div>
      <div class="whom-card rev d2">
        <div class="w-icon"><i class="fas fa-users" style="color:var(--gold);"></i></div>
        <h3>Πλήρης Διαχείριση Αθλητών</h3>
        <p>Οργανώστε όλους τους αθλητές και τα στοιχεία τους σε ένα ασφαλές σύστημα: στοιχεία επικοινωνίας γονέων, τμήματα, πληρωμές και ιστορικό - όλα εύκολα και συγκεντρωμένα.</p>
      </div>
      <div class="whom-card rev d3">
        <div class="w-icon"><i class="fa-solid fa-house-chimney-user" style="color:#a855f7;"></i></div>
        <h3>Πύλη Γονέων για Πληρωμές</h3>
        <p>Δώστε στους γονείς πρόσβαση στο δικό τους portal — βλέπουν σε πραγματικό χρόνο την κατάσταση πληρωμών και το ιστορικό συνδρομών των παιδιών τους.</p>
      </div>
      <div class="whom-card rev d4">
        <div class="w-icon"><i class="fas fa-chart-line" style="color:#3a86ff;"></i></div>
        <h3>Οικονομική Οργάνωση & Αναφορές</h3>
        <p>Παρακολουθήστε έσοδα, οφειλές και τη συνολική οικονομική κατάσταση της σχολής σε πραγματικό χρόνο, με δυναμικά γραφήματα και αναλυτικές αναφορές.</p>
      </div>
    </div>
  </div>
</section>

<!-- ═══ FEATURES ═══ -->
<section class="section feat-section" id="features">
  <div class="s-inner">
    <div class="rev">
      <div class="s-tag">Λειτουργίες</div>
      <h2 class="s-h2">Ό,τι χρειάζεστε,<br><em>τίποτα παραπάνω</em></h2>
      <p class="s-desc">Κάθε εργαλείο σχεδιάστηκε ειδικά για να εξοικονομεί χρόνο και να αυτοματοποιεί χρονοβόρες διαδικασίες.</p>
    </div>
    <div class="feat-grid">
      <div class="feat-card rev"><div class="f-icon fi-r"><i class="fas fa-users"></i></div><div><h3>Διαχείριση Αθλητών</h3><p>Πλήρες προφίλ αθλητή με στοιχεία γονέα. Γρήγορη αναζήτηση & φίλτρα ανά τμήμα, κατάσταση πληρωμής και οφειλές.</p></div></div>
      <div class="feat-card rev d1"><div class="f-icon fi-g"><i class="fas fa-mobile-alt"></i></div><div><h3>SMS & Email Υπενθυμίσεις</h3><p>Αυτόματες υπενθυμίσεις πληρωμής. Το σύστημα ειδοποιεί μόνο του τους οφειλέτες, χωρίς να χρειαστεί να κάνετε τίποτα χειροκίνητα.</p></div></div>
      <div class="feat-card rev d2"><div class="f-icon fi-gr"><i class="fas fa-coins"></i></div><div><h3>Συνδρομές & Πληρωμές</h3><p>Παρακολουθήστε όλες τις συνδρομές των μελών σας - δείτε άμεσα ποιος χρωστάει, πότε λήγει η συνδρομή του και πώς - πότε πλήρωσε.</p></div></div>
      <div class="feat-card rev d1"><div class="f-icon fi-p"><i class="fa-solid fa-house-chimney-user"></i></div><div><h3>Πύλη Γονέων</h3><p>Οι γονείς έχουν πρόσβαση στο δικό τους portal όπου βλέπουν την κατάσταση πληρωμών των παιδιών τους σε πραγματικό χρόνο — χωρίς να χρειάζεται να επικοινωνήσουν μαζί σας.</p></div></div>
    </div>
  </div>
</section>

<!-- ═══ HOW IT WORKS ═══ -->
<section class="section" id="how-it-works">
  <div class="s-inner" style="text-align:center">
    <div class="rev">
      <div class="s-tag">Πώς Λειτουργεί</div>
      <h2 class="s-h2">Έτοιμοι σε <em>2 λεπτά</em></h2>
      <p class="s-desc" style="margin:0 auto">Χωρίς εγκατάσταση. Χωρίς τεχνικές γνώσεις. Εγγραφή και αμέσως έναρξη.</p>
    </div>
    <div class="steps">
      <div class="step rev d1"><div class="step-n">01</div><h3>Εγγραφή Σχολής</h3><p>Δημιουργήστε λογαριασμό με το email σας. Χωρίς πληρωμή, χωρίς δέσμευση.</p></div>
      <div class="step rev d2"><div class="step-n">02</div><h3>Προσθήκη Αθλητών</h3><p>Εισάγετε αθλητές, τμήματα και στοιχεία επικοινωνίας γρήγορα & εύκολα.</p></div>
      <div class="step rev d3"><div class="step-n">03</div><h3>Ρύθμιση Συνδρομών</h3><p>Ορίστε μηνιαίες συνδρομές. Το σύστημα στέλνει υπενθυμίσεις αυτόματα χωρίς καμία ενέργεια.</p></div>
      <div class="step rev d4"><div class="step-n">04</div><h3>Πλήρης Διαχείριση</h3><p>Από οποιαδήποτε συσκευή. Αναφορές, πληρωμές, portal γονέων - όλα στα χέρια σας.</p></div>
    </div>
  </div>
</section>

<!-- ═══ PRICING ═══ -->
<section class="section" id="pricing">
  <div class="s-inner" style="text-align:center">
    <div class="rev">
      <div class="s-tag">Τιμές</div>
      <h2 class="s-h2">Απλές τιμές,<br><em>χωρίς εκπλήξεις</em></h2>
      <p class="s-desc" style="margin:0 auto">14 ημέρες δωρεάν δοκιμή. Δεν χρειάζεται κάρτα. Δυνατότητα ακύρωσης οποτεδήποτε.</p>
    </div>
    <div class="price-toggle">
      <span class="p-lbl on" id="lm">Μηνιαία</span>
      <button class="p-switch" id="ptoggle" type="button" onclick="toggleP()">
        <div class="p-knob"></div>
      </button>
      <span class="p-lbl" id="la">Ετήσια</span>
      <span class="save-tag">Εξοικονομήστε Χρήματα</span>
    </div>
    <div class="pricing-grid">
      <!-- BASIC -->
      <div class="p-card rev">
        <div class="p-name">Βασικό</div>
        <div class="p-sub">Μόνο email υπενθυμίσεις</div>
        <div class="p-price">
          <span class="p-curr">€</span><span id="pb">15</span><span id="pb-dec" style="font-size:1.5rem">,00</span><span class="p-per" id="pb-per">/μήνα</span>
        </div>
        <div class="p-vat" style="font-size:.72rem;color:var(--muted);letter-spacing:.04em;margin-top:-.4rem;margin-bottom:.5rem">συμπ. ΦΠΑ</div>
        <div class="p-annual" id="ab">&nbsp;</div>
        <a href="<?= APP_URL ?>/register.php?plan=basic" class="btn-plan btn-plan-s" id="basic-plan-link">Ξεκινήστε με Βασικό &nbsp;<i class="fa-solid fa-arrow-right-to-bracket"></i></a>
        <div class="p-div"></div>
        <ul class="p-feats">
          <li><span class="ck">✓</span> Έως 60 αθλητές</li>
          <li><span class="ck">✓</span> Πλήρη προφίλ Αθλητών</li>
          <li><span class="ck">✓</span> Portal Γονέων</li>
          <li><span class="ck">✓</span> Συνδρομές & πληρωμές</li>
          <li><span class="ck">✓</span> Email υπενθυμίσεις</li>
          <li><span class="ck">✓</span> Τμήματα & πρόγραμμα</li>
          <li class="dim"><span class="no">✕</span> SMS υπενθυμίσεις</li>
          <li class="dim"><span class="no">✕</span> Οικονομικά & αναφορές</li>
        </ul>
      </div>

      <!-- PRO -->
      <div class="p-card hot rev d1">
        <div class="ribbon"><i class="fas fa-star" style="margin-right:0.2rem;"></i> Δημοφιλές</div>
        <div class="p-name" style="color:var(--gold)">Pro</div>
        <div class="p-sub">Για πλήρη αυτοματοποίηση</div>
        <div class="p-price" style="color:var(--gold)">
          <span class="p-curr" style="color:var(--gold)">€</span><span id="pp">25</span><span id="pp-dec" style="font-size:1.5rem">,00</span><span class="p-per" id="pp-per">/μήνα</span>
        </div>
        <div class="p-vat" style="font-size:.72rem;color:var(--muted);letter-spacing:.04em;margin-top:-.4rem;margin-bottom:.5rem">συμπ. ΦΠΑ</div>
        <div class="p-annual" id="ap">&nbsp;</div>
        <a href="<?= APP_URL ?>/register.php?plan=pro" class="btn-plan btn-plan-r" id="pro-plan-link">Ξεκινήστε με Pro &nbsp;<i class="fa-solid fa-arrow-right-to-bracket"></i></a>
        <div class="p-div"></div>
        <ul class="p-feats">
          <li><span class="ck">✓</span> <strong>Απεριόριστοι αθλητές</strong></li>
          <li><span class="ck">✓</span> Όλα του Βασικού</li>
          <li><span class="ck">✓</span> <strong>SMS υπενθυμίσεις</strong></li>
          <li><span class="ck">✓</span> Πλήρη οικονομικά</li>
          <li><span class="ck">✓</span> Αναφορές & γραφήματα</li>
          <li><span class="ck">✓</span> Μαζική Εξαγωγή Στατιστικών</li>
          <li><span class="ck">✓</span> Προτεραιότητα υποστήριξης</li>
          <li><span class="ck">✓</span> Πλήρες Πάνελ Διαχείρισης</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<style>
  .foot-copy a { color:var(--muted); transition:color .2s; }
  .foot-copy a:hover { color:var(--white); }
  .foot-copy a strong { color:inherit; font-weight:500; }
</style>

<!-- ═══ FOOTER ═══ -->
<footer class="footer">
  <div class="foot-inner">
    <div class="foot-top">
      <div class="foot-brand">
        <a href="index.php">
          <img src="./assets/img/logo-tr.png" alt="MAster" class="nav-logo-img" width="150">
        </a>
        <p>Μια ολοκληρωμένη πλατφόρμα διαχείρισης αθλητικών συλλόγων σε όλη την Ελλάδα.</p>
      </div>
      <div class="foot-col">
        <h4>Πλατφόρμα</h4>
        <ul>
          <li><a href="#features">Λειτουργίες</a></li>
          <li><a href="#pricing">Τιμές</a></li>
          <li><a href="#how-it-works">Πώς Λειτουργεί</a></li>
          <li><a href="#for-whom">Για Ποιους</a></li>
          <li><a href="<?= APP_URL ?>/register.php" class="foot-cta-link"><i class="fas fa-rocket"></i> Δωρεάν Δοκιμή</a></li>
        </ul>
      </div>
      <div class="foot-col">
        <h4>Νομικά</h4>
        <ul>
          <li><a href="<?= APP_URL ?>/legal/privacy.php">Πολιτική Απορρήτου</a></li>
          <li><a href="<?= APP_URL ?>/legal/terms.php">Όροι Χρήσης</a></li>
          <li><a href="<?= APP_URL ?>/legal/cookies.php">Πολιτική Cookies</a></li>
          <li><a href="<?= APP_URL ?>/legal/gdpr.php">GDPR & Δεδομένα</a></li>
          <li><a href="<?= APP_URL ?>/legal/refunds.php">Ακύρωση & Επιστροφές</a></li>
          <li><a href="<?= APP_URL ?>/legal/payments.php">Πληρωμές & Χρεώσεις</a></li>
        </ul>
      </div>
      <div class="foot-col">
        <h4>Υποστήριξη</h4>
        <ul>
          <li><a href="mailto:pkotsorgios654@gmail.com"><i class="fas fa-envelope"></i> pkotsorgios654@gmail.com</a></li>
<li>
  <a href="tel:+306986788178" aria-label="Call us at +30 698 678 8178">
    <i class="fas fa-mobile-alt"></i> +30 698 678 8178
  </a>
</li>
<li>
  <a href="tel:+302631028971" aria-label="Call us at +30 2631028971">
    <i class="fas fa-phone"></i> +30 26310 28971
  </a>
</li>          <li><a href="<?= APP_URL ?>/login.php"><i class="fas fa-lock"></i> Σύνδεση Χρήστη</a></li>
          <li><a href="<?= APP_URL ?>/register.php"><i class="fas fa-user-plus"></i> Εγγραφή Χρήστη</a></li>
        </ul>
      </div>
    </div>
    <!-- Newsletter opt-in -->
    <div id="newsletter" style="margin-top:2rem;padding:1.5rem 1.75rem;border:1px solid rgba(255,255,255,.08);border-radius:16px;background:linear-gradient(135deg,rgba(230,57,70,.08),rgba(240,165,0,.05));display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center;justify-content:space-between">
      <div style="flex:1;min-width:240px">
        <div style="font-weight:800;font-size:1.05rem;margin-bottom:.25rem;letter-spacing:-.01em">Λάβετε ενημερώσεις</div>
        <div style="color:var(--muted);font-size:.88rem;line-height:1.5">Νέα, features και ανακοινώσεις events — απευθείας στο email σας. Καμία spam.</div>
      </div>
      <form id="newsletter-form" style="display:flex;gap:.5rem;flex-wrap:wrap;flex:1;min-width:260px;max-width:520px" onsubmit="return newsletterSubmit(event)">
        <input type="email" name="email" required placeholder="you@example.com"
               style="flex:1;min-width:180px;padding:.7rem .95rem;border-radius:10px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#f0f2ff;font-family:inherit;font-size:.9rem">
        <button type="submit"
                style="padding:.7rem 1.1rem;border-radius:10px;border:none;background:linear-gradient(135deg,#e63946,#c72832);color:#fff;font-weight:700;font-size:.85rem;cursor:pointer;font-family:inherit;letter-spacing:.01em">
          Εγγραφή
        </button>
        <div id="newsletter-msg" role="status" aria-live="polite" style="width:100%;font-size:.82rem;color:#8892b0;min-height:1.2em"></div>
      </form>
    </div>

    <div class="foot-divider"></div>
    <div class="foot-bot">
      <div class="foot-bot-left">
        <span class="foot-copy">© <?= date('Y') ?> <strong>MAster</strong> · Κατασκευάστηκε με &nbsp;<i class="fas fa-heart" style="color:var(--red)"></i></span>
      </div>
    </div>
  </div>
</footer>

<script>
// ── Newsletter subscribe ──
function newsletterSubmit(e) {
  e.preventDefault();
  const form = e.target;
  const btn  = form.querySelector('button');
  const msg  = document.getElementById('newsletter-msg');
  const email = form.email.value.trim();
  if (!email) return false;
  btn.disabled = true;
  const originalLabel = btn.textContent;
  btn.textContent = '…';
  msg.style.color = '#8892b0';
  msg.textContent = 'Στέλνουμε…';
  const data = new FormData();
  data.append('email', email);
  fetch('<?= APP_URL ?>/api/newsletter_subscribe.php', { method:'POST', body:data })
    .then(r => r.json())
    .then(res => {
      msg.style.color = res.ok ? '#7bffb4' : '#ffb0b8';
      msg.textContent = res.message || (res.ok ? 'Εγγραφή επιτυχής.' : 'Πρόβλημα.');
      if (res.ok) form.email.value = '';
    })
    .catch(() => {
      msg.style.color = '#ffb0b8';
      msg.textContent = 'Δεν ήταν δυνατή η επικοινωνία με τον server.';
    })
    .finally(() => { btn.disabled = false; btn.textContent = originalLabel; });
  return false;
}

// ── Navbar scroll effect ──
const nav = document.getElementById('nav');
window.addEventListener('scroll', () => nav.classList.toggle('scrolled', window.scrollY > 20));

// ── Mobile menu toggle ──
const burger = document.getElementById('burger');
const mm     = document.getElementById('mmenu');
const body   = document.body;

function mmOpen() {
  burger.classList.add('open');
  body.classList.add('no-scroll');
  mm.classList.remove('open');
  requestAnimationFrame(() => requestAnimationFrame(() => mm.classList.add('open')));
  const portal = document.getElementById('dm-icon-portal');
  if (portal) portal.style.display = 'none';
}

function mmClose() {
  burger.classList.remove('open');
  mm.classList.remove('open');
  body.classList.remove('no-scroll');
  const portal = document.getElementById('dm-icon-portal');
  if (portal) portal.style.display = '';
}

burger.addEventListener('click', () => {
  mm.classList.contains('open') ? mmClose() : mmOpen();
});
mm.querySelectorAll('a').forEach(a => a.addEventListener('click', () => mmClose()));

// ── Scroll reveal ──
const io = new IntersectionObserver(entries => {
  entries.forEach(entry => {
    if (entry.isIntersecting) entry.target.classList.add('in');
  });
}, { threshold: .1 });
document.querySelectorAll('.rev').forEach(el => io.observe(el));

// ══════════════════════════════════════════
// ── DYNAMIC STATS — fetch from DB ──
// ══════════════════════════════════════════
function animateCounter(el, target, suffix) {
  suffix = suffix || '';
  el.classList.remove('loading');
  const duration  = 1800;
  const startTime = performance.now();
  function update(now) {
    const progress = Math.min((now - startTime) / duration, 1);
    const eased    = 1 - Math.pow(1 - progress, 3);
    el.textContent = Math.floor(target * eased) + suffix;
    if (progress < 1) requestAnimationFrame(update);
    else el.textContent = target + suffix;
  }
  requestAnimationFrame(update);
}

function renderStats(d) {
  var elA = document.getElementById('stat-athletes');
  var elS = document.getElementById('stat-schools');
  var elR = document.getElementById('stat-reminders');
  if (elA && !elA.dataset.done) { elA.dataset.done = '1'; animateCounter(elA, d.athletes); }
  if (elS && !elS.dataset.done) { elS.dataset.done = '1'; animateCounter(elS, d.schools); }
  if (elR && !elR.dataset.done) { elR.dataset.done = '1'; animateCounter(elR, d.reminders, '+'); }
}

window._statsVisible = false;
window._statsData    = null;

// Start fetching immediately in background
fetch('<?= APP_URL ?>/api/public_stats.php')
  .then(function(r) { return r.json(); })
  .then(function(json) {
    if (!json.success) return;
    window._statsData = json.data;
    // Render immediately — even on tall mobile layouts where the
    // IntersectionObserver threshold may never fire. The observer
    // below still upgrades to the animated version if we get in view
    // first, but we no longer depend on it to show real numbers.
    renderStats(json.data);
  })
  .catch(function() {
    var els = ['stat-athletes','stat-schools','stat-reminders'];
    els.forEach(function(id) {
      var el = document.getElementById(id);
      if (el) { el.classList.remove('loading'); el.textContent = '—'; }
    });
  });

// Optional: still observe for the animated counter effect when the
// user actually scrolls to the bar. Threshold 0 = fire as soon as any
// pixel is visible (was .25 which never fired on tall mobile layouts).
var statsBar = document.querySelector('.stats-bar');
if (statsBar && 'IntersectionObserver' in window) {
  var statsObserver = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        window._statsVisible = true;
        if (window._statsData) renderStats(window._statsData);
        statsObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0, rootMargin: '0px 0px -10% 0px' });
  statsObserver.observe(statsBar);
}

// ── Pricing toggle ──
function toggleP() {
  const sw = document.getElementById('ptoggle');
  const annual = sw.classList.toggle('annual');

  document.getElementById('lm').classList.toggle('on', !annual);
  document.getElementById('la').classList.toggle('on', annual);

  const basicLink = document.getElementById('basic-plan-link');
  const proLink   = document.getElementById('pro-plan-link');

  basicLink.href = '<?= APP_URL ?>/register.php?plan=basic' + (annual ? '&billing=annual' : '');
  proLink.href   = '<?= APP_URL ?>/register.php?plan=pro'   + (annual ? '&billing=annual' : '');

  if (annual) {
    document.getElementById('pb').textContent = '12';
    document.getElementById('pb-dec').textContent = ',50';
    document.getElementById('pb-per').textContent = '/μήνα';
    document.getElementById('pp').textContent = '20';
    document.getElementById('pp-dec').textContent = ',00';
    document.getElementById('pp-per').textContent = '/μήνα';
    document.getElementById('ab').innerHTML = '<span style="color:#2dc653;font-weight:600">€150/έτος — γλιτώστε €30</span>';
    document.getElementById('ap').innerHTML = '<span style="color:#2dc653;font-weight:600">€240/έτος — γλιτώστε €60</span>';
  } else {
    document.getElementById('pb').textContent = '15';
    document.getElementById('pb-dec').textContent = ',00';
    document.getElementById('pb-per').textContent = '/μήνα';
    document.getElementById('pp').textContent = '25';
    document.getElementById('pp-dec').textContent = ',00';
    document.getElementById('pp-per').textContent = '/μήνα';
    document.getElementById('ab').innerHTML = '&nbsp;';
    document.getElementById('ap').innerHTML = '&nbsp;';
  }
}

// ── Smooth scroll ──
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const t = document.querySelector(a.getAttribute('href'));
    if (t) {
      e.preventDefault();
      window.scrollTo({
        top: t.getBoundingClientRect().top + window.scrollY - 80,
        behavior: 'smooth'
      });
    }
  });
});
</script>

<script>
// ── Hero title fit ──
(function(){
  const title = document.querySelector('.hero-h1');
  const wrap  = document.querySelector('.hero-content');
  if (!title || !wrap) return;

  function fitHeroTitle() {
    title.style.fontSize = '';
    const maxWidth = wrap.clientWidth;
    if (!maxWidth) return;

    const meas = document.createElement('div');
    const cs   = getComputedStyle(title);

    meas.style.position   = 'absolute';
    meas.style.visibility = 'hidden';
    meas.style.left       = '-9999px';
    meas.style.top        = '-9999px';
    meas.style.whiteSpace = 'nowrap';
    meas.style.fontFamily = cs.fontFamily;
    meas.style.fontWeight = cs.fontWeight;
    meas.style.letterSpacing         = cs.letterSpacing;
    meas.style.textTransform         = cs.textTransform;
    meas.style.webkitTextStrokeWidth = cs.webkitTextStrokeWidth;
    meas.style.webkitTextStrokeColor = cs.webkitTextStrokeColor;

    document.body.appendChild(meas);

    const lines = title.innerText.split('\n').map(s => s.trim()).filter(Boolean);
    let fontSize = parseFloat(cs.fontSize);
    const minSize = 26;
    const step    = 1;

    function anyOverflow(sz) {
      meas.style.fontSize = sz + 'px';
      return lines.some(line => {
        meas.textContent = line;
        return meas.getBoundingClientRect().width > maxWidth;
      });
    }

    while (fontSize > minSize && anyOverflow(fontSize)) fontSize -= step;
    title.style.fontSize = fontSize + 'px';
    document.body.removeChild(meas);
  }

  const rafFit = () => requestAnimationFrame(fitHeroTitle);
  window.addEventListener('load', rafFit);
  window.addEventListener('resize', rafFit);
  window.addEventListener('orientationchange', rafFit);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(rafFit).catch(() => {});
})();
</script>

<!-- Cookie Consent -->
<script src="<?= APP_URL ?>/assets/js/cookie-consent.js"></script>
</body>
</html>