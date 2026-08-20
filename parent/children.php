<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
requireParentLogin();

try { $children = getParentChildren(); } catch (Throwable $e) { $children = []; }
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title>Τα Παιδιά Μου — Portal Γονέων — MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
  <style>
  :root {
    --bg:     #07090f;
    --card:  #111520;
    --brd:   #1e2536;
    --red:   #e63946;
    --green: #2dc653;
    --text:  #f0f2ff;
    --muted: #b0bdd6;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { -webkit-text-size-adjust: 100%; }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); font-size: 1rem; min-height: 100vh; }

  .parent-wrap { min-height: 100vh; display: flex; flex-direction: column; }

  .pp-topbar {
    background: var(--card); border-bottom: 2px solid var(--brd);
    padding: 1rem 2rem; display: flex; align-items: center;
    justify-content: space-between; position: sticky; top: 0; z-index: 50;
    gap: 1rem;
  }
  .pp-logo { font-family: 'Bebas Neue', sans-serif; font-size: 1.8rem; letter-spacing: .08em; color: var(--text); display: flex; align-items: center; gap: .5rem; text-decoration: none; flex-shrink: 0; }
  .pp-logo span { color: var(--red); }
  .pp-nav { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
  .pp-nav a { font-size: 0.95rem; font-weight: 700; color: var(--muted); text-decoration: none; display: flex; align-items: center; gap: .4rem; padding: .5rem .7rem; border-radius: 10px; transition: all .2s; min-height: 44px; }
  .pp-nav a:hover { color: var(--text); background: rgba(255,255,255,.06); }
  .pp-nav a.active { color: var(--text); background: rgba(255,255,255,.08); }
  .pp-nav a.nav-logout { color: var(--red); }
  .pp-nav a.nav-logout:hover { background: rgba(230,57,70,.08); color: #ff6b76; }
  .pp-nav a i { font-size: 1rem; }

  .parent-body { flex: 1; max-width: 1200px; width: 100%; margin: 0 auto; padding: 2rem 1.5rem; }

  .page-hero { margin-bottom: 1.5rem; }
  .page-hero-tag { font-size: .8rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--red); display: flex; align-items: center; gap: .4rem; margin-bottom: .5rem; }
  .page-hero h1 { font-family: 'Bebas Neue', sans-serif; font-size: clamp(1.5rem, 5vw, 3rem); letter-spacing: .04em; line-height: .92; }
  .page-hero h1 em { font-style: normal; color: var(--red); }
  .page-hero-sub { font-size: 0.9rem; color: var(--muted); margin-top: .5rem; line-height: 1.7; }

  .pbadge {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .4rem 0.9rem; border-radius: 20px;
    font-size: 0.8rem; font-weight: 800; letter-spacing: .03em;
  }
  .pbadge.paid    { background: rgba(45,198,83,.12);  color: var(--green); border: 1px solid rgba(45,198,83,.3); }
  .pbadge.pending { background: rgba(240,165,0,.12);  color: #f0a500;  border: 1px solid rgba(240,165,0,.3); }
  .pbadge.overdue { background: rgba(230,57,70,.12);  color: var(--red); border: 1px solid rgba(230,57,70,.3); }

  .children-card {
    background: var(--card);
    border: 1px solid var(--brd);
    border-radius: 16px;
    overflow: hidden;
  }
  .children-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.1rem 1.5rem;
    border-bottom: 1px solid var(--brd);
    background: rgba(255,255,255,.02);
    flex-wrap: wrap;
    gap: 1rem;
  }
  .children-card-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.3rem;
    letter-spacing: .06em;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: .5rem;
  }
  .children-card-title i { color: var(--red); font-size: 1rem; }

  .children-table-wrap { overflow-x: auto; }
  .children-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.95rem;
  }
  .children-table thead th {
    background: rgba(0,0,0,.2);
    padding: .65rem 1rem;
    text-align: left;
    font-size: .7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted);
    white-space: nowrap;
    border-bottom: 1px solid var(--brd);
  }
  .children-table tbody td {
    padding: 1rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,.05);
    vertical-align: middle;
  }
  .children-table tbody tr:last-child td { border-bottom: none; }
  .children-table tbody tr:hover td { background: rgba(255,255,255,.025); }

  .athlete-name {
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: .6rem;
  }
  .athlete-avatar {
    width: 36px; height: 36px;
    background: rgba(230,57,70,.15);
    border: 1px solid rgba(230,57,70,.25);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 0.95rem;
    color: var(--red);
    flex-shrink: 0;
  }
  .fee-amount {
    font-weight: 700;
    color: var(--text);
    font-size: 0.9rem;
  }

  .btn-parent-primary {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    background: linear-gradient(135deg, var(--red), #b52a35);
    color: #fff;
    font-weight: 800;
    font-size: 0.9rem;
    padding: .65rem 1.25rem;
    border-radius: 12px;
    text-decoration: none;
    transition: all .2s;
    box-shadow: 0 0 20px rgba(230,57,70,.28);
    white-space: nowrap;
    min-height: 44px;
  }
  .btn-parent-primary:hover {
    background: linear-gradient(135deg, #b52a35, #8c1e27);
    box-shadow: 0 0 26px rgba(230,57,70,.45);
    transform: translateY(-1px);
  }
  .btn-parent-back {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    background: rgba(255,255,255,.06);
    border: 1px solid var(--brd);
    color: var(--muted);
    font-weight: 700;
    font-size: 0.9rem;
    padding: .65rem 1.25rem;
    border-radius: 12px;
    text-decoration: none;
    transition: all .2s;
    white-space: nowrap;
    min-height: 44px;
  }
  .btn-parent-back:hover { background: rgba(255,255,255,.09); color: var(--text); }

  .empty-parent {
    text-align: center;
    padding: 3rem 1.5rem;
    color: var(--muted);
  }
  .empty-parent i {
    font-size: 2.5rem;
    color: rgba(230,57,70,.3);
    margin-bottom: 1rem;
    display: block;
  }
  .empty-parent p {
    font-size: 0.95rem;
    line-height: 1.7;
    max-width: 380px;
    margin: 0 auto;
  }

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

  @media (max-width: 768px) {
    .pp-nav { display: none; }
    .pp-bottom-nav { display: block; }
    .parent-body { padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)); }
  }

  @media (max-width: 768px) {
    .pp-topbar { padding: .75rem 1rem; gap: 0.75rem; }
    .parent-body { padding-top: 1.5rem; padding-left: 1rem; padding-right: 1rem; }
    .children-card-header { padding: 1rem 1.25rem; }
    .children-table thead th { padding: .55rem 0.85rem; font-size: 0.65rem; }
    .children-table tbody td { padding: 0.85rem 0.85rem; font-size: 0.9rem; }
    .hide-sm { display: none !important; }
  }

  @media (max-width: 480px) {
    .pp-topbar { padding: 0.65rem 0.75rem; }
    .parent-body { padding-top: 1rem; padding-left: 0.75rem; padding-right: 0.75rem; }
    .page-hero h1 { font-size: clamp(1.2rem, 4vw, 2rem); }
    .children-card-header { padding: 0.9rem 1rem; }
    .children-card-title { font-size: 1.1rem; }
    .children-table-wrap { -webkit-overflow-scrolling: touch; }
    .children-table { font-size: 0.85rem; }
    .children-table thead th { padding: .5rem 0.6rem; font-size: 0.6rem; }
    .children-table tbody td { padding: 0.75rem 0.6rem; font-size: 0.8rem; }
    .athlete-avatar { width: 32px; height: 32px; font-size: 0.85rem; }
    .athlete-name { gap: .4rem; }
    .fee-amount { font-size: 0.8rem; }
    .pbadge { font-size: 0.7rem; padding: 0.3rem 0.75rem; }
    .btn-parent-primary, .btn-parent-back { padding: 0.55rem 1rem; font-size: 0.8rem; min-height: 40px; }
    .hide-sm { display: none !important; }
  }
  </style>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/postlogin-portal-theme.css?v=<?= @filemtime(__DIR__ . "/../assets/css/postlogin-portal-theme.css") ?: time() ?>">
</head>
<body>
<div class="parent-wrap">

  <header class="pp-topbar">
    <a href="index.php" class="pp-logo">MA<span>ster</span></a>
    <nav class="pp-nav">
      <a href="index.php"><i class="fas fa-house"></i><span class="nav-label">Αρχική</span></a>
      <a href="children.php" class="active"><i class="fas fa-children"></i><span class="nav-label">Παιδιά</span></a>
      <a href="settings.php"><i class="fas fa-gear"></i><span class="nav-label">Ρυθμίσεις</span></a>
      <a href="<?= APP_URL ?>/logout.php" class="nav-logout"><i class="fas fa-right-from-bracket"></i><span class="nav-label">Έξοδος</span></a>
    </nav>
  </header>

  <main class="parent-body">

    <div class="page-hero">
      <div class="page-hero-tag"><i class="fas fa-users"></i> Διαχείριση</div>
      <h1>ΤΑ <em>ΠΑΙΔΙΑ</em> ΜΟΥ</h1>
      <div class="page-hero-sub">Δείτε την κατάσταση πληρωμών και το ιστορικό συνδρομών.</div>
    </div>

    <div class="children-card">
      <div class="children-card-header">
        <div class="children-card-title">
          <i class="fas fa-list-check"></i> Λίστα Παιδιών
        </div>
        <a href="index.php" class="btn-parent-back">
          <i class="fas fa-arrow-left"></i><span class="nav-label">Πίσω</span>
        </a>
      </div>

      <?php if (empty($children)): ?>
        <div class="empty-parent">
          <i class="fas fa-user-xmark"></i>
          <p>Δεν βρέθηκαν παιδιά. Επικοινωνήστε με τη σχολή.</p>
        </div>
      <?php else: ?>
        <div class="children-table-wrap">
          <table class="children-table">
            <thead>
              <tr>
                <th>Παιδί</th>
                <th>Συνδρομή</th>
                <th>Κατάσταση</th>
                <th class="hide-sm">Τελ. Πληρωμή</th>
                <th>Ενέργειες</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($children as $child):
                  $initial = mb_strtoupper(mb_substr($child['full_name'], 0, 1, 'UTF-8'), 'UTF-8');
                  $statusInfo = match($child['status']) {
                      'paid'    => ['label' => 'Εξοφλημένη', 'class' => 'paid', 'icon' => 'fa-circle-check'],
                      'pending' => ['label' => 'Σε Αναμονή', 'class' => 'pending', 'icon' => 'fa-clock'],
                      default   => ['label' => 'Ληξιπρόθεσμη', 'class' => 'overdue', 'icon' => 'fa-circle-exclamation'],
                  };
              ?>
              <tr>
                <td>
                  <div class="athlete-name">
                    <div class="athlete-avatar"><?= $initial ?></div>
                    <?= htmlspecialchars($child['full_name']) ?>
                  </div>
                </td>
                <td>
                  <span class="fee-amount">€<?= number_format($child['monthly_fee'], 2) ?></span>
                </td>
                <td>
                  <span class="pbadge <?= $statusInfo['class'] ?>">
                    <i class="fas <?= $statusInfo['icon'] ?>"></i> <?= $statusInfo['label'] ?>
                  </span>
                </td>
                <td class="hide-sm" style="color:var(--muted);font-size:0.8rem">
                  <?= $child['last_payment_date'] ? date('d/m/Y', strtotime($child['last_payment_date'])) : '—' ?>
                </td>
                <td>
                  <a href="payment-history.php?athlete_id=<?= $child['id'] ?>" class="btn-parent-primary">
                    <i class="fas fa-history"></i><span class="nav-label">Ιστορικό</span>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- Bottom Tab Bar (mobile only) -->
<nav class="pp-bottom-nav">
  <div class="pp-bottom-nav-inner">
    <a href="index.php"><i class="fas fa-house"></i>Αρχική</a>
    <a href="children.php" class="active"><i class="fas fa-children"></i>Παιδιά</a>
    <a href="settings.php"><i class="fas fa-gear"></i>Ρυθμίσεις</a>
    <a href="<?= APP_URL ?>/logout.php" class="nav-logout"><i class="fas fa-right-from-bracket"></i>Έξοδος</a>
  </div>
</nav>

</body>
</html>