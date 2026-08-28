<?php
/**
 * events/athletes.php — Public athlete search page
 * URL: /events/athletes.php?q=…
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

$q = trim($_GET['q'] ?? '');
$results = [];
if (mb_strlen($q, 'UTF-8') >= 2) {
    // Reuse api/athletes_search.php logic via internal call
    $db = getDB();
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $st = $db->prepare("
        SELECT r.id AS reg_id, r.athlete_id, a.full_name, a.birthdate,
               e.id AS event_id, e.slug, e.title AS event_title,
               e.starts_at, e.venue_name, e.type,
               c.name AS cat_name, s.name AS school_name
        FROM event_registrations r
        JOIN events e ON e.id = r.event_id
        JOIN athletes a ON a.id = r.athlete_id
        LEFT JOIN event_categories c ON c.id = r.category_id
        LEFT JOIN schools s ON s.id = r.registering_school_id
        WHERE e.visibility = 'public' AND e.status IN ('open','in_progress','completed')
          AND r.status NOT IN ('rejected','withdrawn')
          AND r.show_public = 1
          AND a.full_name LIKE ?
        ORDER BY e.starts_at DESC
        LIMIT 100
    ");
    $st->execute([$like]);
    $rows = $st->fetchAll();

    foreach ($rows as $r) {
        $name = $r['full_name'];
        if (!empty($r['birthdate']) && $r['birthdate'] !== '0000-00-00') {
            try {
                $age = (new DateTime())->diff(new DateTime($r['birthdate']))->y;
                if ($age < 18) {
                    $p = preg_split('/\s+/', trim($name));
                    if (count($p) >= 2) $name = $p[0] . ' ' . mb_substr(end($p), 0, 1, 'UTF-8') . '.';
                }
            } catch (Exception $e) {}
        }
        $k = (int)$r['athlete_id'];
        if (!isset($results[$k])) $results[$k] = ['name' => $name, 'club' => $r['school_name'], 'events' => []];
        $results[$k]['events'][] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Αναζήτηση αθλητή — MAster Events</title>
<meta name="description" content="Βρες σε ποια events συμμετέχει ένας αθλητής.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;line-height:1.55;min-height:100vh}
.top{position:sticky;top:0;background:rgba(7,9,15,.9);backdrop-filter:blur(10px);border-bottom:1px solid #1e2536;padding:.9rem 1.25rem;z-index:10}
.top-inner{max-width:1000px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
.brand{font-size:1.15rem;font-weight:800;color:#f0f2ff;text-decoration:none}
.brand em{color:#e63946;font-style:normal}
.top-actions{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}
.top-actions a{padding:.55rem .95rem;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#ffffff !important;font-size:.9rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;transition:all .18s;text-decoration:none}
.top-actions a i{color:#ffffff !important}
.top-actions a:hover{border-color:rgba(230,57,70,.55);background:rgba(230,57,70,.08);color:#ffffff !important}
.top-actions a.active{background:linear-gradient(135deg,#e63946,#c72832);border-color:transparent;color:#ffffff !important;box-shadow:0 4px 14px -4px rgba(230,57,70,.5)}
.top-actions a.active i{color:#ffffff !important}
.top-actions .btn-login{background:linear-gradient(135deg,#e63946,#c72832);border-color:transparent}
.wrap{max-width:900px;margin:0 auto;padding:2rem 1.25rem}
h1{font-size:1.8rem;margin-bottom:.5rem}
.lead{color:#8892b0;margin-bottom:2rem}
.search-form{display:flex;gap:.5rem;margin-bottom:1.5rem}
.search-form input{flex:1;padding:1rem 1.25rem;background:#111520;border:1px solid #2a3248;border-radius:12px;color:#f0f2ff;font-size:1.05rem;font-family:inherit}
.search-form button{padding:1rem 1.5rem;background:#e63946;color:#fff;border:none;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit}
.athlete{background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.25rem;margin-bottom:1rem}
.athlete-name{font-size:1.1rem;font-weight:800;color:#f0f2ff}
.athlete-club{color:#8892b0;font-size:.85rem;margin-bottom:.7rem}
.evt-row{display:block;padding:.7rem .9rem;background:#0d1017;border:1px solid #1e2536;border-radius:10px;margin-top:.4rem;text-decoration:none;color:inherit;transition:border-color .15s}
.evt-row:hover{border-color:#e63946}
.evt-title{color:#f0f2ff;font-weight:700}
.evt-meta{color:#6b7494;font-size:.8rem;margin-top:.15rem}
.empty{text-align:center;padding:2rem;color:#6b7494;border:1px dashed #2a3248;border-radius:14px}
</style>
<?php include __DIR__ . "/../includes/prelogin_polish.php"; ?>
</head>
<body>

<div class="top">
  <div class="top-inner">
    <a href="<?= APP_URL ?>/" class="brand">MA<em>ster</em> · Events</a>
    <div class="top-actions">
      <a href="<?= APP_URL ?>/events/"><i class="fas fa-list"></i> Λίστα</a>
      <a href="<?= APP_URL ?>/events/calendar.php"><i class="fas fa-calendar"></i> Ημερολόγιο</a>
      <a href="<?= APP_URL ?>/events/athletes.php" class="active"><i class="fas fa-magnifying-glass"></i> Αθλητές</a>
      <a href="<?= APP_URL ?>/"><i class="fas fa-house"></i> Αρχική</a>
      <a href="<?= APP_URL ?>/login.php" class="btn-login" style="margin-left:.35rem">Σύνδεση</a>
    </div>
  </div>
</div>

<div class="wrap">
  <h1>Αναζήτηση αθλητή</h1>
  <p class="lead">Βρείτε σε ποια δημόσια events συμμετέχει ένας αθλητής.</p>

  <form class="search-form" method="GET">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Π.χ. Γιάννης Παπαδόπουλος (τουλάχιστον 2 χαρακτήρες)" autofocus>
    <button type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
  </form>

  <?php if ($q === ''): ?>
    <div class="empty">Πληκτρολογήστε ένα όνομα για αναζήτηση.</div>
  <?php elseif (!$results): ?>
    <div class="empty">Δεν βρέθηκε αθλητής με «<?= h($q) ?>».</div>
  <?php else: ?>
    <p style="color:#8892b0;margin-bottom:1rem"><?= count($results) ?> αθλητές</p>
    <?php foreach ($results as $ath): ?>
      <div class="athlete">
        <div class="athlete-name"><?= h($ath['name']) ?></div>
        <div class="athlete-club"><?= h($ath['club'] ?? '—') ?></div>
        <?php foreach ($ath['events'] as $e): ?>
          <a class="evt-row" href="<?= APP_URL ?>/events/view.php?slug=<?= h($e['slug']) ?>">
            <div class="evt-title"><?= h($e['event_title']) ?></div>
            <div class="evt-meta">
              <?= h(eventTypeLabel($e['type'])) ?>
              <?php if ($e['starts_at']): ?> · <?= h(formatDate(substr($e['starts_at'],0,10))) ?><?php endif; ?>
              <?php if ($e['cat_name']): ?> · <?= h($e['cat_name']) ?><?php endif; ?>
              <?php if ($e['venue_name']): ?> · <?= h($e['venue_name']) ?><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</body>
</html>
