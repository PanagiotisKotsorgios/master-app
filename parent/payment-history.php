<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_navigation.php';
requireParentLogin();

$athleteId = (int)($_GET['athlete_id'] ?? 0);
if (!$athleteId) { header('Location: children.php'); exit; }

$db     = getDB();
$sid    = parentSchoolId();
$userId = parentUserId();

$stmt = $db->prepare("
    SELECT a.*, pc.parent_id
    FROM athletes a
    JOIN parent_children pc ON a.id = pc.athlete_id
    WHERE a.id = ? AND pc.parent_id = ? AND a.school_id = ? AND a.active = 1
");
$stmt->execute([$athleteId, $userId, $sid]);
$athlete = $stmt->fetch();
if (!$athlete) { header('Location: children.php'); exit; }

$months = getAthleteMonthlyPayments($athleteId);

$totalPaid    = 0;
$totalPartial = 0;
$totalUnpaid  = 0;
$totalOwed    = 0.0;
foreach ($months as $m) {
    if ($m['paid']) $totalPaid++;
    elseif (!empty($m['partial'])) { $totalPartial++; $totalOwed += $m['remaining']; }
    else { $totalUnpaid++; $totalOwed += $m['remaining']; }
}

$byYear = [];
foreach ($months as $m) {
    $byYear[$m['year']][] = $m;
}
krsort($byYear);
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title>Ιστορικό Μηνών — Portal Γονέων — MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
:root {
  --bg:    #07090f;
  --card:  #111520;
  --brd:   #1e2536;
  --red:   #e63946;
  --green: #2dc653;
  --gold:  #f0a500;
  --text:  #f0f2ff;
  --muted: #b0bdd6;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }
body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; font-size: 1rem; }

.pp-topbar {
  background: var(--card); border-bottom: 2px solid var(--brd);
  padding: 1rem 2rem; display: flex; align-items: center;
  justify-content: space-between; position: sticky; top: 0; z-index: 50;
  gap: 1rem;
}
.pp-logo {
  font-family: 'DM Sans', sans-serif;
  font-size: 1.8rem; letter-spacing: -.01em; color: var(--text);
  display: flex; align-items: baseline; gap: 0;
  text-decoration: none; flex-shrink: 0;
}
.pp-logo .logo-ma {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.8rem; letter-spacing: .03em; color: var(--text);
}
.pp-logo .logo-ster {
  font-family: 'DM Sans', sans-serif;
  font-size: 1.3rem; font-weight: 800; letter-spacing: .01em;
  color: var(--red); text-transform: lowercase;
}
.pp-nav { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.pp-nav a { font-size: 0.95rem; font-weight: 700; color: var(--muted); text-decoration: none; display: flex; align-items: center; gap: .4rem; padding: .5rem .7rem; border-radius: 10px; transition: all .2s; min-height: 44px; }
.pp-nav a:hover { color: var(--text); background: rgba(255,255,255,.06); }
.pp-nav a.active { color: var(--text); background: rgba(255,255,255,.08); }
.pp-nav a.nav-logout { color: var(--red); }
.pp-nav a.nav-logout:hover { background: rgba(230,57,70,.08); color: #ff6b76; }
.pp-nav a i { font-size: 1rem; }

.pp-body { max-width: 1200px; width: 100%; margin: 0 auto; padding: 2rem 1.5rem; }

.page-hero { margin-bottom: 1.5rem; }
.page-hero-tag { font-size: .8rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--red); display: flex; align-items: center; gap: .4rem; margin-bottom: .5rem; }
.page-hero h1 { font-family: 'Bebas Neue', sans-serif; font-size: clamp(1.5rem, 5vw, 3rem); letter-spacing: .04em; line-height: .92; }
.page-hero h1 em { font-style: normal; color: var(--red); }
.page-hero-sub { font-size: 0.9rem; color: var(--muted); margin-top: .5rem; line-height: 1.7; }

/* ── Summary stats ── */
.pp-summary {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.8rem;
  margin-bottom: 2rem;
}
.hstat {
  background: var(--card); border: 1px solid var(--brd);
  border-radius: 14px; padding: 1.1rem;
  min-width: 0; /* prevent overflow in grid */
}
.hstat-num {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 2.2rem; letter-spacing: .04em; line-height: 1;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.hstat-num.green { color: var(--green); }
.hstat-num.red   { color: var(--red); }
.hstat-num.gold  { color: var(--gold); }
.hstat-lbl { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-top: .3rem; }

.year-section { margin-bottom: 1.5rem; }
.year-label {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.5rem; letter-spacing: .08em;
  color: var(--muted); margin-bottom: 0.8rem;
  display: flex; align-items: center; gap: .5rem;
}
.year-label::after { content: ''; flex: 1; height: 1px; background: var(--brd); }

.month-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 0.8rem;
}
.month-card {
  background: var(--card); border-radius: 14px;
  padding: 1rem;
  border: 2px solid transparent;
  transition: transform .2s, box-shadow .2s;
}
.month-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.3); }
.month-card.paid    { border-color: rgba(45,198,83,.3); background: rgba(45,198,83,.04); }
.month-card.unpaid  { border-color: rgba(230,57,70,.3); background: rgba(230,57,70,.04); }
.month-card.partial { border-color: rgba(240,165,0,.35); background: rgba(240,165,0,.04); }
.month-card-icon { font-size: 1.3rem; margin-bottom: .5rem; }
.month-card.paid    .month-card-icon { color: var(--green); }
.month-card.unpaid  .month-card-icon { color: var(--red); }
.month-card.partial .month-card-icon { color: var(--gold); }
.month-card-name { font-size: 0.95rem; font-weight: 800; color: var(--text); margin-bottom: .3rem; }
.month-card-amount { font-size: 0.85rem; }
.month-card.paid    .month-card-amount { color: var(--green); font-weight: 700; }
.month-card.unpaid  .month-card-amount { color: var(--red);   font-weight: 700; }
.month-card.partial .month-card-amount { color: var(--gold);  font-weight: 700; }
.month-card-status {
  margin-top: .4rem;
  font-size: .65rem; font-weight: 800; text-transform: uppercase;
  letter-spacing: .08em; padding: .2rem .5rem; border-radius: 16px;
  display: inline-block;
}
.month-card.paid    .month-card-status { background: rgba(45,198,83,.12); color: var(--green); }
.month-card.unpaid  .month-card-status { background: rgba(230,57,70,.12); color: var(--red); }
.month-card.partial .month-card-status { background: rgba(240,165,0,.12);  color: var(--gold); }

.pp-btn { display: inline-flex; align-items: center; gap: .45rem; padding: .65rem 1.25rem; border-radius: 12px; font-size: 0.9rem; font-weight: 800; text-decoration: none; transition: all .2s; min-height: 44px; }
.pp-btn-outline { background: rgba(255,255,255,.06); border: 1px solid var(--brd); color: var(--muted); }
.pp-btn-outline:hover { background: rgba(255,255,255,.1); color: var(--text); }

.pp-empty { text-align: center; padding: 2.5rem 1.5rem; color: var(--muted); background: var(--card); border: 1px solid var(--brd); border-radius: 14px; }
.pp-empty i { font-size: 2rem; color: rgba(230,57,70,.25); margin-bottom: 0.8rem; display: block; }
.pp-empty p { font-size: 0.9rem; line-height: 1.6; max-width: 380px; margin: 0 auto; }

/* ── Bottom Tab Bar ── */
.pp-bottom-nav {
  display: none;
  position: fixed;
  bottom: 0; left: 0; right: 0;
  background: var(--card);
  border-top: 2px solid var(--brd);
  z-index: 100;
  padding-bottom: env(safe-area-inset-bottom, 0px);
}
.pp-bottom-nav-inner {
  display: flex;
  align-items: stretch;
}
.pp-bottom-nav a {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 10px 4px 10px;
  color: var(--muted);
  text-decoration: none;
  font-size: 0.6rem;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
  transition: color .2s;
  position: relative;
  min-height: 56px;
}
.pp-bottom-nav a i {
  font-size: 1.35rem;
  transition: color .2s, transform .2s, filter .2s;
}
.pp-bottom-nav a.active {
  color: var(--red);
}
.pp-bottom-nav a.active::before {
  content: '';
  position: absolute;
  top: 0; left: 20%; right: 20%;
  height: 2px;
  background: var(--red);
  border-radius: 0 0 4px 4px;
}
.pp-bottom-nav a.active i {
  color: var(--red);
  filter: drop-shadow(0 0 6px rgba(230,57,70,.6));
  transform: translateY(-1px);
}
.pp-bottom-nav a.nav-logout { color: var(--red); opacity: .7; }
.pp-bottom-nav a.nav-logout:hover,
.pp-bottom-nav a.nav-logout:active { opacity: 1; }

/* ── Tablet ── */
@media (max-width: 768px) {
  .pp-nav { display: none; }
  .pp-bottom-nav { display: block; }
  .pp-topbar { padding: .75rem 1rem; gap: 0.75rem; }
  .pp-body {
    padding-top: 1.5rem;
    padding-left: 1rem;
    padding-right: 1rem;
    padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px));
  }
  .pp-summary { grid-template-columns: repeat(2, 1fr); }
  .month-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
}

/* ── Mobile ── */
@media (max-width: 480px) {
  .pp-topbar { padding: 0.65rem 0.75rem; }
  .pp-body {
    padding-top: 1rem;
    padding-left: 0.75rem;
    padding-right: 0.75rem;
    padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px));
  }
  .page-hero h1 { font-size: clamp(1.2rem, 4vw, 2rem); }
  .pp-summary { grid-template-columns: repeat(2, 1fr); gap: 0.6rem; }
  .hstat { padding: 0.9rem 0.8rem; }
  .hstat-num { font-size: 1.6rem; }
  .hstat-lbl { font-size: 0.65rem; }
  .month-grid { grid-template-columns: repeat(2, 1fr); gap: 0.6rem; }
  .month-card { padding: 0.85rem; }
  .month-card-name { font-size: 0.85rem; }
  .month-card-amount { font-size: 0.75rem; }
}

/* ── Extra small ── */
@media (max-width: 360px) {
  .pp-topbar { padding: 0.6rem 0.65rem; }
  .pp-body {
    padding-top: 0.9rem;
    padding-left: 0.65rem;
    padding-right: 0.65rem;
    padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px));
  }
  .pp-summary { grid-template-columns: repeat(2, 1fr); gap: 0.5rem; }
  .hstat { padding: 0.75rem 0.65rem; }
  .hstat-num { font-size: 1.4rem; }
  .hstat-lbl { font-size: 0.6rem; }
  .month-grid { grid-template-columns: repeat(2, 1fr); gap: 0.5rem; }
  .month-card { padding: 0.75rem; }
}
</style>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/postlogin-portal-theme.css?v=<?= @filemtime(__DIR__ . "/../assets/css/postlogin-portal-theme.css") ?: time() ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/portal-navigation.css?v=<?= @filemtime(__DIR__ . "/../assets/css/portal-navigation.css") ?: time() ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;flex-direction:column">

  <?php renderParentPortalTopbar('children'); ?>

  <main class="pp-body" style="flex:1">

    <div class="page-hero">
      <div class="page-hero-tag"><i class="fas fa-calendar-days"></i> Ιστορικό Πληρωμών</div>
      <h1>ΜΗΝΙΑΙΕΣ<br><em>ΠΛΗΡΩΜΕΣ</em></h1>
      <div class="page-hero-sub">
        <strong><?= htmlspecialchars($athlete['full_name']) ?></strong> · €<?= number_format($athlete['monthly_fee'] ?? 0, 2) ?>/μήνα
      </div>
    </div>

    <div style="margin-bottom:1.25rem">
      <a href="javascript:history.back()" class="pp-btn pp-btn-outline">
        <i class="fas fa-arrow-left"></i><span class="nav-label">Πίσω</span>
      </a>
    </div>

    <div class="pp-summary">
      <div class="hstat">
        <div class="hstat-num green"><?= $totalPaid ?></div>
        <div class="hstat-lbl">Εξοφλημένοι</div>
      </div>
      <div class="hstat">
        <div class="hstat-num gold"><?= $totalPartial ?></div>
        <div class="hstat-lbl">Μερικώς</div>
      </div>
      <div class="hstat">
        <div class="hstat-num red"><?= $totalUnpaid ?></div>
        <div class="hstat-lbl">Εκκρεμείς</div>
      </div>
      <div class="hstat">
        <div class="hstat-num gold">€<?= number_format($totalOwed, 2) ?></div>
        <div class="hstat-lbl">Σύνολο Οφειλής</div>
      </div>
    </div>

    <?php if (empty($months)): ?>
      <div class="pp-empty">
        <i class="fas fa-calendar-xmark"></i>
        <p>Δεν βρέθηκαν δεδομένα για αυτόν τον αθλητή.</p>
      </div>
    <?php else: ?>
      <?php foreach ($byYear as $year => $yearMonths): ?>
      <div class="year-section">
        <div class="year-label"><?= $year ?></div>
        <div class="month-grid">
          <?php foreach ($yearMonths as $m): ?>
          <div class="month-card <?= $m['payment_status'] ?? ($m['paid'] ? 'paid' : 'unpaid') ?>">
            <div class="month-card-icon">
              <i class="fas <?= $m['paid'] ? 'fa-circle-check' : (!empty($m['partial']) ? 'fa-circle-half-stroke' : 'fa-circle-xmark') ?>"></i>
            </div>
            <div class="month-card-name"><?= htmlspecialchars($m['label']) ?></div>
            <?php if ($m['paid']): ?>
              <div class="month-card-amount">€<?= number_format($m['paid_amount'], 2) ?></div>
              <span class="month-card-status">Εξοφλήθηκε</span>
            <?php elseif (!empty($m['partial'])): ?>
              <div class="month-card-amount">€<?= number_format($m['paid_amount'], 2) ?></div>
              <div class="month-card-amount" style="font-size:.75rem;opacity:.8;margin-top:.2rem">Υπόλ. €<?= number_format($m['remaining'], 2) ?></div>
              <span class="month-card-status">Μερική</span>
            <?php else: ?>
              <div class="month-card-amount">€<?= number_format($m['remaining'], 2) ?></div>
              <span class="month-card-status">Εκκρεμεί</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </main>
</div>

<!-- Bottom Tab Bar (mobile only) -->
<?php renderParentPortalBottomNav('children'); ?>

</body>
</html>
