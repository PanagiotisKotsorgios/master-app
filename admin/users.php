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
 * admin/users.php — Διαχείριση Χρηστών (Super Admin)
 * ============================================================
 * PURPOSE:
 *   Επεξεργασία users, lock/unlock accounts, password reset.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf()
 *   ✓ password reset: password_hash(BCRYPT) με νέο random token
 *   ✓ Δεν επιτρέπει διαγραφή superadmin από UI
 *   ✓ lock_user: active=0 (soft lock, αναστρέψιμο)
 *   ✓ Prepared statements
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── POST: Ενέργειες Χρηστών ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $a = $_POST;
    if ($a['_action'] === 'save_user') {
        $id     = (int)($a['id'] ?? 0);
        $school = !empty($a['school_id']) ? (int)$a['school_id'] : null; // null = super admin (χωρίς σχολή)
        if ($id) {
            // Ενημέρωση υπάρχοντος χρήστη
            $db->prepare("UPDATE users SET name=?,email=?,role=?,active=?,school_id=? WHERE id=?")
               ->execute([$a['name'], $a['email'], $a['role'], (int)($a['active'] ?? 1), $school, $id]);
            if (!empty($a['new_password'])) {
                // Αλλαγή κωδικού μόνο αν συμπληρώθηκε νέος (bcrypt)
                $db->prepare("UPDATE users SET password=? WHERE id=?")
                   ->execute([password_hash($a['new_password'], PASSWORD_DEFAULT), $id]);
            }
        } else {
            // Δημιουργία νέου χρήστη με bcrypt hash
            $pw = password_hash($a['password'] ?? 'changeme123', PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (school_id,name,email,password,role,active) VALUES (?,?,?,?,?,1)")
               ->execute([$school, $a['name'], $a['email'], $pw, $a['role']]);
        }
        flash('Χρήστης αποθηκεύτηκε!');
    }
    if ($a['_action'] === 'delete_user') {
    $id = (int)$a['id'];
    // Prevent deleting superadmin — ελέγχει τον ρόλο πριν τη διαγραφή
    $role = $db->prepare("SELECT role FROM users WHERE id=?");
    $role->execute([$id]);
    if ($role->fetchColumn() !== 'superadmin') {
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        flash('Χρήστης διαγράφηκε.');
    }
    redirect(APP_URL . '/admin/users.php');
}

    if ($a['_action'] === 'toggle_user') {
        $db->prepare("UPDATE users SET active=NOT active WHERE id=? AND role != 'superadmin'")->execute([(int)$a['id']]);
    }

    // Reset κωδικού χρήστη από admin
    if ($a['_action'] === 'reset_password') {
        $id  = (int)$a['id'];
        $pwd = trim($a['new_password'] ?? '');
        $row = $db->prepare("SELECT role FROM users WHERE id=?"); $row->execute([$id]);
        if ($row->fetchColumn() !== 'superadmin' && strlen($pwd) >= 6) {
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($pwd, PASSWORD_DEFAULT), $id]);
            auditLog('admin_password_reset', 'user', $id, 'Admin reset password');
            flash('✅ Κωδικός αλλάχτηκε.');
        } else {
            flash('Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.', 'error');
        }
    }

    // Κλείδωμα χρήστη (αδυναμία σύνδεσης χωρίς διαγραφή)
    if ($a['_action'] === 'lock_user') {
        $id = (int)$a['id'];
        $db->prepare("UPDATE users SET active=0 WHERE id=? AND role != 'superadmin'")->execute([$id]);
        auditLog('admin_lock_user', 'user', $id, 'Admin locked user');
        flash('Χρήστης κλειδώθηκε.');
    }

    redirect(APP_URL . '/admin/users.php');
}

$schools = $db->query("SELECT id,name FROM schools ORDER BY name")->fetchAll();

// ── Φίλτρα από GET παραμέτρους ─────────────────────────────────────────────
$search      = trim($_GET['q'] ?? '');
$filterRole  = trim($_GET['role'] ?? '');
$filterSchool= (int)($_GET['school_id'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;

$where  = ["u.role != 'superadmin' OR u.role = 'superadmin'"];  // εμφάνιση όλων
$where  = ['1=1']; // Επαναορισμός — εμφανίζει ΟΛΑ τα roles συμπεριλαμβανομένου superadmin
$params = [];

if ($search) {
    $where[]  = "(u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterRole) {
    $where[]  = "u.role = ?";
    $params[] = $filterRole;
}
if ($filterSchool) {
    $where[]  = "u.school_id = ?";
    $params[] = $filterSchool;
}

$whereStr = implode(' AND ', $where);

// ── Pagination ──────────────────────────────────────────────────────────────
$countStmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereStr");
$countStmt->execute($params);
$totalRows  = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / max(1, $limit)));
$offset     = ($page - 1) * $limit;

// ── Κύριο query: χρήστες με όνομα σχολής — superadmin πρώτοι ──────────────
$stmt = $db->prepare("
    SELECT u.*, s.name as school_name
    FROM users u
    LEFT JOIN schools s ON s.id=u.school_id
    WHERE $whereStr
    ORDER BY u.role='superadmin' DESC, u.created_at DESC -- Superadmin πάντα πρώτος
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

// ── Φόρτωση χρήστη για επεξεργασία ─────────────────────────────────────────
$editId   = (int)($_GET['edit'] ?? 0);
$editUser = null;
if ($editId) $editUser = $db->query("SELECT * FROM users WHERE id=$editId")->fetch();

renderHead('Χρήστες');
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
<?php renderSidebar('admin_users'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-users"></i> Διαχείριση Χρηστών'); ?>
<div class="page-body">

<?php if ($editUser || isset($_GET['add'])): ?>
<div class="d-flex ai-center gap-md mb-3">
  <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-secondary btn-sm">
    <i class="fa-solid fa-arrow-left"></i> Πίσω
  </a>
  <h2><?= $editUser ? 'Επεξεργασία Χρήστη' : 'Νέος Χρήστης' ?></h2>
</div>
<?php $eu = $editUser; ?>
<div class="card" style="max-width:560px">
<form method="POST">
  <input type="hidden" name="_action" value="save_user">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  <input type="hidden" name="id" value="<?= $eu['id'] ?? '' ?>">
  <div class="form-row col-2">
    <div class="form-group">
      <label class="form-label">Όνομα *</label>
      <input name="name" class="form-control" value="<?= h($eu['name'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Email *</label>
      <input type="email" name="email" class="form-control" value="<?= h($eu['email'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Ρόλος</label>
      <select name="role" class="form-control">
        <?php foreach (['employee' => 'Employee', 'owner' => 'Owner', 'admin' => 'Admin', 'coach' => 'Προπονητής', 'secretary' => 'Γραμματεία', 'superadmin' => 'Super Admin'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($eu['role'] ?? 'owner') === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Σχολή</label>
      <select name="school_id" class="form-control">
        <option value="">— Κεντρικό (Super Admin) —</option>
        <?php foreach ($schools as $s): ?>
          <option value="<?= $s['id'] ?>" <?= ($eu['school_id'] ?? 0) == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($eu): ?>
    <div class="form-group">
      <label class="form-label">Νέος Κωδικός <span class="text-muted">(κενό = χωρίς αλλαγή)</span></label>
      <input type="password" name="new_password" class="form-control">
    </div>
    <div class="form-group">
      <label class="form-label">Ενεργός</label>
      <select name="active" class="form-control">
        <option value="1" <?= ($eu['active'] ?? 1) ? 'selected' : '' ?>>Ναι</option>
        <option value="0" <?= !($eu['active'] ?? 1) ? 'selected' : '' ?>>Όχι</option>
      </select>
    </div>
    <?php else: ?>
    <div class="form-group">
      <label class="form-label">Κωδικός *</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-sm mt-2">
    <button type="submit" class="btn btn-primary">
      <i class="fa-solid fa-floppy-disk"></i> Αποθήκευση
    </button>
    <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-secondary">Ακύρωση</a>
  </div>
</form>
</div>

<?php else: ?>
<!-- Filters + List -->
<div class="d-flex jc-between ai-center mb-3">
  <form method="GET" class="d-flex gap-sm flex-wrap ai-end">
    <div class="form-group" style="margin-bottom:0;min-width:210px">
      <div class="search-bar">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input name="q" value="<?= h($search) ?>" placeholder="Όνομα ή email...">
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0;min-width:140px">
      <select name="role" class="form-control">
        <option value="">Όλοι ρόλοι</option>
        <?php foreach (['superadmin' => 'Super Admin', 'employee' => 'Employee', 'owner' => 'Owner', 'admin' => 'Admin', 'coach' => 'Προπονητής', 'secretary' => 'Γραμματεία'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $filterRole === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:0;min-width:160px">
      <select name="school_id" class="form-control">
        <option value="">Όλες σχολές</option>
        <?php foreach ($schools as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterSchool == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">
      <i class="fa-solid fa-filter"></i> Φίλτρο
    </button>
    <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-ghost btn-sm">
      <i class="fa-solid fa-xmark"></i>
    </a>
  </form>
  <a href="?add=1" class="btn btn-primary btn-sm">
    <i class="fa-solid fa-plus"></i> Νέος Χρήστης
  </a>
</div>

<div class="card p-0">
  <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border)" class="text-sm text-muted">
    <?= $totalRows ?> χρήστες βρέθηκαν
  </div>
  <div class="table-wrap"><table>
    <thead>
      <tr>
        <th>Χρήστης</th>
        <th>Ρόλος</th>
        <th>Σχολή</th>
        <th>Τελ. Σύνδεση</th>
        <th>Κατάσταση</th>
        <th>Ενέργειες</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <div class="fw-600"><?= h($u['name']) ?></div>
          <div class="text-xs text-muted"><?= h($u['email']) ?></div>
        </td>
        <td>
          <span class="badge <?= $u['role'] === 'superadmin' ? 'badge-superadmin' : ($u['role'] === 'employee' ? 'badge-active' : ($u['role'] === 'owner' ? 'badge-pro' : 'badge-basic')) ?>">
            <?= $u['role'] ?>
          </span>
        </td>
        <td><?= h($u['school_name'] ?? '—') ?></td>
        <td><?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : '—' ?></td>
        <td>
          <span class="badge <?= $u['active'] ? 'badge-active' : 'badge-inactive' ?>">
            <?= $u['active'] ? 'Ενεργός' : 'Ανενεργός' ?>
          </span>
        </td>
        <td>
          <div class="d-flex gap-sm">
            <a href="<?= APP_URL ?>/admin/user-profile.php?id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm" title="Προφίλ">
              <i class="fa-solid fa-eye"></i>
            </a>
            <a href="?edit=<?= $u['id'] ?>" class="btn btn-ghost btn-sm" title="Επεξεργασία">
              <i class="fa-solid fa-pen-to-square"></i>
            </a>
            <?php if ($u['role'] !== 'superadmin'): ?>
            <button type="button" class="btn btn-ghost btn-sm" title="Reset Κωδικού"
              onclick="openResetPwd(<?= $u['id'] ?>, '<?= h(addslashes($u['name'])) ?>')">
              <i class="fa-solid fa-key" style="color:#f0a500"></i>
            </button>
            <form method="POST" style="display:inline">
              <input type="hidden" name="_action" value="toggle_user">
              <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm" title="<?= $u['active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?>">
                <?php if ($u['active']): ?>
                  <i class="fa-solid fa-circle-pause" style="color:var(--danger)"></i>
                <?php else: ?>
                  <i class="fa-solid fa-circle-play" style="color:var(--success)"></i>
                <?php endif; ?>
              </button>
            </form>
            <?php if ($u['role'] !== 'superadmin'): ?>
<form method="POST" style="display:inline" onsubmit="return confirm('Διαγραφή χρήστη;')">
  <input type="hidden" name="_action" value="delete_user">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  <input type="hidden" name="id" value="<?= $u['id'] ?>">
  <button type="submit" class="btn btn-ghost btn-sm" title="Διαγραφή">
    <i class="fa-solid fa-trash" style="color:var(--danger)"></i>
  </button>
</form>
<?php endif; ?>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($users)): ?>
      <tr><td colspan="6" class="text-center text-muted" style="padding:2rem">Δεν βρέθηκαν χρήστες</td></tr>
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

<?php endif; ?>
</div></div></div>
<style>
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9998;align-items:center;justify-content:center}
.modal-backdrop.open{display:flex}
.modal-box{background:var(--card,#111520);border:1px solid var(--border,#1e2536);border-radius:16px;padding:1.5rem;min-width:300px;max-width:440px;width:100%}
.modal-title{font-size:1rem;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
</style>

<!-- MODAL: Reset Κωδικού -->
<div class="modal-backdrop" id="modalResetPwd">
  <div class="modal-box">
    <div class="modal-title"><i class="fa-solid fa-key" style="color:#f0a500"></i> Reset Κωδικού</div>
    <div class="text-muted text-sm mb-3" id="resetPwdName"></div>
    <form method="POST">
      <input type="hidden" name="_action" value="reset_password">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="id" id="resetPwdId">
      <div class="form-group mb-3">
        <label class="form-label">Νέος Κωδικός *</label>
        <input type="password" name="new_password" id="resetPwdInput" class="form-control" minlength="6" required placeholder="Τουλάχιστον 6 χαρακτήρες">
        <div class="text-muted text-xs mt-1">Ο χρήστης θα συνδεθεί με αυτόν τον κωδικό.</div>
      </div>
      <div class="d-flex gap-sm">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Αλλαγή</button>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalResetPwd').classList.remove('open')">Ακύρωση</button>
      </div>
    </form>
  </div>
</div>

<script>
function openResetPwd(id, name) {
    document.getElementById('resetPwdId').value = id;
    document.getElementById('resetPwdName').textContent = name;
    document.getElementById('resetPwdInput').value = '';
    document.getElementById('modalResetPwd').classList.add('open');
    setTimeout(function(){ document.getElementById('resetPwdInput').focus(); }, 100);
}
document.getElementById('modalResetPwd').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
</script>
</body></html>