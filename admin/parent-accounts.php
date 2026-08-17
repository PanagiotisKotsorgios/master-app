<?php
/**
 * ============================================================
 * admin/parent-accounts.php — Διαχείριση Λογαριασμών Γονέων
 * ============================================================
 * Super-admin: view, edit, reset password, pause, delete,
 * impersonate parent portal accounts across all schools.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf() on POST
 *   ✓ Impersonation uses separate session key, not full session swap
 *   ✓ Prepared statements throughout
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/mailer.php';

requireSuperAdmin();

$db = getDB();

// ── Auto-migration: add active column if missing ──────────────────────────────
try {
    $cols = array_column($db->query("DESCRIBE parent_users")->fetchAll(), 'Field');
    if (!in_array('active', $cols, true)) {
        $db->exec("ALTER TABLE parent_users ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `last_login`");
    }
} catch (Throwable $e) {
    error_log('[parent-accounts] migration failed: ' . $e->getMessage());
}

// ── POST Actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['_action'] ?? '';

    // ── Delete parent account ──────────────────────────────────────────────
    if ($act === 'delete_parent') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid > 0) {
            $db->prepare("DELETE FROM parent_children WHERE parent_id = ?")->execute([$pid]);
            $db->prepare("DELETE FROM parent_users WHERE id = ?")->execute([$pid]);
            auditLog('admin_delete_parent', 'parent_user', $pid);
            flash('Ο λογαριασμός γονέα διαγράφηκε.');
        }
        redirect(APP_URL . '/admin/parent-accounts.php');
    }

    // ── Toggle pause (active toggle) ──────────────────────────────────────
    if ($act === 'toggle_parent') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid > 0) {
            $db->prepare("UPDATE parent_users SET active = NOT active WHERE id = ?")->execute([$pid]);
            auditLog('admin_toggle_parent', 'parent_user', $pid);
            flash('Κατάσταση λογαριασμού ενημερώθηκε.');
        }
        redirect(APP_URL . '/admin/parent-accounts.php');
    }

    // ── Edit parent email ──────────────────────────────────────────────────
    if ($act === 'edit_parent') {
        $pid   = (int)($_POST['id'] ?? 0);
        $email = strtolower(trim($_POST['parent_email'] ?? ''));
        if ($pid > 0 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Check uniqueness within the same school
            $sch = $db->prepare("SELECT school_id FROM parent_users WHERE id = ? LIMIT 1");
            $sch->execute([$pid]);
            $schoolId = (int)$sch->fetchColumn();
            $dup = $db->prepare("SELECT id FROM parent_users WHERE school_id = ? AND parent_email = ? AND id != ? LIMIT 1");
            $dup->execute([$schoolId, $email, $pid]);
            if ($dup->fetchColumn()) {
                flash('Αυτό το email χρησιμοποιείται ήδη στη σχολή.', 'danger');
            } else {
                $db->prepare("UPDATE parent_users SET parent_email = ? WHERE id = ?")->execute([$email, $pid]);
                auditLog('admin_edit_parent', 'parent_user', $pid);
                flash('Email ενημερώθηκε.');
            }
        } else {
            flash('Μη έγκυρο email.', 'danger');
        }
        redirect(APP_URL . '/admin/parent-accounts.php');
    }

    // ── Reset password & resend credentials ───────────────────────────────
    if ($act === 'reset_parent_password') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid > 0) {
            $row = $db->prepare("
                SELECT pu.parent_email, s.name AS school_name,
                       (SELECT a.full_name FROM parent_children pc
                        JOIN athletes a ON a.id = pc.athlete_id
                        WHERE pc.parent_id = pu.id LIMIT 1) AS athlete_name
                FROM parent_users pu
                JOIN schools s ON s.id = pu.school_id
                WHERE pu.id = ? LIMIT 1
            ");
            $row->execute([$pid]);
            $parent = $row->fetch();
            if ($parent) {
                $rawPassword  = bin2hex(random_bytes(5));
                $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);
                $db->prepare("UPDATE parent_users SET password_hash = ?, first_login = 1 WHERE id = ?")
                   ->execute([$passwordHash, $pid]);
                auditLog('admin_reset_parent_password', 'parent_user', $pid);
                $emailDebug = null;
                $sent = sendParentCredentials(
                    $parent['parent_email'],
                    $rawPassword,
                    (string)($parent['athlete_name'] ?? ''),
                    (string)($parent['school_name'] ?? 'MAster'),
                    $emailDebug
                );
                // Always store credentials in session for display — regardless of email success
                $_SESSION['_pending_parent_creds'] = [
                    'email'      => $parent['parent_email'],
                    'password'   => $rawPassword,
                    'email_sent' => $sent,
                    'email_debug'=> $emailDebug,
                    'expires'    => time() + 120,
                ];
                if ($sent) {
                    flash('✉️ Νέοι κωδικοί στάλθηκαν στο ' . h($parent['parent_email']) . '.');
                } else {
                    $hint = $emailDebug ? ' (' . htmlspecialchars(substr($emailDebug, 0, 100), ENT_QUOTES, 'UTF-8') . ')' : '';
                    flash('Email δεν στάλθηκε' . $hint . '. Δείτε τα στοιχεία παρακάτω.', 'warning');
                }
            }
        }
        redirect(APP_URL . '/admin/parent-accounts.php');
    }

    // ── Generate credentials (show only, no email required) ───────────────
    if ($act === 'generate_credentials') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid > 0) {
            $row = $db->prepare("SELECT parent_email FROM parent_users WHERE id = ? LIMIT 1");
            $row->execute([$pid]);
            $pEmail = (string)($row->fetchColumn() ?: '');
            if ($pEmail) {
                $rawPassword  = bin2hex(random_bytes(5));
                $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);
                $db->prepare("UPDATE parent_users SET password_hash = ?, first_login = 1 WHERE id = ?")
                   ->execute([$passwordHash, $pid]);
                auditLog('admin_generate_parent_credentials', 'parent_user', $pid);
                $_SESSION['_pending_parent_creds'] = [
                    'email'      => $pEmail,
                    'password'   => $rawPassword,
                    'email_sent' => false,
                    'email_debug'=> null,
                    'expires'    => time() + 120,
                ];
            }
        }
        redirect(APP_URL . '/admin/parent-accounts.php');
    }

    // ── Impersonate: set session flag and redirect to parent portal ────────
    if ($act === 'impersonate_parent') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid > 0) {
            $row = $db->prepare("
                SELECT pu.id, pu.school_id, pu.parent_email, s.name AS school_name
                FROM parent_users pu
                JOIN schools s ON s.id = pu.school_id
                WHERE pu.id = ? LIMIT 1
            ");
            $row->execute([$pid]);
            $parent = $row->fetch();
            if ($parent) {
                // Store admin's original session so we can restore it on exit
                $_SESSION['admin_impersonating_parent'] = true;
                $_SESSION['admin_original_user_id']     = userId();

                // Set parent session
                $_SESSION['is_parent']    = true;
                $_SESSION['parent_id']    = $parent['id'];
                $_SESSION['user_id']      = $parent['id'];
                $_SESSION['school_id']    = $parent['school_id'];
                $_SESSION['school_name']  = $parent['school_name'];
                $_SESSION['parent_email'] = $parent['parent_email'];
                $_SESSION['parent_first_login'] = false;

                auditLog('admin_impersonate_parent', 'parent_user', $pid);
                redirect(APP_URL . '/parent/index.php');
            }
        }
        redirect(APP_URL . '/admin/parent-accounts.php');
    }
}

// ── Exit impersonation ────────────────────────────────────────────────────────
if (isset($_GET['exit_impersonate']) && isset($_SESSION['admin_impersonating_parent'])) {
    $adminId = $_SESSION['admin_original_user_id'] ?? null;
    // Clear parent keys
    unset(
        $_SESSION['is_parent'],
        $_SESSION['parent_id'],
        $_SESSION['parent_email'],
        $_SESSION['parent_first_login'],
        $_SESSION['admin_impersonating_parent'],
        $_SESSION['admin_original_user_id']
    );
    // Restore admin
    if ($adminId) {
        $adminRow = $db->prepare("SELECT id, school_id, name, role FROM users WHERE id = ? LIMIT 1");
        $adminRow->execute([$adminId]);
        $adm = $adminRow->fetch();
        if ($adm) {
            $_SESSION['user_id']    = $adm['id'];
            $_SESSION['school_id']  = $adm['school_id'];
            $_SESSION['user_role']  = $adm['role'];
            $_SESSION['user_name']  = $adm['name'];
            unset($_SESSION['is_parent']);
        }
    }
    flash('Επιστρέψατε στο Admin Panel.');
    redirect(APP_URL . '/admin/parent-accounts.php');
}

// ── Pick up pending credentials from session ─────────────────────────────────
$pendingCreds = null;
if (!empty($_SESSION['_pending_parent_creds'])) {
    $pc = $_SESSION['_pending_parent_creds'];
    unset($_SESSION['_pending_parent_creds']);
    if (isset($pc['expires']) && $pc['expires'] > time()) {
        $pendingCreds = $pc;
    }
}

// ── Filters & Pagination ──────────────────────────────────────────────────────
$search      = trim($_GET['q']         ?? '');
$filterSchool = (int)($_GET['school_id'] ?? 0);
$filterStatus = trim($_GET['status']    ?? ''); // 'active','inactive',''
$page  = max(1, (int)($_GET['page']  ?? 1));
$limit = 25;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = "(pu.parent_email LIKE ? OR a.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterSchool > 0) {
    $where[]  = "pu.school_id = ?";
    $params[] = $filterSchool;
}
if ($filterStatus === 'active') {
    $where[] = "pu.active = 1";
} elseif ($filterStatus === 'inactive') {
    $where[] = "pu.active = 0";
}

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("
    SELECT COUNT(DISTINCT pu.id)
    FROM parent_users pu
    LEFT JOIN parent_children pc ON pc.parent_id = pu.id
    LEFT JOIN athletes a ON a.id = pc.athlete_id
    WHERE $whereStr
");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $limit;

$stmt = $db->prepare("
    SELECT
        pu.id,
        pu.parent_email,
        pu.school_id,
        pu.active,
        pu.first_login,
        pu.last_login,
        pu.created_at,
        s.name AS school_name,
        GROUP_CONCAT(a.full_name ORDER BY a.full_name SEPARATOR ', ') AS athlete_names,
        COUNT(DISTINCT pc.athlete_id) AS athlete_count
    FROM parent_users pu
    JOIN schools s ON s.id = pu.school_id
    LEFT JOIN parent_children pc ON pc.parent_id = pu.id
    LEFT JOIN athletes a ON a.id = pc.athlete_id
    WHERE $whereStr
    GROUP BY pu.id
    ORDER BY pu.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$parents = $stmt->fetchAll();

// Schools list for filter dropdown
$schools = $db->query("SELECT id, name FROM schools ORDER BY name")->fetchAll();

// Summary stats
$statsRow = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(active = 1) AS active_count,
        SUM(active = 0) AS paused_count,
        SUM(first_login = 1) AS never_logged_in
    FROM parent_users
")->fetch();

// ── Render ────────────────────────────────────────────────────────────────────
renderHead('Parent Accounts');
?>
<body>
<div class="app-layout">
<?php renderSidebar('admin_parent_accounts'); ?>
<div class="main-content">
<?php renderTopbar('Λογαριασμοί Γονέων', 'admin_parent_accounts'); ?>

<div class="page-body">

<?php if ($pendingCreds): ?>
<!-- Credentials display card -->
<div class="card" style="border:2px solid rgba(240,165,0,.5);background:rgba(240,165,0,.07);margin-bottom:1.5rem;padding:1.25rem 1.5rem">
  <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
    <div style="background:rgba(240,165,0,.18);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="fa-solid fa-key" style="color:#f0a500;font-size:1.1rem"></i>
    </div>
    <div>
      <div style="font-weight:800;font-size:1rem;color:#f0a500">Στοιχεία Σύνδεσης Γονέα</div>
      <div style="font-size:.78rem;color:var(--muted)">
        <?= $pendingCreds['email_sent'] ? '✉️ Email στάλθηκε επιτυχώς — αντίγραφο παρακάτω' : '⚠️ Email δεν στάλθηκε — δώστε τα στοιχεία χειροκίνητα' ?>
      </div>
    </div>
    <?php if (!$pendingCreds['email_sent'] && $pendingCreds['email_debug']): ?>
    <div style="font-size:.72rem;color:#e63946;margin-left:auto;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($pendingCreds['email_debug']) ?>">
      <?= h(substr($pendingCreds['email_debug'], 0, 80)) ?>
    </div>
    <?php endif; ?>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:1rem">
    <div style="flex:1;min-width:200px">
      <div style="font-size:.75rem;color:var(--muted);font-weight:700;margin-bottom:.3rem">EMAIL</div>
      <div style="font-family:monospace;font-size:.98rem;font-weight:700;background:rgba(0,0,0,.3);padding:.55rem .85rem;border-radius:8px;border:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:.6rem">
        <span id="credsEmail"><?= h($pendingCreds['email']) ?></span>
        <button type="button" onclick="copyText('credsEmail')" style="background:none;border:none;color:var(--muted);cursor:pointer;padding:.1rem .3rem;border-radius:4px;font-size:.8rem" title="Αντιγραφή"><i class="fa-solid fa-copy"></i></button>
      </div>
    </div>
    <div style="flex:1;min-width:200px">
      <div style="font-size:.75rem;color:var(--muted);font-weight:700;margin-bottom:.3rem">ΚΩΔΙΚΟΣ</div>
      <div style="font-family:monospace;font-size:1.1rem;font-weight:700;color:#f0a500;background:rgba(0,0,0,.3);padding:.55rem .85rem;border-radius:8px;border:1px solid rgba(240,165,0,.3);display:flex;align-items:center;gap:.6rem;letter-spacing:.08em">
        <span id="credsPass"><?= h($pendingCreds['password']) ?></span>
        <button type="button" onclick="copyText('credsPass')" style="background:none;border:none;color:#f0a500;cursor:pointer;padding:.1rem .3rem;border-radius:4px;font-size:.8rem" title="Αντιγραφή"><i class="fa-solid fa-copy"></i></button>
      </div>
    </div>
    <div style="display:flex;align-items:flex-end">
      <a href="<?= h(APP_URL) ?>/parent/login.php" target="_blank" style="font-size:.82rem;color:#58a6ff;text-decoration:none;white-space:nowrap"><i class="fa-solid fa-arrow-up-right-from-square"></i> Άνοιγμα portal</a>
    </div>
  </div>
  <div style="margin-top:.85rem;font-size:.76rem;color:var(--muted)"><i class="fa-solid fa-triangle-exclamation" style="color:#f0a500"></i> Αυτά τα στοιχεία εμφανίζονται μόνο μία φορά. Αποθηκεύστε τα πριν φύγετε από τη σελίδα.</div>
</div>
<?php endif; ?>

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem">
  <?php
  $stats = [
      ['Σύνολο', $statsRow['total'] ?? 0, 'fa-people-roof', '#58a6ff'],
      ['Ενεργοί', $statsRow['active_count'] ?? 0, 'fa-circle-check', '#2dc653'],
      ['Παυσαρισμένοι', $statsRow['paused_count'] ?? 0, 'fa-pause', '#f0a500'],
      ['Δεν συνδέθηκαν ποτέ', $statsRow['never_logged_in'] ?? 0, 'fa-clock', '#8892b0'],
  ];
  foreach ($stats as [$label, $val, $icon, $col]): ?>
  <div class="card" style="padding:1rem 1.1rem;display:flex;align-items:center;gap:.85rem">
    <div style="background:<?= h($col) ?>18;border-radius:50%;width:42px;height:42px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="fa-solid <?= h($icon) ?>" style="color:<?= h($col) ?>;font-size:1.1rem"></i>
    </div>
    <div>
      <div style="font-size:1.5rem;font-weight:800;line-height:1"><?= (int)$val ?></div>
      <div style="font-size:.78rem;color:var(--muted)"><?= h($label) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card" style="padding:1rem 1.25rem;margin-bottom:1.25rem">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
    <div style="flex:1;min-width:200px">
      <label style="font-size:.78rem;color:var(--muted);display:block;margin-bottom:.35rem;font-weight:700">Αναζήτηση</label>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Email ή όνομα αθλητή…"
             class="form-control" style="height:38px">
    </div>
    <div style="min-width:180px">
      <label style="font-size:.78rem;color:var(--muted);display:block;margin-bottom:.35rem;font-weight:700">Σχολή</label>
      <select name="school_id" class="form-control" style="height:38px">
        <option value="">Όλες</option>
        <?php foreach ($schools as $sc): ?>
        <option value="<?= (int)$sc['id'] ?>" <?= $filterSchool === (int)$sc['id'] ? 'selected' : '' ?>><?= h($sc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="min-width:130px">
      <label style="font-size:.78rem;color:var(--muted);display:block;margin-bottom:.35rem;font-weight:700">Κατάσταση</label>
      <select name="status" class="form-control" style="height:38px">
        <option value="">Όλες</option>
        <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Ενεργοί</option>
        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Παυσαρισμένοι</option>
      </select>
    </div>
    <div style="display:flex;gap:.5rem">
      <button type="submit" class="btn btn-primary" style="height:38px">
        <i class="fa-solid fa-magnifying-glass"></i> Φίλτρο
      </button>
      <a href="<?= APP_URL ?>/admin/parent-accounts.php" class="btn btn-ghost" style="height:38px">
        <i class="fa-solid fa-xmark"></i> Reset
      </a>
    </div>
  </form>
</div>

<!-- Table -->
<div class="card">
  <div style="padding:.9rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
    <div style="font-weight:800;font-size:1rem"><i class="fa-solid fa-people-roof" style="color:#58a6ff;margin-right:.5rem"></i>Λογαριασμοί Γονέων (<?= $totalRows ?>)</div>
  </div>

  <?php if (empty($parents)): ?>
  <div style="text-align:center;padding:3rem 1rem;color:var(--muted)">
    <i class="fa-solid fa-people-roof" style="font-size:2.5rem;opacity:.25;display:block;margin-bottom:.75rem"></i>
    Δεν βρέθηκαν λογαριασμοί
  </div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;font-size:.88rem">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th style="padding:.65rem 1rem;text-align:left;font-weight:700;color:var(--muted);font-size:.76rem;text-transform:uppercase">Email</th>
        <th style="padding:.65rem .75rem;text-align:left;font-weight:700;color:var(--muted);font-size:.76rem;text-transform:uppercase">Σχολή</th>
        <th style="padding:.65rem .75rem;text-align:left;font-weight:700;color:var(--muted);font-size:.76rem;text-transform:uppercase">Αθλητές</th>
        <th style="padding:.65rem .75rem;text-align:left;font-weight:700;color:var(--muted);font-size:.76rem;text-transform:uppercase">Τελ. Σύνδεση</th>
        <th style="padding:.65rem .75rem;text-align:left;font-weight:700;color:var(--muted);font-size:.76rem;text-transform:uppercase">Κατάσταση</th>
        <th style="padding:.65rem .75rem;text-align:center;font-weight:700;color:var(--muted);font-size:.76rem;text-transform:uppercase">Ενέργειες</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($parents as $p):
        $isActive    = (bool)$p['active'];
        $neverLogged = empty($p['last_login']);
        $statusColor = $isActive ? '#2dc653' : '#f0a500';
        $statusLabel = $isActive ? 'Ενεργός' : 'Παυσαρισμένος';
        $pJson       = htmlspecialchars(json_encode([
            'id'            => $p['id'],
            'parent_email'  => $p['parent_email'],
            'school_name'   => $p['school_name'],
            'athlete_names' => $p['athlete_names'] ?? '—',
            'athlete_count' => (int)$p['athlete_count'],
            'last_login'    => $p['last_login'],
            'created_at'    => $p['created_at'],
            'active'        => $p['active'],
            'first_login'   => $p['first_login'],
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
    ?>
    <tr style="border-bottom:1px solid rgba(255,255,255,.04);transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.03)'" onmouseout="this.style.background=''">
      <td style="padding:.7rem 1rem">
        <div style="font-weight:700;color:var(--text)"><?= h($p['parent_email']) ?></div>
        <?php if ($p['first_login']): ?>
        <span style="font-size:.71rem;color:#f0a500"><i class="fa-solid fa-clock"></i> Δεν συνδέθηκε ποτέ</span>
        <?php endif; ?>
      </td>
      <td style="padding:.7rem .75rem;color:var(--muted)"><?= h($p['school_name']) ?></td>
      <td style="padding:.7rem .75rem">
        <span style="background:rgba(88,166,255,.1);color:#58a6ff;border:1px solid rgba(88,166,255,.25);border-radius:20px;padding:.1rem .55rem;font-size:.75rem;font-weight:700">
          <?= (int)$p['athlete_count'] ?> αθλητ<?= (int)$p['athlete_count'] === 1 ? 'ής' : 'ές' ?>
        </span>
        <?php if ($p['athlete_names']): ?>
        <div style="font-size:.75rem;color:var(--muted);margin-top:.2rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($p['athlete_names']) ?>">
          <?= h($p['athlete_names']) ?>
        </div>
        <?php endif; ?>
      </td>
      <td style="padding:.7rem .75rem;color:var(--muted);font-size:.82rem">
        <?= $p['last_login'] ? date('d/m/Y H:i', strtotime($p['last_login'])) : '—' ?>
      </td>
      <td style="padding:.7rem .75rem">
        <span style="background:<?= h($statusColor) ?>18;color:<?= h($statusColor) ?>;border:1px solid <?= h($statusColor) ?>44;border-radius:20px;padding:.15rem .6rem;font-size:.75rem;font-weight:700">
          <?= h($statusLabel) ?>
        </span>
      </td>
      <td style="padding:.7rem .75rem;text-align:center">
        <div style="display:flex;gap:.4rem;justify-content:center;flex-wrap:wrap">
          <!-- Preview -->
          <button type="button" onclick="openPreview(<?= $pJson ?>)"
            style="background:rgba(88,166,255,.1);color:#58a6ff;border:1px solid rgba(88,166,255,.25);border-radius:7px;padding:.3rem .6rem;cursor:pointer;font-size:.75rem;font-weight:700;min-height:30px"
            title="Προβολή">
            <i class="fa-solid fa-eye"></i>
          </button>
          <!-- Edit -->
          <button type="button" onclick="openEdit(<?= $pJson ?>)"
            style="background:rgba(240,165,0,.1);color:#f0a500;border:1px solid rgba(240,165,0,.25);border-radius:7px;padding:.3rem .6rem;cursor:pointer;font-size:.75rem;font-weight:700;min-height:30px"
            title="Επεξεργασία">
            <i class="fa-solid fa-pen"></i>
          </button>
          <!-- Reset password -->
          <form method="post" style="display:inline" onsubmit="return confirm('Θα σταλεί νέος κωδικός στο <?= h($p['parent_email']) ?>. Συνέχεια;')">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="reset_parent_password">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit"
              style="background:rgba(45,198,83,.1);color:#2dc653;border:1px solid rgba(45,198,83,.25);border-radius:7px;padding:.3rem .6rem;cursor:pointer;font-size:.75rem;font-weight:700;min-height:30px"
              title="Νέος κωδικός & αποστολή email">
              <i class="fa-solid fa-paper-plane"></i>
            </button>
          </form>
          <!-- Pause/Unpause -->
          <form method="post" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="toggle_parent">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit"
              style="background:rgba(<?= $isActive ? '240,165,0' : '45,198,83' ?>,.1);color:<?= $isActive ? '#f0a500' : '#2dc653' ?>;border:1px solid rgba(<?= $isActive ? '240,165,0' : '45,198,83' ?>,.25);border-radius:7px;padding:.3rem .6rem;cursor:pointer;font-size:.75rem;font-weight:700;min-height:30px"
              title="<?= $isActive ? 'Παύση' : 'Ενεργοποίηση' ?>">
              <i class="fa-solid fa-<?= $isActive ? 'pause' : 'play' ?>"></i>
            </button>
          </form>
          <!-- Impersonate -->
          <form method="post" style="display:inline" onsubmit="return confirm('Θα συνδεθείτε ως γονέας. Μπορείτε να βγείτε με το κουμπί εξόδου.')">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="impersonate_parent">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit"
              style="background:rgba(148,103,189,.1);color:#9467bd;border:1px solid rgba(148,103,189,.25);border-radius:7px;padding:.3rem .6rem;cursor:pointer;font-size:.75rem;font-weight:700;min-height:30px"
              title="Impersonate — σύνδεση ως γονέας">
              <i class="fa-solid fa-user-secret"></i>
            </button>
          </form>
          <!-- Generate credentials (show on screen) -->
          <form method="post" style="display:inline" onsubmit="return confirm('Νέος κωδικός για <?= h(addslashes($p['parent_email'])) ?> — θα εμφανιστεί στην οθόνη. Συνέχεια;')">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="generate_credentials">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit"
              style="background:rgba(240,165,0,.1);color:#f0a500;border:1px solid rgba(240,165,0,.3);border-radius:7px;padding:.3rem .6rem;cursor:pointer;font-size:.75rem;font-weight:700;min-height:30px"
              title="Δημιουργία κωδικού — εμφάνιση στην οθόνη">
              <i class="fa-solid fa-key"></i>
            </button>
          </form>
          <!-- Delete -->
          <form method="post" style="display:inline" onsubmit="return confirm('ΔΙΑΓΡΑΦΗ λογαριασμού <?= h(addslashes($p['parent_email'])) ?>; Η ενέργεια είναι μη αναστρέψιμη.')">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="delete_parent">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit"
              style="background:rgba(230,57,70,.1);color:#e63946;border:1px solid rgba(230,57,70,.25);border-radius:7px;padding:.3rem .6rem;cursor:pointer;font-size:.75rem;font-weight:700;min-height:30px"
              title="Διαγραφή">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="display:flex;justify-content:center;gap:.4rem;padding:1rem;flex-wrap:wrap">
    <?php for ($i = 1; $i <= $totalPages; $i++):
        $qs = http_build_query(array_merge($_GET, ['page' => $i]));
        $isCurrent = ($i === $page);
    ?>
    <a href="?<?= $qs ?>"
       style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;font-weight:700;font-size:.85rem;text-decoration:none;
              <?= $isCurrent ? 'background:var(--red);color:#fff' : 'background:rgba(255,255,255,.06);color:var(--muted)' ?>">
      <?= $i ?>
    </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

</div><!-- .page-body -->
</div><!-- .main-content -->
</div><!-- .app-layout -->

<!-- ── Preview Modal ───────────────────────────────────────────────────────── -->
<div id="previewModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:1rem">
  <div style="background:#111520;border:1px solid rgba(255,255,255,.1);border-radius:18px;max-width:480px;width:100%;padding:1.5rem;box-shadow:0 24px 60px rgba(0,0,0,.6);max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
      <div style="font-weight:800;font-size:1.1rem"><i class="fa-solid fa-user-shield" style="color:#58a6ff;margin-right:.5rem"></i>Στοιχεία Γονέα</div>
      <button onclick="closePreview()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.2rem;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px">✕</button>
    </div>
    <table style="width:100%;font-size:.88rem;border-collapse:collapse" id="previewTable">
    </table>
  </div>
</div>

<!-- ── Edit Modal ──────────────────────────────────────────────────────────── -->
<div id="editModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:1rem">
  <div style="background:#111520;border:1px solid rgba(255,255,255,.1);border-radius:18px;max-width:420px;width:100%;padding:1.5rem;box-shadow:0 24px 60px rgba(0,0,0,.6)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
      <div style="font-weight:800;font-size:1.1rem"><i class="fa-solid fa-pen" style="color:#f0a500;margin-right:.5rem"></i>Επεξεργασία Email</div>
      <button onclick="closeEdit()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.2rem;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px">✕</button>
    </div>
    <form method="post" id="editForm">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="edit_parent">
      <input type="hidden" name="id" id="editId">
      <div style="margin-bottom:1.1rem">
        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:.4rem">Email Γονέα</label>
        <input type="email" name="parent_email" id="editEmail" class="form-control" required>
      </div>
      <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary" style="flex:1">Αποθήκευση</button>
        <button type="button" onclick="closeEdit()" class="btn btn-ghost" style="flex:1">Ακύρωση</button>
      </div>
    </form>
  </div>
</div>

<script>
function openPreview(data) {
    var rows = [
        ['Email', data.parent_email],
        ['Σχολή', data.school_name],
        ['Αθλητές (' + data.athlete_count + ')', data.athlete_names || '—'],
        ['Τελευταία Σύνδεση', data.last_login || 'Ποτέ'],
        ['Δημιουργία', data.created_at || '—'],
        ['Κατάσταση', data.active == 1 ? 'Ενεργός' : 'Παυσαρισμένος'],
        ['Πρώτη Σύνδεση', data.first_login == 1 ? 'Δεν έχει συνδεθεί ακόμα' : 'Έχει συνδεθεί'],
    ];
    var html = '';
    rows.forEach(function(r) {
        html += '<tr><td style="padding:.45rem .5rem .45rem 0;color:var(--muted);width:40%;vertical-align:top;font-size:.82rem">' + escH(r[0]) + '</td>';
        html += '<td style="padding:.45rem 0;font-weight:600;font-size:.85rem">' + escH(String(r[1])) + '</td></tr>';
    });
    document.getElementById('previewTable').innerHTML = html;
    document.getElementById('previewModal').style.display = 'flex';
}
function closePreview() { document.getElementById('previewModal').style.display = 'none'; }

function openEdit(data) {
    document.getElementById('editId').value    = data.id;
    document.getElementById('editEmail').value = data.parent_email;
    document.getElementById('editModal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('editEmail').focus(); }, 80);
}
function closeEdit() { document.getElementById('editModal').style.display = 'none'; }

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function copyText(elemId) {
    var el = document.getElementById(elemId);
    if (!el) return;
    var text = el.innerText || el.textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            var btn = el.nextElementSibling;
            if (btn) { btn.innerHTML = '<i class="fa-solid fa-check"></i>'; setTimeout(function(){ btn.innerHTML = '<i class="fa-solid fa-copy"></i>'; }, 1500); }
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
    }
}

// Close modals on backdrop click
['previewModal','editModal'].forEach(function(id){
    document.getElementById(id).addEventListener('click', function(e){
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

</body>
</html>
