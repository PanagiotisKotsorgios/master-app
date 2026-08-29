<?php
// includes/layout.php — renderHead($title), renderSidebar($activeNav), renderTopbar($title)

/**
 * HEAD
 */
function renderHead(string $title): void {
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> - MAster - Εφαρμογή Διαχείρισης Αθλητικών Συλλόγων / Συνδρομών Μελλών</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">
  <?php
    $__pl_file = __DIR__ . '/../assets/css/postlogin-portal-theme.css';
    $__pl_ver  = @filemtime($__pl_file) ?: time();
  ?>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/postlogin-portal-theme.css?v=<?= $__pl_ver ?>">
  <?php
    $__pr_file = __DIR__ . '/../assets/css/print-clean.css';
    $__pr_ver  = @filemtime($__pr_file) ?: time();
  ?>
  <!-- Xlsx-style print reset. Screen unaffected; @media print takes over. -->
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print-clean.css?v=<?= $__pr_ver ?>" media="all">

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
  <link rel="shortcut icon" href="../assets/img/favicon.png" type="image/png">

  <style>
    /* ─────────────────────────────────────────
       BASE SIDEBAR / TOPBAR
    ───────────────────────────────────────── */
    .sidebar{
      display:flex;
      flex-direction:column;
      min-height:100vh;
    }

    .sidebar-header{
      position:sticky;
      top:0;
      z-index:2;
      background:inherit;
      padding:.55rem 0;
    }

    .sidebar-logo{
      padding:.15rem 1rem .1rem 1rem;
    }

    .sidebar-school{
      width:calc(100% - 2rem);
      margin:.22rem 1rem;
      padding:0;
      display:flex;
      align-items:center;
      justify-content:flex-start;
      text-align:left;
      font-weight:700;
      font-size:.82rem;
      line-height:1.2;
      color:#f0f2ff;
      background:none;
      box-shadow:none;
      border:none;
      border-radius:0;
      white-space:normal !important;
      overflow-wrap:anywhere;
      word-break:break-word;
    }

    .topbar-hamburger{
      display:none;
      flex-direction:column;
      gap:4px;
      cursor:pointer;
      background:none;
      border:none;
      padding:.4rem .45rem;
      border-radius:8px;
      transition:background .2s;
      flex-shrink:0;
    }

    .topbar-hamburger:hover{
      background:var(--bg3,#1c1f29);
    }

    .topbar-hamburger span{
      display:block;
      width:22px;
      height:2px;
      background:var(--text,#eef0f8);
      border-radius:2px;
      transition:all .25s;
    }

    .topbar-hamburger.open span:nth-child(1){transform:translateY(6px) rotate(45deg)}
    .topbar-hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0)}
    .topbar-hamburger.open span:nth-child(3){transform:translateY(-6px) rotate(-45deg)}

    .sidebar .nav-item{
      border-bottom:1px solid rgba(255,255,255,.11);
    }

    .logo-text{
      font-family:'DM Sans', sans-serif !important;
      font-weight:900 !important;
      text-transform:none !important;
    }

    .logo-text em{
      font-family:'DM Sans', sans-serif !important;
      font-style:normal !important;
      font-weight:700 !important;
    }

    /* ─────────────────────────────────────────
       USER CHIP
    ───────────────────────────────────────── */
    .user-chip{
      display:flex !important;
      align-items:center;
      gap:clamp(.3rem,1.5vw,.6rem);
      padding:clamp(.28rem,1.2vw,.45rem) clamp(.35rem,1.8vw,.65rem);
      border-radius:30px;
      cursor:pointer;
    }

    .user-chip > div:not(.avatar){
      display:flex !important;
      flex-direction:column;
    }

    .uname{
      display:block !important;
      font-size:clamp(.7rem,2.5vw,.88rem);
      font-weight:700;
      line-height:1.2;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      max-width:clamp(55px,18vw,130px);
      color:#f0f2ff;
    }

    .urole{
      display:block !important;
      font-size:clamp(.58rem,1.8vw,.72rem);
      color:#6b7494;
      line-height:1.1;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      max-width:clamp(55px,18vw,130px);
    }

    .avatar{
      width:clamp(28px,7vw,34px) !important;
      height:clamp(28px,7vw,34px) !important;
      font-size:clamp(.68rem,2.2vw,.82rem) !important;
      flex-shrink:0;
    }

    /* ─────────────────────────────────────────
       DESKTOP
    ───────────────────────────────────────── */
    @media(min-width:769px){
      .sidebar-overlay{display:none !important}
    }

    /* ─────────────────────────────────────────
       MOBILE / TABLET
    ───────────────────────────────────────── */
    @media(max-width:900px){
      .sidebar{
        padding-top:env(safe-area-inset-top, 0px);
        overflow-y:auto !important;
        -webkit-overflow-scrolling:touch;
        overscroll-behavior:contain;
        scrollbar-width:thin;
        scrollbar-color:#e63946 rgba(255,255,255,.07);
      }

      .sidebar-header{
        padding-top:calc(.55rem + env(safe-area-inset-top, 0px));
      }

      .sidebar-school{
        font-size:.86rem;
      }

      .sidebar::-webkit-scrollbar{
        width:8px;
      }

      .sidebar::-webkit-scrollbar-track{
        background:rgba(255,255,255,.06);
        border-radius:999px;
      }

      .sidebar::-webkit-scrollbar-thumb{
        background:linear-gradient(180deg,#ff5a66 0%, #e63946 55%, #b91c2b 100%);
        border-radius:999px;
        border:1px solid rgba(255,255,255,.08);
      }

      .sidebar::-webkit-scrollbar-thumb:hover{
        background:linear-gradient(180deg,#ff6b76 0%, #ef4444 55%, #c81e1e 100%);
      }

      /* visible only on mobile overlay sidebar */
      .sidebar::after{
        content:'';
        position:sticky;
        bottom:0;
        left:0;
        right:0;
        display:block;
        height:22px;
        margin-top:auto;
        pointer-events:none;
        background:linear-gradient(
          to bottom,
          rgba(10,14,22,0),
          rgba(230,57,70,.10) 45%,
          rgba(10,14,22,.94) 100%
        );
        z-index:2;
      }

.sidebar .nav-item{
  border-bottom:1px solid rgba(255,255,255,.10);
}

.sidebar .nav-item::after{
  content:'';
  position:absolute;
  left:1rem;
  right:1rem;
  bottom:0;
  height:1px;
  background:linear-gradient(
    90deg,
    transparent,
    rgba(255,255,255,.18),
    transparent
  );
}

    @media(max-width:768px){
      .topbar-hamburger{display:flex !important}
      body{overflow-x:hidden}
      .page-body{padding:.85rem !important}
      .card{border-radius:10px}
      .btn-group{flex-wrap:wrap;gap:.4rem}
      .search-bar{max-width:100% !important}
      .form-row.col-2,
      .form-row.col-3{grid-template-columns:1fr !important}
      .stat-card .stat-val{font-size:1.8rem}
    }

    @media(max-width:480px){
      .topbar{padding:.6rem .875rem !important}
      .page-title{font-size:.88rem !important}
      .stat-card .stat-val{font-size:1.55rem}
      .tabs{
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
        flex-wrap:nowrap;
        padding-bottom:2px;
      }
      .tab{
        white-space:nowrap;
        flex-shrink:0;
      }
      .modal-body{padding:.875rem}
      .modal-footer{padding:.75rem .875rem}
    }

    @media(max-width:350px){
      .urole{display:none !important}
      .uname{max-width:50px}
    }

    /* ─────────────────────────────────────────
       TRIAL BANNER
    ───────────────────────────────────────── */
    #dm-trial-close:hover{
      background:rgba(255,255,255,.22) !important;
      transform:scale(1.06);
    }

    #dm-trial-close:active{
      transform:scale(.98);
    }
  </style>
</head>
<?php
}

/**
 * SIDEBAR
 */
function renderSidebar(string $active = ''): void {
    $user        = currentUser();
    $isSA        = isSuperAdmin();
    $plan        = $isSA ? null : schoolPlan();
    $planSlug    = $plan['slug'] ?? 'basic';
    $planExpires = $plan['plan_expires'] ?? '';
    $school      = $_SESSION['school_name'] ?? 'MAster';

    $navItems = [];

    $sid2 = function_exists('schoolId') ? schoolId() : 0;
    $privacyMode = (bool)($_SESSION['privacy_mode_' . $sid2] ?? false);

    if ($isSA) {
        // ── Admin sidebar organised into collapsible categories ──
        // Each section has its own label + icon so grouping is obvious;
        // sections are collapsible (state in localStorage, see JS below).
        $navItems = [
            'overview' => [
                '_label' => 'Επισκόπηση',
                '_icon'  => 'fa-solid fa-gauge',
                'items'  => [
                    ['href' => APP_URL.'/admin/',                    'icon' => 'fa-solid fa-sliders',        'label' => 'Κεντρική',        'key' => 'admin_dash'],
                    ['href' => APP_URL.'/admin/stats.php',           'icon' => 'fa-solid fa-chart-line',     'label' => 'Στατιστικά',      'key' => 'admin_stats'],
                    ['href' => APP_URL.'/admin/reports.php',         'icon' => 'fa-solid fa-chart-pie',      'label' => 'Αναφορές',        'key' => 'admin_reports'],
                    ['href' => APP_URL.'/admin/churn.php',           'icon' => 'fa-solid fa-user-slash',     'label' => 'Churn & MRR',     'key' => 'admin_churn'],
                    ['href' => APP_URL.'/admin/activity.php',        'icon' => 'fa-solid fa-wave-square',    'label' => 'Activity Feed',   'key' => 'admin_activity'],
                ],
            ],
            'accounts' => [
                '_label' => 'Σύλλογοι & Χρήστες',
                '_icon'  => 'fa-solid fa-people-group',
                'items'  => [
                    ['href' => APP_URL.'/admin/schools.php',         'icon' => 'fa-solid fa-school',         'label' => 'Σχολές',            'key' => 'admin_schools'],
                    ['href' => APP_URL.'/admin/school_approvals.php','icon' => 'fa-solid fa-user-check',     'label' => 'Έγκριση Σχολών',    'key' => 'admin_school_approvals'],
                    ['href' => APP_URL.'/admin/users.php',           'icon' => 'fa-solid fa-users',          'label' => 'Χρήστες',           'key' => 'admin_users'],
                    ['href' => APP_URL.'/admin/parent-accounts.php', 'icon' => 'fa-solid fa-people-roof',    'label' => 'Portal Γονέων',     'key' => 'admin_parent_accounts'],
                    ['href' => APP_URL.'/admin/privileges.php',      'icon' => 'fa-solid fa-shield-halved',  'label' => 'Privileges',        'key' => 'admin_privileges'],
                ],
            ],
            'finance' => [
                '_label' => 'Οικονομικά',
                '_icon'  => 'fa-solid fa-euro-sign',
                'items'  => [
                    ['href' => APP_URL.'/admin/plans.php',           'icon' => 'fa-solid fa-boxes-stacked',  'label' => 'Πλάνα',                    'key' => 'admin_plans'],
                    ['href' => APP_URL.'/admin/payments.php',        'icon' => 'fa-solid fa-credit-card',    'label' => 'Πληρωμές',                 'key' => 'admin_payments'],
                    ['href' => APP_URL.'/admin/coupons.php',         'icon' => 'fa-solid fa-ticket',         'label' => 'Κουπόνια',                 'key' => 'admin_coupons'],
                    ['href' => APP_URL.'/admin/event_invoices.php',  'icon' => 'fa-solid fa-file-invoice',   'label' => 'Τιμολόγια Διοργανώσεων',   'key' => 'admin_event_invoices'],
                ],
            ],
            'comms' => [
                '_label' => 'Επικοινωνία',
                '_icon'  => 'fa-solid fa-paper-plane',
                'items'  => [
                    ['href' => APP_URL.'/admin/notifications.php',   'icon' => 'fa-solid fa-paper-plane',       'label' => 'Αποστολές',        'key' => 'admin_notif'],
                    ['href' => APP_URL.'/admin/broadcast.php',       'icon' => 'fa-solid fa-bullhorn',          'label' => 'Broadcast',        'key' => 'admin_broadcast'],
                    ['href' => APP_URL.'/admin/marketing-popup.php', 'icon' => 'fa-solid fa-lightbulb',         'label' => 'Popup Καμπάνιας',  'key' => 'admin_marketing_popup'],
                    ['href' => APP_URL.'/admin/email-logs.php',      'icon' => 'fa-solid fa-envelope-open-text','label' => 'Email & SMS Logs', 'key' => 'admin_email_logs'],
                    ['href' => APP_URL.'/admin/sms-calculator.php',  'icon' => 'fa-solid fa-calculator',        'label' => 'SMS Κοστολόγηση',  'key' => 'admin_sms_calc'],
                    ['href' => APP_URL.'/pages/opt-out-manual.php',  'icon' => 'fa-solid fa-bell-slash',        'label' => 'Opt-out Χειροκίνητα', 'key' => 'admin_opt_out'],
                ],
            ],
            'events' => [
                '_label' => 'Διοργανώσεις',
                '_icon'  => 'fa-solid fa-trophy',
                'items'  => [
                    ['href' => APP_URL.'/admin/event_moderation.php','icon' => 'fa-solid fa-flag',              'label' => 'Έλεγχος Διοργανώσεων','key' => 'admin_event_mod'],
                    ['href' => APP_URL.'/admin/federations.php',     'icon' => 'fa-solid fa-handshake',         'label' => 'Ομοσπονδίες',       'key' => 'admin_federations'],
                ],
            ],
            'system' => [
                '_label' => 'Σύστημα',
                '_icon'  => 'fa-solid fa-server',
                'items'  => [
                    ['href' => APP_URL.'/admin/system-settings.php', 'icon' => 'fa-solid fa-gears',             'label' => 'System Settings',  'key' => 'admin_sys_settings'],
                    ['href' => APP_URL.'/admin/health.php',          'icon' => 'fa-solid fa-heart-pulse',       'label' => 'System Health',    'key' => 'admin_health'],
                    ['href' => APP_URL.'/admin/backups.php',         'icon' => 'fa-solid fa-database',          'label' => 'Backups',          'key' => 'admin_backups'],
                    ['href' => APP_URL.'/admin/audit.php',           'icon' => 'fa-solid fa-clipboard-list',    'label' => 'Audit Log',        'key' => 'admin_audit'],
                    ['href' => APP_URL.'/admin/consent-logs.php',    'icon' => 'fa-solid fa-file-shield',       'label' => 'Consent Log',      'key' => 'admin_consent_logs'],
                ],
            ],
        ];
    } else {
        $mainItems = [
            ['href' => APP_URL.'/dashboard/',              'icon' => 'fa-solid fa-house',           'label' => 'Κεντρική',          'key' => 'dashboard'],
            ['href' => APP_URL.'/pages/athletes.php',      'icon' => 'fa-solid fa-person-running',  'label' => 'Αθλητές',           'key' => 'athletes'],
            ['href' => APP_URL.'/pages/subscriptions.php', 'icon' => 'fa-solid fa-money-bill-wave', 'label' => 'Πληρωμές',          'key' => 'subscriptions'],
            ['href' => APP_URL.'/pages/departments.php',   'icon' => 'fa-solid fa-folder-open',     'label' => 'Τμήματα',           'key' => 'departments'],
            ['href' => APP_URL.'/pages/notifications.php', 'icon' => 'fa-solid fa-paper-plane',     'label' => 'Ειδοποιήσεις',      'key' => 'notifications'],
            ['href' => APP_URL.'/pages/events.php',        'icon' => 'fa-solid fa-trophy',          'label' => 'Διοργανώσεις',      'key' => 'events'],
        ];

        if (!$privacyMode && $planSlug === 'pro') {
            $mainItems[] = [
                'href'  => APP_URL.'/pages/economics_reports.php?tab=economics',
                'icon'  => 'fa-solid fa-chart-bar',
                'label' => 'Οικονομικά',
                'key'   => 'economics',
                'pro'   => true,
            ];
            $mainItems[] = [
                'href'  => APP_URL.'/pages/payment_analytics.php',
                'icon'  => 'fa-solid fa-chart-line',
                'label' => 'Αναλυτικά',
                'key'   => 'payment_analytics',
                'pro'   => true,
            ];
        }

        $navItems = [
            'main'     => $mainItems,
            'settings' => [
                ['href' => APP_URL.'/pages/settings.php',         'icon' => 'fa-solid fa-gear',       'label' => 'Ρυθμίσεις',    'key' => 'settings'],
                ['href' => APP_URL.'/docs/user-guide.html', 'target' => '_blank', 'icon' => 'fa-solid fa-book', 'label' => 'Οδηγός Χρήσης', 'key' => 'help'],
                ...($planSlug !== 'pro' ? [['href' => APP_URL.'/pages/upgrade.php', 'icon' => 'fa-solid fa-star', 'label' => 'Αναβάθμιση', 'key' => 'upgrade']] : []),
            ],
        ];
    }
?>
<div class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <div class="logo-text">MA<em>ster</em></div>
    </div>
  </div>

  <?php foreach ($navItems as $section => $entry):
      // Admin sections carry _label/_icon + nested `items`; non-admin
      // legacy sections are a flat item list under 'main' / 'settings'.
      $sectionLabel = null; $sectionIcon = null;
      if ($isSA && isset($entry['items'])) {
          $sectionLabel = $entry['_label'] ?? null;
          $sectionIcon  = $entry['_icon']  ?? 'fa-solid fa-folder';
          $items        = $entry['items'];
      } else {
          $items        = is_array($entry) ? $entry : [];
      }
      // Auto-open a section if any item in it is active
      $sectionActive = false;
      foreach ($items as $it) {
          if (($it['key'] ?? '') === $active) { $sectionActive = true; break; }
      }
      $sectionSlug = preg_replace('/[^a-z0-9]/', '', strtolower((string)$section));
  ?>
    <div class="nav-section<?= $isSA && $sectionLabel ? ' has-toggle' : '' ?><?= $sectionActive ? ' section-open' : '' ?>"
         data-section="<?= h($sectionSlug) ?>">
      <?php if ($isSA && $sectionLabel): ?>
        <button type="button" class="nav-label nav-label-toggle" onclick="toggleNavSection(this)">
          <i class="<?= h($sectionIcon) ?>"></i>
          <span><?= h($sectionLabel) ?></span>
          <i class="fa-solid fa-chevron-down chev"></i>
        </button>
      <?php elseif (!$isSA && $section === 'settings'): ?>
        <div class="nav-label"><i class="fa-solid fa-gear"></i> Διαχείριση</div>
      <?php endif; ?>

      <div class="nav-section-body">
      <?php foreach ($items as $item):
          $isPro  = !empty($item['pro']);
          $locked = $isPro && !planHas('competitions_enabled') && !$isSA;

          $isActive = ($active === $item['key'])
                   || ($active === 'reports'        && $item['key'] === 'economics')
                   || ($active === 'economics'      && $item['key'] === 'economics')
                   || ($active === 'event_invoices' && $item['key'] === 'events');

          $cls = $isActive ? 'active' : '';
          if ($locked) $cls .= ' text-muted';
      ?>
        <a href="<?= $locked ? '#' : $item['href'] ?>"
           class="nav-item <?= $cls ?>"
           <?= $locked ? 'onclick="showUpgrade();return false"' : '' ?>
           <?= (!$locked && !empty($item['target'])) ? 'target="'.htmlspecialchars($item['target'], ENT_QUOTES).'" rel="noopener"' : '' ?>
           style="<?= $locked ? 'opacity:.5;cursor:not-allowed' : '' ?>">
          <span class="icon"><i class="<?= $item['icon'] ?>"></i></span>
          <span><?= $item['label'] ?></span>
          <?php if ($isPro && !$isSA && $planSlug !== 'pro'): ?>
            <span class="tag" style="margin-left:auto">Pro</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="sidebar-bottom">
    <?php if (!$isSA && $plan): ?>
      <div class="sidebar-plan">
        <div class="plan-name"><?= h($plan['name'] ?? '') ?> Συνδρομητής</div>
        <?php if ($planExpires): ?>
          <div class="plan-expires">Λήξη: <?= formatDate($planExpires) ?></div>
        <?php endif; ?>
        <?php if ($planSlug === 'basic'): ?>
          <a href="<?= APP_URL ?>/pages/upgrade.php" style="font-size:.72rem;color:var(--gold)">
            <i class="fa-solid fa-circle-arrow-up"></i> Αναβάθμιση σε Pro
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/logout.php" class="nav-item nav-logout">
      <span class="icon"><i class="fa-solid fa-right-from-bracket"></i></span>
      <span>Αποσύνδεση</span>
    </a>
  </div>
</div>

<style>
/* ── Logout button (red bg + white fg, NO divider line at all) ── */
.sidebar .nav-logout,
.sidebar a.nav-logout,
.nav-logout{
  background: linear-gradient(135deg,#e63946,#c72832) !important;
  color: #ffffff !important;
  border: 1px solid rgba(255,255,255,.14) !important;
  border-top: 1px solid rgba(255,255,255,.14) !important;
  border-bottom: 1px solid rgba(255,255,255,.14) !important;
  border-radius: 10px !important;
  margin: .5rem 1rem 0 !important;
  padding: .75rem 1rem !important;
  box-shadow: 0 4px 14px -4px rgba(230,57,70,.5) !important;
  font-weight: 800 !important;
  position: relative;
  overflow: hidden;
  transition: transform .15s, box-shadow .15s;
}
.sidebar .nav-logout:hover,
.nav-logout:hover{
  background: linear-gradient(135deg,#c72832,#a51e28) !important;
  color: #ffffff !important;
  box-shadow: 0 6px 20px -4px rgba(230,57,70,.7) !important;
  transform: translateY(-1px);
}
.sidebar .nav-logout *,
.nav-logout *{ color: #ffffff !important; }
.sidebar .nav-logout i,
.nav-logout i{ color: #ffffff !important; }
/* Kill EVERY pseudo/decoration that could paint a line across it */
.sidebar .nav-logout::before,
.sidebar .nav-logout::after,
.nav-logout::before,
.nav-logout::after{ content: none !important; display: none !important; background: none !important; }
.sidebar .nav-logout span::before,
.sidebar .nav-logout span::after{ content: none !important; display: none !important; background: none !important; }
.sidebar .nav-logout{ text-decoration: none !important; }

/* Collapsible sidebar categories (admin) */
.sidebar .nav-label-toggle{
  width:100%;background:none;border:none;cursor:pointer;
  display:flex;align-items:center;gap:.6rem;
  color:#a9b3c9;font-weight:800;font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;
  padding:.85rem 1rem .5rem;margin:0;font-family:inherit;text-align:left;
  transition:color .15s;
}
.sidebar .nav-label-toggle:hover{ color:#ffffff; }
.sidebar .nav-label-toggle > span{ flex:1; }
.sidebar .nav-label-toggle i:first-child{ color:#e63946;font-size:.9rem;width:16px;text-align:center; }
.sidebar .nav-label-toggle .chev{
  font-size:.7rem;color:#8892b0;transition:transform .2s;margin-left:auto;
}
.sidebar .nav-section.has-toggle:not(.section-open) .chev{ transform:rotate(-90deg); }
.sidebar .nav-section.has-toggle .nav-section-body{
  max-height:0;overflow:hidden;transition:max-height .25s ease;
}
.sidebar .nav-section.has-toggle.section-open .nav-section-body{
  max-height:1200px;
}
</style>

<script>
(function(){
  var KEY = 'ms_admin_sidebar_sections_v1';
  var stored = {};
  try { stored = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch(e){}

  // Restore stored open/closed state on load (respects auto-open on active row)
  document.querySelectorAll('.sidebar .nav-section.has-toggle').forEach(function(sec){
    var slug = sec.dataset.section;
    if (stored[slug] === false && !sec.classList.contains('section-open')) {
      sec.classList.remove('section-open');
    } else if (stored[slug] === true) {
      sec.classList.add('section-open');
    }
  });

  window.toggleNavSection = function(btn){
    var sec = btn.closest('.nav-section');
    if (!sec) return;
    var open = sec.classList.toggle('section-open');
    var slug = sec.dataset.section;
    try {
      stored[slug] = open;
      localStorage.setItem(KEY, JSON.stringify(stored));
    } catch(e){}
  };
})();

/* Universal "Καθαρισμός" button tagger — scans buttons + anchor tags,
   applies .btn-clear class when the visible text is (or ends with)
   "Καθαρισμός". Runs once on DOMContentLoaded and again on any late
   DOM insert via a lightweight MutationObserver. */
(function(){
  function tag(el){
    if (!el || el.classList.contains('btn-clear')) return;
    var txt = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (!txt) return;
    if (txt === 'Καθαρισμός' || txt.endsWith(' Καθαρισμός') || /^Καθαρισμός\b/.test(txt)) {
      el.classList.add('btn-clear');
    }
  }
  function sweep(root){
    (root || document).querySelectorAll('a, button').forEach(tag);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ sweep(); });
  } else {
    sweep();
  }
  try {
    new MutationObserver(function(muts){
      muts.forEach(function(m){
        m.addedNodes && m.addedNodes.forEach(function(n){
          if (n.nodeType === 1) {
            if (n.tagName === 'A' || n.tagName === 'BUTTON') tag(n);
            else sweep(n);
          }
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  } catch(e){}
})();
</script>
<?php
}

/**
 * TOPBAR
 */
function renderTopbar(string $title, string $active = ''): void {
    $user     = currentUser();
    $initials = strtoupper(mb_substr($user['name'] ?? 'U', 0, 1));
    $flash    = getFlash();
    $schoolStatus = (!isSuperAdmin()) ? getSchoolStatus() : null;
?>

<?php
if ($schoolStatus && ($schoolStatus['status'] ?? '') === 'trial' && (int)($schoolStatus['days_left'] ?? 0) <= 1):
    $d     = (int)($schoolStatus['days_left'] ?? 0);
    $col   = '230,57,70';
    $hex   = '#e63946';
    $icon  = 'fa-triangle-exclamation';
?>
<div id="dm-trial-banner"
     style="background:rgba(<?= $col ?>,.1);
            border-bottom:1px solid rgba(<?= $col ?>,.25);
            padding:.55rem 1.5rem;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:1rem;
            flex-wrap:wrap;
            position:relative;">

  <span style="font-size:.88rem;color:<?= $hex ?>;font-weight:600">
    <i class="fas <?= $icon ?>" style="margin-right:.4rem"></i>
    <?= $d <= 0
        ? 'Η δοκιμαστική περίοδος έληξε σήμερα!'
        : "Απομένουν <strong>$d ημέρες</strong> από τη δωρεάν δοκιμή σας." ?>
  </span>

  <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
    <a href="<?= APP_URL ?>/pages/upgrade.php"
       style="font-size:.82rem;font-weight:800;background:<?= $hex ?>;color:#fff;
              padding:.35rem .9rem;border-radius:8px;text-decoration:none;white-space:nowrap">
      Αναβάθμιση τώρα →
    </a>

    <button type="button"
            id="dm-trial-close"
            aria-label="Κλείσιμο"
            style="width:30px;height:30px;border-radius:999px;border:none;
                   display:inline-flex;align-items:center;justify-content:center;
                   background:rgba(255,255,255,.14);color:<?= $hex ?>;
                   cursor:pointer;line-height:1;flex:0 0 auto;">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>
</div>

<script>
(function(){
  const KEY = 'dm_trial_banner_closed_at';
  const ONE_DAY = 24 * 60 * 60 * 1000;

  function shouldHide() {
    const v = localStorage.getItem(KEY);
    if (!v) return false;
    const t = parseInt(v, 10);
    if (!Number.isFinite(t)) return false;
    const now = Date.now();
    if (now - t < ONE_DAY) return true;
    localStorage.removeItem(KEY);
    return false;
  }

  document.addEventListener('DOMContentLoaded', function(){
    const banner = document.getElementById('dm-trial-banner');
    if (!banner) return;

    if (shouldHide()) {
      banner.style.display = 'none';
      return;
    }

    const btn = document.getElementById('dm-trial-close');
    if (!btn) return;

    btn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      banner.style.display = 'none';
      localStorage.setItem(KEY, String(Date.now()));
    });
  });
})();
</script>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="topbar">
  <div class="topbar-left">
    <button class="topbar-hamburger" id="menuBtn" aria-label="Μενού" onclick="toggleSidebar()">
      <span></span><span></span><span></span>
    </button>
    <div class="page-title"><?= $title ?></div>
  </div>

  <div class="topbar-right">
    <?php if (!isSuperAdmin()): ?>
      <a href="<?= APP_URL ?>/pages/notifications.php" class="btn btn-ghost btn-sm" title="Ειδοποιήσεις">
        <i class="fa-solid fa-bell"></i>
      </a>
    <?php endif; ?>

    <div class="user-dropdown">
      <div class="user-chip" id="userDropdownToggle">
        <div class="avatar"><?= $initials ?></div>
        <div>
          <div class="uname"><?= h($user['name'] ?? '') ?></div>
          <div class="urole"><?= h($user['role'] ?? '') ?></div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Rendered outside .topbar so its position:fixed is viewport-relative.
     The .topbar has backdrop-filter which would otherwise trap it and
     leave the sheet clipped/off-screen on mobile. -->
<div class="dropdown-menu" id="userDropdownMenu">
  <a class="dropdown-item" href="<?= APP_URL ?>/pages/settings.php">
    <i class="fa-solid fa-gear"></i>Ρυθμίσεις
  </a>
  <a class="dropdown-item" href="<?= APP_URL ?>/logout.php">
    <i class="fa-solid fa-right-from-bracket"></i> Αποσύνδεση
  </a>
</div>
<div class="user-dropdown-backdrop" id="userDropdownBackdrop"></div>

<style>
.user-dropdown{position:relative;cursor:pointer}
.user-chip:hover{
  background:rgba(230,57,70,.15);
  box-shadow:0 0 0 2px rgba(230,57,70,.3);
  transition:background .2s, box-shadow .2s;
  border-radius:30px;
}
.dropdown-item{
  display:flex;
  align-items:center;
  gap:.75rem;
  padding:.75rem 1rem;
  color:#f0f2ff;
  text-decoration:none;
  font-size:.95rem;
  transition:background .15s;
}
.dropdown-item:hover{
  background:rgba(230,57,70,.15);
  color:#e63946;
}
.dropdown-item i{width:20px;text-align:center}

/* ── Free-floating profile dropdown ──
   Rendered at body root (outside .topbar) so its position:fixed is
   viewport-relative, not trapped by the topbar's backdrop-filter. */
#userDropdownMenu{
  display:none;
  position:fixed;
  top:60px;
  right:12px;
  background:#0a0e16;
  border:1px solid #1e2536;
  border-radius:12px;
  padding:.5rem 0;
  min-width:200px;
  box-shadow:0 10px 25px rgba(0,0,0,.5);
  z-index:100000;
}
body.user-dropdown-open #userDropdownMenu{ display:block }

.user-dropdown-backdrop{
  display:none;
  position:fixed; inset:0;
  background:rgba(4,8,16,.55);
  backdrop-filter:blur(3px);
  -webkit-backdrop-filter:blur(3px);
  z-index:99999;
}

@media (max-width:900px){
  /* Bottom-sheet on phones */
  #userDropdownMenu{
    top:auto !important;
    bottom:0 !important;
    left:0 !important;
    right:0 !important;
    width:100% !important;
    max-width:100% !important;
    min-width:0 !important;
    max-height:70vh;
    overflow-y:auto;
    border:1px solid rgba(255,255,255,.14) !important;
    border-bottom:none !important;
    border-radius:18px 18px 0 0 !important;
    box-shadow:0 -12px 40px rgba(0,0,0,.65) !important;
    padding:.5rem 0 calc(.85rem + env(safe-area-inset-bottom, 0px)) !important;
  }
  body.user-dropdown-open #userDropdownMenu{
    display:block;
    animation:userSheetUp .26s cubic-bezier(.2,.8,.2,1);
  }
  @keyframes userSheetUp{
    from{transform:translateY(100%)}
    to{transform:translateY(0)}
  }
  #userDropdownMenu::before{
    content:'';
    display:block;
    width:40px; height:4px;
    margin:.35rem auto .55rem;
    border-radius:99px;
    background:rgba(255,255,255,.22);
  }
  #userDropdownMenu .dropdown-item{
    padding:1.05rem 1.15rem;
    font-size:1rem;
  }
  #userDropdownMenu .dropdown-item + .dropdown-item{
    border-top:1.5px solid rgba(255,255,255,.22);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.05);
  }
  body.user-dropdown-open .user-dropdown-backdrop{ display:block }
}
@media (min-width:901px){
  .user-dropdown-backdrop{ display:none !important; }
}
</style>

<script>
function toggleSidebar() {
  const sidebar  = document.getElementById('sidebar');
  const overlay  = document.getElementById('sidebarOverlay');
  const btn      = document.getElementById('menuBtn');
  const isOpen   = sidebar.classList.contains('open');

  if (isOpen) {
    sidebar.classList.remove('open');
    overlay.classList.remove('visible');
    btn.classList.remove('open');
    document.body.style.overflow = '';
  } else {
    sidebar.classList.add('open');
    overlay.classList.add('visible');
    btn.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
}

function closeSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const btn     = document.getElementById('menuBtn');

  sidebar.classList.remove('open');
  overlay.classList.remove('visible');
  if (btn) btn.classList.remove('open');
  document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function(){
  const links = document.querySelectorAll('.nav-item');
  links.forEach(function(l){
    l.addEventListener('click', function(){
      if (window.innerWidth <= 768) closeSidebar();
    });
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const container = document.querySelector('.user-dropdown');
  if (!container) return;

  const toggle  = container.querySelector('.user-chip');
  const backdrop = document.getElementById('userDropdownBackdrop');
  function sync() {
    document.body.classList.toggle('user-dropdown-open', container.classList.contains('open'));
  }
  toggle.addEventListener('click', function(e) {
    e.stopPropagation();
    container.classList.toggle('open');
    sync();
  });
  if (backdrop) {
    backdrop.addEventListener('click', function() {
      container.classList.remove('open');
      sync();
    });
  }
  document.addEventListener('click', function(e) {
    if (!container.contains(e.target) && (!backdrop || !backdrop.contains(e.target))) {
      container.classList.remove('open');
      sync();
    }
  });
  // ESC closes the sheet
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && container.classList.contains('open')) {
      container.classList.remove('open');
      sync();
    }
  });
});
</script>

<?php if ($flash): ?>
  <div style="padding:.75rem 1.5rem 0">
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
      <?php if ($flash['type'] === 'success'): ?>
        <i class="fa-solid fa-circle-check"></i>
      <?php else: ?>
        <i class="fa-solid fa-circle-xmark"></i>
      <?php endif; ?>
      <?= $flash['msg'] ?>
    </div>
  </div>
<?php endif; ?>

<?php if (!isLoggedIn()): ?>
<script>
(function(){
  var s = document.createElement('script');
  s.src = '<?= APP_URL ?>/assets/js/cookie-consent.js';
  document.body.appendChild(s);
})();
</script>
<?php endif; ?>

<style>
#dm-upgrade-modal {
  transition: opacity .22s ease, backdrop-filter .22s ease;
}
#dm-upgrade-modal.open { display:flex!important; }
#dm-upgrade-modal.closing {
  opacity: 0 !important;
  pointer-events: none;
}

@keyframes dmUpgIn {
  from { opacity:0; transform:scale(.95) translateY(14px); }
  to   { opacity:1; transform:scale(1) translateY(0); }
}
@keyframes dmUpgOut {
  from { opacity:1; transform:scale(1) translateY(0); }
  to   { opacity:0; transform:scale(.95) translateY(10px); }
}
#dm-upgrade-modal.open .upg-card { animation:dmUpgIn .28s cubic-bezier(.22,1,.36,1) both; }
#dm-upgrade-modal.closing .upg-card { animation:dmUpgOut .2s cubic-bezier(.4,0,1,1) both; }

@media (max-width:480px) {
  #dm-upgrade-modal { align-items:flex-end!important; padding:0!important; }
  #dm-upgrade-modal .upg-card {
    border-radius:18px 18px 0 0!important;
    max-width:100%!important;
    max-height:88dvh;
    overflow-y:auto;
  }
  #dm-upgrade-modal .upg-body {
    padding-bottom:calc(clamp(1.2rem,4vw,1.6rem) + env(safe-area-inset-bottom,0px))!important;
  }
  #dm-upgrade-modal .upg-card::after {
    content:'';
    position:absolute;
    top:.55rem;
    left:50%;
    transform:translateX(-50%);
    width:36px;
    height:3px;
    background:rgba(255,255,255,.1);
    border-radius:2px;
  }
}
</style>

<div id="dm-upgrade-modal"
     onclick="if(event.target===this)dmCloseUpgrade()"
     style="display:none;position:fixed;inset:0;z-index:99999;
            background:rgba(2,4,10,.92);backdrop-filter:blur(14px);
            align-items:center;justify-content:center;
            padding:clamp(.75rem,4vw,1.5rem);">

  <div class="upg-card" style="
    background:#0d1018;
    border:1px solid rgba(240,165,0,.18);
    border-radius:clamp(14px,3vw,20px);
    width:100%;max-width:min(420px,100%);
    position:relative;
    box-shadow:0 0 0 1px rgba(240,165,0,.06),0 32px 64px rgba(0,0,0,.8);
    overflow:hidden;
  ">

    <div style="position:absolute;top:0;left:10%;right:10%;height:1px;
                background:linear-gradient(90deg,transparent,rgba(240,165,0,.5),transparent);
                pointer-events:none;"></div>

    <button onclick="dmCloseUpgrade()" aria-label="Κλείσιμο" style="
      position:absolute;
      top:clamp(.65rem,2.5vw,.9rem);right:clamp(.65rem,2.5vw,.9rem);
      width:32px;height:32px;border-radius:50%;
      border:1px solid rgba(255,255,255,.08);
      background:rgba(255,255,255,.04);color:#4a5068;
      cursor:pointer;display:flex;align-items:center;justify-content:center;
      font-size:.9rem;z-index:2;transition:all .2s;
    " onmouseover="this.style.background='rgba(240,165,0,.12)';this.style.color='#f0a500';this.style.borderColor='rgba(240,165,0,.2)'"
       onmouseout="this.style.background='rgba(255,255,255,.04)';this.style.color='#4a5068';this.style.borderColor='rgba(255,255,255,.08)'">
      <i class="fa-solid fa-xmark"></i>
    </button>

    <div class="upg-body" style="
      padding:clamp(1.4rem,5vw,1.9rem) clamp(1.1rem,5vw,1.6rem) clamp(1.2rem,4vw,1.6rem);
      display:flex;flex-direction:column;gap:clamp(.9rem,3vw,1.1rem);
    ">

      <div style="display:flex;align-items:center;gap:.75rem;">
        <div>
          <div style="font-size:.65rem;font-weight:700;letter-spacing:.14em;
                      text-transform:uppercase;color:#f0a500;margin-bottom:.18rem;">Χρειάζεστε Pro Πλάνο</div>
          <h2 style="font-family:'DM Sans',sans-serif;
                     font-size:clamp(1.1rem,4vw,1.3rem);
                     font-weight:800;color:#f0f2ff;line-height:1.15;margin:0;">
            Ξεκλειδώστε όλες τις δυνατότητες
          </h2>
        </div>
      </div>

      <div style="height:1px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.06),transparent);"></div>

      <div style="display:flex;flex-direction:column;gap:clamp(.38rem,1.5vw,.5rem);">
        <?php
        $feats = [
          ['fa-chart-bar',  'Οικονομικά'],
          ['fa-file-lines', 'Στατιστικά & Εξαγωγή'],
          ['fa-users',      'Απεριόριστοι αθλητές'],
          ['fa-paper-plane','SMS Αποστολές'],
          ['fa-headset',    'Προτεραιότητα Support'],
        ];
        foreach ($feats as $f): ?>
        <div style="
          display:flex;align-items:center;gap:.65rem;
          padding:clamp(.45rem,1.8vw,.6rem) clamp(.65rem,2.5vw,.85rem);
          background:rgba(255,255,255,.025);
          border:1px solid rgba(255,255,255,.05);
          border-radius:9px;
        ">
          <div style="
            width:clamp(26px,6vw,30px);height:clamp(26px,6vw,30px);
            border-radius:7px;flex-shrink:0;
            background:rgba(240,165,0,.08);border:1px solid rgba(240,165,0,.12);
            display:flex;align-items:center;justify-content:center;
            color:#c8880a;font-size:clamp(.7rem,2vw,.8rem);
          "><i class="fa-solid <?= $f[0] ?>"></i></div>
          <span style="font-size:clamp(.82rem,2.8vw,.9rem);font-weight:600;color:#c8cce0;line-height:1;">
            <?= $f[1] ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>

      <div style="height:1px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.06),transparent);"></div>

      <div style="
        display:flex;align-items:center;justify-content:space-between;
        padding:clamp(.65rem,2.5vw,.85rem) clamp(.75rem,3vw,1rem);
        background:rgba(240,165,0,.05);border:1px solid rgba(240,165,0,.14);
        border-radius:11px;flex-wrap:wrap;gap:.5rem;
      ">
        <div>
          <div style="font-size:.68rem;color:#4a5068;font-weight:500;margin-bottom:.12rem;">Μηνιαία συνδρομή από</div>
          <div style="font-size:clamp(1.6rem,5vw,1.85rem);font-weight:800;color:#f0a500;line-height:1;">
            €22<span style="font-size:clamp(.75rem,2.5vw,.82rem);font-weight:400;color:#4a5068;">/μήνα</span>
          </div>
        </div>
        <div style="font-size:clamp(.7rem,2.2vw,.75rem);font-weight:700;color:#6b7494;
                    display:flex;align-items:center;gap:.3rem;">
          <i class="fa-solid fa-circle-check" style="color:#3d5a38;"></i> Ακύρωση οποτεδήποτε
        </div>
      </div>

      <a href="<?= APP_URL ?>/pages/upgrade.php" style="
        display:flex;align-items:center;justify-content:center;gap:.55rem;
        background:linear-gradient(135deg,#f0a500,#d49000);
        color:#0a0800;font-family:'DM Sans',sans-serif;
        font-size:clamp(.95rem,3.5vw,1.05rem);font-weight:800;
        padding:clamp(.85rem,3.5vw,.95rem) 1.5rem;
        border-radius:11px;text-decoration:none;border:none;
        box-shadow:0 0 28px rgba(240,165,0,.28);
        transition:box-shadow .2s,transform .18s;letter-spacing:.01em;width:100%;
      " onmouseover="this.style.boxShadow='0 0 42px rgba(240,165,0,.45)';this.style.transform='translateY(-1px)'"
         onmouseout="this.style.boxShadow='0 0 28px rgba(240,165,0,.28)';this.style.transform='translateY(0)'">
        <i class="fa-solid fa-rocket"></i> Αναβάθμιση σε Pro τώρα →
      </a>

      <button onclick="dmCloseUpgrade()" style="
        background:none;border:none;color:#343750;
        font-size:clamp(.78rem,2.5vw,.83rem);cursor:pointer;
        padding:.2rem .5rem;border-radius:7px;transition:color .2s;
        font-family:'DM Sans',sans-serif;align-self:center;
      " onmouseover="this.style.color='#6b7494'" onmouseout="this.style.color='#343750'">
        Ίσως αργότερα
      </button>

    </div>
  </div>
</div>

<script>
function showUpgrade() {
  const m = document.getElementById('dm-upgrade-modal');
  m.classList.remove('closing');
  m.classList.add('open');
}
function dmCloseUpgrade() {
  const m = document.getElementById('dm-upgrade-modal');
  m.classList.add('closing');
  setTimeout(function() {
    m.classList.remove('open', 'closing');
  }, 220);
}
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') dmCloseUpgrade();
});
</script>

<?php
}

/**
 * PAYMENT WALL
 */
function renderPaymentWall(): void {
    if (isSuperAdmin()) return;

    $s = getSchoolStatus();
    if (!$s['expired']) return;

    $db   = getDB();
    $stmt = $db->prepare("SELECT plan_status, trial_ends, plan_expires FROM schools WHERE id=?");
    $stmt->execute([schoolId()]);
    $school = $stmt->fetch();

    if (!$school) {
        $title   = 'Πρόβλημα λογαριασμού';
        $icon    = 'fa-triangle-exclamation';
        $heading = 'Δεν βρέθηκε σχολή';
        $msg     = 'Ο λογαριασμός σας δεν είναι συνδεδεμένος με κάποια σχολή. Επικοινωνήστε με τον διαχειριστή.';
    } else {
        $wasPaidExpiry = !empty($school['plan_expires']) && strtotime($school['plan_expires']) < time();
        if ($wasPaidExpiry) {
            $title   = 'Η συνδρομή σας έληξε';
            $icon    = 'fa-rotate';
            $heading = 'Ανανεώστε τη συνδρομή σας';
            $msg     = 'Η συνδρομή σας έληξε στις <strong>' . date('d/m/Y', strtotime($school['plan_expires'])) . '</strong>. Επιλέξτε πλάνο και ολοκληρώστε την πληρωμή.';
        } else {
            $title   = 'Λήξη Δωρεάν Δοκιμής';
            $icon    = 'fa-lock';
            $heading = 'Η δωρεάν δοκιμή ολοκληρώθηκε';
            $msg     = 'Η δωρεάν δοκιμή 14 ημερών έληξε. Επιλέξτε πλάνο για να συνεχίσετε.';
        }
    }

    // ----- COMPACT & MINIMAL HTML OUTPUT (static prices) -----
    echo '<!DOCTYPE html><html lang="el"><head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>' . $title . ' — MAster</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{font-family:"DM Sans",sans-serif;background:#07090f;color:#f0f2ff;padding:1rem;display:flex;align-items:center;justify-content:center;min-height:100vh}
      .wall{max-width:900px;width:100%;margin:0 auto}
      .expiry-header{text-align:center;margin-bottom:1.2rem}
      .wall-icon{width:56px;height:56px;background:rgba(230,57,70,.12);border:2px solid rgba(230,57,70,.3);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:0.6rem;font-size:1.5rem;color:#e63946}
      .wall h1{font-size:1.4rem;font-weight:800;margin-bottom:0.25rem}
      .wall p{color:#8892b0;font-size:0.85rem;max-width:500px;margin:0 auto}
      .plan-cards{display:flex;flex-wrap:wrap;gap:0.8rem;justify-content:center;margin:1.2rem 0 1.2rem}
      .plan-c{background:#111520;border:1px solid #2a3248;border-radius:18px;padding:1rem 1rem;flex:1;min-width:230px}
      .plan-c.hot{border-color:rgba(240,165,0,.5);background:rgba(240,165,0,.03)}
      .plan-c .pn{font-size:1.2rem;font-weight:800;display:flex;align-items:center;gap:.4rem;margin-bottom:0.2rem}
      .plan-c .pn .badge{background:#f0a500;color:#0a0800;font-size:0.65rem;font-weight:800;padding:0.15rem 0.5rem;border-radius:30px}
      .plan-c .pp{font-size:1.6rem;font-weight:800;color:#e63946;margin:0.3rem 0 0.1rem}
      .plan-c.hot .pp{color:#f0a500}
      .plan-c .yearly-price{font-size:0.7rem;color:#8a93b0;border-top:1px dashed #2a3248;padding-top:0.4rem;margin-top:0.3rem}
      .plan-c ul{list-style:none;margin:0.6rem 0 0}
      .plan-c ul li{font-size:0.75rem;padding:0.2rem 0;display:flex;align-items:center;gap:.4rem;color:#c8cce0}
      .plan-c ul li i{width:16px;font-size:0.7rem;color:#4a9eff}
      .plan-c.hot ul li i{color:#f0a500}
      .payment-box{background:#0d1119;border-radius:18px;padding:1rem;border:1px solid #2a3248;margin-top:0.8rem}
      .payment-title{font-size:1rem;font-weight:700;margin-bottom:0.7rem;display:flex;align-items:center;gap:.4rem}
      .bank-details{background:#080c14;border-radius:14px;padding:0.7rem;margin-bottom:0.8rem}
      .bank-row{display:flex;flex-wrap:wrap;margin-bottom:0.4rem;font-size:0.75rem}
      .bank-label{font-weight:700;width:100px;color:#8892b0}
      .bank-value{color:#f0f2ff;word-break:break-all;flex:1;font-size:0.75rem}
      .iris-note{background:rgba(240,165,0,.1);border-left:2px solid #f0a500;padding:0.5rem 0.7rem;border-radius:10px;margin:0.6rem 0;font-size:0.75rem}
      .email-instruction{margin-top:0.6rem;background:rgba(74,158,255,.08);border-radius:12px;padding:0.6rem;text-align:center;border:1px solid rgba(74,158,255,.2);font-size:0.75rem}
      .email-instruction strong{color:#4a9eff;font-size:0.8rem}
      .logout-link{text-align:center;margin-top:1rem}
      .logout-link a{color:#6b7494;text-decoration:none;font-size:0.75rem;display:inline-flex;align-items:center;gap:.3rem}
      .logout-link a:hover{color:#e63946}
      .grace-note{font-size:0.7rem;padding:0.5rem;margin-bottom:1rem}
      @media(max-width:550px){.plan-cards{flex-direction:column;gap:0.7rem}.bank-row{flex-direction:column;gap:0.1rem}.bank-label{width:auto}}
    </style>
    </head><body>
    <div class="wall">
      <div class="expiry-header">
        <div class="wall-icon"><i class="fas ' . $icon . '"></i></div>
        <h1>' . $heading . '</h1>
        <p>' . $msg . '</p>
      </div>';

    if ($wasPaidExpiry ?? false) {
        echo '<div class="grace-note" style="background:rgba(240,165,0,.08);border:1px solid rgba(240,165,0,.2);border-radius:8px;padding:0.4rem 0.6rem;font-size:0.7rem;color:#f0a500;text-align:center;margin-bottom:0.8rem">
            <i class="fas fa-circle-info"></i> Τα δεδομένα σας είναι ασφαλή.
        </div>';
    }

    // Static pricing cards (compact)
    echo '<div class="plan-cards">
        <div class="plan-c">
            <div class="pn">Basic</div>
            <div class="pp">€15<span style="font-size:0.8rem;">/μήνα</span></div>
            <div class="yearly-price">€150/έτος (€12,50/μήνα)</div>
            <div style="font-size:.62rem;color:#8892b0;letter-spacing:.05em;margin-top:.15rem">συμπ. ΦΠΑ</div>
            <ul>
                <li><i class="fas fa-users"></i> Έως 50 αθλητές</li>
                <li><i class="fas fa-envelope"></i> Email υπενθυμίσεις</li>
                <li><i class="fas fa-people-roof"></i> Portal Γονέων</li>
            </ul>
        </div>
        <div class="plan-c hot">
            <div class="pn">Pro <span class="badge"><i class="fas fa-star"></i></span></div>
            <div class="pp">€25<span style="font-size:0.8rem;">/μήνα</span></div>
            <div class="yearly-price">€240/έτος (€20/μήνα)</div>
            <div style="font-size:.62rem;color:#8892b0;letter-spacing:.05em;margin-top:.15rem">συμπ. ΦΠΑ</div>
            <ul>
                <li><i class="fas fa-infinity"></i> Απεριόριστοι αθλητές</li>
                <li><i class="fas fa-sms"></i> SMS & Viber</li>
                <li><i class="fas fa-chart-pie"></i> Οικονομικά & αναφορές</li>
            </ul>
        </div>
    </div>';

    // Payment instructions (from admin settings)
    $_wBankName  = getBankName();
    $_wBankIban  = getBankIban();
    $_wBankBenef = getBankBeneficiary();
    $_wBankEmail = getBankReceiptEmail();
    $_wIrisPhone = getIrisPhone();
    $_wIrisAfm   = getIrisAfm();
    $_wSchoolId  = schoolId();
    $_wRefCode   = str_replace('{SCHOOL_ID}', (string)$_wSchoolId, getBankReference());

    $payRows = '';
    if ($_wBankBenef) $payRows .= '<div class="bank-row"><div class="bank-label">Δικαιούχος:</div><div class="bank-value">' . h($_wBankBenef) . '</div></div>';
    if ($_wBankName)  $payRows .= '<div class="bank-row"><div class="bank-label">Τράπεζα:</div><div class="bank-value">' . h($_wBankName) . '</div></div>';
    if ($_wBankIban)  $payRows .= '<div class="bank-row"><div class="bank-label">IBAN:</div><div class="bank-value">' . h($_wBankIban) . '</div></div>';
    $payRows .= '<div class="bank-row"><div class="bank-label">Αιτιολογία:</div><div class="bank-value">' . h($_wRefCode) . '</div></div>';

    $irisNote = '';
    if ($_wIrisPhone || $_wIrisAfm) {
        $irisParts = array_filter([$_wIrisPhone ? 'Τηλ: ' . h($_wIrisPhone) : '', $_wIrisAfm ? 'ΑΦΜ: ' . h($_wIrisAfm) : '']);
        $irisNote = '<div class="iris-note"><i class="fas fa-bolt"></i> <strong>IRIS:</strong> ' . implode(' · ', $irisParts) . '</div>';
    }

    echo '<div class="payment-box">
        <div class="payment-title"><i class="fas fa-landmark"></i> Πληρωμή</div>
        <div class="bank-details">' . $payRows . '</div>'
        . $irisNote .
        '<div class="email-instruction">
            <i class="fas fa-envelope"></i>
            <strong>Στείλτε αποδεικτικό στο:</strong> ' . h($_wBankEmail) . '<br>
            <span style="font-size:0.65rem;">Ενεργοποίηση εντός 24 ωρών</span>
        </div>
    </div>';

    // Logout only
    echo '<div class="logout-link">
        <a href="' . APP_URL . '/logout.php"><i class="fas fa-right-from-bracket"></i> Αποσύνδεση</a>
    </div>
    </div>
    </body></html>';
    exit;
}