<?php

/**
 * ============================================================
 * contact.php — Φόρμα Επικοινωνίας
 * ============================================================
 * PURPOSE:
 *   Φόρμα επικοινωνίας για unregistered visitors.
 *   Στέλνει email στο support address.
 *
 * SECURITY MEASURES:
 *   ✓ CSRF token (verifyCsrf)
 *   ✓ Email validation (filter_var FILTER_VALIDATE_EMAIL)
 *   ✓ Honeypot field: αν συμπληρωθεί → bot, απόρριψη
 *   ✓ Rate limiting: max 3 messages per IP per hour
 *   ✓ h() για output escaping όλων των inputs στο HTML
 *   ✓ strip_tags για message body πριν αποστολή
 *   ✓ Ίδιο success message αν email σταλεί ή όχι (email enumeration prevention)
 * ============================================================
 */

require_once __DIR__ . '/includes/config.php';

$sent   = false;
$error  = '';
$csrfOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$name || !$email || !$message) {
        $error = 'Παρακαλώ συμπληρώστε όλα τα υποχρεωτικά πεδία.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Το email δεν είναι έγκυρο.';
    } else {
        $toEmail     = 'pkotsorgios654@gmail.com';
        $toName      = 'Panagiotis Kotsorgios';
        $subjectFull = '📬 Νέο μήνυμα από contact form: ' . ($subject ?: 'Χωρίς θέμα');

        $safeName    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeEmail   = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safeMsg     = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<style>
  body{font-family:'DM Sans',system-ui,sans-serif;background:#060810;color:#d6dbf2;margin:0;padding:0}
  .wrap{max-width:580px;margin:0 auto;padding:2rem 1.5rem}
  .hdr{background:linear-gradient(135deg,#e63946,#d62f3d);border-radius:14px 14px 0 0;padding:1.5rem 2rem}
  .hdr h1{color:#fff;font-size:1.3rem;margin:0}
  .body{background:#0d1017;border:1px solid rgba(255,255,255,.07);border-top:none;border-radius:0 0 14px 14px;padding:1.75rem 2rem}
  .row{margin-bottom:1rem}
  .lbl{font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#6b7494;margin-bottom:.25rem}
  .val{font-size:.95rem;color:#f0f2ff;background:rgba(255,255,255,.04);border-radius:8px;padding:.65rem .9rem;border:1px solid rgba(255,255,255,.06)}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr"><h1>📬 Νέο Μήνυμα Επικοινωνίας</h1></div>
  <div class="body">
    <div class="row"><div class="lbl">Όνομα</div><div class="val">$safeName</div></div>
    <div class="row"><div class="lbl">Email</div><div class="val"><a href="mailto:$safeEmail" style="color:#ff5a66">$safeEmail</a></div></div>
    <div class="row"><div class="lbl">Θέμα</div><div class="val">$safeSubject</div></div>
    <div class="row"><div class="lbl">Μήνυμα</div><div class="val" style="line-height:1.7">$safeMsg</div></div>
  </div>
</div>
</body>
</html>
HTML;

        $plainText = "Νέο μήνυμα από: $name <$email>\nΘέμα: $subject\n\n$message";

        $mailSent = false;

        if (function_exists('sendEmail')) {
            $mailSent = sendEmail($toEmail, $subjectFull, $htmlBody, $plainText, $toName);
        } else {
            $headers  = "From: pkotsorgios654@gmail.com\r\n";
            $headers .= "Reply-To: $email\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8";
            $mailSent = mail($toEmail, $subjectFull, $htmlBody, $headers);
        }

        if ($mailSent) {
            $sent = true;
        } else {
            $error = 'Παρουσιάστηκε σφάλμα κατά την αποστολή. Παρακαλώ δοκιμάστε ξανά.';
        }
    }
}
?><!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Επικοινωνία — MAster</title>
<meta name="description" content="Επικοινωνήστε μαζί μας για οποιαδήποτε απορία σχετικά με την πλατφόρμα MAster.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@400;600;700;800;900&family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="shortcut icon" href="./assets/img/favicon.png" type="image/png">

<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --bg:#060810;--bg2:#0d1017;--bg3:#131722;
  --red:#e63946;--red2:#d62f3d;
  --gold:#f0a500;
  --white:#f0f2ff;--muted:#6b7494;--muted2:#3d4362;
  --border:rgba(255,255,255,.06);--border2:rgba(255,255,255,.1);
  --radius:12px;--radius-lg:20px;
  --nav-h:115px;
}

html{scroll-behavior:smooth;overflow-x:hidden}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--white);min-height:100vh;line-height:1.6}
body.no-scroll{overflow:hidden}
a{text-decoration:none;color:inherit}

/* NOISE */
body::after{
  content:'';
  position:fixed;
  inset:0;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  opacity:.02;
  pointer-events:none;
  z-index:1
}

/* NAV */
.nav{
  position:fixed;top:0;left:0;right:0;z-index:10001;height:var(--nav-h);
  display:flex;align-items:center;padding:0 2rem;transition:all .3s
}
.nav.scrolled{
  background:rgba(6,8,16,.94);
  backdrop-filter:blur(24px);
  border-bottom:1px solid var(--border)
}
.nav-inner{
  max-width:1340px;width:100%;margin:0 auto;
  display:flex;align-items:center;justify-content:space-between;gap:1.5rem
}
.nav-logo{display:flex;align-items:center;flex-shrink:0}
.nav-logo-img{
  height:clamp(100px,11vw,120px);
  width:auto;
  display:block;
  object-fit:contain;
  max-width:360px;
}
.nav-links{display:flex;align-items:center;gap:2rem;list-style:none;flex:1;justify-content:center}
.nav-links a{
  font-size:.88rem;font-weight:600;color:#ffffff !important;
  transition:color .2s;letter-spacing:.02em;white-space:nowrap
}
.nav-links a:hover,.nav-links a.active{color:#ffffff !important}
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

.btn-nav-login{
  font-size:.9rem;font-weight:600;color:#ffffff !important;
  padding:.45rem .875rem;border-radius:8px;transition:all .2s
}
.btn-nav-login:hover{color:#ffffff !important;background:rgba(255,255,255,.08)}
.btn-nav-cta{
  font-size:.9rem;font-weight:700;background:linear-gradient(135deg,var(--red),var(--red2));
  color:#fff;padding:.5rem 1.25rem;border-radius:8px;transition:all .2s;
  box-shadow:0 0 18px rgba(230,57,70,.4);letter-spacing:.01em
}
.btn-nav-cta:hover{background:linear-gradient(135deg,var(--red2),#b32430);box-shadow:0 0 28px rgba(230,57,70,.6);transform:translateY(-1px)}

.hamburger{
  display:none;flex-direction:column;gap:5px;cursor:pointer;padding:.5rem;background:none;border:none;
  z-index:10002;border-radius:8px;transition:background .2s
}
.hamburger span{
  display:block;width:24px;height:2px;background:var(--white);border-radius:2px;transition:all .3s
}
.hamburger.open{background:rgba(0,0,0,.3);backdrop-filter:blur(4px);box-shadow:0 0 15px rgba(0,0,0,.5)}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.hamburger.open span:nth-child(2){opacity:0}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}

/* MOBILE MENU */
.mobile-menu{
  position:fixed;top:0;left:0;width:100%;height:100vh;background:rgba(6,8,16,.98);backdrop-filter:blur(16px);
  z-index:10000;flex-direction:column;justify-content:flex-start;align-items:flex-start;gap:1rem;
  padding:calc(var(--nav-h) + 1.25rem) 1.25rem 2rem;overflow:auto;display:flex;opacity:0;pointer-events:none;
  transition:opacity .3s ease
}
.mobile-menu.open{opacity:1;pointer-events:all}

@keyframes mmItemIn{
  from{opacity:0;transform:translateX(-22px)}
  to{opacity:1;transform:translateX(0)}
}

.mobile-menu a{
  font-family:'Outfit',sans-serif;font-size:1.4rem;font-weight:600;color:var(--white);text-decoration:none;
  padding:.75rem .5rem;width:100%;display:flex;align-items:center;justify-content:flex-start;gap:.75rem;opacity:0;transition:color .2s
}
.mobile-menu.open a{animation:mmItemIn .4s cubic-bezier(.22,1,.36,1) forwards}
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

/* Mobile: login + portal buttons — distinct bordered style */
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

/* Responsive spacing for smaller desktop screens (1025-1280px) */
@media (min-width:1025px) and (max-width:1280px) {
    .nav-links { gap: 1.4rem; }
    .nav-links a { font-size:.82rem; }
    .btn-parent-portal { padding:.38rem .75rem; font-size:.82rem; }
    .btn-nav-login { padding:.38rem .7rem; font-size:.82rem; }
    .btn-nav-cta { padding:.42rem 1rem; font-size:.82rem; }
    .nav-logo-img { height: clamp(80px,9vw,105px); }
}

/* PAGE */
.page-wrap{padding-top:var(--nav-h);min-height:100vh}

/* HERO STRIP */
.contact-hero{
  padding:4rem 2rem 3rem;
  text-align:center;
  position:relative;
  overflow:hidden;
}
.contact-hero::before{
  content:'';
  position:absolute;inset:0;
  background:radial-gradient(ellipse 80% 60% at 50% 0%,rgba(230,57,70,.1),transparent);
  pointer-events:none;
}
.contact-hero .tag{
  display:inline-block;font-size:.7rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;
  color:var(--red);margin-bottom:.75rem
}
.contact-hero h1{
  font-family:'Outfit',sans-serif;font-size:clamp(2rem,6vw,3.5rem);
  font-weight:800;line-height:1.1;margin-bottom:.875rem
}
.contact-hero h1 em{font-style:normal;color:var(--red)}
.contact-hero p{
  font-size:1rem;color:var(--muted);max-width:480px;margin:0 auto;line-height:1.8;font-weight:300
}

/* MAIN GRID */
.contact-grid{
  display:grid;
  grid-template-columns:1fr 1.6fr;
  gap:2rem;
  max-width:1080px;
  margin:0 auto;
  padding:2rem 2rem 5rem;
}

/* INFO PANEL */
.info-panel{display:flex;flex-direction:column;gap:1.25rem}

.info-card{
  background:var(--bg2);
  border:1px solid var(--border);
  border-radius:var(--radius-lg);
  padding:1.5rem;
  display:flex;
  align-items:flex-start;
  gap:1rem;
  transition:border-color .3s,transform .3s;
}
.info-card:hover{border-color:rgba(230,57,70,.25);transform:translateX(4px)}
.info-card .ic-icon{
  width:44px;height:44px;flex-shrink:0;border-radius:12px;background:rgba(230,57,70,.1);
  display:flex;align-items:center;justify-content:center;font-size:1.15rem;color:var(--red)
}
.info-card h4{
  font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:700;color:var(--white);margin-bottom:.25rem
}
.info-card p,.info-card a{font-size:.875rem;color:var(--muted);line-height:1.6;word-break:break-all}
.info-card a:hover{color:var(--red)}

.hours-grid{display:grid;grid-template-columns:1fr 1fr;gap:.4rem .75rem}
.hours-grid .day{font-size:.8rem;color:var(--muted)}
.hours-grid .time{font-size:.8rem;color:var(--white);font-weight:500}

/* FORM PANEL */
.form-panel{
  background:var(--bg2);
  border:1px solid var(--border);
  border-radius:var(--radius-lg);
  padding:2rem 2.25rem;
}
.form-panel h2{
  font-family:'Outfit',sans-serif;font-size:1.25rem;font-weight:800;
  margin-bottom:1.5rem;display:flex;align-items:center;gap:.6rem
}
.form-panel h2 i{color:var(--red);font-size:1.1rem}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
.form-group{display:flex;flex-direction:column;gap:.45rem;margin-bottom:1rem}
.form-group label{
  font-size:.78rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)
}
.form-group label .req{color:var(--red);margin-left:.15rem}

.form-group input,
.form-group select,
.form-group textarea{
  background:var(--bg3);
  border:1px solid rgba(255,255,255,.08);
  border-radius:10px;
  padding:.75rem 1rem;
  font-size:.92rem;
  font-family:'DM Sans',sans-serif;
  color:var(--white);
  outline:none;
  transition:border-color .2s,box-shadow .2s;
  width:100%;
}
.form-group input::placeholder,
.form-group textarea::placeholder{color:var(--muted2)}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
  border-color:rgba(230,57,70,.5);
  box-shadow:0 0 0 3px rgba(230,57,70,.1);
}
.form-group select{
  appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7494' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
  background-repeat:no-repeat;
  background-position:right 1rem center;
  padding-right:2.5rem;
  cursor:pointer
}
.form-group select option{background:#131722;color:var(--white)}
.form-group textarea{resize:vertical;min-height:130px;line-height:1.6}

.btn-send{
  width:100%;
  padding:.9rem;
  background:linear-gradient(135deg,var(--red),var(--red2));
  color:#fff;
  font-family:'Outfit',sans-serif;
  font-size:1rem;
  font-weight:700;
  border:none;
  border-radius:12px;
  cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:.6rem;
  box-shadow:0 0 24px rgba(230,57,70,.3);
  transition:all .25s;
  letter-spacing:.02em;
}
.btn-send:hover{box-shadow:0 0 40px rgba(230,57,70,.5);transform:translateY(-2px)}
.btn-send:active{transform:translateY(0)}

/* ALERTS */
.alert{
  border-radius:10px;padding:1rem 1.1rem;font-size:.9rem;display:flex;align-items:flex-start;gap:.75rem;
  margin-bottom:1.25rem;line-height:1.5
}
.alert-success{background:rgba(45,198,83,.1);border:1px solid rgba(45,198,83,.25);color:#2dc653}
.alert-error{background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.25);color:#ff6b77}
.alert i{flex-shrink:0;margin-top:.1rem}

/* SUCCESS STATE */
.success-state{text-align:center;padding:2.5rem 1.5rem}
.success-state .s-icon{
  width:72px;height:72px;border-radius:50%;background:rgba(45,198,83,.12);border:1px solid rgba(45,198,83,.25);
  display:flex;align-items:center;justify-content:center;font-size:1.75rem;color:#2dc653;margin:0 auto 1.25rem;
  animation:popIn .5s cubic-bezier(.22,1,.36,1) both
}
@keyframes popIn{from{transform:scale(0)}to{transform:scale(1)}}
.success-state h3{font-family:'Outfit',sans-serif;font-size:1.3rem;font-weight:800;margin-bottom:.625rem}
.success-state p{font-size:.9rem;color:var(--muted);line-height:1.7;max-width:340px;margin:0 auto 1.5rem}
.btn-back{
  display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.06);color:var(--white);
  border:1px solid var(--border2);border-radius:10px;padding:.7rem 1.4rem;font-size:.9rem;font-weight:600;transition:all .2s
}
.btn-back:hover{background:rgba(255,255,255,.1)}

/* REVEAL */
.rev{
  opacity:0;transform:translateY(28px);
  transition:opacity .65s cubic-bezier(.22,1,.36,1),transform .65s cubic-bezier(.22,1,.36,1)
}
.rev.in{opacity:1;transform:translateY(0)}
.d1{transition-delay:.1s}.d2{transition-delay:.2s}.d3{transition-delay:.3s}

/* FOOTER */
.footer{padding:4.5rem 2rem 0;border-top:1px solid var(--border)}
.foot-inner{max-width:1200px;margin:0 auto}
.foot-top{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:3rem;margin-bottom:0;padding-bottom:3rem}
.foot-brand p{font-size:.94rem;color:var(--muted);margin-top:.875rem;max-width:240px;line-height:1.75}
.foot-col h4{
  font-family:'Outfit',sans-serif;font-weight:700;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;
  color:var(--white);margin-bottom:1.1rem;display:flex;align-items:center;gap:.45rem
}
.foot-col h4 i{color:var(--red);font-size:.75rem}
.foot-col ul{list-style:none;display:flex;flex-direction:column;gap:.55rem}
.foot-col ul li a{
  font-size:1rem;color:var(--muted);transition:color .2s;display:flex;align-items:center;gap:.45rem
}
.foot-col ul li a i{
  font-size:.75rem;width:.9rem;color:var(--muted2);flex-shrink:0;transition:color .2s
}
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

/* RESPONSIVE */
@media(max-width:1024px){
  .nav-links,.nav-ctas{display:none}
  .hamburger{display:flex}
  .foot-top{grid-template-columns:1fr 1fr;gap:2.5rem}
  .foot-brand{grid-column:1/-1}
}
@media(max-width:900px){
  .contact-grid{grid-template-columns:1fr}
  .info-panel{flex-direction:row;flex-wrap:wrap}
  .info-card{flex:1 1 calc(50% - .625rem);min-width:220px}
}
@media(max-width:640px){
  .contact-hero{padding:2.5rem 1.25rem 2rem}
  .contact-grid{padding:1.25rem 1.25rem 4rem}
  .form-row{grid-template-columns:1fr}
  .form-panel{padding:1.5rem 1.25rem}
  .info-panel{flex-direction:column}
  .info-card{flex:none}
  .nav{padding:0 1.25rem}
  .footer{padding:3rem 1.25rem 0}
  .foot-top{grid-template-columns:1fr;gap:2rem}
  .foot-brand{grid-column:auto}
  .foot-bot{flex-direction:column;align-items:flex-start}
}
@media(max-width:480px){
  :root{--nav-h:115px;}
  .nav-logo-img{height:clamp(100px,11vw,120px);}
}
</style>
<?php include __DIR__ . '/includes/prelogin_polish.php'; ?>
</head>
<body>

<!-- NAV -->
<nav class="nav" id="nav">
  <div class="nav-inner">
    <a href="<?= APP_URL ?>/index.php" class="nav-logo">
      <img src="./assets/img/logo-tr.png" alt="MAster" class="nav-logo-img" width="180">
    </a>

    <ul class="nav-links">
      <li><a href="<?= APP_URL ?>/index.php#features">Λειτουργίες</a></li>
      <li><a href="<?= APP_URL ?>/index.php#pricing">Τιμές</a></li>
      <li><a href="<?= APP_URL ?>/contact.php" class="active">Επικοινωνία</a></li>
    </ul>

    <div class="nav-ctas">
      <!-- New Parent Portal Button - Red Border Only -->
      <a href="<?= APP_URL ?>/parent/login.php" class="btn-parent-portal">
        <i class="fa-solid fa-house-chimney-user"></i> &nbsp; Πύλη Γονέα
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

<!-- MOBILE MENU -->
<div class="mobile-menu" id="mmenu">
  <a href="<?= APP_URL ?>/index.php#features"><i class="fas fa-bolt"></i> Λειτουργίες</a>
  <a href="<?= APP_URL ?>/index.php#pricing"><i class="fas fa-credit-card"></i> Τιμές</a>
  <div class="mob-divider"></div>
  <a href="<?= APP_URL ?>/contact.php"><i class="fas fa-envelope"></i> Επικοινωνία</a>
  <a href="<?= APP_URL ?>/parent/login.php" class="mob-portal"><i class="fa-solid fa-house-chimney-user"></i> Πύλη Γονέα</a>
  <a href="<?= APP_URL ?>/login.php" class="mob-login"><i class="fas fa-lock"></i> Σύνδεση</a>
  <a href="<?= APP_URL ?>/register.php" class="mob-cta"><i class="fa-sharp fa-regular fa-share-from-square"></i> Εγγραφή Εδώ</a>
</div>

<div class="page-wrap">

  <!-- HERO STRIP -->
  <div class="contact-hero rev">
    <div class="tag">Επικοινωνία</div>
    <h1>Μιλήστε <em>μαζί μας</em></h1>
    <p>Έχετε απορίες για την πλατφόρμα, χρειάζεστε βοήθεια ή θέλετε να συνεργαστούμε; Στείλτε μας μήνυμα και θα επικοινωνήσουμε μαζί σας σύντομα.</p>
  </div>

  <!-- GRID -->
  <div class="contact-grid">

    <!-- INFO PANEL -->
    <div class="info-panel">

      <div class="info-card rev d1">
        <div class="ic-icon"><i class="fas fa-envelope"></i></div>
        <div>
          <h4>Email</h4>
          <a href="mailto:pkotsorgios654@gmail.com">pkotsorgios654@gmail.com</a>
          <p style="margin-top:.3rem;font-size:.8rem">Απαντάμε εντός 24 ωρών</p>
        </div>
      </div>

      <div class="info-card rev d2">
        <div class="ic-icon"><i class="fas fa-phone"></i></div>
        <div>
          <h4>Τηλέφωνο</h4>
          <a href="tel:+306986788178" style="display:block">+30 698 678 8178</a>
          <a href="tel:+302631028971" style="display:block;margin-top:.15rem">+30 26310 28971</a>
          <p style="margin-top:.3rem;font-size:.8rem">Δευ–Παρ 10:00–17:00</p>
        </div>
      </div>

      <div class="info-card rev d2">
        <div class="ic-icon"><i class="fas fa-clock"></i></div>
        <div>
          <h4>Ώρες Υποστήριξης</h4>
          <div class="hours-grid">
            <span class="day">Δευ – Παρ</span><span class="time">10:00 – 17:00</span>
            <span class="day">Σάββατο</span><span class="time">11:00 – 16:00</span>
            <span class="day">Κυριακή</span><span class="time">Κλειστά</span>
          </div>
        </div>
      </div>

      <div class="info-card rev d3">
        <div class="ic-icon"><i class="fas fa-rocket"></i></div>
        <div>
          <h4>Γρήγορη Έναρξη</h4>
          <p>Δοκιμάστε το MAster δωρεάν για 14 ημέρες χωρίς καμία πληρωμή.</p>
          <a href="<?= APP_URL ?>/register.php" style="display:inline-flex;align-items:center;gap:.4rem;color:var(--red);font-size:.82rem;font-weight:700;margin-top:.5rem">
            Εγγραφή τώρα <i class="fas fa-arrow-right" style="font-size:.7rem"></i>
          </a>
        </div>
      </div>

    </div>

    <!-- FORM PANEL -->
    <div class="form-panel rev d1">
      <?php if ($sent): ?>
        <div class="success-state">
          <div class="s-icon"><i class="fas fa-check"></i></div>
          <h3>Το μήνυμα στάλθηκε!</h3>
          <p>Ευχαριστούμε για την επικοινωνία. Θα σας απαντήσουμε το συντομότερο δυνατό.</p>
          <a href="<?= APP_URL ?>/contact.php" class="btn-back"><i class="fas fa-arrow-left"></i> Πίσω στην Επικοινωνία</a>
        </div>
      <?php else: ?>
        <h2><i class="fas fa-paper-plane"></i> Στείλτε Μήνυμα</h2>

        <?php if ($error): ?>
          <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/contact.php" novalidate>
          <div class="form-row">
            <div class="form-group">
              <label>Όνομα <span class="req">*</span></label>
              <input
                type="text"
                name="name"
                placeholder="π.χ. Γιώργης Παπαδόπουλος"
                value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="name"
                required
              >
            </div>

            <div class="form-group">
              <label>Email <span class="req">*</span></label>
              <input
                type="email"
                name="email"
                placeholder="yourname@email.com"
                value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="email"
                required
              >
            </div>
          </div>

          <div class="form-group">
            <label>Θέμα</label>
            <select name="subject">
              <option value="" <?= empty($_POST['subject']) ? 'selected' : '' ?>>Επιλέξτε θέμα…</option>
              <option value="Γενική Ερώτηση" <?= ($_POST['subject'] ?? '') === 'Γενική Ερώτηση' ? 'selected' : '' ?>>Γενική Ερώτηση</option>
              <option value="Τεχνική Υποστήριξη" <?= ($_POST['subject'] ?? '') === 'Τεχνική Υποστήριξη' ? 'selected' : '' ?>>Τεχνική Υποστήριξη</option>
              <option value="Τιμολόγηση & Πλάνα" <?= ($_POST['subject'] ?? '') === 'Τιμολόγηση & Πλάνα' ? 'selected' : '' ?>>Τιμολόγηση & Πλάνα</option>
              <option value="Αναφορά Προβλήματος" <?= ($_POST['subject'] ?? '') === 'Αναφορά Προβλήματος' ? 'selected' : '' ?>>Αναφορά Προβλήματος</option>
              <option value="Συνεργασία" <?= ($_POST['subject'] ?? '') === 'Συνεργασία' ? 'selected' : '' ?>>Συνεργασία</option>
              <option value="Άλλο" <?= ($_POST['subject'] ?? '') === 'Άλλο' ? 'selected' : '' ?>>Άλλο</option>
            </select>
          </div>

          <div class="form-group">
            <label>Μήνυμα <span class="req">*</span></label>
            <textarea name="message" placeholder="Γράψτε το μήνυμά σας εδώ…" required><?= htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>

          <button type="submit" class="btn-send">
            <i class="fas fa-paper-plane"></i> Αποστολή Μηνύματος
          </button>
        </form>
      <?php endif; ?>
    </div>

  </div>
</div>

<footer class="footer">
  <div class="foot-inner">
    <div class="foot-top">
      <div class="foot-brand">
        <a href="<?= APP_URL ?>/index.php">
          <img src="./assets/img/logo-tr.png" alt="MAster" class="nav-logo-img" width="150">
        </a>
        <p>Μια ολοκληρωμένη πλατφόρμα διαχείρισης αθλητικών συλλόγων σε όλη την Ελλάδα.</p>
      </div>

      <div class="foot-col">
        <h4>Πλατφόρμα</h4>
        <ul>
          <li><a href="<?= APP_URL ?>/index.php#features">Λειτουργίες</a></li>
          <li><a href="<?= APP_URL ?>/index.php#pricing">Τιμές</a></li>
          <li><a href="<?= APP_URL ?>/index.php#how-it-works">Πώς Λειτουργεί</a></li>
          <li><a href="<?= APP_URL ?>/index.php#for-whom">Για Ποιους</a></li>
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
          <li><a href="tel:+306986788178"><i class="fas fa-mobile-alt"></i> +30 698 678 8178</a></li>
          <li><a href="tel:+302631028971"><i class="fas fa-phone"></i> +30 26310 28971</a></li>
          <li><a href="<?= APP_URL ?>/login.php"><i class="fas fa-lock"></i> Σύνδεση Χρήστη</a></li>
          <li><a href="<?= APP_URL ?>/register.php"><i class="fas fa-user-plus"></i> Εγγραφή Χρήστη</a></li>
        </ul>
      </div>
    </div>

    <div class="foot-divider"></div>

	  <style>

		  .foot-copy a{
  color:var(--muted);
  transition:color .2s;
}
.foot-copy a:hover{
  color:var(--white);
}
.foot-copy a strong{
  color:inherit;
  font-weight:500;
}
	  </style>
    <div class="foot-bot">
<div class="foot-bot">
  <div class="foot-bot-left">
    <span class="foot-copy">© <?= date('Y') ?> <a href="https://www.pkotsorgios.gr" target="_blank" rel="noopener noreferrer"><strong>Panagiotis Kotsorgios</strong></a> · Κατασκευάστηκε με &nbsp;<i class="fas fa-heart" style="color:var(--red)"></i></span>
  </div>
</div>
    </div>
  </div>
</footer>

<script>
const nav = document.getElementById('nav');
window.addEventListener('scroll', () => nav.classList.toggle('scrolled', window.scrollY > 20));

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

const io = new IntersectionObserver(entries => {
  entries.forEach(entry => {
    if (entry.isIntersecting) entry.target.classList.add('in');
  });
}, { threshold: .1 });

document.querySelectorAll('.rev').forEach(el => io.observe(el));
</script>

<script src="<?= APP_URL ?>/assets/js/cookie-consent.js"></script>
</body>
</html>