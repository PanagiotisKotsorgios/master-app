<?php
/**
 * pages/event_referee.php — Referee tablet: score a single match, or list a ring's queue
 * ============================================================
 *  GET  ?id=EVENT                       → pick ring / see queue
 *  GET  ?id=EVENT&ring=1                → live queue for that ring
 *  GET  ?id=EVENT&match=MID             → score this specific match
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events_bracket.php';

requireLogin();
$sid    = schoolId();
$userId = userId();
$id     = (int)($_GET['id']    ?? 0);
$ring   = (int)($_GET['ring']  ?? 0);
$matchId= (int)($_GET['match'] ?? 0);
$ev     = eventGet($id);

if (!$ev || (int)$ev['organiser_school_id'] !== $sid) {
    flash('Δεν έχετε πρόσβαση.', 'error');
    redirect(APP_URL . '/pages/events.php');
}

// POST: score / status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    try {
        $mid = (int)($_POST['match_id'] ?? 0);
        if ($action === 'live_on')  bracketSetLive($mid, true);
        if ($action === 'live_off') bracketSetLive($mid, false);
        if ($action === 'score') {
            $red   = max(0, (int)($_POST['red_score']  ?? 0));
            $blue  = max(0, (int)($_POST['blue_score'] ?? 0));
            $rt    = $_POST['result_type'] ?? 'points';
            $winId = isset($_POST['winner_id']) && $_POST['winner_id'] !== '' ? (int)$_POST['winner_id'] : null;
            $notes = $_POST['notes'] ?? '';
            bracketScore($mid, $red, $blue, $rt, $winId, $notes);
            flash('Αποτέλεσμα καταχωρήθηκε.');
        }
    } catch (Throwable $e) {
        flash('Σφάλμα: ' . $e->getMessage(), 'error');
    }
    // Return to same view (match view goes back to ring queue after scoring)
    if ($matchId) {
        $m = bracketMatchGet($matchId);
        $r = $m ? (int)$m['ring_number'] : 0;
        redirect(APP_URL . '/pages/event_referee.php?id=' . $id . ($r ? '&ring=' . $r : ''));
    }
    redirect($_SERVER['REQUEST_URI']);
}

renderHead('Referee · ' . $ev['title']);
$flash = getFlash();
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Referee · ' . $ev['title']); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <div style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.12);border:1px solid rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <?php if ($matchId):
    $m = bracketMatchGet($matchId);
    if (!$m || (int)$m['event_id'] !== $id) { echo '<p>Match not found.</p>'; goto endpage; }
    $isDone = $m['status'] === 'completed';
  ?>
    <!-- ── SCORE A SPECIFIC MATCH ─────────────────────────── -->
    <div style="margin-bottom:1rem"><a href="?id=<?= $id ?>&ring=<?= (int)$m['ring_number'] ?>" style="color:#8892b0;text-decoration:none;font-size:.9rem"><i class="fa-solid fa-arrow-left"></i> Πίσω στην ουρά τερέν <?= (int)$m['ring_number'] ?></a></div>

    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.5rem;margin-bottom:1rem">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.5rem">
        <div>
          <div style="font-size:.72rem;text-transform:uppercase;color:#e63946;font-weight:700;letter-spacing:.1em"><?= h($m['cat_name'] ?? '—') ?> · <?= h($m['round_label'] ?? '—') ?></div>
          <div style="color:#8892b0;font-size:.9rem;margin-top:.3rem">
            Τερέν <?= (int)$m['ring_number'] ?>
            <?php if ($m['scheduled_at']): ?> · <?= h(date('H:i', strtotime($m['scheduled_at']))) ?><?php endif; ?>
            · Match #<?= (int)$m['bracket_position'] ?>
          </div>
        </div>
        <div>
          <?php if ($isDone): ?>
            <span class="badge badge-paid">Ολοκληρώθηκε</span>
          <?php elseif ($m['status'] === 'live'): ?>
            <span class="badge" style="background:#e63946;color:#fff">🔴 LIVE</span>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
              <input type="hidden" name="_action" value="live_off">
              <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn-ghost btn-sm">Παύση live</button>
            </form>
          <?php else: ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
              <input type="hidden" name="_action" value="live_on">
              <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn-ghost btn-sm">▶ Έναρξη LIVE</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <form method="POST" id="scoreForm">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_action" value="score">
        <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">

        <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:1rem;align-items:center;margin:1.5rem 0;">
          <!-- RED -->
          <div style="background:#2a0d12;border:2px solid #e63946;border-radius:14px;padding:1.25rem;text-align:center">
            <div style="font-size:.72rem;text-transform:uppercase;color:#ff6b74;font-weight:700;letter-spacing:.1em;margin-bottom:.5rem">🟥 RED</div>
            <div style="font-size:1.1rem;color:#f0f2ff;font-weight:800;line-height:1.2"><?= h($m['red_name'] ?? '—') ?></div>
            <div style="color:#8892b0;font-size:.82rem;margin-top:.2rem"><?= h($m['red_school'] ?? '') ?></div>
            <input type="number" name="red_score" min="0" max="99" value="<?= (int)$m['red_score'] ?>" style="width:100%;font-size:5rem;font-weight:900;text-align:center;background:transparent;border:none;color:#e63946;font-family:monospace;padding:.5rem 0" <?= $isDone ? 'disabled' : '' ?>>
            <div style="display:flex;gap:.35rem;justify-content:center;flex-wrap:wrap">
              <button type="button" onclick="bumpScore('red_score', 1)" style="padding:.55rem 1rem;background:#e63946;color:#fff;border:none;border-radius:8px;font-weight:800;cursor:pointer;font-size:1.05rem" <?= $isDone ? 'disabled' : '' ?>>+1</button>
              <button type="button" onclick="bumpScore('red_score', 2)" style="padding:.55rem 1rem;background:#e63946;color:#fff;border:none;border-radius:8px;font-weight:800;cursor:pointer;font-size:1.05rem" <?= $isDone ? 'disabled' : '' ?>>+2</button>
              <button type="button" onclick="bumpScore('red_score', 3)" style="padding:.55rem 1rem;background:#e63946;color:#fff;border:none;border-radius:8px;font-weight:800;cursor:pointer;font-size:1.05rem" <?= $isDone ? 'disabled' : '' ?>>+3</button>
              <button type="button" onclick="bumpScore('red_score', -1)" style="padding:.55rem 1rem;background:#333;color:#fff;border:none;border-radius:8px;font-weight:800;cursor:pointer;font-size:1.05rem" <?= $isDone ? 'disabled' : '' ?>>−</button>
            </div>
          </div>

          <div style="font-size:1.8rem;color:#4a5270;font-weight:900">VS</div>

          <!-- BLUE -->
          <div style="background:#0d1a2e;border:2px solid #3b82f6;border-radius:14px;padding:1.25rem;text-align:center">
            <div style="font-size:.72rem;text-transform:uppercase;color:#6ea8ff;font-weight:700;letter-spacing:.1em;margin-bottom:.5rem">🟦 BLUE</div>
            <div style="font-size:1.1rem;color:#f0f2ff;font-weight:800;line-height:1.2"><?= h($m['blue_name'] ?? '—') ?></div>
            <div style="color:#8892b0;font-size:.82rem;margin-top:.2rem"><?= h($m['blue_school'] ?? '') ?></div>
            <input type="number" name="blue_score" min="0" max="99" value="<?= (int)$m['blue_score'] ?>" style="width:100%;font-size:5rem;font-weight:900;text-align:center;background:transparent;border:none;color:#3b82f6;font-family:monospace;padding:.5rem 0" <?= $isDone ? 'disabled' : '' ?>>
            <div style="display:flex;gap:.35rem;justify-content:center;flex-wrap:wrap">
              <button type="button" onclick="bumpScore('blue_score', 1)" style="padding:.55rem 1rem;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-weight:800;cursor:pointer;font-size:1.05rem" <?= $isDone ? 'disabled' : '' ?>>+1</button>
              <button type="button" onclick="bumpScore('blue_score', 2)" style="padding:.55rem 1rem;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-weight:800;cursor:pointer;font-size:1.05rem" <?= $isDone ? 'disabled' : '' ?>>+2</button>
              <button type="button" onclick="bumpScore('blue_score', 3)" style="padding:.55rem 1rem;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-weight:800;cursor:pointer;font-size:1.05rem" <?= $isDone ? 'disabled' : '' ?>>+3</button>
              <button type="button" onclick="bumpScore('blue_score', -1)" style="padding:.55rem 1rem;background:#333;color:#fff;border:none;border-radius:8px;font-weight:800;cursor:pointer;font-size:1.05rem" <?= $isDone ? 'disabled' : '' ?>>−</button>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-bottom:1rem">
          <label style="color:#c8cfe0;font-size:.85rem">
            Τύπος νίκης
            <select name="result_type" style="width:100%;padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff" <?= $isDone ? 'disabled' : '' ?>>
              <?php foreach (['points'=>'Πόντοι','ippon'=>'Ippon','waza'=>'Waza-ari','ko'=>'KO','dq'=>'Disqualification','walkover'=>'Walkover','draw'=>'Ισοπαλία'] as $v => $lbl): ?>
                <option value="<?= $v ?>" <?= $m['result_type']===$v?'selected':'' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label style="color:#c8cfe0;font-size:.85rem">
            Νικητής (μόνο για DQ / walkover)
            <select name="winner_id" style="width:100%;padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff" <?= $isDone ? 'disabled' : '' ?>>
              <option value="">(αυτόματα από τη βαθμολογία)</option>
              <?php if ($m['red_registration_id']): ?><option value="<?= (int)$m['red_registration_id'] ?>">RED — <?= h($m['red_name']) ?></option><?php endif; ?>
              <?php if ($m['blue_registration_id']): ?><option value="<?= (int)$m['blue_registration_id'] ?>">BLUE — <?= h($m['blue_name']) ?></option><?php endif; ?>
            </select>
          </label>
        </div>
        <label style="color:#c8cfe0;font-size:.85rem;display:block;margin-bottom:1rem">
          Σημειώσεις
          <input type="text" name="notes" maxlength="500" value="<?= h($m['notes'] ?? '') ?>" style="width:100%;padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff" <?= $isDone ? 'disabled' : '' ?>>
        </label>

        <?php if (!$isDone): ?>
          <button type="submit" class="btn btn-primary" style="width:100%;padding:1rem;font-size:1.1rem" onclick="return confirm('Οριστικοποίηση αποτελέσματος;')">
            <i class="fa-solid fa-check-double"></i> Οριστικοποίηση
          </button>
        <?php else: ?>
          <div style="text-align:center;padding:.85rem;background:rgba(45,198,83,.1);border:1px solid rgba(45,198,83,.3);border-radius:10px;color:#2dc653">
            ✓ Ολοκληρώθηκε — Νικητής: <strong><?= h(((int)$m['winner_registration_id'] === (int)$m['red_registration_id']) ? $m['red_name'] : $m['blue_name']) ?></strong>
          </div>
        <?php endif; ?>
      </form>
    </div>

  <?php elseif ($ring): ?>
    <!-- ── RING QUEUE ─────────────────────────────────────── -->
    <?php $matches = bracketMatchesForRing($id, $ring, 20); ?>
    <div style="margin-bottom:1rem"><a href="?id=<?= $id ?>" style="color:#8892b0;text-decoration:none;font-size:.9rem"><i class="fa-solid fa-arrow-left"></i> Όλα τα τερέν</a></div>

    <h2 style="margin:0 0 1rem;font-size:1.4rem;color:#f0f2ff">Τερέν <?= $ring ?> · Ουρά αγώνων</h2>

    <?php if (!$matches): ?>
      <p style="color:#8892b0">Δεν υπάρχουν εκκρεμείς αγώνες σε αυτό το τερέν.</p>
    <?php else: foreach ($matches as $i => $m):
      $isNext = ($i === 0);
    ?>
      <a href="?id=<?= $id ?>&match=<?= (int)$m['id'] ?>" style="display:block;background:<?= $isNext?'linear-gradient(135deg,#1a0d2e,#0d1017)':'#111520' ?>;border:<?= $isNext?'2px solid #e63946':'1px solid #1e2536' ?>;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:.7rem;text-decoration:none;color:inherit">
        <?php if ($isNext): ?><div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#e63946;font-weight:800;margin-bottom:.35rem">▶ ΕΠΟΜΕΝΟΣ</div><?php endif; ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem;flex-wrap:wrap">
          <div>
            <div style="color:#f0f2ff;font-size:1.05rem;font-weight:800"><?= h($m['red_name'] ?? '—') ?> <span style="color:#4a5270">vs</span> <?= h($m['blue_name'] ?? '—') ?></div>
            <div style="color:#8892b0;font-size:.85rem;margin-top:.25rem"><?= h($m['cat_name'] ?? '—') ?> · <?= h($m['round_label'] ?? '—') ?></div>
          </div>
          <div style="text-align:right">
            <?php if ($m['scheduled_at']): ?><div style="color:#f0a500;font-weight:700"><?= h(date('H:i', strtotime($m['scheduled_at']))) ?></div><?php endif; ?>
            <?php if ($m['status'] === 'live'): ?><span style="color:#e63946;font-weight:700">🔴 LIVE</span><?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; endif; ?>

  <?php else: ?>
    <!-- ── RING PICKER ────────────────────────────────────── -->
    <h2 style="margin:0 0 1rem;font-size:1.4rem;color:#f0f2ff">Επιλέξτε τερέν</h2>
    <p style="color:#8892b0;margin-bottom:1.25rem">Το event έχει <?= (int)$ev['ring_count'] ?> τερέν. Κάθε διαιτητής/κριτής ανοίγει τη σελίδα του δικού του.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem">
      <?php for ($i = 1; $i <= (int)$ev['ring_count']; $i++):
        // count pending on this ring
        $c = getDB()->prepare("SELECT COUNT(*) FROM event_matches WHERE event_id = ? AND ring_number = ? AND status IN ('scheduled','live')");
        $c->execute([$id, $i]);
        $pending = (int)$c->fetchColumn();
      ?>
        <a href="?id=<?= $id ?>&ring=<?= $i ?>" style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.5rem 1rem;text-align:center;text-decoration:none;color:inherit;transition:border-color .15s" onmouseover="this.style.borderColor='#e63946'" onmouseout="this.style.borderColor='#1e2536'">
          <div style="font-size:3rem;font-weight:900;color:#e63946;line-height:1">R<?= $i ?></div>
          <div style="color:#8892b0;font-size:.85rem;margin-top:.5rem"><?= $pending ?> εκκρεμείς</div>
        </a>
      <?php endfor; ?>
    </div>
    <div style="margin-top:1.5rem">
      <a href="<?= APP_URL ?>/events/display.php?slug=<?= h($ev['slug']) ?>" target="_blank" class="btn btn-ghost"><i class="fa-solid fa-tv"></i> Ανοιχτό venue display (fullscreen)</a>
    </div>
  <?php endif; ?>

  <?php endpage: ?>

</div>
</div>
</div>

<script>
function bumpScore(field, delta){
  var el = document.getElementsByName(field)[0];
  if (!el) return;
  var v = parseInt(el.value||'0', 10) + delta;
  if (v < 0) v = 0;
  el.value = v;
}
</script>
</body></html>
