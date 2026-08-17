<?php
/**
 * pages/events.php — Events organised by the current school
 * ============================================================
 *  - List of events created by this school
 *  - "New event" button → pages/event_edit.php
 *  - Each row → pages/event_manage.php?id=…
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();

$sid = schoolId();
$events = eventsMineForSchool($sid);

renderHead('Events / Πρωταθλήματα');
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Events — Πρωταθλήματα, Φιλικά, Camps'); ?>
<div class="page-body">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;gap:.75rem;flex-wrap:wrap">
    <div>
      <h2 style="margin:0;font-size:1.4rem">Τα Events μου</h2>
      <p style="margin:.25rem 0 0;color:var(--muted,#8892b0);font-size:.9rem">
        Πρωταθλήματα, φιλικά, camps και σεμινάρια που διοργανώνει η σχολή σας.
      </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <a href="<?= APP_URL ?>/pages/events_browse.php" class="btn btn-ghost">
        <i class="fa-solid fa-magnifying-glass"></i> Αναζήτηση events άλλων συλλόγων
      </a>
      <a href="<?= APP_URL ?>/pages/event_edit.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Νέο Event
      </a>
    </div>
  </div>

  <?php if (!$events): ?>
    <div style="background:#111520;border:1px dashed #2a3248;border-radius:14px;padding:2.5rem 1.5rem;text-align:center;color:#8892b0">
      <div style="font-size:3rem;margin-bottom:.75rem;color:#4a5270"><i class="fa-solid fa-trophy"></i></div>
      <h3 style="color:#f0f2ff;margin:0 0 .5rem">Δεν έχετε δημιουργήσει events ακόμα.</h3>
      <p style="margin:0 0 1.25rem">Ξεκινήστε ένα πρωτάθλημα, φιλικό ή camp και δεχθείτε συμμετοχές από άλλους συλλόγους.</p>
      <a href="<?= APP_URL ?>/pages/event_edit.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Δημιουργία πρώτου event
      </a>
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem">
      <?php foreach ($events as $ev): ?>
        <a href="<?= h(eventManageUrl((int)$ev['id'])) ?>" style="text-decoration:none;color:inherit">
          <div class="card" style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.15rem;transition:border-color .15s;cursor:pointer" onmouseover="this.style.borderColor='#e63946'" onmouseout="this.style.borderColor='#1e2536'">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;margin-bottom:.6rem">
              <div style="font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#e63946">
                <?= h(eventTypeLabel($ev['type'])) ?>
              </div>
              <?= eventStatusBadge($ev['status']) ?>
            </div>
            <h3 style="margin:0 0 .3rem;color:#f0f2ff;font-size:1.05rem;line-height:1.3"><?= h($ev['title']) ?></h3>
            <?php if ($ev['subtitle']): ?>
              <p style="margin:0 0 .55rem;color:#8892b0;font-size:.85rem;line-height:1.4"><?= h($ev['subtitle']) ?></p>
            <?php endif; ?>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;color:#6b7494;font-size:.8rem;margin-top:.7rem">
              <?php if ($ev['starts_at']): ?>
                <span><i class="fa-regular fa-calendar"></i> <?= h(formatDate(substr($ev['starts_at'], 0, 10))) ?></span>
              <?php endif; ?>
              <?php if ($ev['venue_name']): ?>
                <span><i class="fa-solid fa-location-dot"></i> <?= h($ev['venue_name']) ?></span>
              <?php endif; ?>
              <span><i class="fa-solid fa-euro-sign"></i>
                <?= $ev['fee_model'] === 'free' ? 'Δωρεάν' : number_format((float)$ev['fee_amount'], 2, ',', '.') . '€' ?>
              </span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</div>
</div>
</body></html>
