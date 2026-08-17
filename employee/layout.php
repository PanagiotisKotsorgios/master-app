<?php
/**
 * ============================================================
 * employee/layout.php — Employee Panel Layout
 * ============================================================
 */

if (!defined('EMPLOYEE_LAYOUT_BOOTSTRAPPED')) {
    define('EMPLOYEE_LAYOUT_BOOTSTRAPPED', true);

    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

function renderEmpHead(string $title): void { ?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> - MAster Employee</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
  <script src="<?= APP_URL ?>/employee/emp-ui.js" defer></script>
  <style>
    :root{--bg:#07090f;--panel:#111520;--panel-3:#181e2e;--border:#2a3248;--border-soft:rgba(255,255,255,.07);--text:#f0f2ff;--muted:#8892b0;--muted-2:#66728f;--accent:#e63946;--accent-2:#c0303b;--green:#2dc653;--red:#e63946;--gold:#f0a500;--blue:#58a6ff;--sidebar-w:268px;--radius:18px;--shadow:0 24px 60px rgba(0,0,0,.38)}
    *,*::before,*::after{box-sizing:border-box}html{height:100%}body{margin:0;min-height:100vh;background:radial-gradient(circle at top left, rgba(230,57,70,.08), transparent 24%),radial-gradient(circle at bottom right, rgba(230,57,70,.04), transparent 18%),linear-gradient(180deg, #07090f 0%, #0a1020 100%);color:var(--text);font-family:'DM Sans',sans-serif;display:flex}a{color:inherit;text-decoration:none}
    .emp-sidebar{width:var(--sidebar-w);min-height:100vh;position:fixed;inset:0 auto 0 0;background:rgba(17,21,32,.95);border-right:1px solid var(--border-soft);backdrop-filter:blur(18px);display:flex;flex-direction:column;z-index:110;overflow-y:auto}.emp-main{margin-left:var(--sidebar-w);min-height:100vh;flex:1;display:flex;flex-direction:column}
    .emp-sidebar-logo{padding:1.25rem 1.2rem 1rem;border-bottom:1px solid var(--border-soft)}.emp-brand{display:flex;align-items:center;gap:.85rem}.emp-brand img{width:44px;height:44px;object-fit:contain;flex-shrink:0}.emp-brand-word{font-family:'Bebas Neue',sans-serif;letter-spacing:.06em;font-size:2rem;line-height:1}.emp-brand-word span{color:var(--accent)}.emp-role-pill{display:inline-flex;align-items:center;gap:.45rem;margin-top:.9rem;background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.28);color:#ffb3b8;padding:.42rem .8rem;border-radius:999px;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
    .emp-nav-wrap{padding:.8rem}.emp-nav-label{padding:.65rem .7rem .4rem;color:var(--muted-2);font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em}.emp-nav-item{display:flex;align-items:center;gap:.8rem;padding:.82rem .88rem;border-radius:14px;color:#c2cae0;font-size:.95rem;font-weight:600;transition:.18s ease;margin-bottom:.22rem;border:1px solid transparent}.emp-nav-item:hover{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.06);color:#fff;transform:translateX(1px)}.emp-nav-item.active{background:linear-gradient(135deg, rgba(230,57,70,.18), rgba(230,57,70,.07));border-color:rgba(230,57,70,.35);color:#fff}.emp-nav-item .icon{width:20px;text-align:center;flex-shrink:0;color:#c2cae0}.emp-sidebar-bottom{margin-top:auto;padding:.8rem;border-top:1px solid var(--border-soft)}
    .emp-topbar{position:sticky;top:0;z-index:90;display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;background:rgba(10,16,32,.74);border-bottom:1px solid var(--border-soft);backdrop-filter:blur(16px)}.emp-topbar h1{margin:0;font-size:1.15rem;font-weight:800}.topbar-right{display:flex;align-items:center;gap:.8rem}.user-badge{display:flex;align-items:center;gap:.7rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);padding:.45rem .6rem;border-radius:999px}.user-avatar{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--red),var(--accent-2));font-weight:800;color:#fff;flex-shrink:0}.emp-content{padding:1.5rem;flex:1}
    .section-title{font-size:1.45rem;font-weight:800;margin-bottom:.35rem}.section-sub{font-size:.95rem;color:var(--muted);margin-bottom:1.4rem;line-height:1.6}.card,.stat-card{background:rgba(17,21,32,.92);border:1px solid var(--border-soft);border-radius:var(--radius);box-shadow:var(--shadow)}.card{padding:1.3rem 1.35rem;margin-bottom:1.2rem}.card-title{display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;font-size:1rem;font-weight:800}.card-title .icon{color:#d9d1ff}.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:1rem;margin-bottom:1.2rem}.stat-card{padding:1.15rem 1.2rem}.stat-label{font-size:.8rem;color:var(--muted);margin-bottom:.45rem}.stat-val{font-size:1.85rem;font-weight:800}.stat-sub{font-size:.78rem;color:var(--muted);margin-top:.3rem}
    .tbl-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.9rem}th{padding:.82rem 1rem;font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:#99a4c7;background:rgba(255,255,255,.03);text-align:left;border-bottom:1px solid rgba(255,255,255,.06)}td{padding:.88rem 1rem;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}tbody tr:hover td{background:rgba(255,255,255,.025)}
    .badge{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .72rem;border-radius:999px;font-size:.75rem;font-weight:800}.badge-green{background:rgba(45,198,83,.14);color:#9ef0b2}.badge-red{background:rgba(230,57,70,.14);color:#ffb1b8}.badge-gold{background:rgba(240,165,0,.14);color:#ffd98a}.badge-blue{background:rgba(88,166,255,.14);color:#b9dbff}.badge-purple{background:rgba(123,97,255,.14);color:#ddd5ff}.badge-muted{background:rgba(255,255,255,.06);color:#c2cae0}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.68rem 1rem;border:none;border-radius:14px;cursor:pointer;font-family:inherit;font-size:.9rem;font-weight:800;transition:.18s ease}.btn:hover{transform:translateY(-1px)}.btn-primary{background:linear-gradient(135deg,var(--red),var(--accent-2));color:#fff;box-shadow:0 4px 14px rgba(230,57,70,.3)}.btn-green{background:rgba(45,198,83,.14);color:#9ef0b2;border:1px solid rgba(45,198,83,.24)}.btn-red{background:rgba(230,57,70,.12);color:#ffb1b8;border:1px solid rgba(230,57,70,.24)}.btn-ghost{background:rgba(255,255,255,.04);color:#eef2ff;border:1px solid rgba(255,255,255,.08)}
    .form-group{margin-bottom:1rem}.form-group label{display:block;margin-bottom:.45rem;font-size:.83rem;color:var(--muted);font-weight:700}.form-control{width:100%;background:var(--panel-3);border:1px solid var(--border);border-radius:14px;padding:.78rem .92rem;color:var(--text);font-family:inherit;font-size:.92rem;outline:none}.form-control:focus{border-color:rgba(230,57,70,.8);box-shadow:0 0 0 4px rgba(230,57,70,.15)}.alert{border-radius:16px;padding:.95rem 1rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.7rem;font-size:.92rem;line-height:1.55;border:1px solid transparent}.alert-success{background:rgba(45,198,83,.1);border-color:rgba(45,198,83,.28);color:#9ef0b2}.alert-danger{background:rgba(230,57,70,.1);border-color:rgba(230,57,70,.28);color:#ffb1b8}.alert-info{background:rgba(88,166,255,.1);border-color:rgba(88,166,255,.22);color:#b9dbff}.alert-warn{background:rgba(240,165,0,.12);border-color:rgba(240,165,0,.24);color:#ffd98a}
    .search-input-wrap{position:relative}.search-input-wrap input{padding-left:2.55rem}.search-input-wrap i{position:absolute;left:.95rem;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}.pagination{display:flex;align-items:center;gap:.42rem;flex-wrap:wrap}.pagination a,.pagination span{min-width:36px;height:36px;padding:0 .55rem;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:#d2d9ef;font-size:.84rem}.pagination .active{background:linear-gradient(135deg,var(--red),var(--accent-2));color:#fff;border-color:transparent}
    .emp-debug{margin:0 1.5rem 1.25rem;background:rgba(17,21,32,.96);border:1px solid rgba(230,57,70,.28);border-radius:18px;padding:1rem;box-shadow:var(--shadow)}.emp-debug-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:.6rem}.emp-debug h2{margin:0;font-size:1rem;font-weight:800}.emp-debug p{margin:0;color:var(--muted);font-size:.88rem;line-height:1.6}.emp-debug-count{min-width:34px;height:34px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:rgba(230,57,70,.14);color:#ffb1b8;font-weight:800}.emp-debug-item{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:14px;padding:.85rem .95rem;margin-top:.7rem}.emp-debug-badge{display:inline-flex;align-items:center;gap:.4rem;padding:.28rem .62rem;border-radius:999px;background:rgba(230,57,70,.14);color:#ffb1b8;font-size:.73rem;font-weight:800;margin-bottom:.55rem}.emp-debug-message code{white-space:pre-wrap;word-break:break-word;color:#fff;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.84rem}.emp-debug-meta{font-size:.76rem;color:#98a4c6;margin-top:.45rem}.emp-hamburger{display:none}
    @media (max-width: 920px){.emp-sidebar{transform:translateX(-100%);transition:transform .22s ease}.emp-sidebar.open{transform:translateX(0)}.emp-main{margin-left:0}.emp-hamburger{display:inline-flex !important;background:none;border:none;color:#fff;font-size:1.15rem;padding:.35rem;cursor:pointer}.emp-content{padding:1rem}.emp-debug{margin:0 1rem 1rem}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.emp-topbar{padding:.9rem 1rem}}
    @media (max-width: 640px){.stats-grid{grid-template-columns:1fr}.section-title{font-size:1.25rem}.card{padding:1rem}th,td{padding:.75rem .8rem}}
  </style>
</head>
<?php }

function renderEmpSidebar(string $active = ''): void {
  // Load privileges helper if not already loaded
  if (!function_exists('empCan')) {
    require_once __DIR__ . '/privileges.php';
  }

  $allNav = [
    ['href' => APP_URL . '/employee/',             'icon' => 'fa-solid fa-gauge-high',       'label' => 'Dashboard',    'key' => 'dash',     'priv' => null],
    ['href' => APP_URL . '/employee/schools.php',  'icon' => 'fa-solid fa-school',           'label' => 'Σχολές',       'key' => 'schools',  'priv' => 'schools_view'],
    ['href' => APP_URL . '/employee/users.php',    'icon' => 'fa-solid fa-users',            'label' => 'Χρήστες',      'key' => 'users',    'priv' => 'users_view'],
    ['href' => APP_URL . '/employee/athletes.php', 'icon' => 'fa-solid fa-person-running',   'label' => 'Αθλητές',      'key' => 'athletes', 'priv' => 'athletes_view'],
    ['href' => APP_URL . '/employee/payments.php', 'icon' => 'fa-solid fa-credit-card',      'label' => 'Πληρωμές',     'key' => 'payments', 'priv' => 'payments_view'],
    ['href' => APP_URL . '/employee/logs.php',     'icon' => 'fa-solid fa-clipboard-list',   'label' => 'Audit Log',    'key' => 'logs',     'priv' => 'logs_view'],
    ['href' => APP_URL . '/employee/search.php',   'icon' => 'fa-solid fa-magnifying-glass', 'label' => 'Αναζήτηση',    'key' => 'search',   'priv' => 'search_access'],
    ['href' => APP_URL . '/employee/backups.php',  'icon' => 'fa-solid fa-database',         'label' => 'Backups',      'key' => 'backups',  'priv' => 'backups_view'],
    ['href' => APP_URL . '/employee/health.php',   'icon' => 'fa-solid fa-heart-pulse',      'label' => 'System Health','key' => 'health',   'priv' => 'health_view'],
    ['href' => APP_URL . '/employee/analytics.php','icon' => 'fa-solid fa-chart-line',       'label' => 'Analytics',    'key' => 'analytics','priv' => 'analytics_view'],
    ['href' => APP_URL . '/employee/export.php',   'icon' => 'fa-solid fa-file-export',      'label' => 'Export Center','key' => 'export',   'priv' => 'export_schools,export_users,export_athletes,export_payments'],
  ];

  // Filter nav items based on privileges
  $nav = array_filter($allNav, function($item) {
    if ($item['priv'] === null) return true;
    // Support comma-separated OR privileges (show if employee has ANY of them)
    foreach (explode(',', $item['priv']) as $p) {
      if (empCan(trim($p))) return true;
    }
    return false;
  });
?>
<aside class="emp-sidebar" id="empSidebar"><div class="emp-sidebar-logo"><div class="emp-brand"><img src="<?= APP_URL ?>/assets/img/logo-tr.png" alt="MAster"><div class="emp-brand-word">MA<span>ster</span></div></div><div class="emp-role-pill"><i class="fa-solid fa-id-badge"></i> Employee Panel</div></div><div class="emp-nav-wrap"><div class="emp-nav-label">Πλοήγηση</div><?php foreach ($nav as $item): ?><a href="<?= $item['href'] ?>" class="emp-nav-item <?= $active === $item['key'] ? 'active' : '' ?>"><span class="icon"><i class="<?= $item['icon'] ?>"></i></span><span><?= h($item['label']) ?></span></a><?php endforeach; ?></div><div class="emp-sidebar-bottom"><a href="<?= APP_URL ?>/logout.php" class="emp-nav-item" style="color:#ffb1b8"><span class="icon"><i class="fa-solid fa-right-from-bracket"></i></span><span>Αποσύνδεση</span></a></div></aside>
<?php }

function renderEmpTopbar(string $title): void { $user = currentUser(); $name = $user['name'] ?? 'Employee'; $flash = getFlash(); $initial = function_exists('mb_substr') ? mb_strtoupper((string)mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1)); ?>
<div class="emp-topbar"><div style="display:flex;align-items:center;gap:.8rem"><button class="emp-hamburger" id="empHamburger" data-emp-sidebar-toggle="1"><i class="fa-solid fa-bars"></i></button><h1><?= h($title) ?></h1></div><div class="topbar-right"><div class="user-badge"><div class="user-avatar"><?= h($initial) ?></div><div><div style="font-size:.86rem;font-weight:800;color:#fff"><?= h($name) ?></div><div style="font-size:.75rem;color:#d9d1ff"><i class="fa-solid fa-id-badge"></i> Employee</div></div></div></div></div>
<?php if ($flash): ?><div style="padding:1rem 1.5rem 0"><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-danger' ?>"><i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i><div><?= h($flash['msg']) ?></div></div></div><?php endif; ?>
<?php }

function renderEmpClose(): void { ?>
</div>
</body></html>
<?php }