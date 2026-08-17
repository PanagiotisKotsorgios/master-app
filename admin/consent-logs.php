<?php
/**
 * ============================================================
 * admin/consent-logs.php — Consent Proof Log Viewer (Super Admin)
 * ============================================================
 * PURPOSE:
 *   Εμφανίζει αρχεία συγκατάθεσης (GDPR consent proof log).
 *   Καταγράφει: αποδοχή Όρων, opt-in/out SMS/email, unsubscribe.
 *   Χρησιμοποιείται ως αποδεικτικό GDPR συμμόρφωσης.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin() — μόνο superadmin
 *   ✓ Read-only — δεν επιτρέπεται τροποποίηση
 *   ✓ Prepared statements με dynamic WHERE
 *   ✓ h() για output escaping
 *   ✓ Pagination (αποτρέπει memory exhaustion)
 *
 * DATA RETENTION (per Privacy Policy §6):
 *   Τα consent logs διατηρούνται για 12 μήνες.
 *   Cleanup τρέχει αυτόματα από cron.
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── Ensure consent_log table exists ──────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS consent_log (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        school_id      INT NULL,
        parent_user_id INT NULL,
        event_type     VARCHAR(40)  NOT NULL,
        ip_hash        VARCHAR(128) NULL,
        user_agent     VARCHAR(512) NULL,
        terms_version  VARCHAR(10)  NULL,
        created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pid   (parent_user_id),
        INDEX idx_type  (event_type),
        INDEX idx_date  (created_at)
    )");
} catch (\PDOException $e) { /* already exists */ }

// ── Filters ───────────────────────────────────────────────────────────────────
$filterType   = trim($_GET['event_type'] ?? '');
$filterParent = (int)($_GET['parent_id']  ?? 0);
$search       = trim($_GET['q']           ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = in_array((int)($_GET['per_page'] ?? 25), [10,25,50,100]) ? (int)$_GET['per_page'] : 25;

$where  = ['1=1'];
$params = [];

if ($filterType) {
    $where[]  = 'cl.event_type = ?';
    $params[] = $filterType;
}
if ($filterParent) {
    $where[]  = 'cl.parent_user_id = ?';
    $params[] = $filterParent;
}
if ($search) {
    $where[]  = '(pu.parent_email LIKE ? OR cl.event_type LIKE ? OR cl.terms_version LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("
    SELECT COUNT(*)
    FROM consent_log cl
    LEFT JOIN parent_users pu ON pu.id = cl.parent_user_id
    WHERE $whereStr
");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$offset     = ($page - 1) * $perPage;

$stmt = $db->prepare("
    SELECT cl.*,
           pu.parent_email,
           s.name AS school_name
    FROM consent_log cl
    LEFT JOIN parent_users pu ON pu.id  = cl.parent_user_id
    LEFT JOIN schools       s  ON s.id  = cl.school_id
    WHERE $whereStr
    ORDER BY cl.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Distinct event types for filter dropdown
$eventTypes = $db->query("SELECT DISTINCT event_type FROM consent_log ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);

// Stats
$statsStmt = $db->query("
    SELECT event_type, COUNT(*) AS cnt
    FROM consent_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY event_type
    ORDER BY cnt DESC
");
$statsData = $statsStmt->fetchAll();

// Oldest record & retention info
$oldestStmt = $db->query("SELECT MIN(created_at) FROM consent_log");
$oldest = $oldestStmt->fetchColumn();

$totalAllStmt = $db->query("SELECT COUNT(*) FROM consent_log");
$totalAll = (int)$totalAllStmt->fetchColumn();

// ── Event type labels ─────────────────────────────────────────────────────────
$eventLabels = [
    'terms_accepted'    => ['label' => 'Αποδοχή Όρων',       'color' => '#2dc653', 'icon' => 'fa-circle-check'],
    'sms_consent_given' => ['label' => 'Συγκατάθεση SMS',     'color' => '#3b82f6', 'icon' => 'fa-message'],
    'sms_opt_out'       => ['label' => 'Άρση SMS',            'color' => '#f0a500', 'icon' => 'fa-bell-slash'],
    'email_opt_out'     => ['label' => 'Άρση Email',          'color' => '#f0a500', 'icon' => 'fa-envelope-open'],
    'email_opt_in'      => ['label' => 'Opt-in Email',        'color' => '#2dc653', 'icon' => 'fa-envelope'],
    'sms_opt_in'        => ['label' => 'Opt-in SMS',          'color' => '#2dc653', 'icon' => 'fa-message'],
    'unsubscribe'       => ['label' => 'Unsubscribe',         'color' => '#e63946', 'icon' => 'fa-user-minus'],
    'manual_opt_out'    => ['label' => 'Χειροκίνητη Άρση',   'color' => '#8892b0', 'icon' => 'fa-hand'],
];

function getEventMeta(string $type, array $labels): array {
    return $labels[$type] ?? ['label' => $type, 'color' => '#8892b0', 'icon' => 'fa-circle'];
}

renderHead('Consent Log');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { font-size: 15px; }
.page-body { padding: 1.75rem !important; }
.card { border-radius: 14px !important; }
.card-title { font-size: 1rem !important; font-weight: 700 !important; }
table { font-size: .88rem !important; }
thead th { font-size: .75rem !important; padding: .7rem 1rem !important; letter-spacing: .07em; }
tbody td { padding: .75rem 1rem !important; vertical-align: middle; }

.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px,1fr));
  gap: 1rem;
  margin-bottom: 1.5rem;
}
.stat-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 1.1rem 1.25rem;
  display: flex;
  align-items: center;
  gap: 1rem;
}
.stat-icon {
  width: 42px; height: 42px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem;
  flex-shrink: 0;
}
.stat-val { font-size: 1.6rem; font-weight: 800; line-height: 1; }
.stat-lbl { font-size: .75rem; color: var(--muted); margin-top: .2rem; }

.event-badge {
  display: inline-flex; align-items: center; gap: .35rem;
  font-size: .75rem; font-weight: 700;
  padding: .2rem .6rem;
  border-radius: 99px;
  border: 1px solid;
  white-space: nowrap;
}
.mono { font-family: 'Courier New', monospace; font-size: .78rem; color: var(--muted); }

.filter-bar {
  display: flex; flex-wrap: wrap; gap: .75rem;
  align-items: flex-end;
  margin-bottom: 1.25rem;
}
.filter-bar .fg { display: flex; flex-direction: column; gap: .3rem; }
.filter-bar label { font-size: .75rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
.filter-bar input,
.filter-bar select {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 8px; color: var(--white);
  padding: .5rem .75rem; font-size: .88rem; font-family: inherit;
  min-width: 160px;
}
.filter-bar input:focus,
.filter-bar select:focus { outline: none; border-color: var(--red); }
.btn-filter {
  background: var(--red); color: #fff; border: none;
  border-radius: 8px; padding: .55rem 1.1rem;
  font-size: .88rem; font-weight: 700; cursor: pointer;
  align-self: flex-end;
}
.btn-clear {
  background: transparent; color: var(--muted); border: 1px solid var(--border);
  border-radius: 8px; padding: .55rem 1rem;
  font-size: .88rem; font-weight: 600; cursor: pointer;
  text-decoration: none; align-self: flex-end;
  display: inline-flex; align-items: center; gap: .4rem;
}
.btn-clear:hover { color: var(--white); border-color: var(--muted); }

.retention-note {
  background: rgba(240,165,0,.07);
  border: 1px solid rgba(240,165,0,.25);
  border-radius: 10px;
  padding: .75rem 1rem;
  font-size: .82rem;
  color: #f0a500;
  margin-bottom: 1.25rem;
  display: flex; align-items: center; gap: .6rem;
}

.pagination { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:1.25rem; }
.pagination a, .pagination span {
  padding:.4rem .75rem; border-radius:8px; font-size:.85rem; font-weight:600;
  background:var(--bg2); border:1px solid var(--border); color:var(--muted);
  text-decoration:none; transition:all .15s;
}
.pagination a:hover { border-color:var(--red); color:var(--white); }
.pagination .cur { background:var(--red); border-color:var(--red); color:#fff; }

@media(max-width:640px){
  .filter-bar { flex-direction:column; }
  .filter-bar input, .filter-bar select { min-width:100%; }
  .stat-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<?php renderSidebar('admin_consent_logs'); ?>
<div class="main-content">
<?php renderTopbar('Consent Log — GDPR Proof'); ?>
<div class="page-body">

<!-- ── Retention notice ─────────────────────────────────────────────────── -->
<div class="retention-note">
  <i class="fas fa-clock"></i>
  <span>
    Τα consent logs διατηρούνται για <strong>12 μήνες</strong> (βάσει Πολιτικής Απορρήτου §6).
    Σύνολο εγγραφών: <strong><?= number_format($totalAll) ?></strong>
    <?php if ($oldest): ?> — Παλαιότερη: <strong><?= h(date('d/m/Y', strtotime($oldest))) ?></strong><?php endif; ?>
  </span>
</div>

<!-- ── Stats (last 30 days) ─────────────────────────────────────────────── -->
<?php if ($statsData): ?>
<div class="stat-grid">
  <?php foreach ($statsData as $s):
    $meta = getEventMeta($s['event_type'], $eventLabels);
  ?>
  <div class="stat-card">
    <div class="stat-icon" style="background:<?= h($meta['color']) ?>22; color:<?= h($meta['color']) ?>">
      <i class="fas <?= h($meta['icon']) ?>"></i>
    </div>
    <div>
      <div class="stat-val" style="color:<?= h($meta['color']) ?>"><?= number_format((int)$s['cnt']) ?></div>
      <div class="stat-lbl"><?= h($meta['label']) ?><br><span style="color:var(--dim)">τελ. 30 ημέρες</span></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Filter bar ────────────────────────────────────────────────────────── -->
<form method="GET" class="filter-bar">
  <div class="fg">
    <label>Αναζήτηση</label>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="email, τύπος, έκδοση…">
  </div>
  <div class="fg">
    <label>Τύπος Γεγονότος</label>
    <select name="event_type">
      <option value="">— Όλοι —</option>
      <?php foreach ($eventTypes as $et): ?>
        <option value="<?= h($et) ?>" <?= $filterType === $et ? 'selected' : '' ?>>
          <?= h(getEventMeta($et, $eventLabels)['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($filterParent): ?>
  <input type="hidden" name="parent_id" value="<?= $filterParent ?>">
  <?php endif; ?>
  <div class="fg">
    <label>Ανά σελίδα</label>
    <select name="per_page">
      <?php foreach ([10,25,50,100] as $pp): ?>
        <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Φίλτρο</button>
  <a href="consent-logs.php" class="btn-clear"><i class="fas fa-times"></i> Καθαρισμός</a>
</form>

<!-- ── Table ─────────────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
    <span class="card-title"><i class="fas fa-file-shield" style="color:var(--red);margin-right:.4rem"></i>Consent Proof Log</span>
    <span style="font-size:.82rem;color:var(--muted)"><?= number_format($total) ?> εγγραφές<?= $filterType || $search || $filterParent ? ' (φιλτραρισμένες)' : '' ?></span>
  </div>

  <?php if (empty($logs)): ?>
    <div style="text-align:center;padding:3rem 1rem;color:var(--muted)">
      <i class="fas fa-inbox" style="font-size:2.5rem;opacity:.3;margin-bottom:1rem;display:block"></i>
      <div>Δεν βρέθηκαν εγγραφές<?= $filterType || $search ? ' για τα τρέχοντα φίλτρα' : '' ?>.</div>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr>
        <th style="text-align:left">#</th>
        <th style="text-align:left">Γεγονός</th>
        <th style="text-align:left">Γονέας (email)</th>
        <th style="text-align:left">Σχολή</th>
        <th style="text-align:left">IP Hash</th>
        <th style="text-align:left">User Agent</th>
        <th style="text-align:left">Έκδοση Όρων</th>
        <th style="text-align:left">Ημερομηνία</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($logs as $log):
        $meta = getEventMeta($log['event_type'], $eventLabels);
      ?>
      <tr style="border-top:1px solid var(--border)">
        <td style="color:var(--dim)"><?= $log['id'] ?></td>
        <td>
          <span class="event-badge" style="color:<?= h($meta['color']) ?>;border-color:<?= h($meta['color']) ?>44;background:<?= h($meta['color']) ?>11">
            <i class="fas <?= h($meta['icon']) ?>"></i>
            <?= h($meta['label']) ?>
          </span>
        </td>
        <td>
          <?php if ($log['parent_email']): ?>
            <a href="consent-logs.php?parent_id=<?= (int)$log['parent_user_id'] ?>" style="color:var(--white);text-decoration:none">
              <?= h($log['parent_email']) ?>
            </a>
          <?php elseif ($log['parent_user_id']): ?>
            <span style="color:var(--muted)">ID <?= $log['parent_user_id'] ?></span>
          <?php else: ?>
            <span style="color:var(--dim)">—</span>
          <?php endif; ?>
        </td>
        <td><?= $log['school_name'] ? h($log['school_name']) : '<span style="color:var(--dim)">—</span>' ?></td>
        <td>
          <?php if ($log['ip_hash']): ?>
            <span class="mono" title="Ψευδωνυμοποιημένο hash — δεν αντιστοιχεί σε πραγματική IP">
              <?= h(substr($log['ip_hash'], 0, 16)) ?>…
            </span>
          <?php else: ?>
            <span style="color:var(--dim)">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($log['user_agent']): ?>
            <span class="mono" title="<?= h($log['user_agent']) ?>">
              <?= h(substr($log['user_agent'], 0, 32)) ?><?= strlen($log['user_agent']) > 32 ? '…' : '' ?>
            </span>
          <?php else: ?>
            <span style="color:var(--dim)">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?= $log['terms_version'] ? '<span style="font-size:.78rem;background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:6px;padding:.15rem .45rem">v' . h($log['terms_version']) . '</span>' : '<span style="color:var(--dim)">—</span>' ?>
        </td>
        <td style="white-space:nowrap;color:var(--muted)">
          <?= $log['created_at'] ? h(date('d/m/Y H:i', strtotime($log['created_at']))) : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php
    $q = http_build_query(array_filter([
      'q'          => $search,
      'event_type' => $filterType,
      'parent_id'  => $filterParent ?: null,
      'per_page'   => $perPage !== 25 ? $perPage : null,
    ]));
    $base = 'consent-logs.php?' . ($q ? $q . '&' : '');
    ?>
    <?php if ($page > 1): ?><a href="<?= $base ?>page=<?= $page-1 ?>">‹ Πριν</a><?php endif; ?>
    <?php for ($p = max(1,$page-3); $p <= min($totalPages,$page+3); $p++): ?>
      <?php if ($p === $page): ?>
        <span class="cur"><?= $p ?></span>
      <?php else: ?>
        <a href="<?= $base ?>page=<?= $p ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="<?= $base ?>page=<?= $page+1 ?>">Μετά ›</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<!-- ── GDPR Info ─────────────────────────────────────────────────────────── -->
<div style="margin-top:1.5rem;background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:1rem 1.25rem;font-size:.82rem;color:#93c5fd">
  <i class="fas fa-circle-info" style="margin-right:.4rem"></i>
  <strong>GDPR Note:</strong> Τα consent logs αποτελούν <strong>αποδεικτικό συμμόρφωσης</strong> (απόδειξη συγκατάθεσης — άρθρο 7§1 ΓΚΠΔ).
  Τα IP αποθηκεύονται ψευδωνυμοποιημένα (hash με salt — δεν είναι δυνατή η αντιστροφή).
  Διατηρούνται για <strong>12 μήνες</strong> και διαγράφονται αυτόματα.
</div>

</div><!-- /.page-body -->
</div><!-- /.main-content -->

<?php
// ── Render sidebar script ──
?>
<script>
document.querySelectorAll('tbody tr').forEach(r => {
  r.addEventListener('mouseenter', () => r.style.background = 'rgba(255,255,255,.025)');
  r.addEventListener('mouseleave', () => r.style.background = '');
});
</script>
