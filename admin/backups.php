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
        error_log('[backups.php] EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
// ──────────────────────────────────────────────────────────────────────────

/**
 * ============================================================
 * admin/backups.php — Database Backups (Super Admin)
 * ============================================================
 * Papaki.gr shared hosting — pure PHP/PDO, no exec(), no mysqldump.
 * Generates a valid, phpMyAdmin-importable SQL file via PDO only.
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── Helper: generate complete SQL dump via PDO only ──────────────────────────
function generateSqlDump(PDO $db): string
{
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    $out  = "-- ============================================================\n";
    $out .= "-- MAster Database Backup\n";
    $out .= "-- Generated : " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- Database  : " . DB_NAME . "\n";
    $out .= "-- ============================================================\n\n";
    $out .= "SET NAMES utf8mb4;\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $out .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $out .= "SET TIME_ZONE = '+00:00';\n\n";

    // All base tables only (no views)
    $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $qt = "`$table`";

        $out .= "-- --------------------------------------------------------\n";
        $out .= "-- Structure for table $qt\n";
        $out .= "-- --------------------------------------------------------\n\n";
        $out .= "DROP TABLE IF EXISTS $qt;\n";

        $createRow = $db->query("SHOW CREATE TABLE $qt")->fetch(PDO::FETCH_NUM);
        $out .= $createRow[1] . ";\n\n";

        $rowCount = (int)$db->query("SELECT COUNT(*) FROM $qt")->fetchColumn();
        if ($rowCount === 0) {
            $out .= "-- (table $table is empty)\n\n";
            continue;
        }

        // Column type info for NULL handling
        $colTypes = $db->query(
            "SELECT COLUMN_NAME, DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = " . $db->quote(DB_NAME) . "
               AND TABLE_NAME   = " . $db->quote($table) . "
             ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $numericTypes = ['int','tinyint','smallint','mediumint','bigint','decimal','float','double','bit','year'];

        $out .= "-- Data for $qt ($rowCount rows)\n";

        // Chunked fetch: 200 rows at a time to stay within memory limits
        $chunkSize = 200;
        $offset    = 0;

        while ($offset < $rowCount) {
            $rows = $db->query("SELECT * FROM $qt LIMIT $chunkSize OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) break;

            $colNames    = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $valueGroups = [];

            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $col => $val) {
                    if ($val === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = $db->quote($val); // handles escaping + quoting
                    }
                }
                $valueGroups[] = '(' . implode(', ', $vals) . ')';
            }

            $out .= "INSERT INTO $qt ($colNames) VALUES\n";
            $out .= implode(",\n", $valueGroups) . ";\n";

            $offset += $chunkSize;
        }

        $out .= "\n";
    }

    $out .= "-- --------------------------------------------------------\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    $out .= "-- Backup complete: " . date('Y-m-d H:i:s') . "\n";

    return $out;
}

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create_backup') {
        $backupDir = __DIR__ . '/../backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0750, true);
            file_put_contents($backupDir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
        }

        $filename = 'master_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backupDir . '/' . $filename;
        $notes    = trim($_POST['notes'] ?? '');

        $sql = generateSqlDump($db);
        $ok  = file_put_contents($filepath, $sql);

        if ($ok === false) {
            flash('Αποτυχία εγγραφής αρχείου. Ελέγξτε τα permissions του φακέλου backups/.', 'danger');
        } else {
            $size = strlen($sql);
            $db->prepare("INSERT INTO backup_log (filename,size_bytes,created_by,notes) VALUES (?,?,?,?)")
               ->execute([$filename, $size, userId(), $notes]);
            auditLog('backup_created', 'backup', 0, $filename);
            flash('Backup δημιουργήθηκε: ' . $filename . ' (' . number_format($size / 1024, 1) . ' KB)');
        }

        redirect(APP_URL . '/admin/backups.php');
    }

    if ($action === 'delete_backup') {
        $id  = (int)$_POST['id'];
        $stBd = $db->prepare("SELECT filename FROM backup_log WHERE id=? LIMIT 1"); $stBd->execute([$id]); $row = $stBd->fetch();
        if ($row) {
            $path = __DIR__ . '/../backups/' . $row['filename'];
            if (file_exists($path)) @unlink($path);
            $db->prepare("DELETE FROM backup_log WHERE id=?")->execute([$id]);
            auditLog('backup_deleted', 'backup', $id, $row['filename']);
            flash('Backup διαγράφηκε.');
        }
        redirect(APP_URL . '/admin/backups.php');
    }
}

// ── GET: Download ─────────────────────────────────────────────────────────────
if (isset($_GET['download'])) {
    $id  = (int)$_GET['download'];
    $stBdl = $db->prepare("SELECT filename FROM backup_log WHERE id=? LIMIT 1"); $stBdl->execute([$id]); $row = $stBdl->fetch();
    if ($row) {
        $path = __DIR__ . '/../backups/' . $row['filename'];
        if (file_exists($path)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($row['filename']) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            auditLog('backup_downloaded', 'backup', $id, $row['filename']);
            exit;
        }
    }
    flash('Αρχείο δεν βρέθηκε.', 'danger');
    redirect(APP_URL . '/admin/backups.php');
}

// ── Data for page ─────────────────────────────────────────────────────────────
$backups = [];
try {
    $backups = $db->query("
        SELECT bl.*, u.name as created_by_name
        FROM backup_log bl
        LEFT JOIN users u ON u.id = bl.created_by
        ORDER BY bl.created_at DESC LIMIT 30
    ")->fetchAll();
} catch (Exception $e) {}

$dbSizeRow  = $db->query("SELECT ROUND(SUM(data_length+index_length)/1024,1) as kb FROM information_schema.TABLES WHERE table_schema=" . $db->quote(DB_NAME))->fetch();
$dbSizeKB   = (float)($dbSizeRow['kb'] ?? 0);
$tableCount = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema=" . $db->quote(DB_NAME))->fetchColumn();
$backupDir  = __DIR__ . '/../backups';
$freeSpace  = is_dir($backupDir) ? @disk_free_space($backupDir) : null;
$maxExec    = (int)ini_get('max_execution_time');

renderHead('Backups');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body{font-size:15px}
.page-body{padding:1.75rem!important}
.card{border-radius:14px!important}
.card-title{font-size:1rem!important;font-weight:700!important}
table{font-size:.9rem!important}
thead th{font-size:.75rem!important;padding:.7rem 1rem!important;letter-spacing:.07em}
tbody td{padding:.8rem 1rem!important;font-size:.88rem!important}
.fw-600{font-size:.92rem!important}
.text-xs{font-size:.78rem!important}
.badge{font-size:.72rem!important;padding:.22rem .6rem!important;border-radius:50px!important;font-weight:700!important}
.btn{font-size:.875rem!important;padding:.5rem 1.05rem!important;border-radius:9px!important;font-weight:500!important}
.btn-sm{font-size:.8rem!important;padding:.32rem .65rem!important}
.form-label{font-size:.82rem!important;font-weight:600!important;color:var(--muted)}
.form-control{font-size:.88rem!important;padding:.58rem .8rem!important;border-radius:9px!important}
.form-group{gap:.4rem!important}
.alert{font-size:.9rem!important;padding:.85rem 1.1rem!important;border-radius:10px!important;display:flex;align-items:flex-start;gap:.65rem;margin-bottom:1rem}
.alert i{flex-shrink:0;margin-top:.15rem}
.alert-warning{background:rgba(240,165,0,.12);color:#fbbf24;border:1px solid rgba(240,165,0,.2)}
.alert-info{background:rgba(59,130,246,.12);color:#93c5fd;border:1px solid rgba(59,130,246,.2)}
.alert-danger{background:rgba(230,57,70,.12);color:#f87171;border:1px solid rgba(230,57,70,.2)}
.text-muted{color:var(--muted)!important}
.text-green{color:var(--green)!important}
h2{font-size:1.2rem!important;font-weight:700!important}

.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.1rem}
.stat-card{border-radius:14px;padding:1.25rem;display:flex;flex-direction:column;gap:.3rem}
.stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:.2rem}
.icon-blue{background:rgba(59,130,246,.15);color:#3b82f6}
.icon-green{background:rgba(45,198,83,.15);color:#2dc653}
.icon-gold{background:rgba(240,165,0,.15);color:#f0a500}
.icon-purple{background:rgba(168,85,247,.15);color:#a855f7}
.stat-val{font-size:clamp(1.1rem,3vw,1.55rem)!important;font-weight:800;line-height:1.1}
.stat-lbl{font-size:.8rem!important;color:var(--muted,#8892b0);font-weight:600}

.card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.95rem 1.2rem;border-bottom:1px solid var(--border,#1e2536)}
.card-body{padding:1.3rem}
.filename{font-family:monospace;font-size:.86rem!important;color:#93c5fd;word-break:break-all}
.spinner{display:none;width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}
.empty-state{text-align:center;padding:3rem 1rem;color:var(--muted,#8892b0)}
.empty-state i{font-size:2.5rem!important;margin-bottom:.75rem;display:block}
.table-wrap{overflow-x:auto}
.table-wrap table{width:100%;border-collapse:collapse}
.table-wrap th{font-size:.74rem!important;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;padding:.65rem .9rem}
.table-wrap td{font-size:.88rem!important;padding:.78rem .9rem;vertical-align:middle}
.table-wrap tbody tr:hover{background:rgba(255,255,255,.03)}

@media(max-width:900px){.stats-row{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.page-body{padding:1rem!important}.hide-sm{display:none}}
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_backups'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-database"></i> Backups'); ?>
<div class="page-body">

<div class="d-flex jc-between ai-center mb-3">
    <h2><i class="fa-solid fa-database" style="color:var(--red,#e63946)"></i> Database Backups</h2>
</div>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-card card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-database"></i></div>
        <div class="stat-val"><?= $dbSizeKB >= 1024 ? number_format($dbSizeKB/1024,2).' MB' : number_format($dbSizeKB,1).' KB' ?></div>
        <div class="stat-lbl">Μέγεθος DB</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-purple"><i class="fa-solid fa-table"></i></div>
        <div class="stat-val"><?= $tableCount ?></div>
        <div class="stat-lbl">Πίνακες</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-hard-drive"></i></div>
        <div class="stat-val"><?= $freeSpace ? number_format($freeSpace/1024/1024/1024,1).' GB' : '—' ?></div>
        <div class="stat-lbl">Ελεύθερος Χώρος</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-clock"></i></div>
        <div class="stat-val"><?= $maxExec ?>s</div>
        <div class="stat-lbl">Max Exec Time</div>
    </div>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    <div>
        Backup μέσω <strong>PHP/PDO</strong> — δεν χρειάζεται mysqldump ή πρόσβαση shell.
        Παράγει <code>.sql</code> αρχείο πλήρως συμβατό με <strong>phpMyAdmin → Import</strong>.
    </div>
</div>

<!-- Create -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-plus" style="color:#2dc653"></i> Δημιουργία Backup</div>
    </div>
    <div class="card-body">
        <form method="POST" id="backupForm" onsubmit="startBackup()">
            <input type="hidden" name="_action" value="create_backup">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="form-group" style="margin-bottom:1rem;max-width:460px">
                <label class="form-label"><i class="fa-solid fa-comment"></i> Σημειώσεις (προαιρετικό)</label>
                <input name="notes" class="form-control" placeholder="π.χ. Πριν από update">
            </div>
            <button type="submit" id="backupBtn" class="btn btn-primary" style="min-height:50px;padding:.65rem 1.8rem">
                <div class="spinner" id="spinner"></div>
                <i class="fa-solid fa-database" id="backupIcon"></i>
                <span id="backupTxt">Δημιουργία Backup Τώρα</span>
            </button>
            <div id="backupNote" style="display:none;margin-top:.75rem;font-size:.84rem;color:var(--muted)">
                <i class="fa-solid fa-hourglass-half"></i>
                Παρακαλείτε περιμένετε — η διαδικασία μπορεί να πάρει μερικά δευτερόλεπτα...
            </div>
        </form>
    </div>
</div>

<!-- History -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--muted)"></i> Ιστορικό Backups</div>
        <span style="font-size:.84rem;color:var(--muted)">Τελευταία 30</span>
    </div>
    <?php if (empty($backups)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-box-open"></i>
        <p>Δεν υπάρχουν backups ακόμα.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-backups" type="text" placeholder="Αναζήτηση backups..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-backups">
        <thead>
            <tr>
                <th>Αρχείο</th>
                <th>Μέγεθος</th>
                <th class="hide-sm">Από</th>
                <th class="hide-sm">Σημειώσεις</th>
                <th>Ημ/νία</th>
                <th>Ενέργειες</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($backups as $b):
            $sizeKB     = $b['size_bytes'] / 1024;
            $sizeStr    = $sizeKB >= 1024 ? number_format($sizeKB/1024,2).' MB' : number_format($sizeKB,1).' KB';
            $fileExists = file_exists(__DIR__.'/../backups/'.$b['filename']);
        ?>
        <tr style="<?= !$fileExists ? 'opacity:.45' : '' ?>">
            <td>
                <div class="filename"><?= h($b['filename']) ?></div>
                <?php if (!$fileExists): ?>
                <div style="font-size:.75rem;color:#e63946;margin-top:.2rem">
                    <i class="fa-solid fa-triangle-exclamation"></i> Αρχείο δεν βρέθηκε
                </div>
                <?php endif; ?>
            </td>
            <td style="font-weight:700;white-space:nowrap"><?= $sizeStr ?></td>
            <td class="hide-sm" style="color:var(--muted)"><?= h($b['created_by_name'] ?? '—') ?></td>
            <td class="hide-sm" style="color:var(--muted);font-size:.84rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($b['notes'] ?? '—') ?></td>
            <td style="white-space:nowrap;color:var(--muted);font-size:.85rem"><?= date('d/m/Y H:i', strtotime($b['created_at'])) ?></td>
            <td>
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                    <?php if ($fileExists): ?>
                    <a href="?download=<?= $b['id'] ?>" class="btn btn-secondary btn-sm">
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                    <?php endif; ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Διαγραφή <?= h(addslashes($b['filename'])) ?>;')">
                        <input type="hidden" name="_action" value="delete_backup">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="id" value="<?= $b['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" title="Διαγραφή">
                            <i class="fa-solid fa-trash" style="color:var(--danger,#e63946)"></i>
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div id="pg-backups" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
    <?php endif; ?>
</div>

<div class="alert alert-warning" style="margin-bottom:.5rem">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div>
        <strong>Σημαντικό:</strong> Τα backup αρχεία αποθηκεύονται στον server σας (<code>/backups/</code>).
        Κατεβάστε τα τακτικά τοπικά. Για επαναφορά: <strong>phpMyAdmin → Import</strong>.
    </div>
</div>

</div></div></div>

<script>
function startBackup() {
    var btn = document.getElementById('backupBtn');
    var sp  = document.getElementById('spinner');
    var ic  = document.getElementById('backupIcon');
    var tx  = document.getElementById('backupTxt');
    var nt  = document.getElementById('backupNote');
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '.75';
        if (sp) sp.style.display = 'block';
        if (ic) ic.style.display = 'none';
        if (tx) tx.textContent = 'Δημιουργία Backup...';
        if (nt) nt.style.display = 'block';
    }
}
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
initPagination('tbl-backups', 'pg-backups', 10, 'srch-backups');
</script>
</body></html>