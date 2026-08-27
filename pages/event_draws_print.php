<?php
/**
 * pages/event_draws_print.php — Printable draws (Κληρώσεις) for an event
 * ============================================================
 * One page per category, ELOT-style: header + participants list + a
 * simple matches grid (pool round-robin or single-elim brackets).
 * Print-clean layout (black on white). No sidebar, no chrome.
 *
 * URL:  ?id=EVENT
 *       ?id=EVENT&cat=CATEGORY  (single-category mode)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';
require_once __DIR__ . '/../includes/events_bracket.php';

requireLogin();

$sid = schoolId();
$id  = (int)($_GET['id']  ?? 0);
$ev  = eventGet($id);
if (!$ev || (int)$ev['organiser_school_id'] !== $sid) {
    http_response_code(403);
    echo 'Δεν έχετε πρόσβαση.';
    exit;
}

$onlyCatId = (int)($_GET['cat'] ?? 0);
$allCats   = eventCategories($id);
$cats      = $onlyCatId
    ? array_filter($allCats, fn($c) => (int)$c['id'] === $onlyCatId)
    : $allCats;

$evDate = $ev['starts_at']
    ? date('d/m/Y', strtotime($ev['starts_at']))
    : '';
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<title>Κληρώσεις — <?= h($ev['title']) ?></title>
<style>
  /* Screen preview — mimics a paper look so the operator sees roughly
     what will come out of the printer even before hitting Ctrl+P. */
  * { box-sizing: border-box; }
  body {
    margin: 0;
    padding: 20px;
    background: #dcdcdc;
    color: #000;
    font-family: "DejaVu Sans", Arial, Helvetica, sans-serif;
    font-size: 10pt;
    line-height: 1.35;
  }
  .toolbar {
    max-width: 800px; margin: 0 auto 20px; text-align: center;
    display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;
  }
  .toolbar button, .toolbar a {
    padding: 10px 18px; border-radius: 8px; font-weight: 700;
    text-decoration: none; font-family: inherit; font-size: 13px;
    cursor: pointer; border: none;
  }
  .toolbar .btn-print { background: #2563eb; color: #fff; }
  .toolbar .btn-back  { background: #ffffff; color: #333; border: 1px solid #999; }

  .sheet {
    background: #fff;
    max-width: 800px;
    margin: 0 auto 20px;
    padding: 20mm 18mm 16mm;
    box-shadow: 0 2px 10px rgba(0,0,0,.15);
    page-break-after: always;
    min-height: 250mm;
  }
  .sheet:last-child { page-break-after: auto; }
  .sheet-head {
    border-bottom: 2px solid #000;
    padding-bottom: 8px;
    margin-bottom: 14px;
  }
  .sheet-head .brand {
    font-size: 8pt; color: #555;
    text-transform: uppercase; letter-spacing: .06em;
  }
  .sheet-head h1 {
    margin: 4px 0 2px;
    font-size: 14pt;
    letter-spacing: .01em;
  }
  .sheet-head .cat {
    font-size: 11pt; font-weight: 700; margin-top: 4px;
  }
  .sheet-head .meta {
    font-size: 9pt; color: #444; margin-top: 4px;
    display: flex; gap: 14px; flex-wrap: wrap;
  }
  .section-title {
    margin: 14px 0 6px;
    font-size: 10pt; font-weight: 700;
    background: #eee; padding: 5px 8px; border-left: 3px solid #000;
  }
  table {
    width: 100%; border-collapse: collapse; font-size: 9.5pt;
    margin-bottom: 10px;
  }
  thead th {
    background: #eee !important; color: #000; font-weight: 700;
    border: .5pt solid #666; padding: 5px 8px; text-align: left;
    text-transform: none; letter-spacing: 0;
  }
  tbody td {
    background: #fff !important; color: #000;
    border: .5pt solid #999; padding: 4px 8px;
    vertical-align: top;
  }
  .col-seed  { width: 40px; text-align: center; font-weight: 700; }
  .col-num   { width: 40px; text-align: center; }
  .col-count { text-align: right; }

  .grid-hd { display:flex; justify-content:space-between; margin-top:12px; align-items:baseline }
  .grid-hd .lbl { font-weight:700; font-size:10pt }
  .grid-hd .cnt { color:#555; font-size:9pt }

  .matches-grid { border:.5pt solid #000; }
  .match-row {
    display: grid;
    grid-template-columns: 44px 1fr 60px;
    border-bottom: .5pt solid #999;
  }
  .match-row:last-child { border-bottom: none; }
  .m-num { background:#eee; color:#000; font-weight:700; text-align:center; padding:5px; border-right:.5pt solid #999; }
  .m-body { padding: 4px 8px; }
  .m-body .m-athlete { display:flex; justify-content:space-between; gap:8px; padding:2px 0; }
  .m-body .m-athlete .name { font-weight:600 }
  .m-body .m-athlete .school { color:#555; font-size:8.5pt }
  .m-body .m-vs { color:#999; font-size:7.5pt; text-align:center; padding:1px 0; }
  .m-side { padding:6px 8px; text-align:center; color:#555; font-size:8.5pt; border-left:.5pt solid #999; }

  .footer {
    margin-top: 18px; padding-top: 8px;
    border-top: 1px dashed #999;
    display: flex; justify-content: space-between;
    color: #666; font-size: 8pt;
  }
  .empty {
    padding: 20mm; text-align: center; color: #666; font-style: italic;
  }

  @media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none !important; }
    .sheet { box-shadow: none; margin: 0; max-width: none; padding: 12mm; min-height: 0; }
    @page { size: A4; margin: 10mm 10mm 12mm 10mm; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <a href="<?= APP_URL ?>/pages/event_manage.php?id=<?= $id ?>&tab=categories" class="btn-back">← Πίσω</a>
  <button type="button" class="btn-print" onclick="window.print()">🖨 Εκτύπωση</button>
  <?php if ($onlyCatId): ?>
    <a href="?id=<?= $id ?>" class="btn-back">Όλες οι κατηγορίες</a>
  <?php endif; ?>
</div>

<?php if (!$cats): ?>
  <div class="sheet"><div class="empty">Δεν έχουν οριστεί κατηγορίες ακόμη.</div></div>
<?php else: foreach ($cats as $cat):
    $regs    = bracketCategoryRegs((int)$cat['id']);
    $matches = bracketFull($id, (int)$cat['id']);
    // Split matches into pools + bracket rounds
    $byPool  = [];
    $bracket = [];
    foreach ($matches as $m) {
        if ($m['pool_id']) $byPool[$m['pool_id']][] = $m;
        else               $bracket[] = $m;
    }
    // Group bracket matches by round label for a clean display
    $rounds = [];
    foreach ($bracket as $m) $rounds[$m['round_label'] ?: 'Αγώνες'][] = $m;
?>
  <div class="sheet">
    <div class="sheet-head">
      <div class="brand">MAster · Κληρώσεις Πρωταθλήματος</div>
      <h1><?= h($ev['title']) ?></h1>
      <div class="cat">Κατηγορία: <?= h($cat['name']) ?></div>
      <div class="meta">
        <?php if ($evDate): ?><span>Ημερομηνία: <?= h($evDate) ?></span><?php endif; ?>
        <span>Μορφή: <?= h(eventFormatLabel($cat['format'] ?? '')) ?></span>
        <?php if (!empty($cat['min_age']) || !empty($cat['max_age'])): ?>
          <span>Ηλικίες: <?= (int)($cat['min_age'] ?? 0) ?>–<?= (int)($cat['max_age'] ?? 0) ?></span>
        <?php endif; ?>
        <?php if (!empty($cat['min_weight']) || !empty($cat['max_weight'])): ?>
          <span>Βάρος: <?= number_format((float)($cat['min_weight'] ?? 0), 1, ',', '') ?>–<?= number_format((float)($cat['max_weight'] ?? 0), 1, ',', '') ?> kg</span>
        <?php endif; ?>
        <?php $genderLbl = ['M'=>'Άνδρες','F'=>'Γυναίκες','MX'=>'Μικτό'][$cat['gender']] ?? $cat['gender']; ?>
        <span>Φύλο: <?= h($genderLbl) ?></span>
      </div>
    </div>

    <!-- Athletes list -->
    <div class="section-title">Λίστα συμμετεχόντων (<?= count($regs) ?>)</div>
    <?php if (!$regs): ?>
      <div class="empty" style="padding:8mm">Δεν υπάρχουν εγκεκριμένοι συμμετέχοντες.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th class="col-seed">Seed</th>
            <th>Αθλητής</th>
            <th>Σύλλογος</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($regs as $i => $r): ?>
          <tr>
            <td class="col-seed"><?= $r['seed'] ? (int)$r['seed'] : ($i + 1) ?></td>
            <td><?= h($r['athlete_name'] ?? '—') ?></td>
            <td><?= h($r['school_name']  ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Pools -->
    <?php if ($byPool): ?>
      <?php foreach ($byPool as $poolId => $poolMatches): ?>
        <div class="grid-hd">
          <div class="lbl">Όμιλος <?= (int)$poolId ?></div>
          <div class="cnt"><?= count($poolMatches) ?> αγώνες</div>
        </div>
        <div class="matches-grid">
          <?php foreach ($poolMatches as $mi => $m): ?>
            <div class="match-row">
              <div class="m-num"><?= (int)$m['bracket_position'] ?: $mi + 1 ?></div>
              <div class="m-body">
                <div class="m-athlete">
                  <span class="name"><?= h($m['red_name']  ?? '(αναμονή)') ?></span>
                  <span class="school"><?= h($m['red_school'] ?? '') ?></span>
                </div>
                <div class="m-vs">— vs —</div>
                <div class="m-athlete">
                  <span class="name"><?= h($m['blue_name'] ?? '(αναμονή)') ?></span>
                  <span class="school"><?= h($m['blue_school'] ?? '') ?></span>
                </div>
              </div>
              <div class="m-side">
                <?= h($m['ring_number'] ? 'T' . (int)$m['ring_number'] : '') ?>
                <?= h($m['scheduled_at'] ? "\n" . date('H:i', strtotime($m['scheduled_at'])) : '') ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Bracket matches -->
    <?php if ($bracket): ?>
      <?php foreach ($rounds as $roundLbl => $roundMatches): ?>
        <div class="grid-hd">
          <div class="lbl"><?= h($roundLbl) ?></div>
          <div class="cnt"><?= count($roundMatches) ?> αγώνες</div>
        </div>
        <div class="matches-grid">
          <?php foreach ($roundMatches as $mi => $m): ?>
            <div class="match-row">
              <div class="m-num"><?= (int)$m['bracket_position'] ?: $mi + 1 ?></div>
              <div class="m-body">
                <div class="m-athlete">
                  <span class="name"><?= h($m['red_name']  ?? '(αναμονή)') ?></span>
                  <span class="school"><?= h($m['red_school'] ?? '') ?></span>
                </div>
                <div class="m-vs">— vs —</div>
                <div class="m-athlete">
                  <span class="name"><?= h($m['blue_name'] ?? '(αναμονή)') ?></span>
                  <span class="school"><?= h($m['blue_school'] ?? '') ?></span>
                </div>
              </div>
              <div class="m-side">
                <?= h($m['ring_number'] ? 'T' . (int)$m['ring_number'] : '') ?>
                <?= h($m['scheduled_at'] ? "\n" . date('H:i', strtotime($m['scheduled_at'])) : '') ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!$byPool && !$bracket): ?>
      <div class="empty" style="padding:8mm">Δεν έχουν δημιουργηθεί αγώνες ακόμη — πηγαίνετε στην κατηγορία και πατήστε <em>Δημιουργία Κληρώσεων</em>.</div>
    <?php endif; ?>

    <div class="footer">
      <span>MAster — <?= h($ev['title']) ?></span>
      <span>Εκτύπωση: <?= date('d/m/Y H:i') ?></span>
    </div>
  </div>
<?php endforeach; endif; ?>

</body>
</html>
