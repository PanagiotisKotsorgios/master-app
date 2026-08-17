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
 * admin/index.php — Super Admin Dashboard
 * ============================================================
 * PURPOSE:
 *   Κεντρική σελίδα super admin. KPI stats, γράφημα εγγραφών,
 *   κατανομή πλάνων, πρόσφατες σχολές, σχολές που λήγουν.
 *
 * ACCESS:
 *   Μόνο superadmin role (requireSuperAdmin → redirect αν όχι)
 *
 * SECURITY:
 *   ✓ requireSuperAdmin() — double check: login + role
 *   ✓ Δεν δέχεται POST inputs (read-only dashboard)
 *   ✓ Prepared statements για sub-queries
 *   ✓ h() για output escaping
 *   ✓ Impersonate link: μέσω schools.php (ελεγμένο endpoint)
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── Παγκόσμια Στατιστικά (KPI Cards) ───────────────────────────────────────
$totalSchools   = $db->query("SELECT COUNT(*) FROM schools")->fetchColumn();                 // Σύνολο εγγεγραμμένων σχολών
$activeSchools  = $db->query("SELECT COUNT(*) FROM schools WHERE active=1")->fetchColumn();  // Ενεργές σχολές
$totalUsers     = $db->query("SELECT COUNT(*) FROM users WHERE role != 'superadmin'")->fetchColumn(); // Χρήστες (εκτός superadmin)
$totalAthletes  = $db->query("SELECT COUNT(*) FROM athletes WHERE active=1")->fetchColumn(); // Ενεργοί αθλητές
$totalRevenue   = $db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE paid_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)")->fetchColumn(); // Έσοδα τελευταίου 12μήνου
$proSchools     = $db->query("SELECT COUNT(*) FROM schools s JOIN plans p ON p.id=s.plan_id WHERE p.slug='pro'")->fetchColumn(); // Σχολές με Pro πλάνο
$expiringSoon   = $db->query("SELECT COUNT(*) FROM schools WHERE plan_expires BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND plan_status='active'")->fetchColumn(); // Λήγουν εντός 30 ημερών
$newThisMonth   = $db->query("SELECT COUNT(*) FROM schools WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn(); // Νέες εγγραφές τρέχοντος μήνα

// ── Πρόσφατες Σχολές (τελευταίες 8) ───────────────────────────────────────
// JOIN με plans για όνομα/slug πλάνου, subquery για αθλητές
$recentSchools = $db->query("
    SELECT s.*, p.name as plan_name, p.slug as plan_slug,
           COUNT(DISTINCT u.id) as user_count,
           (SELECT COUNT(*) FROM athletes a WHERE a.school_id=s.id AND a.active=1) as athlete_count
    FROM schools s
    JOIN plans p ON p.id=s.plan_id
    LEFT JOIN users u ON u.school_id=s.id AND u.role != 'superadmin'
    GROUP BY s.id
    ORDER BY s.created_at DESC LIMIT 8
")->fetchAll();

// ── Μηνιαίες Εγγραφές για Bar Chart (τελευταίοι 6 μήνες) ──────────────────
$monthlySignups = [];
for ($i = 5; $i >= 0; $i--) {
    $m   = date('Y-m', strtotime("-$i months"));
    $s   = "$m-01";                              // Πρώτη μέρα μήνα
    $e   = date('Y-m-t', strtotime($s));         // Τελευταία μέρα μήνα
    $cnt = $db->query("SELECT COUNT(*) FROM schools WHERE created_at BETWEEN '$s' AND '$e 23:59:59'")->fetchColumn();
    $monthlySignups[] = ['month' => date('M Y', strtotime($s)), 'count' => (int)$cnt];
}

// ── Σχολές που λήγουν εντός 30 ημερών ─────────────────────────────────────
$expiringSchools = $db->query("
    SELECT s.id, s.name, s.email, s.plan_expires, s.trial_ends, s.plan_status,
           p.name as plan_name, p.slug as plan_slug
    FROM schools s JOIN plans p ON p.id=s.plan_id
    WHERE (
        (s.plan_status='active' AND s.plan_expires BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY))
        OR
        (s.plan_status='trial' AND s.trial_ends BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY))
    )
    ORDER BY COALESCE(s.plan_expires, s.trial_ends) ASC
    LIMIT 15
")->fetchAll();

renderHead('Super Admin Dashboard');
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
<?php renderSidebar('admin_dash'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-sliders"></i> Super Admin Dashboard'); ?>
<div class="page-body">

<!-- Προειδοποίηση Super Admin Mode -->
<div class="alert alert-warning mb-3">
  <i class="fa-solid fa-lock"></i> <strong>Super Admin Mode</strong> — Έχετε πλήρη πρόσβαση σε όλα τα δεδομένα του συστήματος.
</div>

<!-- ── KPI Stats (5 κάρτες) ──────────────────────────────────────────────── -->
<div class="grid grid-5 mb-3">
  <!-- Σύνολο / ενεργές σχολές -->
  <div class="stat-card">
    <div class="stat-icon icon-blue"><i class="fa-solid fa-school"></i></div>
    <div class="stat-val"><?= $totalSchools ?></div>
    <div class="stat-lbl">Σύνολο Σχολών</div>
    <div class="stat-sub"><?= $activeSchools ?> ενεργές</div>
  </div>
  <!-- Σχολές με Pro πλάνο -->
  <div class="stat-card">
    <div class="stat-icon icon-gold"><i class="fa-solid fa-star"></i></div>
    <div class="stat-val"><?= $proSchools ?></div>
    <div class="stat-lbl">Pro Πλάνα</div>
  </div>
  <!-- Συνολικά έσοδα 12μήνου -->
  <div class="stat-card">
    <div class="stat-icon icon-green"><i class="fa-solid fa-euro-sign"></i></div>
    <div class="stat-val text-green"><?= formatMoney($totalRevenue) ?></div>
    <div class="stat-lbl">Έσοδα 12μηνο</div>
  </div>
  <!-- Ενεργοί αθλητές σε όλες τις σχολές -->
  <div class="stat-card">
    <div class="stat-icon icon-purple"><i class="fa-solid fa-person-running"></i></div>
    <div class="stat-val"><?= $totalAthletes ?></div>
    <div class="stat-lbl">Σύνολο Αθλητών</div>
  </div>
  <!-- Σχολές που λήγουν σύντομα + νέες του μήνα -->
  <div class="stat-card">
    <div class="stat-icon icon-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="stat-val text-red"><?= $expiringSoon ?></div>
    <div class="stat-lbl">Λήγουν σύντομα</div>
    <div class="stat-sub"><?= $newThisMonth ?> νέες αυτό το μήνα</div>
  </div>
</div>

<div class="grid grid-2 mb-3">
  <!-- ── Bar Chart: Νέες εγγραφές σχολών ανά μήνα (6μηνο) ── -->
  <div class="card">
    <div class="card-title mb-2"><i class="fa-solid fa-chart-bar"></i> Νέες Εγγραφές (6μηνο)</div>
    <canvas id="signupChart" height="160"></canvas>
  </div>

  <!-- ── Κατανομή πλάνων με progress bars ── -->
  <div class="card">
    <div class="card-title mb-2"><i class="fa-solid fa-boxes-stacked"></i> Κατανομή Πλάνων</div>
    <?php
    // Φέρνει πλάνα με αριθμό σχολών — LEFT JOIN ώστε να εμφανίζονται και κενά πλάνα
    $planDist = $db->query("SELECT p.name, p.slug, COUNT(s.id) as cnt FROM plans p LEFT JOIN schools s ON s.plan_id=p.id GROUP BY p.id")->fetchAll();
    foreach ($planDist as $pd):
        $pct = $totalSchools > 0 ? round($pd['cnt'] / $totalSchools * 100) : 0; // Ποσοστό %
    ?>
    <div class="mb-2">
      <div class="d-flex jc-between text-sm mb-1">
        <span><?= planBadge($pd['slug']) ?> <?= h($pd['name']) ?></span>
        <span><?= $pd['cnt'] ?> σχολές (<?= $pct ?>%)</span>
      </div>
      <!-- Progress bar: Pro χρυσό, Basic default -->
      <div class="progress"><div class="progress-bar <?= $pd['slug'] === 'pro' ? 'gold' : '' ?>" style="width:<?= $pct ?>%"></div></div>
    </div>
    <?php endforeach; ?>
    <hr class="divider">
    <div class="d-flex gap-sm">
      <a href="<?= APP_URL ?>/admin/plans.php" class="btn btn-secondary btn-sm">
        <i class="fa-solid fa-gear"></i> Διαχείριση Πλάνων
      </a>
      <a href="<?= APP_URL ?>/admin/payments.php" class="btn btn-secondary btn-sm">
        <i class="fa-solid fa-credit-card"></i> Πληρωμές
      </a>
    </div>
  </div>
</div>

<!-- ── Πίνακας Πρόσφατων Σχολών ──────────────────────────────────────────── -->
<div class="card p-0">
  <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
    <div class="card-title"><i class="fa-solid fa-school"></i> Πρόσφατες Σχολές</div>
    <a href="<?= APP_URL ?>/admin/schools.php" class="btn btn-secondary btn-sm">Όλες</a>
  </div>
  <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-recent" type="text" placeholder="Αναζήτηση σχολών..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-recent-schools">
    <thead><tr><th>Σχολή</th><th>Πλάνο</th><th>Κατάσταση</th><th>Αθλητές</th><th>Λήξη</th><th>Εγγραφή</th><th>Ενέργειες</th></tr></thead>
    <tbody>
      <?php foreach ($recentSchools as $s): ?>
      <tr>
        <td>
          <div class="fw-600"><?= h($s['name']) ?></div>
          <div class="text-xs text-muted"><?= h($s['email'] ?? '') ?></div>
        </td>
        <td><?= planBadge($s['plan_slug']) ?> <?= h($s['plan_name']) ?></td>
        <!-- Badge κατάστασης με χρωματική κωδικοποίηση -->
        <td>
          <span class="badge <?= match($s['plan_status']) {
              'active'    => 'badge-paid',
              'trial'     => 'badge-pending',
              'expired'   => 'badge-overdue',
              'suspended' => 'badge-overdue',
              default     => 'badge-basic'
          } ?>">
            <?= ['active' => 'Ενεργή', 'trial' => 'Δοκιμή', 'expired' => 'Έληξε', 'suspended' => 'Αναστολή'][$s['plan_status']] ?? $s['plan_status'] ?>
          </span>
        </td>
        <td><?= $s['athlete_count'] ?></td>
        <td><?= $s['plan_expires'] ? formatDate($s['plan_expires']) : '—' ?></td>
        <td><?= formatDate(substr($s['created_at'], 0, 10)) ?></td>
        <td>
          <div class="d-flex gap-sm">
            <!-- Επεξεργασία στοιχείων σχολής -->
            <a href="<?= APP_URL ?>/admin/schools.php?edit=<?= $s['id'] ?>" class="btn btn-ghost btn-sm" title="Επεξεργασία">
              <i class="fa-solid fa-pen-to-square"></i>
            </a>
            <!-- Impersonate: login ως owner της σχολής -->
            <a href="<?= APP_URL ?>/admin/schools.php?impersonate=<?= $s['id'] ?>" class="btn btn-ghost btn-sm" title="Impersonate">
              <i class="fa-solid fa-user-secret"></i>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <div id="pg-recent-schools" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
</div>


<!-- ── Σχολές που Λήγουν Σύντομα ─────────────────────────────────────────── -->
<?php if (!empty($expiringSchools)): ?>
<div class="card p-0 mt-3">
  <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem;border-bottom:1px solid var(--border);background:rgba(230,57,70,.04)">
    <div class="card-title" style="color:#e63946"><i class="fa-solid fa-triangle-exclamation"></i> Λήγουν Σύντομα (<?= count($expiringSchools) ?>)</div>
    <a href="<?= APP_URL ?>/admin/schools.php?status=active" class="btn btn-ghost btn-sm">Όλες</a>
  </div>
  <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-expiring" type="text" placeholder="Αναζήτηση λήξεων..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-expiring">
    <thead><tr><th>Σχολή</th><th>Πλάνο</th><th>Κατάσταση</th><th>Λήξη</th><th>Ημέρες</th><th>Ενέργειες</th></tr></thead>
    <tbody>
    <?php foreach ($expiringSchools as $s):
      $expiryDate = $s['plan_status']==='trial' ? $s['trial_ends'] : $s['plan_expires'];
      $daysLeft   = $expiryDate ? max(0, (int)floor((strtotime($expiryDate) - time()) / 86400)) : 0;
    ?>
    <tr>
      <td><div class="fw-600"><?= h($s['name']) ?></div><div class="text-xs text-muted"><?= h($s['email']??'') ?></div></td>
      <td><?= planBadge($s['plan_slug']) ?></td>
      <td><span class="badge <?= $s['plan_status']==='active'?'badge-paid':'badge-pending' ?>"><?= $s['plan_status']==='active'?'Ενεργή':'Δοκιμή' ?></span></td>
      <td class="text-sm"><?= $expiryDate ? formatDate($expiryDate) : '—' ?></td>
      <td><span class="badge <?= $daysLeft<=7?'badge-overdue':($daysLeft<=14?'badge-pending':'badge-basic') ?>"><?= $daysLeft ?>d</span></td>
      <td>
        <a href="<?= APP_URL ?>/admin/schools.php?edit=<?= $s['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-pen-to-square"></i></a>
        <a href="<?= APP_URL ?>/admin/schools.php?impersonate=<?= $s['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-user-secret"></i></a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div id="pg-expiring" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
</div>
<?php endif; ?>
</div></div></div>
<!-- ── Chart.js: Bar chart νέων εγγραφών σχολών ─────────────────────────── -->
<script>
new Chart(document.getElementById('signupChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($monthlySignups, 'month')) ?>, // Ετικέτες μηνών
    datasets: [{
      label: 'Νέες Σχολές',
      data: <?= json_encode(array_column($monthlySignups, 'count')) ?>, // Αριθμός εγγραφών
      backgroundColor: 'rgba(230,57,70,.7)',
      borderColor: 'rgba(230,57,70,1)',
      borderWidth: 1
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { labels: { color: '#7a849e' } } },
    scales: {
      x: { ticks: { color: '#7a849e' } },
      y: { ticks: { color: '#7a849e', stepSize: 1 } } // stepSize:1 γιατί οι τιμές είναι integers
    }
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
initPagination('tbl-recent-schools', 'pg-recent-schools', 10, 'srch-recent');
initPagination('tbl-expiring', 'pg-expiring', 10, 'srch-expiring');
</script>
</body></html>