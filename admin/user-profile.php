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
 * admin/user-profile.php — Προφίλ Χρήστη (Super Admin View)
 * ============================================================
 * PURPOSE:
 *   Λεπτομερές προφίλ χρήστη με στατιστικά, audit log,
 *   ιστορικό συνδέσεων, στατιστικά σχολής και διαχείριση.
 *
 * ACCESS:
 *   Μόνο superadmin
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ Prepared statements
 *   ✓ h() output escaping
 *   ✓ verifyCsrf() για POST actions
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) {
    redirect(APP_URL . '/admin/users.php');
}

// ── Βασικά στοιχεία χρήστη ─────────────────────────────────────────────────
$user = $db->prepare("
    SELECT u.*, s.name as school_name, s.email as school_email,
           s.plan_status, s.plan_expires, s.city as school_city,
           s.active as school_active,
           p.name as plan_name, p.slug as plan_slug
    FROM users u
    LEFT JOIN schools s ON s.id = u.school_id
    LEFT JOIN plans p ON p.id = s.plan_id
    WHERE u.id = ?
");
$user->execute([$userId]);
$u = $user->fetch();

if (!$u) {
    redirect(APP_URL . '/admin/users.php');
}

// ── POST: Γρήγορες ενέργειες ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'toggle_active' && $u['role'] !== 'superadmin') {
        $db->prepare("UPDATE users SET active = NOT active WHERE id = ?")->execute([$userId]);
        auditLog('admin_toggle_user', 'user', $userId, 'Admin toggled active status');
        flash($u['active'] ? 'Χρήστης απενεργοποιήθηκε.' : 'Χρήστης ενεργοποιήθηκε.');
    }

    if ($action === 'reset_password' && $u['role'] !== 'superadmin') {
        $pwd = trim($_POST['new_password'] ?? '');
        if (strlen($pwd) >= 6) {
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($pwd, PASSWORD_DEFAULT), $userId]);
            auditLog('admin_password_reset', 'user', $userId, 'Admin reset password from profile');
            flash('✅ Κωδικός αλλάχτηκε.');
        } else {
            flash('Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.', 'error');
        }
    }

    if ($action === 'add_note') {
        $note = trim($_POST['admin_note'] ?? '');
        try {
            $db->prepare("UPDATE users SET admin_note = ? WHERE id = ?")->execute([$note, $userId]);
            flash('✅ Σημείωση αποθηκεύτηκε.');
        } catch (Exception $e) {
            // Column may not exist — silent
            flash('Η σημείωση δεν μπόρεσε να αποθηκευτεί.', 'error');
        }
    }

    redirect(APP_URL . '/admin/user-profile.php?id=' . $userId);
}

// Re-fetch after possible update
$user->execute([$userId]);
$u = $user->fetch();

// ── Στατιστικά χρήστη ──────────────────────────────────────────────────────
$auditCount = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id = ?");
$auditCount->execute([$userId]);
$totalActions = (int)$auditCount->fetchColumn();

$loginCount = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id = ? AND action = 'login'");
$loginCount->execute([$userId]);
$loginCount = (int)$loginCount->fetchColumn();

$lastLogin = $db->prepare("SELECT MAX(created_at) FROM audit_log WHERE user_id = ? AND action = 'login'");
$lastLogin->execute([$userId]);
$lastLogin = $lastLogin->fetchColumn();

$firstLogin = $db->prepare("SELECT MIN(created_at) FROM audit_log WHERE user_id = ? AND action = 'login'");
$firstLogin->execute([$userId]);
$firstLogin = $firstLogin->fetchColumn();

// Πιο συχνές ενέργειες
$topActions = $db->prepare("
    SELECT action, COUNT(*) as cnt
    FROM audit_log
    WHERE user_id = ?
    GROUP BY action
    ORDER BY cnt DESC
    LIMIT 8
");
$topActions->execute([$userId]);
$topActions = $topActions->fetchAll();

// Τελευταίες 20 ενέργειες
$recentAudit = $db->prepare("
    SELECT action, entity_type, entity_id, details, ip, created_at
    FROM audit_log
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 20
");
$recentAudit->execute([$userId]);
$recentAudit = $recentAudit->fetchAll();

// Ενέργειες ανά μήνα (τελευταίοι 6 μήνες)
$monthlyActivity = [];
for ($i = 5; $i >= 0; $i--) {
    $m   = date('Y-m', strtotime("-$i months"));
    $s   = "$m-01";
    $e   = date('Y-m-t', strtotime($s));
    $stmt = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id = ? AND created_at BETWEEN ? AND ?");
    $stmt->execute([$userId, $s, "$e 23:59:59"]);
    $monthlyActivity[] = ['month' => date('M', strtotime($s)), 'count' => (int)$stmt->fetchColumn()];
}

// ── Στατιστικά σχολής (αν έχει) ────────────────────────────────────────────
$schoolStats = null;
if ($u['school_id']) {
    $sid = (int)$u['school_id'];
    $stAt = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id=? AND active=1"); $stAt->execute([$sid]); $athletes = $stAt->fetchColumn();
    $stAA = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id=?"); $stAA->execute([$sid]); $allAthletes = $stAA->fetchColumn();
    $stPS = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE school_id=? AND status='paid'"); $stPS->execute([$sid]); $paidSubs = $stPS->fetchColumn();
    $stOS = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE school_id=? AND status='overdue'"); $stOS->execute([$sid]); $overdueSubs = $stOS->fetchColumn();
    $stTR = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE school_id=?"); $stTR->execute([$sid]); $totalRevenue = $stTR->fetchColumn();
    $stSU = $db->prepare("SELECT COUNT(*) FROM users WHERE school_id=?"); $stSU->execute([$sid]); $schoolUsers = $stSU->fetchColumn();
    $stDp = $db->prepare("SELECT COUNT(*) FROM departments WHERE school_id=? AND active=1"); $stDp->execute([$sid]); $departments = $stDp->fetchColumn();
    $stEM = $db->prepare("SELECT COUNT(*) FROM reminder_logs WHERE school_id=? AND type='email' AND status='sent'"); $stEM->execute([$sid]); $emailSent = $stEM->fetchColumn();
    $stSM = $db->prepare("SELECT COUNT(*) FROM reminder_logs WHERE school_id=? AND type='sms' AND status='sent'"); $stSM->execute([$sid]); $smsSent = $stSM->fetchColumn();

    $schoolStats = compact('athletes','allAthletes','paidSubs','overdueSubs','totalRevenue','schoolUsers','departments','emailSent','smsSent');
}

// ── Άλλοι χρήστες ίδιας σχολής ─────────────────────────────────────────────
$schoolmates = [];
if ($u['school_id']) {
    $sm = $db->prepare("SELECT id, name, email, role, active, last_login FROM users WHERE school_id = ? AND id != ? ORDER BY role, name");
    $sm->execute([$u['school_id'], $userId]);
    $schoolmates = $sm->fetchAll();
}

// Role labels
$roleLabels = ['superadmin' => 'Super Admin', 'owner' => 'Ιδιοκτήτης', 'admin' => 'Διαχειριστής', 'coach' => 'Προπονητής', 'secretary' => 'Γραμματεία'];

renderHead('Προφίλ Χρήστη — ' . h($u['name']));
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { font-size: 15px; }
.page-body { padding: 1.75rem !important; }
.card { border-radius: 14px !important; }
.card-title { font-size: 1rem !important; font-weight: 700 !important; }
table { font-size: .9rem !important; }
thead th { font-size: .75rem !important; padding: .7rem 1rem !important; letter-spacing: .07em; }
tbody td { padding: .8rem 1rem !important; font-size: .88rem !important; }
.fw-600 { font-size: .92rem !important; }
.text-xs { font-size: .78rem !important; }
.text-sm { font-size: .85rem !important; }
.stat-card { border-radius: 14px !important; padding: 1.35rem !important; }
.stat-card .stat-val { font-size: 2.1rem !important; font-weight: 800 !important; }
.stat-card .stat-lbl { font-size: .82rem !important; }
.stat-card .stat-icon { width: 46px !important; height: 46px !important; font-size: 1.3rem !important; border-radius: 12px !important; }
.badge { font-size: .72rem !important; padding: .22rem .6rem !important; border-radius: 50px !important; font-weight: 700 !important; }
.btn { font-size: .875rem !important; padding: .5rem 1.05rem !important; border-radius: 9px !important; font-weight: 500 !important; }
.btn-sm { font-size: .8rem !important; padding: .32rem .65rem !important; }
.form-label { font-size: .82rem !important; font-weight: 600 !important; color: var(--muted); }
.form-control { font-size: .88rem !important; padding: .58rem .8rem !important; border-radius: 9px !important; }
.text-muted { color: var(--muted) !important; }
.text-green { color: var(--green) !important; }
.text-red { color: var(--red) !important; }
h2 { font-size: 1.2rem !important; font-weight: 700 !important; }

/* Profile hero */
.profile-hero {
    background: linear-gradient(135deg, rgba(230,57,70,.08) 0%, rgba(230,57,70,.02) 100%);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 1.75rem;
    display: flex;
    gap: 1.5rem;
    align-items: flex-start;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}
.profile-avatar-lg {
    width: 80px; height: 80px;
    background: linear-gradient(135deg, #e63946, #c1121f);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; font-weight: 800; color: #fff;
    flex-shrink: 0;
}
.profile-meta { flex: 1; min-width: 200px; }
.profile-name { font-size: 1.5rem; font-weight: 800; line-height: 1.2; margin-bottom: .3rem; }
.profile-email { font-size: .9rem; color: var(--muted); margin-bottom: .5rem; }
.profile-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: .75rem; }
.info-item { background: var(--bg2); border-radius: 10px; padding: .75rem 1rem; }
.info-item .label { font-size: .72rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: .2rem; }
.info-item .value { font-size: .92rem; font-weight: 600; }
.activity-bar { display: flex; align-items: flex-end; gap: 4px; height: 48px; }
.activity-bar-item { flex: 1; background: rgba(230,57,70,.6); border-radius: 3px 3px 0 0; min-width: 8px; position: relative; }
.activity-bar-item:hover::after { content: attr(data-tip); position: absolute; bottom: 110%; left: 50%; transform: translateX(-50%); background: #111; color: #fff; font-size: .72rem; padding: .2rem .5rem; border-radius: 5px; white-space: nowrap; z-index: 10; }
.action-chip { display: inline-flex; align-items: center; gap: .3rem; background: var(--bg2); border-radius: 20px; padding: .3rem .7rem; font-size: .78rem; font-weight: 600; margin: .15rem; }
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:9998; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal-box { background:var(--card,#111520); border:1px solid var(--border,#1e2536); border-radius:16px; padding:1.5rem; min-width:300px; max-width:440px; width:100%; }
.modal-title { font-size:1rem; font-weight:700; margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }

@media(max-width:768px){
    .page-body { padding: 1rem !important; }
    .profile-hero { padding: 1.25rem; gap: 1rem; }
    .profile-name { font-size: 1.2rem !important; }
}
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_users'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-user-circle"></i> Προφίλ Χρήστη'); ?>
<div class="page-body">

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?> mb-3"><?= $flash['msg'] ?></div>
<?php endif; ?>

<!-- Breadcrumb -->
<div class="d-flex ai-center gap-sm mb-3">
    <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-secondary btn-sm">
        <i class="fa-solid fa-arrow-left"></i> Πίσω στους Χρήστες
    </a>
    <?php if ($u['school_id']): ?>
    <a href="<?= APP_URL ?>/admin/schools.php?edit=<?= $u['school_id'] ?>" class="btn btn-ghost btn-sm">
        <i class="fa-solid fa-school"></i> Σχολή: <?= h($u['school_name']) ?>
    </a>
    <?php endif; ?>
</div>

<!-- Profile Hero -->
<div class="profile-hero">
    <div class="profile-avatar-lg">
        <?= strtoupper(substr($u['name'], 0, 1)) ?>
    </div>
    <div class="profile-meta">
        <div class="profile-name"><?= h($u['name']) ?></div>
        <div class="profile-email"><i class="fa-solid fa-envelope"></i> <?= h($u['email']) ?></div>
        <div class="d-flex gap-sm flex-wrap ai-center">
            <span class="badge <?= $u['role'] === 'superadmin' ? 'badge-superadmin' : ($u['role'] === 'owner' ? 'badge-pro' : 'badge-basic') ?>">
                <i class="fa-solid fa-shield-halved"></i> <?= $roleLabels[$u['role']] ?? $u['role'] ?>
            </span>
            <span class="badge <?= $u['active'] ? 'badge-active' : 'badge-inactive' ?>">
                <?= $u['active'] ? '● Ενεργός' : '○ Ανενεργός' ?>
            </span>
            <?php if ($u['school_name']): ?>
            <span class="badge badge-basic"><i class="fa-solid fa-school"></i> <?= h($u['school_name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="profile-actions">
            <a href="<?= APP_URL ?>/admin/users.php?edit=<?= $u['id'] ?>" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-pen-to-square"></i> Επεξεργασία
            </a>
            <?php if ($u['role'] !== 'superadmin'): ?>
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('modalResetPwd').classList.add('open')">
                <i class="fa-solid fa-key"></i> Reset Κωδικού
            </button>
            <form method="POST" style="display:inline">
                <input type="hidden" name="_action" value="toggle_active">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <button type="submit" class="btn <?= $u['active'] ? 'btn-ghost' : 'btn-secondary' ?> btn-sm">
                    <i class="fa-solid fa-<?= $u['active'] ? 'ban' : 'check' ?>"></i>
                    <?= $u['active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?>
                </button>
            </form>
            <?php if ($u['school_id']): ?>
            <a href="<?= APP_URL ?>/admin/schools.php?impersonate=<?= $u['school_id'] ?>" class="btn btn-ghost btn-sm">
                <i class="fa-solid fa-user-secret"></i> Impersonate
            </a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick info grid -->
    <div style="flex-basis:100%;">
        <div class="info-grid">
            <div class="info-item">
                <div class="label"><i class="fa-solid fa-calendar-plus"></i> Εγγραφή</div>
                <div class="value"><?= $u['created_at'] ? date('d/m/Y', strtotime($u['created_at'])) : '—' ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="fa-solid fa-clock"></i> Τελευταία Σύνδεση</div>
                <div class="value"><?= $lastLogin ? date('d/m/Y H:i', strtotime($lastLogin)) : '—' ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="fa-solid fa-right-to-bracket"></i> Συνδέσεις</div>
                <div class="value"><?= number_format($loginCount) ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="fa-solid fa-list-check"></i> Σύνολο Ενεργειών</div>
                <div class="value"><?= number_format($totalActions) ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="fa-solid fa-id-card"></i> User ID</div>
                <div class="value">#<?= $u['id'] ?></div>
            </div>
            <?php if ($firstLogin): ?>
            <div class="info-item">
                <div class="label"><i class="fa-solid fa-flag"></i> Πρώτη Σύνδεση</div>
                <div class="value"><?= date('d/m/Y', strtotime($firstLogin)) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── KPI Stats Cards ──────────────────────────────────────────────────── -->
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-right-to-bracket"></i></div>
        <div class="stat-val"><?= $loginCount ?></div>
        <div class="stat-lbl">Συνδέσεις</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-list-check"></i></div>
        <div class="stat-val"><?= number_format($totalActions) ?></div>
        <div class="stat-lbl">Σύνολο Ενεργειών</div>
    </div>
    <?php if ($schoolStats): ?>
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-person-running"></i></div>
        <div class="stat-val"><?= $schoolStats['athletes'] ?></div>
        <div class="stat-lbl">Ενεργοί Αθλητές</div>
        <div class="stat-sub">Σχολή</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-euro-sign"></i></div>
        <div class="stat-val text-green"><?= formatMoney($schoolStats['totalRevenue']) ?></div>
        <div class="stat-lbl">Έσοδα Σχολής</div>
    </div>
    <?php else: ?>
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="stat-val">SA</div>
        <div class="stat-lbl">Super Admin</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-star"></i></div>
        <div class="stat-val">∞</div>
        <div class="stat-lbl">Πλήρης Πρόσβαση</div>
    </div>
    <?php endif; ?>
</div>

<div class="grid grid-2 mb-3">

    <!-- Activity chart -->
    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-chart-bar"></i> Δραστηριότητα (6 Μήνες)</div>
        <canvas id="activityChart" height="160"></canvas>
    </div>

    <!-- Top actions -->
    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-bolt"></i> Συχνότερες Ενέργειες</div>
        <?php if (empty($topActions)): ?>
        <p class="text-muted text-sm">Δεν βρέθηκαν ενέργειες.</p>
        <?php else: ?>
        <?php $maxCnt = max(array_column($topActions, 'cnt')); ?>
        <?php foreach ($topActions as $ta): ?>
        <div class="mb-2">
            <div class="d-flex jc-between text-sm mb-1">
                <span class="action-chip"><i class="fa-solid fa-circle-dot" style="color:#e63946;font-size:.6rem"></i> <?= h($ta['action']) ?></span>
                <span class="fw-600"><?= $ta['cnt'] ?></span>
            </div>
            <div class="progress"><div class="progress-bar" style="width:<?= $maxCnt > 0 ? round($ta['cnt']/$maxCnt*100) : 0 ?>%"></div></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── School Stats (αν ανήκει σε σχολή) ──────────────────────────────── -->
<?php if ($schoolStats): ?>
<div class="card mb-3">
    <div class="card-title mb-3"><i class="fa-solid fa-school"></i> Στατιστικά Σχολής — <?= h($u['school_name']) ?></div>
    <div class="grid grid-4" style="gap:.75rem;">
        <div class="info-item">
            <div class="label">Ενεργοί Αθλητές</div>
            <div class="value text-green"><?= $schoolStats['athletes'] ?> / <?= $schoolStats['allAthletes'] ?></div>
        </div>
        <div class="info-item">
            <div class="label">Πληρωμένες Συνδρομές</div>
            <div class="value text-green"><?= $schoolStats['paidSubs'] ?></div>
        </div>
        <div class="info-item">
            <div class="label">Ληξιπρόθεσμες</div>
            <div class="value <?= $schoolStats['overdueSubs'] > 0 ? 'text-red' : '' ?>"><?= $schoolStats['overdueSubs'] ?></div>
        </div>
        <div class="info-item">
            <div class="label">Χρήστες Σχολής</div>
            <div class="value"><?= $schoolStats['schoolUsers'] ?></div>
        </div>
        <div class="info-item">
            <div class="label">Τμήματα</div>
            <div class="value"><?= $schoolStats['departments'] ?></div>
        </div>
        <div class="info-item">
            <div class="label">Email Απεσταλμένα</div>
            <div class="value"><?= $schoolStats['emailSent'] ?></div>
        </div>
        <div class="info-item">
            <div class="label">SMS Απεσταλμένα</div>
            <div class="value"><?= $schoolStats['smsSent'] ?></div>
        </div>
        <div class="info-item">
            <div class="label">Πλάνο</div>
            <div class="value"><?= planBadge($u['plan_slug'] ?? 'basic') ?> <?= h($u['plan_name'] ?? '—') ?></div>
        </div>
    </div>
    <?php if ($u['plan_expires']): ?>
    <div class="mt-2 text-sm text-muted">
        <i class="fa-solid fa-calendar-xmark"></i> Λήξη πλάνου: <strong><?= formatDate($u['plan_expires']) ?></strong>
        <?php
        $daysLeft = (!empty($u['plan_expires']) && strtotime($u['plan_expires']) !== false) ? max(0, (int)floor((strtotime($u['plan_expires']) - time()) / 86400)) : 0;
        $cls = $daysLeft <= 7 ? 'text-red' : ($daysLeft <= 30 ? 'text-orange' : 'text-green');
        ?>
        <span class="<?= $cls ?>">(<?= $daysLeft ?> ημέρες)</span>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Συνάδελφοι ίδιας σχολής ────────────────────────────────────────── -->
<?php if (!empty($schoolmates)): ?>
<div class="card p-0 mb-3">
    <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title"><i class="fa-solid fa-users"></i> Άλλοι Χρήστες Σχολής</div>
        <span class="badge badge-basic"><?= count($schoolmates) ?></span>
    </div>
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-schoolmates" type="text" placeholder="Αναζήτηση χρήστη..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-schoolmates">
        <thead><tr><th>Χρήστης</th><th>Ρόλος</th><th>Κατάσταση</th><th>Τελ. Σύνδεση</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($schoolmates as $sm): ?>
        <tr>
            <td>
                <div class="fw-600"><?= h($sm['name']) ?></div>
                <div class="text-xs text-muted"><?= h($sm['email']) ?></div>
            </td>
            <td><span class="badge <?= $sm['role'] === 'owner' ? 'badge-pro' : 'badge-basic' ?>"><?= $roleLabels[$sm['role']] ?? $sm['role'] ?></span></td>
            <td><span class="badge <?= $sm['active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $sm['active'] ? 'Ενεργός' : 'Ανενεργός' ?></span></td>
            <td class="text-sm"><?= $sm['last_login'] ? date('d/m/Y', strtotime($sm['last_login'])) : '—' ?></td>
            <td><a href="?id=<?= $sm['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div id="pg-schoolmates" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
</div>
<?php endif; ?>

<!-- ── Admin Notes ─────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-title mb-2"><i class="fa-solid fa-note-sticky"></i> Admin Σημείωση</div>
    <form method="POST">
        <input type="hidden" name="_action" value="add_note">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <textarea name="admin_note" class="form-control mb-2" rows="3" placeholder="Σημείωση για αυτόν τον χρήστη (ορατή μόνο από admins)..."><?= h($u['admin_note'] ?? '') ?></textarea>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
    </form>
</div>

<!-- ── Recent Audit Log ────────────────────────────────────────────────── -->
<div class="card p-0">
    <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title"><i class="fa-solid fa-clipboard-list"></i> Τελευταίες Ενέργειες</div>
        <a href="<?= APP_URL ?>/admin/audit.php?user_id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm">Όλες <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-audit-profile" type="text" placeholder="Αναζήτηση ενέργειας..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-audit">
        <thead><tr><th>Ενέργεια</th><th>Αντικείμενο</th><th>Λεπτομέρειες</th><th>IP</th><th>Ώρα</th></tr></thead>
        <tbody>
        <?php foreach ($recentAudit as $al): ?>
        <tr>
            <td><span class="action-chip"><i class="fa-solid fa-circle-dot" style="color:#e63946;font-size:.6rem"></i> <?= h($al['action']) ?></span></td>
            <td class="text-sm"><?= $al['entity_type'] ? h($al['entity_type']) . ' #' . $al['entity_id'] : '—' ?></td>
            <td class="text-sm text-muted" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($al['details'] ?? '') ?: '—' ?></td>
            <td class="text-xs text-muted"><?= h($al['ip'] ?? '') ?></td>
            <td class="text-xs text-muted"><?= $al['created_at'] ? date('d/m H:i', strtotime($al['created_at'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentAudit)): ?>
        <tr><td colspan="5" class="text-center text-muted" style="padding:2rem">Δεν βρέθηκαν ενέργειες</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
    <div id="pg-audit" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
</div>

</div></div></div>

<!-- MODAL: Reset Κωδικού -->
<div class="modal-backdrop" id="modalResetPwd">
    <div class="modal-box">
        <div class="modal-title"><i class="fa-solid fa-key" style="color:#f0a500"></i> Reset Κωδικού</div>
        <div class="text-muted text-sm mb-3"><?= h($u['name']) ?></div>
        <form method="POST">
            <input type="hidden" name="_action" value="reset_password">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="form-group mb-3">
                <label class="form-label">Νέος Κωδικός *</label>
                <input type="password" name="new_password" class="form-control" minlength="6" required placeholder="Τουλάχιστον 6 χαρακτήρες">
            </div>
            <div class="d-flex gap-sm">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Αλλαγή</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalResetPwd').classList.remove('open')">Ακύρωση</button>
            </div>
        </form>
    </div>
</div>

<script>
new Chart(document.getElementById('activityChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($monthlyActivity, 'month')) ?>,
        datasets: [{
            label: 'Ενέργειες',
            data: <?= json_encode(array_column($monthlyActivity, 'count')) ?>,
            backgroundColor: 'rgba(230,57,70,.7)',
            borderColor: 'rgba(230,57,70,1)',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: { color: '#7a849e' }, grid: { color: 'rgba(255,255,255,.04)' } },
            y: { ticks: { color: '#7a849e', stepSize: 1 }, grid: { color: 'rgba(255,255,255,.04)' } }
        }
    }
});
document.getElementById('modalResetPwd').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
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
initPagination('tbl-schoolmates', 'pg-schoolmates', 10, 'srch-schoolmates');
initPagination('tbl-audit', 'pg-audit', 10, 'srch-audit-profile');
</script>
</body></html>