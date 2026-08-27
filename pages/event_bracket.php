<?php
/**
 * pages/event_bracket.php — Bracket & schedule management per category
 * ============================================================
 *  GET  ?id=EVENT&cat=CATEGORY
 *   - Seeding UI (auto or manual)
 *   - "Δημιουργία bracket" button
 *   - Full bracket + pool view
 *   - Schedule generator (start time, slot, rest, rings)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events_bracket.php';

requireLogin();
$sid    = schoolId();
$userId = userId();
$id     = (int)($_GET['id']  ?? 0);
$catId  = (int)($_GET['cat'] ?? 0);
$ev     = eventGet($id);
if (!$ev || (int)$ev['organiser_school_id'] !== $sid) {
    flash('Δεν έχετε πρόσβαση.', 'error');
    redirect(APP_URL . '/pages/events.php');
}
$cat = $catId ? eventCategoryGet($catId) : null;
if (!$cat || (int)$cat['event_id'] !== $id) {
    flash('Άκυρη κατηγορία.', 'error');
    redirect(eventManageUrl($id) . '&tab=categories');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'seed_auto') {
            $mode = $_POST['mode'] ?? 'club_snake';
            $n = bracketAutoSeed($catId, $mode);
            flash("Έγινε αυτόματο seeding σε {$n} αθλητές.");
        }
        if ($action === 'seed_manual') {
            $order = (array)($_POST['order'] ?? []);
            $s = 1;
            foreach ($order as $regId) {
                bracketSetSeed((int)$regId, $catId, $s++);
            }
            flash('Το seeding αποθηκεύτηκε.');
        }
        if ($action === 'generate') {
            $n = bracketGenerate($id, $catId);
            flash("Δημιουργήθηκαν {$n} αγώνες.");
        }
        if ($action === 'reset') {
            bracketReset($id, $catId);
            flash('Το bracket διαγράφηκε.', 'info');
        }
        if ($action === 'schedule') {
            $start = new DateTime(($_POST['start_at'] ?? '') ?: 'now');
            $slot  = max(5, (int)($_POST['slot_min'] ?? 15));
            $rest  = max(0, (int)($_POST['rest_min'] ?? 20));
            $n = bracketSchedule($id, $catId, $start, $slot, $rest);
            flash("Προγραμματίστηκαν {$n} αγώνες.");
        }
        if ($action === 'publish') {
            bracketPublishResults($id, $catId);
            flash('Τα αποτελέσματα δημοσιεύτηκαν.');
        }
    } catch (Throwable $e) {
        flash('Σφάλμα: ' . $e->getMessage(), 'error');
    }
    redirect($_SERVER['REQUEST_URI']);
}

$regs      = bracketCategoryRegs($catId);
$allMatches= bracketFull($id, $catId);

// Split matches into rounds & pools
$byPool = []; $bracketList = [];
foreach ($allMatches as $m) {
    if ($m['pool_id']) $byPool[$m['pool_id']][] = $m;
    else               $bracketList[] = $m;
}
// Group bracket matches by round_label
$rounds = [];
foreach ($bracketList as $m) $rounds[$m['round_label'] ?: '—'][] = $m;

// ── XLSX export ────────────────────────────────────────────────
// GET ?id=X&cat=Y&export=xlsx → 3-sheet workbook: participants list,
// pool round-robins, bracket matches.
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/xlsx_writer.php';
    $xw = new XlsxWriter();

    // Sheet 1 — Λίστα συμμετεχόντων (with seed)
    $rows1 = [['Seed', 'Αθλητής', 'Σύλλογος', 'Πληρωμή', 'Κατάσταση']];
    foreach ($regs as $r) {
        $rows1[] = [
            (int)($r['seed'] ?? 0) ?: '',
            (string)($r['athlete_name'] ?? '—'),
            (string)($r['school_name']  ?? '—'),
            match ($r['payment_status'] ?? 'unpaid') {
                'verified'       => 'Πληρωμένο',
                'proof_uploaded' => 'Αποδεικτικό ανέβηκε',
                'waived'         => 'Απαλλαγή',
                'refunded'       => 'Επιστροφή',
                default          => 'Εκκρεμεί',
            },
            match ($r['status'] ?? 'pending') {
                'approved'    => 'Εγκεκριμένος',
                'checked_in'  => 'Παρών',
                'no_show'     => 'Απουσία',
                'disqualified'=> 'Αποκλείστηκε',
                default       => 'Σε αναμονή',
            },
        ];
    }
    $xw->addSheet('Συμμετέχοντες', $rows1, ['freezeHeader' => true]);

    // Sheet 2 — Pools
    $poolRows = [['Pool', 'Αγώνας #', 'Κόκκινος', 'Σύλλογος', 'Score',
                  'Μπλε', 'Σύλλογος', 'Νικητής', 'Τύπος', 'Ring', 'Ώρα']];
    foreach ($byPool as $poolId => $matches) {
        foreach ($matches as $m) {
            $winner = '—';
            if ((int)$m['winner_registration_id'] === (int)$m['red_registration_id'])  $winner = (string)($m['red_name'] ?? '');
            if ((int)$m['winner_registration_id'] === (int)$m['blue_registration_id']) $winner = (string)($m['blue_name'] ?? '');
            $poolRows[] = [
                'Pool ' . $poolId,
                (int)$m['bracket_position'],
                (string)($m['red_name']    ?? '—'),
                (string)($m['red_school']  ?? '—'),
                ($m['status']==='completed') ? ((int)$m['red_score'].' - '.(int)$m['blue_score']) : '—',
                (string)($m['blue_name']   ?? '—'),
                (string)($m['blue_school'] ?? '—'),
                $winner,
                (string)$m['result_type'],
                (int)$m['ring_number'],
                $m['scheduled_at'] ? date('d/m H:i', strtotime($m['scheduled_at'])) : '',
            ];
        }
    }
    $xw->addSheet('Pools', $poolRows, ['freezeHeader' => true]);

    // Sheet 3 — Bracket (elimination rounds)
    $bracketRows = [['Round', 'Θέση', 'Κόκκινος', 'Σύλλογος', 'Score',
                     'Μπλε', 'Σύλλογος', 'Νικητής', 'Τύπος', 'Ring', 'Ώρα']];
    foreach ($bracketList as $m) {
        $winner = '—';
        if ((int)$m['winner_registration_id'] === (int)$m['red_registration_id'])  $winner = (string)($m['red_name'] ?? '');
        if ((int)$m['winner_registration_id'] === (int)$m['blue_registration_id']) $winner = (string)($m['blue_name'] ?? '');
        $bracketRows[] = [
            (string)($m['round_label'] ?? '—'),
            (int)$m['bracket_position'],
            (string)($m['red_name']    ?? '(αναμονή)'),
            (string)($m['red_school']  ?? ''),
            ($m['status']==='completed') ? ((int)$m['red_score'].' - '.(int)$m['blue_score']) : '—',
            (string)($m['blue_name']   ?? '(αναμονή)'),
            (string)($m['blue_school'] ?? ''),
            $winner,
            (string)$m['result_type'],
            (int)$m['ring_number'],
            $m['scheduled_at'] ? date('d/m H:i', strtotime($m['scheduled_at'])) : '',
        ];
    }
    $xw->addSheet('Bracket', $bracketRows, ['freezeHeader' => true]);

    $slug = preg_replace('/[^a-zA-Z0-9_-]/', '-', $cat['name']);
    $xw->send('event-' . $id . '-cat-' . $catId . '-' . $slug . '.xlsx');
    exit;
}

renderHead('Bracket: ' . $cat['name']);
$flash = getFlash();
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Bracket · ' . $cat['name']); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <div style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.12);border:1px solid rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <div style="margin-bottom:1rem">
    <a href="<?= h(eventManageUrl($id)) ?>&tab=categories" style="color:#8892b0;text-decoration:none;font-size:.9rem">
      <i class="fa-solid fa-arrow-left"></i> Πίσω στις κατηγορίες
    </a>
  </div>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:1rem">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
      <div>
        <div style="font-size:.72rem;text-transform:uppercase;color:#e63946;font-weight:700;letter-spacing:.1em"><?= h($ev['title']) ?></div>
        <h1 style="margin:.25rem 0 .3rem;font-size:1.35rem;color:#f0f2ff"><?= h($cat['name']) ?></h1>
        <div style="color:#8892b0;font-size:.9rem">
          Μορφή: <strong style="color:#f0f2ff"><?= h(eventFormatLabel($cat['format'] ?? '')) ?></strong> ·
          Όμιλος: <strong style="color:#f0f2ff"><?= (int)$cat['pool_size'] ?></strong> αθλητές ·
          Συμμετέχοντες: <strong style="color:#f0f2ff"><?= count($regs) ?></strong>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="?id=<?= $id ?>&cat=<?= $catId ?>&export=xlsx"
           style="display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.15rem;border-radius:10px;
                  background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;font-weight:800;font-size:.92rem;
                  text-decoration:none;min-height:44px;box-shadow:0 6px 18px -6px rgba(34,197,94,.55)"
           title="Λίστα συμμετεχόντων + Pools + Bracket σε ένα αρχείο">
          <i class="fa-solid fa-file-excel"></i> Εξαγωγή XLSX
        </a>
        <button type="button" onclick="window.print()"
                style="display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.15rem;border-radius:10px;
                       background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;font-weight:800;font-size:.92rem;
                       border:none;cursor:pointer;min-height:44px;font-family:inherit;box-shadow:0 6px 18px -6px rgba(59,130,246,.55)">
          <i class="fa-solid fa-print"></i> Εκτύπωση
        </button>
      </div>
    </div>
  </div>

  <!-- ── SEEDING ─────────────────────────────────────────── -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:1rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem;gap:.5rem;flex-wrap:wrap">
      <h3 style="margin:0;color:#e63946;font-size:.95rem;text-transform:uppercase;letter-spacing:.08em">1. Seeding</h3>
      <form method="POST" style="display:flex;gap:.4rem">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_action" value="seed_auto">
        <select name="mode" style="padding:.5rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
          <option value="club_snake">Snake (spread clubs)</option>
          <option value="random">Τυχαία</option>
          <option value="belt">Κατά ζώνη</option>
        </select>
        <button class="btn btn-ghost btn-sm"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto-seed</button>
      </form>
    </div>
    <?php if (!$regs): ?>
      <p style="color:#8892b0">Καμία εγκεκριμένη εγγραφή ακόμα.</p>
    <?php else: ?>
      <form method="POST" id="seedForm">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_action" value="seed_manual">
        <ol id="seedList" style="list-style:decimal;padding-left:2rem;margin:0">
          <?php foreach ($regs as $r): ?>
            <li style="padding:.55rem .85rem;background:#0d1017;border:1px solid #1e2536;border-radius:8px;margin-bottom:.3rem;color:#f0f2ff;cursor:grab" draggable="true" data-id="<?= (int)$r['id'] ?>">
              <input type="hidden" name="order[]" value="<?= (int)$r['id'] ?>">
              <strong><?= h($r['athlete_name']) ?></strong>
              <span style="color:#6b7494;font-size:.85rem">· <?= h($r['school_name'] ?? '—') ?><?php if ($r['belt']): ?> · <?= h($r['belt']) ?><?php endif; ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
        <button class="btn btn-ghost btn-sm" style="margin-top:.75rem"><i class="fa-solid fa-save"></i> Αποθήκευση σειράς</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- ── GENERATE ────────────────────────────────────────── -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:1rem">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;justify-content:space-between">
      <h3 style="margin:0;color:#e63946;font-size:.95rem;text-transform:uppercase;letter-spacing:.08em">2. Bracket · <?= count($allMatches) ?> αγώνες</h3>
      <div style="display:flex;gap:.4rem">
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
          <input type="hidden" name="_action" value="generate">
          <button class="btn btn-primary btn-sm" onclick="return confirm('Δημιουργία (θα σβήσει προηγούμενο μη-ολοκληρωμένο bracket).')">
            <i class="fa-solid fa-diagram-project"></i> Δημιουργία bracket
          </button>
        </form>
        <?php if ($allMatches): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="_action" value="reset">
            <button class="btn btn-ghost btn-sm" style="color:#e63946" onclick="return confirm('Διαγραφή bracket;')">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── SCHEDULER ───────────────────────────────────────── -->
  <?php if ($allMatches): ?>
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:1rem">
    <h3 style="margin:0 0 .75rem;color:#e63946;font-size:.95rem;text-transform:uppercase;letter-spacing:.08em">3. Πρόγραμμα (τερέν & ώρες)</h3>
    <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.55rem;align-items:end">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="_action" value="schedule">
      <label style="color:#c8cfe0;font-size:.82rem">
        Έναρξη
        <input type="datetime-local" name="start_at" value="<?= h(date('Y-m-d\T09:00', strtotime($ev['starts_at'] ?: 'now'))) ?>" style="width:100%;padding:.55rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
      </label>
      <label style="color:#c8cfe0;font-size:.82rem">
        Slot / αγώνα (λεπτά)
        <input type="number" min="5" value="15" name="slot_min" style="width:100%;padding:.55rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
      </label>
      <label style="color:#c8cfe0;font-size:.82rem">
        Ανάπαυση αθλητή (λεπτά)
        <input type="number" min="0" value="20" name="rest_min" style="width:100%;padding:.55rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
      </label>
      <button class="btn btn-primary btn-sm"><i class="fa-solid fa-calendar-check"></i> Auto-schedule</button>
    </form>
    <p style="color:#6b7494;font-size:.8rem;margin:.55rem 0 0">
      Χρησιμοποιεί <?= (int)$ev['ring_count'] ?> τερέν (από το event). Θα σκεπάσει προηγούμενες τιμές πλην των ολοκληρωμένων αγώνων.
    </p>
  </div>
  <?php endif; ?>

  <!-- ── POOLS ───────────────────────────────────────────── -->
  <?php if ($byPool): foreach ($byPool as $poolId => $matches):
    $st = getDB()->prepare("SELECT name FROM event_pools WHERE id = ?");
    $st->execute([$poolId]);
    $poolName = $st->fetchColumn() ?: 'Pool';
    $standings = bracketPoolStandings((int)$poolId);
  ?>
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:1rem">
      <h3 style="margin:0 0 .75rem;color:#e63946;font-size:.95rem"><?= h($poolName) ?></h3>
      <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:1rem">
        <div>
          <table style="width:100%;border-collapse:collapse;color:#c8cfe0;font-size:.85rem">
            <thead><tr style="color:#6b7494;text-transform:uppercase;font-size:.7rem;letter-spacing:.08em">
              <th style="text-align:left;padding:.4rem 0">Αγώνας</th><th>Score</th><th style="text-align:right">Ring</th>
            </tr></thead>
            <tbody>
              <?php foreach ($matches as $m): ?>
                <tr style="border-top:1px solid #1e2536">
                  <td style="padding:.5rem 0;color:#f0f2ff">
                    <?= h($m['red_name'] ?? '—') ?> <span style="color:#6b7494">vs</span> <?= h($m['blue_name'] ?? '—') ?>
                  </td>
                  <td style="color:<?= $m['status']==='completed'?'#2dc653':'#6b7494' ?>"><?= $m['status']==='completed' ? $m['red_score'].'-'.$m['blue_score'] : '—' ?></td>
                  <td style="text-align:right;color:#8892b0">R<?= (int)$m['ring_number'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div>
          <div style="font-size:.7rem;text-transform:uppercase;color:#6b7494;font-weight:700;margin-bottom:.35rem">Κατάταξη</div>
          <table style="width:100%;border-collapse:collapse;color:#c8cfe0;font-size:.85rem">
            <thead><tr style="color:#6b7494;font-size:.7rem"><th style="text-align:left">#</th><th style="text-align:left">Αθλητής</th><th>W-L</th><th>±</th></tr></thead>
            <tbody>
              <?php foreach ($standings as $i => $s): ?>
                <tr style="border-top:1px solid #1e2536">
                  <td style="padding:.4rem 0"><?= $i+1 ?></td>
                  <td><?= h($s['athlete_name'] ?? '—') ?></td>
                  <td><?= (int)$s['wins'] ?>-<?= (int)$s['losses'] ?></td>
                  <td style="color:<?= $s['diff']>=0?'#2dc653':'#e63946' ?>"><?= $s['diff']>0?'+':'' ?><?= (int)$s['diff'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <!-- ── BRACKET ROUNDS ──────────────────────────────────── -->
  <?php if ($rounds): ?>
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:1rem;overflow-x:auto">
      <h3 style="margin:0 0 .75rem;color:#e63946;font-size:.95rem;text-transform:uppercase;letter-spacing:.08em">Bracket</h3>
      <div style="display:flex;gap:1.5rem;min-width:600px">
        <?php foreach ($rounds as $lbl => $matches): ?>
          <div style="min-width:230px">
            <div style="font-size:.72rem;text-transform:uppercase;color:#6b7494;font-weight:700;margin-bottom:.5rem;letter-spacing:.08em"><?= h($lbl) ?></div>
            <?php foreach ($matches as $m):
              $done = $m['status'] === 'completed';
              $winId = (int)$m['winner_registration_id'];
              $redW  = $done && $winId === (int)$m['red_registration_id'];
              $blueW = $done && $winId === (int)$m['blue_registration_id'];
            ?>
              <div style="display:block;background:#0d1017;border:1px solid #1e2536;border-radius:8px;padding:.55rem .75rem;margin-bottom:.6rem">
                <div style="display:flex;justify-content:space-between;color:<?= $redW?'#2dc653':($m['red_name']?'#f0f2ff':'#4a5270') ?>;font-weight:<?= $redW?800:600 ?>;font-size:.85rem">
                  <span><?= h($m['red_name'] ?? '(αναμονή)') ?></span>
                  <span><?= $done ? (int)$m['red_score'] : '' ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;color:<?= $blueW?'#2dc653':($m['blue_name']?'#f0f2ff':'#4a5270') ?>;font-weight:<?= $blueW?800:600 ?>;font-size:.85rem;margin-top:.2rem">
                  <span><?= h($m['blue_name'] ?? '(αναμονή)') ?></span>
                  <span><?= $done ? (int)$m['blue_score'] : '' ?></span>
                </div>
                <?php if ($m['scheduled_at']): ?>
                  <div style="color:#6b7494;font-size:.7rem;margin-top:.35rem"><i class="fa-regular fa-clock"></i> <?= h(date('H:i', strtotime($m['scheduled_at']))) ?> · R<?= (int)$m['ring_number'] ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ── PUBLISH ──────────────────────────────────────────── -->
  <?php if ($rounds): ?>
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem">
      <h3 style="margin:0 0 .5rem;color:#e63946;font-size:.95rem;text-transform:uppercase;letter-spacing:.08em">4. Δημοσίευση αποτελεσμάτων</h3>
      <p style="color:#8892b0;font-size:.9rem;margin-bottom:.85rem">Θα υπολογίσει μετάλλια (χρυσό, ασημένιο, δύο χάλκινα) από τον τελικό και τους ημιτελικούς και θα τα κάνει δημόσια.</p>
      <form method="POST" onsubmit="return confirm('Δημοσίευση αποτελεσμάτων;')">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_action" value="publish">
        <button class="btn btn-primary"><i class="fa-solid fa-medal"></i> Δημοσίευση</button>
      </form>
    </div>
  <?php endif; ?>

</div>
</div>
</div>

<script>
// Drag-and-drop reordering of the seed list
(function(){
  var list = document.getElementById('seedList');
  if (!list) return;
  var dragEl = null;
  list.querySelectorAll('li').forEach(function(li){
    li.addEventListener('dragstart', function(e){ dragEl = li; li.style.opacity = '.4'; });
    li.addEventListener('dragend', function(){ if (dragEl) dragEl.style.opacity = '1'; dragEl = null; });
    li.addEventListener('dragover', function(e){ e.preventDefault(); });
    li.addEventListener('drop', function(e){
      e.preventDefault();
      if (!dragEl || dragEl === li) return;
      var rect = li.getBoundingClientRect();
      if (e.clientY < rect.top + rect.height/2) li.parentNode.insertBefore(dragEl, li);
      else li.parentNode.insertBefore(dragEl, li.nextSibling);
    });
  });
})();
</script>
</body></html>
