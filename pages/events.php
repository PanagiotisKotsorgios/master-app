<?php
/**
 * pages/events.php — Διοργανώσεις hub (school-owner side)
 * ============================================================
 * Unified page with two tabs:
 *   • ?tab=mine      → «Οι Διοργανώσεις μου» (list of events I've created)
 *   • ?tab=payments  → «Πληρωμές Συμμετεχόντων» (participants × my events;
 *                       organiser marks paid / unpaid / waived / refunded)
 *
 * Old URL /pages/event_invoices.php now redirects to ?tab=payments.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();

$sid = schoolId();

// ── Handle organiser action on a registration payment ───────────
$flashMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_reg_payment') {
    try {
        verifyCsrf();
        $regId    = (int)($_POST['reg_id']     ?? 0);
        $eventId  = (int)($_POST['event_id']   ?? 0);
        $newState = (string)($_POST['payment_status'] ?? '');

        // Guard: I can only touch registrations of events I organise
        $chk = getDB()->prepare("SELECT 1 FROM event_registrations r
                                 JOIN events e ON e.id = r.event_id
                                 WHERE r.id = ? AND e.id = ? AND e.organiser_school_id = ?");
        $chk->execute([$regId, $eventId, $sid]);
        if ($chk->fetchColumn()) {
            eventRegistrationUpdatePayment($regId, $eventId, $newState, userId() ?: null);
            $flashMsg = 'Η κατάσταση πληρωμής ενημερώθηκε.';
        } else {
            $flashMsg = 'Δεν βρέθηκε η συμμετοχή.';
        }
    } catch (\Throwable $e) {
        error_log('[events.php update_reg_payment] ' . $e->getMessage());
        $flashMsg = 'Σφάλμα ενημέρωσης.';
    }
    redirect(APP_URL . '/pages/events.php?tab=payments&ok=1');
}

$tab      = ($_GET['tab'] ?? 'mine') === 'payments' ? 'payments' : 'mine';
$events   = $tab === 'mine' ? eventsMineForSchool($sid) : [];
$regsAll  = eventRegistrationsAcrossOrganiserSchool($sid);   // for the tab count
$regs     = $tab === 'payments' ? $regsAll : [];

// Filters (payments tab)
$fEvent  = isset($_GET['event'])  ? (int)$_GET['event'] : 0;
$fStatus = in_array($_GET['status'] ?? '', ['unpaid','verified','proof_uploaded','waived','refunded'], true)
         ? $_GET['status'] : '';
$fQ      = trim((string)($_GET['q'] ?? ''));

if ($tab === 'payments' && $regs) {
    // Client-side would be fine but with big lists PHP filter is cheaper
    $regs = array_values(array_filter($regs, function($r) use ($fEvent, $fStatus, $fQ) {
        if ($fEvent && (int)$r['event_id'] !== $fEvent) return false;
        if ($fStatus !== '' && $r['payment_status'] !== $fStatus) return false;
        if ($fQ !== '') {
            $needle = mb_strtolower($fQ, 'UTF-8');
            $hay = mb_strtolower(
                ($r['athlete_name']      ?? '') . ' ' .
                ($r['athlete_snap_name'] ?? '') . ' ' .
                ($r['school_name']       ?? '') . ' ' .
                ($r['event_title']       ?? '')
            , 'UTF-8');
            if (mb_strpos($hay, $needle) === false) return false;
        }
        return true;
    }));
}

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

/* ── Tabs (accessibility: large, white, high-contrast) ── */
.ev-tabs {
  display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.4rem;
  background:#0d1117;border:1px solid #1e2536;border-radius:14px;padding:.45rem;
  width:fit-content;max-width:100%;
}
.ev-tab {
  display:inline-flex;align-items:center;gap:.6rem;
  padding:.85rem 1.5rem;border-radius:11px;
  color:#ffffff !important;
  font-weight:800;font-size:1.08rem;text-decoration:none;
  letter-spacing:.01em;line-height:1.2;
  transition:all .18s;
  cursor:pointer;border:none;background:transparent;font-family:inherit;
  min-height:48px;
}
.ev-tab i { color:#ffffff !important;font-size:1.1rem }
.ev-tab:hover {
  background:rgba(255,255,255,.06);
  transform:translateY(-1px);
}
.ev-tab.active {
  background:linear-gradient(135deg,#e63946 0%,#c72832 100%);
  color:#ffffff !important;
  box-shadow:0 6px 18px -6px rgba(230,57,70,.65), inset 0 0 0 1px rgba(255,255,255,.15);
}
.ev-tab.active i { color:#ffffff !important }
.ev-tab .count {
  background:rgba(255,255,255,.16);color:#ffffff !important;
  padding:.2rem .65rem;border-radius:50px;
  font-size:.85rem;font-weight:900;
  min-width:26px;text-align:center;
}
.ev-tab.active .count { background:rgba(0,0,0,.28);color:#ffffff !important }
@media (max-width:520px){
  .ev-tab { padding:.7rem 1rem; font-size:1rem }
  .ev-tab .count { font-size:.78rem }
}

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
    <a class="ev-tab <?= $tab === 'payments' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/pages/events.php?tab=payments"
       role="tab" aria-selected="<?= $tab === 'payments' ? 'true' : 'false' ?>">
      <i class="fa-solid fa-money-check-dollar"></i>
      <span>Πληρωμές Συμμετεχόντων</span>
      <span class="count"><?= count($regsAll) ?></span>
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

<?php else: /* tab === payments — Participants payment management */
  // Group events for filter dropdown
  $eventOptions = [];
  foreach ($regsAll as $r) $eventOptions[$r['event_id']] = $r['event_title'];
  asort($eventOptions);
  // Totals for KPIs
  $totCount   = count($regsAll);
  $totAmount  = array_sum(array_map(fn($r) => (float)$r['amount'], $regsAll));
  $paidRows   = array_filter($regsAll, fn($r) => in_array($r['payment_status'], ['verified','waived'], true));
  $paidCount  = count($paidRows);
  $paidAmount = array_sum(array_map(fn($r) => (float)$r['amount'], $paidRows));
  $unpaidRows   = array_filter($regsAll, fn($r) => $r['payment_status'] === 'unpaid');
  $unpaidCount  = count($unpaidRows);
  $unpaidAmount = array_sum(array_map(fn($r) => (float)$r['amount'], $unpaidRows));
?>
  <!-- ═══ TAB: Πληρωμές Συμμετεχόντων ═══ -->
  <?php if (!empty($_GET['ok'])): ?>
    <div style="background:rgba(45,198,83,.1);border:1px solid rgba(45,198,83,.35);color:#8fe6a1;padding:.7rem 1rem;border-radius:10px;margin-bottom:1rem;font-weight:700">
      <i class="fa-solid fa-circle-check"></i> Η κατάσταση πληρωμής ενημερώθηκε.
    </div>
  <?php endif; ?>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <div style="width:46px;height:46px;border-radius:11px;background:linear-gradient(135deg,#e63946,#c72832);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.25rem;flex-shrink:0">
        <i class="fa-solid fa-money-check-dollar"></i>
      </div>
      <div style="flex:1;min-width:220px">
        <h3 style="margin:0;font-size:1.2rem;color:#ffffff;font-weight:800">Διαχείριση Πληρωμών Συμμετεχόντων</h3>
        <div style="color:#a9b3c9;font-size:.9rem;margin-top:.2rem">Ποιοι έχουν εγγραφεί στις διοργανώσεις σας, ποιοι έχουν πληρώσει, τρόπος πληρωμής και ενέργειες.</div>
      </div>
    </div>
  </div>

  <!-- KPIs -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.85rem;margin-bottom:1.15rem">
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:.95rem 1.1rem">
      <div style="color:#8892b0;font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Συμμετέχοντες</div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:2.1rem;color:#ffffff;line-height:1"><?= $totCount ?></div>
    </div>
    <div style="background:#111520;border:1px solid rgba(45,198,83,.25);border-radius:14px;padding:.95rem 1.1rem">
      <div style="color:#8892b0;font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Πληρωμένοι</div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:2.1rem;color:#2dc653;line-height:1"><?= $paidCount ?></div>
      <div style="color:#8fe6a1;font-size:.85rem;margin-top:.2rem;font-weight:700"><?= number_format($paidAmount, 2, ',', '.') ?> €</div>
    </div>
    <div style="background:#111520;border:1px solid rgba(230,57,70,.25);border-radius:14px;padding:.95rem 1.1rem">
      <div style="color:#8892b0;font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Εκκρεμούν</div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:2.1rem;color:#e63946;line-height:1"><?= $unpaidCount ?></div>
      <div style="color:#ff8891;font-size:.85rem;margin-top:.2rem;font-weight:700"><?= number_format($unpaidAmount, 2, ',', '.') ?> €</div>
    </div>
    <div style="background:#111520;border:1px solid rgba(240,165,0,.25);border-radius:14px;padding:.95rem 1.1rem">
      <div style="color:#8892b0;font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Σύνολο τζίρου</div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;color:#f0a500;line-height:1"><?= number_format($totAmount, 2, ',', '.') ?> €</div>
    </div>
  </div>

  <!-- Filters -->
  <form method="GET" style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1rem 1.1rem;margin-bottom:1rem;
                            display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;align-items:end">
    <input type="hidden" name="tab" value="payments">
    <div>
      <label style="display:block;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Διοργάνωση</label>
      <select name="event" style="width:100%;padding:.6rem .8rem;background:#0d1117;border:1.5px solid #2a3248;border-radius:9px;color:#ffffff;font-size:.95rem;min-height:44px">
        <option value="0">— Όλες οι διοργανώσεις —</option>
        <?php foreach ($eventOptions as $eid => $etitle): ?>
          <option value="<?= (int)$eid ?>" <?= $fEvent === (int)$eid ? 'selected' : '' ?>><?= h($etitle) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Κατάσταση</label>
      <select name="status" style="width:100%;padding:.6rem .8rem;background:#0d1117;border:1.5px solid #2a3248;border-radius:9px;color:#ffffff;font-size:.95rem;min-height:44px">
        <?php
          $sLabels = ['' => 'Όλες', 'unpaid'=>'Εκκρεμούν', 'verified'=>'Πληρωμένοι',
                     'proof_uploaded'=>'Αποδεικτικό ανέβηκε', 'waived'=>'Απαλλαγή', 'refunded'=>'Επιστροφή'];
          foreach ($sLabels as $v => $lbl):
        ?>
          <option value="<?= h($v) ?>" <?= $fStatus === $v ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Αναζήτηση</label>
      <input type="text" name="q" value="<?= h($fQ) ?>" placeholder="Αθλητής, σχολή…"
             style="width:100%;padding:.6rem .8rem;background:#0d1117;border:1.5px solid #2a3248;border-radius:9px;color:#ffffff;font-size:.95rem;min-height:44px">
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary" style="background:#e63946;color:#fff;border:none;padding:.6rem 1.1rem;border-radius:9px;font-weight:800;cursor:pointer;min-height:44px">
        <i class="fa-solid fa-filter"></i> Εφαρμογή
      </button>
      <a href="<?= APP_URL ?>/pages/events.php?tab=payments" style="background:rgba(255,255,255,.06);color:#ffffff;border:1px solid #2a3248;padding:.6rem 1rem;border-radius:9px;font-weight:700;text-decoration:none;min-height:44px;display:inline-flex;align-items:center">
        <i class="fa-solid fa-rotate-left"></i>
      </a>
    </div>
  </form>

  <!-- Table -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;overflow:hidden">
    <?php if (!$regsAll): ?>
      <div style="padding:3rem 1.5rem;text-align:center;color:#a9b3c9">
        <div style="font-size:2.8rem;color:#4a5270;margin-bottom:.6rem"><i class="fa-solid fa-users-slash"></i></div>
        <strong style="color:#ffffff;font-size:1.05rem">Δεν έχετε συμμετέχοντες σε καμία από τις διοργανώσεις σας.</strong>
        <div style="margin-top:.5rem;font-size:.9rem">Όταν άλλοι σύλλογοι εγγράψουν αθλητές τους, θα εμφανιστούν εδώ για διαχείριση πληρωμών.</div>
      </div>
    <?php elseif (!$regs): ?>
      <div style="padding:2.5rem 1.5rem;text-align:center;color:#a9b3c9">
        <div style="font-size:2.4rem;color:#4a5270;margin-bottom:.5rem"><i class="fa-solid fa-magnifying-glass"></i></div>
        <strong style="color:#ffffff">Δεν βρέθηκαν συμμετέχοντες με τα φίλτρα.</strong>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;min-width:900px;font-size:.94rem">
          <thead>
            <tr>
              <th style="padding:.85rem 1rem;text-align:left">Διοργάνωση / Κατηγορία</th>
              <th style="padding:.85rem 1rem;text-align:left">Αθλητής / Σχολή</th>
              <th style="padding:.85rem 1rem;text-align:right">Ποσό</th>
              <th style="padding:.85rem 1rem;text-align:left">Κατάσταση</th>
              <th style="padding:.85rem 1rem;text-align:left">Ημ. Πληρωμής</th>
              <th style="padding:.85rem 1rem;text-align:right;min-width:280px">Ενέργειες</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $psColors = [
                'unpaid'          => ['#ff8891','rgba(230,57,70,.14)','rgba(230,57,70,.35)'],
                'proof_uploaded'  => ['#a9c1ff','rgba(78,132,255,.15)','rgba(78,132,255,.35)'],
                'verified'        => ['#8fe6a1','rgba(45,198,83,.14)','rgba(45,198,83,.35)'],
                'refunded'        => ['#e6d5ff','rgba(155,110,255,.15)','rgba(155,110,255,.35)'],
                'waived'          => ['#c9cee1','rgba(255,255,255,.08)','rgba(255,255,255,.18)'],
              ];
              $psLabels = [
                'unpaid'         => 'Εκκρεμεί',
                'proof_uploaded' => 'Αποδ. ανέβηκε',
                'verified'       => 'Πληρώθηκε',
                'refunded'       => 'Επιστροφή',
                'waived'         => 'Απαλλαγή',
              ];
              foreach ($regs as $r):
                [$col,$bg,$bd] = $psColors[$r['payment_status']] ?? ['#c9cee1','rgba(255,255,255,.06)','rgba(255,255,255,.15)'];
                $lbl = $psLabels[$r['payment_status']] ?? $r['payment_status'];
                $displayName = $r['athlete_name'] ?: ($r['athlete_snap_name'] ?: '— Αθλητής —');
            ?>
              <tr style="border-top:1px solid rgba(255,255,255,.05)">
                <td style="padding:.85rem 1rem">
                  <div style="font-weight:800;color:#ffffff"><?= h($r['event_title']) ?></div>
                  <div style="font-size:.8rem;color:#8892b0;margin-top:.2rem">
                    <?php if ($r['starts_at']): ?><i class="fa-regular fa-calendar"></i> <?= h(date('d/m/Y', strtotime($r['starts_at']))) ?><?php endif; ?>
                    <?php if (!empty($r['cat_name'])): ?>
                      · <i class="fa-solid fa-layer-group"></i> <?= h($r['cat_name']) ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="padding:.85rem 1rem">
                  <div style="font-weight:800;color:#ffffff"><?= h($displayName) ?></div>
                  <div style="font-size:.8rem;color:#8892b0;margin-top:.2rem">
                    <i class="fa-solid fa-building"></i> <?= h($r['school_name'] ?? '—') ?>
                  </div>
                </td>
                <td style="padding:.85rem 1rem;text-align:right;font-weight:800;color:#ffffff;font-variant-numeric:tabular-nums;white-space:nowrap">
                  <?= number_format((float)$r['amount'], 2, ',', '.') ?> €
                </td>
                <td style="padding:.85rem 1rem">
                  <span style="padding:.28rem .7rem;border-radius:99px;font-size:.78rem;font-weight:800;color:<?= $col ?>;background:<?= $bg ?>;border:1px solid <?= $bd ?>;white-space:nowrap">
                    <?= h($lbl) ?>
                  </span>
                </td>
                <td style="padding:.85rem 1rem;color:#c9cee1;font-size:.86rem;white-space:nowrap">
                  <?= $r['paid_at'] ? h(date('d/m/Y', strtotime($r['paid_at']))) : '—' ?>
                </td>
                <td style="padding:.85rem 1rem;text-align:right">
                  <div style="display:inline-flex;gap:.35rem;flex-wrap:wrap;justify-content:flex-end">
                    <?php if ($r['payment_status'] !== 'verified'): ?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="_action" value="update_reg_payment">
                      <input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="event_id" value="<?= (int)$r['event_id'] ?>">
                      <input type="hidden" name="payment_status" value="verified">
                      <button type="submit" title="Επιβεβαίωση πληρωμής"
                              style="background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;padding:.42rem .7rem;border-radius:8px;font-weight:800;font-size:.78rem;cursor:pointer;min-height:36px">
                        <i class="fa-solid fa-check"></i> Πληρώθηκε
                      </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($r['payment_status'] !== 'unpaid'): ?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="_action" value="update_reg_payment">
                      <input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="event_id" value="<?= (int)$r['event_id'] ?>">
                      <input type="hidden" name="payment_status" value="unpaid">
                      <button type="submit" title="Επαναφορά σε εκκρεμότητα"
                              style="background:rgba(255,255,255,.06);color:#c9cee1;border:1px solid #2a3248;padding:.42rem .7rem;border-radius:8px;font-weight:700;font-size:.78rem;cursor:pointer;min-height:36px">
                        <i class="fa-solid fa-rotate-left"></i>
                      </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($r['payment_status'] !== 'waived'): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Απαλλαγή από πληρωμή;')">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="_action" value="update_reg_payment">
                      <input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="event_id" value="<?= (int)$r['event_id'] ?>">
                      <input type="hidden" name="payment_status" value="waived">
                      <button type="submit" title="Απαλλαγή πληρωμής"
                              style="background:rgba(240,165,0,.14);color:#fcd34d;border:1px solid rgba(240,165,0,.35);padding:.42rem .7rem;border-radius:8px;font-weight:700;font-size:.78rem;cursor:pointer;min-height:36px">
                        <i class="fa-solid fa-hand"></i>
                      </button>
                    </form>
                    <?php endif; ?>
                  </div>
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
