<?php
/**
 * events/results.php — Public results page for an event
 * URL: /events/results.php?slug=…
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events_bracket.php';

$slug = trim($_GET['slug'] ?? '');
$ev   = $slug ? eventGetBySlug($slug) : null;
if (!$ev) { http_response_code(404); exit('Event not found'); }
if ($ev['visibility'] === 'invite_only') { http_response_code(403); exit('Private event'); }

$results = bracketResultsFor((int)$ev['id']);
$byCat   = [];
foreach ($results as $r) $byCat[$r['cat_name'] ?? '—'][] = $r;

$metaTitle = 'Αποτελέσματα · ' . $ev['title'];
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($metaTitle) ?></title>
<meta name="description" content="Αποτελέσματα του event <?= h($ev['title']) ?>.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;line-height:1.55;min-height:100vh}
.top{position:sticky;top:0;background:rgba(7,9,15,.9);backdrop-filter:blur(10px);border-bottom:1px solid #1e2536;padding:1rem 1.25rem;z-index:10;display:flex;justify-content:space-between;align-items:center}
.brand{font-size:1.15rem;font-weight:800;color:#f0f2ff;text-decoration:none}
.brand em{color:#e63946;font-style:normal}
.wrap{max-width:900px;margin:0 auto;padding:2rem 1.25rem}
h1{font-size:1.8rem;margin-bottom:.5rem}
.lead{color:#8892b0;margin-bottom:2rem}
.cat{background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem 1.5rem;margin-bottom:1rem}
.cat h2{font-size:1.15rem;color:#e63946;margin-bottom:1rem;text-transform:uppercase;letter-spacing:.06em}
.podium{display:grid;grid-template-columns:1fr;gap:.5rem}
.row{display:flex;align-items:center;gap:1rem;padding:.85rem 1rem;background:#0d1017;border:1px solid #1e2536;border-radius:10px}
.medal{font-size:2rem;line-height:1;width:50px;text-align:center}
.athlete{font-weight:800;color:#f0f2ff}
.club{color:#8892b0;font-size:.85rem}
.place{color:#6b7494;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;margin-top:.2rem}
.gold{border-color:#f0a500;box-shadow:0 0 15px rgba(240,165,0,.15)}
.silver{border-color:#c0c0c0}
.bronze{border-color:#cd7f32}
.empty{text-align:center;padding:3rem;color:#8892b0;border:1px dashed #2a3248;border-radius:14px}
.print{position:fixed;top:1rem;right:1rem;padding:.6rem 1rem;background:#e63946;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-family:inherit;text-decoration:none}
@media print{.top,.print{display:none}body{background:#fff;color:#000}.cat{border-color:#ccc;background:#fff;box-shadow:none;page-break-inside:avoid}.row{background:#fff}h2,.athlete{color:#000}}
</style>
<?php include __DIR__ . "/../includes/prelogin_polish.php"; ?>
</head>
<body>

<div class="top">
  <a href="<?= APP_URL ?>/" class="brand">MA<em>ster</em></a>
  <a href="<?= h(eventPublicUrl($ev)) ?>" style="color:#8892b0;text-decoration:none;font-size:.9rem"><i class="fa-solid fa-arrow-left"></i> Πίσω</a>
</div>

<button class="print" onclick="window.print()"><i class="fa-solid fa-print"></i> Εκτύπωση</button>

<div class="wrap">
  <h1><?= h($ev['title']) ?></h1>
  <p class="lead">Επίσημα αποτελέσματα · <?= h(formatDate(substr($ev['starts_at']??'',0,10))) ?></p>

  <?php if (!$byCat): ?>
    <div class="empty">
      <i class="fa-solid fa-medal" style="font-size:3rem;color:#4a5270;display:block;margin-bottom:.75rem"></i>
      Τα αποτελέσματα δεν έχουν δημοσιευτεί ακόμα.
    </div>
  <?php else: foreach ($byCat as $catName => $rows): ?>
    <div class="cat">
      <h2><i class="fa-solid fa-trophy"></i> <?= h($catName) ?></h2>
      <div class="podium">
        <?php foreach ($rows as $r):
          $medalEmoji = ['gold'=>'🥇','silver'=>'🥈','bronze'=>'🥉'][$r['medal']] ?? '·';
        ?>
          <div class="row <?= h($r['medal']) ?>">
            <div class="medal"><?= $medalEmoji ?></div>
            <div style="flex:1">
              <div class="athlete"><?= h($r['athlete_name'] ?? '—') ?></div>
              <div class="club"><?= h($r['school_name'] ?? '—') ?></div>
            </div>
            <div class="place">#<?= (int)$r['place'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <p style="text-align:center;color:#4a5270;font-size:.8rem;padding:2rem 0">
    Powered by <a href="<?= APP_URL ?>/" style="color:#e63946;text-decoration:none">MA<em style="font-style:normal">ster</em></a>
  </p>
</div>

</body>
</html>
