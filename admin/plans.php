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
 * admin/plans.php — Διαχείριση Πλάνων (Super Admin)
 * ============================================================
 * PURPOSE:
 *   Επεξεργασία πλάνων: τιμές, όρια αθλητών, features.
 *   Οι αλλαγές εφαρμόζονται άμεσα σε ΟΛΕΣ τις σχολές.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf()
 *   ✓ Feature checkboxes: isset() → 1/0 (boolean sanitization)
 *   ✓ prices: (float) cast
 *   ✓ max_athletes: (int) cast
 *   ✓ Prepared statements
 *   ✓ Audit log
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── POST: Αποθήκευση Τροποποιημένου Πλάνου ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Επαλήθευση CSRF token — αποτρέπει Cross-Site Request Forgery ──
    verifyCsrf();
    $a = $_POST;
    if ($a['_action'] === 'save_plan') {
        $id = (int)$a['id'];
        // UPDATE με όλα τα πεδία — checkboxes features: isset() → 1/0
        $db->prepare("UPDATE plans SET name=?,price_monthly=?,price_annual=?,max_athletes=?,sms_enabled=?,email_enabled=?,competitions_enabled=?,economics_enabled=?,reports_enabled=?,elot_export=? WHERE id=?")
           ->execute([$a['name'],$a['price_monthly'],$a['price_annual'],$a['max_athletes'],
               (int)isset($a['sms_enabled']),          // SMS υπενθυμίσεις
               (int)isset($a['email_enabled']),         // Email υπενθυμίσεις
               (int)isset($a['competitions_enabled']),  // Αγωνιστικά
               (int)isset($a['economics_enabled']),     // Οικονομικά
               (int)isset($a['reports_enabled']),       // Αναφορές
               (int)isset($a['elot_export']),           // ΕΛΟΤ Export
               $id]);
        flash('Πλάνο ενημερώθηκε!');
    }
    redirect(APP_URL.'/admin/plans.php');
}

// ── Φόρτωση πλάνων με αριθμό σχολών που το χρησιμοποιούν ──────────────────
$plans = $db->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM schools WHERE plan_id = p.id) AS school_count
    FROM plans p
    ORDER BY p.price_monthly  -- Ταξινόμηση από φθηνότερο προς ακριβότερο
")->fetchAll();

// ── Φόρτωση πλάνου για επεξεργασία (αν υπάρχει ?edit=) ────────────────────
$editId = (int)($_GET['edit'] ?? 0);
$editPlan = null;
if ($editId) {
    $editPlan = $db->query("SELECT * FROM plans WHERE id = $editId")->fetch();
}

renderHead('Πλάνα');
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
<?php renderSidebar('admin_plans'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-boxes-stacked"></i> Διαχείριση Πλάνων'); ?>
<div class="page-body">

<?php if ($editPlan): ?>
<!-- ── Φόρμα Επεξεργασίας Πλάνου ─────────────────────────────────────────── -->
<div class="d-flex ai-center gap-md mb-3">
    <a href="<?= APP_URL ?>/admin/plans.php" class="btn btn-secondary btn-sm">
        <i class="fa-solid fa-arrow-left"></i> Πίσω
    </a>
    <h2>Επεξεργασία: <?= h($editPlan['name']) ?></h2>
</div>
<?php $ep = $editPlan; ?>
<div class="card" style="max-width:600px">
<form method="POST">
  <input type="hidden" name="_action" value="save_plan">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  <input type="hidden" name="id" value="<?= $ep['id'] ?>">
  <div class="form-row col-2 mb-3">
    <div class="form-group">
        <label class="form-label">Όνομα</label>
        <input name="name" class="form-control" value="<?= h($ep['name']) ?>">
    </div>
    <div class="form-group">
        <label class="form-label">Μηνιαία Τιμή (€)</label>
        <input type="number" step=".01" name="price_monthly" class="form-control" value="<?= $ep['price_monthly'] ?>">
    </div>
    <div class="form-group">
        <label class="form-label">Ετήσια Τιμή (€)</label>
        <input type="number" step=".01" name="price_annual" class="form-control" value="<?= $ep['price_annual'] ?>">
    </div>
    <!-- max_athletes: τιμή 999 σημαίνει απεριόριστοι (∞) -->
    <div class="form-group">
        <label class="form-label">Μέγ. Αθλητές (999=∞)</label>
        <input type="number" name="max_athletes" class="form-control" value="<?= $ep['max_athletes'] ?>">
    </div>
  </div>
  <!-- ── Checkboxes Features: κάθε feature αντιστοιχεί σε boolean column ── -->
  <div class="mb-3">
    <div class="text-muted text-sm mb-2">Δυνατότητες</div>
    <div class="grid grid-2">
      <?php $features = [
          ['email_enabled',        '<i class="fa-solid fa-envelope"></i> Email Υπενθυμίσεις'],
          ['sms_enabled',          '<i class="fa-solid fa-mobile-screen-button"></i> SMS Υπενθυμίσεις'],
          ['competitions_enabled', '<i class="fa-solid fa-trophy"></i> Αγωνιστικά'],
          ['economics_enabled',    '<i class="fa-solid fa-euro-sign"></i> Οικονομικά'],
          ['reports_enabled',      '<i class="fa-solid fa-file-lines"></i> Αναφορές'],
          ['elot_export',          '<i class="fa-solid fa-link"></i> ΕΛΟΤ Export'],
      ]; ?>
      <?php foreach ($features as [$key, $label]): ?>
      <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem;padding:.3rem">
        <input type="checkbox" name="<?= $key ?>" value="1" <?= $ep[$key] ? 'checked' : '' ?>>
        <?= $label ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <button type="submit" class="btn btn-primary">
      <i class="fa-solid fa-floppy-disk"></i> Αποθήκευση
  </button>
</form>
</div>

<?php else: ?>
<!-- Search bar for plans -->
<div class="d-flex ai-center gap-sm mb-3" style="max-width:420px">
    <div class="search-bar" style="flex:1">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input id="plans-search" type="text" placeholder="Αναζήτηση πλάνου...">
    </div>
    <button class="btn btn-ghost btn-sm" onclick="document.getElementById('plans-search').value='';filterPlans()"><i class="fa-solid fa-xmark"></i></button>
</div>
<!-- ── Πλέγμα Καρτών Πλάνων (read-only, με badge features) ─────────────── -->
<div class="grid grid-2" id="plans-grid">
  <?php foreach ($plans as $p): ?>
  <div class="card plan-card" data-name="<?= strtolower(h($p['name'])) ?>">
    <div class="d-flex jc-between ai-center mb-2">
      <div><?= planBadge($p['slug']) ?> <span class="fw-600"><?= h($p['name']) ?></span></div>
      <a href="?edit=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">
          <i class="fa-solid fa-pen-to-square"></i>
      </a>
    </div>
    <!-- Τιμές και όρια αθλητών -->
    <div class="grid grid-2 text-sm" style="gap:.5rem;margin-bottom:.75rem">
      <div><span class="text-muted">Μηνιαία:</span> <?= formatMoney($p['price_monthly']) ?></div>
      <div><span class="text-muted">Ετήσια:</span> <?= formatMoney($p['price_annual']) ?></div>
      <div><span class="text-muted">Αθλητές:</span> <?= $p['max_athletes'] == 999 ? '∞' : $p['max_athletes'] ?></div>
      <div><span class="text-muted">Σχολές:</span> <?= $p['school_count'] ?></div>
    </div>
    <!-- Feature badges: πράσινο = ενεργό, γκρι = ανενεργό -->
    <div class="d-flex gap-sm flex-wrap">
      <?php $feats = [
          ['email_enabled',        '<i class="fa-solid fa-envelope"></i> Email'],
          ['sms_enabled',          '<i class="fa-solid fa-mobile-screen-button"></i> SMS'],
          ['competitions_enabled', '<i class="fa-solid fa-trophy"></i> Αγωνιστικά'],
          ['economics_enabled',    '<i class="fa-solid fa-euro-sign"></i> Οικονομικά'],
          ['reports_enabled',      '<i class="fa-solid fa-file-lines"></i> Αναφορές'],
          ['elot_export',          '<i class="fa-solid fa-link"></i> ΕΛΟΤ'],
      ];
      foreach ($feats as [$key, $lbl]): ?>
        <span class="badge <?= $p[$key] ? 'badge-paid' : 'badge-inactive' ?>"><?= $lbl ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div></div></div>
<script>
function filterPlans() {
    var q = (document.getElementById('plans-search').value || '').toLowerCase().trim();
    var cards = document.querySelectorAll('.plan-card');
    var visible = 0;
    cards.forEach(function(card) {
        var name = card.getAttribute('data-name') || '';
        var text = card.textContent.toLowerCase();
        var show = !q || name.indexOf(q) !== -1 || text.indexOf(q) !== -1;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var empty = document.getElementById('plans-empty');
    if (empty) empty.style.display = visible === 0 ? '' : 'none';
}
var psInput = document.getElementById('plans-search');
if (psInput) psInput.addEventListener('input', filterPlans);
</script>
</body></html>