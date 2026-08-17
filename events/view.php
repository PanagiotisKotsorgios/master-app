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
  .par-row{padding:.5rem .75rem;border-bottom:1px solid #1e2536;display:flex;justify-content:space-between;color:#c8cfe0;font-size:.9rem}
  .par-row:last-child{border-bottom:none}
  .par-row .club{color:#8892b0;font-size:.82rem}
  .cta-row{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1.25rem}
  @media(max-width:640px){h1{font-size:1.5rem}.header{padding:1.5rem 1.25rem}.section{padding:1.15rem 1.25rem}}
</style>
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

  <div class="header">
    <span class="type-badge"><?= h(eventTypeLabel($ev['type'])) ?></span>
    <h1><?= h($ev['title']) ?></h1>
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
      <?php if (in_array($ev['status'], ['in_progress','completed'], true)): ?>
        <a href="<?= APP_URL ?>/events/results.php?slug=<?= h($ev['slug']) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-medal"></i> Αποτελέσματα
        </a>
        <a href="<?= APP_URL ?>/events/display.php?slug=<?= h($ev['slug']) ?>" target="_blank" class="btn btn-ghost">
          <i class="fa-solid fa-tv"></i> Live board
        </a>
      <?php endif; ?>
      <?php if ($ev['venue_url']): ?>
        <a href="<?= h($ev['venue_url']) ?>" target="_blank" rel="noopener" class="btn btn-ghost">
          <i class="fa-solid fa-map"></i> Χάρτης
        </a>
      <?php endif; ?>
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
      <h2>Κατηγορίες</h2>
      <?php foreach ($categories as $c): ?>
        <div class="cat-item">
          <h4><?= h($c['name']) ?></h4>
          <div class="cat-meta">
            <?php if ($c['gender']!=='MX'): ?><span><?= ['M'=>'Άνδρες','F'=>'Γυναίκες'][$c['gender']] ?></span> · <?php endif; ?>
            <?php if ($c['min_age'] || $c['max_age']): ?><span><?= ($c['min_age']??'—') ?>-<?= ($c['max_age']??'—') ?> ετών</span> · <?php endif; ?>
            <?php if ($c['min_weight'] || $c['max_weight']): ?><span><?= ($c['min_weight']??'—') ?>-<?= ($c['max_weight']??'—') ?> kg</span> · <?php endif; ?>
            <span>Format: <?= h($c['format']) ?></span>
            <?php if ($c['fee_override']!==null): ?> · <span>Κόστος: <?= number_format((float)$c['fee_override'],2,',','.') ?>€</span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($participants): ?>
    <div class="section">
      <h2>Συμμετέχοντες αθλητές (<?= count($participants) ?>)</h2>
      <?php foreach ($byCat as $catName => $rows): ?>
        <div class="par-group">
          <h3><?= h($catName) ?> (<?= count($rows) ?>)</h3>
          <div class="par-list">
            <?php foreach ($rows as $p): ?>
              <div class="par-row">
                <span><?= h($p['full_name'] ?? '—') ?></span>
                <span class="club"><?= h($p['school_name'] ?? '—') ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
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

  <p style="text-align:center;color:#4a5270;font-size:.8rem;padding:2rem 0">
    Powered by <a href="<?= APP_URL ?>/" style="color:#e63946;text-decoration:none">MA<em style="font-style:normal">ster</em></a>
  </p>
</div>

</body>
</html>
