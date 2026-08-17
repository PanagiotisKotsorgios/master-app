<?php
/**
 * ============================================================
 * admin/federations.php — Διαχείριση Ομοσπονδιών (Super Admin)
 * ============================================================
 * PURPOSE:
 *   Διαχείριση ομοσπονδιών που φέρνουν σχολές στην πλατφόρμα.
 *   Παρακολούθηση:
 *     - Ποιες σχολές ανήκουν σε κάθε ομοσπονδία
 *     - Ποσοστό προμήθειας που κρατά η ομοσπονδία
 *     - Σύνολο προμηθειών ανά ομοσπονδία
 *     - Καθαρά έσοδα μετά από προμήθειες (κέρδος/ζημιά)
 *     - Ποια σωματεία έφερε η ομοσπονδία (counter)
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf()
 *   ✓ Prepared statements παντού
 *   ✓ h() output escaping
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── Migration: δημιουργεί πίνακες αν δεν υπάρχουν ──────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS `federations` (
      `id`              INT NOT NULL AUTO_INCREMENT,
      `name`            VARCHAR(200) NOT NULL,
      `contact_name`    VARCHAR(200) DEFAULT NULL,
      `contact_email`   VARCHAR(200) DEFAULT NULL,
      `contact_phone`   VARCHAR(50)  DEFAULT NULL,
      `commission_pct`  DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Ποσοστό % που κρατά η ομοσπονδία',
      `notes`           TEXT DEFAULT NULL,
      `active`          TINYINT(1) NOT NULL DEFAULT 1,
      `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$db->exec("
    ALTER TABLE schools
    ADD COLUMN IF NOT EXISTS `federation_id`       INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `federation_ref_code` VARCHAR(100) DEFAULT NULL
");

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';

    // ── Δημιουργία νέας ομοσπονδίας ──
    if ($action === 'add_federation') {
        $name    = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_name'] ?? '');
        $email   = trim($_POST['contact_email'] ?? '');
        $phone   = trim($_POST['contact_phone'] ?? '');
        $pct     = max(0, min(100, (float)($_POST['commission_pct'] ?? 0)));
        $notes   = trim($_POST['notes'] ?? '');
        if ($name) {
            $db->prepare("INSERT INTO federations (name,contact_name,contact_email,contact_phone,commission_pct,notes) VALUES (?,?,?,?,?,?)")
               ->execute([$name, $contact, $email, $phone, $pct, $notes]);
            auditLog('add_federation', 'system', 0, "Ομοσπονδία: $name ($pct%)");
            flash('✅ Ομοσπονδία προστέθηκε!');
        }
        redirect(APP_URL . '/admin/federations.php');
    }

    // ── Ενημέρωση ομοσπονδίας ──
    if ($action === 'edit_federation') {
        $id  = (int)$_POST['id'];
        $pct = max(0, min(100, (float)($_POST['commission_pct'] ?? 0)));
        $db->prepare("UPDATE federations SET name=?,contact_name=?,contact_email=?,contact_phone=?,commission_pct=?,notes=?,active=? WHERE id=?")
           ->execute([
               trim($_POST['name']          ?? ''),
               trim($_POST['contact_name']  ?? ''),
               trim($_POST['contact_email'] ?? ''),
               trim($_POST['contact_phone'] ?? ''),
               $pct,
               trim($_POST['notes'] ?? ''),
               isset($_POST['active']) ? 1 : 0,
               $id,
           ]);
        auditLog('edit_federation', 'system', 0, "ID: $id");
        flash('✅ Ομοσπονδία ενημερώθηκε!');
        redirect(APP_URL . '/admin/federations.php');
    }

    // ── Ανάθεση σχολής σε ομοσπονδία ──
    if ($action === 'assign_school') {
        $schoolId = (int)$_POST['school_id'];
        $fedId    = (int)$_POST['federation_id'];
        $refCode  = trim($_POST['ref_code'] ?? '');
        $db->prepare("UPDATE schools SET federation_id=?,federation_ref_code=? WHERE id=?")
           ->execute([$fedId ?: null, $refCode ?: null, $schoolId]);
        auditLog('assign_school_to_federation', 'school', $schoolId, "Federation ID: $fedId");
        flash('✅ Σχολή ανατέθηκε!');
        redirect(APP_URL . '/admin/federations.php?tab=schools');
    }

    // ── Αφαίρεση σχολής από ομοσπονδία ──
    if ($action === 'unassign_school') {
        $schoolId = (int)$_POST['school_id'];
        $db->prepare("UPDATE schools SET federation_id=NULL, federation_ref_code=NULL WHERE id=?")
           ->execute([$schoolId]);
        auditLog('unassign_school_from_federation', 'school', $schoolId, '');
        flash('Σχολή αφαιρέθηκε από ομοσπονδία.');
        redirect(APP_URL . '/admin/federations.php?tab=schools');
    }

    redirect(APP_URL . '/admin/federations.php');
}

// ── Load data ─────────────────────────────────────────────────────────────────
$editId  = (int)($_GET['edit'] ?? 0);
$editFed = null;
if ($editId) {
    $s = $db->prepare("SELECT * FROM federations WHERE id=?"); $s->execute([$editId]);
    $editFed = $s->fetch();
}

$activeTab = trim($_GET['tab'] ?? 'list');

// Ομοσπονδίες με στατιστικά
$federations = $db->query("
    SELECT f.*,
        COUNT(DISTINCT s.id) AS school_count,
        COALESCE(SUM(spp.amount), 0) AS total_revenue,
        COALESCE(SUM(spp.amount * f.commission_pct / 100), 0) AS total_commission,
        COALESCE(SUM(spp.amount * (1 - f.commission_pct/100)), 0) AS total_net
    FROM federations f
    LEFT JOIN schools s ON s.federation_id = f.id AND s.active = 1
    LEFT JOIN school_plan_payments spp ON spp.school_id = s.id
    GROUP BY f.id
    ORDER BY f.name
")->fetchAll();

// Σχολές χωρίς ομοσπονδία
$unassigned = $db->query("
    SELECT id, name, email FROM schools
    WHERE (federation_id IS NULL) AND active=1
    ORDER BY name
")->fetchAll();

// Σχολές με ομοσπονδία
$assigned = $db->query("
    SELECT s.id, s.name, s.email, s.federation_ref_code,
           f.id AS fed_id, f.name AS fed_name, f.commission_pct
    FROM schools s
    JOIN federations f ON f.id = s.federation_id
    WHERE s.active = 1
    ORDER BY f.name, s.name
")->fetchAll();

// Για dropdown ανάθεσης
$allSchools     = $db->query("SELECT id,name FROM schools WHERE active=1 ORDER BY name")->fetchAll();
$allFederations = $db->query("SELECT id,name FROM federations WHERE active=1 ORDER BY name")->fetchAll();

// Συνολικά οικονομικά αριθμοί
$totalFedCommission = array_sum(array_column($federations, 'total_commission'));
$totalFedRevenue    = array_sum(array_column($federations, 'total_revenue'));
$totalNetAfterFed   = array_sum(array_column($federations, 'total_net'));
$allRevenue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM school_plan_payments")->fetchColumn();
$nonFedRevenue = $allRevenue - $totalFedRevenue;

renderHead('Ομοσπονδίες');
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
.stat-card { border-radius: 14px !important; padding: 1.35rem !important; }
.stat-card .stat-val { font-size: 2.1rem !important; font-weight: 800 !important; }
.stat-card .stat-lbl { font-size: .82rem !important; }
.stat-card .stat-icon { width: 46px !important; height: 46px !important; font-size: 1.3rem !important; border-radius: 12px !important; }
.badge { font-size: .72rem !important; padding: .22rem .6rem !important; border-radius: 50px !important; font-weight: 700 !important; }
.btn { font-size: .875rem !important; padding: .5rem 1.05rem !important; border-radius: 9px !important; font-weight: 500 !important; }
.btn-sm { font-size: .8rem !important; padding: .32rem .65rem !important; }
.form-label { font-size: .82rem !important; font-weight: 600 !important; color: var(--muted); }
.form-control { font-size: .88rem !important; padding: .58rem .8rem !important; border-radius: 9px !important; }
.form-group { gap: .4rem !important; }
.tab-bar { display:flex; gap:.5rem; margin-bottom:1.2rem; flex-wrap:wrap; }
.tab-bar button { padding:.5rem 1.1rem; border-radius:9px; border:1.5px solid var(--border); background:transparent;
    cursor:pointer; font-size:.88rem; font-weight:700; color:var(--muted); transition:all .15s; }
.tab-bar button.active { background:rgba(240,165,0,.12); border-color:var(--gold); color:var(--gold); }
.fed-card { background:var(--card); border:1.5px solid var(--border); border-radius:16px; padding:1.25rem; margin-bottom:1rem; }
.fed-card:hover { border-color:rgba(240,165,0,.3); }
.profit-pos { color:#2dc653; }
.profit-neg { color:#e63946; }
@media(max-width:768px){ .page-body { padding: 1rem !important; } }
</style>

<body><div class="app-layout">
<?php renderSidebar('admin_federations'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-handshake"></i> Ομοσπονδίες'); ?>
<div class="page-body">

<!-- ── KPI Cards ── -->
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-handshake"></i></div>
        <div class="stat-val text-gold"><?= count($federations) ?></div>
        <div class="stat-lbl">Σύνολο Ομοσπονδιών</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-school"></i></div>
        <div class="stat-val"><?= count($assigned) ?></div>
        <div class="stat-lbl">Σχολές από Ομοσπονδίες</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-red"><i class="fa-solid fa-percent"></i></div>
        <div class="stat-val text-red"><?= formatMoney($totalFedCommission) ?></div>
        <div class="stat-lbl">Σύνολο Προμηθειών Ομοσπονδιών</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-euro-sign"></i></div>
        <div class="stat-val text-green"><?= formatMoney($totalNetAfterFed + $nonFedRevenue) ?></div>
        <div class="stat-lbl">Καθαρά Έσοδα (μετά προμήθειες)</div>
    </div>
</div>

<!-- ── Tab bar ── -->
<div class="tab-bar">
    <button onclick="switchFedTab('list',this)" id="tb-list" class="<?= $activeTab==='list'?'active':'' ?>">
        <i class="fa-solid fa-list"></i> Ομοσπονδίες
    </button>
    <button onclick="switchFedTab('schools',this)" id="tb-schools" class="<?= $activeTab==='schools'?'active':'' ?>">
        <i class="fa-solid fa-school"></i> Σχολές
    </button>
    <button onclick="switchFedTab('economics',this)" id="tb-economics" class="<?= $activeTab==='economics'?'active':'' ?>">
        <i class="fa-solid fa-chart-bar"></i> Οικονομικά
    </button>
    <button onclick="switchFedTab('add',this)" id="tb-add" class="<?= $editFed||$activeTab==='add'?'active':'' ?>">
        <i class="fa-solid fa-plus"></i> <?= $editFed ? 'Επεξεργασία' : 'Νέα Ομοσπονδία' ?>
    </button>
</div>

<!-- ═══════════════════ TAB: LIST ════════════════════════════ -->
<div id="fedtab-list" style="<?= $activeTab!=='list'?'display:none':'' ?>">
<?php if (empty($federations)): ?>
<div class="card" style="text-align:center;padding:2.5rem;color:var(--muted)">
    <i class="fa-solid fa-handshake" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.75rem"></i>
    Δεν υπάρχουν ομοσπονδίες ακόμα. Προσθέστε την πρώτη!
</div>
<?php else: ?>
<?php foreach ($federations as $f): ?>
<div class="fed-card">
    <div class="d-flex jc-between ai-center flex-wrap gap-sm mb-2">
        <div class="d-flex ai-center gap-sm">
            <div style="width:38px;height:38px;border-radius:10px;background:rgba(240,165,0,.12);
                        border:1px solid rgba(240,165,0,.2);display:flex;align-items:center;
                        justify-content:center;flex-shrink:0">
                <i class="fa-solid fa-handshake" style="color:#f0a500;font-size:.9rem"></i>
            </div>
            <div>
                <div class="fw-600" style="font-size:.97rem"><?= h($f['name']) ?></div>
                <?php if ($f['contact_email']): ?>
                <div class="text-muted text-xs"><?= h($f['contact_email']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex ai-center gap-sm">
            <?php if (!$f['active']): ?>
            <span class="badge badge-inactive">Ανενεργό</span>
            <?php endif; ?>
            <a href="?edit=<?= $f['id'] ?>" class="btn btn-ghost btn-sm">
                <i class="fa-solid fa-pen-to-square"></i> Επεξεργασία
            </a>
        </div>
    </div>

    <div class="grid grid-4 text-sm" style="gap:.5rem">
        <div style="background:rgba(255,255,255,.03);border-radius:8px;padding:.6rem .8rem">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem">Σχολές</div>
            <div class="fw-600" style="font-size:1.2rem"><?= $f['school_count'] ?></div>
        </div>
        <div style="background:rgba(255,255,255,.03);border-radius:8px;padding:.6rem .8rem">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem">Προμήθεια %</div>
            <div class="fw-600 text-red" style="font-size:1.2rem"><?= number_format($f['commission_pct'], 1) ?>%</div>
        </div>
        <div style="background:rgba(255,255,255,.03);border-radius:8px;padding:.6rem .8rem">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem">Σύνολο Προμηθειών</div>
            <div class="fw-600 text-red" style="font-size:1.2rem"><?= formatMoney($f['total_commission']) ?></div>
        </div>
        <div style="background:rgba(255,255,255,.03);border-radius:8px;padding:.6rem .8rem">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem">Καθαρά Έσοδα</div>
            <div class="fw-600 <?= $f['total_net'] >= 0 ? 'text-green' : 'text-red' ?>" style="font-size:1.2rem"><?= formatMoney($f['total_net']) ?></div>
        </div>
    </div>

    <?php if ($f['notes']): ?>
    <div class="text-muted text-xs" style="margin-top:.6rem;background:rgba(255,255,255,.03);border-radius:6px;padding:.45rem .7rem">
        <?= h($f['notes']) ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<!-- ═══════════════════ TAB: SCHOOLS ════════════════════════ -->
<div id="fedtab-schools" style="<?= $activeTab!=='schools'?'display:none':'' ?>">

<!-- Assign school form -->
<div class="card mb-3">
    <div class="card-title mb-2"><i class="fa-solid fa-link"></i> Ανάθεση Σχολής σε Ομοσπονδία</div>
    <form method="POST">
        <input type="hidden" name="_action" value="assign_school">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <div class="form-row col-3">
            <div class="form-group">
                <label class="form-label">Σχολή</label>
                <select name="school_id" class="form-control">
                    <?php foreach ($allSchools as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Ομοσπονδία</label>
                <select name="federation_id" class="form-control">
                    <option value="">— Καμία —</option>
                    <?php foreach ($allFederations as $f): ?>
                    <option value="<?= $f['id'] ?>"><?= h($f['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Κωδικός Αναφοράς (προαιρετικό)</label>
                <input name="ref_code" class="form-control" placeholder="π.χ. FED-2024-001">
            </div>
        </div>
        <button type="submit" class="btn btn-primary mt-2">
            <i class="fa-solid fa-link"></i> Ανάθεση
        </button>
    </form>
</div>

<!-- Assigned schools table -->
<div class="card p-0 mb-3">
    <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title">Σχολές ανά Ομοσπονδία <span class="text-muted text-sm">(<?= count($assigned) ?> σχολές)</span></div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr>
            <th>Σχολή</th>
            <th>Ομοσπονδία</th>
            <th>Προμήθεια %</th>
            <th>Κωδικός Αναφοράς</th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($assigned as $a): ?>
        <tr>
            <td class="fw-600"><?= h($a['name']) ?></td>
            <td>
                <span class="badge" style="background:rgba(240,165,0,.12);color:#f0a500"><?= h($a['fed_name']) ?></span>
            </td>
            <td class="text-red"><?= number_format($a['commission_pct'], 1) ?>%</td>
            <td class="text-muted text-xs"><?= h($a['federation_ref_code'] ?? '—') ?></td>
            <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Αφαίρεση σχολής από ομοσπονδία;')">
                    <input type="hidden" name="_action" value="unassign_school">
                    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                    <input type="hidden" name="school_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" title="Αφαίρεση">
                        <i class="fa-solid fa-unlink" style="color:var(--danger)"></i>
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($assigned)): ?>
        <tr><td colspan="5" class="text-center text-muted" style="padding:1.5rem">Καμία σχολή δεν έχει ανατεθεί</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</div>

<!-- Unassigned schools -->
<?php if ($unassigned): ?>
<div class="card p-0">
    <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title text-muted">Σχολές χωρίς Ομοσπονδία <span class="text-sm">(<?= count($unassigned) ?>)</span></div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>Σχολή</th><th>Email</th></tr></thead>
        <tbody>
        <?php foreach ($unassigned as $u): ?>
        <tr>
            <td class="fw-600"><?= h($u['name']) ?></td>
            <td class="text-muted text-xs"><?= h($u['email']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
</div>

<!-- ═══════════════════ TAB: ECONOMICS ═══════════════════════ -->
<div id="fedtab-economics" style="<?= $activeTab!=='economics'?'display:none':'' ?>">

<!-- Summary card -->
<div class="card mb-3">
    <div class="card-title mb-3"><i class="fa-solid fa-chart-bar" style="color:#f0a500"></i> Οικονομική Ανάλυση Ομοσπονδιών</div>
    <div class="grid grid-3" style="gap:1rem;margin-bottom:1.5rem">
        <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:12px;padding:1rem;text-align:center">
            <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#8892b0;margin-bottom:.4rem">Σύνολο Εσόδων (Ομοσπονδίες)</div>
            <div style="font-size:1.8rem;font-weight:800;color:#f0f2ff"><?= formatMoney($totalFedRevenue) ?></div>
        </div>
        <div style="background:rgba(230,57,70,.07);border:1px solid rgba(230,57,70,.2);border-radius:12px;padding:1rem;text-align:center">
            <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#e63946;margin-bottom:.4rem">Σύνολο Προμηθειών (−)</div>
            <div style="font-size:1.8rem;font-weight:800;color:#e63946">−<?= formatMoney($totalFedCommission) ?></div>
        </div>
        <div style="background:rgba(45,198,83,.07);border:1px solid rgba(45,198,83,.2);border-radius:12px;padding:1rem;text-align:center">
            <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#2dc653;margin-bottom:.4rem">Καθαρά Κέρδη από Ομοσπονδίες</div>
            <div style="font-size:1.8rem;font-weight:800;color:#2dc653"><?= formatMoney($totalNetAfterFed) ?></div>
        </div>
    </div>

    <div class="grid grid-2" style="gap:1rem;margin-bottom:1.5rem">
        <div style="background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:1rem;text-align:center">
            <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#3b82f6;margin-bottom:.4rem">Έσοδα χωρίς Ομοσπονδία</div>
            <div style="font-size:1.6rem;font-weight:800;color:#93c5fd"><?= formatMoney($nonFedRevenue) ?></div>
        </div>
        <div style="background:rgba(45,198,83,.07);border:1px solid rgba(45,198,83,.2);border-radius:12px;padding:1rem;text-align:center">
            <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#2dc653;margin-bottom:.4rem">Συνολικά Καθαρά Έσοδα</div>
            <div style="font-size:1.6rem;font-weight:800;color:#2dc653"><?= formatMoney($totalNetAfterFed + $nonFedRevenue) ?></div>
        </div>
    </div>
</div>

<!-- Per-federation breakdown table -->
<div class="card p-0">
    <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title">Ανάλυση ανά Ομοσπονδία</div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr>
            <th>Ομοσπονδία</th>
            <th>Σχολές</th>
            <th>Προμήθεια %</th>
            <th>Σύνολο Πληρωμών</th>
            <th>Προμήθεια (−)</th>
            <th>Καθαρά Κέρδη</th>
            <th>Κέρδος / Ζημιά</th>
        </tr></thead>
        <tbody>
        <?php foreach ($federations as $f):
            $profitPct = $f['total_revenue'] > 0
                ? round(100 * $f['total_net'] / $f['total_revenue'], 1) : 0;
        ?>
        <tr>
            <td class="fw-600"><?= h($f['name']) ?></td>
            <td><?= $f['school_count'] ?></td>
            <td class="text-red"><?= number_format($f['commission_pct'], 1) ?>%</td>
            <td class="fw-600"><?= formatMoney($f['total_revenue']) ?></td>
            <td class="text-red">−<?= formatMoney($f['total_commission']) ?></td>
            <td class="fw-600 <?= $f['total_net'] >= 0 ? 'text-green' : 'text-red' ?>"><?= formatMoney($f['total_net']) ?></td>
            <td>
                <?php if ($f['total_revenue'] > 0): ?>
                <span class="badge <?= $profitPct >= 70 ? 'badge-paid' : ($profitPct >= 40 ? 'badge-pending' : 'badge-inactive') ?>">
                    <?= $profitPct ?>%
                </span>
                <?php else: ?>
                <span class="text-muted text-xs">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($federations)): ?>
        <tr><td colspan="7" class="text-center text-muted" style="padding:1.5rem">Δεν υπάρχουν ομοσπονδίες</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</div>
</div>

<!-- ═══════════════════ TAB: ADD/EDIT ════════════════════════ -->
<div id="fedtab-add" style="<?= (!$editFed && $activeTab!=='add')?'display:none':'' ?>">
<div class="card" style="max-width:640px">
    <div class="card-title mb-3">
        <i class="fa-solid fa-<?= $editFed ? 'pen-to-square' : 'plus' ?>"></i>
        <?= $editFed ? 'Επεξεργασία: ' . h($editFed['name']) : 'Νέα Ομοσπονδία' ?>
    </div>
    <form method="POST">
        <input type="hidden" name="_action" value="<?= $editFed ? 'edit_federation' : 'add_federation' ?>">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <?php if ($editFed): ?><input type="hidden" name="id" value="<?= $editFed['id'] ?>"><?php endif; ?>
        <div class="form-row col-2 mb-2">
            <div class="form-group" style="grid-column:1/-1">
                <label class="form-label">Όνομα Ομοσπονδίας *</label>
                <input name="name" class="form-control" required
                       value="<?= h($editFed['name'] ?? '') ?>" placeholder="π.χ. Ομοσπονδία Πολεμικών Τεχνών">
            </div>
            <div class="form-group">
                <label class="form-label">Όνομα Υπεύθυνου</label>
                <input name="contact_name" class="form-control"
                       value="<?= h($editFed['contact_name'] ?? '') ?>" placeholder="Όνομα Επωνύμου">
            </div>
            <div class="form-group">
                <label class="form-label">Email Επικοινωνίας</label>
                <input type="email" name="contact_email" class="form-control"
                       value="<?= h($editFed['contact_email'] ?? '') ?>" placeholder="federation@example.gr">
            </div>
            <div class="form-group">
                <label class="form-label">Τηλέφωνο</label>
                <input name="contact_phone" class="form-control"
                       value="<?= h($editFed['contact_phone'] ?? '') ?>" placeholder="+30 210 0000000">
            </div>
            <div class="form-group">
                <label class="form-label">Ποσοστό Προμήθειας (%) *</label>
                <input type="number" step=".1" min="0" max="100" name="commission_pct" class="form-control" required
                       value="<?= h($editFed['commission_pct'] ?? '0') ?>">
                <span class="form-hint" style="font-size:.75rem;color:var(--muted)">
                    % από κάθε πληρωμή σχολής που πηγαίνει στην ομοσπονδία
                </span>
            </div>
            <?php if ($editFed): ?>
            <div class="form-group" style="align-self:center">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem">
                    <input type="checkbox" name="active" value="1" <?= ($editFed['active'] ?? 1) ? 'checked' : '' ?>>
                    Ενεργή Ομοσπονδία
                </label>
            </div>
            <?php endif; ?>
            <div class="form-group" style="grid-column:1/-1">
                <label class="form-label">Σημειώσεις</label>
                <textarea name="notes" class="form-control" rows="3"
                          placeholder="Εσωτερικές σημειώσεις για τη συνεργασία..."><?= h($editFed['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="d-flex gap-sm">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-floppy-disk"></i> <?= $editFed ? 'Αποθήκευση' : 'Προσθήκη' ?>
            </button>
            <a href="<?= APP_URL ?>/admin/federations.php" class="btn btn-ghost">Ακύρωση</a>
        </div>
    </form>
</div>
</div>

</div></div></div>

<script>
function switchFedTab(name, btn) {
    ['list','schools','economics','add'].forEach(function(t) {
        var el = document.getElementById('fedtab-' + t);
        if (el) el.style.display = (t === name) ? '' : 'none';
    });
    document.querySelectorAll('.tab-bar button').forEach(function(b) {
        b.classList.remove('active');
    });
    if (btn) btn.classList.add('active');
    history.replaceState(null, '', '?tab=' + name);
}

<?php if ($activeTab !== 'list'): ?>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('tb-<?= h($activeTab) ?>') ||
              document.getElementById('tb-<?= $editFed ? 'add' : $activeTab ?>');
    if (btn) switchFedTab('<?= $editFed ? 'add' : h($activeTab) ?>', btn);
});
<?php endif; ?>
</script>
</body></html>
