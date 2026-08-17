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
 * admin/churn.php — Churn & MRR Αναφορές (Super Admin)
 * ============================================================
 * PURPOSE:
 *   Business intelligence: MRR γράφημα 12 μηνών, ανενεργές
 *   σχολές (churn risk), πρόσφατα ληγμένες.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin() — sensitive business data
 *   ✓ Read-only: δεν δέχεται POST
 *   ✓ Prepared statements
 *   ✓ h() για output
 *   ✓ Subquery για last_login: αποτρέπει false churn detection
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();
$db = getDB();

// MRR ανά μήνα (12 μήνες)
$mrr = [];
for ($i=11; $i>=0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $s = "$m-01"; $e = date('Y-m-t', strtotime($s));
    $rev = $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE paid_at BETWEEN '$s' AND '$e'")->fetchColumn();
    $newS = $db->query("SELECT COUNT(*) FROM schools WHERE DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $mrr[] = ['month'=>date('M y', strtotime($s)), 'revenue'=>(float)$rev, 'new_schools'=>(int)$newS];
}

// Ανενεργές σχολές (30+ ημέρες χωρίς σύνδεση)
$churnSchools = $db->query("
    SELECT s.id,s.name,s.email,s.city,s.plan_status,s.created_at,
           p.name as plan_name,
           (SELECT COUNT(*) FROM athletes a WHERE a.school_id=s.id AND a.active=1) as athletes,
           (SELECT MAX(created_at) FROM audit_log al WHERE al.school_id=s.id AND al.action='login') as last_login
    FROM schools s JOIN plans p ON p.id=s.plan_id
    WHERE s.plan_status IN ('active','trial')
    AND s.id NOT IN (
        SELECT DISTINCT school_id FROM audit_log
        WHERE action='login' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND school_id IS NOT NULL AND school_id > 0
    )
    ORDER BY last_login ASC
    LIMIT 50
")->fetchAll();

// Σχολές που έληξαν τον τελευταίο μήνα χωρίς ανανέωση
$recentExpired = $db->query("
    SELECT s.id,s.name,s.email,s.plan_expires,s.trial_ends,
           p.name as plan_name,
           (SELECT COUNT(*) FROM athletes a WHERE a.school_id=s.id AND a.active=1) as athletes
    FROM schools s JOIN plans p ON p.id=s.plan_id
    WHERE s.plan_status IN ('expired','trial')
    AND (s.plan_expires >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR s.trial_ends >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
    ORDER BY COALESCE(s.plan_expires, s.trial_ends) DESC
    LIMIT 30
")->fetchAll();

// Συνολικά MRR στατιστικά
$totalMrr     = $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE YEAR(paid_at)=YEAR(CURDATE()) AND MONTH(paid_at)=MONTH(CURDATE())")->fetchColumn();
$totalRevYear = $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE YEAR(paid_at)=YEAR(CURDATE())")->fetchColumn();
$churnCount   = count($churnSchools);

renderHead('Churn & MRR');
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
<?php renderSidebar('admin_churn'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-chart-line"></i> Churn & MRR Αναφορές'); ?>
<div class="page-body">

<!-- KPI Cards -->
<div class="grid grid-4 mb-3">
  <div class="stat-card">
    <div class="stat-icon icon-green"><i class="fa-solid fa-euro-sign"></i></div>
    <div class="stat-val"><?= number_format((float)$totalMrr, 2, ',', '.') ?>€</div>
    <div class="stat-lbl">Έσοδα Τρέχοντος Μήνα</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-blue"><i class="fa-solid fa-calendar-year"></i></div>
    <div class="stat-val"><?= number_format((float)$totalRevYear, 2, ',', '.') ?>€</div>
    <div class="stat-lbl">Έσοδα Τρέχοντος Έτους</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-red"><i class="fa-solid fa-user-slash"></i></div>
    <div class="stat-val"><?= $churnCount ?></div>
    <div class="stat-lbl">Ανενεργές 30+ ημέρες</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-orange"><i class="fa-solid fa-clock"></i></div>
    <div class="stat-val"><?= count($recentExpired) ?></div>
    <div class="stat-lbl">Έληξαν (τελ. 30 ημέρες)</div>
  </div>
</div>

<!-- MRR Chart -->
<div class="card mb-3">
  <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-bar" style="color:#3b82f6"></i> Μηνιαία Έσοδα (12 μήνες)</div></div>
  <div class="card-body">
    <canvas id="mrrChart" height="80"></canvas>
  </div>
</div>

<!-- Churn Schools -->
<div class="card mb-3">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-user-slash" style="color:#e63946"></i> Ανενεργές Σχολές (30+ ημέρες χωρίς σύνδεση)</div>
    <a href="<?= APP_URL ?>/admin/schools.php?churn=1" class="btn btn-ghost btn-sm">Πλήρης λίστα</a>
  </div>
  <div class="card-body p-0">
    <?php if (empty($churnSchools)): ?>
      <div class="text-center text-muted" style="padding:2rem">Δεν υπάρχουν ανενεργές σχολές 🎉</div>
    <?php else: ?>
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-churn" type="text" placeholder="Αναζήτηση ανενεργών..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-churn">
      <thead><tr><th>Σχολή</th><th>Πλάνο</th><th>Κατάσταση</th><th>Αθλητές</th><th>Τελευταία Σύνδεση</th><th>Εγγραφή</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($churnSchools as $s):
        $daysSince = $s['last_login'] ? floor((time()-strtotime($s['last_login']))/86400) : null;
      ?>
      <tr>
        <td><div class="fw-600"><?= h($s['name']) ?></div><div class="text-xs text-muted"><?= h($s['email']??'') ?><?= $s['city']?' · '.h($s['city']):'' ?></div></td>
        <td><?= h($s['plan_name']) ?></td>
        <td><span class="badge <?= $s['plan_status']==='active'?'badge-paid':'badge-pending' ?>"><?= $s['plan_status']==='active'?'Ενεργή':'Δοκιμή' ?></span></td>
        <td><?= $s['athletes'] ?></td>
        <td>
          <?php if ($s['last_login']): ?>
            <span style="color:#e63946" class="text-sm"><?= $daysSince ?>d πριν</span>
            <div class="text-xs text-muted"><?= formatDate(substr($s['last_login'],0,10)) ?></div>
          <?php else: ?><span class="text-muted text-sm">Ποτέ</span><?php endif; ?>
        </td>
        <td class="text-xs text-muted"><?= formatDate(substr($s['created_at'],0,10)) ?></td>
        <td>
          <a href="<?= APP_URL ?>/admin/schools.php?edit=<?= $s['id'] ?>" class="btn btn-ghost btn-sm" title="Επεξεργασία"><i class="fa-solid fa-pen-to-square"></i></a>
          <a href="<?= APP_URL ?>/admin/schools.php?impersonate=<?= $s['id'] ?>" class="btn btn-ghost btn-sm" title="Impersonate" onclick="return confirm('Login ως σχολή;')"><i class="fa-solid fa-user-secret"></i></a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div id="pg-churn" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
    <?php endif; ?>
  </div>
</div>

<!-- Recently Expired -->
<div class="card">
  <div class="card-header"><div class="card-title"><i class="fa-solid fa-clock" style="color:#f0a500"></i> Πρόσφατα Ληγμένες (30 ημέρες)</div></div>
  <div class="card-body p-0">
    <?php if (empty($recentExpired)): ?>
      <div class="text-center text-muted" style="padding:2rem">Καμία πρόσφατη λήξη</div>
    <?php else: ?>
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-expired" type="text" placeholder="Αναζήτηση ληγμένων..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-expired">
      <thead><tr><th>Σχολή</th><th>Πλάνο</th><th>Αθλητές</th><th>Λήξη</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recentExpired as $s): ?>
      <tr>
        <td><div class="fw-600"><?= h($s['name']) ?></div><div class="text-xs text-muted"><?= h($s['email']??'') ?></div></td>
        <td><?= h($s['plan_name']) ?></td>
        <td><?= $s['athletes'] ?></td>
        <td class="text-xs"><?= $s['plan_expires'] ? formatDate($s['plan_expires']) : formatDate($s['trial_ends']??'') ?></td>
        <td>
          <a href="<?= APP_URL ?>/admin/schools.php?edit=<?= $s['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-pen-to-square"></i></a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div id="pg-expired" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
    <?php endif; ?>
  </div>
</div>

</div></div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
var mrrData = <?= json_encode($mrr) ?>;
var ctx = document.getElementById('mrrChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: mrrData.map(function(r){ return r.month; }),
        datasets: [{
            label: 'Έσοδα (€)',
            data: mrrData.map(function(r){ return r.revenue; }),
            backgroundColor: 'rgba(59,130,246,.7)',
            borderColor: '#3b82f6',
            borderWidth: 1,
            borderRadius: 6,
            yAxisID: 'y'
        },{
            label: 'Νέες Σχολές',
            data: mrrData.map(function(r){ return r.new_schools; }),
            type: 'line',
            borderColor: '#2dc653',
            backgroundColor: 'rgba(45,198,83,.1)',
            borderWidth: 2,
            pointRadius: 4,
            tension: .3,
            yAxisID: 'y2'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y:  { beginAtZero:true, title:{ display:true, text:'Έσοδα (€)' }, grid:{ color:'rgba(255,255,255,.05)' } },
            y2: { beginAtZero:true, position:'right', title:{ display:true, text:'Νέες Σχολές' }, grid:{ drawOnChartArea:false } }
        }
    }
});
<script>
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
initPagination('tbl-churn', 'pg-churn', 10, 'srch-churn');
initPagination('tbl-expired', 'pg-expired', 10, 'srch-expired');
</script>
</body></html>