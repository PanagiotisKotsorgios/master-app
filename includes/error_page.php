<?php

function renderErrorPage(int $code, string $title, string $message, string $hint = ''): void
{
    http_response_code($code);

    $appUrl     = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $logoUrl    = $appUrl . '/assets/img/logo-tr.png';
    $faviconUrl = $appUrl . '/assets/img/favicon.png';
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $code ?> — <?= htmlspecialchars($title) ?> · MAster</title>
<meta name="robots" content="noindex,follow">
<link rel="shortcut icon" href="<?= htmlspecialchars($faviconUrl) ?>" type="image/png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --bg:#060810;
  --bg2:#0d1017;
  --bg3:#131722;
  --red:#e63946;
  --red2:#d62f3d;
  --white:#f0f2ff;
  --muted:#6b7494;
  --muted2:#3d4362;
  --border:rgba(255,255,255,.06);
  --border2:rgba(255,255,255,.1);
}

html,body{min-height:100%}

body{
  font-family:'DM Sans',sans-serif;
  background:var(--bg);
  color:var(--white);
  display:flex;
  align-items:center;
  justify-content:center;
  min-height:100vh;
  padding:2rem 1rem;
  position:relative;
  overflow:hidden;
  line-height:1.6;
}

a{
  text-decoration:none;
  color:inherit;
}

/* background noise */
body::after{
  content:'';
  position:fixed;
  inset:0;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  opacity:.02;
  pointer-events:none;
  z-index:0;
}

/* decorative glow */
.glow{
  position:fixed;
  width:700px;
  height:700px;
  top:-20%;
  left:-20%;
  background:radial-gradient(circle,rgba(230,57,70,.1),transparent 70%);
  pointer-events:none;
  z-index:0;
}

/* card */
.error-card{
  position:relative;
  z-index:2;
  max-width:720px;
  width:100%;
  background:linear-gradient(180deg,rgba(19,23,34,.96),rgba(13,16,23,.96));
  border:1px solid var(--border);
  border-radius:28px;
  padding:3rem 2rem;
  text-align:center;
  box-shadow:0 30px 80px rgba(0,0,0,.65);
}

/* logo */
.logo{
  margin-bottom:1.5rem;
}
.logo img{
  height:90px;
  width:auto;
  object-fit:contain;
  display:inline-block;
}

/* typography */
.error-code{
  font-family:'Bebas Neue',sans-serif;
  font-size:clamp(4.8rem,12vw,6.5rem);
  line-height:.95;
  color:var(--red);
  margin-bottom:.35rem;
}

.error-title{
  font-family:'Syne',sans-serif;
  font-size:clamp(1.6rem,4vw,2.25rem);
  font-weight:800;
  margin-bottom:1rem;
}

.error-message{
  color:var(--muted);
  font-size:1.02rem;
  margin-bottom:1rem;
}

.error-hint{
  color:var(--muted2);
  font-size:.96rem;
  margin-bottom:2rem;
}

/* buttons wrapper */
.actions{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:.8rem;
  flex-wrap:wrap;
}

/* base button */
.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:.55rem;
  padding:.95rem 1.65rem;
  border-radius:12px;
  font-size:.96rem;
  font-weight:700;
  line-height:1;
  cursor:pointer;
  user-select:none;
  -webkit-tap-highlight-color:transparent;
  transition:
    transform .2s ease,
    box-shadow .25s ease,
    background .25s ease,
    border-color .25s ease,
    color .25s ease;
  position:relative;
  z-index:3;
}

.btn i{
  font-size:.9rem;
  transition:transform .2s ease, opacity .2s ease;
}

/* primary */
.btn-primary{
  background:linear-gradient(135deg,var(--red),var(--red2));
  color:#fff;
  border:1px solid transparent;
  box-shadow:0 0 25px rgba(230,57,70,.35);
}
.btn-primary:hover{
  transform:translateY(-2px);
  box-shadow:0 0 40px rgba(230,57,70,.5);
}
.btn-primary:active{
  transform:translateY(0);
}
.btn-primary:hover i{
  transform:translateX(1px);
}

/* secondary */
.btn-secondary{
  background:rgba(255,255,255,.05);
  color:var(--white);
  border:1px solid var(--border2);
  box-shadow:0 8px 24px rgba(0,0,0,.18);
}
.btn-secondary:hover{
  background:rgba(255,255,255,.09);
  border-color:rgba(255,255,255,.18);
  transform:translateY(-2px);
  box-shadow:
    0 10px 28px rgba(0,0,0,.28),
    0 0 0 1px rgba(255,255,255,.03) inset;
}
.btn-secondary:active{
  transform:translateY(0);
  box-shadow:0 5px 14px rgba(0,0,0,.2);
}
.btn-secondary:hover i{
  transform:translateX(-2px);
  opacity:1;
}

/* focus */
.btn:focus-visible{
  outline:2px solid rgba(230,57,70,.55);
  outline-offset:3px;
}

/* actual button element reset */
button.btn{
  appearance:none;
  -webkit-appearance:none;
  border:1px solid var(--border2);
  font-family:inherit;
}

/* footer */
.footer{
  margin-top:2rem;
  font-size:.92rem;
  color:var(--muted2);
}
.footer strong{
  color:var(--muted);
  font-weight:600;
}

@media(max-width:640px){
  .error-card{
    padding:2.2rem 1.2rem;
  }

  .actions{
    flex-direction:column;
  }

  .btn{
    width:100%;
  }

  .logo img{
    height:78px;
  }
}
</style>
</head>
<body>

<div class="glow" aria-hidden="true"></div>

<div class="error-card">
  <div class="logo">
    <a href="<?= htmlspecialchars($appUrl ?: '/') ?>">
      <img src="<?= htmlspecialchars($logoUrl) ?>" alt="MAster">
    </a>
  </div>

  <div class="error-code"><?= $code ?></div>

  <h1 class="error-title"><?= htmlspecialchars($title) ?></h1>

  <p class="error-message"><?= htmlspecialchars($message) ?></p>

  <?php if ($hint !== ''): ?>
    <p class="error-hint"><?= htmlspecialchars($hint) ?></p>
  <?php endif; ?>

  <div class="actions">
    <?php if (in_array($code, [401, 403], true)): ?>
      <a href="<?= htmlspecialchars($appUrl . '/login.php') ?>" class="btn btn-primary">
        <i class="fa-solid fa-right-to-bracket"></i>
        <span>Σύνδεση</span>
      </a>
    <?php else: ?>
      <a href="<?= htmlspecialchars($appUrl . '/') ?>" class="btn btn-primary">
        <i class="fa-solid fa-house"></i>
        <span>Αρχική Σελίδα</span>
      </a>
    <?php endif; ?>

    <button type="button" onclick="history.back()" class="btn btn-secondary">
      <i class="fa-solid fa-arrow-left"></i>
      <span>Προηγούμενη Σελίδα</span>
    </button>
  </div>

  <div class="footer">
    © <?= date('Y') ?> <strong>MAster</strong>
  </div>
</div>

</body>
</html>
<?php
exit;
}