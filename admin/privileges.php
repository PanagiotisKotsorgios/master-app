<?php
/**
 * ============================================================
 * admin/privileges.php — Employee Privilege Manager
 * ============================================================
 * Super-admin can select any employee/maintainer user and
 * toggle granular privileges across every area of the
 * employee panel.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf() on POST
 *   ✓ Only allows editing users with role employee|maintainer
 *   ✓ Prepared statements throughout
 *
 * ============================================================
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Error handling
// -----------------------------------------------------------------------------
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function safe_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function renderDebugErrorPage(string $title, string $message, array $context = [], int $httpCode = 500): void
{
    error_log("[privileges.php][{$httpCode}] {$title}: {$message}");
    if (!headers_sent()) {
        http_response_code($httpCode);
    }
    if (function_exists('flash') && function_exists('redirect') && !headers_sent()) {
        flash($title . ': ' . $message, 'danger');
        $url = defined('APP_URL') ? APP_URL . '/admin/privileges.php' : '/admin/privileges.php';
        header('Location: ' . $url);
    } else {
        echo safe_h($title . ': ' . $message);
    }
    exit;
}

// -----------------------------------------------------------------------------
// App bootstrap
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

requireSuperAdmin();

if (!function_exists('getDB')) {
    renderDebugErrorPage(
        'Bootstrap Error',
        'Η function getDB() δεν βρέθηκε.',
        ['Hint' => 'Έλεγξε το includes/config.php'],
        500
    );
}

try {
    $db = getDB();
    if (!$db instanceof PDO) {
        throw new RuntimeException('getDB() did not return a valid PDO instance.');
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log('[DB BOOTSTRAP ERROR] ' . $e->getMessage());
    renderDebugErrorPage(
        'Database Connection Error',
        $e->getMessage(),
        [
            'Exception Class' => get_class($e),
            'File'            => $e->getFile(),
            'Line'            => $e->getLine(),
            'Trace'           => $e->getTraceAsString(),
        ],
        500
    );
}

// -----------------------------------------------------------------------------
// Define all available privileges grouped by resource
// -----------------------------------------------------------------------------
$PRIVILEGE_GROUPS = [
    'schools' => [
        'label' => 'Σχολές',
        'icon'  => 'fa-solid fa-school',
        'color' => 'var(--blue)',
        'privs' => [
            'schools_view'        => ['label' => 'Προβολή',       'icon' => 'fa-solid fa-eye',         'desc' => 'Βλέπει τη λίστα σχολών'],
            'schools_create'      => ['label' => 'Δημιουργία',    'icon' => 'fa-solid fa-plus',        'desc' => 'Δημιουργεί νέες σχολές'],
            'schools_edit'        => ['label' => 'Επεξεργασία',   'icon' => 'fa-solid fa-pen',         'desc' => 'Επεξεργάζεται δεδομένα σχολής'],
            'schools_delete'      => ['label' => 'Διαγραφή',      'icon' => 'fa-solid fa-trash',       'desc' => 'Διαγράφει σχολές'],
            'schools_impersonate' => ['label' => 'Impersonation', 'icon' => 'fa-solid fa-user-secret', 'desc' => 'Συνδέεται ως σχολή'],
        ],
    ],
    'users' => [
        'label' => 'Χρήστες',
        'icon'  => 'fa-solid fa-users',
        'color' => 'var(--accent)',
        'privs' => [
            'users_view'        => ['label' => 'Προβολή',       'icon' => 'fa-solid fa-eye',         'desc' => 'Βλέπει τη λίστα χρηστών'],
            'users_create'      => ['label' => 'Δημιουργία',    'icon' => 'fa-solid fa-plus',        'desc' => 'Δημιουργεί νέους χρήστες'],
            'users_edit'        => ['label' => 'Επεξεργασία',   'icon' => 'fa-solid fa-pen',         'desc' => 'Επεξεργάζεται χρήστες'],
            'users_delete'      => ['label' => 'Διαγραφή',      'icon' => 'fa-solid fa-trash',       'desc' => 'Διαγράφει χρήστες'],
            'users_impersonate' => ['label' => 'Impersonation', 'icon' => 'fa-solid fa-user-secret', 'desc' => 'Συνδέεται ως χρήστης'],
        ],
    ],
    'athletes' => [
        'label' => 'Αθλητές',
        'icon'  => 'fa-solid fa-person-running',
        'color' => 'var(--green)',
        'privs' => [
            'athletes_view'   => ['label' => 'Προβολή',     'icon' => 'fa-solid fa-eye',   'desc' => 'Βλέπει τη λίστα αθλητών'],
            'athletes_create' => ['label' => 'Δημιουργία',  'icon' => 'fa-solid fa-plus',  'desc' => 'Δημιουργεί νέους αθλητές'],
            'athletes_edit'   => ['label' => 'Επεξεργασία', 'icon' => 'fa-solid fa-pen',   'desc' => 'Επεξεργάζεται αθλητές'],
            'athletes_delete' => ['label' => 'Διαγραφή',    'icon' => 'fa-solid fa-trash', 'desc' => 'Διαγράφει αθλητές'],
        ],
    ],
    'payments' => [
        'label' => 'Πληρωμές',
        'icon'  => 'fa-solid fa-credit-card',
        'color' => 'var(--gold)',
        'privs' => [
            'payments_view'   => ['label' => 'Προβολή',     'icon' => 'fa-solid fa-eye',   'desc' => 'Βλέπει πληρωμές σχολών'],
            'payments_create' => ['label' => 'Δημιουργία',  'icon' => 'fa-solid fa-plus',  'desc' => 'Καταχωρεί νέες πληρωμές'],
            'payments_edit'   => ['label' => 'Επεξεργασία', 'icon' => 'fa-solid fa-pen',   'desc' => 'Επεξεργάζεται πληρωμές'],
            'payments_delete' => ['label' => 'Διαγραφή',    'icon' => 'fa-solid fa-trash', 'desc' => 'Διαγράφει πληρωμές'],
        ],
    ],
    'backups' => [
        'label' => 'Backups',
        'icon'  => 'fa-solid fa-database',
        'color' => 'var(--teal,#2dc6c6)',
        'privs' => [
            'backups_view'   => ['label' => 'Προβολή',    'icon' => 'fa-solid fa-eye',   'desc' => 'Βλέπει λίστα backups'],
            'backups_create' => ['label' => 'Δημιουργία', 'icon' => 'fa-solid fa-plus',  'desc' => 'Δημιουργεί νέο backup'],
            'backups_delete' => ['label' => 'Διαγραφή',   'icon' => 'fa-solid fa-trash', 'desc' => 'Διαγράφει backups'],
        ],
    ],
    'system' => [
        'label' => 'Σύστημα',
        'icon'  => 'fa-solid fa-server',
        'color' => 'var(--muted,#8892b0)',
        'privs' => [
            'logs_view'     => ['label' => 'Audit Log',     'icon' => 'fa-solid fa-clipboard-list',  'desc' => 'Βλέπει audit log ενεργειών'],
            'search_access' => ['label' => 'Αναζήτηση',     'icon' => 'fa-solid fa-magnifying-glass','desc' => 'Πρόσβαση στη global αναζήτηση'],
            'health_view'   => ['label' => 'System Health', 'icon' => 'fa-solid fa-heart-pulse',     'desc' => 'Βλέπει μετρικές υγείας συστήματος'],
        ],
    ],

    'analytics' => [
        'label' => 'Analytics',
        'icon'  => 'fa-solid fa-chart-line',
        'color' => 'var(--blue,#58a6ff)',
        'privs' => [
            'analytics_view'   => ['label' => 'Προβολή Analytics',  'icon' => 'fa-solid fa-chart-line',   'desc' => 'Βλέπει πλήρη platform analytics (γραφήματα, KPIs, στατιστικά)'],
            'analytics_export' => ['label' => 'Εξαγωγή Analytics',  'icon' => 'fa-solid fa-file-export',  'desc' => 'Εξαγωγή analytics σε CSV / PDF / Print'],
        ],
    ],
    'exports' => [
        'label' => 'Εξαγωγές Δεδομένων',
        'icon'  => 'fa-solid fa-file-export',
        'color' => 'var(--green,#2dc653)',
        'privs' => [
            'export_schools'  => ['label' => 'Export Σχολές',   'icon' => 'fa-solid fa-school',          'desc' => 'Εξαγωγή λίστας σχολών σε CSV/PDF/Print'],
            'export_users'    => ['label' => 'Export Χρήστες',  'icon' => 'fa-solid fa-users',           'desc' => 'Εξαγωγή λίστας χρηστών σε CSV/PDF/Print'],
            'export_athletes' => ['label' => 'Export Αθλητές',  'icon' => 'fa-solid fa-person-running',  'desc' => 'Εξαγωγή λίστας αθλητών σε CSV/PDF/Print'],
            'export_payments' => ['label' => 'Export Πληρωμές', 'icon' => 'fa-solid fa-credit-card',     'desc' => 'Εξαγωγή λίστας πληρωμών σε CSV/PDF/Print'],
        ],
    ],
];

// Flat list of all priv keys
$ALL_PRIV_KEYS = [];
foreach ($PRIVILEGE_GROUPS as $group) {
    foreach ($group['privs'] as $key => $_meta) {
        $ALL_PRIV_KEYS[] = $key;
    }
}

// -----------------------------------------------------------------------------
// POST — save privileges
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('verifyCsrf')) {
        renderDebugErrorPage(
            'CSRF Bootstrap Error',
            'Η function verifyCsrf() δεν βρέθηκε.',
            [],
            500
        );
    }

    verifyCsrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_privileges') {
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId < 1) {
            if (function_exists('flash') && function_exists('redirect')) {
                flash('Μη έγκυρος χρήστης.', 'danger');
                redirect(APP_URL . '/admin/privileges.php');
            }

            renderDebugErrorPage('Validation Error', 'Μη έγκυρος χρήστης.', ['user_id' => $userId], 400);
        }

        $uStmt = $db->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([$userId]);
        $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser || !in_array($targetUser['role'], ['employee', 'maintainer'], true)) {
            if (function_exists('flash') && function_exists('redirect')) {
                flash('Ο χρήστης δεν είναι employee ή maintainer.', 'danger');
                redirect(APP_URL . '/admin/privileges.php');
            }

            renderDebugErrorPage(
                'Validation Error',
                'Ο χρήστης δεν είναι employee ή maintainer.',
                ['user_id' => $userId, 'target_user' => $targetUser],
                400
            );
        }

        $setCols = [];
        $updateVals = [];

        foreach ($ALL_PRIV_KEYS as $key) {
            $setCols[] = "`{$key}` = ?";
            $updateVals[] = isset($_POST['priv'][$key]) ? 1 : 0;
        }

        $sql = "INSERT INTO employee_privileges (user_id, " . implode(', ', array_map(fn($k) => "`{$k}`", $ALL_PRIV_KEYS)) . ")
                VALUES (?, " . implode(', ', array_fill(0, count($ALL_PRIV_KEYS), '?')) . ")
                ON DUPLICATE KEY UPDATE " . implode(', ', $setCols);

        $insertVals = [$userId];
        foreach ($ALL_PRIV_KEYS as $key) {
            $insertVals[] = isset($_POST['priv'][$key]) ? 1 : 0;
        }

        $executeVals = array_merge($insertVals, $updateVals);

        $stmt = $db->prepare($sql);
        $ok   = $stmt->execute($executeVals);

        if (!$ok) {
            renderDebugErrorPage(
                'Database Save Error',
                'Απέτυχε η αποθήκευση δικαιωμάτων.',
                [
                    'SQL'         => $sql,
                    'InsertVals'  => $insertVals,
                    'UpdateVals'  => $updateVals,
                    'ExecuteVals' => $executeVals,
                    'ErrorInfo'   => $stmt->errorInfo(),
                ],
                500
            );
        }

        try {
            $logStmt = $db->prepare("
                INSERT INTO audit_log (user_id, action, details, created_at)
                VALUES (?, 'employee_privileges_updated', ?, NOW())
            ");

            $currentUserId = 0;
            if (function_exists('currentUser')) {
                $current = currentUser();
                $currentUserId = (int)($current['id'] ?? 0);
            }

            $logStmt->execute([
                $currentUserId,
                json_encode(['target_user_id' => $userId], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('[AUDIT LOG ERROR] ' . $e->getMessage());
        }

        if (function_exists('flash') && function_exists('redirect')) {
            flash('Τα δικαιώματα αποθηκεύτηκαν!');
            redirect(APP_URL . '/admin/privileges.php?user_id=' . $userId);
        }

        renderDebugErrorPage(
            'Post-save Redirect Error',
            'Η αποθήκευση έγινε αλλά λείπουν helper functions για redirect/flash.',
            ['user_id' => $userId],
            500
        );
    }
}

// -----------------------------------------------------------------------------
// Load employee users
// -----------------------------------------------------------------------------
$employees = $db->query(
    "SELECT u.id, u.name, u.email, u.role, u.active,
            (SELECT COUNT(*) FROM employee_privileges ep WHERE ep.user_id = u.id) AS has_row
     FROM users u
     WHERE u.role IN ('employee','maintainer')
     ORDER BY u.name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

if (!is_array($employees)) {
    renderDebugErrorPage(
        'Data Load Error',
        'Το query για employees δεν επέστρεψε σωστά δεδομένα.',
        ['employees' => $employees],
        500
    );
}

// Selected employee
$selectedId   = (int)($_GET['user_id'] ?? 0);
$selectedUser = null;
$currentPrivs = [];

if ($selectedId > 0) {
    foreach ($employees as $employee) {
        if ((int)$employee['id'] === $selectedId) {
            $selectedUser = $employee;
            break;
        }
    }

    if (!$selectedUser) {
        renderDebugErrorPage(
            'Selected User Not Found',
            'Το user_id δόθηκε στο URL αλλά δεν βρέθηκε στη λίστα employees.',
            [
                'selectedId' => $selectedId,
                'GET'        => $_GET,
            ],
            404
        );
    }

    $pStmt = $db->prepare("SELECT * FROM employee_privileges WHERE user_id = ? LIMIT 1");
    $pStmt->execute([$selectedId]);
    $currentPrivs = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// -----------------------------------------------------------------------------
// Render
// -----------------------------------------------------------------------------
if (!function_exists('renderHead') || !function_exists('renderSidebar') || !function_exists('renderTopbar')) {
    renderDebugErrorPage(
        'Layout Bootstrap Error',
        'Λείπουν render helper functions από το layout include.',
        [
            'renderHead'    => function_exists('renderHead') ? 'yes' : 'no',
            'renderSidebar' => function_exists('renderSidebar') ? 'yes' : 'no',
            'renderTopbar'  => function_exists('renderTopbar') ? 'yes' : 'no',
        ],
        500
    );
}

renderHead('Privileges');
?>
<body>
<div class="app-layout">
<?php renderSidebar('admin_privileges'); ?>

<div class="main-content">
<?php renderTopbar('Employee Privileges', 'admin_privileges'); ?>

<div class="page-body">

  <div class="page-title-row" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem">
    <div>
      <h1 style="margin:0;font-size:1.5rem;font-weight:800">
        <i class="fa-solid fa-shield-halved" style="color:var(--accent)"></i>
        Employee Privileges
      </h1>
      <div style="color:var(--text-muted,#8892b0);font-size:.93rem;margin-top:.3rem">
        Δώσε ή αφαίρεσε δικαιώματα σε employees για κάθε ενέργεια του panel.
      </div>
    </div>
  </div>

  <?php
  $flash = function_exists('getFlash') ? getFlash() : null;
  if ($flash):
  ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-danger' ?>" style="margin-bottom:1.2rem">
      <i class="fa-solid <?= ($flash['type'] ?? '') === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
      <?= function_exists('h') ? h($flash['msg'] ?? '') : safe_h($flash['msg'] ?? '') ?>
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:300px 1fr;gap:1.25rem;align-items:start">

    <!-- LEFT: employee list -->
    <div class="card" style="padding:1rem">
      <div style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted,#8892b0);font-weight:800;margin-bottom:.75rem">
        <i class="fa-solid fa-id-badge"></i> Employees
      </div>

      <?php if (empty($employees)): ?>
        <div style="color:var(--text-muted,#8892b0);font-size:.9rem;text-align:center;padding:1.5rem 0">
          Δεν υπάρχουν employees ακόμα.<br>
          <a href="<?= safe_h(APP_URL) ?>/admin/users.php" style="color:var(--accent)">→ Δημιούργησε έναν</a>
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.35rem">
          <?php foreach ($employees as $emp): ?>
            <?php
            $isActive = (int)$emp['id'] === $selectedId;
            $empName  = function_exists('h') ? h($emp['name']) : safe_h($emp['name']);
            $empRole  = function_exists('h') ? h($emp['role']) : safe_h($emp['role']);
            $initial  = function_exists('mb_strtoupper') && function_exists('mb_substr')
                ? mb_strtoupper(mb_substr((string)$emp['name'], 0, 1))
                : strtoupper(substr((string)$emp['name'], 0, 1));
            ?>
            <a href="?user_id=<?= (int)$emp['id'] ?>"
               style="display:flex;align-items:center;gap:.75rem;padding:.7rem .85rem;border-radius:14px;border:1px solid <?= $isActive ? 'rgba(230,57,70,.4)' : 'transparent' ?>;background:<?= $isActive ? 'rgba(230,57,70,.1)' : 'rgba(255,255,255,.03)' ?>;text-decoration:none;transition:.15s ease">
              <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#e63946,#c0303b);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:.9rem;flex-shrink:0">
                <?= safe_h($initial) ?>
              </div>
              <div style="min-width:0;flex:1">
                <div style="font-size:.9rem;font-weight:700;color:#f0f2ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= $empName ?>
                </div>
                <div style="font-size:.75rem;color:var(--text-muted,#8892b0);display:flex;align-items:center;gap:.4rem">
                  <span class="badge <?= $emp['role'] === 'employee' ? 'badge-purple' : 'badge-muted' ?>" style="font-size:.65rem;padding:.15rem .5rem">
                    <?= $empRole ?>
                  </span>
                  <?php if (!(int)$emp['active']): ?>
                    <span class="badge badge-red" style="font-size:.65rem;padding:.15rem .5rem">inactive</span>
                  <?php endif; ?>
                  <?php if (!empty($emp['has_row'])): ?>
                    <span title="Έχει ρυθμίσεις" style="color:var(--green,#2dc653)">
                      <i class="fa-solid fa-circle-check" style="font-size:.7rem"></i>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: privilege editor -->
    <div>
      <?php if (!$selectedUser): ?>
        <div class="card" style="text-align:center;padding:3rem 2rem;color:var(--text-muted,#8892b0)">
          <i class="fa-solid fa-arrow-left" style="font-size:2.5rem;margin-bottom:1rem;display:block;opacity:.4"></i>
          <div style="font-size:1.05rem;font-weight:700;color:#c2cae0;margin-bottom:.4rem">Επίλεξε Employee</div>
          <div style="font-size:.9rem">Κάνε κλικ σε έναν employee αριστερά για να διαχειριστείς τα δικαιώματά του.</div>
        </div>
      <?php else: ?>

        <form method="post" action="<?= safe_h(APP_URL) ?>/admin/privileges.php?user_id=<?= (int)$selectedUser['id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= function_exists('csrf') ? safe_h(csrf()) : '' ?>">
          <input type="hidden" name="_action" value="save_privileges">
          <input type="hidden" name="user_id" value="<?= (int)$selectedUser['id'] ?>">

          <!-- User header -->
          <div class="card" style="margin-bottom:1rem;padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem">
            <div style="display:flex;align-items:center;gap:.85rem">
              <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#e63946,#c0303b);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:1.15rem;flex-shrink:0">
                <?php
                $selInitial = function_exists('mb_strtoupper') && function_exists('mb_substr')
                    ? mb_strtoupper(mb_substr((string)$selectedUser['name'], 0, 1))
                    : strtoupper(substr((string)$selectedUser['name'], 0, 1));
                echo safe_h($selInitial);
                ?>
              </div>
              <div>
                <div style="font-size:1.05rem;font-weight:800;color:#f0f2ff">
                  <?= function_exists('h') ? h($selectedUser['name']) : safe_h($selectedUser['name']) ?>
                </div>
                <div style="font-size:.82rem;color:var(--text-muted,#8892b0)">
                  <?= function_exists('h') ? h($selectedUser['email']) : safe_h($selectedUser['email']) ?> &nbsp;·&nbsp;
                  <span class="badge <?= $selectedUser['role'] === 'employee' ? 'badge-purple' : 'badge-muted' ?>">
                    <?= function_exists('h') ? h($selectedUser['role']) : safe_h($selectedUser['role']) ?>
                  </span>
                </div>
              </div>
            </div>

            <div style="display:flex;gap:.6rem;align-items:center">
              <button type="button" onclick="selectAll(true)" class="btn btn-ghost" style="font-size:.82rem;padding:.5rem .85rem">
                <i class="fa-solid fa-check-double"></i> Επιλογή Όλων
              </button>
              <button type="button" onclick="selectAll(false)" class="btn btn-ghost" style="font-size:.82rem;padding:.5rem .85rem">
                <i class="fa-solid fa-xmark"></i> Αποεπιλογή Όλων
              </button>
              <button type="submit" class="btn btn-primary" style="font-size:.9rem">
                <i class="fa-solid fa-floppy-disk"></i> Αποθήκευση
              </button>
            </div>
          </div>

          <!-- Privilege groups -->
          <?php foreach ($PRIVILEGE_GROUPS as $groupKey => $group): ?>
            <div class="card" style="margin-bottom:1rem;padding:1.2rem 1.3rem">

              <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:1rem;padding-bottom:.85rem;border-bottom:1px solid rgba(255,255,255,.06)">
                <div style="width:34px;height:34px;border-radius:10px;background:rgba(255,255,255,.05);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <i class="<?= safe_h($group['icon']) ?>" style="color:<?= safe_h($group['color']) ?>"></i>
                </div>
                <div>
                  <div style="font-size:1rem;font-weight:800;color:#f0f2ff">
                    <?= function_exists('h') ? h($group['label']) : safe_h($group['label']) ?>
                  </div>
                </div>
                <button type="button"
                        onclick="toggleGroup('<?= safe_h($groupKey) ?>')"
                        style="margin-left:auto;background:none;border:1px solid rgba(255,255,255,.1);color:#c2cae0;border-radius:8px;padding:.3rem .7rem;cursor:pointer;font-size:.78rem;font-weight:700">
                  Εναλλαγή
                </button>
              </div>

              <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.75rem">
                <?php foreach ($group['privs'] as $privKey => $privMeta): ?>
                  <?php $checked = !empty($currentPrivs[$privKey]); ?>

                  <label class="priv-card group-<?= safe_h($groupKey) ?> <?= $checked ? 'priv-on' : '' ?>"
                         style="display:flex;align-items:flex-start;gap:.75rem;padding:.85rem 1rem;border-radius:14px;cursor:pointer;transition:.15s ease;user-select:none">

                    <input type="checkbox"
                           name="priv[<?= safe_h($privKey) ?>]"
                           value="1"
                           class="priv-check"
                           <?= $checked ? 'checked' : '' ?>
                           style="display:none"
                           onchange="updateCardStyle(this.closest('.priv-card'))">

                    <div style="width:32px;height:32px;border-radius:9px;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.05rem">
                      <i class="<?= safe_h($privMeta['icon']) ?>" style="color:<?= safe_h($group['color']) ?>;font-size:.9rem"></i>
                    </div>

                    <div style="flex:1;min-width:0">
                      <div style="font-size:.88rem;font-weight:700;color:#f0f2ff;margin-bottom:.2rem">
                        <?= function_exists('h') ? h($privMeta['label']) : safe_h($privMeta['label']) ?>
                      </div>
                      <div style="font-size:.76rem;color:var(--text-muted,#8892b0);line-height:1.45">
                        <?= function_exists('h') ? h($privMeta['desc']) : safe_h($privMeta['desc']) ?>
                      </div>
                    </div>

                    <div class="priv-indicator" style="width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.2rem;transition:.15s ease">
                      <?php if ($checked): ?>
                        <i class="fa-solid fa-check" style="font-size:.55rem;color:#fff"></i>
                      <?php endif; ?>
                    </div>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-top:.5rem">
            <a href="<?= safe_h(APP_URL) ?>/admin/privileges.php" class="btn btn-ghost">Ακύρωση</a>
            <button type="submit" class="btn btn-primary" style="padding:.75rem 1.5rem">
              <i class="fa-solid fa-floppy-disk"></i> Αποθήκευση Δικαιωμάτων
            </button>
          </div>

        </form>
      <?php endif; ?>
    </div>

  </div>
</div>

<style>
.priv-card {
  border: 1px solid rgba(255,255,255,.07) !important;
  background: rgba(255,255,255,.03) !important;
}

.priv-card:hover {
  border-color: rgba(230,57,70,.25) !important;
  background: rgba(230,57,70,.05) !important;
}

.priv-card.priv-on {
  border-color: rgba(230,57,70,.4) !important;
  background: rgba(230,57,70,.1) !important;
}

.priv-card .priv-indicator {
  border: 2px solid rgba(255,255,255,.15) !important;
  background: transparent !important;
}

.priv-card.priv-on .priv-indicator {
  border-color: rgba(230,57,70,.8) !important;
  background: var(--red, #e63946) !important;
}
</style>

<script>
function updateCardStyle(label) {
  if (!label) return;

  var cb = label.querySelector('.priv-check');
  var ind = label.querySelector('.priv-indicator');
  if (!cb || !ind) return;

  var on = cb.checked;
  label.classList.toggle('priv-on', on);
  ind.innerHTML = on
    ? '<i class="fa-solid fa-check" style="font-size:.55rem;color:#fff"></i>'
    : '';
}

function selectAll(on) {
  document.querySelectorAll('.priv-card').forEach(function(label) {
    var cb = label.querySelector('.priv-check');
    if (!cb) return;
    cb.checked = on;
    updateCardStyle(label);
  });
}

function toggleGroup(groupKey) {
  var cards = document.querySelectorAll('.group-' + groupKey);
  if (!cards.length) return;

  var allOn = Array.from(cards).every(function(label) {
    var cb = label.querySelector('.priv-check');
    return cb && cb.checked;
  });

  cards.forEach(function(label) {
    var cb = label.querySelector('.priv-check');
    if (!cb) return;
    cb.checked = !allOn;
    updateCardStyle(label);
  });
}

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.priv-card').forEach(function(label) {
    updateCardStyle(label);
  });
});
</script>

</div>
</div>
</body>
</html>