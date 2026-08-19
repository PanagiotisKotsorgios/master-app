<?php
/**
 * events/federation.php — Public roll-up of events sanctioned by a federation
 * URL: /events/federation.php?id=<federation_id>
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

$fedId = (int)($_GET['id'] ?? 0);
$db = getDB();

$fed = $db->prepare("SELECT id, name, contact_email FROM federations WHERE id = ? AND active = 1 LIMIT 1");
$fed->execute([$fedId]);
$federation = $fed->fetch();
if (!$federation) { http_response_code(404); exit('Federation not found'); }

$events = $db->prepare("
    SELECT e.*, s.name AS organiser_name
    FROM events e
    LEFT JOIN schools s ON s.id = e.organiser_school_id
    WHERE e.federation_id = ?
      AND e.visibility = 'public'
      AND e.status IN ('open','in_progress','completed','closed')
    ORDER BY (e.starts_at IS NULL), e.starts_at DESC
    LIMIT 60
");
$events->execute([$fedId]);
$rows = $events->fetchAll();
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($federation['name']) ?> — Events</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;line-height:1.55;min-height:100vh}
.top{position:sticky;top:0;background:rgba(7,9,15,.9);backdrop-filter:blur(10px);border-bottom:1px solid #1e2536;padding:1rem 1.25rem;z-index:10;display:flex;justify-content:space-between;align-items:center}
.brand{font-size:1.15rem;font-weight:800;color:#f0f2ff;text-decoration:none}
.brand em{color:#e63946;font-style:normal}
.wrap{max-width:1000px;margin:0 auto;padding:2rem 1.25rem}
.header{background:linear-gradient(135deg,#111520,#0d1017);border:1px solid #1e2536;border-radius:16px;padding:2rem;margin-bottom:1.5rem;text-align:center}
.badge{display:inline-block;background:rgba(230,57,70,.15);color:#e63946;padding:.35rem 1rem;border-radius:20px;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;font-weight:800;margin-bottom:.75rem}
h1{font-size:2rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem}
.card{background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.25rem;text-decoration:none;color:inherit;transition:border-color .15s}
.card:hover{border-color:#e63946}
.type{font-size:.7rem;text-transform:uppercase;color:#e63946;font-weight:700;letter-spacing:.1em;margin-bottom:.4rem}
h3{font-size:1rem;margin-bottom:.35rem;line-height:1.3}
.by{color:#6b7494;font-size:.82rem;margin-bottom:.5rem}
.meta{color:#6b7494;font-size:.82rem;display:flex;gap:1rem;flex-wrap:wrap}
.empty{text-align:center;padding:2rem;color:#6b7494;border:1px dashed #2a3248;border-radius:14px}
</style>
<?php include __DIR__ . "/../includes/prelogin_polish.php"; ?>
</head>
<body>

<div class="top">
  <a href="<?= APP_URL ?>/" class="brand">MA<em>ster</em></a>
  <a href="<?= APP_URL ?>/events/" style="color:#8892b0;text-decoration:none;font-size:.9rem"><i class="fa-solid fa-list"></i> Όλα τα events</a>
</div>

<div class="wrap">
  <div class="header">
    <span class="badge">Ομοσπονδία</span>
    <h1><?= h($federation['name']) ?></h1>
  </div>

  <?php if ($rows): ?>
    <div class="grid">
      <?php foreach ($rows as $ev): ?>
        <a href="<?= h(eventPublicUrl($ev)) ?>" class="card">
          <div class="type"><?= h(eventTypeLabel($ev['type'])) ?></div>
          <h3><?= h($ev['title']) ?></h3>
          <div class="by">από <?= h($ev['organiser_name'] ?? '—') ?></div>
          <div class="meta">
            <?php if ($ev['starts_at']): ?><span><i class="fa-regular fa-calendar"></i> <?= h(formatDate(substr($ev['starts_at'],0,10))) ?></span><?php endif; ?>
            <?php if ($ev['venue_name']): ?><span><i class="fa-solid fa-location-dot"></i> <?= h($ev['venue_name']) ?></span><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty">Δεν υπάρχουν εγκεκριμένα events από αυτή την ομοσπονδία.</div>
  <?php endif; ?>
</div>

</body>
</html>
