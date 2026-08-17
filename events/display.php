<?php
/**
 * events/display.php — Giant venue display board (fullscreen, auto-refresh)
 * ============================================================
 *  URL: /events/display.php?slug=…            → all rings
 *  URL: /events/display.php?slug=…&ring=1     → single ring, huge
 *  Public (no auth) — safe: only shows upcoming/live matches.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events_bracket.php';

$slug = trim($_GET['slug'] ?? '');
$ring = isset($_GET['ring']) ? max(1, (int)$_GET['ring']) : 0;

$ev = $slug ? eventGetBySlug($slug) : null;
if (!$ev) { http_response_code(404); exit('Event not found'); }
if ($ev['visibility'] === 'invite_only') { http_response_code(403); exit('Private event'); }

$title = $ev['title'];
$rings = max(1, (int)$ev['ring_count']);

// Group matches by ring
$byRing = [];
if ($ring) {
    $byRing[$ring] = bracketMatchesForRing((int)$ev['id'], $ring, 8);
} else {
    for ($r = 1; $r <= $rings; $r++) {
        $byRing[$r] = bracketMatchesForRing((int)$ev['id'], $r, 4);
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> — Live</title>
<meta http-equiv="refresh" content="15">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#000;color:#fff;overflow:hidden;height:100vh;display:flex;flex-direction:column}
.top{background:linear-gradient(90deg,#e63946,#8b0d1a);padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #000}
.top h1{font-family:'Bebas Neue',sans-serif;font-size:2.4rem;letter-spacing:.05em;line-height:1}
.top .clock{font-family:'Bebas Neue',sans-serif;font-size:2.2rem;letter-spacing:.05em}
.rings{flex:1;display:grid;gap:.5rem;padding:.5rem;overflow:hidden;grid-template-columns:repeat(auto-fit,minmax(360px,1fr))}
.ring-single .rings{grid-template-columns:1fr}
.ring{background:#0d1017;border:2px solid #1e2536;border-radius:12px;padding:1rem 1.25rem;display:flex;flex-direction:column;overflow:hidden}
.ring-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;padding-bottom:.5rem;border-bottom:2px solid #1e2536}
.ring-name{font-family:'Bebas Neue',sans-serif;font-size:2.2rem;color:#e63946;letter-spacing:.08em;line-height:1}
.ring-count{color:#8892b0;font-size:.9rem}
.match{background:#111520;border:1px solid #1e2536;border-radius:8px;padding:.85rem 1rem;margin-bottom:.55rem}
.match.live{background:linear-gradient(135deg,#2a0d12,#1a0d17);border-color:#e63946;box-shadow:0 0 20px rgba(230,57,70,.4)}
.match.next{border-color:#f0a500;border-width:2px}
.match-cat{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#8892b0;font-weight:700;margin-bottom:.35rem}
.match-vs{display:grid;grid-template-columns:1fr auto 1fr;gap:.75rem;align-items:center}
.player{font-size:1rem;font-weight:800;line-height:1.2}
.player.red{color:#ff6b74;text-align:right}
.player.blue{color:#6ea8ff;text-align:left}
.player .club{color:#6b7494;font-size:.75rem;font-weight:600;margin-top:.15rem}
.score{font-family:'Bebas Neue',sans-serif;font-size:2rem;letter-spacing:.05em;color:#f0f2ff;background:#000;padding:.15rem .75rem;border-radius:6px;min-width:120px;text-align:center}
.meta-row{color:#8892b0;font-size:.78rem;margin-top:.4rem;display:flex;justify-content:space-between}
.live-tag{color:#e63946;font-weight:800;animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.empty{color:#4a5270;text-align:center;padding:2rem;font-size:.95rem}
.ring-single .ring-name{font-size:4rem}
.ring-single .player{font-size:2rem}
.ring-single .score{font-size:4rem;min-width:180px}
</style>
</head>
<body class="<?= $ring ? 'ring-single' : '' ?>">

<div class="top">
  <h1><?= h($ev['title']) ?><?php if ($ring): ?> — Ring <?= $ring ?><?php endif; ?></h1>
  <div class="clock" id="clock"><?= date('H:i') ?></div>
</div>

<div class="rings">
  <?php foreach ($byRing as $ringNum => $matches): ?>
    <div class="ring">
      <div class="ring-header">
        <div class="ring-name">RING <?= $ringNum ?></div>
        <div class="ring-count"><?= count($matches) ?> αγώνες</div>
      </div>

      <?php if (!$matches): ?>
        <div class="empty"><i class="fa-solid fa-check-circle" style="font-size:2rem;color:#2dc653;display:block;margin-bottom:.5rem"></i>Καθαρό</div>
      <?php else: foreach ($matches as $i => $m):
        $cls = $m['status'] === 'live' ? 'live' : ($i === 0 ? 'next' : '');
      ?>
        <div class="match <?= $cls ?>">
          <div class="match-cat">
            <?= h($m['cat_name'] ?? '—') ?> · <?= h($m['round_label'] ?? '—') ?>
            <?php if ($m['scheduled_at']): ?><span style="float:right"><?= h(date('H:i', strtotime($m['scheduled_at']))) ?></span><?php endif; ?>
          </div>
          <div class="match-vs">
            <div class="player red">
              <?= h($m['red_name'] ?? '—') ?>
              <div class="club"><?= h($m['red_school'] ?? '') ?></div>
            </div>
            <div class="score">
              <?php if ($m['status'] === 'live'): ?>
                <?= (int)$m['red_score'] ?> – <?= (int)$m['blue_score'] ?>
              <?php else: ?>
                vs
              <?php endif; ?>
            </div>
            <div class="player blue">
              <?= h($m['blue_name'] ?? '—') ?>
              <div class="club"><?= h($m['blue_school'] ?? '') ?></div>
            </div>
          </div>
          <?php if ($m['status'] === 'live'): ?>
            <div class="meta-row"><span class="live-tag">🔴 LIVE</span></div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<script>
  setInterval(function(){
    var d = new Date();
    document.getElementById('clock').textContent = String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
  }, 15000);
</script>
</body>
</html>
