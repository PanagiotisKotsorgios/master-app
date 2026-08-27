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
 * admin/notifications.php — Ιστορικό Ειδοποιήσεων (Super Admin)
 * ============================================================
 * PURPOSE:
 *   System-wide log email/SMS αποστολών.
 *   Manual resend ειδοποίησης.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf() σε POST resend
 *   ✓ resend: επαληθεύει ότι log record υπάρχει πριν αποστολή
 *   ✓ Prepared statements
 *   ✓ h() output
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── POST: Manual Resend ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'resend') {
    // ── Επαλήθευση CSRF token — αποτρέπει Cross-Site Request Forgery ──
    verifyCsrf();
    $logId = (int)($_POST['log_id'] ?? 0);
    $row = $db->prepare("SELECT r.*,a.full_name,a.email,a.phone,a.parent_email,a.parent_phone,s.name as school_name FROM reminder_logs r JOIN athletes a ON a.id=r.athlete_id JOIN schools s ON s.id=r.school_id WHERE r.id=?");
    $row->execute([$logId]);
    $log = $row->fetch();
    if ($log) {
        require_once __DIR__.'/../includes/mailer.php';
        $ok = false;
        $athleteName = $log['full_name'] ?? 'αθλητής';
        $schoolName  = $log['school_name'] ?? 'MAster';

        // Fetch latest subscription for amount/date
        $subFetch = $db->prepare("SELECT amount, valid_until FROM subscriptions WHERE athlete_id=? ORDER BY valid_until DESC LIMIT 1");
        $subFetch->execute([$log['athlete_id']]);
        $subRow = $subFetch->fetch() ?: ['amount' => 0, 'valid_until' => null];

        // Fetch athlete monthly_fee for debt calculation
        $athFetch = $db->prepare("SELECT monthly_fee, debt_from_month FROM athletes WHERE id=?");
        $athFetch->execute([$log['athlete_id']]);
        $athRow = $athFetch->fetch() ?: [];
        $monthlyFee = (float)($athRow['monthly_fee'] ?? 0);
        $amount = $monthlyFee ?: (float)($subRow['amount'] ?? 0);
        $amountFmt = number_format($amount, 2, ',', '.') . '€';

        if ($log['type'] === 'email') {
            $to = $log['recipient'];
            if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash('❌ Μη έγκυρη διεύθυνση email παραλήπτη.', 'error');
                redirect(APP_URL.'/admin/notifications.php');
            }
            $subject = "Υπενθύμιση Συνδρομής — {$schoolName}";
            $plainBody = "Αγαπητέ/ή κηδεμόνα,\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε τη συνδρομή του/της {$athleteName}." .
                ($amount > 0 ? "\nΠοσό: {$amountFmt}" : '') .
                "\n\nΌποτε σας εξυπηρετεί, επικοινωνήστε με τη σχολή σας για τη διευθέτησή της. Είμαστε στη διάθεσή σας.\n\nΕυχαριστούμε πολύ,\n{$schoolName}";
            $html = function_exists('buildEmailHtml')
                ? buildEmailHtml($plainBody, $schoolName, $subject)
                : nl2br(htmlspecialchars($plainBody, ENT_QUOTES, 'UTF-8'));
            $emailDbg = null;
            $ok = sendEmail($to, $subject, $html, $plainBody, $athleteName, $emailDbg, null, $schoolName);
        } elseif ($log['type'] === 'sms' && function_exists('sendSms')) {
            $to = $log['recipient'];
            $smsBody = "{$schoolName}: Υπενθύμιση συνδρομής {$athleteName}." .
                ($amount > 0 ? " Οφειλή: {$amountFmt}." : '') .
                " Παρακαλούμε επικοινωνήστε μαζί μας.";
            $smsErr = null;
            $ok = sendSms($to, $smsBody, $smsErr);
        }
        if ($ok) {
            $db->prepare("UPDATE reminder_logs SET status='sent', sent_at=NOW() WHERE id=?")->execute([$logId]);
            auditLog('manual_resend','reminder_log',$logId,"Type:{$log['type']} To:{$log['recipient']}");
            flash('✅ Ειδοποίηση εστάλη ξανά.');
        } else {
            flash('❌ Αποτυχία αποστολής. Έλεγξε τις ρυθμίσεις email/SMS.','error');
        }
    }
    redirect(APP_URL.'/admin/notifications.php');
}

// ── Στατιστικά Αποστολών (από όλο τον πίνακα, χωρίς φίλτρα) ───────────────
$stats = [
    'total' => $db->query("SELECT COUNT(*) FROM reminder_logs")->fetchColumn(),               // Σύνολο αποστολών
    'sent'  => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE status='sent'")->fetchColumn(), // Επιτυχείς
    'email' => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='email'")->fetchColumn(),  // Email
    'sms'   => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='sms'")->fetchColumn(),    // SMS
];

// ── Φίλτρα από GET παραμέτρους ─────────────────────────────────────────────
$search       = trim($_GET['q'] ?? '');
$filterType   = trim($_GET['type'] ?? '');           // 'email' ή 'sms'
$filterStatus = trim($_GET['status'] ?? '');          // 'sent', 'failed', 'pending'
$filterSchool = (int)($_GET['school_id'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;

$where  = ['1=1'];
$params = [];

if ($search) {
    // Αναζήτηση σε όνομα αθλητή, παραλήπτη (email/τηλ.) ή όνομα σχολής
    $where[]  = "(a.full_name LIKE ? OR r.recipient LIKE ? OR s.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterType) {
    $where[]  = "r.type = ?";
    $params[] = $filterType;
}
if ($filterStatus) {
    $where[]  = "r.status = ?";
    $params[] = $filterStatus;
}
if ($filterSchool) {
    $where[]  = "r.school_id = ?";
    $params[] = $filterSchool;
}

$whereStr = implode(' AND ', $where);

// ── Μέτρηση + Pagination ────────────────────────────────────────────────────
$countStmt = $db->prepare("SELECT COUNT(*) FROM reminder_logs r JOIN athletes a ON a.id=r.athlete_id JOIN schools s ON s.id=r.school_id WHERE $whereStr");
$countStmt->execute($params);
$totalRows  = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / max(1, $limit)));
$offset     = ($page - 1) * $limit;

// ── Κύριο query: logs με όνομα αθλητή και σχολής ───────────────────────────
$stmt = $db->prepare("
    SELECT r.*, a.full_name, s.name as school_name
    FROM reminder_logs r
    JOIN athletes a ON a.id=r.athlete_id
    JOIN schools s ON s.id=r.school_id
    WHERE $whereStr
    ORDER BY r.sent_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$schools = $db->query("SELECT id,name FROM schools ORDER BY name")->fetchAll();

renderHead('Αποστολές');
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
<?php renderSidebar('admin_notif'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-paper-plane"></i> Αποστολές Ειδοποιήσεων'); ?>
<div class="page-body">

<div class="grid grid-4 mb-3">
  <div class="stat-card">
    <div class="stat-icon icon-blue"><i class="fa-solid fa-paper-plane"></i></div>
    <div class="stat-val"><?= $stats['total'] ?></div>
    <div class="stat-lbl">Σύνολο</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
    <div class="stat-val"><?= $stats['sent'] ?></div>
    <div class="stat-lbl">Επιτυχείς</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-gold"><i class="fa-solid fa-envelope"></i></div>
    <div class="stat-val"><?= $stats['email'] ?></div>
    <div class="stat-lbl">Email</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon icon-purple"><i class="fa-solid fa-mobile-screen-button"></i></div>
    <div class="stat-val"><?= $stats['sms'] ?></div>
    <div class="stat-lbl">SMS</div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <form method="GET" class="d-flex gap-sm flex-wrap ai-end">
    <div class="form-group" style="margin-bottom:0;min-width:210px">
      <label class="form-label">Αναζήτηση</label>
      <div class="search-bar">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input name="q" value="<?= h($search) ?>" placeholder="Αθλητής, email, σχολή...">
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0;min-width:130px">
      <label class="form-label">Τύπος</label>
      <select name="type" class="form-control">
        <option value="">Όλοι</option>
        <option value="email" <?= $filterType === 'email' ? 'selected' : '' ?>>Email</option>
        <option value="sms"   <?= $filterType === 'sms'   ? 'selected' : '' ?>>SMS</option>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:0;min-width:130px">
      <label class="form-label">Κατάσταση</label>
      <select name="status" class="form-control">
        <option value="">Όλες</option>
        <option value="sent"    <?= $filterStatus === 'sent'    ? 'selected' : '' ?>>Επιτυχής</option>
        <option value="failed"  <?= $filterStatus === 'failed'  ? 'selected' : '' ?>>Αποτυχία</option>
        <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Εκκρεμής</option>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:0;min-width:160px">
      <label class="form-label">Σχολή</label>
      <select name="school_id" class="form-control">
        <option value="">Όλες</option>
        <?php foreach ($schools as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterSchool == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">
      <i class="fa-solid fa-filter"></i> Φίλτρο
    </button>
    <a href="<?= APP_URL ?>/admin/notifications.php" class="btn btn-ghost btn-sm">
      <i class="fa-solid fa-xmark"></i> Καθαρισμός
    </a>
  </form>
</div>

<div class="card p-0">
  <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border)" class="text-sm text-muted">
    <?= $totalRows ?> εγγραφές βρέθηκαν
  </div>
  <div class="table-wrap"><table>
    <thead>
      <tr>
        <th>Σχολή</th>
        <th>Αθλητής</th>
        <th>Τύπος</th>
        <th>Παραλήπτης</th>
        <th>Κατάσταση</th>
        <th>Ημ/νία</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= h($l['school_name']) ?></td>
        <td><?= h($l['full_name']) ?></td>
        <td>
          <?php if ($l['type'] === 'email'): ?>
            <span class="badge badge-basic"><i class="fa-solid fa-envelope"></i> Email</span>
          <?php else: ?>
            <span class="badge badge-basic"><i class="fa-solid fa-mobile-screen-button"></i> SMS</span>
          <?php endif; ?>
        </td>
        <td class="text-sm text-muted"><?= h($l['recipient'] ?? '—') ?></td>
        <td>
          <?php if ($l['status'] === 'sent'): ?>
            <span class="badge badge-paid"><i class="fa-solid fa-circle-check"></i> Επιτυχής</span>
          <?php elseif ($l['status'] === 'failed'): ?>
            <span class="badge badge-overdue"><i class="fa-solid fa-circle-xmark"></i> Αποτυχία</span>
          <?php else: ?>
            <span class="badge badge-pending"><i class="fa-solid fa-hourglass-half"></i> Εκκρεμής</span>
          <?php endif; ?>
        </td>
        <td class="text-xs text-muted"><?= date('d/m/Y H:i', strtotime($l['sent_at'])) ?></td>
        <td>
          <form method="POST" style="display:inline">
            <input type="hidden" name="_action" value="resend">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="log_id" value="<?= $l['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" title="Αποστολή ξανά" onclick="return confirm('Αποστολή ξανά στο <?= h(addslashes($l["recipient"] ?? "")) ?>;')">
              <i class="fa-solid fa-rotate-right" style="color:#3b82f6"></i>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($logs)): ?>
      <tr><td colspan="7" class="text-center text-muted" style="padding:2rem">Δεν βρέθηκαν εγγραφές</td></tr>
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