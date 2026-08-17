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
 * admin/audit.php — Audit Log Viewer (Super Admin)
 * ============================================================
 * PURPOSE:
 *   Εμφανίζει ιστορικό όλων των καταγεγραμμένων ενεργειών.
 *   Φίλτρα: action, school, user, text search, pagination.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin() — μόνο superadmin βλέπει τα logs
 *   ✓ Read-only: δεν υπάρχει POST (δεν επιτρέπεται τροποποίηση)
 *   ✓ Prepared statements με dynamic WHERE
 *   ✓ (int) cast για school_id, user_id από GET
 *   ✓ h() για output
 *   ✓ Pagination: LIMIT/OFFSET (αποτρέπει memory exhaustion)
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin(); // Ελέγχει ότι ο τρέχων χρήστης είναι superadmin, αλλιώς redirect

$db = getDB();

// ── Φίλτρα από GET παραμέτρους ─────────────────────────────────────────────
$search       = trim($_GET['q'] ?? '');              // Ελεύθερη αναζήτηση κειμένου
$filterAction = trim($_GET['action'] ?? '');          // Φίλτρο συγκεκριμένης ενέργειας
$filterSchool = (int)($_GET['school_id'] ?? 0);       // Φίλτρο ανά σχολή
$filterUser   = (int)($_GET['user_id'] ?? 0);         // Φίλτρο ανά χρήστη
$page         = max(1, (int)($_GET['page'] ?? 1));    // Τρέχουσα σελίδα (min=1)
$limit = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;                                   // Εγγραφές ανά σελίδα

// ── Δυναμικό WHERE clause με prepared statement parameters ─────────────────
$where  = ['1=1']; // Βάση που πάντα αληθεύει, ώστε να προσθέτουμε AND εύκολα
$params = [];

if ($search) {
    // Αναζήτηση σε ενέργεια, τύπο οντότητας, λεπτομέρειες και όνομα χρήστη
    $where[]  = "(al.action LIKE ? OR al.entity_type LIKE ? OR al.details LIKE ? OR u.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterAction) {
    // Φίλτρο ακριβούς τιμής ενέργειας (π.χ. 'login', 'backup_created')
    $where[]  = "al.action = ?";
    $params[] = $filterAction;
}
if ($filterSchool) {
    $where[]  = "al.school_id = ?";
    $params[] = $filterSchool;
}
if ($filterUser) {
    $where[]  = "al.user_id = ?";
    $params[] = $filterUser;
}

$whereStr = implode(' AND ', $where); // Ενώνει όλες τις συνθήκες με AND

// ── Μέτρηση συνολικών εγγραφών για pagination ──────────────────────────────
$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON u.id=al.user_id WHERE $whereStr");
$countStmt->execute($params);
$total      = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / max(1, $limit)));    // Σύνολο σελίδων
$offset     = ($page - 1) * $limit;    // Offset για LIMIT/OFFSET pagination

// ── Κύριο query: φέρνει τα audit logs με ονόματα χρήστη & σχολής ───────────
$stmt = $db->prepare("
    SELECT al.*, u.name as user_name, s.name as school_name
    FROM audit_log al
    LEFT JOIN users u ON u.id=al.user_id       -- Όνομα χρήστη (null αν system action)
    LEFT JOIN schools s ON s.id=al.school_id   -- Όνομα σχολής (null αν global action)
    WHERE $whereStr
    ORDER BY al.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// ── Δεδομένα για τα dropdowns των φίλτρων ──────────────────────────────────
$schools     = $db->query("SELECT id,name FROM schools ORDER BY name")->fetchAll();
$users       = $db->query("SELECT id,name FROM users ORDER BY name")->fetchAll();
$actions     = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN); // Μοναδικές ενέργειες για dropdown

renderHead('Audit Log');
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
<?php renderSidebar('admin_audit'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-clipboard-list"></i> Audit Log'); ?>
<div class="page-body">

<!-- ── Φόρμα Φίλτρων ─────────────────────────────────────────────────────── -->
<div class="card mb-3">
  <form method="GET" class="d-flex gap-sm flex-wrap ai-end">
    <!-- Ελεύθερη αναζήτηση κειμένου -->
    <div class="form-group" style="margin-bottom:0;min-width:210px">
      <label class="form-label">Αναζήτηση</label>
      <div class="search-bar">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input name="q" value="<?= h($search) ?>" placeholder="Ενέργεια, οντότητα, λεπτομέρειες...">
      </div>
    </div>
    <!-- Φίλτρο τύπου ενέργειας — δυναμικό dropdown από τη βάση -->
    <div class="form-group" style="margin-bottom:0;min-width:160px">
      <label class="form-label">Ενέργεια</label>
      <select name="action" class="form-control">
        <option value="">Όλες</option>
        <?php foreach ($actions as $act): ?>
          <option value="<?= h($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>><?= h($act) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <!-- Φίλτρο ανά σχολή -->
    <div class="form-group" style="margin-bottom:0;min-width:160px">
      <label class="form-label">Σχολή</label>
      <select name="school_id" class="form-control">
        <option value="">Όλες</option>
        <?php foreach ($schools as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterSchool == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <!-- Φίλτρο ανά χρήστη -->
    <div class="form-group" style="margin-bottom:0;min-width:160px">
      <label class="form-label">Χρήστης</label>
      <select name="user_id" class="form-control">
        <option value="">Όλοι</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">
      <i class="fa-solid fa-filter"></i> Φίλτρο
    </button>
    <!-- Καθαρισμός όλων των φίλτρων (redirect χωρίς παραμέτρους) -->
    <a href="<?= APP_URL ?>/admin/audit.php" class="btn btn-ghost btn-sm">
      <i class="fa-solid fa-xmark"></i> Καθαρισμός
    </a>
  </form>
</div>

<!-- ── Πίνακας Audit Logs ─────────────────────────────────────────────────── -->
<div class="card p-0">
  <!-- Επικεφαλίδα με σύνολο και τρέχουσα σελίδα -->
  <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border)" class="text-sm text-muted">
    Σύνολο εγγραφών: <strong><?= $total ?></strong>
    &nbsp;·&nbsp; Σελίδα <?= $page ?> από <?= max(1, $totalPages) ?>
  </div>
  <div class="table-wrap"><table>
    <thead>
      <tr>
        <th>Ημ/νία</th>
        <th>Χρήστης</th>
        <th>Σχολή</th>
        <th>Ενέργεια</th>
        <th>Οντότητα</th> <!-- Τύπος + ID της επηρεαζόμενης οντότητας -->
        <th>IP</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <!-- Ημερομηνία/ώρα σε μορφή d/m/Y H:i:s -->
        <td class="text-xs text-muted" style="white-space:nowrap">
          <?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?>
        </td>
        <!-- Χρήστης: εμφανίζει όνομα ή παύλα αν ήταν system action -->
        <td>
          <?php if ($l['user_name']): ?>
            <i class="fa-solid fa-user text-muted" style="font-size:.75rem"></i> <?= h($l['user_name']) ?>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <!-- Σχολή: fallback 'System' αν δεν συνδέεται με σχολή -->
        <td><?= h($l['school_name'] ?? 'System') ?></td>
        <!-- Ενέργεια ως badge (π.χ. 'login', 'athlete_created') -->
        <td><span class="badge badge-basic"><?= h($l['action']) ?></span></td>
        <!-- Οντότητα: τύπος (π.χ. 'athlete') και ID -->
        <td>
          <?php if ($l['entity_type']): ?>
            <span class="text-sm"><?= h($l['entity_type']) ?> <span class="text-muted">#<?= $l['entity_id'] ?></span></span>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td class="text-xs text-muted"><?= h($l['ip'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($logs)): ?>
      <tr><td colspan="6" class="text-center text-muted" style="padding:2rem">Δεν βρέθηκαν εγγραφές</td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.35rem 0">
  <form method="GET" style="display:inline-flex;align-items:center;margin-right:.5rem">
    <?php foreach(array_filter($_GET, fn($k)=>$k!=='per_page'&&$k!=='page', ARRAY_FILTER_USE_KEY) as $gk=>$gv): ?>
    <input type="hidden" name="<?= h($gk) ?>" value="<?= h($gv) ?>">
    <?php endforeach; ?>
    <select name="per_page" class="pg-size-select" onchange="this.form.submit()" style="font-size:.8rem;padding:.28rem .5rem;border-radius:7px;border:1px solid var(--border,#1e2536);background:var(--card,#111827);color:var(--text,#e2e8f0);cursor:pointer">
      <?php foreach([10,25,50,100] as $n): ?>
      <option value="<?= $n ?>"<?= $limit==$n?' selected':'' ?>><?= $n ?> / σελίδα</option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php if($page>1):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>" class="page-btn" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a><?php endif;?>
  <?php if($page>1):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="page-btn" title="Προηγούμενη"><i class="fa-solid fa-chevron-left"></i></a><?php endif;?>
  <?php if($page>3):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>" class="page-btn">1</a><?php if($page>4):?><span class="page-btn" style="pointer-events:none">…</span><?php endif;?><?php endif;?>
  <?php for($pp=max(1,$page-2);$pp<=min($totalPages,$page+2);$pp++):?>
  <a href="?<?= http_build_query(array_merge($_GET,['page'=>$pp])) ?>" class="page-btn <?=$pp==$page?'active':''?>"><?=$pp?></a>
  <?php endfor;?>
  <?php if($page<$totalPages-2):?><?php if($page<$totalPages-3):?><span class="page-btn" style="pointer-events:none">…</span><?php endif;?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>" class="page-btn"><?=$totalPages?></a><?php endif;?>
  <?php if($page<$totalPages):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="page-btn" title="Επόμενη"><i class="fa-solid fa-chevron-right"></i></a><?php endif;?>
  <?php if($page<$totalPages):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>" class="page-btn" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a><?php endif;?>
  <span style="font-size:.8rem;color:var(--muted);margin-left:.4rem"><?=$page?> / <?=$totalPages?></span>
</div>
<?php endif; ?>

</div></div></div>
</body></html>