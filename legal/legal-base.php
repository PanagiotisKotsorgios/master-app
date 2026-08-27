<?php
// legal-base.php — shared header/footer for all legal pages
// Usage: include this file, set $legalTitle, $legalLastUpdated, $legalContent before calling legalPage()

function legalHeader(string $title, string $lastUpdated): void { ?>
<?php require_once '../includes/config.php'; ?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> - MAster</title>
<meta name="robots" content="noindex,follow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@400;600;700;800;900&family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="shortcut icon" href="../assets/img/favicon.png" type="image/png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#060810;--bg2:#0d1017;--bg3:#131722;
  --red:#e63946;--red2:#d62f3d;
  --gold:#f0a500;
  --white:#f0f2ff;--muted:#6b7494;--muted2:#3d4362;
  --border:rgba(255,255,255,.06);--border2:rgba(255,255,255,.1);
  --radius:12px;--radius-lg:20px;--nav-h:68px;
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--white);min-height:100vh;overflow-x:hidden;line-height:1.6}
a{text-decoration:none;color:inherit}
body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");opacity:.02;pointer-events:none;z-index:9998}

/* ══════════════════════════════════════════
   NAV — fixed height, never wraps
══════════════════════════════════════════ */
.nav{
  position:fixed;top:0;left:0;right:0;z-index:200;
  height:var(--nav-h);
  display:flex;align-items:center;
  padding:0 1.5rem;
  background:rgba(6,8,16,.94);backdrop-filter:blur(24px);
  border-bottom:1px solid var(--border);
}
.nav-inner{
  max-width:1100px;width:100%;margin:0 auto;
  display:flex;align-items:center;justify-content:space-between;
  gap:.75rem;
  min-width:0;
}
.nav-logo-link{flex-shrink:0;display:flex;align-items:center;min-width:0}
.nav-logo-img{
  height:clamp(36px,5.5vw,52px);
  width:auto;display:block;object-fit:contain;
  max-width:clamp(120px,30vw,220px);
}
.nav-ctas{
  display:flex;align-items:center;
  gap:clamp(.3rem,.8vw,.75rem);
  flex-shrink:0;
}

/* ── PARENT PORTAL BUTTON - Red Border Only ── */
.btn-parent-portal {
  font-size: clamp(.72rem,.9vw,.9rem);
  font-weight: 500;
  color: var(--red);
  padding: clamp(.35rem,.5vw,.45rem) clamp(.5rem,.8vw,.95rem);
  border: 1.5px solid var(--red);
  border-radius: 8px;
  transition: all .25s;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  white-space: nowrap;
}
.btn-parent-portal:hover {
  background: rgba(230, 57, 70, 0.08);
  color: #fff;
  border-color: #fff;
  transform: translateY(-1px);
}

.btn-nav-login{
  font-size:clamp(.72rem,.9vw,.85rem);
  font-weight:500;color:var(--muted);
  padding:clamp(.35rem,.5vw,.45rem) clamp(.5rem,.8vw,.875rem);
  border-radius:8px;transition:all .2s;white-space:nowrap;
}
.btn-nav-login:hover{color:var(--white);background:rgba(255,255,255,.06)}
.btn-nav-cta{
  font-size:clamp(.72rem,.9vw,.85rem);
  font-weight:700;
  background:linear-gradient(135deg,var(--red),var(--red2));
  color:#fff;
  padding:clamp(.35rem,.5vw,.5rem) clamp(.6rem,.9vw,1.25rem);
  border-radius:8px;transition:all .2s;
  box-shadow:0 0 18px rgba(230,57,70,.4);
  white-space:nowrap;
}
.btn-nav-cta:hover{box-shadow:0 0 28px rgba(230,57,70,.6);transform:translateY(-1px)}

/* Extra-small screens — shrink logo further, text stays visible */
@media(max-width:360px){
  .btn-nav-login{padding:.35rem .45rem}
  .btn-parent-portal{padding:.35rem .45rem}
  .nav-logo-img{max-width:90px}
}

/* ══════════════════════════════════════════
   HERO
══════════════════════════════════════════ */
.legal-hero{
  padding:calc(var(--nav-h) + 2.5rem) 1.25rem 2.5rem;
  background:linear-gradient(180deg,rgba(230,57,70,.06) 0%,transparent 100%);
  border-bottom:1px solid var(--border);
  text-align:center;
}
.legal-hero-inner{max-width:700px;margin:0 auto}
.legal-tag{display:inline-flex;align-items:center;gap:.5rem;background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.22);color:var(--red2);border-radius:50px;padding:.35rem 1rem;font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:1.25rem}
.legal-title{font-family:'Syne',sans-serif;font-weight:800;font-size:clamp(1.5rem,5vw,3rem);line-height:1.1;margin-bottom:.75rem}
.legal-title em{font-style:normal;color:var(--red)}
.legal-meta{font-size:.82rem;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:1.5rem;flex-wrap:wrap;margin-top:.75rem}
.legal-meta span{display:flex;align-items:center;gap:.4rem}

/* ══════════════════════════════════════════
   CONTENT
══════════════════════════════════════════ */
.legal-wrap{max-width:820px;margin:0 auto;padding:3.5rem 1.5rem 5rem}
.legal-content h2{font-family:'Syne',sans-serif;font-weight:700;font-size:1.2rem;color:var(--white);margin:2.5rem 0 .75rem;padding-bottom:.5rem;border-bottom:1px solid var(--border)}
.legal-content h2:first-child{margin-top:0}
.legal-content h3{font-family:'Syne',sans-serif;font-weight:600;font-size:1rem;color:var(--gold);margin:1.5rem 0 .5rem}
.legal-content p{font-size:.9rem;color:var(--muted);line-height:1.85;margin-bottom:1rem}
.legal-content p strong{color:var(--white);font-weight:600}
.legal-content ul,.legal-content ol{padding-left:1.5rem;margin-bottom:1rem}
.legal-content li{font-size:.9rem;color:var(--muted);line-height:1.8;margin-bottom:.3rem}
.legal-content li strong{color:var(--white)}
.legal-content a{color:var(--red);text-decoration:underline;text-underline-offset:3px}
.legal-content a:hover{color:var(--white)}
.info-box{background:var(--bg2);border:1px solid var(--border);border-left:3px solid var(--red);border-radius:var(--radius);padding:1.25rem 1.5rem;margin:1.5rem 0}
.info-box.gold{border-left-color:var(--gold)}
.info-box.green{border-left-color:#2dc653}
.info-box p{margin:0;font-size:.875rem}
.info-box p+p{margin-top:.5rem}

/* ══════════════════════════════════════════
   TABLES — universal horizontal scroll
══════════════════════════════════════════ */
.table-wrap,
.table-responsive{
  width:100%;
  overflow-x:auto;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior-x:contain;
  margin:1.25rem 0;
  border-radius:var(--radius);
  background:
    linear-gradient(to right,  var(--bg2) 20%, transparent) left  center / 40px 100% no-repeat,
    linear-gradient(to left,   var(--bg2) 20%, transparent) right center / 40px 100% no-repeat,
    radial-gradient(farthest-side at 0%   50%, rgba(0,0,0,.28), transparent) left  center / 14px 100% no-repeat scroll,
    radial-gradient(farthest-side at 100% 50%, rgba(0,0,0,.28), transparent) right center / 14px 100% no-repeat scroll;
  background-attachment:local,local,scroll,scroll;
  scrollbar-width:thin;
  scrollbar-color:var(--red) rgba(255,255,255,.04);
}
.table-wrap::-webkit-scrollbar,
.table-responsive::-webkit-scrollbar{height:5px}
.table-wrap::-webkit-scrollbar-track,
.table-responsive::-webkit-scrollbar-track{background:rgba(255,255,255,.04);border-radius:3px}
.table-wrap::-webkit-scrollbar-thumb,
.table-responsive::-webkit-scrollbar-thumb{background:var(--red);border-radius:3px}
.table-wrap::-webkit-scrollbar-thumb:hover,
.table-responsive::-webkit-scrollbar-thumb:hover{background:var(--red2)}

.table-wrap .legal-table,
.table-responsive .legal-table{
  margin:0;
  min-width:420px;
  width:100%;
}

.legal-table{width:100%;border-collapse:collapse;margin:1.25rem 0;font-size:.83rem}
.legal-table th{background:var(--bg3);color:var(--white);font-weight:600;padding:.625rem .875rem;text-align:left;border:1px solid var(--border)}
.legal-table td{padding:.575rem .875rem;border:1px solid var(--border);color:var(--muted);vertical-align:top}
.legal-table tr:nth-child(even) td{background:rgba(255,255,255,.02)}

@media(max-width:480px){
  .table-wrap .legal-table th,
  .table-wrap .legal-table td,
  .table-responsive .legal-table th,
  .table-responsive .legal-table td{
    font-size:.75rem;
    padding:.4rem .55rem;
  }
  .table-wrap .legal-table code,
  .table-responsive .legal-table code{font-size:.72rem}
}

/* ══════════════════════════════════════════
   FOOTER
══════════════════════════════════════════ */
.footer{padding:3rem 1.5rem 2rem;border-top:1px solid var(--border)}
.foot-inner{max-width:1100px;margin:0 auto;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1.25rem}
.foot-links{display:flex;gap:1rem;flex-wrap:wrap}
.foot-links a{font-size:.8rem;color:var(--muted);transition:color .2s}
.foot-links a:hover{color:var(--white)}
.foot-copy{font-size:.76rem;color:var(--muted2)}

/* ══════════════════════════════════════════
   RESPONSIVE OVERRIDES
══════════════════════════════════════════ */
@media(max-width:640px){
  .legal-wrap{padding:2rem 1.1rem 4rem}
  .legal-hero{padding-inline:1.1rem}
  .legal-meta{flex-direction:column;gap:.5rem}
  .foot-inner{flex-direction:column}
  .foot-links{gap:.75rem}
  .foot-inner > div:last-child{text-align:left !important}
}

@media(max-width:400px){
  .legal-wrap{padding:1.75rem .9rem 3.5rem}
  .legal-content h2{font-size:1.05rem}
  .legal-content p,
  .legal-content li{font-size:.84rem}
  .info-box{padding:1rem 1.1rem}
  .info-box p{font-size:.82rem}
}
</style>
<?php include __DIR__ . '/../includes/prelogin_polish.php'; ?>
</head>
<body>

<nav class="nav">
  <div class="nav-inner">
    <a href="<?= APP_URL ?>/" class="nav-logo-link">
      <img src="<?= APP_URL ?>/assets/img/logo-tr.png" alt="MAster" class="nav-logo-img">
    </a>
    <div class="nav-ctas">
      <a href="<?= APP_URL ?>/parent/login.php" class="btn-parent-portal">
        <i class="fa-solid fa-house-chimney-user"></i> Πύλη Γονέα / Αθλητή
      </a>
      <a href="<?= APP_URL ?>/login.php" class="btn-nav-login">
        <i class="fa-solid fa-arrow-right-to-bracket"></i> Σύνδεση
      </a>
      <a href="<?= APP_URL ?>/register.php" class="btn-nav-cta">Δωρεάν Δοκιμή →</a>
    </div>
  </div>
</nav>

<div class="legal-hero">
  <div class="legal-hero-inner">
    <h1 class="legal-title"><?= htmlspecialchars($title) ?></h1>
    <div class="legal-meta">
      <span><i class="fas fa-clock"></i> Τελευταία ενημέρωση: <?= htmlspecialchars($lastUpdated) ?></span>
      <span><i class="fas fa-user"></i> MAster</span>
    </div>
  </div>
</div>

<div class="legal-wrap">
  <div class="legal-content">
<?php } ?>

<?php function legalFooter(): void { ?>
  </div><!-- .legal-content -->
</div><!-- .legal-wrap -->

<footer class="footer">
  <div class="foot-inner">
    <div class="foot-links">
      <a href="privacy.php">Πολιτική Απορρήτου</a>
      <a href="terms.php">Όροι Χρήσης</a>
      <a href="cookies.php">Cookies</a>
      <a href="gdpr.php">GDPR</a>
      <a href="refunds.php">Επιστροφές</a>
      <a href="payments.php">Πληρωμές</a>
    </div>
    <div style="font-size:.76rem;color:var(--muted2);line-height:1.7;text-align:right;">
      <strong style="color:var(--muted);">ΚΟΤΣΟΡΓΙΟΣ ΠΑΝΑΓΙΩΤΗΣ</strong> · Ατομική Επιχείρηση<br>ΑΦΜ: 176091030<br>
      Εργατικές Κατοικίες 113 Λιμάνι Μεσολόγγι, 30200 · Ελλάδα<br>
      Email: <a href="mailto:pkotsorgios654@gmail.com" style="color:var(--muted2);">pkotsorgios654@gmail.com</a><br>
      ΗΕΔ: <a href="https://ec.europa.eu/consumers/odr" target="_blank" rel="noopener" style="color:var(--muted2);">ec.europa.eu/consumers/odr</a><br>
      <span class="foot-copy">© <?= date('Y') ?> MAster · Όλα τα δικαιώματα διατηρούνται</span>
    </div>
  </div>
</footer>

</body>
</html>
<?php } ?>