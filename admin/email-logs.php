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
 * admin/email-logs.php — Email & SMS Logs Viewer (Super Admin)
 * ============================================================
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── Filters ────────────────────────────────────────────────────────────────
$filterType   = trim($_GET['type'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterSchool = (int)($_GET['school_id'] ?? 0);
$filterDate   = trim($_GET['date'] ?? '');
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;

$where  = ['1=1'];
$params = [];

if ($filterType)   { $where[] = "rl.type = ?";       $params[] = $filterType; }
if ($filterStatus) { $where[] = "rl.status = ?";     $params[] = $filterStatus; }
if ($filterSchool) { $where[] = "rl.school_id = ?";  $params[] = $filterSchool; }
if ($filterDate)   { $where[] = "DATE(rl.sent_at) = ?"; $params[] = $filterDate; }
if ($search)       { $where[] = "(rl.recipient LIKE ? OR rl.subject LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereStr = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM reminder_logs rl WHERE $whereStr");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / max(1, $limit)));
$offset     = ($page - 1) * $limit;

// Main query
$stmt = $db->prepare("
    SELECT rl.*, s.name as school_name
    FROM reminder_logs rl
    LEFT JOIN schools s ON s.id = rl.school_id
    WHERE $whereStr
    ORDER BY rl.sent_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Schools dropdown
$schools = $db->query("SELECT id, name FROM schools ORDER BY name")->fetchAll();

// ── Stats ──────────────────────────────────────────────────────────────────
$stats = [
    'email_total'  => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='email'")->fetchColumn(),
    'email_sent'   => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='email' AND status='sent'")->fetchColumn(),
    'email_failed' => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='email' AND status='failed'")->fetchColumn(),
    'sms_total'    => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='sms'")->fetchColumn(),
    'sms_sent'     => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='sms' AND status='sent'")->fetchColumn(),
    'sms_failed'   => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='sms' AND status='failed'")->fetchColumn(),
    'today_total'  => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE DATE(sent_at)=CURDATE()")->fetchColumn(),
    'today_failed' => $db->query("SELECT COUNT(*) FROM reminder_logs WHERE status='failed' AND DATE(sent_at)=CURDATE()")->fetchColumn(),
];

// Daily volume chart (last 14 days)
$dailyData = [];
for ($i = 13; $i >= 0; $i--) {
    $d   = date('Y-m-d', strtotime("-$i days"));
    $ec  = $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='email' AND status='sent' AND DATE(sent_at)='$d'")->fetchColumn();
    $sc  = $db->query("SELECT COUNT(*) FROM reminder_logs WHERE type='sms'   AND status='sent' AND DATE(sent_at)='$d'")->fetchColumn();
    $dailyData[] = ['day' => date('d/m', strtotime($d)), 'email' => (int)$ec, 'sms' => (int)$sc];
}

// Top senders (schools by volume)
$topSenders = $db->query("
    SELECT s.name, COUNT(*) as cnt,
           SUM(CASE WHEN rl.status='sent' THEN 1 ELSE 0 END) as sent,
           SUM(CASE WHEN rl.status='failed' THEN 1 ELSE 0 END) as failed
    FROM reminder_logs rl
    JOIN schools s ON s.id = rl.school_id
    WHERE rl.sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY rl.school_id ORDER BY cnt DESC LIMIT 8
")->fetchAll();

renderHead('Email & SMS Logs');
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
.search-bar input { font-size: .88rem !important; }
.page-btn { font-size: .82rem !important; padding: .38rem .68rem !important; }

.type-email { color: #4361ee; }
.type-sms   { color: #7209b7; }
.status-sent   { color: var(--green, #2a9d5c); }
.status-failed { color: var(--red, #e63946); }

.preview-btn { cursor:pointer; }
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:9998; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal-box { background:var(--card,#111520); border:1px solid var(--border,#1e2536); border-radius:16px; padding:1.75rem; min-width:300px; max-width:680px; width:95%; max-height:80vh; overflow-y:auto; }
.modal-title { font-size:1rem; font-weight:700; margin-bottom:1rem; }

@media(max-width:768px){ .page-body { padding: 1rem !important; } table { font-size:.78rem !important; } }
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_email_logs'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-paper-plane"></i> Email & SMS Logs'); ?>
<div class="page-body">

<!-- KPI Stats -->
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-envelope"></i></div>
        <div class="stat-val"><?= number_format($stats['email_sent']) ?></div>
        <div class="stat-lbl">Emails Απεσταλμένα</div>
        <div class="stat-sub"><?= number_format($stats['email_failed']) ?> αποτυχίες</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-comment-sms"></i></div>
        <div class="stat-val"><?= number_format($stats['sms_sent']) ?></div>
        <div class="stat-lbl">SMS Απεσταλμένα</div>
        <div class="stat-sub"><?= number_format($stats['sms_failed']) ?> αποτυχίες</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-calendar-day"></i></div>
        <div class="stat-val"><?= number_format($stats['today_total']) ?></div>
        <div class="stat-lbl">Σήμερα</div>
        <?php if ($stats['today_failed'] > 0): ?>
        <div class="stat-sub text-red"><?= $stats['today_failed'] ?> αποτυχίες</div>
        <?php endif; ?>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?= $stats['email_failed'] + $stats['sms_failed'] > 0 ? 'icon-red' : 'icon-green' ?>">
            <i class="fa-solid fa-percent"></i>
        </div>
        <div class="stat-val <?= $stats['email_failed'] + $stats['sms_failed'] > 0 ? 'text-red' : 'text-green' ?>">
            <?php
            $total = $stats['email_total'] + $stats['sms_total'];
            $sent  = $stats['email_sent'] + $stats['sms_sent'];
            echo $total > 0 ? round($sent / $total * 100, 1) : 0;
            ?>%
        </div>
        <div class="stat-lbl">Ποσοστό Επιτυχίας</div>
    </div>
</div>

<div class="grid grid-2 mb-3">
    <!-- Volume chart -->
    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-chart-bar"></i> Αποστολές 14 Ημερών</div>
        <canvas id="volumeChart" height="160"></canvas>
    </div>

    <!-- Top senders -->
    <div class="card">
        <div class="card-title mb-2"><i class="fa-solid fa-ranking-star"></i> Top Σχολές (30 ημέρες)</div>
        <?php if (empty($topSenders)): ?>
        <p class="text-muted text-sm">Δεν βρέθηκαν δεδομένα.</p>
        <?php else: ?>
        <?php $maxSend = max(array_column($topSenders, 'cnt')); ?>
        <?php foreach ($topSenders as $ts): ?>
        <div class="mb-2">
            <div class="d-flex jc-between text-sm mb-1">
                <span style="font-weight:600;font-size:.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px"><?= h($ts['name']) ?></span>
                <span><?= $ts['sent'] ?> ✓ <?= $ts['failed'] > 0 ? '<span class="text-red"> · ' . $ts['failed'] . ' ✗</span>' : '' ?></span>
            </div>
            <div class="progress"><div class="progress-bar" style="width:<?= $maxSend>0?round($ts['cnt']/$maxSend*100):0 ?>%"></div></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3" style="padding:.875rem">
    <form method="GET" class="d-flex gap-sm flex-wrap ai-end">
        <div class="form-group" style="margin:0;min-width:200px">
            <div class="search-bar">
                <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input name="q" value="<?= h($search) ?>" placeholder="Παραλήπτης ή θέμα...">
            </div>
        </div>
        <div class="form-group" style="margin:0">
            <select name="type" class="form-control">
                <option value="">Όλοι τύποι</option>
                <option value="email" <?= $filterType==='email'?'selected':'' ?>>Email</option>
                <option value="sms"   <?= $filterType==='sms'?'selected':'' ?>>SMS</option>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <select name="status" class="form-control">
                <option value="">Όλα</option>
                <option value="sent"   <?= $filterStatus==='sent'?'selected':'' ?>>Απεστάλη</option>
                <option value="failed" <?= $filterStatus==='failed'?'selected':'' ?>>Απέτυχε</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;min-width:160px">
            <select name="school_id" class="form-control">
                <option value="">Όλες σχολές</option>
                <?php foreach ($schools as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filterSchool==$s['id']?'selected':'' ?>><?= h($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <input type="date" name="date" value="<?= h($filterDate) ?>" class="form-control">
        </div>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i> Φίλτρο</button>
        <a href="<?= APP_URL ?>/admin/email-logs.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i></a>
    </form>
</div>

<!-- Logs Table -->
<div class="card p-0">
    <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title"><i class="fa-solid fa-list"></i> Αρχείο Αποστολών</div>
        <span class="text-muted text-sm"><?= number_format($totalRows) ?> εγγραφές · σελίδα <?= $page ?>/<?= max(1,$totalPages) ?></span>
    </div>
    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Τύπος</th>
                <th>Σχολή</th>
                <th>Παραλήπτης</th>
                <th>Θέμα / Μήνυμα</th>
                <th>Κατάσταση</th>
                <th>Ημερομηνία</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
            <td>
                <?php if ($log['type'] === 'email'): ?>
                <span class="badge" style="background:rgba(67,97,238,.12);color:#4361ee"><i class="fa-solid fa-envelope"></i> Email</span>
                <?php else: ?>
                <span class="badge" style="background:rgba(114,9,183,.12);color:#7209b7"><i class="fa-solid fa-comment-sms"></i> SMS</span>
                <?php endif; ?>
            </td>
            <td class="text-sm"><?= h($log['school_name'] ?? '—') ?></td>
            <td>
                <div class="fw-600" style="font-size:.85rem"><?= h($log['recipient'] ?? '—') ?></div>
            </td>
            <td>
                <div style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem">
                    <?= h($log['subject'] ?? $log['body'] ?? '—') ?>
                </div>
            </td>
            <td>
                <?php if ($log['status'] === 'sent'): ?>
                <span class="badge badge-active"><i class="fa-solid fa-check"></i> Απεστάλη</span>
                <?php elseif ($log['status'] === 'failed'): ?>
                <span class="badge badge-overdue"><i class="fa-solid fa-xmark"></i> Απέτυχε</span>
                <?php else: ?>
                <span class="badge badge-pending"><?= h($log['status']) ?></span>
                <?php endif; ?>
            </td>
            <td class="text-xs text-muted"><?= $log['sent_at'] ? date('d/m/Y H:i', strtotime($log['sent_at'])) : '—' ?></td>
            <td>
                <?php if (!empty($log['body']) || !empty($log['subject'])): ?>
                <button class="btn btn-ghost btn-sm preview-btn"
                    onclick="showPreview(<?= htmlspecialchars(json_encode(['type'=>$log['type'],'recipient'=>$log['recipient'],'subject'=>$log['subject']??'','message'=>$log['body']??'','status'=>$log['status'],'date'=>$log['sent_at'],'school'=>$log['school_name']??'']), ENT_QUOTES) ?>)"
                    title="Προεπισκόπηση">
                    <i class="fa-solid fa-eye"></i>
                </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
        <tr><td colspan="7" class="text-center text-muted" style="padding:2.5rem">Δεν βρέθηκαν εγγραφές</td></tr>
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

<!-- Preview Modal -->
<div class="modal-backdrop" id="previewModal">
    <div class="modal-box">
        <div class="modal-title" id="previewTitle"></div>
        <div style="margin-bottom:.75rem">
            <div class="text-muted text-xs" id="previewMeta"></div>
        </div>
        <div id="previewBody" style="background:var(--bg2);border-radius:10px;padding:1rem;font-size:.88rem;line-height:1.6;white-space:pre-wrap;max-height:400px;overflow-y:auto;"></div>
        <div class="mt-3">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('previewModal').classList.remove('open')">Κλείσιμο</button>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('volumeChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($dailyData, 'day')) ?>,
        datasets: [
            { label: 'Email', data: <?= json_encode(array_column($dailyData, 'email')) ?>, backgroundColor: 'rgba(67,97,238,.7)', borderRadius:3 },
            { label: 'SMS',   data: <?= json_encode(array_column($dailyData, 'sms')) ?>,   backgroundColor: 'rgba(114,9,183,.7)', borderRadius:3 }
        ]
    },
    options: {
        responsive:true,
        plugins:{ legend:{ labels:{ color:'#7a849e', boxWidth:12 } } },
        scales:{ x:{ ticks:{ color:'#7a849e', font:{size:10} }, stacked:true }, y:{ ticks:{ color:'#7a849e', stepSize:1 }, stacked:true } }
    }
});

function showPreview(data) {
    const icon = data.type === 'email' ? '📧' : '💬';
    document.getElementById('previewTitle').innerHTML = icon + ' ' + (data.type === 'email' ? 'Email' : 'SMS') + ' Preview';
    document.getElementById('previewMeta').innerHTML =
        '<strong>Από:</strong> ' + (data.school || '—') + ' → <strong>Προς:</strong> ' + data.recipient +
        (data.subject ? ' · <strong>Θέμα:</strong> ' + data.subject : '') +
        ' · ' + (data.date ? data.date.substring(0,16) : '') +
        ' · <span class="' + (data.status==='sent'?'text-green':'text-red') + '">' + (data.status==='sent'?'✓ Απεστάλη':'✗ Απέτυχε') + '</span>';
    document.getElementById('previewBody').textContent = data.message || data.subject || '(Δεν υπάρχει περιεχόμενο)';
    document.getElementById('previewModal').classList.add('open');
}
document.getElementById('previewModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
</script>
</body></html>