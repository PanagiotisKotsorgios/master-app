<?php
/**
 * pages/events_browse.php — In-app discovery of PUBLIC events from other clubs
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();

$q       = trim($_GET['q'] ?? '');
$sport   = trim($_GET['sport'] ?? '');
$type    = trim($_GET['type'] ?? '');
$filters = ['upcoming' => 1];
if ($q)     $filters['q']     = $q;
if ($sport) $filters['sport'] = $sport;
if ($type)  $filters['type']  = $type;

$events = eventsPublicSearch($filters, 60, 0);

renderHead('Αναζήτηση events');
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Διοργανώσεις άλλων συλλόγων'); ?>
<div class="page-body">

  <form method="GET" class="events-browse-filters"
        style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1rem 1.1rem;margin-bottom:1rem;
               display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.65rem;align-items:end">
    <div style="grid-column:1/-1">
      <label style="display:block;font-size:.7rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Αναζήτηση</label>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Τίτλος, τοποθεσία…" autocomplete="off" inputmode="search"
             style="width:100%;padding:.85rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:1rem;min-height:48px">
    </div>
    <div>
      <label style="display:block;font-size:.7rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Τύπος</label>
      <select name="type" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
        <option value="">— Όλοι —</option>
        <?php foreach (['championship','friendly','camp','seminar','meeting','exam'] as $t): ?>
          <option value="<?= $t ?>" <?= $type===$t?'selected':'' ?>><?= h(eventTypeLabel($t)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:.7rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Άθλημα</label>
      <input type="text" name="sport" value="<?= h($sport) ?>" placeholder="π.χ. Taekwondo"
             style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
      <button type="submit" class="btn btn-primary"
              style="min-height:48px;padding:.75rem 1.3rem;font-size:.98rem;font-weight:800;flex:1">
        <i class="fa-solid fa-magnifying-glass"></i> Αναζήτηση
      </button>
      <?php if ($q || $type || $sport): ?>
      <a href="<?= APP_URL ?>/pages/events_browse.php" class="btn btn-ghost"
         style="min-height:48px;padding:.75rem 1rem;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem">
        <i class="fa-solid fa-rotate-left"></i>
      </a>
      <?php endif; ?>
    </div>
  </form>

  <?php if (!$events): ?>
    <div style="background:#111520;border:1px dashed #2a3248;border-radius:14px;padding:2.5rem;text-align:center;color:#8892b0">
      Δεν βρέθηκαν events με αυτά τα κριτήρια.
    </div>
  <?php else: ?>
    <style>
      .browse-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem}
      @media (max-width:520px){
        .browse-grid{grid-template-columns:1fr;gap:.85rem}
        .b-body h3{font-size:1rem}
        .b-body p.b-sub{font-size:.85rem}
      }
      .b-card{background:#111520;border:1px solid #1e2536;border-radius:16px;overflow:hidden;
        display:flex;flex-direction:column;transition:transform .22s cubic-bezier(.2,.9,.3,1.1),border-color .22s ease,box-shadow .22s ease}
      .b-card:hover{transform:translateY(-6px);border-color:#e63946;
        box-shadow:0 14px 34px -12px rgba(230,57,70,.35),0 6px 16px rgba(0,0,0,.5)}
      .b-media{position:relative;aspect-ratio:16/9;overflow:hidden;
        background:linear-gradient(135deg,#131b2e 0%,#0d1017 100%);
        display:flex;align-items:center;justify-content:center}
      .b-media img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease}
      .b-card:hover .b-media img{transform:scale(1.05)}
      .b-media .b-no-img{color:#2a3248;font-size:3rem}
      .b-media .b-badge{position:absolute;top:.7rem;left:.7rem;font-size:.68rem;
        text-transform:uppercase;letter-spacing:.1em;color:#fff;font-weight:800;
        background:rgba(230,57,70,.92);padding:.32rem .7rem;border-radius:6px;backdrop-filter:blur(6px)}
      .b-body{padding:1rem 1.15rem 1.15rem;display:flex;flex-direction:column;gap:.35rem;flex:1}
      .b-body h3{margin:0;color:#f0f2ff;font-size:1.05rem;line-height:1.35}
      .b-body .b-org{color:#8892b0;font-size:.82rem}
      .b-body p.b-sub{margin:.2rem 0 .3rem;color:#c8cfe0;font-size:.87rem;line-height:1.45}
      .b-meta{display:flex;gap:1rem;flex-wrap:wrap;color:#6b7494;font-size:.8rem;margin-top:.35rem}
      .b-meta i{color:#e63946;font-size:.75rem;margin-right:.25rem}
      .b-actions{display:flex;gap:.4rem;flex-wrap:wrap;padding:0 1.15rem 1.15rem}
    </style>
    <div class="browse-grid">
      <?php foreach ($events as $ev):
        $bUrl = !empty($ev['banner_path'])
            ? rtrim(APP_URL, '/') . '/uploads/' . ltrim($ev['banner_path'], '/')
            : '';
      ?>
        <div class="b-card">
          <a href="<?= h(eventPublicUrl($ev)) ?>" target="_blank" style="text-decoration:none;color:inherit">
            <div class="b-media">
              <?php if ($bUrl): ?>
                <img src="<?= h($bUrl) ?>" alt="<?= h($ev['title']) ?>" loading="lazy">
              <?php else: ?>
                <i class="fa-solid fa-trophy b-no-img"></i>
              <?php endif; ?>
              <span class="b-badge"><?= h(eventTypeLabel($ev['type'])) ?></span>
            </div>
          </a>
          <div class="b-body">
            <h3><?= h($ev['title']) ?></h3>
            <div class="b-org">από <?= h($ev['organiser_name'] ?? '—') ?></div>
            <?php if ($ev['subtitle']): ?>
              <p class="b-sub"><?= h($ev['subtitle']) ?></p>
            <?php endif; ?>
            <div class="b-meta">
              <?php if ($ev['starts_at']): ?><span><i class="fa-regular fa-calendar"></i><?= h(formatDate(substr($ev['starts_at'],0,10))) ?></span><?php endif; ?>
              <?php if ($ev['venue_name']): ?><span><i class="fa-solid fa-location-dot"></i><?= h($ev['venue_name']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="b-actions">
            <a href="<?= APP_URL ?>/pages/event_participate.php?id=<?= (int)$ev['id'] ?>" class="btn btn-primary btn-sm" style="flex:1">
              <i class="fa-solid fa-user-plus"></i> Δήλωση συμμετοχής
            </a>
            <a href="<?= h(eventPublicUrl($ev)) ?>" target="_blank" class="btn btn-ghost btn-sm">
              <i class="fa-solid fa-arrow-up-right-from-square"></i>
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
</div>
</div>
</body></html>
