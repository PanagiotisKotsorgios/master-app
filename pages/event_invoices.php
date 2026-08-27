<?php
/**
 * pages/event_invoices.php — School's event invoices tab
 * ============================================================
 * Shows every event_payment for the current school, with a
 * download button for those where an admin has uploaded an
 * invoice PDF, and a "pending" row for those still awaiting.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();
$sid = schoolId();

$payments = eventPaymentsAllForSchool($sid);

renderHead('Τιμολόγια Διοργανώσεων');
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
</style>
<body>
<div class="app-layout">
<?php renderSidebar('event_invoices'); ?>
<div id="dm-overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('on')"></div>
<div class="main-content">
<?php renderTopbar('Τιμολόγια Διοργανώσεων'); ?>
<div class="page-body">

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
    <div style="display:flex;align-items:center;gap:.7rem">
      <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#e63946,#c72832);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem">
        <i class="fa-solid fa-file-invoice"></i>
      </div>
      <div>
        <h1 style="margin:0;font-size:1.35rem;color:#f0f2ff">Τιμολόγια Διοργανώσεων</h1>
        <div style="color:#8892b0;font-size:.88rem">Τα τιμολόγια για τις πληρωμές σας σε events εμφανίζονται εδώ μόλις τα ανεβάσει ο administrator.</div>
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

</div>
</div>
</div>
</body>
</html>
