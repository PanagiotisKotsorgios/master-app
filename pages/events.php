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

renderHead('Διοργανώσεις');
?>
<style>
/* Match the responsive shell used by athletes.php / subscriptions.php so the
   sidebar behaves identically here (mobile burger, no horizontal scroll). */
.main-content { overflow-x: hidden !important; min-width: 0 !important; }
.page-body    { animation: fadeIn .35s ease both; padding: 1.5rem; }
@keyframes fadeIn { from { opacity: 0 } to { opacity: 1 } }
@media (max-width: 900px) {
  #menuBtn { display: inline-flex !important; min-width: 44px !important; min-height: 44px !important;
             align-items: center !important; justify-content: center !important;
             font-size: 1.2rem !important; cursor: pointer !important; }
  .sidebar { position: fixed !important; top: 0 !important; left: 0 !important; bottom: 0 !important;
             width: min(280px, 80vw) !important; z-index: 9999 !important;
             transform: translateX(-110%) !important;
             transition: transform .28s cubic-bezier(.2,.8,.2,1) !important; overflow-y: auto; }
  .sidebar.open { transform: translateX(0) !important; box-shadow: 6px 0 40px rgba(0,0,0,.6) !important; }
  .main-content { margin-left: 0 !important; width: 100% !important; }
  .page-body    { padding: 1rem !important; }
}
</style>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Διοργανώσεις — Πρωταθλήματα, Φιλικά, Camps'); ?>
<div class="page-body">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;gap:.75rem;flex-wrap:wrap">
    <div>
      <h2 style="margin:0;font-size:1.4rem">Οι Διοργανώσεις μου</h2>
      <p style="margin:.25rem 0 0;color:var(--muted,#8892b0);font-size:.9rem">
        Πρωταθλήματα, φιλικά, camps και σεμινάρια που διοργανώνει η σχολή σας.
      </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <a href="<?= APP_URL ?>/pages/events_browse.php" class="btn btn-ghost">
        <i class="fa-solid fa-magnifying-glass"></i> Αναζήτηση διοργανώσεων άλλων συλλόγων
      </a>
      <a href="<?= APP_URL ?>/pages/event_edit.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Νέα Διοργάνωση
      </a>
    </div>
  </div>

  <?php if (!$events): ?>
    <div style="background:#111520;border:1px dashed #2a3248;border-radius:14px;padding:2.5rem 1.5rem;text-align:center;color:#8892b0">
      <div style="font-size:3rem;margin-bottom:.75rem;color:#4a5270"><i class="fa-solid fa-trophy"></i></div>
      <h3 style="color:#f0f2ff;margin:0 0 .5rem">Δεν έχετε δημιουργήσει διοργανώσεις ακόμα.</h3>
      <p style="margin:0 0 1.25rem">Ξεκινήστε ένα πρωτάθλημα, φιλικό ή camp και δεχθείτε συμμετοχές από άλλους συλλόγους.</p>
      <a href="<?= APP_URL ?>/pages/event_edit.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Δημιουργία πρώτης διοργάνωσης
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
