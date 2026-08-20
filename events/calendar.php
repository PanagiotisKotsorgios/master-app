<?php
/**
 * events/calendar.php — Monthly calendar of all public events
 * ============================================================
 * Public (no login). Query params: ?y=YYYY&m=MM (defaults to current month).
 * Shows a Mon–Sun grid with event chips inside each day cell.
 * Chips link to the event's public page.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

// ── Resolve month ────────────────────────────────────────
$year  = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$firstOfMonth = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth  = (int)date('t', $firstOfMonth);
// PHP's `w` returns 0=Sunday .. 6=Saturday. We want Mon=0..Sun=6.
$firstDow     = (int)date('w', $firstOfMonth);
$offsetMon    = ($firstDow + 6) % 7;

// Previous / next month links
$prev = ['y' => $year, 'm' => $month - 1];
$next = ['y' => $year, 'm' => $month + 1];
if ($prev['m'] < 1)  { $prev['m'] = 12; $prev['y']--; }
if ($next['m'] > 12) { $next['m'] = 1;  $next['y']++; }

$monthNamesGr = [
    '', 'Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος',
    'Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος',
];
$monthLabel = $monthNamesGr[$month] . ' ' . $year;

// ── Load events for this month ─────────────────────────
$events = eventsForMonth($year, $month);

// Bucket events into day-of-month arrays. A multi-day event is placed on
// every day it overlaps in the current month, so it appears across the row.
$byDay = array_fill(1, $daysInMonth, []);
$monthStartTs = $firstOfMonth;
$monthEndTs   = mktime(23, 59, 59, $month, $daysInMonth, $year);

foreach ($events as $ev) {
    $startTs = $ev['starts_at'] ? strtotime($ev['starts_at']) : null;
    $endTs   = $ev['ends_at']   ? strtotime($ev['ends_at'])   : $startTs;
    if (!$startTs) continue;

    // Clip to visible month
    $from = max($startTs, $monthStartTs);
    $to   = min($endTs ?? $startTs, $monthEndTs);
    for ($t = strtotime(date('Y-m-d', $from)); $t <= $to; $t += 86400) {
        if ((int)date('n', $t) !== $month) continue;
        $d = (int)date('j', $t);
        if (!isset($byDay[$d])) continue;
        $byDay[$d][] = $ev;
    }
}

$typeLabelsGr = [
    'championship' => 'Πρωτάθλημα',
    'friendly'     => 'Φιλικός',
    'camp'         => 'Camp',
    'seminar'      => 'Σεμινάριο',
    'meeting'      => 'Συνάντηση',
    'exam'         => 'Εξέταση',
];

$today = date('Y-n-j');

$metaTitle = 'Ημερολόγιο Διοργανώσεων — ' . $monthLabel . ' | MAster';
$metaDesc  = 'Μηνιαίο ημερολόγιο για πρωταθλήματα, φιλικούς αγώνες, camps και σεμινάρια πολεμικών τεχνών στην Ελλάδα.';
?><!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($metaTitle) ?></title>
<meta name="description" content="<?= h($metaDesc) ?>">
<meta property="og:title" content="<?= h($metaTitle) ?>">
<meta property="og:description" content="<?= h($metaDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= h(APP_URL) ?>/events/calendar.php">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;min-height:100vh}
  a{color:inherit;text-decoration:none}

  .top{
    position:sticky;top:0;z-index:10;
    background:rgba(7,9,15,.9);backdrop-filter:blur(10px);
    border-bottom:1px solid #1e2536;
    padding:1rem 1.25rem;
  }
  .top-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
  .top-title{font-size:1.1rem;font-weight:800;display:flex;align-items:center;gap:.6rem}
  .top-title i{color:#e63946}
  .top-actions{display:flex;gap:.5rem;flex-wrap:wrap}
  .top-actions a{
    padding:.5rem .85rem;border-radius:8px;border:1px solid rgba(255,255,255,.1);
    background:rgba(255,255,255,.03);color:#c9cee1;font-size:.85rem;font-weight:600;
    display:inline-flex;align-items:center;gap:.4rem;transition:all .18s;
  }
  .top-actions a:hover{border-color:rgba(230,57,70,.4);color:#fff}
  .top-actions a.active{background:linear-gradient(135deg,#e63946,#c72832);border-color:transparent;color:#fff}

  .wrap{max-width:1200px;margin:0 auto;padding:1.5rem 1.25rem 3rem}

  .cal-head{
    display:flex;align-items:center;justify-content:space-between;
    gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap;
  }
  .cal-month{font-size:clamp(1.5rem,4vw,2.2rem);font-weight:800;letter-spacing:-.02em}
  .cal-nav{display:flex;gap:.4rem;align-items:center}
  .cal-nav a, .cal-nav button{
    background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);color:#e3e6f2;
    width:40px;height:40px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;
    cursor:pointer;font-family:inherit;font-size:1rem;transition:all .18s;
  }
  .cal-nav a:hover, .cal-nav button:hover{
    background:rgba(230,57,70,.15);border-color:rgba(230,57,70,.4);color:#fff;
  }
  .cal-nav .today-btn{width:auto;padding:0 .95rem;font-size:.85rem;font-weight:700}

  .cal-dow{
    display:grid;grid-template-columns:repeat(7,minmax(0,1fr));
    gap:6px;margin-bottom:6px;
  }
  .cal-dow div{
    text-align:center;font-size:.7rem;font-weight:800;
    text-transform:uppercase;letter-spacing:.12em;color:#6b7494;
    padding:.4rem 0;
  }

  .cal-grid{
    display:grid;grid-template-columns:repeat(7,minmax(0,1fr));
    gap:6px;
  }
  .cell{
    background:linear-gradient(180deg,rgba(19,23,34,.7),rgba(13,16,23,.7));
    border:1px solid rgba(255,255,255,.06);border-radius:12px;
    min-height:120px;padding:.55rem;
    display:flex;flex-direction:column;gap:.35rem;
    transition:border-color .18s ease;
  }
  .cell.empty{background:transparent;border-color:rgba(255,255,255,.03);min-height:0}
  .cell:not(.empty):hover{border-color:rgba(230,57,70,.28)}
  .cell.today{border-color:rgba(230,57,70,.55);box-shadow:0 0 0 3px rgba(230,57,70,.08)}
  .cell.weekend{background:linear-gradient(180deg,rgba(13,16,23,.6),rgba(9,11,18,.6))}

  .cell-day{
    display:flex;align-items:center;justify-content:space-between;
    font-size:.85rem;font-weight:700;color:#9199b6;
  }
  .cell.today .cell-day{color:#fff}
  .cell.today .cell-day .day-num{
    background:#e63946;color:#fff;
    min-width:22px;height:22px;border-radius:50%;
    display:inline-flex;align-items:center;justify-content:center;
    font-size:.75rem;
  }
  .cell-count{font-size:.65rem;color:#6b7494;font-weight:600}

  .chip{
    display:block;padding:.28rem .5rem;border-radius:6px;
    background:linear-gradient(135deg,rgba(230,57,70,.18),rgba(230,57,70,.08));
    border:1px solid rgba(230,57,70,.28);color:#ffe6e8;
    font-size:.72rem;font-weight:700;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    transition:transform .15s ease, background .18s ease;
  }
  .chip:hover{background:linear-gradient(135deg,rgba(230,57,70,.32),rgba(230,57,70,.15));transform:translateX(1px)}
  .chip.type-camp     {background:linear-gradient(135deg,rgba(240,165,0,.18),rgba(240,165,0,.08));border-color:rgba(240,165,0,.3);color:#fff2c9}
  .chip.type-seminar  {background:linear-gradient(135deg,rgba(78,132,255,.18),rgba(78,132,255,.08));border-color:rgba(78,132,255,.3);color:#dbe6ff}
  .chip.type-meeting  {background:linear-gradient(135deg,rgba(155,155,255,.14),rgba(155,155,255,.06));border-color:rgba(155,155,255,.28);color:#dcdcff}
  .chip.type-exam     {background:linear-gradient(135deg,rgba(45,198,83,.16),rgba(45,198,83,.06));border-color:rgba(45,198,83,.28);color:#d5ffd8}

  .chip-more{
    font-size:.68rem;color:#8892b0;padding:.15rem .4rem;
    background:transparent;border:1px dashed rgba(255,255,255,.15);border-radius:6px;
    text-align:center;
  }

  .legend{
    display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1.25rem;font-size:.75rem;color:#8892b0;
    padding:.75rem 1rem;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.05);
    border-radius:10px;
  }
  .legend span{display:inline-flex;align-items:center;gap:.4rem}
  .legend i{
    width:10px;height:10px;border-radius:3px;display:inline-block;
  }

  .empty-state{
    grid-column:1 / -1;
    padding:3rem 1.5rem;text-align:center;color:#6b7494;
    background:rgba(255,255,255,.02);border:1px dashed rgba(255,255,255,.08);border-radius:12px;
  }

  @media (max-width:820px){
    .cell{min-height:80px;padding:.4rem}
    .cell-day{font-size:.75rem}
    .chip{font-size:.66rem;padding:.2rem .4rem}
  }
  @media (max-width:560px){
    .wrap{padding:1rem .6rem 2.5rem}
    .cal-grid, .cal-dow{gap:3px}
    .cell{min-height:64px;padding:.28rem;border-radius:8px}
    .cell-count{display:none}
    .chip{font-size:.6rem;padding:.14rem .3rem;border-radius:5px}
    .cal-dow div{font-size:.6rem;letter-spacing:.08em}
  }
</style>
<?php include __DIR__ . "/../includes/prelogin_polish.php"; ?>
</head>
<body>

<div class="top">
  <div class="top-inner">
    <div class="top-title">
      <i class="fas fa-calendar-days"></i>
      Διοργανώσεις — Ημερολόγιο
    </div>
    <div class="top-actions">
      <a href="<?= APP_URL ?>/events/"><i class="fas fa-list"></i> Λίστα</a>
      <a href="<?= APP_URL ?>/events/calendar.php" class="active"><i class="fas fa-calendar"></i> Ημερολόγιο</a>
      <a href="<?= APP_URL ?>/events/athletes.php"><i class="fas fa-magnifying-glass"></i> Αθλητές</a>
      <a href="<?= APP_URL ?>/"><i class="fas fa-house"></i> Αρχική</a>
    </div>
  </div>
</div>

<div class="wrap">
  <div class="cal-head">
    <h1 class="cal-month"><?= h($monthLabel) ?></h1>
    <div class="cal-nav">
      <a href="?y=<?= $prev['y'] ?>&m=<?= $prev['m'] ?>" title="Προηγούμενος μήνας" aria-label="Προηγούμενος μήνας"><i class="fas fa-chevron-left"></i></a>
      <a href="<?= APP_URL ?>/events/calendar.php" class="today-btn" title="Πήγαινε στον τρέχοντα μήνα">Σήμερα</a>
      <a href="?y=<?= $next['y'] ?>&m=<?= $next['m'] ?>" title="Επόμενος μήνας" aria-label="Επόμενος μήνας"><i class="fas fa-chevron-right"></i></a>
    </div>
  </div>

  <div class="cal-dow" aria-hidden="true">
    <div>Δευ</div><div>Τρι</div><div>Τετ</div><div>Πεμ</div><div>Παρ</div><div>Σαβ</div><div>Κυρ</div>
  </div>

  <div class="cal-grid" role="grid" aria-label="<?= h($monthLabel) ?>">
    <?php
    // Leading empty cells
    for ($i = 0; $i < $offsetMon; $i++) {
        echo '<div class="cell empty" aria-hidden="true"></div>';
    }

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $cellDate = "$year-$month-$d";
        $dow = (int)date('w', mktime(0,0,0,$month,$d,$year));
        $isWeekend = ($dow === 0 || $dow === 6);
        $isToday   = ($cellDate === $today);

        $classes = ['cell'];
        if ($isWeekend) $classes[] = 'weekend';
        if ($isToday)   $classes[] = 'today';

        $dayEvents = $byDay[$d] ?? [];
        $shown     = array_slice($dayEvents, 0, 3);
        $overflow  = count($dayEvents) - count($shown);
        ?>
        <div class="<?= implode(' ', $classes) ?>" role="gridcell">
          <div class="cell-day">
            <span class="day-num"><?= $d ?></span>
            <?php if (count($dayEvents) > 0): ?>
              <span class="cell-count"><?= count($dayEvents) ?> event<?= count($dayEvents) === 1 ? '' : 's' ?></span>
            <?php endif; ?>
          </div>
          <?php foreach ($shown as $ev):
            $type = $ev['type'] ?? 'friendly';
            $chipClass = 'chip type-' . h($type);
            $tt = ($typeLabelsGr[$type] ?? ucfirst($type))
                . ' • ' . h($ev['organiser_name'] ?? '')
                . ($ev['venue_name'] ? ' — ' . h($ev['venue_name']) : '');
          ?>
            <a class="<?= $chipClass ?>"
               href="<?= h(eventPublicUrl($ev)) ?>"
               title="<?= $tt ?>"><?= h($ev['title']) ?></a>
          <?php endforeach; ?>
          <?php if ($overflow > 0): ?>
            <div class="chip-more">+ <?= (int)$overflow ?> ακόμη</div>
          <?php endif; ?>
        </div>
    <?php }

    // Trailing empty cells to complete the last week row
    $used = $offsetMon + $daysInMonth;
    $trail = (7 - ($used % 7)) % 7;
    for ($i = 0; $i < $trail; $i++) {
        echo '<div class="cell empty" aria-hidden="true"></div>';
    }
    ?>
  </div>

  <div class="legend" aria-label="Υπόμνημα">
    <span><i style="background:linear-gradient(135deg,rgba(230,57,70,.5),rgba(230,57,70,.2));border:1px solid rgba(230,57,70,.4)"></i> Πρωτάθλημα / Φιλικός</span>
    <span><i style="background:linear-gradient(135deg,rgba(240,165,0,.5),rgba(240,165,0,.2));border:1px solid rgba(240,165,0,.4)"></i> Camp</span>
    <span><i style="background:linear-gradient(135deg,rgba(78,132,255,.5),rgba(78,132,255,.2));border:1px solid rgba(78,132,255,.4)"></i> Σεμινάριο</span>
    <span><i style="background:linear-gradient(135deg,rgba(45,198,83,.4),rgba(45,198,83,.2));border:1px solid rgba(45,198,83,.4)"></i> Εξέταση</span>
    <span><i style="background:linear-gradient(135deg,rgba(155,155,255,.4),rgba(155,155,255,.15));border:1px solid rgba(155,155,255,.4)"></i> Συνάντηση</span>
  </div>
</div>

</body>
</html>
