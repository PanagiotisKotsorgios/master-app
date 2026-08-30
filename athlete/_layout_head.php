<?php
$__athleteRow  = currentAthlete();
$__athleteName = trim((string)($__athleteRow['full_name'] ?? ''));
if ($__athleteName === '') $__athleteName = 'Αθλητής';
$__firstName   = explode(' ', $__athleteName)[0];
$__schoolName  = athleteSchoolName();
$__title       = $athletePageTitle ?? 'Portal Αθλητή';
$__active      = $athleteActiveNav ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title><?= h($__title) ?> — Portal Αθλητή — MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
      --bg:#07090f;--card:#111520;--card2:#161c2d;--brd:#1e2536;
      --red:#e63946;--red-d:#c0303b;
      --green:#2dc653;--gold:#f0a500;--blue:#3b82f6;--purple:#8b5cf6;
      --text:#f0f2ff;--muted:#b0bdd6;--muted2:#6b7494;
    }
    html{-webkit-text-size-adjust:100%}
    body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:1rem;line-height:1.6}
    a{color:inherit;text-decoration:none}

    /* Topbar */
    .ap-topbar{background:var(--card);border-bottom:2px solid var(--brd);padding:.85rem 1.5rem;
               display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;gap:1rem}
    .ap-brand{display:flex;align-items:center;gap:.7rem;font-weight:800;font-size:1rem;letter-spacing:.02em}
    .ap-brand img{height:34px;width:auto}
    .ap-brand .role{background:rgba(230,57,70,.15);color:#ff8891;border:1px solid rgba(230,57,70,.3);
                    padding:.15rem .55rem;border-radius:6px;font-size:.62rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
    .ap-user{display:flex;align-items:center;gap:.7rem;font-size:.85rem;color:var(--muted)}
    .ap-user .who{font-weight:700;color:var(--text)}
    .ap-user a.logout{background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.35);color:#ff8891;
                       padding:.42rem .8rem;border-radius:8px;font-weight:700;font-size:.82rem;transition:all .15s}
    .ap-user a.logout:hover{background:rgba(230,57,70,.22);color:#fff}

    /* Layout */
    .ap-wrap{max-width:1200px;margin:0 auto;padding:1.5rem 1.25rem 5rem;display:grid;grid-template-columns:240px 1fr;gap:1.5rem}
    @media(max-width:920px){.ap-wrap{grid-template-columns:1fr;padding:1rem 1rem 4rem}}

    /* Sidebar */
    .ap-side{background:var(--card);border:1px solid var(--brd);border-radius:16px;padding:.6rem;height:fit-content;position:sticky;top:5rem}
    @media(max-width:920px){.ap-side{position:static;display:flex;flex-wrap:wrap;gap:.4rem;padding:.5rem}}
    .ap-side a{display:flex;align-items:center;gap:.7rem;padding:.7rem .9rem;border-radius:10px;
               color:var(--muted);font-weight:600;font-size:.92rem;transition:all .15s}
    @media(max-width:920px){.ap-side a{flex:1 1 calc(50% - .4rem);justify-content:center;padding:.55rem .5rem;font-size:.85rem}}
    .ap-side a i{width:20px;text-align:center;color:var(--muted2)}
    .ap-side a:hover{background:rgba(255,255,255,.04);color:var(--text)}
    .ap-side a.active{background:linear-gradient(135deg,rgba(230,57,70,.18),rgba(230,57,70,.06));color:#fff;border:1px solid rgba(230,57,70,.35)}
    .ap-side a.active i{color:var(--red)}

    /* Main */
    .ap-main{min-width:0}
    .ap-head{margin-bottom:1.25rem}
    .ap-head h1{font-size:clamp(1.35rem,4vw,1.7rem);font-weight:800;margin-bottom:.15rem}
    .ap-head p{color:var(--muted);font-size:.92rem}

    .card{background:var(--card);border:1px solid var(--brd);border-radius:16px;padding:1.35rem 1.35rem;margin-bottom:1.25rem}
    .card h2{font-size:1.02rem;font-weight:800;margin-bottom:.9rem;display:flex;align-items:center;gap:.55rem}
    .card h2 i{color:var(--red)}
    .card h3{font-size:.95rem;font-weight:700;margin:.9rem 0 .5rem}

    .alert{padding:.85rem 1rem;border-radius:10px;font-weight:600;margin-bottom:1rem;display:flex;gap:.55rem;align-items:flex-start;font-size:.92rem}
    .alert i{flex-shrink:0;margin-top:.15rem}
    .alert-ok  {background:rgba(45,198,83,.1);border:1.5px solid rgba(45,198,83,.35);color:#90f0aa}
    .alert-err {background:rgba(230,57,70,.12);border:1.5px solid rgba(230,57,70,.4);color:#ffb3b8}
    .alert-info{background:rgba(59,130,246,.1);border:1.5px solid rgba(59,130,246,.3);color:#93c5fd}

    .grid-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.9rem;margin-bottom:1.25rem}
    .stat{background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:1rem 1.1rem}
    .stat .lbl{font-size:.72rem;font-weight:700;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;margin-bottom:.35rem}
    .stat .val{font-family:'Bebas Neue',sans-serif;font-size:1.9rem;line-height:1}
    .stat.g .val{color:var(--green)} .stat.r .val{color:var(--red)} .stat.b .val{color:var(--blue)} .stat.o .val{color:var(--gold)}

    .kv{display:grid;grid-template-columns:180px 1fr;gap:.5rem 1rem;font-size:.9rem}
    @media(max-width:600px){.kv{grid-template-columns:1fr}}
    .kv .k{color:var(--muted);font-weight:600}
    .kv .v{color:var(--text);font-weight:700}

    .btn{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.1rem;border-radius:10px;font-weight:700;font-size:.92rem;
         border:none;cursor:pointer;font-family:inherit;transition:all .15s;text-decoration:none}
    .btn-primary{background:var(--red);color:#fff;box-shadow:0 4px 18px rgba(230,57,70,.35)}
    .btn-primary:hover{background:var(--red-d);transform:translateY(-1px)}
    .btn-ghost{background:rgba(255,255,255,.06);color:var(--text);border:1px solid var(--brd)}
    .btn-ghost:hover{background:rgba(255,255,255,.1)}

    input[type="text"],input[type="email"],input[type="password"],input[type="date"],
    select,textarea{
      width:100%;padding:.75rem .9rem;background:var(--card2);border:1.5px solid var(--brd);
      border-radius:10px;color:var(--text);font-size:.95rem;font-family:inherit;transition:border-color .15s
    }
    input:focus,select:focus,textarea:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(230,57,70,.15)}
    label{display:block;font-weight:700;font-size:.86rem;margin-bottom:.35rem;color:var(--text)}
    .form-row{margin-bottom:.9rem}

    table{width:100%;border-collapse:collapse;font-size:.9rem}
    th{text-align:left;padding:.65rem .75rem;color:var(--muted);font-weight:700;font-size:.75rem;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid var(--brd)}
    td{padding:.75rem .75rem;border-bottom:1px solid rgba(255,255,255,.04)}
    tr:last-child td{border-bottom:none}
    .pill{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:50px;font-size:.72rem;font-weight:700}
    .pill.ok{background:rgba(45,198,83,.15);color:#8fe6a1}
    .pill.warn{background:rgba(240,165,0,.15);color:#fcd34d}
    .pill.err{background:rgba(230,57,70,.15);color:#ff8891}
    .pill.muted{background:rgba(255,255,255,.06);color:var(--muted)}
  </style>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/portal-navigation.css?v=<?= @filemtime(__DIR__ . '/../assets/css/portal-navigation.css') ?: time() ?>">
</head>
<body>

<div class="ap-topbar">
  <a href="<?= APP_URL ?>/athlete/index.php" class="ap-brand">
    <img src="<?= APP_URL ?>/assets/img/logo-tr.png" alt="MAster">
    <span><?= h($__schoolName) ?></span>
    <span class="role">Αθλητής</span>
  </a>
  <div class="ap-user">
    <span class="who"><?= h($__firstName) ?></span>
    <a href="<?= APP_URL ?>/athlete/logout.php" class="logout"><i class="fas fa-right-from-bracket"></i> Έξοδος</a>
  </div>
</div>

<div class="ap-wrap">

  <nav class="ap-side">
    <a href="<?= APP_URL ?>/athlete/index.php"         class="<?= $__active==='dashboard'?'active':'' ?>"><i class="fas fa-house"></i> Αρχική</a>
    <a href="<?= APP_URL ?>/athlete/documents.php"     class="<?= $__active==='documents'?'active':'' ?>"><i class="fas fa-folder-open"></i> Έγγραφά μου</a>
    <a href="<?= APP_URL ?>/athlete/subscriptions.php" class="<?= $__active==='subscriptions'?'active':'' ?>"><i class="fas fa-euro-sign"></i> Συνδρομές</a>
    <a href="<?= APP_URL ?>/athlete/events.php"        class="<?= $__active==='events'?'active':'' ?>"><i class="fas fa-calendar-days"></i> Διοργανώσεις</a>
    <a href="<?= APP_URL ?>/athlete/settings.php"      class="<?= $__active==='settings'?'active':'' ?>"><i class="fas fa-gear"></i> Ρυθμίσεις</a>
  </nav>

  <main class="ap-main">
