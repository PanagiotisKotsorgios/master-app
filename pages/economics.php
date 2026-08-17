<?php

/**
 * ============================================================
 * pages/economics.php — Οικονομική Διαχείριση (Pro)
 * ============================================================
 * PURPOSE:
 *   CRUD transactions (έσοδα/έξοδα), bank transfer requests,
 *   μηνιαία γραφήματα, κατηγορίες, αναφορές.
 *   Απαιτεί Pro plan (planHas economics_enabled).
 *
 * SECURITY:
 *   ✓ requireLogin() + planHas('economics_enabled')
 *   ✓ verifyCsrf() σε κάθε POST
 *   ✓ Ownership: WHERE school_id = schoolId()
 *   ✓ amount: (float) cast + αρνητικά απαγορεύονται
 *   ✓ type: whitelist (income/expense)
 *   ✓ category: strip_tags + trim
 *   ✓ Prepared statements
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
renderPaymentWall();

if (!planHas('economics_enabled')) {
    flash('Τα Οικονομικά απαιτούν Pro πλάνο.', 'danger');
    redirect(APP_URL . '/pages/upgrade.php');
}

$db  = getDB();
$sid = schoolId();

if (!function_exists('greekMonthName')) {
    function greekMonthName(int $month): string {
        $map = [
            1 => 'Ιανουάριος',
            2 => 'Φεβρουάριος',
            3 => 'Μάρτιος',
            4 => 'Απρίλιος',
            5 => 'Μάιος',
            6 => 'Ιούνιος',
            7 => 'Ιούλιος',
            8 => 'Αύγουστος',
            9 => 'Σεπτέμβριος',
            10 => 'Οκτώβριος',
            11 => 'Νοέμβριος',
            12 => 'Δεκέμβριος',
        ];
        return $map[$month] ?? '';
    }
}

if (!function_exists('greekMonthShort')) {
    function greekMonthShort(int $month): string {
        $map = [
            1 => 'Ιαν',
            2 => 'Φεβ',
            3 => 'Μαρ',
            4 => 'Απρ',
            5 => 'Μάι',
            6 => 'Ιουν',
            7 => 'Ιουλ',
            8 => 'Αυγ',
            9 => 'Σεπ',
            10 => 'Οκτ',
            11 => 'Νοε',
            12 => 'Δεκ',
        ];
        return $map[$month] ?? '';
    }
}

if (!function_exists('formatGreekMonthYear')) {
    function formatGreekMonthYear(string $date): string {
        $ts = strtotime($date);
        if (!$ts) return '';
        return greekMonthName((int)date('n', $ts)) . ' ' . date('Y', $ts);
    }
}

if (!function_exists('formatGreekShortMonthYear')) {
    function formatGreekShortMonthYear(string $date): string {
        $ts = strtotime($date);
        if (!$ts) return '';
        return greekMonthShort((int)date('n', $ts)) . ' ' . date('Y', $ts);
    }
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

/* ── POST: save_tx ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['_action'] ?? '') === 'save_tx')) {
    verifyCsrf();

    $a  = $_POST;
    $id = (int)($a['id'] ?? 0);

    $type = trim($a['type'] ?? '');
    if (!in_array($type, ['income', 'expense'], true)) {
        flash('Μη έγκυρος τύπος κίνησης.', 'danger');
        redirect(APP_URL . '/pages/economics.php?month=' . urlencode($month));
    }

    $category         = trim(strip_tags($a['category'] ?? '')) ?: null;
    $amount           = (float)($a['amount'] ?? 0);
    $description      = trim($a['description'] ?? '') ?: null;
    $transaction_date = trim($a['transaction_date'] ?? '') ?: null;
    $payment_method   = trim($a['payment_method'] ?? '') ?: null;
    $notes            = trim($a['notes'] ?? '') ?: null;

    if ($amount < 0) {
        flash('Το ποσό δεν μπορεί να είναι αρνητικό.', 'danger');
        redirect(APP_URL . '/pages/economics.php?month=' . urlencode($month));
    }

    if (!in_array($payment_method, ['cash', 'card', 'deposit', 'other'], true)) {
        $payment_method = 'cash';
    }

    $fields = ['type','category','amount','description','transaction_date','payment_method','notes'];
    $data   = [$type, $category, $amount, $description, $transaction_date, $payment_method, $notes];

    if ($id) {
        $sql = "UPDATE transactions SET " . implode(',', array_map(fn($k) => "$k=?", $fields)) . " WHERE id=? AND school_id=?";
        $db->prepare($sql)->execute([...$data, $id, $sid]);
        flash('Η εγγραφή ενημερώθηκε!');
    } else {
        $db->prepare("INSERT INTO transactions (school_id," . implode(',', $fields) . ") VALUES (?".str_repeat(',?', count($fields)).")")
           ->execute([$sid, ...$data]);
        flash('Η εγγραφή προστέθηκε!');
    }

    redirect(APP_URL . '/pages/economics.php?month=' . urlencode($month));
}

/* ── POST: delete_tx ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['_action'] ?? '') === 'delete_tx')) {
    verifyCsrf();

    $db->prepare("DELETE FROM transactions WHERE id=? AND school_id=?")
       ->execute([(int)($_POST['id'] ?? 0), $sid]);

    flash('Η εγγραφή διαγράφηκε.', 'danger');
    redirect(APP_URL . '/pages/economics.php?month=' . urlencode($month));
}

$mStart = $month . '-01';
$mEnd   = date('Y-m-t', strtotime($mStart));

$txType   = $_GET['txtype'] ?? '';
$txCat    = $_GET['txcat'] ?? '';
$txSearch = trim($_GET['tq'] ?? '');

$txWhere  = "t.school_id=? AND t.transaction_date BETWEEN ? AND ?";
$txParams = [$sid, $mStart, $mEnd];

if ($txType && in_array($txType, ['income', 'expense'], true)) {
    $txWhere .= " AND t.type=?";
    $txParams[] = $txType;
}

if ($txCat) {
    $txWhere .= " AND t.category=?";
    $txParams[] = $txCat;
}

if ($txSearch) {
    $txWhere .= " AND (t.description LIKE ? OR t.notes LIKE ?)";
    $txParams[] = "%$txSearch%";
    $txParams[] = "%$txSearch%";
}

$_pp     = isset($_GET['pp']) ? (int)$_GET['pp'] : 10;
$perPage = in_array($_pp, [10,25,50,100], true) ? $_pp : 10;
$txPage  = max(1, (int)($_GET['txpage'] ?? 1));

$countStmt = $db->prepare("SELECT COUNT(*) FROM transactions t WHERE $txWhere");
$countStmt->execute($txParams);
$txTotal = (int)$countStmt->fetchColumn();
$txPages = max(1, (int)ceil($txTotal / $perPage));

$offset = ($txPage - 1) * $perPage;

$txs = $db->prepare("
    SELECT t.*, a.full_name
    FROM transactions t
    LEFT JOIN athletes a ON a.id = t.athlete_id
    WHERE $txWhere
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT $perPage OFFSET $offset
");
$txs->execute($txParams);
$transactions = $txs->fetchAll();

$incomeStmt = $db->prepare("
    SELECT COALESCE(SUM(amount),0)
    FROM transactions
    WHERE school_id=?
      AND type='income'
      AND transaction_date BETWEEN ? AND ?
");
$incomeStmt->execute([$sid, $mStart, $mEnd]);
$income = (float)$incomeStmt->fetchColumn();

$expenseStmt = $db->prepare("
    SELECT COALESCE(SUM(amount),0)
    FROM transactions
    WHERE school_id=?
      AND type='expense'
      AND transaction_date BETWEEN ? AND ?
");
$expenseStmt->execute([$sid, $mStart, $mEnd]);
$expense = (float)$expenseStmt->fetchColumn();

$chartData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $s = $m . '-01';
    $e = date('Y-m-t', strtotime($s));

    $inc = $db->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM transactions
        WHERE school_id=?
          AND type='income'
          AND transaction_date BETWEEN ? AND ?
    ");
    $inc->execute([$sid, $s, $e]);

    $exp = $db->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM transactions
        WHERE school_id=?
          AND type='expense'
          AND transaction_date BETWEEN ? AND ?
    ");
    $exp->execute([$sid, $s, $e]);

    $chartData[] = [
        'month'   => formatGreekShortMonthYear($s),
        'income'  => (float)$inc->fetchColumn(),
        'expense' => (float)$exp->fetchColumn(),
    ];
}

/* ── Load tx for edit popup via JS ── */
$editTxJson = 'null';
if (isset($_GET['edit_id'])) {
    $stmtEdit = $db->prepare("SELECT * FROM transactions WHERE id=? AND school_id=?");
    $stmtEdit->execute([(int)$_GET['edit_id'], $sid]);
    $et = $stmtEdit->fetch(PDO::FETCH_ASSOC);
    if ($et) {
        $editTxJson = json_encode($et, JSON_UNESCAPED_UNICODE);
    }
}

$incomeCategories  = ['Συνδρομές','Εξετάσεις','Αγωνιστικά','Άλλα έσοδα'];
$expenseCategories = ['Ενοίκιο','Εξοπλισμός','Ασφάλιστρα','Μισθοί','Διαφήμιση','Λοιπά έξοδα'];
$categories        = array_merge($incomeCategories, $expenseCategories);
$payMethods        = ['cash'=>'Μετρητά','card'=>'Κάρτα','deposit'=>'Κατάθεση','other'=>'Άλλο'];

function txPageUrl(int $p): string {
    $q = $_GET;
    $q['txpage'] = $p;
    return '?' . http_build_query($q);
}

renderHead('Οικονομικά');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<style>
/* ═══════════════════════════════════════════
   ΟΙΚΟΝΟΜΙΚΑ — UI με αναδυόμενα παράθυρα
═══════════════════════════════════════════ */

.topbar { position: relative !important; top: auto !important; z-index: auto !important; }

@media (max-width: 900px) {
    #menuBtn { display: inline-flex !important; min-width: 48px !important; min-height: 48px !important; align-items: center !important; justify-content: center !important; font-size: 1.3rem !important; cursor: pointer !important; }
    .sidebar { position: fixed !important; top: 0 !important; left: 0 !important; bottom: 0 !important; width: min(290px,85vw) !important; z-index: 9999 !important; transform: translateX(-110%) !important; transition: transform .28s cubic-bezier(.2,.8,.2,1) !important; overflow-y: auto; }
    .sidebar.open { transform: translateX(0) !important; box-shadow: 6px 0 40px rgba(0,0,0,.7) !important; }
    .main-content { margin-left: 0 !important; width: 100% !important; }
}
#dm-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9998; cursor: pointer; }
#dm-overlay.on { display: block; }

@keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
@keyframes popIn  { from { opacity:0; transform:scale(.93) translateY(20px); } to { opacity:1; transform:scale(1) translateY(0); } }

.page-body { animation: fadeIn .3s ease both; }
.anim-1 { animation: fadeUp .4s ease-out .04s both; }
.anim-2 { animation: fadeUp .4s ease-out .1s both; }
.anim-3 { animation: fadeUp .4s ease-out .16s both; }
.anim-4 { animation: fadeUp .4s ease-out .22s both; }

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation: none !important;
        transition: none !important;
        opacity: 1 !important;
    }
}

.page-body { padding: .85rem !important; }
.page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .6rem; margin-bottom: .9rem; }
.page-header h2 { font-size: clamp(1.2rem,5vw,1.5rem) !important; font-weight: 800; display: flex; align-items: center; gap: .5rem; margin: 0; }

.stat-cards-row { display: grid; grid-template-columns: repeat(3,1fr); gap: .75rem; margin-bottom: .9rem; }
.stat-card { border-radius: 16px; padding: .95rem .85rem .85rem; display: flex; flex-direction: column; gap: .3rem; }
.stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; margin-bottom: .2rem; }
.icon-green { background: rgba(45,198,83,.15); color: var(--green,#2dc653); }
.icon-red   { background: rgba(230,57,70,.15);  color: var(--red,#e63946); }
.icon-blue  { background: rgba(59,130,246,.15); color: #3b82f6; }
.stat-val { font-size: clamp(1.3rem,5.5vw,1.85rem) !important; font-weight: 800; line-height: 1.05; }
.stat-lbl { font-size: clamp(.85rem,3.5vw,.95rem) !important; color: var(--muted,#8892b0); font-weight: 600; }
.text-green { color: var(--green,#2dc653) !important; }
.text-red   { color: var(--red,#e63946) !important; }

.chart-card { border-radius: 16px; margin-bottom: .9rem; overflow: hidden; }
.card-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; padding: .85rem 1rem; border-bottom: 1px solid var(--border,#1e2536); }
.card-title { font-size: clamp(1rem,4vw,1.1rem) !important; font-weight: 800; display: flex; align-items: center; gap: .45rem; }

.tx-card { border-radius: 16px; overflow: hidden; margin-bottom: .9rem; }

.filters-bar { display: flex; flex-wrap: wrap; gap: .45rem; align-items: center; }
.search-bar { position: relative; flex: 1; min-width: 120px; }
.search-bar .si { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--muted,#8892b0); pointer-events: none; font-size: .95rem; }
.search-bar input { width: 100%; padding-left: 2.25rem !important; }

.fc { font-size: clamp(.9rem,3.8vw,1rem) !important; min-height: 46px; padding: .5rem .75rem; border-radius: 10px !important; background: var(--surface2,#141927); border: 1.5px solid var(--border,#1e2536); color: var(--text,#e2e8f0); }
.fc:focus { outline: none; border-color: var(--red,#e63946) !important; box-shadow: 0 0 0 3px rgba(230,57,70,.15) !important; }
select.fc { cursor: pointer; }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.table-wrap table { width: 100%; border-collapse: collapse; }
@media (max-width: 600px) {
    .table-wrap table { width: max-content; min-width: 100%; }
    .table-wrap td, .table-wrap th { white-space: nowrap; }
    .col-hide-mobile { display: none !important; }
}
.table-wrap th { font-size: clamp(.8rem,3vw,.88rem) !important; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; padding: .65rem .9rem; color: var(--muted,#8892b0); border-right: 1px solid rgba(255,255,255,.06); }
.table-wrap th:last-child { border-right: none; }
.table-wrap td { font-size: clamp(.95rem,3.5vw,1.02rem) !important; padding: .8rem .9rem; vertical-align: middle; border-top: 1px solid rgba(255,255,255,.05); border-right: 1px solid rgba(255,255,255,.04); }
.table-wrap td:last-child { border-right: none; }
.table-wrap tbody tr:hover { background: rgba(255,255,255,.03); }
.amount-cell { font-size: clamp(1rem,4vw,1.1rem) !important; font-weight: 800; }

.btn { min-height: 46px; font-size: clamp(.95rem,4vw,1.02rem) !important; font-weight: 700 !important; display: inline-flex; align-items: center; justify-content: center; gap: .45rem; border-radius: 11px; transition: all .17s; text-decoration: none; padding: .5rem 1rem; cursor: pointer; border: none; white-space: nowrap; }
.btn:active { transform: scale(.96); }
.btn-sm { min-height: 42px; padding: .42rem .85rem; font-size: clamp(.9rem,3.8vw,.98rem) !important; }
.btn-icon { min-height: 42px; min-width: 42px; padding: 0; border-radius: 10px; }
.btn-lg { min-height: 56px; padding: .7rem 1.4rem; font-size: clamp(1.05rem,4.5vw,1.15rem) !important; border-radius: 13px; }
.w-100 { width: 100%; }

.badge { display: inline-flex; align-items: center; gap: .3rem; padding: .32rem .8rem; border-radius: 999px; font-size: clamp(.82rem,3.5vw,.9rem) !important; font-weight: 700; white-space: nowrap; }
.badge-basic   { background: rgba(255,255,255,.08); color: var(--text,#e2e8f0); }
.badge-income  { background: rgba(45,198,83,.15);   color: var(--green,#2dc653); }
.badge-expense { background: rgba(230,57,70,.15);   color: var(--red,#e63946); }

.nav-item { min-height: 48px !important; font-size: clamp(.95rem,3.5vw,1.02rem) !important; font-weight: 600 !important; padding: .65rem .9rem !important; border-radius: 10px !important; display: flex !important; align-items: center !important; gap: .7rem !important; transition: background .15s !important; text-decoration: none; }
.nav-item .icon { width: 22px; text-align: center; font-size: 1rem; flex-shrink: 0; }

.sidebar-school { margin:.25rem 1rem !important; padding:0 !important; display:flex !important; align-items:center !important; font-weight:700 !important; font-size:clamp(.85rem,3vw,.95rem) !important; color:var(--text,#f0f2ff) !important; background:none !important; border:none !important; box-shadow:none !important; overflow-wrap:anywhere !important; word-break:break-word !important; }
.sidebar-school:hover,.sidebar-school:focus { background:none !important; outline:none !important; }

.empty-state { text-align: center; padding: 2.5rem 1rem; }
.empty-icon { font-size: 2.5rem; opacity:.4; margin-bottom: .65rem; }
.empty-state p { font-size: clamp(.95rem,3.5vw,1.05rem) !important; color: var(--muted,#8892b0); margin: 0; }

.modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.72);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    overflow-y: auto;
}
.modal-backdrop.open { display: flex; }

.modal-box {
    background: var(--surface,#0d1117);
    border: 1.5px solid var(--border,#1e2536);
    border-radius: 22px;
    width: 100%;
    max-width: 520px;
    padding: 0;
    animation: popIn .28s cubic-bezier(.2,.8,.2,1) both;
    position: relative;
    box-shadow: 0 24px 80px rgba(0,0,0,.7);
}
.modal-box.modal-sm { max-width: 400px; }

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.1rem 1.25rem .9rem;
    border-bottom: 1.5px solid var(--border,#1e2536);
}
.modal-title {
    font-size: clamp(1.1rem,4.5vw,1.3rem) !important;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.modal-close {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: rgba(255,255,255,.06);
    border: none;
    color: var(--muted,#8892b0);
    font-size: 1.15rem;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
    flex-shrink: 0;
}
.modal-close:hover { background: rgba(230,57,70,.18); color: var(--red,#e63946); }
.modal-body { padding: 1.1rem 1.25rem; }
.modal-footer { display: flex; gap: .6rem; flex-wrap: wrap; padding: 0 1.25rem 1.25rem; }

.form-section-title { font-size: clamp(.82rem,3.5vw,.9rem) !important; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: var(--muted,#8892b0); margin-bottom: .7rem; margin-top: .2rem; display: flex; align-items: center; gap: .4rem; padding-bottom: .5rem; border-bottom: 1px solid var(--border,#1e2536); }
.form-row { display: grid; gap: .8rem; margin-bottom: .8rem; }
.form-row.col-2 { grid-template-columns: 1fr 1fr; }
.col-span-2 { grid-column: span 2; }
.form-label { font-size: clamp(.95rem,4vw,1.05rem) !important; font-weight: 700; display: block; margin-bottom: .35rem; }
.form-control {
    font-size: clamp(.95rem,4vw,1.05rem) !important;
    min-height: 50px;
    padding: .65rem .9rem;
    border-radius: 11px !important;
    border: 1.5px solid var(--border,#1e2536);
    background: var(--surface2,#141927);
    color: var(--text,#e2e8f0);
    width: 100%;
    transition: border-color .2s, box-shadow .2s;
}
.form-control:focus { outline: none; border-color: var(--red,#e63946) !important; box-shadow: 0 0 0 3px rgba(230,57,70,.15) !important; }
select.form-control { cursor: pointer; }

.delete-icon-wrap {
    width: 80px; height: 80px;
    border-radius: 20px;
    background: rgba(230,57,70,.12);
    border: 2px solid rgba(230,57,70,.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 2.4rem;
    color: var(--red,#e63946);
    margin: 0 auto .9rem;
    animation: popIn .3s .1s both;
}
.delete-modal-title {
    font-size: clamp(1.25rem,5.5vw,1.6rem) !important;
    font-weight: 800;
    text-align: center;
    margin-bottom: .5rem;
    line-height: 1.2;
}
.delete-modal-sub {
    font-size: clamp(.95rem,4vw,1.08rem) !important;
    color: var(--muted,#8892b0);
    text-align: center;
    margin-bottom: 1rem;
    line-height: 1.5;
}
.delete-detail-box {
    background: rgba(230,57,70,.07);
    border: 1.5px solid rgba(230,57,70,.2);
    border-radius: 13px;
    padding: .9rem 1rem;
    margin-bottom: 1.1rem;
    font-size: clamp(.95rem,4vw,1.05rem) !important;
    font-weight: 600;
}
.delete-detail-box strong { display: block; margin-bottom: .25rem; font-size: 1.1rem !important; }

.totals-row { display: flex; align-items: center; justify-content: flex-end; gap: 1.25rem; padding: .8rem 1rem; border-top: 1px solid var(--border,#1e2536); flex-wrap: wrap; }
.totals-row span { font-size: clamp(.9rem,3.8vw,1rem) !important; font-weight: 800; }

.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    padding: 1rem 1rem .9rem;
    border-top: 1px solid var(--border,#1e2536);
    flex-wrap: wrap;
}
.btn-pag {
    min-width: 48px !important;
    min-height: 48px !important;
    font-size: clamp(1rem,4.5vw,1.15rem) !important;
    font-weight: 800 !important;
    border-radius: 12px !important;
    padding: .4rem .5rem !important;
}
.btn-pag-active {
    background: var(--red,#e63946) !important;
    color: #fff !important;
    box-shadow: 0 4px 16px rgba(230,57,70,.35);
}
.pag-ellipsis {
    font-size: clamp(1rem,4vw,1.15rem);
    font-weight: 800;
    color: var(--muted,#8892b0);
    padding: 0 .2rem;
    user-select: none;
}
.pag-info {
    width: 100%;
    text-align: center;
    font-size: clamp(.88rem,3.8vw,.98rem) !important;
    color: var(--muted,#8892b0);
    font-weight: 600;
    margin-top: .3rem;
}

@media (max-width: 700px) {
    .page-body { padding: .65rem !important; }
    .stat-cards-row { gap: .5rem; grid-template-columns: 1fr !important; }
    .stat-cards-row .stat-card:last-child { grid-column: span 1; }
    .form-row.col-2 { grid-template-columns: 1fr !important; }
    .col-span-2 { grid-column: span 1; }
    .col-hide-mobile { display: none !important; }
    .modal-box { border-radius: 18px; }
}
@media (max-width: 480px) {
    .stat-cards-row { grid-template-columns: 1fr !important; }
    .stat-cards-row .stat-card:last-child { grid-column: span 1; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; }
}
@media (max-width: 360px) {
    .stat-cards-row { grid-template-columns: 1fr !important; }
    .stat-cards-row .stat-card:last-child { grid-column: span 1; }
}
</style>

<body>
<div class="app-layout">
<?php renderSidebar('economics'); ?>
<div id="dm-overlay"></div>

<div class="main-content">
<?php renderTopbar('Οικονομικά'); ?>
<div class="page-body">

<div class="page-header anim-1">
    <h2>
        <i class="fa-solid fa-chart-column" style="color:var(--red,#e63946)"></i>
        Οικονομικά
        <span style="font-size:clamp(.88rem,3.8vw,.98rem)!important;font-weight:600;color:var(--muted,#8892b0)">
            — <?= h(formatGreekMonthYear($month . '-01')) ?>
        </span>
    </h2>
    <button class="btn btn-primary btn-sm" onclick="openAddModal()">
        <i class="fa-solid fa-plus"></i> Νέα Κίνηση
    </button>
</div>

<?php $profit = $income - $expense; ?>
<div class="stat-cards-row anim-2">
    <div class="stat-card card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div class="stat-val text-green"><?= formatMoney($income) ?></div>
        <div class="stat-lbl">Έσοδα Μήνα</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-red"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div class="stat-val text-red"><?= formatMoney($expense) ?></div>
        <div class="stat-lbl">Έξοδα Μήνα</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon <?= $profit >= 0 ? 'icon-blue' : 'icon-red' ?>">
            <i class="fa-solid fa-scale-balanced"></i>
        </div>
        <div class="stat-val <?= $profit >= 0 ? 'text-green' : 'text-red' ?>"><?= formatMoney($profit) ?></div>
        <div class="stat-lbl">Καθαρό Αποτέλεσμα</div>
    </div>
</div>

<div class="card chart-card anim-3">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-chart-bar" style="color:var(--gold,#f0a500)"></i>
            Εξέλιξη Τελευταίων 6 Μηνών
        </div>
    </div>
    <div style="padding:.85rem">
        <canvas id="finChart" style="max-height:200px"></canvas>
    </div>
</div>

<div class="card tx-card anim-4">
    <div class="card-header" style="flex-wrap:wrap;gap:.55rem">
        <div class="card-title">
            <i class="fa-solid fa-list" style="color:var(--red,#e63946)"></i>
            Κινήσεις
            <?php if ($txTotal): ?>
            <span class="badge badge-basic"><?= $txTotal ?></span>
            <?php endif; ?>
        </div>

        <div class="filters-bar" style="margin:0" id="filtersBar">
            <!-- month picker still needs page reload (different data set) -->
            <form method="GET" style="display:contents" id="monthForm">
                <input type="month" name="month" class="fc" id="monthPicker"
                       value="<?= h($month) ?>"
                       style="min-width:145px;max-width:165px"
                       onchange="document.getElementById('monthForm').submit()">
            </form>

            <select id="liveType" class="fc" style="min-width:105px;max-width:130px" onchange="liveFilter()">
                <option value="">Όλοι</option>
                <option value="income">Έσοδα</option>
                <option value="expense">Έξοδα</option>
            </select>

            <select id="liveCat" class="fc" style="min-width:130px;max-width:160px" onchange="liveFilter()">
                <option value="">Όλες</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= h($c) ?>"><?= h($c) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="search-bar" style="max-width:175px">
                <span class="si"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input id="liveSearch" value="" placeholder="Αναζήτηση..." class="fc"
                       style="padding-left:2.4rem!important;width:100%"
                       oninput="liveFilter()">
            </div>

            <button type="button" id="liveClearBtn" class="btn btn-ghost btn-sm" title="Καθαρισμός φίλτρων"
                    style="display:none" onclick="liveClearFilters()">
                <i class="fa-solid fa-filter-circle-xmark"></i> Καθαρισμός φίλτρων
            </button>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Ημ/νία</th>
                    <th>Τύπος</th>
                    <th class="col-hide-mobile">Κατηγορία</th>
                    <th>Περιγραφή</th>
                    <th>Ποσό</th>
                    <th class="col-hide-mobile">Τρόπος</th>
                    <th style="text-align:center">Ενέργειες</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr class="tx-row"
                    data-type="<?= h($t['type']) ?>"
                    data-cat="<?= h($t['category'] ?? '') ?>"
                    data-desc="<?= strtolower(h($t['description'] ?? '')) ?>"
                    data-notes="<?= strtolower(h($t['notes'] ?? '')) ?>"
                    data-name="<?= strtolower(h($t['full_name'] ?? '')) ?>"
                    data-amount="<?= (float)$t['amount'] ?>">
                    <td style="white-space:nowrap;font-size:clamp(.9rem,3.5vw,.98rem)!important"><?= formatDate($t['transaction_date']) ?></td>
                    <td>
                        <span class="badge <?= $t['type'] === 'income' ? 'badge-income' : 'badge-expense' ?>">
                            <i class="fa-solid <?= $t['type'] === 'income' ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i>
                            <?= $t['type'] === 'income' ? 'Έσοδο' : 'Έξοδο' ?>
                        </span>
                    </td>
                    <td class="col-hide-mobile" style="color:var(--muted,#8892b0)"><?= h($t['category']) ?></td>
                    <td style="max-width:200px">
                        <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px"><?= h($t['description'] ?: '—') ?></div>
                        <?php if (!empty($t['full_name'])): ?>
                        <div style="color:var(--muted,#8892b0);font-size:.78em;margin-top:.08rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px">
                            <i class="fa-solid fa-user" style="font-size:.75em"></i> <?= h($t['full_name']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="amount-cell <?= $t['type'] === 'income' ? 'text-green' : 'text-red' ?>">
                        <?= $t['type'] === 'income' ? '+' : '−' ?><?= formatMoney($t['amount']) ?>
                    </td>
                    <td class="col-hide-mobile" style="color:var(--muted,#8892b0)">
                        <?= h($payMethods[$t['payment_method']] ?? '—') ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:.3rem;justify-content:center">
                            <button class="btn btn-ghost btn-sm"
                                    onclick='openEditModal(<?= json_encode($t, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    title="Επεξεργασία" style="color:var(--gold,#f0a500)" type="button">
                                <i class="fa-solid fa-pen-to-square"></i> Επεξεργασία
                            </button>
                            <button class="btn btn-ghost btn-sm"
                                    onclick="openDeleteModal(<?= (int)$t['id'] ?>, <?= json_encode($t['description'] ?: 'Κίνηση', JSON_UNESCAPED_UNICODE) ?>, <?= json_encode(($t['type'] === 'income' ? '+' : '−') . formatMoney($t['amount']), JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($t['type']) ?>)"
                                    title="Διαγραφή" style="color:var(--red,#e63946)" type="button">
                                <i class="fa-solid fa-trash"></i> Διαγραφή
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (!$transactions): ?>
                <tr><td colspan="7">
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-receipt"></i></div>
                        <p>Δεν υπάρχουν κινήσεις για αυτόν τον μήνα</p>
                        <button class="btn btn-primary btn-sm" style="margin-top:.75rem" onclick="openAddModal()" type="button">
                            <i class="fa-solid fa-plus"></i> Προσθήκη Κίνησης
                        </button>
                    </div>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($transactions): ?>
    <div class="totals-row">
        <span style="color:var(--muted,#8892b0);font-weight:600">Σύνολο:</span>
        <span class="text-green"><i class="fa-solid fa-arrow-up"></i> <span id="liveTotalIncome"><?= formatMoney($income) ?></span></span>
        <span class="text-red"><i class="fa-solid fa-arrow-down"></i> <span id="liveTotalExpense"><?= formatMoney($expense) ?></span></span>
        <span style="border-left:1.5px solid var(--border,#1e2536);padding-left:1.25rem;margin-left:.25rem">
            = <span id="liveTotalProfit" class="<?= $profit >= 0 ? 'text-green' : 'text-red' ?>"><?= formatMoney(abs($profit)) ?></span>
        </span>
    </div>
    <?php endif; ?>

    <?php if ($txPages > 1): ?>
    <div class="pagination">
        <?php if ($txPage > 1): ?>
            <a href="<?= h(txPageUrl(1)) ?>" class="btn btn-ghost btn-pag btn-icon" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a>
            <a href="<?= h(txPageUrl($txPage-1)) ?>" class="btn btn-ghost btn-pag btn-icon" title="Προηγούμενη"><i class="fa-solid fa-angle-left"></i></a>
        <?php endif; ?>

        <?php
        $range = 2;
        $pages_to_show = [];
        for ($i = 1; $i <= $txPages; $i++) {
            if ($i === 1 || $i === $txPages || ($i >= $txPage - $range && $i <= $txPage + $range)) {
                $pages_to_show[] = $i;
            }
        }
        $prev = null;
        foreach ($pages_to_show as $i):
            if ($prev !== null && $i - $prev > 1): ?>
                <span class="pag-ellipsis">…</span>
            <?php endif; ?>
            <a href="<?= h(txPageUrl($i)) ?>"
               class="btn btn-pag <?= $i === $txPage ? 'btn-pag-active' : 'btn-ghost' ?>">
                <?= $i ?>
            </a>
        <?php $prev = $i; endforeach; ?>

        <?php if ($txPage < $txPages): ?>
            <a href="<?= h(txPageUrl($txPage+1)) ?>" class="btn btn-ghost btn-pag btn-icon" title="Επόμενη"><i class="fa-solid fa-angle-right"></i></a>
            <a href="<?= h(txPageUrl($txPages)) ?>" class="btn btn-ghost btn-pag btn-icon" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a>
        <?php endif; ?>

        <span class="pag-info">
            Σελίδα <?= $txPage ?> / <?= $txPages ?>
            &nbsp;·&nbsp;
            <?= $txTotal ?> κινήσεις
        </span>
    </div>
    <?php endif; ?>
</div>

</div>
</div>
</div>

<div class="modal-backdrop" id="txModal" role="dialog" aria-modal="true" aria-labelledby="txModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="txModalTitle">
                <i class="fa-solid fa-plus" style="color:var(--green,#2dc653)" id="txModalIcon"></i>
                <span id="txModalTitleText">Νέα Κίνηση</span>
            </div>
            <button class="modal-close" onclick="closeModal('txModal')" aria-label="Κλείσιμο" type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" id="txForm">
            <div class="modal-body">
                <input type="hidden" name="_action" value="save_tx">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="id" id="txId" value="">

                <div class="form-section-title"><i class="fa-solid fa-tags"></i> Ταξινόμηση</div>
                <div class="form-row col-2">
                    <div>
                        <label class="form-label">Τύπος</label>
                        <select name="type" id="txType" class="form-control">
                            <option value="income">Έσοδο</option>
                            <option value="expense">Έξοδο</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Κατηγορία</label>
                        <select name="category" id="txCategory" class="form-control">
                            <?php foreach ($incomeCategories as $c): ?>
                            <option value="<?= h($c) ?>" data-type="income"><?= h($c) ?></option>
                            <?php endforeach; ?>
                            <?php foreach ($expenseCategories as $c): ?>
                            <option value="<?= h($c) ?>" data-type="expense"><?= h($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-section-title"><i class="fa-solid fa-euro-sign"></i> Ποσό &amp; Ημερομηνία</div>
                <div class="form-row col-2">
                    <div>
                        <label class="form-label">Ποσό (€) <span style="color:var(--red,#e63946)">*</span></label>
                        <input type="number" step=".01" name="amount" id="txAmount" class="form-control"
                               required placeholder="0.00" min="0">
                    </div>
                    <div>
                        <label class="form-label">Ημερομηνία <span style="color:var(--red,#e63946)">*</span></label>
                        <input type="date" name="transaction_date" id="txDate" class="form-control" required>
                    </div>
                    <div class="col-span-2">
                        <label class="form-label">Περιγραφή</label>
                        <input name="description" id="txDesc" class="form-control"
                               placeholder="π.χ. Συνδρομή Ιανουαρίου">
                    </div>
                    <div>
                        <label class="form-label">Τρόπος Πληρωμής</label>
                        <select name="payment_method" id="txPayMethod" class="form-control">
                            <?php foreach ($payMethods as $v => $l): ?>
                            <option value="<?= h($v) ?>"><?= h($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Σημειώσεις</label>
                        <input name="notes" id="txNotes" class="form-control" placeholder="Προαιρετικά...">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary" style="flex:1">
                    <i class="fa-solid fa-floppy-disk"></i> Αποθήκευση
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('txModal')">
                    Ακύρωση
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <div class="modal-title" id="deleteModalTitle" style="color:var(--red,#e63946)">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Επιβεβαίωση Διαγραφής
            </div>
            <button class="modal-close" onclick="closeModal('deleteModal')" aria-label="Κλείσιμο" type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body" style="padding-top:.9rem">
            <div class="delete-icon-wrap">
                <i class="fa-solid fa-trash"></i>
            </div>
            <div class="delete-modal-title">Διαγραφή Κίνησης;</div>
            <div class="delete-modal-sub">
                Αυτή η ενέργεια <strong>δεν μπορεί να αναιρεθεί</strong>.<br>
                Η κίνηση θα διαγραφεί οριστικά.
            </div>
            <div class="delete-detail-box">
                <strong id="deleteDesc" style="font-size:1.1rem"></strong>
                <span id="deleteAmount" style="font-size:1.05rem"></span>
            </div>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="_action" value="delete_tx">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="id" id="deleteId" value="">
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')" style="flex:1;order:1">
                    <i class="fa-solid fa-arrow-left"></i> Άκυρο
                </button>
                <button type="submit" class="btn" id="deleteConfirmBtn" style="flex:1;background:var(--red,#e63946);color:#fff;order:2">
                    <i class="fa-solid fa-trash"></i> Ναι, Διέγραψέ το
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    document.querySelectorAll('.topbar').forEach(function(el){
        var t = (el.textContent || '').trim();
        if(t === '' || t === '...'){ el.remove(); return; }
        var p = getComputedStyle(el).position;
        if(p === 'fixed' || p === 'sticky'){
            el.style.setProperty('position','relative','important');
            el.style.setProperty('top','auto','important');
        }
    });
})();

(function(){
    var sb=document.getElementById('sidebar'),ov=document.getElementById('dm-overlay'),mb=document.getElementById('menuBtn');
    if(!sb||!mb)return;
    var open=function(){sb.classList.add('open');ov&&ov.classList.add('on');document.body.style.overflow='hidden';};
    var close=function(){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';};
    mb.onclick=function(e){e.stopPropagation();sb.classList.contains('open')?close():open();};
    ov&&ov.addEventListener('click',close);
    sb.querySelectorAll('a.nav-item').forEach(function(a){a.addEventListener('click',function(){if(window.innerWidth<=900)setTimeout(close,80);});});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeAllModals();close();}});
    window.addEventListener('resize',function(){if(window.innerWidth>900){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}});
})();

function openModal(id){
    document.getElementById(id).classList.add('open');
    document.body.style.overflow='hidden';
    setTimeout(function(){
        var first=document.querySelector('#'+id+' input:not([type=hidden]),#'+id+' select');
        if(first)first.focus();
    },80);
}
function closeModal(id){
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow='';
}
function closeAllModals(){
    document.querySelectorAll('.modal-backdrop').forEach(function(m){m.classList.remove('open');});
    document.body.style.overflow='';
}

document.querySelectorAll('.modal-backdrop').forEach(function(bd){
    bd.addEventListener('click',function(e){
        if(e.target===bd) closeModal(bd.id);
    });
});

function openAddModal(){
    var today=new Date().toISOString().split('T')[0];
    document.getElementById('txModalTitleText').textContent='Νέα Κίνηση';
    document.getElementById('txModalIcon').className='fa-solid fa-plus';
    document.getElementById('txModalIcon').style.color='var(--green,#2dc653)';
    document.getElementById('txId').value='';
    document.getElementById('txType').value='income';
    filterTxCategories('income');
    document.getElementById('txCategory').selectedIndex=0;
    document.getElementById('txAmount').value='';
    document.getElementById('txDate').value=today;
    document.getElementById('txDesc').value='';
    document.getElementById('txPayMethod').value='cash';
    document.getElementById('txNotes').value='';
    openModal('txModal');
}

function openEditModal(tx){
    document.getElementById('txModalTitleText').textContent='Επεξεργασία Κίνησης';
    document.getElementById('txModalIcon').className='fa-solid fa-pen-to-square';
    document.getElementById('txModalIcon').style.color='var(--gold,#f0a500)';
    document.getElementById('txId').value=tx.id||'';
    document.getElementById('txType').value=tx.type||'income';
    filterTxCategories(tx.type||'income');
    document.getElementById('txCategory').value=tx.category||'';
    document.getElementById('txAmount').value=tx.amount||'';
    document.getElementById('txDate').value=tx.transaction_date||'';
    document.getElementById('txDesc').value=tx.description||'';
    document.getElementById('txPayMethod').value=tx.payment_method||'cash';
    document.getElementById('txNotes').value=tx.notes||'';
    openModal('txModal');
}

function openDeleteModal(id, desc, amount, type){
    document.getElementById('deleteId').value=id;
    document.getElementById('deleteDesc').textContent=desc;
    var amountEl=document.getElementById('deleteAmount');
    amountEl.textContent=amount;
    amountEl.style.color=type==='income'?'var(--green,#2dc653)':'var(--red,#e63946)';
    openModal('deleteModal');
}

function filterTxCategories(type) {
    var sel = document.getElementById('txCategory');
    if(!sel) return;
    var currentVal = sel.value;
    Array.from(sel.options).forEach(function(opt){
        var optType = opt.getAttribute('data-type');
        opt.hidden = optType && optType !== type;
    });
    var found = false;
    Array.from(sel.options).forEach(function(opt){
        if(!opt.hidden && opt.value === currentVal) found = true;
    });
    if(!found) {
        var firstVisible = Array.from(sel.options).find(function(o){ return !o.hidden; });
        if(firstVisible) sel.value = firstVisible.value;
    }
}
(function(){
    var typeSel = document.getElementById('txType');
    if(typeSel) {
        typeSel.addEventListener('change', function(){ filterTxCategories(this.value); });
        filterTxCategories(typeSel.value);
    }
})();

(function(){
    var tx=<?= $editTxJson ?>;
    if(tx) openEditModal(tx);
})();

// ── Live filter ──
function liveFilter() {
    var type   = document.getElementById('liveType').value;
    var cat    = document.getElementById('liveCat').value;
    var q      = document.getElementById('liveSearch').value.toLowerCase().trim();
    var rows   = document.querySelectorAll('.tx-row');
    var shown  = 0;
    var clearBtn = document.getElementById('liveClearBtn');

    rows.forEach(function(row) {
        var matchType = !type  || row.dataset.type === type;
        var matchCat  = !cat   || row.dataset.cat  === cat;
        var matchQ    = !q     || row.dataset.desc.indexOf(q) >= 0
                               || row.dataset.notes.indexOf(q) >= 0
                               || row.dataset.name.indexOf(q) >= 0;
        var show = matchType && matchCat && matchQ;
        row.style.display = show ? '' : 'none';
        if (show) shown++;
    });

    // show/hide clear button
    if (clearBtn) clearBtn.style.display = (type || cat || q) ? '' : 'none';

    // update totals row
    updateLiveTotals();
}

function liveClearFilters() {
    document.getElementById('liveType').value   = '';
    document.getElementById('liveCat').value    = '';
    document.getElementById('liveSearch').value = '';
    liveFilter();
}

function updateLiveTotals() {
    var incomeEl  = document.getElementById('liveTotalIncome');
    var expenseEl = document.getElementById('liveTotalExpense');
    var profitEl  = document.getElementById('liveTotalProfit');
    if (!incomeEl) return;

    var income = 0, expense = 0;
    document.querySelectorAll('.tx-row').forEach(function(row) {
        if (row.style.display === 'none') return;
        var amt = parseFloat(row.dataset.amount || 0);
        if (row.dataset.type === 'income')  income  += amt;
        if (row.dataset.type === 'expense') expense += amt;
    });
    var profit = income - expense;
    var fmt = function(n) {
        return n.toLocaleString('el-GR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
    };
    incomeEl.textContent  = fmt(income);
    expenseEl.textContent = fmt(expense);
    if (profitEl) {
        profitEl.textContent = fmt(Math.abs(profit));
        profitEl.style.color = profit >= 0 ? 'var(--green,#2dc653)' : 'var(--red,#e63946)';
    }
}

(function(){
    var ctx=document.getElementById('finChart');
    if(!ctx)return;
    new Chart(ctx.getContext('2d'),{
        type:'bar',
        data:{
            labels:<?= json_encode(array_column($chartData,'month'), JSON_UNESCAPED_UNICODE) ?>,
            datasets:[
                {label:'Έσοδα', data:<?= json_encode(array_column($chartData,'income')) ?>, backgroundColor:'rgba(45,198,83,.55)', borderColor:'rgba(45,198,83,1)', borderWidth:2,borderRadius:7},
                {label:'Έξοδα', data:<?= json_encode(array_column($chartData,'expense')) ?>, backgroundColor:'rgba(230,57,70,.55)', borderColor:'rgba(230,57,70,1)', borderWidth:2,borderRadius:7}
            ]
        },
        options:{
            responsive:true,
            plugins:{legend:{labels:{color:'#7a849e',font:{size:13,weight:'700'},padding:14}}},
            scales:{
                x:{ticks:{color:'#7a849e',font:{size:12}},grid:{color:'rgba(255,255,255,.05)'}},
                y:{ticks:{color:'#7a849e',font:{size:12}},grid:{color:'rgba(255,255,255,.05)'}}
            }
        }
    });
})();
</script>
</body>
</html>