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
 * admin/coupons.php — Διαχείριση Κουπονιών (Super Admin)
 * ============================================================
 * PURPOSE:
 *   CRUD κουπονιών έκπτωσης.
 *   Υποστηρίζει: ποσοστιαία/σταθερή έκπτωση, max_uses,
 *   ημερομηνία λήξης, φίλτρο πλάνου.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf()
 *   ✓ code: strtoupper + trim (normalization)
 *   ✓ discount_type: whitelist (percent/fixed)
 *   ✓ applies_to_plan: whitelist (basic/pro/any)
 *   ✓ discount_value: (float) + max(0) (αποτρέπει αρνητικές τιμές)
 *   ✓ max_uses: null = unlimited (δεν περιορίζει arbitrarily)
 *   ✓ Prepared statements (dynamic upsert)
 *   ✓ Audit log
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── POST: Ενέργειες Κουπονιών ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Επαλήθευση CSRF token — αποτρέπει Cross-Site Request Forgery ──
    verifyCsrf();
    $a = $_POST; $action = $a['_action'] ?? '';

    if ($action === 'save_coupon') {
        $id   = (int)($a['id'] ?? 0);
        $code = strtoupper(trim($a['code'] ?? '')); // Πάντα κεφαλαία
        if (!$code) { flash('Ο κωδικός κουπονιού είναι υποχρεωτικός.','danger'); redirect(APP_URL.'/admin/coupons.php'); }

        // Συγκέντρωση πεδίων με sanitization
        $fields = [
            'code'            => $code,
            'description'     => trim($a['description'] ?? ''),
            'discount_type'   => in_array($a['discount_type']??'',['percent','fixed']) ? $a['discount_type'] : 'percent', // Whitelist
            'discount_value'  => max(0, (float)($a['discount_value'] ?? 0)),   // Μη αρνητικό
            'applies_to_plan' => in_array($a['applies_to_plan']??'',['basic','pro','any']) ? $a['applies_to_plan'] : 'any', // Whitelist
            'max_uses'        => ($a['max_uses'] ?? '') !== '' ? max(1,(int)$a['max_uses']) : null, // null = απεριόριστο
            'valid_from'      => $a['valid_from'] ?: null,   // null αν κενό
            'valid_until'     => $a['valid_until'] ?: null,  // null αν κενό
            'active'          => (int)isset($a['active']),   // checkbox → 0/1
        ];

        if ($id) {
            // UPDATE: δυναμικό SET clause από τα keys του $fields array
            $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
            $db->prepare("UPDATE coupons SET $sets WHERE id=?")->execute([...array_values($fields), $id]);
            flash('Κουπόνι ενημερώθηκε!');
        } else {
            // INSERT: δυναμικό με columns και placeholders
            $cols = implode(',', array_keys($fields));
            $phs  = implode(',', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO coupons ($cols) VALUES ($phs)")->execute(array_values($fields));
            flash('Κουπόνι δημιουργήθηκε!');
        }
        auditLog('coupon_saved','coupon',$id,$code);
    }

    if ($action === 'toggle_coupon') {
        // Εναλλαγή active/inactive κατάστασης
        $db->prepare("UPDATE coupons SET active=NOT active WHERE id=?")->execute([(int)$a['id']]);
        flash('Κατάσταση κουπονιού ενημερώθηκε.');
    }

    if ($action === 'delete_coupon') {
        $id = (int)$a['id'];
        // Πρώτα διαγραφή των εξαργυρώσεων (FK constraint)
        $db->prepare("DELETE FROM coupon_redemptions WHERE coupon_id=?")->execute([$id]);
        $db->prepare("DELETE FROM coupons WHERE id=?")->execute([$id]);
        flash('Κουπόνι διαγράφηκε.');
        auditLog('coupon_deleted','coupon',$id,'');
    }

    redirect(APP_URL.'/admin/coupons.php');
}

// ── Φόρτωση δεδομένων ────────────────────────────────────────────────────────
$editId = (int)($_GET['edit'] ?? 0);
$editCoupon = $editId ? $db->query("SELECT * FROM coupons WHERE id=$editId")->fetch() : null;

// Φέρνει όλα τα κουπόνια με αριθμό εξαργυρώσεων (redeemed)
$coupons = $db->query("
    SELECT c.*, COUNT(r.id) as redeemed
    FROM coupons c
    LEFT JOIN coupon_redemptions r ON r.coupon_id=c.id
    GROUP BY c.id ORDER BY c.created_at DESC
")->fetchAll();

// ── Στατιστικά για KPI cards ─────────────────────────────────────────────────
$totalCoupons  = count($coupons);
$activeCoupons = count(array_filter($coupons, fn($c) => $c['active']));   // Φίλτρο PHP αντί DB query
$totalUses     = array_sum(array_column($coupons, 'used_count'));          // Σύνολο χρήσεων

renderHead('Κουπόνια');
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

<style>
.topbar{position:relative!important;top:auto!important;z-index:auto!important}
@media(max-width:900px){#menuBtn{display:inline-flex!important;min-width:44px!important;min-height:44px!important;align-items:center!important;justify-content:center!important;font-size:1.2rem!important;cursor:pointer!important}
.sidebar{position:fixed!important;top:0!important;left:0!important;bottom:0!important;width:min(280px,80vw)!important;z-index:9999!important;transform:translateX(-110%)!important;transition:transform .28s cubic-bezier(.2,.8,.2,1)!important;overflow-y:auto}
.sidebar.open{transform:translateX(0)!important;box-shadow:6px 0 40px rgba(0,0,0,.6)!important}
.main-content{margin-left:0!important;width:100%!important}}
#dm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(3px);z-index:9998;cursor:pointer}
#dm-overlay.on{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.page-body{animation:fadeIn .35s ease both}
.anim-1{opacity:0;animation:fadeUp .42s ease-out .05s both}
.anim-2{opacity:0;animation:fadeUp .42s ease-out .12s both}
.anim-3{opacity:0;animation:fadeUp .42s ease-out .19s both}
@media(prefers-reduced-motion:reduce){.page-body,.anim-1,.anim-2,.anim-3{animation:none!important;opacity:1}}
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.2rem}
.page-header h2{font-size:clamp(1.15rem,4vw,1.5rem)!important;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.1rem}
.stat-card{border-radius:18px;padding:1.1rem 1rem;display:flex;flex-direction:column;gap:.35rem}
.stat-icon{width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:.25rem}
.icon-blue{background:rgba(59,130,246,.15);color:#3b82f6}
.icon-green{background:rgba(45,198,83,.15);color:#2dc653}
.icon-gold{background:rgba(240,165,0,.15);color:var(--gold,#f0a500)}
.stat-val{font-size:clamp(1.4rem,5vw,2rem)!important;font-weight:800;line-height:1}
.stat-lbl{font-size:clamp(.82rem,3vw,.92rem)!important;color:var(--muted,#8892b0);font-weight:600}
.card{border-radius:18px;overflow:hidden;margin-bottom:1.1rem}
.card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.95rem 1.2rem;border-bottom:1px solid var(--border,#1e2536)}
.card-title{font-size:clamp(1rem,3.5vw,1.12rem)!important;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0}
.card-body{padding:1.3rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.form-grid.cols-3{grid-template-columns:1fr 1fr 1fr}
.form-grid .span-2{grid-column:span 2}
.form-group{display:flex;flex-direction:column;gap:.38rem}
.form-label{font-size:clamp(.9rem,3vw,.98rem)!important;font-weight:700;color:var(--text,#e2e8f0);display:flex;align-items:center;gap:.4rem}
.form-control{font-size:clamp(.92rem,3vw,1rem)!important;min-height:48px;padding:.65rem .95rem;border-radius:11px!important;transition:border-color .2s,box-shadow .2s;width:100%}
.form-control:focus{outline:none;border-color:var(--red,#e63946)!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
.form-hint{font-size:.8rem!important;color:var(--muted,#8892b0)}
.btn{min-height:46px;font-size:clamp(.9rem,3vw,1rem)!important;font-weight:700!important;display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border-radius:11px;transition:all .18s;text-decoration:none;padding:.55rem 1.1rem;cursor:pointer;border:none;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-sm{min-height:38px;padding:.38rem .85rem;font-size:.88rem!important;border-radius:9px}
.toggle-row{display:flex;align-items:center;gap:.75rem}
.toggle{position:relative;width:54px;height:30px;flex-shrink:0;cursor:pointer}
.toggle input{opacity:0;width:0;height:0;position:absolute}
.toggle-track{position:absolute;inset:0;background:#2d3748;border-radius:34px;transition:.25s}
.toggle-track:before{content:'';position:absolute;height:24px;width:24px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s}
.toggle input:checked~.toggle-track{background:var(--red,#e63946)}
.toggle input:checked~.toggle-track:before{transform:translateX(24px)}
.toggle-lbl{font-size:clamp(.95rem,3vw,1rem)!important;font-weight:700;cursor:pointer}
.table-wrap{overflow-x:auto}
.table-wrap table{width:100%;border-collapse:collapse}
.table-wrap th{font-size:clamp(.76rem,2.5vw,.84rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;padding:.65rem .9rem}
.table-wrap td{font-size:clamp(.9rem,3vw,.98rem)!important;padding:.8rem .9rem;vertical-align:middle}
.table-wrap tbody tr:hover{background:rgba(255,255,255,.03)}
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .65rem;border-radius:999px;font-size:clamp(.78rem,3vw,.86rem)!important;font-weight:700;white-space:nowrap}
.coupon-code{font-family:monospace;font-size:clamp(.92rem,3vw,1rem)!important;font-weight:800;background:rgba(230,57,70,.1);color:#f87171;padding:.2rem .55rem;border-radius:6px;letter-spacing:.06em}
.progress{height:6px;background:rgba(255,255,255,.08);border-radius:3px;overflow:hidden;min-width:80px}
.progress-fill{height:100%;background:var(--red,#e63946);border-radius:3px;transition:width .3s}
.empty-state{text-align:center;padding:3rem 1rem;color:var(--muted,#8892b0)}
.empty-state i{font-size:clamp(2rem,8vw,3rem)!important;margin-bottom:.75rem;display:block}
.empty-state p{font-size:clamp(.9rem,3vw,1rem)!important}
@media(max-width:900px){.page-body{padding:1rem!important}.stats-row{gap:.75rem}}
@media(max-width:700px){.page-body{padding:.85rem!important}.form-grid,.form-grid.cols-3{grid-template-columns:1fr!important}.form-grid .span-2{grid-column:span 1!important}.hide-sm{display:none}}
@media(max-width:480px){.page-body{padding:.65rem!important}.card{border-radius:14px}.stats-row{grid-template-columns:1fr 1fr}.stats-row .stat-card:last-child{grid-column:span 2}}
</style>
<body>
<div class="app-layout">
<?php renderSidebar('admin_coupons'); ?>
<div id="dm-overlay"></div>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-ticket"></i> Κουπόνια'); ?>
<div class="page-body">

<div class="page-header anim-1">
    <h2><i class="fa-solid fa-ticket" style="color:var(--red,#e63946)"></i> Διαχείριση Κουπονιών</h2>
    <?php if(!$editCoupon && !isset($_GET['add'])): ?>
    <a href="?add=1" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Νέο Κουπόνι</a>
    <?php endif; ?>
</div>

<div class="stats-row anim-2">
    <div class="stat-card card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-ticket"></i></div>
        <div class="stat-val"><?= $totalCoupons ?></div>
        <div class="stat-lbl">Σύνολο Κουπονιών</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-val"><?= $activeCoupons ?></div>
        <div class="stat-lbl">Ενεργά</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-fire"></i></div>
        <div class="stat-val"><?= $totalUses ?></div>
        <div class="stat-lbl">Σύνολο Χρήσεων</div>
    </div>
</div>

<?php if($editCoupon || isset($_GET['add'])): ?>
<!-- ── Form ── -->
<div class="card anim-2">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-<?= $editCoupon?'pen-to-square':'plus' ?>" style="color:var(--gold,#f0a500)"></i>
            <?= $editCoupon ? 'Επεξεργασία Κουπονιού' : 'Νέο Κουπόνι' ?>
        </div>
        <a href="<?= APP_URL ?>/admin/coupons.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-left"></i> Πίσω</a>
    </div>
    <div class="card-body">
        <?php $ec=$editCoupon??[]; ?>
        <form method="POST">
            <input type="hidden" name="_action" value="save_coupon">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="id" value="<?= $ec['id']??'' ?>">

            <div class="form-grid" style="margin-bottom:1rem">
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-hashtag"></i> Κωδικός Κουπονιού</label>
                    <input name="code" class="form-control" value="<?= h(strtoupper($ec['code']??'')) ?>"
                        placeholder="π.χ. WELCOME30" required style="text-transform:uppercase;letter-spacing:.06em;font-weight:700"
                        oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')">
                    <div class="form-hint">Μόνο γράμματα και αριθμοί, χωρίς κενά</div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-align-left"></i> Περιγραφή</label>
                    <input name="description" class="form-control" value="<?= h($ec['description']??'') ?>" placeholder="π.χ. 30% για νέες σχολές">
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-percent"></i> Τύπος Έκπτωσης</label>
                    <select name="discount_type" class="form-control" id="discType" onchange="updateDiscHint()">
                        <option value="percent" <?= ($ec['discount_type']??'percent')==='percent'?'selected':'' ?>>Ποσοστό (%)</option>
                        <option value="fixed"   <?= ($ec['discount_type']??'')==='fixed'?'selected':'' ?>>Σταθερό ποσό (€)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" id="discLbl"><i class="fa-solid fa-tag"></i> Αξία Έκπτωσης</label>
                    <input type="number" step=".01" min="0" max="100" name="discount_value" class="form-control"
                        value="<?= h($ec['discount_value']??'') ?>" placeholder="30" required id="discVal">
                    <div class="form-hint" id="discHint">π.χ. 30 = 30% έκπτωση</div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-box"></i> Ισχύει για Πλάνο</label>
                    <select name="applies_to_plan" class="form-control">
                        <option value="any"   <?= ($ec['applies_to_plan']??'any')==='any'?'selected':'' ?>>Όλα τα πλάνα</option>
                        <option value="basic" <?= ($ec['applies_to_plan']??'')==='basic'?'selected':'' ?>>Basic μόνο</option>
                        <option value="pro"   <?= ($ec['applies_to_plan']??'')==='pro'?'selected':'' ?>>Pro μόνο</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-infinity"></i> Μέγιστες Χρήσεις</label>
                    <input type="number" min="1" name="max_uses" class="form-control"
                        value="<?= h($ec['max_uses']??'') ?>" placeholder="Κενό = απεριόριστες">
                    <div class="form-hint">Αφήστε κενό για απεριόριστες χρήσεις</div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fa-regular fa-calendar"></i> Έναρξη Ισχύος</label>
                    <input type="date" name="valid_from" class="form-control" value="<?= h($ec['valid_from']??'') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-regular fa-calendar-xmark"></i> Λήξη Ισχύος</label>
                    <input type="date" name="valid_until" class="form-control" value="<?= h($ec['valid_until']??'') ?>">
                </div>

                <div class="form-group span-2">
                    <div class="toggle-row">
                        <label class="toggle">
                            <input type="checkbox" name="active" value="1" id="activeToggle" <?= ($ec['active']??1)?'checked':'' ?>>
                            <div class="toggle-track"></div>
                        </label>
                        <label for="activeToggle" class="toggle-lbl">Κουπόνι Ενεργό</label>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary" style="min-height:50px">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $editCoupon?'Αποθήκευση':'Δημιουργία Κουπονιού' ?>
                </button>
                <a href="<?= APP_URL ?>/admin/coupons.php" class="btn btn-secondary">Ακύρωση</a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ── List ── -->
<div class="card anim-3">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-list" style="color:var(--muted,#8892b0)"></i> Όλα τα Κουπόνια</div>
    </div>
    <?php if(empty($coupons)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-ticket"></i>
        <p>Δεν υπάρχουν κουπόνια ακόμα.</p>
        <a href="?add=1" class="btn btn-primary" style="margin-top:.5rem"><i class="fa-solid fa-plus"></i> Δημιουργήστε το πρώτο</a>
    </div>
    <?php else: ?>
    <div class="table-wrap"><div style="display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;border-bottom:1px solid var(--border,#1e2536)"><span style="color:var(--muted);font-size:.85rem"><i class="fa-solid fa-magnifying-glass"></i></span><input id="srch-coupons" type="text" placeholder="Αναζήτηση κουπονιών..." style="background:transparent;border:none;outline:none;color:var(--text,#e2e8f0);font-size:.88rem;width:100%" oninput="void(0)"></div>
<table id="tbl-coupons">
        <thead>
            <tr>
                <th>Κωδικός</th>
                <th>Έκπτωση</th>
                <th>Πλάνο</th>
                <th>Χρήσεις</th>
                <th class="hide-sm">Λήξη</th>
                <th>Κατάσταση</th>
                <th>Ενέργειες</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($coupons as $c): ?>
            <?php
                $usePct = ($c['max_uses'] && $c['max_uses'] > 0) ? min(100, round($c['used_count'] / $c['max_uses'] * 100)) : 0;
                $isExpired = $c['valid_until'] && strtotime($c['valid_until']) < time();
                $isExhausted = $c['max_uses'] && $c['used_count'] >= $c['max_uses'];
            ?>
            <tr style="<?= !$c['active']?'opacity:.55':'' ?>">
                <td>
                    <div class="coupon-code"><?= h($c['code']) ?></div>
                    <?php if($c['description']): ?>
                    <div style="font-size:.82rem;color:var(--muted,#8892b0);margin-top:.25rem"><?= h($c['description']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-weight:800;font-size:1.05rem">
                    <?php if($c['discount_type']==='percent'): ?>
                        <span style="color:#2dc653"><?= $c['discount_value'] ?>%</span>
                    <?php else: ?>
                        <span style="color:#2dc653"><?= formatMoney((float)$c['discount_value']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($c['applies_to_plan']==='any'): ?>
                        <span class="badge" style="background:rgba(99,91,255,.15);color:#a78bfa">Όλα</span>
                    <?php elseif($c['applies_to_plan']==='pro'): ?>
                        <?= planBadge('pro') ?>
                    <?php else: ?>
                        <?= planBadge('basic') ?>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                        <span style="font-weight:700;white-space:nowrap">
                            <?= $c['used_count'] ?><?= $c['max_uses']?' / '.$c['max_uses']:' / ∞' ?>
                        </span>
                        <?php if($c['max_uses']): ?>
                        <div class="progress" style="width:70px"><div class="progress-fill" style="width:<?= $usePct ?>%"></div></div>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="hide-sm" style="color:var(--muted,#8892b0);font-size:.9rem">
                    <?= $c['valid_until'] ? formatDate($c['valid_until']) : '∞' ?>
                    <?php if($isExpired): ?><br><span style="color:#e63946;font-size:.8rem">Έληξε</span><?php endif; ?>
                </td>
                <td>
                    <?php if(!$c['active']): ?>
                        <span class="badge" style="background:rgba(74,85,104,.3);color:#718096">Ανενεργό</span>
                    <?php elseif($isExpired): ?>
                        <span class="badge" style="background:rgba(230,57,70,.15);color:#e63946">Έληξε</span>
                    <?php elseif($isExhausted): ?>
                        <span class="badge" style="background:rgba(240,165,0,.15);color:var(--gold,#f0a500)">Εξαντλήθηκε</span>
                    <?php else: ?>
                        <span class="badge" style="background:rgba(45,198,83,.15);color:#2dc653">Ενεργό</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:.4rem">
                        <a href="?edit=<?= $c['id'] ?>" class="btn btn-ghost btn-sm" title="Επεξεργασία">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </a>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="_action" value="toggle_coupon">
                            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" title="<?= $c['active']?'Απενεργοποίηση':'Ενεργοποίηση' ?>">
                                <i class="fa-solid fa-circle-<?= $c['active']?'pause" style="color:#e63946':'play" style="color:#2dc653' ?>"></i>
                            </button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Διαγραφή κουπονιού <?= h($c['code']) ?>;')">
                            <input type="hidden" name="_action" value="delete_coupon">
                            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
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
    <div id="pg-coupons" class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.75rem 1rem"></div>
    <?php endif; ?>
</div>
<?php endif; ?>

</div></div></div>
<script>
// Sidebar toggle για mobile
(function(){var sb=document.getElementById('sidebar'),ov=document.getElementById('dm-overlay'),mb=document.getElementById('menuBtn');if(!sb||!mb)return;function open(){sb.classList.add('open');ov&&ov.classList.add('on');document.body.style.overflow='hidden'}function close(){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow=''}mb.onclick=function(e){e.stopPropagation();sb.classList.contains('open')?close():open()};ov&&ov.addEventListener('click',close);document.addEventListener('keydown',function(e){if(e.key==='Escape')close()});window.addEventListener('resize',function(){if(window.innerWidth>900){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow=''}});})();
// Ενημερώνει δυναμικά το hint και το max του πεδίου έκπτωσης
// ανάλογα με τον τύπο: percent (0-100%) ή fixed (σταθερό ποσό €)
function updateDiscHint(){
    var t=document.getElementById('discType');
    var v=document.getElementById('discVal');
    var h=document.getElementById('discHint');
    var l=document.getElementById('discLbl');
    if(t&&v&&h&&l){
        if(t.value==='percent'){v.max=100;h.textContent='π.χ. 30 = 30% έκπτωση';l.innerHTML='<i class="fa-solid fa-percent"></i> Ποσοστό Έκπτωσης';}
        else{v.max=99999;h.textContent='Σταθερό ποσό σε ευρώ';l.innerHTML='<i class="fa-solid fa-euro-sign"></i> Ποσό Έκπτωσης (€)';}
    }
}
updateDiscHint(); // Εκτέλεση κατά φόρτωση για αρχική κατάσταση
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
initPagination('tbl-coupons', 'pg-coupons', 10, 'srch-coupons');
</script>
</body></html>