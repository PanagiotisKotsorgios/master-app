<?php


// ── Error Display & Logging ──────────────────────────────────────────────
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);
if (isset($_GET['debug'])) {
    ini_set('display_errors', 1);
} else {
    ini_set('display_errors', 0);
    set_exception_handler(function(\Throwable $e) {
        $file = basename($e->getFile());
        error_log('[' . $file . '] EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        echo '<div style="background:#0d1117;color:#e63946;padding:1.5rem 2rem;font-family:monospace;border:1px solid rgba(230,57,70,.3);border-radius:10px;margin:1.5rem;max-width:900px">';
        echo '<strong style="font-size:1.1rem">⚠ Σφάλμα Συστήματος</strong><br><hr style="border-color:rgba(230,57,70,.2);margin:.75rem 0">';
        echo '<span style="color:#f0a500">Τύπος:</span> ' . get_class($e) . '<br>';
        echo '<span style="color:#f0a500">Μήνυμα:</span> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '<br>';
        echo '<span style="color:#f0a500">Αρχείο:</span> ' . htmlspecialchars($file, ENT_QUOTES) . ' — Γραμμή ' . $e->getLine() . '<br>';
        echo '</div>';
        exit;
    });
    set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
        $log = basename($errfile);
        if ($errno & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            error_log("[{$log}] FATAL ERROR [{$errno}]: {$errstr} on line {$errline}");
        } elseif ($errno & (E_WARNING | E_NOTICE | E_DEPRECATED)) {
            error_log("[{$log}] WARNING [{$errno}]: {$errstr} on line {$errline}");
        }
        return false;
    });
}
// Ensure logs directory exists
@mkdir(__DIR__ . '/../logs', 0750, true);
// ──────────────────────────────────────────────────────────────────────────

/**
 * ============================================================
 * admin/stats.php — Στατιστικά Συστήματος (Super Admin)
 * ============================================================
 * PURPOSE:
 *   System-wide analytics: σχολές, αθλητές, συνδρομές,
 *   email/SMS usage, top schools, sport distribution.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ Read-only: δεν δέχεται POST
 *   ✓ getAllSchoolsUsageStats(): aggregated, δεν εκθέτει per-user data
 *   ✓ Prepared statements
 *   ✓ h() για output
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── Συγκεντρωτικά Στατιστικά ────────────────────────────────────────────────
$stats = [
    'total_schools'      => $db->query("SELECT COUNT(*) FROM schools")->fetchColumn(),
    'active_schools'     => $db->query("SELECT COUNT(*) FROM schools WHERE plan_status='active'")->fetchColumn(),
    'trial_schools'      => $db->query("SELECT COUNT(*) FROM schools WHERE plan_status='trial'")->fetchColumn(),
    'expired_schools'    => $db->query("SELECT COUNT(*) FROM schools WHERE plan_status='expired'")->fetchColumn(),
    'total_users'        => $db->query("SELECT COUNT(*) FROM users WHERE role != 'superadmin'")->fetchColumn(),
    'total_athletes'     => $db->query("SELECT COUNT(*) FROM athletes WHERE active=1")->fetchColumn(),
    'total_subscriptions'=> $db->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn(),           // Σύνολο συνδρομών αθλητών
    'paid_subs'          => $db->query("SELECT COUNT(*) FROM subscriptions WHERE status='paid'")->fetchColumn(),    // Πληρωμένες
    'overdue_subs'       => $db->query("SELECT COUNT(*) FROM subscriptions WHERE status='overdue'")->fetchColumn(), // Ληξιπρόθεσμες
    'total_reminders'    => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE status='sent'")->fetchColumn(),    // Επιτυχείς αποστολές
    'sms_sent'           => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='sms' AND status='sent'")->fetchColumn(),
    'email_sent'         => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='email' AND status='sent'")->fetchColumn(),
];

// ── Top 10 Σχολές με τους περισσότερους ενεργούς αθλητές ──────────────────
$topSchools = $db->query("
    SELECT s.name,
           (SELECT COUNT(*) FROM athletes a WHERE a.school_id=s.id AND a.active=1) as athletes,
           p.slug
    FROM schools s
    JOIN plans p ON p.id=s.plan_id
    WHERE s.active=1
    ORDER BY athletes DESC LIMIT 10
")->fetchAll();

// ── Κατανομή αθλητών ανά σχολή (top 7 για doughnut chart) ──────────────────
$sportDist = $db->query("SELECT s.name as sport, COUNT(a.id) as cnt FROM athletes a JOIN schools s ON s.id=a.school_id WHERE a.active=1 GROUP BY a.school_id ORDER BY cnt DESC LIMIT 7")->fetchAll();
// ── Μηνιαία δεδομένα για line chart ανάπτυξης (12 μήνες) ──────────────────
$monthly = [];
for ($i = 11; $i >= 0; $i--) {
    $m        = date('Y-m', strtotime("-$i months"));
    $s        = "$m-01";
    $e        = date('Y-m-t', strtotime($s));
    $schools  = $db->query("SELECT COUNT(*) FROM schools WHERE created_at BETWEEN '$s' AND '$e 23:59:59'")->fetchColumn();
    $athletes = $db->query("SELECT COUNT(*) FROM athletes WHERE created_at BETWEEN '$s' AND '$e 23:59:59'")->fetchColumn();
    $monthly[] = ['month' => date('M Y', strtotime($s)), 'schools' => (int)$schools, 'athletes' => (int)$athletes];
}

// ── Email/SMS Usage Stats (μόνο για admin) ────────────────────────────────────
$usageStats  = getAllSchoolsUsageStats();
$totalEmailToday = array_sum(array_column($usageStats, 'today_email'));
$totalSmsToday   = array_sum(array_column($usageStats, 'today_sms'));
$totalEmailMonth = array_sum(array_column($usageStats, 'month_email'));
$totalSmsMonth   = array_sum(array_column($usageStats, 'month_sms'));

renderHead('Στατιστικά');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Admin Global Overrides — bigger, cleaner, consistent ── */
body { font-size: 15px; }

/* Page body breathing room */
.page-body { padding: 1.75rem !important; }

/* Cards */
.card { border-radius: 14px !important; }
.card-title { font-size: 1rem !important; font-weight: 700 !important; }
.card-header { margin-bottom: 1.25rem; }

/* Tables — bigger text, more padding */
table { font-size: .9rem !important; }
thead th {
    font-size: .75rem !important;
    padding: .7rem 1rem !important;
    letter-spacing: .07em;
}
tbody td { padding: .8rem 1rem !important; font-size: .88rem !important; }
.fw-600 { font-size: .92rem !important; }
.text-xs { font-size: .78rem !important; }
.text-sm { font-size: .85rem !important; }

/* Stat cards */
.stat-card { border-radius: 14px !important; padding: 1.35rem !important; }
.stat-card .stat-val { font-size: 2.1rem !important; font-weight: 800 !important; }
.stat-card .stat-lbl { font-size: .82rem !important; }
.stat-card .stat-icon { width: 46px !important; height: 46px !important; font-size: 1.3rem !important; border-radius: 12px !important; }

/* Badges */
.badge { font-size: .72rem !important; padding: .22rem .6rem !important; border-radius: 50px !important; font-weight: 700 !important; }

/* Buttons */
.btn { font-size: .875rem !important; padding: .5rem 1.05rem !important; border-radius: 9px !important; font-weight: 500 !important; }
.btn-sm { font-size: .8rem !important; padding: .32rem .65rem !important; }
.btn-lg { font-size: 1rem !important; padding: .7rem 1.5rem !important; }
.btn-icon { padding: .42rem !important; }

/* Forms */
.form-label { font-size: .82rem !important; font-weight: 600 !important; color: var(--muted); }
.form-control { font-size: .88rem !important; padding: .58rem .8rem !important; border-radius: 9px !important; }
.form-hint { font-size: .75rem !important; }
.form-group { gap: .4rem !important; }

/* Nav items */
.nav-item { font-size: .88rem !important; padding: .55rem 1rem !important; }
.nav-label { font-size: .68rem !important; }

/* Search bar */
.search-bar input { font-size: .88rem !important; }

/* Page title */
.page-title { font-size: 1.1rem !important; font-weight: 700 !important; }

/* Topbar */
.topbar { padding: .85rem 1.5rem !important; }

/* Section labels inside cards */
.section-sep { font-size: .75rem !important; letter-spacing: .1em; }

/* Alerts */
.alert { font-size: .9rem !important; padding: .85rem 1.1rem !important; border-radius: 10px !important; }

/* Pagination */
.page-btn { font-size: .82rem !important; padding: .38rem .68rem !important; }

/* Progress bars */
.progress { height: 7px !important; }

/* Text utils */
.text-muted { color: var(--muted) !important; }
.text-green { color: var(--green) !important; }
.text-red, .text-danger { color: var(--red) !important; }
h2 { font-size: 1.2rem !important; font-weight: 700 !important; }

/* Mobile */
@media(max-width:768px){
    .page-body { padding: 1rem !important; }
    table { font-size: .82rem !important; }
    tbody td { padding: .65rem .75rem !important; }
    .stat-card .stat-val { font-size: 1.75rem !important; }
    .btn { font-size: .82rem !important; }
}
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_stats'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-chart-line"></i> Στατιστικά Συστήματος'); ?>
<div class="page-body">

<div class="grid grid-5 mb-3">
  <div class="stat-card">
    <div class="stat-icon icon-blue"><i class="fa-solid fa-school"></i></div>
    <div class="stat-val"><?= $stats['total_schools'] ?></div>
    <div class="stat-lbl">Σύνολο Σχολών</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
    <div class="stat-val"><?= $stats['active_schools'] ?></div>
    <div class="stat-lbl">Ενεργές</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-gold"><i class="fa-solid fa-hourglass-half"></i></div>
    <div class="stat-val"><?= $stats['trial_schools'] ?></div>
    <div class="stat-lbl">Δοκιμαστικές</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-red"><i class="fa-solid fa-circle-xmark"></i></div>
    <div class="stat-val"><?= $stats['expired_schools'] ?></div>
    <div class="stat-lbl">Ληγμένες</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-purple"><i class="fa-solid fa-users"></i></div>
    <div class="stat-val"><?= $stats['total_users'] ?></div>
    <div class="stat-lbl">Χρήστες</div>
  </div>
</div>

<div class="grid grid-4 mb-3">
  <div class="stat-card">
    <div class="stat-icon icon-blue"><i class="fa-solid fa-person-running"></i></div>
    <div class="stat-val"><?= $stats['total_athletes'] ?></div>
    <div class="stat-lbl">Σύνολο Αθλητών</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-green"><i class="fa-solid fa-credit-card"></i></div>
    <div class="stat-val"><?= $stats['paid_subs'] ?></div>
    <div class="stat-lbl">Πληρωμένες Συνδρομές</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="stat-val"><?= $stats['overdue_subs'] ?></div>
    <div class="stat-lbl">Ληξιπρόθεσμες</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-purple"><i class="fa-solid fa-paper-plane"></i></div>
    <div class="stat-val"><?= $stats['total_reminders'] ?></div>
    <div class="stat-lbl">Email+SMS Εστάλησαν</div>
    <div class="stat-sub">
      <i class="fa-solid fa-envelope"></i> <?= $stats['email_sent'] ?> &nbsp;
      <i class="fa-solid fa-mobile-screen-button"></i> <?= $stats['sms_sent'] ?>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-title mb-2"><i class="fa-solid fa-chart-area"></i> Ανάπτυξη (12 Μήνες)</div>
  <canvas id="growthChart" height="80"></canvas>
</div>

<div class="grid grid-3 mb-3">
  <div class="card">
    <div class="card-title mb-2"><i class="fa-solid fa-ranking-star"></i> Top Σχολές (Αθλητές)</div>
    <?php foreach ($topSchools as $i => $s): ?>
    <div class="d-flex jc-between ai-center mb-1 text-sm">
      <div><?= ($i + 1) ?>. <?= h($s['name']) ?> <?= planBadge($s['slug']) ?></div>
      <strong><?= $s['athletes'] ?></strong>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <div class="card-title mb-2"><i class="fa-solid fa-person-running"></i> Αθλητές ανά Σχολή</div>
    <canvas id="sportChart" height="160"></canvas>
  </div>

<!-- Usage Stats Card -->
<div class="card">
  <div class="card-title mb-2"><i class="fa-solid fa-paper-plane"></i> Κατανάλωση Email/SMS</div>
  <div class="d-flex jc-between ai-center mb-2 text-sm">
    <span style="color:var(--muted)">Email σήμερα</span>
    <strong style="color:var(--blue)"><?= $totalEmailToday ?></strong>
  </div>
  <div class="d-flex jc-between ai-center mb-2 text-sm">
    <span style="color:var(--muted)">SMS σήμερα</span>
    <strong style="color:var(--gold)"><?= $totalSmsToday ?></strong>
  </div>
  <div class="d-flex jc-between ai-center mb-2 text-sm">
    <span style="color:var(--muted)">Email τρέχοντος μήνα</span>
    <strong style="color:var(--blue)"><?= $totalEmailMonth ?></strong>
  </div>
  <div class="d-flex jc-between ai-center mb-3 text-sm">
    <span style="color:var(--muted)">SMS τρέχοντος μήνα</span>
    <strong style="color:var(--gold)"><?= $totalSmsMonth ?></strong>
  </div>
  <?php if (!empty($usageStats)): ?>
  <div style="font-size:.72rem;color:var(--muted2);margin-bottom:.5rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em">Κορυφαίες Σχολές (μήνας)</div>
  <?php foreach (array_slice($usageStats, 0, 5) as $us): ?>
  <div class="d-flex jc-between ai-center mb-1 text-sm">
    <span style="color:var(--muted);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($us['school_name'] ?: 'Σχολή #'.$us['school_id']) ?></span>
    <span><span style="color:var(--blue)">✉ <?= $us['month_email'] ?></span> &nbsp; <span style="color:var(--gold)">📱 <?= $us['month_sms'] ?></span></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

</div>

<?php if (!empty($usageStats)): ?>
<div class="card mb-3">
  <div class="card-title mb-2"><i class="fa-solid fa-envelope-open-text"></i> Ανάλυση Κατανάλωσης Email/SMS ανά Σχολή</div>
  <div class="table-wrap">
    <div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-usage" type="text" placeholder="Αναζήτηση σχολής..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-usage">
      <thead>
        <tr>
          <th>Σχολή</th>
          <th>Email Σήμερα</th>
          <th>SMS Σήμερα</th>
          <th>Email Μήνας</th>
          <th>SMS Μήνας</th>
          <th>Σύνολο Email</th>
          <th>Σύνολο SMS</th>
          <th>Τελευταία Δραστ.</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usageStats as $us): ?>
        <tr>
          <td class="td-name"><?= h($us['school_name'] ?: 'Σχολή #'.$us['school_id']) ?></td>
          <td><span style="color:var(--blue);font-weight:600"><?= $us['today_email'] ?></span></td>
          <td><span style="color:var(--gold);font-weight:600"><?= $us['today_sms'] ?></span></td>
          <td><?= $us['month_email'] ?></td>
          <td><?= $us['month_sms'] ?></td>
          <td><?= $us['total_email'] ?></td>
          <td><?= $us['total_sms'] ?></td>
          <td class="text-xs" style="color:var(--muted)"><?= $us['last_activity'] ? date('d/m/Y H:i', strtotime($us['last_activity'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div id="pg-usage" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
</div>
<?php endif; ?>
</div>

</div></div></div>
<script>
// ── Line Chart: Ανάπτυξη Σχολών & Αθλητών (12 μήνες) — δύο y-axes ─────────
new Chart(document.getElementById('growthChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($monthly, 'month')) ?>,
    datasets: [
      {
        label: 'Νέες Σχολές',
        data: <?= json_encode(array_column($monthly, 'schools')) ?>,
        borderColor: '#e63946',
        backgroundColor: 'rgba(230,57,70,.1)',
        fill: true, tension: .3, yAxisID: 'y'   // Αριστερός y-axis
      },
      {
        label: 'Νέοι Αθλητές',
        data: <?= json_encode(array_column($monthly, 'athletes')) ?>,
        borderColor: '#3a86ff',
        backgroundColor: 'rgba(58,134,255,.1)',
        fill: true, tension: .3, yAxisID: 'y1'  // Δεξιός y-axis (διαφορετική κλίμακα)
      }
    ]
  },
  options: {
    responsive: true,
    plugins: { legend: { labels: { color: '#7a849e' } } },
    scales: {
      x:  { ticks: { color: '#7a849e' } },
      y:  { ticks: { color: '#7a849e' }, position: 'left' },
      y1: { ticks: { color: '#7a849e' }, position: 'right', grid: { drawOnChartArea: false } } // Χωρίς grid να επικαλύπτει
    }
  }
});
// ── Doughnut Chart: Αθλητές ανά Σχολή ───────────────────────────────────────
new Chart(document.getElementById('sportChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($sportDist, 'sport')) ?>,
    datasets: [{
      data: <?= json_encode(array_column($sportDist, 'cnt')) ?>,
      backgroundColor: ['#e63946','#f4a535','#2dc653','#3a86ff','#a855f7','#fb923c','#06b6d4']
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { position: 'bottom', labels: { color: '#7a849e' } } }
  }
});
function initPagination(tableId, ctrlId, perPage, searchId) {
    perPage = perPage || 10;
    var table = document.getElementById(tableId);
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;
    var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    if (allRows.length === 0) return;
    var currentPage = 1;
    var currentPerPage = perPage;
    var filteredRows = allRows.slice();
    function filterRows(q) {
        q = (q || '').toLowerCase().trim();
        filteredRows = q ? allRows.filter(function(r){ return r.textContent.toLowerCase().indexOf(q) !== -1; }) : allRows.slice();
        currentPage = 1;
        render(1);
    }
    function totalPages() { return Math.max(1, Math.ceil(filteredRows.length / currentPerPage)); }
    function render(page) {
        currentPage = Math.max(1, Math.min(page, totalPages()));
        allRows.forEach(function(r){ r.style.display = 'none'; });
        filteredRows.forEach(function(r, i) {
            r.style.display = (i >= (currentPage-1)*currentPerPage && i < currentPage*currentPerPage) ? '' : 'none';
        });
        var ctrl = document.getElementById(ctrlId);
        if (!ctrl) return;
        var tp = totalPages();
        var btns = '<div style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap">';
        btns += '<select class="pg-size-select" style="font-size:.8rem;padding:.28rem .5rem;border-radius:7px;border:1px solid var(--border,#1e2536);background:var(--card,#111827);color:var(--text,#e2e8f0);cursor:pointer;margin-right:.4rem">';
        [10,25,50,100].forEach(function(n) { btns += '<option value="'+n+'"'+(n===currentPerPage?' selected':'')+'>'+n+' / σελίδα</option>'; });
        btns += '</select>';
        btns += '<a href="#" class="page-btn'+(currentPage===1?' disabled':'')+'" data-page="1" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a>';
        btns += '<a href="#" class="page-btn'+(currentPage===1?' disabled':'')+'" data-page="'+(currentPage-1)+'" title="Προηγούμενη"><i class="fa-solid fa-chevron-left"></i></a>';
        var start = Math.max(1, currentPage-2), end = Math.min(tp, currentPage+2);
        if (start > 2) { btns += '<a href="#" class="page-btn" data-page="1">1</a>'; if (start > 3) btns += '<span class="page-btn" style="pointer-events:none">…</span>'; }
        for (var p = start; p <= end; p++) btns += '<a href="#" class="page-btn'+(p===currentPage?' active':'')+'" data-page="'+p+'">'+p+'</a>';
        if (end < tp - 1) { if (end < tp - 2) btns += '<span class="page-btn" style="pointer-events:none">…</span>'; btns += '<a href="#" class="page-btn" data-page="'+tp+'">'+tp+'</a>'; }
        btns += '<a href="#" class="page-btn'+(currentPage===tp?' disabled':'')+'" data-page="'+(currentPage+1)+'" title="Επόμενη"><i class="fa-solid fa-chevron-right"></i></a>';
        btns += '<a href="#" class="page-btn'+(currentPage===tp?' disabled':'')+'" data-page="'+tp+'" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a>';
        btns += '<span style="font-size:.8rem;color:var(--muted);margin-left:.4rem">'+filteredRows.length+' εγγραφές · '+currentPage+' / '+tp+'</span>';
        btns += '</div>';
        ctrl.innerHTML = btns;
        ctrl.querySelectorAll('[data-page]').forEach(function(a) {
            a.addEventListener('click', function(e) { e.preventDefault(); if (this.classList.contains('disabled')) return; render(parseInt(this.dataset.page)); });
        });
        var sel = ctrl.querySelector('.pg-size-select');
        if (sel) sel.addEventListener('change', function() { currentPerPage = parseInt(this.value); render(1); });
    }
    if (searchId) { var inp = document.getElementById(searchId); if (inp) inp.addEventListener('input', function(){ filterRows(this.value); }); }
    render(1);
}
initPagination('tbl-usage', 'pg-usage', 10, 'srch-usage');

</script>
</body></html>