<?php
/**
 * ============================================================
 * employee/logs.php — Audit Log Viewer (Read-only)
 * ============================================================
 */

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/privileges.php';
require_once __DIR__ . '/layout.php';

empRequire('logs_view');

$db = getDB();

// ── Filters ─────────────────────────────────────────────────
$search    = trim($_GET['q']      ?? '');
$action    = $_GET['action']      ?? '';
$school_id = (int)($_GET['school_id'] ?? 0);
$user_id   = (int)($_GET['user_id']   ?? 0);
$from      = $_GET['from']        ?? '';
$to        = $_GET['to']          ?? '';
$perPage   = 40;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search) {
    $where[]  = '(a.action LIKE ? OR a.details LIKE ? OR a.ip LIKE ?)';
    $like     = "%$search%";
    $params   = array_merge($params, [$like, $like, $like]);
}
if ($action) {
    $where[]  = 'a.action = ?';
    $params[] = $action;
}
if ($school_id) {
    $where[]  = 'a.school_id = ?';
    $params[] = $school_id;
}
if ($user_id) {
    $where[]  = 'a.user_id = ?';
    $params[] = $user_id;
}
if ($from) {
    $where[]  = 'a.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to) {
    $where[]  = 'a.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows = (int)$db->prepare("SELECT COUNT(*) FROM audit_log a $whereSQL")->execute($params) && true
    ? $db->prepare("SELECT COUNT(*) FROM audit_log a $whereSQL")->execute($params) || true
    : 0;

// Proper count
$stmtC = $db->prepare("SELECT COUNT(*) FROM audit_log a $whereSQL");
$stmtC->execute($params);
$totalRows  = (int)$stmtC->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare(
    "SELECT a.*, u.name as user_name, u.email as user_email, s.name as school_name
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     LEFT JOIN schools s ON s.id = a.school_id
     $whereSQL
     ORDER BY a.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Distinct actions for filter
$actions = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$schools = $db->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll();

renderEmpHead('Audit Log');
?>
<body>
<?php renderEmpSidebar('logs'); ?>
<div class="emp-main">
<?php renderEmpTopbar('Audit Log'); ?>
<div class="emp-content">

  <div class="section-title">Audit Log</div>
  <div class="section-sub">Πλήρες ιστορικό ενεργειών συστήματος — Read-only.</div>

  <!-- Filters -->
  <form method="get" class="card" style="padding:1rem 1.4rem">
    <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:1;min-width:160px">
        <label>Αναζήτηση</label>
        <div class="search-input-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="form-control" placeholder="Ενέργεια, λεπτομέρειες, IP…" value="<?= h($search) ?>">
        </div>
      </div>
      <div class="form-group" style="margin:0;min-width:160px">
        <label>Τύπος Ενέργειας</label>
        <select name="action" class="form-control">
          <option value="">Όλες</option>
          <?php foreach ($actions as $ac): ?>
            <option value="<?= h($ac) ?>" <?= $action===$ac?'selected':'' ?>><?= h($ac) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:160px">
        <label>Σχολή</label>
        <select name="school_id" class="form-control">
          <option value="0">Όλες</option>
          <?php foreach ($schools as $sc): ?>
            <option value="<?= $sc['id'] ?>" <?= $school_id==$sc['id']?'selected':'' ?>><?= h($sc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:120px">
        <label>Από</label>
        <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
      </div>
      <div class="form-group" style="margin:0;min-width:120px">
        <label>Έως</label>
        <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
      </div>
      <div style="display:flex;gap:.5rem">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Φίλτρο</button>
        <a href="?" class="btn btn-ghost">Reset</a>
      </div>
    </div>
  </form>

  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:.9rem 1.4rem;border-bottom:1px solid var(--border)">
      <span style="font-size:.88rem;color:var(--muted)"><?= number_format($totalRows) ?> εγγραφές</span>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Ενέργεια</th>
            <th>Χρήστης</th>
            <th>Σχολή</th>
            <th>Λεπτομέρειες</th>
            <th>IP</th>
            <th>Ημερομηνία</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">Δεν βρέθηκαν εγγραφές.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="color:var(--muted2);font-size:.78rem"><?= $r['id'] ?></td>
            <td>
              <?php
              $icon = 'fa-circle-dot'; $color = 'var(--muted)';
              if (str_contains($r['action'],'login'))   { $icon='fa-right-to-bracket'; $color='var(--green)'; }
              if (str_contains($r['action'],'backup'))  { $icon='fa-database';          $color='var(--blue)';  }
              if (str_contains($r['action'],'delete'))  { $icon='fa-trash';             $color='var(--red)';   }
              if (str_contains($r['action'],'update'))  { $icon='fa-pen';               $color='var(--gold)';  }
              if (str_contains($r['action'],'create'))  { $icon='fa-plus';              $color='var(--teal)';  }
              if (str_contains($r['action'],'register')){ $icon='fa-user-plus';         $color='var(--accent)';}
              ?>
              <div style="display:flex;align-items:center;gap:.45rem">
                <i class="fa-solid <?= $icon ?>" style="color:<?= $color ?>;font-size:.8rem"></i>
                <span style="font-size:.83rem;font-weight:600"><?= h($r['action']) ?></span>
              </div>
              <?php if ($r['entity_type']): ?>
                <div style="font-size:.73rem;color:var(--muted2)"><?= h($r['entity_type']) ?> #<?= $r['entity_id'] ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div style="font-size:.83rem"><?= h($r['user_name'] ?? '—') ?></div>
              <div style="font-size:.73rem;color:var(--muted)"><?= h($r['user_email'] ?? '') ?></div>
            </td>
            <td style="font-size:.82rem;color:var(--muted)"><?= h($r['school_name'] ?? ($r['school_id'] ? '#'.$r['school_id'] : 'System')) ?></td>
            <td style="font-size:.8rem;color:var(--muted);max-width:250px;word-break:break-word"><?= h($r['details'] ?? '') ?></td>
            <td style="font-size:.78rem;font-family:monospace;color:var(--muted2)"><?= h($r['ip'] ?? '') ?></td>
            <td style="font-size:.78rem;color:var(--muted2);white-space:nowrap"><?= date('d/m/Y H:i:s', strtotime($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div style="padding:.9rem 1.4rem;border-top:1px solid var(--border)">
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>"><i class="fa-solid fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i = max(1,$page-4); $i <= min($totalPages,$page+4); $i++): ?>
          <<?= $i===$page?'span class="active"':'a href="?'.http_build_query(array_merge($_GET,['page'=>$i])).'"' ?>><?= $i ?></<?= $i===$page?'span':'a' ?>>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>"><i class="fa-solid fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>
<?php renderEmpClose(); ?>