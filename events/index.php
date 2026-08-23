<?php
/**
 * events/index.php — Public events directory (no login required)
 * ============================================================
 * SEO-friendly URL: /events/
 * Filters: q, sport, type
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

$q       = trim($_GET['q'] ?? '');
$sport   = trim($_GET['sport'] ?? '');
$type    = trim($_GET['type'] ?? '');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset  = ($page - 1) * $perPage;

$filters = ['upcoming' => 1];
if ($q)     $filters['q']     = $q;
if ($sport) $filters['sport'] = $sport;
if ($type)  $filters['type']  = $type;

$total  = eventsPublicCount($filters);
$events = eventsPublicSearch($filters, $perPage, $offset);
$totalPages = max(1, (int)ceil($total / $perPage));

$metaTitle = 'Διοργανώσεις, Πρωταθλήματα & Camps — MAster';
$metaDesc  = 'Ανακαλύψτε πρωταθλήματα, φιλικούς αγώνες, camps και σεμινάρια πολεμικών τεχνών από συλλόγους σε όλη την Ελλάδα.';
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($metaTitle) ?></title>
<meta name="description" content="<?= h($metaDesc) ?>">
<meta property="og:title" content="<?= h($metaTitle) ?>">
<meta property="og:description" content="<?= h($metaDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= h(APP_URL) ?>/events/">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;min-height:100vh}
  .top{position:sticky;top:0;background:rgba(7,9,15,.9);backdrop-filter:blur(10px);border-bottom:1px solid #1e2536;padding:1rem 1.25rem;z-index:10}
  .top-inner{max-width:1200px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
  .brand{font-size:1.2rem;font-weight:800;color:#f0f2ff;text-decoration:none}
  .brand em{color:#e63946;font-style:normal}
  .btn-login{padding:.55rem 1rem;background:#e63946;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:.9rem}
  .wrap{max-width:1200px;margin:0 auto;padding:2rem 1.25rem}
  h1{font-size:2rem;margin-bottom:.5rem}
  .lead{color:#8892b0;margin-bottom:2rem}
  .filters{background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:.65rem}
  .filters input,.filters select{padding:.7rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff;font-family:inherit}
  .filters button{padding:.7rem 1.2rem;background:#e63946;border:none;border-radius:8px;color:#fff;font-weight:700;cursor:pointer}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.15rem}
  .card{background:#111520;border:1px solid #1e2536;border-radius:16px;overflow:hidden;text-decoration:none;color:inherit;
        transition:transform .22s cubic-bezier(.2,.9,.3,1.1), border-color .22s ease, box-shadow .22s ease;
        display:flex;flex-direction:column;position:relative}
  .card::after{content:"";position:absolute;inset:0;border-radius:16px;pointer-events:none;
        background:linear-gradient(180deg,transparent 60%,rgba(230,57,70,0) 100%);transition:background .22s ease}
  .card:hover{transform:translateY(-6px);border-color:#e63946;
        box-shadow:0 14px 34px -12px rgba(230,57,70,.35),0 6px 16px rgba(0,0,0,.5)}
  .card:hover::after{background:linear-gradient(180deg,transparent 55%,rgba(230,57,70,.08) 100%)}
  .card-media{position:relative;aspect-ratio:16/9;overflow:hidden;background:
        linear-gradient(135deg,#131b2e 0%,#0d1017 100%);display:flex;align-items:center;justify-content:center}
  .card-media img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease}
  .card:hover .card-media img{transform:scale(1.05)}
  .card-media .no-img{color:#2a3248;font-size:3rem}
  .card-media .type-badge{position:absolute;top:.7rem;left:.7rem;font-size:.68rem;text-transform:uppercase;
        letter-spacing:.1em;color:#fff;font-weight:800;background:rgba(230,57,70,.92);
        padding:.32rem .7rem;border-radius:6px;backdrop-filter:blur(6px)}
  .card-body{padding:1rem 1.15rem 1.15rem;display:flex;flex-direction:column;gap:.4rem;flex:1}
  .card h3{font-size:1.05rem;margin:0;line-height:1.35;color:#f0f2ff}
  .card .org{color:#8892b0;font-size:.82rem}
  .meta{display:flex;gap:1rem;flex-wrap:wrap;color:#6b7494;font-size:.8rem;margin-top:auto;padding-top:.5rem}
  .meta span{display:inline-flex;align-items:center;gap:.35rem}
  .meta i{color:#e63946;font-size:.75rem}
  .empty{text-align:center;padding:3rem 1rem;color:#8892b0;border:1px dashed #2a3248;border-radius:14px;background:#0d1017}
  .pagination{display:flex;justify-content:center;gap:.3rem;margin-top:2rem}
  .pagination a,.pagination span{padding:.55rem .85rem;background:#111520;border:1px solid #1e2536;border-radius:8px;color:#8892b0;text-decoration:none}
  .pagination .cur{background:#e63946;color:#fff;border-color:#e63946}
  @media(max-width:640px){.filters{grid-template-columns:1fr 1fr;grid-template-rows:auto auto}.filters input[name=q]{grid-column:1/-1}}
</style>
<?php include __DIR__ . "/../includes/prelogin_polish.php"; ?>
</head>
<body>

<div class="top">
  <div class="top-inner">
    <a href="<?= APP_URL ?>/" class="brand">MA<em>ster</em> · Events</a>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
      <a href="<?= APP_URL ?>/events/calendar.php"
         style="padding:.5rem .85rem;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.03);color:#c9cee1;font-weight:600;font-size:.9rem;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .18s">
        <i class="fa-regular fa-calendar"></i> Ημερολόγιο
      </a>
      <a href="<?= APP_URL ?>/login.php" class="btn-login">Σύνδεση</a>
    </div>
  </div>
</div>

<div class="wrap">
  <h1>Πρωταθλήματα · Φιλικά · Camps</h1>
  <p class="lead">Ανακαλύψτε events από συλλόγους πολεμικών τεχνών σε όλη την Ελλάδα.</p>

  <form class="filters" method="GET">
    <input type="text" name="q" placeholder="Αναζήτηση…" value="<?= h($q) ?>">
    <select name="type">
      <option value="">Όλοι οι τύποι</option>
      <?php foreach (['championship','friendly','camp','seminar','meeting','exam'] as $t): ?>
        <option value="<?= $t ?>" <?= $type===$t?'selected':'' ?>><?= h(eventTypeLabel($t)) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="sport" placeholder="Άθλημα" value="<?= h($sport) ?>">
    <button type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
  </form>

  <?php if (!$events): ?>
    <div class="empty">
      <div style="font-size:3rem;color:#4a5270;margin-bottom:.5rem"><i class="fa-solid fa-magnifying-glass"></i></div>
      <p>Δεν βρέθηκαν events για αυτά τα κριτήρια.</p>
    </div>
  <?php else: ?>
    <p style="color:#8892b0;margin-bottom:1rem"><?= (int)$total ?> events</p>
    <div class="grid">
      <?php foreach ($events as $ev):
        $bannerUrl = !empty($ev['banner_path'])
            ? rtrim(APP_URL, '/') . '/uploads/' . ltrim($ev['banner_path'], '/')
            : '';
      ?>
        <a href="<?= h(eventPublicUrl($ev)) ?>" class="card" aria-label="<?= h($ev['title']) ?>">
          <div class="card-media">
            <?php if ($bannerUrl): ?>
              <img src="<?= h($bannerUrl) ?>" alt="<?= h($ev['title']) ?>" loading="lazy">
            <?php else: ?>
              <i class="fa-solid fa-trophy no-img"></i>
            <?php endif; ?>
            <span class="type-badge"><?= h(eventTypeLabel($ev['type'])) ?></span>
          </div>
          <div class="card-body">
            <h3><?= h($ev['title']) ?></h3>
            <div class="org">από <?= h($ev['organiser_name'] ?? '—') ?></div>
            <div class="meta">
              <?php if ($ev['starts_at']): ?><span><i class="fa-regular fa-calendar"></i> <?= h(formatDate(substr($ev['starts_at'],0,10))) ?></span><?php endif; ?>
              <?php if ($ev['venue_name']): ?><span><i class="fa-solid fa-location-dot"></i> <?= h($ev['venue_name']) ?></span><?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++):
          if ($i === $page):
        ?>
          <span class="cur"><?= $i ?></span>
        <?php else: ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
        <?php endif; endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

</body>
</html>
