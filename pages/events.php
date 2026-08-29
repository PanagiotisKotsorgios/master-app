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
$act = $_POST['_action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($act === 'update_reg_payment' || $act === 'delete_reg')) {
    try {
        verifyCsrf();
        $regId    = (int)($_POST['reg_id']     ?? 0);
        $eventId  = (int)($_POST['event_id']   ?? 0);

        // Guard: I can only touch registrations of events I organise
        $chk = getDB()->prepare("SELECT 1 FROM event_registrations r
                                 JOIN events e ON e.id = r.event_id
                                 WHERE r.id = ? AND e.id = ? AND e.organiser_school_id = ?");
        $chk->execute([$regId, $eventId, $sid]);
        if (!$chk->fetchColumn()) {
            $flashMsg = 'Δεν βρέθηκε η συμμετοχή.';
        } elseif ($act === 'delete_reg') {
            $db2 = getDB();
            $db2->prepare("DELETE FROM event_payment_registrations WHERE registration_id = ?")->execute([$regId]);
            $db2->prepare("DELETE FROM event_registrations WHERE id = ?")->execute([$regId]);
            if (function_exists('auditLog')) auditLog('event_reg_delete', 'event_registration', $regId);
            $flashMsg = 'Η εγγραφή διαγράφηκε.';
        } else {
            $newState = (string)($_POST['payment_status'] ?? '');
            eventRegistrationUpdatePayment($regId, $eventId, $newState, userId() ?: null);
            $flashMsg = 'Η κατάσταση πληρωμής ενημερώθηκε.';
        }
    } catch (\Throwable $e) {
        error_log('[events.php reg action] ' . $e->getMessage());
        $flashMsg = 'Σφάλμα ενημέρωσης.';
    }
    redirect(APP_URL . '/pages/events.php?tab=payments&ok=1');
}

// ── Bulk per-club actions on the aggregated Πληρωμές panel ───────
$_bulkActs = ['mark_school_paid','mark_school_unpaid','mark_school_waived','mark_school_refunded','mark_school_partial','delete_school_regs'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($act, $_bulkActs, true)) {
    try {
        verifyCsrf();
        $eventId  = (int)($_POST['event_id']  ?? 0);
        $schoolId = (int)($_POST['school_id'] ?? 0);
        if ($eventId > 0 && $schoolId >= 0) {
            $db = getDB();
            // Guard: event must be one I organise
            $chk = $db->prepare("SELECT 1 FROM events WHERE id = ? AND organiser_school_id = ?");
            $chk->execute([$eventId, $sid]);
            if (!$chk->fetchColumn()) {
                $flashMsg = 'Δεν βρέθηκε η διοργάνωση.';
            } else {
                // Bind school filter: NULL when schoolId=0, exact match otherwise
                $schoolWhere = "(registering_school_id = ? OR (? = 0 AND registering_school_id IS NULL))";

                if ($act === 'mark_school_paid') {
                    $st = $db->prepare("SELECT id FROM event_registrations
                                        WHERE event_id = ? AND $schoolWhere
                                          AND status NOT IN ('rejected','withdrawn')
                                          AND payment_status IN ('unpaid','proof_uploaded')");
                    $st->execute([$eventId, $schoolId, $schoolId]);
                    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                    $n = 0;
                    foreach ($ids as $rid) { eventRegistrationUpdatePayment((int)$rid, $eventId, 'verified', userId() ?: null); $n++; }
                    $flashMsg = "Μαρκαρίστηκαν $n συμμετοχές ως πληρωμένες.";
                }
                elseif ($act === 'mark_school_unpaid') {
                    $st = $db->prepare("SELECT id FROM event_registrations
                                        WHERE event_id = ? AND $schoolWhere
                                          AND status NOT IN ('rejected','withdrawn')
                                          AND payment_status IN ('verified','waived','refunded','proof_uploaded')");
                    $st->execute([$eventId, $schoolId, $schoolId]);
                    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                    $n = 0;
                    foreach ($ids as $rid) { eventRegistrationUpdatePayment((int)$rid, $eventId, 'unpaid', userId() ?: null); $n++; }
                    $flashMsg = "Επαναφέρθηκαν $n συμμετοχές σε εκκρεμότητα.";
                }
                elseif ($act === 'mark_school_waived') {
                    $st = $db->prepare("SELECT id FROM event_registrations
                                        WHERE event_id = ? AND $schoolWhere
                                          AND status NOT IN ('rejected','withdrawn')
                                          AND payment_status <> 'waived'");
                    $st->execute([$eventId, $schoolId, $schoolId]);
                    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                    $n = 0;
                    foreach ($ids as $rid) { eventRegistrationUpdatePayment((int)$rid, $eventId, 'waived', userId() ?: null); $n++; }
                    $flashMsg = "Έγινε απαλλαγή για $n συμμετοχές.";
                }
                elseif ($act === 'mark_school_refunded') {
                    $st = $db->prepare("SELECT id FROM event_registrations
                                        WHERE event_id = ? AND $schoolWhere
                                          AND status NOT IN ('rejected','withdrawn')
                                          AND payment_status = 'verified'");
                    $st->execute([$eventId, $schoolId, $schoolId]);
                    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                    $n = 0;
                    foreach ($ids as $rid) { eventRegistrationUpdatePayment((int)$rid, $eventId, 'refunded', userId() ?: null); $n++; }
                    $flashMsg = "Καταχωρήθηκε επιστροφή για $n συμμετοχές.";
                }
                elseif ($act === 'mark_school_partial') {
                    // Amount € → distribute to N oldest pending athletes (fee = amount per athlete)
                    $amount = (float)str_replace([',', ' '], ['.', ''], (string)($_POST['amount'] ?? '0'));
                    if ($amount <= 0) {
                        $flashMsg = 'Μη έγκυρο ποσό.';
                    } else {
                        // Fetch pending regs, oldest first, with per-reg amount
                        $st = $db->prepare("SELECT id, amount FROM event_registrations
                                            WHERE event_id = ? AND $schoolWhere
                                              AND status NOT IN ('rejected','withdrawn')
                                              AND payment_status IN ('unpaid','proof_uploaded')
                                            ORDER BY created_at ASC, id ASC");
                        $st->execute([$eventId, $schoolId, $schoolId]);
                        $pending = $st->fetchAll();
                        $remaining = $amount;
                        $n = 0;
                        foreach ($pending as $r) {
                            $fee = (float)$r['amount'];
                            if ($remaining + 0.005 >= $fee) {
                                eventRegistrationUpdatePayment((int)$r['id'], $eventId, 'verified', userId() ?: null);
                                $remaining -= $fee;
                                $n++;
                            } else {
                                break;
                            }
                        }
                        $leftover = round($remaining, 2);
                        if ($leftover > 0.005) {
                            // Note the leftover on the first still-pending reg so the organiser sees it
                            $left = $db->prepare("SELECT id, notes FROM event_registrations
                                                  WHERE event_id = ? AND $schoolWhere
                                                    AND status NOT IN ('rejected','withdrawn')
                                                    AND payment_status IN ('unpaid','proof_uploaded')
                                                  ORDER BY created_at ASC, id ASC LIMIT 1");
                            $left->execute([$eventId, $schoolId, $schoolId]);
                            $row = $left->fetch();
                            if ($row) {
                                $note = trim(($row['notes'] ?? '') . " · Μερική προπληρωμή +" . number_format($leftover, 2, ',', '.') . '€ (' . date('d/m/Y') . ')');
                                $upd = $db->prepare("UPDATE event_registrations SET notes = ? WHERE id = ?");
                                $upd->execute([mb_substr($note, 0, 1000), (int)$row['id']]);
                            }
                            $flashMsg = "Πληρώθηκαν $n αθλητ" . ($n===1?'ής':'ές') . ". Υπόλοιπο " . number_format($leftover, 2, ',', '.') . '€ καταχωρήθηκε ως προκαταβολή.';
                        } else {
                            $flashMsg = "Πληρώθηκαν $n αθλητ" . ($n===1?'ής':'ές') . '.';
                        }
                    }
                }
                elseif ($act === 'delete_school_regs') {
                    $st = $db->prepare("SELECT id FROM event_registrations
                                        WHERE event_id = ? AND $schoolWhere");
                    $st->execute([$eventId, $schoolId, $schoolId]);
                    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
                    $n = 0;
                    foreach ($ids as $rid) {
                        $db->prepare("DELETE FROM event_payment_registrations WHERE registration_id = ?")->execute([$rid]);
                        $db->prepare("DELETE FROM event_registrations WHERE id = ?")->execute([$rid]);
                        if (function_exists('auditLog')) auditLog('event_reg_delete', 'event_registration', (int)$rid);
                        $n++;
                    }
                    $flashMsg = "Διαγράφηκαν $n εγγραφές.";
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[events.php bulk school action] ' . $e->getMessage());
        $flashMsg = 'Σφάλμα ενημέρωσης.';
    }
    redirect(APP_URL . '/pages/events.php?tab=payments&ok=1');
}

$tabRaw = $_GET['tab'] ?? 'mine';
if (!in_array($tabRaw, ['mine', 'joined', 'payments'], true)) $tabRaw = 'mine';
$tab      = $tabRaw;
$events   = $tab === 'mine' ? eventsMineForSchool($sid) : [];
$regsAll  = eventRegistrationsAcrossOrganiserSchool($sid);   // for the tab count
$regs     = $tab === 'payments' ? $regsAll : [];

// ── "Διοργανώσεις που συμμετέχω": events any of my athletes are registered in ──
// (this is the participant side — different from eventsMineForSchool which is
//  the organiser side).
$joinedEvents = [];
try {
    $st = getDB()->prepare("
        SELECT e.id, e.slug, e.title, e.subtitle, e.type, e.status, e.visibility,
               e.starts_at, e.ends_at, e.banner_path, e.fee_amount, e.fee_model,
               e.venue_name, e.organiser_school_id,
               os.name AS organiser_name,
               COUNT(r.id) AS my_regs,
               SUM(CASE WHEN r.payment_status = 'verified' THEN 1 ELSE 0 END) AS my_paid,
               SUM(CASE WHEN r.payment_status IN ('unpaid','proof_uploaded') THEN 1 ELSE 0 END) AS my_pending,
               SUM(CASE WHEN r.payment_status IN ('unpaid','proof_uploaded') THEN r.amount ELSE 0 END) AS my_owed
          FROM event_registrations r
          JOIN events   e  ON e.id  = r.event_id
          LEFT JOIN schools os ON os.id = e.organiser_school_id
         WHERE r.registering_school_id = ?
           AND r.status NOT IN ('rejected','withdrawn')
         GROUP BY e.id, e.slug, e.title, e.subtitle, e.type, e.status, e.visibility,
                  e.starts_at, e.ends_at, e.banner_path, e.fee_amount, e.fee_model,
                  e.venue_name, e.organiser_school_id, os.name
         ORDER BY e.starts_at IS NULL, e.starts_at ASC
    ");
    $st->execute([$sid]);
    $joinedEvents = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $joinedEvents = []; }

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
        <i class="fa-solid fa-magnifying-glass"></i> Αναζήτηση Διοργανώσεων
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
    <a class="ev-tab <?= $tab === 'joined' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/pages/events.php?tab=joined"
       role="tab" aria-selected="<?= $tab === 'joined' ? 'true' : 'false' ?>">
      <i class="fa-solid fa-users-line"></i>
      <span>Συμμετέχω</span>
      <span class="count"><?= count($joinedEvents) ?></span>
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

<?php elseif ($tab === 'joined'): ?>
  <!-- ═══ TAB: Συμμετέχω ═══ -->
  <?php if (!$joinedEvents): ?>
    <div style="background:#111520;border:1px dashed #2a3248;border-radius:14px;padding:2.5rem 1.5rem;text-align:center;color:#8892b0">
      <div style="font-size:3rem;margin-bottom:.75rem;color:#4a5270"><i class="fa-solid fa-users-line"></i></div>
      <h3 style="color:#f0f2ff;margin:0 0 .5rem">Δεν συμμετέχετε ακόμα σε καμία διοργάνωση.</h3>
      <p style="margin:0 0 1.25rem">Ψάξτε στις διοργανώσεις άλλων συλλόγων και δηλώστε τους αθλητές σας.</p>
      <a href="<?= APP_URL ?>/pages/events_browse.php" class="btn btn-primary"
         style="color:#ffffff !important;font-weight:800;padding:.85rem 1.4rem;font-size:1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.55rem">
        <i class="fa-solid fa-magnifying-glass" style="color:#ffffff !important"></i>
        <span style="color:#ffffff !important">Αναζήτηση διοργανώσεων</span>
      </a>
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1rem">
      <?php foreach ($joinedEvents as $je):
        $bUrl = !empty($je['banner_path'])
            ? rtrim(APP_URL, '/') . '/uploads/' . ltrim($je['banner_path'], '/') : '';
        $myPaid    = (int)$je['my_paid'];
        $myPending = (int)$je['my_pending'];
        $myRegs    = (int)$je['my_regs'];
        $myOwed    = (float)$je['my_owed'];
        $statusLbl = eventStatusLabel($je['status']);
        $statusCol = match ($je['status']) {
            'open','in_progress' => '#8fe6a1',
            'closed'             => '#fcd34d',
            'completed'          => '#c8cfe0',
            'cancelled'          => '#ff8891',
            default              => '#8892b0',
        };
      ?>
        <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;overflow:hidden;display:flex;flex-direction:column">
          <div style="position:relative;aspect-ratio:16/9;background:linear-gradient(135deg,#131b2e,#0d1017);display:flex;align-items:center;justify-content:center;overflow:hidden">
            <?php if ($bUrl): ?>
              <img src="<?= h($bUrl) ?>" alt="<?= h($je['title']) ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover">
            <?php else: ?>
              <i class="fa-solid fa-trophy" style="color:#2a3248;font-size:3rem"></i>
            <?php endif; ?>
            <span style="position:absolute;top:.6rem;left:.6rem;background:rgba(230,57,70,.92);color:#fff;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;padding:.28rem .6rem;border-radius:6px">
              <?= h(eventTypeLabel($je['type'])) ?>
            </span>
            <span style="position:absolute;top:.6rem;right:.6rem;background:rgba(0,0,0,.55);color:<?= $statusCol ?>;font-size:.68rem;font-weight:800;padding:.28rem .55rem;border-radius:6px">
              <?= h($statusLbl) ?>
            </span>
          </div>
          <div style="padding:.85rem 1rem;flex:1;display:flex;flex-direction:column;gap:.4rem">
            <div style="font-weight:800;color:#fff;font-size:1rem;line-height:1.3"><?= h($je['title']) ?></div>
            <div style="color:#8892b0;font-size:.78rem;line-height:1.5">
              <i class="fa-solid fa-building" style="color:#e63946;font-size:.72rem"></i> <?= h($je['organiser_name'] ?? '—') ?>
              <?php if ($je['starts_at']): ?>
                · <i class="fa-regular fa-calendar" style="color:#e63946;font-size:.72rem"></i> <?= h(date('d/m/Y', strtotime($je['starts_at']))) ?>
              <?php endif; ?>
            </div>
            <?php if (!empty($je['venue_name'])): ?>
              <div style="color:#8892b0;font-size:.78rem"><i class="fa-solid fa-location-dot" style="color:#e63946;font-size:.72rem"></i> <?= h($je['venue_name']) ?></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.4rem;margin-top:.6rem;padding-top:.6rem;border-top:1px solid #1e2536">
              <div style="text-align:center;background:#0d1017;border:1px solid #1e2536;border-radius:8px;padding:.4rem">
                <div style="color:#8892b0;font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase">Οι αθλητές μου</div>
                <div style="color:#fff;font-weight:800;font-size:1.15rem;line-height:1;margin-top:.15rem"><?= $myRegs ?></div>
              </div>
              <div style="text-align:center;background:#0d1017;border:1px solid rgba(45,198,83,.25);border-radius:8px;padding:.4rem">
                <div style="color:#8892b0;font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase">Πληρώθηκαν</div>
                <div style="color:#8fe6a1;font-weight:800;font-size:1.15rem;line-height:1;margin-top:.15rem"><?= $myPaid ?></div>
              </div>
              <div style="text-align:center;background:#0d1017;border:1px solid rgba(230,57,70,.28);border-radius:8px;padding:.4rem">
                <div style="color:#8892b0;font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase">Εκκρεμούν</div>
                <div style="color:#ff8891;font-weight:800;font-size:1.15rem;line-height:1;margin-top:.15rem"><?= $myPending ?></div>
              </div>
            </div>
            <?php if ($myOwed > 0.005): ?>
              <div style="color:#f0a500;font-size:.82rem;font-weight:700;text-align:center;margin-top:.15rem">
                Υπόλοιπο: <?= number_format($myOwed, 2, ',', '.') ?> €
              </div>
            <?php endif; ?>
          </div>
          <div style="padding:0 1rem 1rem;display:flex;gap:.4rem;flex-wrap:wrap">
            <a href="<?= APP_URL ?>/pages/event_participate.php?id=<?= (int)$je['id'] ?>"
               class="btn btn-primary" style="flex:1;text-align:center;padding:.7rem 1rem;font-weight:800;min-height:44px">
              <i class="fa-solid fa-arrow-right"></i> Διαχείριση συμμετοχής
            </a>
            <a href="<?= APP_URL ?>/events/view.php?slug=<?= h($je['slug']) ?>" target="_blank"
               class="btn btn-ghost" style="padding:.7rem;min-height:44px;text-decoration:none" title="Δημόσια σελίδα">
              <i class="fa-solid fa-arrow-up-right-from-square"></i>
            </a>
          </div>
        </div>
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

  <!-- Filters (live — no submit button needed) -->
  <form method="GET" id="evPayFiltersForm"
        style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1rem 1.1rem;margin-bottom:1rem;
               display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;align-items:end">
    <input type="hidden" name="tab" value="payments">
    <div>
      <label style="display:block;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Διοργάνωση</label>
      <select name="event" data-live style="width:100%;padding:.6rem .8rem;background:#0d1117;border:1.5px solid #2a3248;border-radius:9px;color:#ffffff;font-size:.95rem;min-height:44px">
        <option value="0">— Όλες οι διοργανώσεις —</option>
        <?php foreach ($eventOptions as $eid => $etitle): ?>
          <option value="<?= (int)$eid ?>" <?= $fEvent === (int)$eid ? 'selected' : '' ?>><?= h($etitle) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Κατάσταση</label>
      <select name="status" data-live style="width:100%;padding:.6rem .8rem;background:#0d1117;border:1.5px solid #2a3248;border-radius:9px;color:#ffffff;font-size:.95rem;min-height:44px">
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
      <input type="text" name="q" value="<?= h($fQ) ?>" placeholder="Αθλητής, σχολή…" data-live
             style="width:100%;padding:.6rem .8rem;background:#0d1117;border:1.5px solid #2a3248;border-radius:9px;color:#ffffff;font-size:.95rem;min-height:44px">
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <a href="<?= APP_URL ?>/pages/events.php?tab=payments" style="background:rgba(255,255,255,.06);color:#ffffff;border:1px solid #2a3248;padding:.6rem 1rem;border-radius:9px;font-weight:700;text-decoration:none;min-height:44px;display:inline-flex;align-items:center;gap:.4rem">
        <i class="fa-solid fa-rotate-left"></i> Καθαρισμός
      </a>
      <span id="evPayBusy" style="color:#8892b0;font-size:.82rem;display:none;align-items:center;gap:.35rem">
        <i class="fa-solid fa-spinner fa-spin"></i> Ενημέρωση…
      </span>
    </div>
  </form>
  <script>
    (function(){
      var form = document.getElementById('evPayFiltersForm');
      if (!form) return;
      var busy = document.getElementById('evPayBusy');
      var t = null;
      function submit(){ if (busy) busy.style.display = 'inline-flex'; form.submit(); }
      form.querySelectorAll('[data-live]').forEach(function(el){
        if (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'search')) {
          el.addEventListener('input', function(){ clearTimeout(t); t = setTimeout(submit, 400); });
        } else {
          el.addEventListener('change', function(){ clearTimeout(t); t = setTimeout(submit, 100); });
        }
      });
    })();
  </script>

  <?php
    // ── Aggregate per (event × school) — user asked for club totals,
    // not per-athlete rows. Uses the currently filtered $regs set.
    $agg = [];
    foreach ($regs as $r) {
        $eid = (int)$r['event_id'];
        $sk  = (int)($r['registering_school_id'] ?? 0);
        $key = $eid . '·' . $sk;
        if (!isset($agg[$key])) {
            $agg[$key] = [
                'event_id'     => $eid,
                'event_title'  => $r['event_title'],
                'starts_at'    => $r['starts_at'],
                'school_id'    => $sk,
                'school_name'  => $r['school_name'] ?: '— Άγνωστη σχολή —',
                'athletes'     => 0,
                'total'        => 0.0,
                'paid'         => 0.0,
                'unpaid'       => 0.0,
                'unpaid_count' => 0,
                'proof_count'  => 0,
            ];
        }
        $agg[$key]['athletes']++;
        $agg[$key]['total'] += (float)$r['amount'];
        $ps = $r['payment_status'];
        if (in_array($ps, ['verified','waived'], true)) {
            $agg[$key]['paid'] += (float)$r['amount'];
        } elseif ($ps === 'unpaid') {
            $agg[$key]['unpaid'] += (float)$r['amount'];
            $agg[$key]['unpaid_count']++;
        } elseif ($ps === 'proof_uploaded') {
            $agg[$key]['proof_count']++;
        }
    }
    // Unpaid first, then by school name
    uasort($agg, function($a, $b){
        if ($b['unpaid'] != $a['unpaid']) return $b['unpaid'] <=> $a['unpaid'];
        return strcasecmp($a['school_name'], $b['school_name']);
    });
  ?>

  <?php if ($agg): ?>
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;margin-bottom:1rem;overflow:hidden">
    <div style="padding:.9rem 1.15rem;border-bottom:1px solid #1e2536;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
      <div style="width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff"><i class="fa-solid fa-building-columns"></i></div>
      <div style="flex:1;min-width:180px">
        <div style="font-weight:800;color:#fff;font-size:1rem">Πληρωμές ανά σύλλογο</div>
        <div style="color:#8892b0;font-size:.78rem;margin-top:.1rem"><?= count($agg) ?> σύλλογοι · <?= array_sum(array_column($agg,'athletes')) ?> αθλητές συνολικά</div>
      </div>
    </div>
    <div class="table-wrap" style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.94rem;min-width:900px">
        <thead>
          <tr style="background:rgba(255,255,255,.03)">
            <th style="padding:.7rem 1rem;text-align:left;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em">Σύλλογος / Διοργάνωση</th>
            <th style="padding:.7rem .75rem;text-align:right;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em">Αθλητές</th>
            <th style="padding:.7rem .75rem;text-align:right;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em">Σύνολο</th>
            <th style="padding:.7rem .75rem;text-align:right;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em">Πληρωμένο</th>
            <th style="padding:.7rem .75rem;text-align:right;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em">Εκκρεμεί</th>
            <th style="padding:.7rem 1rem;text-align:right;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;min-width:260px">Ενέργειες</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($agg as $a): ?>
            <tr style="border-top:1px solid rgba(255,255,255,.05)">
              <td style="padding:.75rem 1rem">
                <div style="font-weight:800;color:#fff"><?= h($a['school_name']) ?></div>
                <div style="font-size:.78rem;color:#8892b0;margin-top:.15rem">
                  <i class="fa-solid fa-trophy" style="color:#e63946;font-size:.7rem"></i>
                  <?= h($a['event_title']) ?>
                  <?php if ($a['starts_at']): ?> · <?= h(date('d/m/Y', strtotime($a['starts_at']))) ?><?php endif; ?>
                </div>
              </td>
              <td style="padding:.75rem .75rem;text-align:right;font-weight:700;color:#fff"><?= (int)$a['athletes'] ?></td>
              <td style="padding:.75rem .75rem;text-align:right;font-weight:700;color:#fff"><?= number_format($a['total'], 2, ',', '.') ?> €</td>
              <td style="padding:.75rem .75rem;text-align:right;font-weight:800;color:<?= $a['paid']>0 ? '#8fe6a1' : '#4a5270' ?>"><?= number_format($a['paid'], 2, ',', '.') ?> €</td>
              <td style="padding:.75rem .75rem;text-align:right;font-weight:800;color:<?= $a['unpaid']>0 ? '#ff8891' : '#4a5270' ?>">
                <?php if ($a['unpaid']>0): ?>
                  <?= number_format($a['unpaid'], 2, ',', '.') ?> €
                  <div style="font-size:.7rem;font-weight:700;color:#ff8891;opacity:.85;margin-top:.1rem"><?= (int)$a['unpaid_count'] ?> αθλητ<?= $a['unpaid_count']===1?'ής':'ές' ?></div>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td style="padding:.75rem 1rem;text-align:right">
                <div style="display:inline-flex;gap:.35rem;flex-wrap:wrap;justify-content:flex-end">
                  <?php $pending = (int)($a['unpaid_count'] + $a['proof_count']); ?>
                  <?php if ($pending > 0): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Μαρκάρισμα <?= $pending ?> εκκρεμών αθλητών από <?= h(addslashes($a['school_name'])) ?> ως πληρωμένοι;');">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="_action" value="mark_school_paid">
                      <input type="hidden" name="event_id" value="<?= (int)$a['event_id'] ?>">
                      <input type="hidden" name="school_id" value="<?= (int)$a['school_id'] ?>">
                      <button type="submit" title="Πληρώθηκαν όλοι οι εκκρεμείς αθλητές"
                              style="background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;padding:.5rem .85rem;border-radius:8px;font-weight:800;font-size:.8rem;cursor:pointer;min-height:36px;display:inline-flex;align-items:center;gap:.35rem">
                        <i class="fa-solid fa-check-double"></i> Πληρώθηκαν όλοι
                      </button>
                    </form>
                    <button type="button"
                            onclick="openPartialModal(<?= (int)$a['event_id'] ?>, <?= (int)$a['school_id'] ?>, '<?= h(addslashes($a['school_name'])) ?>', <?= json_encode($a['unpaid']) ?>, <?= $pending ?>)"
                            title="Μερική πληρωμή"
                            style="background:rgba(59,130,246,.14);color:#a9c1ff;border:1px solid rgba(59,130,246,.35);padding:.5rem .75rem;border-radius:8px;font-weight:700;font-size:.8rem;cursor:pointer;min-height:36px;display:inline-flex;align-items:center;gap:.35rem">
                      <i class="fa-solid fa-percent"></i> Μερική
                    </button>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Απαλλαγή για <?= $pending ?> εκκρεμείς αθλητές από <?= h(addslashes($a['school_name'])) ?>;');">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="_action" value="mark_school_waived">
                      <input type="hidden" name="event_id" value="<?= (int)$a['event_id'] ?>">
                      <input type="hidden" name="school_id" value="<?= (int)$a['school_id'] ?>">
                      <button type="submit" title="Απαλλαγή πληρωμής (όλοι)"
                              style="background:rgba(240,165,0,.14);color:#fcd34d;border:1px solid rgba(240,165,0,.35);padding:.5rem .75rem;border-radius:8px;font-weight:700;font-size:.8rem;cursor:pointer;min-height:36px">
                        <i class="fa-solid fa-hand"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                  <?php if ($a['paid'] > 0): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Επιστροφή χρημάτων για όλες τις πληρωμένες συμμετοχές από <?= h(addslashes($a['school_name'])) ?>;');">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="_action" value="mark_school_refunded">
                      <input type="hidden" name="event_id" value="<?= (int)$a['event_id'] ?>">
                      <input type="hidden" name="school_id" value="<?= (int)$a['school_id'] ?>">
                      <button type="submit" title="Επιστροφή χρημάτων"
                              style="background:rgba(155,110,255,.14);color:#e6d5ff;border:1px solid rgba(155,110,255,.35);padding:.5rem .75rem;border-radius:8px;font-weight:700;font-size:.8rem;cursor:pointer;min-height:36px">
                        <i class="fa-solid fa-arrow-rotate-left"></i>
                      </button>
                    </form>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Επαναφορά σε εκκρεμότητα για όλους από <?= h(addslashes($a['school_name'])) ?>;');">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="_action" value="mark_school_unpaid">
                      <input type="hidden" name="event_id" value="<?= (int)$a['event_id'] ?>">
                      <input type="hidden" name="school_id" value="<?= (int)$a['school_id'] ?>">
                      <button type="submit" title="Επαναφορά σε εκκρεμότητα"
                              style="background:rgba(255,255,255,.06);color:#c9cee1;border:1px solid #2a3248;padding:.5rem .75rem;border-radius:8px;font-weight:700;font-size:.8rem;cursor:pointer;min-height:36px">
                        <i class="fa-solid fa-rotate-left"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                  <form method="POST" style="display:inline"
                        onsubmit="return confirm('Διαγραφή ΟΛΩΝ των εγγραφών (<?= (int)$a['athletes'] ?>) από <?= h(addslashes($a['school_name'])) ?>; Δεν αναιρείται.');">
                    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                    <input type="hidden" name="_action" value="delete_school_regs">
                    <input type="hidden" name="event_id" value="<?= (int)$a['event_id'] ?>">
                    <input type="hidden" name="school_id" value="<?= (int)$a['school_id'] ?>">
                    <button type="submit" title="Διαγραφή όλων των εγγραφών (μόνιμο)"
                            style="background:rgba(230,57,70,.12);color:#ff8891;border:1px solid rgba(230,57,70,.35);padding:.5rem .75rem;border-radius:8px;font-weight:700;font-size:.8rem;cursor:pointer;min-height:36px">
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Partial payment modal -->
  <div id="partialPayModal" role="dialog" aria-modal="true"
       style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);z-index:10500;align-items:center;justify-content:center;padding:1rem"
       onclick="if(event.target===this)closePartialModal()">
    <div style="background:#111520;border:1px solid #1e2536;border-radius:16px;max-width:440px;width:100%;box-shadow:0 30px 80px rgba(0,0,0,.6)">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.2rem;border-bottom:1px solid #1e2536">
        <div style="display:flex;align-items:center;gap:.6rem">
          <div style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff"><i class="fa-solid fa-percent"></i></div>
          <div>
            <div style="font-weight:800;color:#fff">Μερική πληρωμή</div>
            <div id="ppSchool" style="font-size:.8rem;color:#8892b0;margin-top:.15rem"></div>
          </div>
        </div>
        <button type="button" onclick="closePartialModal()" style="background:rgba(255,255,255,.05);border:1px solid #2a3248;color:#fff;width:34px;height:34px;border-radius:9px;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form method="POST" style="padding:1rem 1.2rem">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_action" value="mark_school_partial">
        <input type="hidden" name="event_id" id="pp_event_id">
        <input type="hidden" name="school_id" id="pp_school_id">
        <div style="color:#a9b3c9;font-size:.86rem;line-height:1.5;margin-bottom:.75rem">
          Το ποσό θα κατανεμηθεί αυτόματα ξεκινώντας από τους παλαιότερους εκκρεμείς αθλητές. Ό,τι περισσέψει καταχωρείται ως προκαταβολή στον επόμενο.
        </div>
        <div id="ppMeta" style="background:#0d1017;border:1px solid #1e2536;border-radius:9px;padding:.65rem .85rem;margin-bottom:.75rem;font-size:.85rem;color:#c9cee1"></div>
        <label style="display:block;font-size:.72rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.35rem">Ποσό (€)</label>
        <input type="number" name="amount" id="pp_amount" step="0.01" min="0.01" required
               oninput="ppPreview()"
               style="width:100%;padding:.75rem .9rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:9px;color:#fff;font-size:1.1rem;font-weight:800;min-height:46px">
        <div id="ppPreview" style="margin-top:.65rem;font-size:.88rem;color:#8fe6a1;font-weight:700;min-height:1.2em"></div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">
          <button type="button" onclick="closePartialModal()"
                  style="background:rgba(255,255,255,.06);color:#c9cee1;border:1px solid #2a3248;padding:.6rem 1rem;border-radius:9px;font-weight:700;cursor:pointer;min-height:42px">Ακύρωση</button>
          <button type="submit"
                  style="background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;padding:.6rem 1.1rem;border-radius:9px;font-weight:800;cursor:pointer;min-height:42px">
            <i class="fa-solid fa-check"></i> Καταχώρηση
          </button>
        </div>
      </form>
    </div>
  </div>
  <script>
    var _ppCtx = { unpaidAmount: 0, pendingCount: 0, fee: 15 };
    function openPartialModal(eventId, schoolId, schoolName, unpaidAmount, pendingCount) {
      _ppCtx.unpaidAmount = Number(unpaidAmount) || 0;
      _ppCtx.pendingCount = pendingCount | 0;
      _ppCtx.fee = pendingCount > 0 ? (_ppCtx.unpaidAmount / pendingCount) : 15;
      document.getElementById('pp_event_id').value  = eventId;
      document.getElementById('pp_school_id').value = schoolId;
      document.getElementById('pp_amount').value    = '';
      document.getElementById('ppSchool').textContent = schoolName;
      document.getElementById('ppMeta').innerHTML =
        'Εκκρεμούν <b style="color:#fff">' + pendingCount + '</b> αθλητ' + (pendingCount===1?'ής':'ές') +
        ' · Σύνολο οφειλής <b style="color:#fff">' + _ppCtx.unpaidAmount.toFixed(2).replace('.', ',') + ' €</b>' +
        ' · Ανά αθλητή <b style="color:#fff">' + _ppCtx.fee.toFixed(2).replace('.', ',') + ' €</b>';
      document.getElementById('ppPreview').textContent = '';
      document.getElementById('partialPayModal').style.display = 'flex';
    }
    function closePartialModal() { document.getElementById('partialPayModal').style.display = 'none'; }
    function ppPreview() {
      var amt = parseFloat(document.getElementById('pp_amount').value.replace(',', '.')) || 0;
      var fee = _ppCtx.fee || 0;
      if (amt <= 0 || fee <= 0) { document.getElementById('ppPreview').textContent = ''; return; }
      var n = Math.min(_ppCtx.pendingCount, Math.floor((amt + 0.005) / fee));
      var leftover = Math.max(0, amt - n * fee);
      var txt = 'Θα πληρωθούν ' + n + ' αθλητ' + (n===1?'ής':'ές');
      if (leftover > 0.005) txt += ' · Υπόλοιπο ' + leftover.toFixed(2).replace('.', ',') + ' € ως προκαταβολή';
      document.getElementById('ppPreview').textContent = txt;
    }
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closePartialModal(); });
  </script>

  <?php if (false): // Detail per-athlete table hidden — actions live on the aggregate above ?>
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
                    <?php if ($r['payment_status'] === 'verified' || $r['payment_status'] === 'refunded'): ?>
                      <?php if ($r['payment_status'] !== 'refunded'): ?>
                      <form method="POST" style="display:inline"
                            onsubmit="return confirm('Σήμανση ως Επιστροφή χρημάτων;')">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="_action" value="update_reg_payment">
                        <input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="event_id" value="<?= (int)$r['event_id'] ?>">
                        <input type="hidden" name="payment_status" value="refunded">
                        <button type="submit" title="Επιστροφή χρημάτων"
                                style="background:rgba(155,110,255,.14);color:#e6d5ff;border:1px solid rgba(155,110,255,.35);padding:.42rem .7rem;border-radius:8px;font-weight:700;font-size:.78rem;cursor:pointer;min-height:36px">
                          <i class="fa-solid fa-arrow-rotate-left"></i>
                        </button>
                      </form>
                      <?php endif; ?>
                    <?php endif; ?>
                    <!-- Delete registration entirely — for wrong entries -->
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Διαγραφή ΟΛΗΣ της εγγραφής του/της <?= h($displayName) ?>; Δεν αναιρείται.');">
                      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                      <input type="hidden" name="_action" value="delete_reg">
                      <input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="event_id" value="<?= (int)$r['event_id'] ?>">
                      <button type="submit" title="Διαγραφή εγγραφής (μόνιμο)"
                              style="background:rgba(230,57,70,.12);color:#ff8891;border:1px solid rgba(230,57,70,.35);padding:.42rem .7rem;border-radius:8px;font-weight:700;font-size:.78rem;cursor:pointer;min-height:36px">
                        <i class="fa-solid fa-trash"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; // /hidden detail table ?>
<?php endif; ?>

</div><!-- /page-body -->
</div><!-- /main-content -->
</div><!-- /app-layout -->
</body>
</html>
