<?php
/**
 * events/view.php — Public event page
 * ============================================================
 * URL: /events/view.php?slug=…
 * Anyone can view (respects visibility).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

$slug = trim($_GET['slug'] ?? '');
$ev   = $slug ? eventGetBySlug($slug) : null;

if (!$ev) {
    http_response_code(404);
    echo '<h1>404 · Event not found</h1>';
    exit;
}

// Only public + unlisted events reachable via URL
if ($ev['visibility'] === 'invite_only') {
    // logged-in organiser can preview
    $sid = isset($_SESSION['school_id']) ? (int)$_SESSION['school_id'] : 0;
    if ((int)$ev['organiser_school_id'] !== $sid) {
        http_response_code(403);
        echo '<h1>403 · Ιδιωτικό event</h1>';
        exit;
    }
}

$categories   = eventCategories((int)$ev['id']);
$participants = eventPublicParticipants((int)$ev['id']);

// Organiser school name
$orgStmt = getDB()->prepare("SELECT name FROM schools WHERE id = ?");
$orgStmt->execute([$ev['organiser_school_id']]);
$organiser = $orgStmt->fetchColumn() ?: '—';

// Group participants by category for nicer display
$byCat = [];
foreach ($participants as $p) {
    $byCat[$p['cat_name'] ?? '—'][] = $p;
}

$metaTitle = $ev['title'] . ' — MAster';
$metaDesc  = mb_substr($ev['subtitle'] ?: strip_tags($ev['description'] ?? ''), 0, 200);
$canonical = eventPublicUrl($ev);
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($metaTitle) ?></title>
<meta name="description" content="<?= h($metaDesc) ?>">
<link rel="canonical" href="<?= h($canonical) ?>">
<meta property="og:type" content="event">
<meta property="og:title" content="<?= h($ev['title']) ?>">
<meta property="og:description" content="<?= h($metaDesc) ?>">
<meta property="og:url" content="<?= h($canonical) ?>">
<?php if (!empty($ev['banner_path'])):
  $ogImg = rtrim(APP_URL, '/') . '/uploads/' . ltrim($ev['banner_path'], '/');
?>
<meta property="og:image" content="<?= h($ogImg) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= h($ogImg) ?>">
<?php endif; ?>
<script type="application/ld+json">
<?= json_encode([
  '@context' => 'https://schema.org',
  '@type' => 'SportsEvent',
  'name' => $ev['title'],
  'description' => $ev['subtitle'] ?: mb_substr(strip_tags($ev['description'] ?? ''), 0, 500),
  'startDate' => $ev['starts_at'],
  'endDate' => $ev['ends_at'],
  'eventStatus' => 'https://schema.org/EventScheduled',
  'location' => $ev['venue_name'] ? [
      '@type' => 'Place',
      'name' => $ev['venue_name'],
      'address' => $ev['venue_address'] ?: '',
  ] : null,
  'organizer' => ['@type' => 'Organization', 'name' => $organiser],
  'url' => $canonical,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;line-height:1.55}
  .top{position:sticky;top:0;background:rgba(7,9,15,.9);backdrop-filter:blur(10px);border-bottom:1px solid #1e2536;padding:1rem 1.25rem;z-index:10}
  .top-inner{max-width:1000px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
  .brand{font-size:1.15rem;font-weight:800;color:#f0f2ff;text-decoration:none}
  .brand em{color:#e63946;font-style:normal}
  .btn{padding:.55rem 1rem;border-radius:8px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-flex;align-items:center;gap:.4rem;border:none;cursor:pointer;font-family:inherit}
  .btn-primary{background:#e63946;color:#fff}
  .btn-ghost{background:transparent;border:1px solid #2a3248;color:#f0f2ff}
  .wrap{max-width:1000px;margin:0 auto;padding:2rem 1.25rem}
  .hero-banner{position:relative;border-radius:18px;overflow:hidden;margin-bottom:1.25rem;
        border:1px solid #1e2536;aspect-ratio:21/9;background:#0d1017;
        box-shadow:0 14px 40px -18px rgba(0,0,0,.6)}
  .hero-banner img{width:100%;height:100%;object-fit:cover;display:block}
  .hero-banner::after{content:"";position:absolute;inset:0;
        background:linear-gradient(180deg,transparent 40%,rgba(7,9,15,.85) 100%)}
  .hero-banner .hero-caption{position:absolute;left:0;right:0;bottom:0;padding:1.5rem 1.75rem;z-index:2}
  .hero-banner .hero-type{display:inline-block;font-size:.68rem;text-transform:uppercase;letter-spacing:.12em;
        color:#fff;font-weight:800;background:#e63946;padding:.32rem .75rem;border-radius:6px;margin-bottom:.6rem}
  .hero-banner h1{color:#fff;text-shadow:0 2px 12px rgba(0,0,0,.5);font-size:clamp(1.5rem,4vw,2.4rem)}
  @media(max-width:640px){.hero-banner{aspect-ratio:4/3}.hero-banner .hero-caption{padding:1rem 1.15rem}}
  .header{background:linear-gradient(135deg,#111520,#0d1017);border:1px solid #1e2536;border-radius:16px;padding:2rem 1.75rem;margin-bottom:1.5rem}
  .type-badge{display:inline-block;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#e63946;font-weight:800;background:rgba(230,57,70,.1);padding:.35rem .85rem;border-radius:20px;margin-bottom:.9rem}
  h1{font-size:2rem;margin-bottom:.5rem;line-height:1.2}
  .subtitle{color:#8892b0;font-size:1.1rem;margin-bottom:1.2rem}
  .meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.9rem;margin-top:1.5rem}
  .meta-item{background:rgba(255,255,255,.03);border:1px solid #1e2536;border-radius:10px;padding:.85rem 1rem}
  .meta-label{font-size:.72rem;text-transform:uppercase;color:#6b7494;letter-spacing:.08em;font-weight:700}
  .meta-value{color:#f0f2ff;margin-top:.3rem;font-weight:600}
  .section{background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.5rem 1.75rem;margin-bottom:1.25rem}
  .section h2{font-size:1.15rem;color:#e63946;margin-bottom:1rem;text-transform:uppercase;letter-spacing:.08em;font-size:.85rem;font-weight:800}
  .cat-item{padding:.85rem 1rem;background:#0d1017;border:1px solid #1e2536;border-radius:10px;margin-bottom:.5rem}
  .cat-item h4{color:#f0f2ff;font-size:1rem;margin-bottom:.3rem}
  .cat-meta{color:#8892b0;font-size:.85rem}
  .par-group{margin-bottom:1.25rem}
  .par-group h3{color:#e63946;font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.5rem}
  .par-list{background:#0d1017;border:1px solid #1e2536;border-radius:10px;padding:.5rem}
  .par-row{padding:.55rem .8rem;border-bottom:1px solid #1e2536;display:flex;justify-content:space-between;color:#c8cfe0;font-size:.9rem;gap:.75rem;align-items:center;transition:background .12s;flex-wrap:wrap}
  .par-row:last-child{border-bottom:none}
  .par-row:hover{background:rgba(230,57,70,.04)}
  .par-row .club{color:#8892b0;font-size:.82rem;white-space:nowrap;display:inline-flex;align-items:center}
  .par-row .par-name{display:flex;align-items:center;gap:.6rem;min-width:0;flex:1}
  .par-row .par-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#e63946,#c72832);color:#fff;font-weight:800;font-size:.78rem;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
  .par-row .par-txt{color:#f0f2ff;font-weight:600;overflow-wrap:anywhere}
  .par-row.hit{background:rgba(240,165,0,.08);border-left:3px solid #f0a500;padding-left:calc(.8rem - 3px)}

  /* Cats grid — polished cards */
  .cats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.75rem}
  .cat-card{background:#0d1017;border:1px solid #1e2536;border-radius:12px;padding:.9rem 1rem;transition:border-color .15s,transform .15s}
  .cat-card:hover{border-color:rgba(230,57,70,.35);transform:translateY(-1px)}
  .cat-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem;margin-bottom:.55rem}
  .cat-card-head h4{color:#f0f2ff;font-size:.98rem;line-height:1.3;font-weight:800;margin:0;overflow-wrap:anywhere}
  .cat-card-pill{display:inline-flex;align-items:center;gap:.3rem;background:rgba(230,57,70,.14);color:#ff8891;padding:.22rem .55rem;border-radius:50px;font-size:.75rem;font-weight:800;white-space:nowrap;flex-shrink:0}
  .cat-card-meta{display:flex;gap:.35rem;flex-wrap:wrap}
  .cat-chip{display:inline-flex;align-items:center;gap:.3rem;background:rgba(255,255,255,.05);color:#c8cfe0;padding:.22rem .55rem;border-radius:6px;font-size:.75rem;font-weight:600;line-height:1.3}
  .cat-chip i{color:#8892b0;font-size:.7rem}
  .cat-chip-fee{background:rgba(240,165,0,.1);color:#f0a500}
  .cat-chip-fee i{color:#f0a500}

  /* Participants search bar */
  .par-search-wrap{display:flex;gap:.5rem;margin-bottom:.85rem;flex-wrap:wrap;align-items:center}
  .par-search-input{position:relative;flex:1;min-width:220px}
  .par-search-input i{position:absolute;left:.9rem;top:50%;transform:translateY(-50%);color:#6b7494;pointer-events:none;font-size:.9rem}
  .par-search-input input{width:100%;padding:.85rem 1rem .85rem 2.4rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:12px;color:#f0f2ff;font-family:inherit;font-size:1rem;min-height:48px;transition:border-color .15s,box-shadow .15s}
  .par-search-input input:focus{outline:none;border-color:#e63946;box-shadow:0 0 0 3px rgba(230,57,70,.15)}
  #parClearBtn{background:rgba(255,255,255,.06);color:#c8cfe0;border:1px solid #2a3248;padding:.75rem 1rem;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;min-height:48px;display:inline-flex;align-items:center;gap:.4rem}
  #parClearBtn:hover{background:rgba(255,255,255,.1);color:#fff}
  .par-chips{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;padding-bottom:.6rem;border-bottom:1px solid rgba(255,255,255,.05)}
  .par-chip{background:rgba(255,255,255,.04);color:#c8cfe0;border:1px solid #1e2536;padding:.4rem .8rem;border-radius:50px;font-size:.82rem;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:.35rem;transition:all .15s}
  .par-chip:hover{background:rgba(230,57,70,.1);color:#fff;border-color:rgba(230,57,70,.3)}
  .par-chip.active{background:linear-gradient(135deg,#e63946,#c72832);color:#fff;border-color:rgba(255,255,255,.15);box-shadow:0 4px 12px -4px rgba(230,57,70,.5)}
  .par-chip .ct{background:rgba(255,255,255,.15);padding:.05rem .45rem;border-radius:50px;font-size:.7rem;font-weight:800}
  .par-chip.active .ct{background:rgba(0,0,0,.25)}
  .par-group{margin-bottom:1.25rem}
  .par-group.empty{display:none}
  .par-group h3{color:#e63946;font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.5rem;font-weight:800}
  .par-list{background:#0d1017;border:1px solid #1e2536;border-radius:12px;padding:.35rem}

  .cta-row{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1.25rem}
  @media(max-width:640px){
    h1{font-size:1.5rem}
    .header{padding:1.5rem 1.25rem}
    .section{padding:1.15rem 1.25rem}
    .cats-grid{grid-template-columns:1fr}
    .par-row{align-items:flex-start;flex-direction:column;gap:.3rem}
    .par-row .club{padding-left:2.15rem}
  }
</style>
<?php include __DIR__ . "/../includes/prelogin_polish.php"; ?>
</head>
<body>

<div class="top">
  <div class="top-inner">
    <a href="<?= APP_URL ?>/" class="brand">MA<em>ster</em></a>
    <div>
      <a href="<?= APP_URL ?>/events/" class="btn btn-ghost"><i class="fa-solid fa-list"></i> Όλα τα events</a>
      <a href="<?= APP_URL ?>/login.php" class="btn btn-primary" style="margin-left:.35rem">Σύνδεση</a>
    </div>
  </div>
</div>

<div class="wrap">

  <?php if (!empty($ev['banner_path'])):
    $viewBanner = rtrim(APP_URL, '/') . '/uploads/' . ltrim($ev['banner_path'], '/');
  ?>
    <div class="hero-banner">
      <img src="<?= h($viewBanner) ?>" alt="<?= h($ev['title']) ?>">
      <div class="hero-caption">
        <span class="hero-type"><?= h(eventTypeLabel($ev['type'])) ?></span>
        <h1><?= h($ev['title']) ?></h1>
      </div>
    </div>
  <?php endif; ?>

  <div class="header">
    <?php if (empty($ev['banner_path'])): ?><span class="type-badge"><?= h(eventTypeLabel($ev['type'])) ?></span><?php endif; ?>
    <?php if (empty($ev['banner_path'])): ?><h1><?= h($ev['title']) ?></h1><?php endif; ?>
    <?php if ($ev['subtitle']): ?><p class="subtitle"><?= h($ev['subtitle']) ?></p><?php endif; ?>

    <div style="color:#c8cfe0;font-size:.95rem;line-height:1.75">
      Διοργάνωση: <strong style="color:#f0f2ff"><?= h($organiser) ?></strong>
      <?php if ($ev['sport']): ?> · <?= h($ev['sport']) ?><?php endif; ?>
      <?php if ($ev['sport_style']): ?> / <?= h($ev['sport_style']) ?><?php endif; ?>
    </div>

    <div class="meta-grid">
      <?php if ($ev['starts_at']): ?>
        <div class="meta-item">
          <div class="meta-label">Ημερομηνία</div>
          <div class="meta-value"><?= h(formatDate(substr($ev['starts_at'],0,10))) ?><?php if ($ev['ends_at'] && substr($ev['ends_at'],0,10) !== substr($ev['starts_at'],0,10)): ?> → <?= h(formatDate(substr($ev['ends_at'],0,10))) ?><?php endif; ?></div>
        </div>
      <?php endif; ?>
      <?php if ($ev['venue_name']): ?>
        <div class="meta-item">
          <div class="meta-label">Τοποθεσία</div>
          <div class="meta-value"><?= h($ev['venue_name']) ?></div>
          <?php if ($ev['venue_address']): ?><div style="color:#6b7494;font-size:.82rem;margin-top:.2rem"><?= h($ev['venue_address']) ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="meta-item">
        <div class="meta-label">Συμμετοχή</div>
        <div class="meta-value"><?= $ev['fee_model']==='free' ? 'Δωρεάν' : number_format((float)$ev['fee_amount'],2,',','.').'€' ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Κατάσταση</div>
        <div class="meta-value"><?= h(eventStatusLabel($ev['status'])) ?></div>
      </div>
      <?php if ($ev['registration_closes_at']): ?>
        <div class="meta-item">
          <div class="meta-label">Λήξη εγγραφών</div>
          <div class="meta-value"><?= h(formatDate(substr($ev['registration_closes_at'],0,10))) ?></div>
        </div>
      <?php endif; ?>
      <?php if ($ev['max_participants']): ?>
        <div class="meta-item">
          <div class="meta-label">Μέγιστοι συμμετέχοντες</div>
          <div class="meta-value"><?= (int)$ev['max_participants'] ?></div>
        </div>
      <?php endif; ?>
    </div>

    <div class="cta-row">
      <?php if ($ev['status'] === 'open'): ?>
        <a href="<?= APP_URL ?>/pages/event_participate.php?id=<?= (int)$ev['id'] ?>" class="btn btn-primary">
          <i class="fa-solid fa-user-plus"></i> Δήλωση συμμετοχής (απαιτείται σύνδεση)
        </a>
      <?php endif; ?>
      <?php if ($ev['venue_url']): ?>
        <a href="<?= h($ev['venue_url']) ?>" target="_blank" rel="noopener" class="btn btn-ghost">
          <i class="fa-solid fa-map"></i> Χάρτης
        </a>
      <?php endif; ?>

      <?php
        // Save-to-calendar helpers -----------------
        $icsUrl = APP_URL . '/events/ics.php?slug=' . urlencode($ev['slug']);
        $gcalStart = $ev['starts_at'] ? gmdate('Ymd\THis\Z', strtotime($ev['starts_at'])) : gmdate('Ymd\THis\Z');
        $gcalEnd   = $ev['ends_at']   ? gmdate('Ymd\THis\Z', strtotime($ev['ends_at']))
                                       : ($ev['starts_at'] ? gmdate('Ymd\THis\Z', strtotime($ev['starts_at']) + 3*3600) : gmdate('Ymd\THis\Z'));
        $gcalUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE&'
                 . http_build_query([
                     'text'     => $ev['title'],
                     'dates'    => $gcalStart . '/' . $gcalEnd,
                     'details'  => trim(($ev['subtitle'] ?? '') . "\n\n" . strip_tags($ev['description'] ?? '')) . "\n\n" . eventPublicUrl($ev),
                     'location' => trim(($ev['venue_name'] ?? '') . ' ' . ($ev['venue_address'] ?? '')),
                   ]);
      ?>
      <div style="position:relative;display:inline-block">
        <button type="button" onclick="var m=this.nextElementSibling;m.style.display=m.style.display==='block'?'none':'block'" class="btn btn-ghost">
          <i class="fa-regular fa-calendar-plus"></i> Save to calendar
        </button>
        <div style="display:none;position:absolute;top:100%;left:0;margin-top:.35rem;background:#111520;border:1px solid #2a3248;border-radius:10px;padding:.35rem;min-width:200px;box-shadow:0 12px 32px rgba(0,0,0,.5);z-index:20">
          <a href="<?= h($gcalUrl) ?>" target="_blank" rel="noopener" style="display:block;padding:.55rem .75rem;color:#f0f2ff;text-decoration:none;border-radius:6px;font-size:.9rem" onmouseover="this.style.background='rgba(230,57,70,.1)'" onmouseout="this.style.background='transparent'">
            <i class="fa-brands fa-google" style="color:#e63946;margin-right:.4rem;width:14px"></i> Google Calendar
          </a>
          <a href="<?= h($icsUrl) ?>" style="display:block;padding:.55rem .75rem;color:#f0f2ff;text-decoration:none;border-radius:6px;font-size:.9rem" onmouseover="this.style.background='rgba(230,57,70,.1)'" onmouseout="this.style.background='transparent'">
            <i class="fa-brands fa-apple" style="color:#e63946;margin-right:.4rem;width:14px"></i> Apple / Outlook (.ics)
          </a>
        </div>
      </div>

      <a href="<?= APP_URL ?>/events/report.php?slug=<?= h($ev['slug']) ?>" class="btn btn-ghost" style="color:#8892b0">
        <i class="fa-regular fa-flag"></i> Αναφορά
      </a>
    </div>

    <!-- Follow form -->
    <form method="POST" action="<?= APP_URL ?>/events/follow.php" style="margin-top:1.25rem;display:flex;gap:.5rem;flex-wrap:wrap;background:rgba(255,255,255,.04);border:1px solid #1e2536;border-radius:10px;padding:.85rem 1rem">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="slug" value="<?= h($ev['slug']) ?>">
      <input type="hidden" name="channel" value="email">
      <input type="email" name="email" placeholder="Το email σας για ενημερώσεις" style="flex:1;min-width:200px;padding:.55rem .75rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff;font-family:inherit">
      <button class="btn btn-primary" style="padding:.55rem 1.2rem"><i class="fa-regular fa-bell"></i> Παρακολούθηση</button>
    </form>
  </div>

  <?php if ($ev['description']): ?>
    <div class="section">
      <h2>Περιγραφή</h2>
      <div style="color:#c8cfe0;line-height:1.7;white-space:pre-wrap"><?= h($ev['description']) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($categories): ?>
    <div class="section">
      <h2>Κατηγορίες <span style="color:#8892b0;font-weight:400;font-size:.85rem;letter-spacing:0;text-transform:none;margin-left:.5rem">(<?= count($categories) ?>)</span></h2>
      <div class="cats-grid">
        <?php foreach ($categories as $c):
          $catCount = isset($byCat[$c['name']]) ? count($byCat[$c['name']]) : 0;
        ?>
          <div class="cat-card">
            <div class="cat-card-head">
              <h4><?= h($c['name']) ?></h4>
              <span class="cat-card-pill"><i class="fa-solid fa-users"></i> <?= $catCount ?></span>
            </div>
            <div class="cat-card-meta">
              <?php if ($c['gender']!=='MX'): ?>
                <span class="cat-chip"><i class="fa-solid <?= $c['gender']==='F'?'fa-venus':'fa-mars' ?>"></i> <?= ['M'=>'Άνδρες','F'=>'Γυναίκες'][$c['gender']] ?></span>
              <?php endif; ?>
              <?php if ($c['min_age'] || $c['max_age']): ?>
                <span class="cat-chip"><i class="fa-solid fa-cake-candles"></i> <?= ($c['min_age']??'—') ?>–<?= ($c['max_age']??'—') ?> ετών</span>
              <?php endif; ?>
              <?php if ($c['min_weight'] || $c['max_weight']): ?>
                <span class="cat-chip"><i class="fa-solid fa-weight-scale"></i> <?= ($c['min_weight']??'—') ?>–<?= ($c['max_weight']??'—') ?> kg</span>
              <?php endif; ?>
              <span class="cat-chip"><i class="fa-solid fa-diagram-project"></i> <?= h(eventFormatLabel($c['format'] ?? '')) ?></span>
              <?php if ($c['fee_override']!==null): ?>
                <span class="cat-chip cat-chip-fee"><i class="fa-solid fa-euro-sign"></i> <?= number_format((float)$c['fee_override'],2,',','.') ?>€</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($participants):
    // Collect distinct clubs for chips
    $clubSet = [];
    foreach ($participants as $p) if (!empty($p['school_name'])) $clubSet[$p['school_name']] = ($clubSet[$p['school_name']] ?? 0) + 1;
    arsort($clubSet);
  ?>
    <div class="section" id="participantsSection">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
        <h2 style="margin:0">Συμμετέχοντες αθλητές
          <span style="color:#8892b0;font-weight:400;font-size:.85rem;letter-spacing:0;text-transform:none;margin-left:.5rem">
            (<span id="parVisibleCount"><?= count($participants) ?></span> / <?= count($participants) ?>)
          </span>
        </h2>
        <div style="color:#6b7494;font-size:.78rem">
          <i class="fa-solid fa-shield-halved" style="color:#8fe6a1"></i> Τα ονόματα εμφανίζονται ανώνυμα για λόγους απορρήτου
        </div>
      </div>

      <!-- Live search + club filter -->
      <div class="par-search-wrap">
        <div class="par-search-input">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="search" id="parSearch" placeholder="Αναζήτηση αθλητή, συλλόγου ή κατηγορίας…"
                 autocomplete="off" inputmode="search" enterkeyhint="search"
                 oninput="__parFilter()">
        </div>
        <button type="button" id="parClearBtn" onclick="__parClear()" style="display:none">
          <i class="fa-solid fa-xmark"></i> Καθαρισμός
        </button>
      </div>

      <?php if (count($clubSet) > 1): ?>
      <div class="par-chips" role="tablist" aria-label="Φίλτρο συλλόγου">
        <button type="button" class="par-chip active" data-club="" onclick="__parClub(this,'')">
          Όλοι <span class="ct"><?= count($participants) ?></span>
        </button>
        <?php foreach ($clubSet as $clubName => $clubCount): ?>
          <button type="button" class="par-chip" data-club="<?= h(mb_strtolower($clubName, 'UTF-8')) ?>" onclick="__parClub(this,'<?= h(mb_strtolower(addslashes($clubName), 'UTF-8')) ?>')">
            <?= h($clubName) ?> <span class="ct"><?= (int)$clubCount ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div id="parAllGroups">
        <?php foreach ($byCat as $catName => $rows): ?>
          <div class="par-group" data-cat="<?= h(mb_strtolower($catName, 'UTF-8')) ?>">
            <h3><?= h($catName) ?> <span style="color:#8892b0;font-weight:600;font-size:.72rem;margin-left:.35rem">(<span class="par-group-count"><?= count($rows) ?></span>)</span></h3>
            <div class="par-list">
              <?php foreach ($rows as $p): ?>
                <div class="par-row"
                     data-name="<?= h(mb_strtolower($p['full_name'] ?? '', 'UTF-8')) ?>"
                     data-club="<?= h(mb_strtolower($p['school_name'] ?? '', 'UTF-8')) ?>"
                     data-cat="<?= h(mb_strtolower($catName, 'UTF-8')) ?>">
                  <div class="par-name">
                    <span class="par-avatar"><?= h(mb_strtoupper(mb_substr($p['full_name'] ?? '?', 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                    <span class="par-txt"><?= h($p['full_name'] ?? '—') ?></span>
                  </div>
                  <span class="club"><i class="fa-solid fa-building" style="color:#e63946;font-size:.68rem;margin-right:.2rem"></i><?= h($p['school_name'] ?? '—') ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div id="parEmpty" style="display:none;padding:2rem 1rem;text-align:center;color:#8892b0;background:#0d1017;border:1px dashed #2a3248;border-radius:12px">
        <i class="fa-solid fa-magnifying-glass" style="font-size:1.6rem;display:block;margin-bottom:.5rem;opacity:.5"></i>
        Δεν βρέθηκε αθλητής με αυτά τα κριτήρια.
      </div>
    </div>
  <?php endif; ?>

  <?php if ($ev['contact_email'] || $ev['contact_phone']): ?>
    <div class="section">
      <h2>Επικοινωνία διοργάνωσης</h2>
      <div style="color:#c8cfe0;line-height:1.8">
        <?php if ($ev['contact_email']): ?><div><i class="fa-solid fa-envelope"></i> <?= h($ev['contact_email']) ?></div><?php endif; ?>
        <?php if ($ev['contact_phone']): ?><div><i class="fa-solid fa-phone"></i> <?= h($ev['contact_phone']) ?></div><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Powered-by badge: subtle viral loop → every public event page invites new organisers -->
  <div style="margin:2.5rem 0 1.5rem;padding:1.5rem 1.75rem;background:linear-gradient(135deg,rgba(230,57,70,.08),rgba(230,57,70,.02));border:1px solid rgba(230,57,70,.22);border-radius:16px;display:flex;align-items:center;justify-content:space-between;gap:1.25rem;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:.9rem;flex:1;min-width:220px">
      <div style="width:44px;height:44px;background:#e63946;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 14px -4px rgba(230,57,70,.55)">
        <i class="fa-solid fa-trophy" style="color:#fff;font-size:1.15rem"></i>
      </div>
      <div>
        <div style="color:#f0f2ff;font-weight:800;font-size:.95rem;line-height:1.3">Διοργανώστε τη δική σας εκδήλωση</div>
        <div style="color:#8892b0;font-size:.82rem;line-height:1.4;margin-top:.15rem">
          Δωρεάν σελίδα, εγγραφές, πληρωμές, κληρώσεις, brackets — όλα σε μια πλατφόρμα.
        </div>
      </div>
    </div>
    <a href="<?= APP_URL ?>/register.php" class="btn btn-primary" style="white-space:nowrap">
      Ξεκινήστε δωρεάν <i class="fa-solid fa-arrow-right"></i>
    </a>
  </div>
  <p style="text-align:center;color:#4a5270;font-size:.78rem;padding:.5rem 0 2rem">
    Powered by
    <a href="<?= APP_URL ?>/" style="color:#e63946;text-decoration:none;font-weight:700">MA<em style="font-style:normal">ster</em></a>
    · Πλατφόρμα διαχείρισης αθλητικών συλλόγων
  </p>
</div>

<script>
/* ── Live participants search + club chip filter ────────────── */
(function(){
  var rows       = Array.from(document.querySelectorAll('#parAllGroups .par-row'));
  var groups     = Array.from(document.querySelectorAll('#parAllGroups .par-group'));
  var totalRows  = rows.length;
  var searchEl   = document.getElementById('parSearch');
  var clearEl    = document.getElementById('parClearBtn');
  var countEl    = document.getElementById('parVisibleCount');
  var emptyEl    = document.getElementById('parEmpty');
  var currentClub = '';

  function norm(s){
    if (!s) return '';
    return s.toString()
      .normalize('NFD').replace(/[̀-ͯ]/g,'') // strip Greek tonos etc.
      .toLowerCase()
      .replace(/ς/g,'σ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function apply(){
    var q = norm(searchEl && searchEl.value || '');
    if (clearEl) clearEl.style.display = (q || currentClub) ? 'inline-flex' : 'none';
    var totalVisible = 0;
    groups.forEach(function(g){
      var groupHits = 0;
      var groupRows = g.querySelectorAll('.par-row');
      var catNorm   = norm(g.getAttribute('data-cat') || '');
      groupRows.forEach(function(r){
        var name = r.getAttribute('data-name') || '';
        var club = r.getAttribute('data-club') || '';
        var cat  = r.getAttribute('data-cat')  || '';
        var showByClub = !currentClub || club === currentClub;
        var showByQ = true, isHit = false;
        if (q) {
          var hay = norm(name + ' ' + club + ' ' + cat);
          showByQ = hay.indexOf(q) >= 0;
          isHit   = showByQ && norm(name).indexOf(q) >= 0;
        }
        var visible = showByClub && showByQ;
        r.style.display = visible ? '' : 'none';
        r.classList.toggle('hit', isHit);
        if (visible) { groupHits++; totalVisible++; }
      });
      // Update the group's count next to its title
      var groupCountEl = g.querySelector('.par-group-count');
      if (groupCountEl) groupCountEl.textContent = groupHits;
      g.classList.toggle('empty', groupHits === 0);
    });
    if (countEl) countEl.textContent = totalVisible;
    if (emptyEl) emptyEl.style.display = totalVisible === 0 ? 'block' : 'none';
  }

  window.__parFilter = apply;
  window.__parClub = function(btn, club) {
    currentClub = club || '';
    document.querySelectorAll('.par-chip').forEach(function(c){ c.classList.remove('active'); });
    if (btn) btn.classList.add('active');
    apply();
  };
  window.__parClear = function() {
    if (searchEl) { searchEl.value = ''; }
    currentClub = '';
    document.querySelectorAll('.par-chip').forEach(function(c){ c.classList.remove('active'); });
    var firstChip = document.querySelector('.par-chip[data-club=""]');
    if (firstChip) firstChip.classList.add('active');
    apply();
    if (searchEl) searchEl.focus();
  };
})();
</script>

</body>
</html>
