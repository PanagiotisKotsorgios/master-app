<?php
/**
 * ============================================================
 * employee/search.php — Universal Search (Read-only)
 * ============================================================
 * Αναζήτηση ταυτόχρονα σε: σχολές, χρήστες, αθλητές, logs
 * ============================================================
 */

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/privileges.php';
require_once __DIR__ . '/layout.php';

empRequire('search_access');

$db = getDB();

$q       = trim($_GET['q'] ?? '');
$section = $_GET['section'] ?? 'all'; // all | schools | users | athletes | logs

$results = [];

if (strlen($q) >= 2) {
    $like = "%$q%";

    if (in_array($section, ['all','schools'])) {
        $stmt = $db->prepare(
            "SELECT 'school' as type, s.id, s.name as title,
                    CONCAT(COALESCE(s.email,''), ' ', COALESCE(s.city,''), ' ', COALESCE(s.phone,'')) as sub,
                    s.subscription_status as status
             FROM schools s
             WHERE s.name LIKE ? OR s.email LIKE ? OR s.city LIKE ? OR s.phone LIKE ?
             LIMIT 20"
        );
        $stmt->execute([$like,$like,$like,$like]);
        $results['schools'] = $stmt->fetchAll();
    }

    if (in_array($section, ['all','users'])) {
        $stmt = $db->prepare(
            "SELECT 'user' as type, u.id, u.name as title,
                    CONCAT(u.email, ' · ', u.role) as sub,
                    u.role as status,
                    s.name as school_name
             FROM users u
             LEFT JOIN schools s ON s.id = u.school_id
             WHERE u.name LIKE ? OR u.email LIKE ?
             LIMIT 20"
        );
        $stmt->execute([$like,$like]);
        $results['users'] = $stmt->fetchAll();
    }

    if (in_array($section, ['all','athletes'])) {
        $stmt = $db->prepare(
            "SELECT 'athlete' as type, a.id, a.full_name as title,
                    COALESCE(a.phone, a.parent_phone,'') as sub,
                    IF(a.active,1,0) as status,
                    s.name as school_name
             FROM athletes a
             LEFT JOIN schools s ON s.id = a.school_id
             WHERE a.full_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ? OR a.parent_phone LIKE ?
             LIMIT 20"
        );
        $stmt->execute([$like,$like,$like,$like]);
        $results['athletes'] = $stmt->fetchAll();
    }

    if (in_array($section, ['all','logs'])) {
        $stmt = $db->prepare(
            "SELECT 'log' as type, a.id, a.action as title,
                    CONCAT(COALESCE(u.name,'—'), ' · ', COALESCE(a.details,'')) as sub,
                    a.created_at as status,
                    a.ip
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.action LIKE ? OR a.details LIKE ? OR a.ip LIKE ?
             ORDER BY a.created_at DESC
             LIMIT 20"
        );
        $stmt->execute([$like,$like,$like]);
        $results['logs'] = $stmt->fetchAll();
    }
}

$totalFound = array_sum(array_map('count', $results));

renderEmpHead('Αναζήτηση');
?>
<body>
<?php renderEmpSidebar('search'); ?>
<div class="emp-main">
<?php renderEmpTopbar('Αναζήτηση'); ?>
<div class="emp-content">

  <div class="section-title">Καθολική Αναζήτηση</div>
  <div class="section-sub">Αναζήτηση σε όλα τα δεδομένα του συστήματος.</div>

  <!-- Search box -->
  <form method="get" class="card" style="padding:1.2rem 1.4rem">
    <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:1;min-width:200px">
        <label>Αναζήτηση (τουλάχιστον 2 χαρακτήρες)</label>
        <div class="search-input-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="form-control" placeholder="Όνομα, email, IP, ενέργεια…"
                 value="<?= h($q) ?>" autofocus style="font-size:1rem">
        </div>
      </div>
      <div class="form-group" style="margin:0;min-width:150px">
        <label>Αναζήτηση σε</label>
        <select name="section" class="form-control">
          <option value="all"      <?= $section==='all'     ?'selected':'' ?>>Παντού</option>
          <option value="schools"  <?= $section==='schools' ?'selected':'' ?>>Σχολές</option>
          <option value="users"    <?= $section==='users'   ?'selected':'' ?>>Χρήστες</option>
          <option value="athletes" <?= $section==='athletes'?'selected':'' ?>>Αθλητές</option>
          <option value="logs"     <?= $section==='logs'    ?'selected':'' ?>>Audit Log</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary" style="height:42px">
        <i class="fa-solid fa-magnifying-glass"></i> Αναζήτηση
      </button>
    </div>
  </form>

  <?php if ($q && strlen($q) < 2): ?>
    <div class="alert alert-warn"><i class="fa-solid fa-triangle-exclamation"></i> Πληκτρολόγησε τουλάχιστον 2 χαρακτήρες.</div>
  <?php endif; ?>

  <?php if ($q && strlen($q) >= 2): ?>
    <div style="margin-bottom:1.1rem;font-size:.9rem;color:var(--muted)">
      <strong style="color:var(--text)"><?= $totalFound ?></strong> αποτελέσματα για «<strong style="color:var(--accent)"><?= h($q) ?></strong>»
    </div>

    <!-- Schools results -->
    <?php if (!empty($results['schools'])): ?>
    <div class="card">
      <div class="card-title"><span class="icon"><i class="fa-solid fa-school"></i></span> Σχολές (<?= count($results['schools']) ?>)</div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>#</th><th>Όνομα</th><th>Email · Πόλη · Τηλ.</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($results['schools'] as $r): ?>
            <tr>
              <td style="color:var(--muted2);font-size:.8rem"><?= $r['id'] ?></td>
              <td style="font-weight:600;font-size:.9rem"><?= h($r['title']) ?></td>
              <td style="font-size:.82rem;color:var(--muted)"><?= h(trim($r['sub'])) ?></td>
              <td>
                <?php
                $cls = match($r['status']) {
                    'active' => 'badge-green', 'trial' => 'badge-blue',
                    'past_due' => 'badge-gold', 'suspended' => 'badge-red', default => 'badge-muted'
                };
                ?>
                <span class="badge <?= $cls ?>"><?= h($r['status']) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:.6rem">
        <a href="schools.php?q=<?= urlencode($q) ?>" class="btn btn-ghost" style="font-size:.82rem">Δες όλα →</a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Users results -->
    <?php if (!empty($results['users'])): ?>
    <div class="card">
      <div class="card-title"><span class="icon"><i class="fa-solid fa-users"></i></span> Χρήστες (<?= count($results['users']) ?>)</div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>#</th><th>Χρήστης</th><th>Email · Role</th><th>Σχολή</th></tr></thead>
          <tbody>
          <?php foreach ($results['users'] as $r): ?>
            <?php
            $roleStyle = match($r['status']) {
                'superadmin'=>'badge-red','employee'=>'badge-purple','maintainer'=>'badge-purple','owner'=>'badge-gold',
                'admin'=>'badge-blue','coach'=>'badge-green', default=>'badge-muted'
            };
            ?>
            <tr>
              <td style="color:var(--muted2);font-size:.8rem"><?= $r['id'] ?></td>
              <td style="font-weight:600;font-size:.88rem"><?= h($r['title']) ?></td>
              <td><span class="badge <?= $roleStyle ?>" style="font-size:.75rem"><?= h($r['status']) ?></span></td>
              <td style="font-size:.82rem;color:var(--muted)"><?= h($r['school_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:.6rem">
        <a href="users.php?q=<?= urlencode($q) ?>" class="btn btn-ghost" style="font-size:.82rem">Δες όλα →</a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Athletes results -->
    <?php if (!empty($results['athletes'])): ?>
    <div class="card">
      <div class="card-title"><span class="icon"><i class="fa-solid fa-person-running"></i></span> Αθλητές (<?= count($results['athletes']) ?>)</div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>#</th><th>Αθλητής</th><th>Άθλημα · Ζώνη · Τηλ.</th><th>Σχολή</th><th>Κατ.</th></tr></thead>
          <tbody>
          <?php foreach ($results['athletes'] as $r): ?>
            <tr>
              <td style="color:var(--muted2);font-size:.8rem"><?= $r['id'] ?></td>
              <td style="font-weight:600;font-size:.88rem"><?= h($r['title']) ?></td>
              <td style="font-size:.8rem;color:var(--muted)"><?= h(trim($r['sub'], ' · ')) ?></td>
              <td style="font-size:.82rem;color:var(--muted)"><?= h($r['school_name'] ?? '—') ?></td>
              <td><span class="badge <?= $r['status'] ? 'badge-green' : 'badge-red' ?>"><?= $r['status'] ? 'Ενεργός' : 'Ανενεργός' ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:.6rem">
        <a href="athletes.php?q=<?= urlencode($q) ?>" class="btn btn-ghost" style="font-size:.82rem">Δες όλα →</a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Logs results -->
    <?php if (!empty($results['logs'])): ?>
    <div class="card">
      <div class="card-title"><span class="icon"><i class="fa-solid fa-clipboard-list"></i></span> Audit Log (<?= count($results['logs']) ?>)</div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>#</th><th>Ενέργεια</th><th>Χρήστης · Λεπτομέρειες</th><th>IP</th><th>Ημερομηνία</th></tr></thead>
          <tbody>
          <?php foreach ($results['logs'] as $r): ?>
            <tr>
              <td style="color:var(--muted2);font-size:.8rem"><?= $r['id'] ?></td>
              <td style="font-weight:600;font-size:.85rem"><?= h($r['title']) ?></td>
              <td style="font-size:.8rem;color:var(--muted)"><?= h($r['sub']) ?></td>
              <td style="font-size:.78rem;font-family:monospace;color:var(--muted2)"><?= h($r['ip'] ?? '') ?></td>
              <td style="font-size:.78rem;color:var(--muted2)"><?= date('d/m/Y H:i', strtotime($r['status'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:.6rem">
        <a href="logs.php?q=<?= urlencode($q) ?>" class="btn btn-ghost" style="font-size:.82rem">Δες όλα →</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($totalFound === 0): ?>
      <div class="card" style="text-align:center;padding:3rem">
        <i class="fa-solid fa-magnifying-glass" style="font-size:2.5rem;color:var(--muted2);display:block;margin-bottom:.75rem"></i>
        <div style="color:var(--muted)">Δεν βρέθηκαν αποτελέσματα για «<?= h($q) ?>»</div>
      </div>
    <?php endif; ?>

  <?php elseif (!$q): ?>
    <!-- Empty state -->
    <div class="card" style="text-align:center;padding:4rem 2rem">
      <i class="fa-solid fa-magnifying-glass" style="font-size:3rem;color:var(--muted2);display:block;margin-bottom:1rem"></i>
      <div style="font-size:1.1rem;font-weight:600;margin-bottom:.5rem">Τι θέλεις να αναζητήσεις;</div>
      <div style="color:var(--muted);font-size:.9rem">
        Αναζήτησε σχολές, χρήστες, αθλητές ή audit log events ταυτόχρονα.
      </div>
    </div>
  <?php endif; ?>

</div>
<?php renderEmpClose(); ?>