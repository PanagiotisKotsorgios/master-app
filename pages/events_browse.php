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
<?php renderTopbar('Events άλλων συλλόγων'); ?>
<div class="page-body">

  <form method="GET" style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1rem 1.25rem;margin-bottom:1rem;display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:.65rem">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Αναζήτηση τίτλου, τοποθεσίας…" style="padding:.7rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
    <select name="type" style="padding:.7rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
      <option value="">Όλοι οι τύποι</option>
      <?php foreach (['championship','friendly','camp','seminar','meeting','exam'] as $t): ?>
        <option value="<?= $t ?>" <?= $type===$t?'selected':'' ?>><?= h(eventTypeLabel($t)) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="sport" value="<?= h($sport) ?>" placeholder="Άθλημα" style="padding:.7rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i></button>
  </form>

  <?php if (!$events): ?>
    <div style="background:#111520;border:1px dashed #2a3248;border-radius:14px;padding:2.5rem;text-align:center;color:#8892b0">
      Δεν βρέθηκαν events με αυτά τα κριτήρια.
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem">
      <?php foreach ($events as $ev): ?>
        <div class="card" style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.15rem">
          <div style="font-size:.72rem;text-transform:uppercase;color:#e63946;font-weight:700;letter-spacing:.1em;margin-bottom:.5rem">
            <?= h(eventTypeLabel($ev['type'])) ?>
          </div>
          <h3 style="margin:0 0 .3rem;color:#f0f2ff;font-size:1.05rem;line-height:1.3"><?= h($ev['title']) ?></h3>
          <div style="color:#6b7494;font-size:.82rem;margin-bottom:.55rem">από <?= h($ev['organiser_name'] ?? '—') ?></div>
          <?php if ($ev['subtitle']): ?>
            <p style="margin:0 0 .55rem;color:#8892b0;font-size:.85rem;line-height:1.4"><?= h($ev['subtitle']) ?></p>
          <?php endif; ?>
          <div style="display:flex;gap:1rem;flex-wrap:wrap;color:#6b7494;font-size:.8rem;margin:.5rem 0 .85rem">
            <?php if ($ev['starts_at']): ?><span><i class="fa-regular fa-calendar"></i> <?= h(formatDate(substr($ev['starts_at'],0,10))) ?></span><?php endif; ?>
            <?php if ($ev['venue_name']): ?><span><i class="fa-solid fa-location-dot"></i> <?= h($ev['venue_name']) ?></span><?php endif; ?>
          </div>
          <div style="display:flex;gap:.4rem;flex-wrap:wrap">
            <a href="<?= APP_URL ?>/pages/event_participate.php?id=<?= (int)$ev['id'] ?>" class="btn btn-primary btn-sm">
              <i class="fa-solid fa-user-plus"></i> Δήλωση συμμετοχής
            </a>
            <a href="<?= h(eventPublicUrl($ev)) ?>" target="_blank" class="btn btn-ghost btn-sm">
              Λεπτομέρειες <i class="fa-solid fa-arrow-up-right-from-square"></i>
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
