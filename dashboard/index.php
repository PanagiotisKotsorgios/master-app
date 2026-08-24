<?php

/**
 * ============================================================
 * dashboard/index.php — Κεντρική Σελίδα Σχολής
 * ============================================================
 * PURPOSE:
 *   Overview dashboard για τον owner/admin της σχολής.
 *   Εμφανίζει: KPI stats, χρήματα, ειδοποιήσεις, γρήγορες ενέργειες.
 *
 * SECURITY:
 *   ✓ requireLogin() — authentication gate
 *   ✓ renderPaymentWall() — έλεγχος active subscription
 *   ✓ schoolId() — όλα τα queries φιλτράρονται ανά school
 *   ✓ Prepared statements παντού
 *   ✓ h() για output escaping
 *   ✓ Δεν εκτίθενται δεδομένα άλλων σχολών (tenant isolation)
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/overage_popup.php';
require_once __DIR__ . '/../includes/summer_popup.php';
require_once __DIR__ . '/../includes/marketing_popup.php';
require_once __DIR__ . '/../includes/pro_website_banner.php';
requireLogin();
if (isSuperAdmin() && !isset($_GET['preview_popup'])) redirect(APP_URL.'/admin/');
renderPaymentWall();

$db  = getDB();
$sid = schoolId();
$privacyMode = (bool)($_SESSION['privacy_mode_' . $sid] ?? false);

// ── Stat: active athletes ──
$stAthletes = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id=? AND active=1");
$stAthletes->execute([$sid]);
$totalAthletes = (int)$stAthletes->fetchColumn();

// ── Fetch all active athletes with debt_from_month, registration_date, monthly_fee ──
$stAll = $db->prepare("SELECT id, registration_date, debt_from_month, monthly_fee FROM athletes WHERE school_id=? AND active=1");
$stAll->execute([$sid]);
$allAthletes = $stAll->fetchAll();

// ── Fetch all PAID subscriptions for this school (one query) ──
// Uses subscriptions table with valid_from / valid_until like athletes.php does
$stSubs = $db->prepare("SELECT athlete_id, valid_from, valid_until FROM subscriptions WHERE status='paid' AND athlete_id IN (SELECT id FROM athletes WHERE school_id=?)");
$stSubs->execute([$sid]);
$subsMap = []; // athlete_id => array of [valid_from, valid_until]
foreach ($stSubs->fetchAll() as $s) {
    $subsMap[$s['athlete_id']][] = ['from' => $s['valid_from'], 'until' => $s['valid_until']];
}

// ── Compute unpaid months — mirrors exactly the logic in athletes.php ──
function getDebtStartDateDash(array $athlete): ?string {
    $dfm = $athlete['debt_from_month'] ?? null;
    if ($dfm && preg_match('/^\d{4}-\d{2}$/', $dfm)) return $dfm . '-01';
    $reg = $athlete['registration_date'] ?? null;
    return ($reg && $reg !== '0000-00-00') ? $reg : null;
}

function getUnpaidMonthCountDash(?string $startDate, array $subs): int {
    if (!$startDate || $startDate === '0000-00-00') return 0;
    $start = (new DateTime($startDate))->modify('first day of this month');
    $now   = (new DateTime())->modify('first day of this month');
    $count = 0;
    $cur   = clone $start;
    while ($cur <= $now) {
        $mEnd    = (clone $cur)->modify('last day of this month');
        $covered = false;
        foreach ($subs as $s) {
            if (new DateTime($s['from']) <= $mEnd && new DateTime($s['until']) >= $cur) {
                $covered = true; break;
            }
        }
        if (!$covered) $count++;
        $cur->modify('+1 month');
    }
    return $count;
}

$athletesWithDebt  = 0;
$totalUnpaidMonths = 0;
$debtData          = [];

foreach ($allAthletes as $a) {
    $subs      = $subsMap[$a['id']] ?? [];
    $startDate = getDebtStartDateDash($a);
    $unpaid    = getUnpaidMonthCountDash($startDate, $subs);
    $fee       = floatval($a['monthly_fee'] ?? 0);
    $owed      = $unpaid * $fee;
    if ($unpaid > 0) {
        $athletesWithDebt++;
        $totalUnpaidMonths += $unpaid;
        $debtData[] = ['id' => $a['id'], 'unpaid' => $unpaid, 'fee' => $fee, 'owed' => $owed];
    }
}

// Sort by owed DESC, top 5
usort($debtData, fn($a,$b) => $b['owed'] <=> $a['owed']);
$top5Debt = array_slice($debtData, 0, 5);

// ── Fetch athlete names for top 5 ──
$top5Rows = [];
if ($top5Debt) {
    $ids   = implode(',', array_map(fn($r) => (int)$r['id'], $top5Debt));
    $stN   = $db->query("SELECT id, full_name FROM athletes WHERE id IN ($ids)");
    $names = [];
    foreach ($stN->fetchAll() as $row) $names[$row['id']] = $row['full_name'];
    foreach ($top5Debt as $d) {
        $top5Rows[] = array_merge($d, ['full_name' => $names[$d['id']] ?? '—']);
    }
}

// ── Pro: month income ──
$monthIncome = 0;
if (planHas('economics_enabled')) {
    $mi = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='income' AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())");
    $mi->execute([$sid]);
    $monthIncome = $mi->fetchColumn();
}

// ── Recent athletes ──
$recentAthletes = $db->prepare("SELECT a.*, d.name as dept_name FROM athletes a LEFT JOIN departments d ON d.id=a.department_id WHERE a.school_id=? AND a.active=1 ORDER BY a.created_at DESC LIMIT 5");
$recentAthletes->execute([$sid]);
$recent = $recentAthletes->fetchAll();

// ── Dept stats ──
$deptStats = $db->prepare("SELECT d.name, COUNT(a.id) as cnt FROM departments d LEFT JOIN athletes a ON a.department_id=d.id AND a.active=1 WHERE d.school_id=? GROUP BY d.id ORDER BY cnt DESC");
$deptStats->execute([$sid]);
$depts = $deptStats->fetchAll();

renderHead('Κεντρική');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
.topbar { position:relative!important; top:auto!important; z-index:auto!important; }
.main-content > div[style*="border-bottom"] { position:relative!important; top:auto!important; }

@media (max-width:900px) {
    #menuBtn { display:inline-flex!important; min-width:44px!important; min-height:44px!important; align-items:center!important; justify-content:center!important; font-size:1.2rem!important; cursor:pointer!important; }
    .sidebar { position:fixed!important; top:0!important; left:0!important; bottom:0!important; width:min(280px,80vw)!important; z-index:9999!important; transform:translateX(-110%)!important; transition:transform .28s cubic-bezier(.2,.8,.2,1)!important; overflow-y:auto; -webkit-overflow-scrolling:touch; }
    .sidebar.open { transform:translateX(0)!important; box-shadow:6px 0 40px rgba(0,0,0,.6)!important; }
    .main-content { margin-left:0!important; width:100%!important; }
}
#dm-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9998; cursor:pointer; }
#dm-overlay.on { display:block; }

@keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.page-body   { animation:fadeIn .35s ease both; }
.dash-stats  { opacity:0; animation:fadeUp .45s ease-out .05s both; }
.dash-mid    { opacity:0; animation:fadeUp .45s ease-out .15s both; }
.dash-recent { opacity:0; animation:fadeUp .45s ease-out .25s both; }
@media (prefers-reduced-motion:reduce) { .page-body,.dash-stats,.dash-mid,.dash-recent{animation:none!important;opacity:1;} }

.stat-card { border-radius:16px; padding:1.1rem 1rem; transition:transform .2s,box-shadow .2s; cursor:default; }
.stat-card:hover { transform:translateY(-3px); box-shadow:0 12px 28px rgba(0,0,0,.3); }
.stat-card.disabled { opacity:.5; cursor:pointer; }
.stat-card.disabled:hover { transform:none; box-shadow:none; }
.stat-val { font-size:clamp(1.65rem,4.5vw,2.2rem)!important; font-weight:800!important; line-height:1.1; letter-spacing:-.01em; }
.stat-lbl { font-size:clamp(.9rem,3vw,1rem)!important; font-weight:600; margin-top:.2rem; }
.stat-sub { font-size:clamp(.78rem,2.5vw,.85rem)!important; margin-top:.1rem; }

.card { border-radius:18px; }
.card-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem; padding:.9rem 1.1rem; border-bottom:1px solid var(--border,#1e2536); }
.card-title { font-size:clamp(1rem,3.5vw,1.1rem)!important; font-weight:800; display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; }
.limit-note { font-size:.75rem; color:var(--muted,#8892b0); font-weight:400; margin-left:.5rem; white-space:nowrap; }

.table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.table-wrap table { width:100%; border-collapse:collapse; }
.table-wrap th { font-size:clamp(.78rem,2.5vw,.85rem)!important; font-weight:800; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; padding:.6rem .9rem; color:var(--muted,#8892b0); }
.table-wrap td { font-size:clamp(.92rem,3vw,1rem)!important; padding:.7rem .9rem; vertical-align:middle; }
.table-wrap tbody tr { transition:background .15s; }
.table-wrap tbody tr:hover { background:rgba(255,255,255,.03); }

/* clickable rows */
.clickable-row { cursor:pointer; }
.clickable-row:hover { background:rgba(255,255,255,.05)!important; }
.clickable-row td { position:relative; }

.btn { min-height:38px; font-size:clamp(.88rem,3vw,.95rem)!important; font-weight:700!important; display:inline-flex; align-items:center; gap:.4rem; border-radius:10px; transition:all .18s; text-decoration:none; padding:.45rem .9rem; cursor:pointer; border:none; white-space:nowrap; }
.btn:active { transform:scale(.97); }
.btn-sm { min-height:34px; padding:.4rem .8rem; }

.debt-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.22rem .6rem; border-radius:20px; font-size:.78rem; font-weight:700; white-space:nowrap; }
.debt-badge.low  { background:rgba(240,165,0,.12);  color:#f0a500; border:1px solid rgba(240,165,0,.3); }
.debt-badge.high { background:rgba(230,57,70,.12);  color:#e63946; border:1px solid rgba(230,57,70,.3); }

.progress { height:8px; border-radius:4px; overflow:hidden; }
.progress-bar { height:100%; border-radius:4px; transition:width .6s ease; }
.dept-row-name  { font-size:clamp(.9rem,3vw,1rem)!important; font-weight:600; }
.dept-row-count { font-size:clamp(.85rem,2.8vw,.92rem)!important; }

.empty-state { text-align:center; padding:1.75rem 1rem; }
.empty-state .empty-icon { font-size:clamp(2rem,7vw,2.75rem); margin-bottom:.6rem; }
.empty-state p { font-size:clamp(.92rem,3vw,1rem)!important; }

.nav-item { min-height:46px!important; font-size:clamp(.92rem,3vw,1rem)!important; font-weight:600!important; padding:.65rem .9rem!important; border-radius:10px!important; display:flex!important; align-items:center!important; gap:.7rem!important; transition:background .15s,color .15s!important; text-decoration:none; }
.nav-item .icon { width:22px; text-align:center; font-size:1rem; flex-shrink:0; }
.sidebar-school { margin:.25rem 1rem!important; padding:0!important; display:flex!important; align-items:center!important; justify-content:flex-start!important; font-weight:700!important; font-size:clamp(.82rem,3vw,.92rem)!important; color:var(--text,#f0f2ff)!important; background:none!important; border:none!important; box-shadow:none!important; border-radius:0!important; }
.sidebar-school:hover,.sidebar-school:focus,.sidebar-school:active { background:none!important; border:none!important; box-shadow:none!important; transform:none!important; outline:none!important; }

@media (max-width:900px) { .grid-4{grid-template-columns:repeat(2,1fr)!important;gap:.85rem!important;} .grid-2{grid-template-columns:1fr!important;gap:.85rem!important;} .page-body{padding:1rem!important;} }
@media (max-width:600px) {
  .grid-4{grid-template-columns:1fr 1fr!important;gap:.6rem!important;}
  .dash-stats .stat-card{width:100%;}
  .stat-card{padding:.85rem .9rem;border-radius:14px;}
  .card{border-radius:14px;}
  .card-header{padding:.75rem .9rem;gap:.4rem;}
  .card-title{font-size:.92rem!important;gap:.3rem;}
  .limit-note{display:none;}
  .table-wrap th{padding:.45rem .6rem;font-size:.72rem!important;}
  .table-wrap td{padding:.55rem .6rem;font-size:.88rem!important;}
  .page-body{padding:.65rem!important;}
  .mb-3{margin-bottom:.65rem!important;}
  /* hide less important cols on small screens */
  .col-hide-sm{display:none!important;}
  /* always show pay button text */
  .pay-btn-text{display:inline!important;}
  .btn-sm{min-height:30px!important;padding:.3rem .5rem!important;}
}
@media (max-width:420px) {
  .grid-4{grid-template-columns:1fr 1fr!important;gap:.55rem!important;}
  .stat-val{font-size:clamp(1.3rem,7vw,1.65rem)!important;}
  .stat-card{padding:.7rem .75rem;}
  .card-header{padding:.65rem .75rem;}
  .page-body{padding:.5rem!important;}
  .table-wrap th{padding:.4rem .5rem;font-size:.7rem!important;}
  .table-wrap td{padding:.5rem .5rem;font-size:.84rem!important;}
  .pay-btn-text{display:inline!important;}
}
@media (max-width:320px) {
  .stat-card{padding:.6rem .65rem;border-radius:12px;}
  .stat-val{font-size:1.2rem!important;}
  .card{border-radius:12px;}
  .page-body{padding:.4rem!important;}
}

/* Always hide amount col on mobile */
@media (max-width:600px) { .col-amt{display:none!important;} }
/* On wider mobile still hide it if needed */
@media (max-width:500px) { .col-amt{display:none!important;} }

/* Prevent any horizontal overflow cutting content */
.main-content{overflow-x:hidden!important;min-width:0!important;}
.page-body{overflow-x:hidden!important;min-width:0!important;box-sizing:border-box;}
.grid-2,.grid-4{min-width:0;}
.card{min-width:0;overflow:hidden;}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%;}
.table-wrap table{min-width:0;}
/* Prevent badge/button from forcing row wide */
.debt-badge{white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis;}

/* ── Table borders and separators ── */
.table-wrap table,
.table-wrap th,
.table-wrap td {
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-collapse: collapse;
}
.table-wrap th {
    background: rgba(0, 0, 0, 0.2);
    font-weight: 800;
}
.table-wrap td {
    background: transparent;
}

</style>

<body>
<?php renderOveragePopup(); ?>
<?php renderSummerPopup(); ?>
<?php renderMarketingPopup(); ?>
<?php renderProWebsiteBanner(); ?>
<div class="app-layout">
<?php renderSidebar('dashboard'); ?>
<div id="dm-overlay"></div>
<div class="main-content">
<?php renderTopbar('Κεντρική'); ?>
<div class="page-body">

  <!-- ── Stats ── -->
  <div class="mb-3 dash-stats" style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;width:100%">
    <div class="stat-card" style="width:100%;box-sizing:border-box">
      <div class="stat-val"><?= $totalAthletes ?></div>
      <div class="stat-lbl">Ενεργοί Αθλητές</div>
    </div>
    <?php if (!$privacyMode): ?>
    <div class="stat-card" style="width:100%;box-sizing:border-box">
      <div class="stat-val text-red"><?= $athletesWithDebt ?></div>
      <div class="stat-lbl">Με Οφειλή</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Middle row ── -->
  <div class="grid grid-2 mb-3 dash-mid">

    <!-- Top 5 με οφειλή -->
    <?php if (!$privacyMode): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <i class="fas fa-triangle-exclamation" style="color:var(--red)"></i>
          Αθλητές με Οφειλή
        </div>
        <a href="<?= APP_URL ?>/pages/athletes.php?debt=owed" class="btn btn-secondary btn-sm">Όλοι</a>
      </div>
      <?php if ($top5Rows): ?>
      <div class="table-wrap">
         <table>
          <thead>
             <tr>
              <th>Αθλητής</th>
              <th>Μήνες</th>
              <th class="col-amt">Οφειλή</th>
              <th>Πληρωμή</th>  <!-- added header -->
             </tr>
          </thead>
          <tbody>
            <?php foreach ($top5Rows as $r):
              $badgeCls = $r['unpaid'] >= 3 ? 'high' : 'low';
              $rowUrl   = APP_URL.'/pages/athletes.php?view='.$r['id'];
            ?>
            <tr class="clickable-row" onclick="window.location='<?= $rowUrl ?>'">
              <td style="font-weight:700"><?= h($r['full_name']) ?></td>
              <td>
                <span class="debt-badge <?= $badgeCls ?>">
                  <i class="fa-solid fa-<?= $r['unpaid'] >= 3 ? 'triangle-exclamation' : 'clock' ?>"></i>
                  <?= $r['unpaid'] ?> <?= $r['unpaid'] === 1 ? 'μήνας' : 'μήνες' ?>
                </span>
              </td>
              <td class="col-amt" style="font-weight:700;color:<?= $r['unpaid'] >= 3 ? '#e63946' : '#f0a500' ?>">
                <?= $r['owed'] > 0 ? number_format($r['owed'], 0, ',', '.') . '€' : '—' ?>
              </td>
              <td onclick="event.stopPropagation()" style="white-space:nowrap">
                <a href="<?= APP_URL ?>/pages/subscriptions.php?action=add&athlete_id=<?= $r['id'] ?>"
                   class="btn btn-sm"
                   style="background:rgba(45,198,83,.1);color:#2dc653;border:1px solid rgba(45,198,83,.25);min-height:30px;padding:.3rem .65rem;font-size:.78rem!important;">
                  <i class="fa-solid fa-plus"></i><span class="pay-btn-text"> Πληρωμή</span>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-circle-check" style="color:#2dc653"></i></div>
        <p>Όλοι οι αθλητές είναι ενήμεροι!</p>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?><!-- /privacy: debt card -->

    <!-- Τμήματα -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <i class="fas fa-folder" style="color:var(--gold)"></i>
          Τμήματα
          <span class="limit-note">(σύνολο ενεργών)</span>
        </div>
        <a href="<?= APP_URL ?>/pages/departments.php" class="btn btn-secondary btn-sm">Διαχείριση</a>
      </div>
      <?php if ($depts): ?>
      <div style="padding:.85rem 1rem">
        <?php foreach ($depts as $d):
          $pct = $totalAthletes > 0 ? round($d['cnt'] / $totalAthletes * 100) : 0; ?>
        <div style="margin-bottom:.9rem">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem;gap:.5rem">
            <span class="dept-row-name"><?= h($d['name']) ?></span>
            <span class="dept-row-count" style="color:var(--muted)"><?= $d['cnt'] ?> αθλητές</span>
          </div>
          <div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-folder-open"></i></div>
        <p>Δεν υπάρχουν τμήματα</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Recent Athletes ── -->
  <div class="card dash-recent">
    <div class="card-header">
      <div class="card-title">
        Πρόσφατοι Αθλητές
        <span class="limit-note">(τελευταίοι 5)</span>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="<?= APP_URL ?>/pages/athletes.php?action=add" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Νέος</a>
        <a href="<?= APP_URL ?>/pages/athletes.php" class="btn btn-secondary btn-sm">Όλοι</a>
      </div>
    </div>
    <?php if ($recent): ?>
    <div class="table-wrap">
      <table>
        <thead>
           <tr>
            <th>Ονοματεπώνυμο</th>
            <th class="col-hide-sm">Τμήμα</th>
            <th>Εγγραφή</th>
           </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $a):
            $rowUrl = APP_URL.'/pages/athletes.php?view='.$a['id'];
          ?>
          <tr class="clickable-row" onclick="window.location='<?= $rowUrl ?>'">
            <td style="font-weight:700"><?= h($a['full_name']) ?></td>
            <td class="col-hide-sm"><?= h($a['dept_name'] ?? '—') ?></td>
            <td style="color:var(--muted,#8892b0);font-size:.88em"><?= $a['created_at'] ? date('d/m/Y', strtotime($a['created_at'])) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="fas fa-hand-fist"></i></div>
      <p>Δεν υπάρχουν αθλητές</p>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>
</div>

<script>
(function removeFakeTopbar() {
    document.querySelectorAll('.topbar').forEach(function(el) {
        var txt = (el.textContent || '').trim();
        if (txt === '...' || txt === '') { el.style.cssText = 'display:none!important'; el.remove(); }
    });
    document.querySelectorAll('.topbar').forEach(function(el) {
        var pos = window.getComputedStyle(el).position;
        if (pos === 'fixed' || pos === 'sticky') { el.style.setProperty('position','relative','important'); el.style.setProperty('top','auto','important'); }
    });
})();

(function sidebarToggle() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('dm-overlay');
    var menuBtn = document.getElementById('menuBtn');
    if (!sidebar || !menuBtn) return;
    function open()  { sidebar.classList.add('open');    overlay&&overlay.classList.add('on');    document.body.style.overflow='hidden'; }
    function close() { sidebar.classList.remove('open'); overlay&&overlay.classList.remove('on'); document.body.style.overflow=''; }
    menuBtn.onclick = function(e){ e.stopPropagation(); sidebar.classList.contains('open')?close():open(); };
    overlay&&overlay.addEventListener('click',close);
    sidebar.querySelectorAll('a.nav-item').forEach(function(l){ l.addEventListener('click',function(){ if(window.innerWidth<=900) setTimeout(close,80); }); });
    document.addEventListener('keydown',function(e){ if(e.key==='Escape') close(); });
    window.addEventListener('resize',function(){ if(window.innerWidth>900){ sidebar.classList.remove('open'); overlay&&overlay.classList.remove('on'); document.body.style.overflow=''; } });
})();
</script>
</body>
</html>