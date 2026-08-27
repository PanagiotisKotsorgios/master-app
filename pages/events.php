<?php
/**
 * pages/events.php — Διοργανώσεις hub (school-owner side)
 * ============================================================
 * Unified page with two tabs:
 *   • ?tab=mine      → «Οι Διοργανώσεις μου» (list of events I've created)
 *   • ?tab=invoices  → «Τιμολόγια» (event_payment rows + invoice download)
 *
 * Old URL /pages/event_invoices.php now redirects to ?tab=invoices.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();

$sid    = schoolId();
$tab    = ($_GET['tab'] ?? 'mine') === 'invoices' ? 'invoices' : 'mine';
$events = $tab === 'mine'     ? eventsMineForSchool($sid)      : [];
$payments = $tab === 'invoices' ? eventPaymentsAllForSchool($sid) : [];

renderHead('Διοργανώσεις');
?>
<style>
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
             transition: transform .28s cubic-bezier(.2,.8,.2,1) !important;
             overflow-y: auto; -webkit-overflow-scrolling: touch; }
  .sidebar.open { transform: translateX(0) !important; box-shadow: 6px 0 40px rgba(0,0,0,.6) !important; }
  .main-content { margin-left: 0 !important; width: 100% !important; }
  .page-body { padding: 1rem !important; }
}
#dm-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9998; cursor: pointer; }
#dm-overlay.on { display: block; }

/* ── Tabs ── */
.ev-tabs {
  display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1.2rem;
  background:#0d1117;border:1px solid #1e2536;border-radius:12px;padding:.35rem;
  width:fit-content;max-width:100%;
}
.ev-tab {
  display:inline-flex;align-items:center;gap:.5rem;
  padding:.55rem 1rem;border-radius:9px;
  color:#8892b0;font-weight:700;font-size:.9rem;text-decoration:none;
  transition:all .18s;
  cursor:pointer;border:none;background:none;font-family:inherit;
}
.ev-tab:hover { color:#f0f2ff;background:rgba(255,255,255,.04) }
.ev-tab.active {
  background:linear-gradient(135deg,rgba(230,57,70,.2),rgba(230,57,70,.08));
  color:#ffffff;
  box-shadow:inset 0 0 0 1px rgba(230,57,70,.4);
}
.ev-tab .count {
  background:rgba(255,255,255,.08);color:#c9cee1;
  padding:.1rem .5rem;border-radius:50px;
  font-size:.72rem;font-weight:800;
}
.ev-tab.active .count { background:rgba(230,57,70,.3);color:#ffffff }

.my-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.15rem}
.my-card{background:#111520;border:1px solid #1e2536;border-radius:16px;overflow:hidden;
  display:flex;flex-direction:column;text-decoration:none;color:inherit;
  transition:transform .22s cubic-bezier(.2,.9,.3,1.1),border-color .22s ease,box-shadow .22s ease}
.my-card:hover{transform:translateY(-6px);border-color:#e63946;
  box-shadow:0 14px 34px -12px rgba(230,57,70,.35),0 6px 16px rgba(0,0,0,.5)}
.my-media{position:relative;aspect-ratio:16/9;overflow:hidden;
  background:linear-gradient(135deg,#131b2e 0%,#0d1017 100%);
  display:flex;align-items:center;justify-content:center}
.my-media img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease}
.my-card:hover .my-media img{transform:scale(1.05)}
.my-media .my-no-img{color:#2a3248;font-size:3rem}
.my-media .my-badge{position:absolute;top:.7rem;left:.7rem;font-size:.68rem;
  text-transform:uppercase;letter-spacing:.1em;color:#fff;font-weight:800;
  background:rgba(230,57,70,.92);padding:.32rem .7rem;border-radius:6px;backdrop-filter:blur(6px)}
.my-media .my-status{position:absolute;top:.7rem;right:.7rem}
.my-body{padding:1rem 1.15rem 1.15rem;display:flex;flex-direction:column;gap:.35rem;flex:1}
.my-body h3{margin:0;color:#f0f2ff;font-size:1.05rem;line-height:1.35}
.my-body p.sub{margin:.2rem 0 .3rem;color:#c8cfe0;font-size:.87rem;line-height:1.45}
.my-meta{display:flex;gap:1rem;flex-wrap:wrap;color:#6b7494;font-size:.8rem;margin-top:.35rem}
.my-meta i{color:#e63946;font-size:.75rem;margin-right:.25rem}
</style>

<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div id="dm-overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('on')"></div>
<div class="main-content">
<?php renderTopbar('Διοργανώσεις'); ?>
<div class="page-body">

  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;gap:.75rem;flex-wrap:wrap">
    <div>
      <h2 style="margin:0;font-size:1.4rem">Διοργανώσεις</h2>
      <p style="margin:.25rem 0 0;color:var(--muted,#8892b0);font-size:.9rem">
        Πρωταθλήματα, φιλικά, camps, σεμινάρια — και τα τιμολόγια των πληρωμών σας.
      </p>
    </div>
    <?php if ($tab === 'mine'): ?>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <a href="<?= APP_URL ?>/pages/events_browse.php" class="btn btn-ghost">
        <i class="fa-solid fa-magnifying-glass"></i> Αναζήτηση διοργανώσεων άλλων συλλόγων
      </a>
      <a href="<?= APP_URL ?>/pages/event_edit.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Νέα Διοργάνωση
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Tabs ── -->
  <div class="ev-tabs" role="tablist">
    <a class="ev-tab <?= $tab === 'mine' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/pages/events.php?tab=mine"
       role="tab" aria-selected="<?= $tab === 'mine' ? 'true' : 'false' ?>">
      <i class="fa-solid fa-trophy"></i>
      <span>Οι Διοργανώσεις μου</span>
      <?php $c = $tab === 'mine' ? count($events) : count(eventsMineForSchool($sid)); ?>
      <span class="count"><?= $c ?></span>
    </a>
    <a class="ev-tab <?= $tab === 'invoices' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/pages/events.php?tab=invoices"
       role="tab" aria-selected="<?= $tab === 'invoices' ? 'true' : 'false' ?>">
      <i class="fa-regular fa-file-lines"></i>
      <span>Τιμολόγια</span>
      <?php $cI = $tab === 'invoices' ? count($payments) : count(eventPaymentsAllForSchool($sid)); ?>
      <span class="count"><?= $cI ?></span>
    </a>
  </div>

<?php if ($tab === 'mine'): ?>
  <!-- ═══ TAB: Οι Διοργανώσεις μου ═══ -->
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
    <div class="my-grid">
      <?php foreach ($events as $ev):
        $mUrl = !empty($ev['banner_path'])
            ? rtrim(APP_URL, '/') . '/uploads/' . ltrim($ev['banner_path'], '/')
            : '';
      ?>
        <a href="<?= h(eventManageUrl((int)$ev['id'])) ?>" class="my-card">
          <div class="my-media">
            <?php if ($mUrl): ?>
              <img src="<?= h($mUrl) ?>" alt="<?= h($ev['title']) ?>" loading="lazy">
            <?php else: ?>
              <i class="fa-solid fa-trophy my-no-img"></i>
            <?php endif; ?>
            <span class="my-badge"><?= h(eventTypeLabel($ev['type'])) ?></span>
            <span class="my-status"><?= eventStatusBadge($ev['status']) ?></span>
          </div>
          <div class="my-body">
            <h3><?= h($ev['title']) ?></h3>
            <?php if ($ev['subtitle']): ?>
              <p class="sub"><?= h($ev['subtitle']) ?></p>
            <?php endif; ?>
            <div class="my-meta">
              <?php if ($ev['starts_at']): ?>
                <span><i class="fa-regular fa-calendar"></i><?= h(formatDate(substr($ev['starts_at'], 0, 10))) ?></span>
              <?php endif; ?>
              <?php if ($ev['venue_name']): ?>
                <span><i class="fa-solid fa-location-dot"></i><?= h($ev['venue_name']) ?></span>
              <?php endif; ?>
              <span><i class="fa-solid fa-euro-sign"></i><?= $ev['fee_model'] === 'free' ? 'Δωρεάν' : number_format((float)$ev['fee_amount'], 2, ',', '.') . '€' ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: /* tab === invoices */ ?>
  <!-- ═══ TAB: Τιμολόγια ═══ -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
    <div style="display:flex;align-items:center;gap:.7rem">
      <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#e63946,#c72832);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem">
        <i class="fa-solid fa-file-invoice"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:1.15rem;color:#f0f2ff">Τιμολόγια Διοργανώσεων</h3>
        <div style="color:#8892b0;font-size:.85rem">Τα τιμολόγια για τις πληρωμές events εμφανίζονται εδώ μόλις τα ανεβάσει ο administrator.</div>
      </div>
    </div>
  </div>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;overflow:hidden">
    <?php if (!$payments): ?>
      <div style="padding:3rem 1.5rem;text-align:center;color:#6b7494">
        <div style="font-size:2.6rem;color:#4a5270;margin-bottom:.5rem"><i class="fa-regular fa-folder-open"></i></div>
        <strong style="color:#c9cee1">Δεν υπάρχουν πληρωμές events για τη σχολή σας ακόμα.</strong>
        <div style="margin-top:.35rem;font-size:.88rem">Όταν συμμετάσχετε σε ένα event και ολοκληρώσετε την πληρωμή, θα δείτε εδώ το αντίστοιχο τιμολόγιο.</div>
      </div>
    <?php else: ?>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;min-width:760px">
          <thead>
            <tr style="background:rgba(255,255,255,.03);color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em">
              <th style="padding:.85rem 1rem;text-align:left">Event</th>
              <th style="padding:.85rem 1rem;text-align:left">Ημ/νία</th>
              <th style="padding:.85rem 1rem;text-align:left">Ποσό</th>
              <th style="padding:.85rem 1rem;text-align:left">Κατάσταση Πληρωμής</th>
              <th style="padding:.85rem 1rem;text-align:left;min-width:200px">Τιμολόγιο</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payments as $p):
              $hasInvoice = !empty($p['invoice_file_path']);
              $statusColors = [
                'pending'         => ['#ffd870','rgba(240,165,0,.15)','rgba(240,165,0,.3)'],
                'proof_uploaded'  => ['#a9c1ff','rgba(78,132,255,.15)','rgba(78,132,255,.3)'],
                'verified'        => ['#7bffb4','rgba(45,198,83,.15)','rgba(45,198,83,.3)'],
                'rejected'        => ['#ffb0b8','rgba(230,57,70,.15)','rgba(230,57,70,.3)'],
                'refunded'        => ['#e6d5ff','rgba(155,110,255,.15)','rgba(155,110,255,.3)'],
              ];
              $statusLabels = [
                'pending'         => 'Εκκρεμής',
                'proof_uploaded'  => 'Αποδεικτικό ανέβηκε',
                'verified'        => 'Επιβεβαιωμένη',
                'rejected'        => 'Απορρίφθηκε',
                'refunded'        => 'Επεστράφη',
              ];
              [$c, $bg, $bd] = $statusColors[$p['status']] ?? ['#c9cee1','rgba(255,255,255,.08)','rgba(255,255,255,.15)'];
              $lbl = $statusLabels[$p['status']] ?? $p['status'];
            ?>
              <tr style="border-top:1px solid rgba(255,255,255,.05)">
                <td style="padding:.85rem 1rem">
                  <div style="font-weight:700;color:#f0f2ff"><?= h($p['event_title']) ?></div>
                  <a style="color:#8892b0;font-size:.78rem" href="<?= APP_URL ?>/events/<?= h($p['event_slug']) ?>" target="_blank" rel="noopener">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Άνοιγμα
                  </a>
                </td>
                <td style="padding:.85rem 1rem;color:#c9cee1;font-size:.86rem">
                  <?= h($p['event_starts_at'] ? date('d/m/Y', strtotime($p['event_starts_at'])) : '—') ?>
                </td>
                <td style="padding:.85rem 1rem;font-weight:800;font-variant-numeric:tabular-nums;color:#f0f2ff">
                  <?= number_format((float)$p['amount'], 2, ',', '.') ?> €
                </td>
                <td style="padding:.85rem 1rem">
                  <span style="padding:.25rem .6rem;border-radius:99px;font-size:.72rem;font-weight:700;color:<?= $c ?>;background:<?= $bg ?>;border:1px solid <?= $bd ?>">
                    <?= h($lbl) ?>
                  </span>
                </td>
                <td style="padding:.85rem 1rem">
                  <?php if ($hasInvoice): ?>
                    <a href="<?= APP_URL ?>/<?= h($p['invoice_file_path']) ?>"
                       target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:.45rem;padding:.5rem .85rem;border-radius:8px;background:linear-gradient(135deg,#e63946,#c72832);color:#fff;font-weight:700;font-size:.82rem;text-decoration:none">
                      <i class="fa-regular fa-file-pdf"></i> Λήψη
                    </a>
                    <div style="color:#6b7494;font-size:.72rem;margin-top:.3rem">
                      Ανέβηκε: <?= h(date('d/m/Y', strtotime($p['invoice_uploaded_at']))) ?>
                    </div>
                  <?php elseif ($p['status'] === 'verified'): ?>
                    <span style="color:#ffd870;font-size:.82rem;display:inline-flex;align-items:center;gap:.4rem">
                      <i class="fa-regular fa-clock"></i> Αναμονή τιμολογίου
                    </span>
                  <?php else: ?>
                    <span style="color:#6b7494;font-size:.82rem">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

</div><!-- /page-body -->
</div><!-- /main-content -->
</div><!-- /app-layout -->
</body>
</html>
