<?php
/**
 * parent/events.php — Events my children are registered in
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_navigation.php';
require_once __DIR__ . '/../includes/events.php';

requireParentLogin();

$pid = parentUserId();
$sid = parentSchoolId();

$events = eventsForParent($pid, $sid);
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Διοργανώσεις των παιδιών μου — MAster</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;line-height:1.55;min-height:100vh}
  .top{position:sticky;top:0;background:rgba(7,9,15,.9);backdrop-filter:blur(10px);border-bottom:1px solid #1e2536;padding:1rem 1.25rem;z-index:10;display:flex;justify-content:space-between;align-items:center}
  .brand{font-size:1.15rem;font-weight:800;color:#f0f2ff;text-decoration:none}
  .brand em{color:#e63946;font-style:normal}
  .nav-back{color:#8892b0;text-decoration:none;font-size:.9rem}
  .wrap{max-width:900px;margin:0 auto;padding:2rem 1.25rem}
  h1{font-size:1.75rem;margin-bottom:.5rem}
  .lead{color:#8892b0;margin-bottom:1.5rem}
  .card{background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:.85rem}
  .type-tag{font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:#e63946;font-weight:700;margin-bottom:.4rem}
  .card h3{font-size:1.1rem;margin-bottom:.35rem}
  .child{color:#8892b0;font-size:.9rem;margin-bottom:.5rem}
  .status-row{display:flex;gap:1rem;flex-wrap:wrap;font-size:.85rem;color:#6b7494;margin-top:.5rem}
  .badge{display:inline-block;padding:.15rem .55rem;border-radius:12px;font-size:.72rem;font-weight:700}
  .badge-paid{background:rgba(45,198,83,.15);color:#2dc653}
  .badge-pending{background:rgba(240,165,0,.15);color:#f0a500}
  .badge-overdue{background:rgba(230,57,70,.15);color:#e63946}
  .empty{text-align:center;padding:3rem 1.25rem;color:#8892b0;border:1px dashed #2a3248;border-radius:14px}
  .btn-view{margin-top:.75rem;padding:.5rem 1rem;background:transparent;border:1px solid #2a3248;color:#f0f2ff;text-decoration:none;border-radius:8px;display:inline-block;font-size:.85rem;font-weight:600}
  .btn-view:hover{border-color:#e63946}
</style>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/postlogin-portal-theme.css?v=<?= @filemtime(__DIR__ . "/../assets/css/postlogin-portal-theme.css") ?: time() ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/portal-navigation.css?v=<?= @filemtime(__DIR__ . "/../assets/css/portal-navigation.css") ?: time() ?>">
</head>
<body>

<?php renderParentPortalTopbar('events'); ?>

<div class="wrap">
  <h1>Διοργανώσεις των παιδιών μου</h1>
  <p class="lead">Δείτε σε ποια πρωταθλήματα, φιλικά και camps έχουν δηλωθεί.</p>

  <?php if (!$events): ?>
    <div class="empty">
      <div style="font-size:3rem;color:#4a5270;margin-bottom:.5rem"><i class="fa-solid fa-trophy"></i></div>
      <p>Κανένα event ακόμα.</p>
      <p style="font-size:.85rem;margin-top:.5rem">Όταν ο σύλλογος δηλώσει το παιδί σας σε πρωτάθλημα ή camp, θα εμφανίζεται εδώ.</p>
      <p style="margin-top:1.25rem">
        <a href="<?= APP_URL ?>/events/" style="color:#e63946;text-decoration:none;font-weight:700">
          Ανακαλύψτε δημόσια events →
        </a>
      </p>
    </div>
  <?php else: ?>
    <?php foreach ($events as $ev): ?>
      <div class="card">
        <div class="type-tag"><?= h(eventTypeLabel($ev['type'])) ?></div>
        <h3><?= h($ev['title']) ?></h3>
        <div class="child">
          <i class="fa-solid fa-user"></i> <?= h($ev['athlete_name']) ?>
          <?php if ($ev['cat_name']): ?> · <?= h($ev['cat_name']) ?><?php endif; ?>
        </div>
        <div>
          <span class="badge <?= $ev['reg_status']==='approved'?'badge-paid':($ev['reg_status']==='rejected'?'badge-overdue':'badge-pending') ?>">
            <?= h(['pending'=>'Εκκρεμεί έγκριση','approved'=>'Εγκεκριμένο','rejected'=>'Απορρίφθηκε','checked_in'=>'Παρών','no_show'=>'Απών','disqualified'=>'DQ'][$ev['reg_status']] ?? $ev['reg_status']) ?>
          </span>
          <span class="badge <?= $ev['payment_status']==='verified'?'badge-paid':'badge-pending' ?>" style="margin-left:.3rem">
            <?= h(['unpaid'=>'Ανεξόφλητο','proof_uploaded'=>'Υπό έλεγχο','verified'=>'Πληρωμένο','refunded'=>'Επιστροφή','waived'=>'Χωρίς χρέωση'][$ev['payment_status']] ?? $ev['payment_status']) ?>
          </span>
        </div>
        <div class="status-row">
          <?php if ($ev['starts_at']): ?><span><i class="fa-regular fa-calendar"></i> <?= h(formatDate(substr($ev['starts_at'],0,10))) ?></span><?php endif; ?>
          <?php if ($ev['venue_name']): ?><span><i class="fa-solid fa-location-dot"></i> <?= h($ev['venue_name']) ?></span><?php endif; ?>
        </div>
        <a href="<?= h(eventPublicUrl($ev)) ?>" class="btn-view">
          Λεπτομέρειες event <i class="fa-solid fa-arrow-right"></i>
        </a>
        <a href="<?= APP_URL ?>/events/certificate.php?reg=<?= (int)$ev['reg_id'] ?>" target="_blank" class="btn-view" style="margin-left:.35rem">
          <i class="fa-solid fa-award"></i> Πιστοποιητικό
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php renderParentPortalBottomNav('events'); ?>

</body>
</html>
