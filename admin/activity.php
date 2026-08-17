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
 * admin/activity.php — Live Activity Feed (Super Admin)
 * ============================================================
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── Filters ────────────────────────────────────────────────────────────────
$search       = trim($_GET['q'] ?? '');
$filterSchool = (int)($_GET['school_id'] ?? 0);
$filterAction = trim($_GET['action'] ?? '');
$filterUser   = (int)($_GET['user_id'] ?? 0);
$filterDate   = trim($_GET['date'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;

$where  = ['1=1'];
$params = [];

if ($search) { $where[] = "(al.action LIKE ? OR al.details LIKE ? OR u.name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filterSchool) { $where[] = "al.school_id = ?"; $params[] = $filterSchool; }
if ($filterAction) { $where[] = "al.action LIKE ?"; $params[] = "%$filterAction%"; }
if ($filterUser)   { $where[] = "al.user_id = ?";   $params[] = $filterUser; }
if ($filterDate)   { $where[] = "DATE(al.created_at) = ?"; $params[] = $filterDate; }

$whereStr = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_log al WHERE $whereStr");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / max(1, $limit)));
$offset     = ($page - 1) * $limit;

// Main query
$stmt = $db->prepare("
    SELECT al.*, u.name as user_name, u.role as user_role, s.name as school_name
    FROM audit_log al
    LEFT JOIN users u ON u.id = al.user_id
    LEFT JOIN schools s ON s.id = al.school_id
    WHERE $whereStr
    ORDER BY al.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Filter dropdowns
$schools = $db->query("SELECT id, name FROM schools ORDER BY name")->fetchAll();
$actions = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// ── Live Stats ─────────────────────────────────────────────────────────────
$activeNow    = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn();
$todayActions = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$todayLogins  = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND DATE(created_at)=CURDATE()")->fetchColumn();
$todayErrors  = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE '%error%' AND DATE(created_at)=CURDATE()")->fetchColumn();

// Unique schools active today
$activeSchoolsToday = (int)$db->query("SELECT COUNT(DISTINCT school_id) FROM audit_log WHERE DATE(created_at)=CURDATE() AND school_id IS NOT NULL")->fetchColumn();

// Top active schools today
$topSchoolsToday = $db->query("
    SELECT s.name, COUNT(*) as cnt
    FROM audit_log al
    JOIN schools s ON s.id = al.school_id
    WHERE DATE(al.created_at) = CURDATE()
    GROUP BY al.school_id
    ORDER BY cnt DESC LIMIT 5
")->fetchAll();

// Action icons map
$actionIcons = [
    'login'           => ['icon' => 'fa-right-to-bracket', 'color' => '#2a9d5c'],
    'logout'          => ['icon' => 'fa-right-from-bracket', 'color' => '#7a849e'],
    'athlete_create'  => ['icon' => 'fa-person-running', 'color' => '#4361ee'],
    'athlete_update'  => ['icon' => 'fa-pen-to-square', 'color' => '#4361ee'],
    'athlete_delete'  => ['icon' => 'fa-trash', 'color' => '#e63946'],
    'payment_create'  => ['icon' => 'fa-credit-card', 'color' => '#2a9d5c'],
    'payment_delete'  => ['icon' => 'fa-credit-card', 'color' => '#e63946'],
    'belt_create'     => ['icon' => 'fa-medal', 'color' => '#f0a500'],
    'sms_sent'        => ['icon' => 'fa-comment-sms', 'color' => '#7209b7'],
    'email_sent'      => ['icon' => 'fa-envelope', 'color' => '#4361ee'],
    'school_update'   => ['icon' => 'fa-gear', 'color' => '#7a849e'],
    'admin_password_reset' => ['icon' => 'fa-key', 'color' => '#f0a500'],
    'admin_lock_user' => ['icon' => 'fa-lock', 'color' => '#e63946'],
];

function getActionStyle(string $action, array $map): array {
    foreach ($map as $k => $v) {
        if (str_starts_with($action, $k) || $action === $k) return $v;
    }
    return ['icon' => 'fa-circle-dot', 'color' => '#7a849e'];
}

renderHead('Activity Feed');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { font-size: 15px; }
.page-body { padding: 1.75rem !important; }
.card { border-radius: 14px !important; }
.card-title { font-size: 1rem !important; font-weight: 700 !important; }
table { font-size: .9rem !important; }
thead th { font-size: .75rem !important; padding: .7rem 1rem !important; letter-spacing: .07em; }
tbody td { padding: .75rem 1rem !important; font-size: .88rem !important; }
.badge { font-size: .72rem !important; padding: .22rem .6rem !important; border-radius: 50px !important; font-weight: 700 !important; }
.btn { font-size: .875rem !important; padding: .5rem 1.05rem !important; border-radius: 9px !important; font-weight: 500 !important; }
.btn-sm { font-size: .8rem !important; padding: .32rem .65rem !important; }
.stat-card { border-radius: 14px !important; padding: 1.35rem !important; }
.stat-card .stat-val { font-size: 2.1rem !important; font-weight: 800 !important; }
.stat-card .stat-lbl { font-size: .82rem !important; }
.stat-card .stat-icon { width: 46px !important; height: 46px !important; font-size: 1.3rem !important; border-radius: 12px !important; }
.text-muted { color: var(--muted) !important; }
.text-green { color: var(--green) !important; }
.text-red { color: var(--red) !important; }
.form-control { font-size: .88rem !important; padding: .58rem .8rem !important; border-radius: 9px !important; }
.form-label { font-size: .82rem !important; font-weight: 600 !important; color: var(--muted); }
.page-btn { font-size: .82rem !important; padding: .38rem .68rem !important; }

/* Feed timeline */
.feed-item {
    display: flex; gap: 1rem; align-items: flex-start;
    padding: .75rem 1rem; border-bottom: 1px solid var(--border);
    transition: background .15s;
}
.feed-item:hover { background: var(--bg2); }
.feed-item:last-child { border-bottom: none; }
.feed-icon {
    width: 34px; height: 34px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; flex-shrink: 0; margin-top: .1rem;
}
.feed-body { flex: 1; }
.feed-action { font-size: .9rem; font-weight: 600; }
.feed-meta { font-size: .78rem; color: var(--muted); margin-top: .15rem; }
.feed-time { font-size: .75rem; color: var(--muted); white-space: nowrap; flex-shrink: 0; }
.online-dot { width: 8px; height: 8px; background: #2a9d5c; border-radius: 50%; display: inline-block; box-shadow: 0 0 6px rgba(42,157,92,.6); animation: pulse 2s infinite; margin-right: .3rem; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

@media(max-width:768px){ .page-body { padding: 1rem !important; } }
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_activity'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-wave-square"></i> Live Activity Feed'); ?>
<div class="page-body">

<!-- KPI cards -->
<div class="grid grid-5 mb-3">
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-circle-dot"></i></div>
        <div class="stat-val text-green"><?= $activeNow ?></div>
        <div class="stat-lbl">Ενεργοί (15 λεπτά)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-bolt"></i></div>
        <div class="stat-val"><?= $todayActions ?></div>
        <div class="stat-lbl">Ενέργειες Σήμερα</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-right-to-bracket"></i></div>
        <div class="stat-val"><?= $todayLogins ?></div>
        <div class="stat-lbl">Συνδέσεις Σήμερα</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-school"></i></div>
        <div class="stat-val"><?= $activeSchoolsToday ?></div>
        <div class="stat-lbl">Ενεργές Σχολές</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-red"><i class="fa-solid fa-circle-exclamation"></i></div>
        <div class="stat-val <?= $todayErrors > 0 ? 'text-red' : '' ?>"><?= $todayErrors ?></div>
        <div class="stat-lbl">Errors Σήμερα</div>
    </div>
</div>

<div class="grid grid-3 mb-3" style="grid-template-columns: 1fr 1fr 1fr;">
<div style="grid-column: span 2;">

<!-- Filters -->
<div class="card mb-3" style="padding:.875rem">
    <form method="GET" class="d-flex gap-sm flex-wrap ai-end">
        <div class="form-group" style="margin:0;min-width:210px">
            <label class="form-label">Αναζήτηση</label>
            <div class="search-bar"><span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span><input name="q" value="<?= h($search ?? '') ?>" placeholder="Ενέργεια, χρήστης, λεπτομέρειες..."></div>
        </div>
        <div class="form-group" style="margin:0;min-width:170px">
            <label class="form-label">Σχολή</label>
            <select name="school_id" class="form-control">
                <option value="">Όλες</option>
                <?php foreach ($schools as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filterSchool == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;min-width:150px">
            <label class="form-label">Ενέργεια</label>
            <select name="action" class="form-control">
                <option value="">Όλες</option>
                <?php foreach ($actions as $ac): ?>
                <option value="<?= h($ac) ?>" <?= $filterAction === $ac ? 'selected' : '' ?>><?= h($ac) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;min-width:140px">
            <label class="form-label">Ημερομηνία</label>
            <input type="date" name="date" value="<?= h($filterDate) ?>" class="form-control">
        </div>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i> Φίλτρο</button>
        <a href="<?= APP_URL ?>/admin/activity.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i></a>
    </form>
</div>

<!-- Feed -->
<div class="card p-0">
    <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem; border-bottom:1px solid var(--border)">
        <div class="card-title">
            <span class="online-dot"></span> Activity Feed
            <span class="badge badge-basic" style="margin-left:.4rem"><?= number_format($totalRows) ?> εγγραφές</span>
        </div>
        <span class="text-muted text-xs">Σελίδα <?= $page ?> / <?= max(1,$totalPages) ?></span>
    </div>

    <?php if (empty($logs)): ?>
    <div class="text-center text-muted" style="padding:3rem">
        <i class="fa-solid fa-inbox" style="font-size:2rem;margin-bottom:.75rem;display:block;opacity:.3"></i>
        Δεν βρέθηκαν εγγραφές για τα επιλεγμένα φίλτρα.
    </div>
    <?php else: ?>
    <?php foreach ($logs as $log):
        $style = getActionStyle($log['action'], $actionIcons);
        $timeAgo = $log['created_at'] ? date('d/m H:i', strtotime($log['created_at'])) : '—';
        $fullTime = $log['created_at'] ? date('d/m/Y H:i:s', strtotime($log['created_at'])) : '';
    ?>
    <div class="feed-item">
        <div class="feed-icon" style="background:<?= $style['color'] ?>22;">
            <i class="fa-solid <?= $style['icon'] ?>" style="color:<?= $style['color'] ?>"></i>
        </div>
        <div class="feed-body">
            <div class="feed-action"><?= h($log['action']) ?>
                <?php if ($log['entity_type']): ?>
                <span class="badge badge-basic" style="margin-left:.3rem"><?= h($log['entity_type']) ?><?= $log['entity_id'] ? ' #' . $log['entity_id'] : '' ?></span>
                <?php endif; ?>
            </div>
            <div class="feed-meta">
                <?php if ($log['user_name']): ?>
                <a href="<?= APP_URL ?>/admin/user-profile.php?id=<?= $log['user_id'] ?>" style="color:inherit;text-decoration:none;font-weight:600">
                    <i class="fa-solid fa-user" style="font-size:.7rem"></i> <?= h($log['user_name']) ?>
                </a>
                <?php if ($log['user_role']): ?>
                <span class="badge badge-basic" style="font-size:.65rem"><?= h($log['user_role']) ?></span>
                <?php endif; ?>
                · 
                <?php endif; ?>
                <?php if ($log['school_name']): ?>
                <a href="<?= APP_URL ?>/admin/schools.php?edit=<?= $log['school_id'] ?>" style="color:var(--muted);text-decoration:none">
                    <i class="fa-solid fa-school" style="font-size:.7rem"></i> <?= h($log['school_name']) ?>
                </a>
                <?php if ($log['ip']): ?>
                · <span class="text-xs"><?= h($log['ip']) ?></span>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($log['details']): ?>
                <br><span class="text-xs" style="opacity:.7"><?= h(mb_substr($log['details'], 0, 100)) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="feed-time" title="<?= h($fullTime) ?>"><?= $timeAgo ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
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

</div><!-- /grid col 2 -->

<!-- Sidebar: Top schools + recent actions summary -->
<div>
    <div class="card mb-3">
        <div class="card-title mb-2"><i class="fa-solid fa-ranking-star"></i> Top Σχολές Σήμερα</div>
        <?php if (empty($topSchoolsToday)): ?>
        <p class="text-muted text-sm">Καμία δραστηριότητα σήμερα.</p>
        <?php else: ?>
        <?php $maxCnt = max(array_column($topSchoolsToday, 'cnt')); ?>
        <?php foreach ($topSchoolsToday as $ts): ?>
        <div class="mb-2">
            <div class="d-flex jc-between text-sm mb-1">
                <span style="font-weight:600;font-size:.82rem"><?= h($ts['name']) ?></span>
                <span><?= $ts['cnt'] ?></span>
            </div>
            <div class="progress"><div class="progress-bar" style="width:<?= $maxCnt > 0 ? round($ts['cnt']/$maxCnt*100) : 0 ?>%"></div></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-clock"></i> Γρήγορα Φίλτρα</div>
        <div class="d-flex flex-wrap gap-sm">
            <a href="?action=login" class="btn btn-ghost btn-sm"><i class="fa-solid fa-right-to-bracket"></i> Logins</a>
            <a href="?action=athlete_create" class="btn btn-ghost btn-sm"><i class="fa-solid fa-person-running"></i> Νέοι Αθλητές</a>
            <a href="?action=payment_create" class="btn btn-ghost btn-sm"><i class="fa-solid fa-credit-card"></i> Πληρωμές</a>
            <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-calendar-day"></i> Σήμερα</a>
            <a href="?action=admin_password_reset" class="btn btn-ghost btn-sm"><i class="fa-solid fa-key"></i> Resets</a>
        </div>
    </div>
</div>

</div><!-- /grid -->
</div></div></div>
</body></html>