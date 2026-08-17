<?php

/**
 * ============================================================
 * pages/economics_reports.php — Οικονομικά & Αναφορές (Pro)
 * ============================================================
 * PURPOSE:
 *   Merged view: CRUD transactions (έσοδα/έξοδα), bank transfer
 *   requests, μηνιαία γραφήματα, κατηγορίες, + ετήσιες αναφορές
 *   (οικονομικά, αθλητές, συνδρομές, ζώνες).
 *   Tab-based toggle between Οικονομικά and Αναφορές.
 *   Απαιτεί Pro plan.
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

/* ══════════════════════════════════════════════════════════════
   HELPER FUNCTIONS
══════════════════════════════════════════════════════════════ */

if (!function_exists('greekMonthName')) {
    function greekMonthName(int $month): string {
        $map = [1=>'Ιανουάριος',2=>'Φεβρουάριος',3=>'Μάρτιος',4=>'Απρίλιος',5=>'Μάιος',6=>'Ιούνιος',7=>'Ιούλιος',8=>'Αύγουστος',9=>'Σεπτέμβριος',10=>'Οκτώβριος',11=>'Νοέμβριος',12=>'Δεκέμβριος'];
        return $map[$month] ?? '';
    }
}

if (!function_exists('greekMonthShort')) {
    function greekMonthShort(int $month): string {
        $map = [1=>'Ιαν',2=>'Φεβ',3=>'Μαρ',4=>'Απρ',5=>'Μάι',6=>'Ιουν',7=>'Ιουλ',8=>'Αυγ',9=>'Σεπ',10=>'Οκτ',11=>'Νοε',12=>'Δεκ'];
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

/* ══════════════════════════════════════════════════════════════
   ECONOMICS DATA
══════════════════════════════════════════════════════════════ */

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

/* ── Active tab ── */
$activeTab = $_GET['tab'] ?? 'economics';
if (!in_array($activeTab, ['economics', 'reports'], true)) {
    $activeTab = 'economics';
}

/* ── POST: save_tx ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['_action'] ?? '') === 'save_tx')) {
    verifyCsrf();

    $a  = $_POST;
    $id = (int)($a['id'] ?? 0);

    $type = trim($a['type'] ?? '');
    if (!in_array($type, ['income', 'expense'], true)) {
        flash('Μη έγκυρος τύπος κίνησης.', 'danger');
        redirect(APP_URL . '/pages/economics_reports.php?tab=economics&month=' . urlencode($month));
    }

    $category         = trim(strip_tags($a['category'] ?? '')) ?: null;
    $amount           = (float)($a['amount'] ?? 0);
    $description      = trim($a['description'] ?? '') ?: null;
    $transaction_date = trim($a['transaction_date'] ?? '') ?: null;
    $payment_method   = trim($a['payment_method'] ?? '') ?: null;
    $notes            = trim($a['notes'] ?? '') ?: null;

    if ($amount < 0) {
        flash('Το ποσό δεν μπορεί να είναι αρνητικό.', 'danger');
        redirect(APP_URL . '/pages/economics_reports.php?tab=economics&month=' . urlencode($month));
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

    redirect(APP_URL . '/pages/economics_reports.php?tab=economics&month=' . urlencode($month));
}

/* ── POST: delete_tx ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['_action'] ?? '') === 'delete_tx')) {
    verifyCsrf();

    $db->prepare("DELETE FROM transactions WHERE id=? AND school_id=?")
       ->execute([(int)($_POST['id'] ?? 0), $sid]);

    flash('Η εγγραφή διαγράφηκε.', 'danger');
    redirect(APP_URL . '/pages/economics_reports.php?tab=economics&month=' . urlencode($month));
}

/* ── Economics: Month transactions ── */
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

$incomeStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='income' AND transaction_date BETWEEN ? AND ?");
$incomeStmt->execute([$sid, $mStart, $mEnd]);
$income = (float)$incomeStmt->fetchColumn();

$expenseStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='expense' AND transaction_date BETWEEN ? AND ?");
$expenseStmt->execute([$sid, $mStart, $mEnd]);
$expense = (float)$expenseStmt->fetchColumn();

$chartData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $s = $m . '-01';
    $e = date('Y-m-t', strtotime($s));
    $inc = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='income' AND transaction_date BETWEEN ? AND ?");
    $inc->execute([$sid, $s, $e]);
    $exp = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='expense' AND transaction_date BETWEEN ? AND ?");
    $exp->execute([$sid, $s, $e]);
    $chartData[] = [
        'month'   => formatGreekShortMonthYear($s),
        'income'  => (float)$inc->fetchColumn(),
        'expense' => (float)$exp->fetchColumn(),
    ];
}

$editTxJson = 'null';
if (isset($_GET['edit_id'])) {
    $stmtEdit = $db->prepare("SELECT * FROM transactions WHERE id=? AND school_id=?");
    $stmtEdit->execute([(int)$_GET['edit_id'], $sid]);
    $et = $stmtEdit->fetch(PDO::FETCH_ASSOC);
    if ($et) $editTxJson = json_encode($et, JSON_UNESCAPED_UNICODE);
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

/* ══════════════════════════════════════════════════════════════
   REPORTS DATA
══════════════════════════════════════════════════════════════ */

$yearParam  = $_GET['year'] ?? date('Y');
$isAllYears = ($yearParam === 'all');

if ($isAllYears) {
    $year = 'all';
} else {
    $year = (int)$yearParam;
    if ($year < 2000 || $year > 2099) $year = (int)date('Y');
}

$schoolStmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$schoolStmt->execute([$sid]);
$school = $schoolStmt->fetch();

/* First data year */
$startYearCandidates = [];
if (!empty($school['created_at'])) {
    $createdTs = strtotime($school['created_at']);
    if ($createdTs) $startYearCandidates[] = (int)date('Y', $createdTs);
}
$stmtMinAth = $db->prepare("SELECT MIN(registration_date) FROM athletes WHERE school_id = ? AND registration_date IS NOT NULL");
$stmtMinAth->execute([$sid]);
$minAthDate = $stmtMinAth->fetchColumn();
if ($minAthDate && $minAthDate !== '0000-00-00') $startYearCandidates[] = (int)date('Y', strtotime($minAthDate));

$stmtMinTrans = $db->prepare("SELECT MIN(transaction_date) FROM transactions WHERE school_id = ? AND transaction_date IS NOT NULL");
$stmtMinTrans->execute([$sid]);
$minTransDate = $stmtMinTrans->fetchColumn();
if ($minTransDate && $minTransDate !== '0000-00-00') $startYearCandidates[] = (int)date('Y', strtotime($minTransDate));

$stmtMinSub = $db->prepare("SELECT MIN(valid_from) FROM subscriptions WHERE school_id = ? AND valid_from IS NOT NULL");
$stmtMinSub->execute([$sid]);
$minSubDate = $stmtMinSub->fetchColumn();
if ($minSubDate && $minSubDate !== '0000-00-00') $startYearCandidates[] = (int)date('Y', strtotime($minSubDate));

$currentYear   = (int)date('Y');
$firstDataYear = !empty($startYearCandidates) ? min($startYearCandidates) : $currentYear;
if ($firstDataYear < 2000 || $firstDataYear > $currentYear) $firstDataYear = $currentYear;
if (!$isAllYears && $year < $firstDataYear) $year = $firstDataYear;

$yearTitle = $isAllYears ? 'Όλα τα έτη' : (string)$year;

/* Monthly income / expense for reports */
$monthlyIncome  = [];
$monthlyExpense = [];
for ($m = 1; $m <= 12; $m++) {
    if ($isAllYears) {
        $i = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE school_id = ? AND type = 'income' AND MONTH(transaction_date) = ?");
        $i->execute([$sid, $m]);
        $monthlyIncome[] = (float)$i->fetchColumn();
        $x = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE school_id = ? AND type = 'expense' AND MONTH(transaction_date) = ?");
        $x->execute([$sid, $m]);
        $monthlyExpense[] = (float)$x->fetchColumn();
    } else {
        $mm = sprintf('%04d-%02d', $year, $m);
        $s  = "$mm-01";
        $e  = date('Y-m-t', strtotime($s));
        $i = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE school_id = ? AND type = 'income' AND transaction_date BETWEEN ? AND ?");
        $i->execute([$sid, $s, $e]);
        $monthlyIncome[] = (float)$i->fetchColumn();
        $x = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE school_id = ? AND type = 'expense' AND transaction_date BETWEEN ? AND ?");
        $x->execute([$sid, $s, $e]);
        $monthlyExpense[] = (float)$x->fetchColumn();
    }
}
$rTotalIncome  = array_sum($monthlyIncome);
$rTotalExpense = array_sum($monthlyExpense);
$rProfit       = $rTotalIncome - $rTotalExpense;

/* Detailed transactions for reports */
if ($isAllYears) {
    $transStmt = $db->prepare("SELECT t.*, a.full_name AS athlete_name FROM transactions t LEFT JOIN athletes a ON a.id = t.athlete_id WHERE t.school_id = ? ORDER BY t.transaction_date DESC");
    $transStmt->execute([$sid]);
} else {
    $transStmt = $db->prepare("SELECT t.*, a.full_name AS athlete_name FROM transactions t LEFT JOIN athletes a ON a.id = t.athlete_id WHERE t.school_id = ? AND YEAR(t.transaction_date) = ? ORDER BY t.transaction_date DESC");
    $transStmt->execute([$sid, $year]);
}
$rTransactions = $transStmt->fetchAll();

/* Income / expense categories */
if ($isAllYears) {
    $catStmt = $db->prepare("SELECT category, SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE school_id = ? AND type = 'income' GROUP BY category ORDER BY total DESC");
    $catStmt->execute([$sid]);
    $catList = $catStmt->fetchAll();
    $expCatStmt = $db->prepare("SELECT category, SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE school_id = ? AND type = 'expense' GROUP BY category ORDER BY total DESC");
    $expCatStmt->execute([$sid]);
    $expCatList = $expCatStmt->fetchAll();
} else {
    $catStmt = $db->prepare("SELECT category, SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE school_id = ? AND type = 'income' AND YEAR(transaction_date) = ? GROUP BY category ORDER BY total DESC");
    $catStmt->execute([$sid, $year]);
    $catList = $catStmt->fetchAll();
    $expCatStmt = $db->prepare("SELECT category, SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE school_id = ? AND type = 'expense' AND YEAR(transaction_date) = ? GROUP BY category ORDER BY total DESC");
    $expCatStmt->execute([$sid, $year]);
    $expCatList = $expCatStmt->fetchAll();
}

/* Athletes */
$stmtAC = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id = ? AND active = 1");
$stmtAC->execute([$sid]);
$athleteCount = (int)$stmtAC->fetchColumn();

if ($isAllYears) {
    $stmtANew = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id = ? AND registration_date IS NOT NULL");
    $stmtANew->execute([$sid]);
    $newAthletes = (int)$stmtANew->fetchColumn();
    $newAthletesLabel = 'συνολικά';
} else {
    $stmtANew = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id = ? AND YEAR(registration_date) = ?");
    $stmtANew->execute([$sid, $year]);
    $newAthletes = (int)$stmtANew->fetchColumn();
    $newAthletesLabel = 'φέτος';
}

/* Department distribution (replaces removed sport column) */
$sportList = [];

/* Departments */
$deptStmt = $db->prepare("
    SELECT d.*, (SELECT COUNT(*) FROM athletes a WHERE a.department_id = d.id AND a.active = 1) AS athlete_count
    FROM departments d WHERE d.school_id = ? AND d.active = 1 ORDER BY d.name
");
$deptStmt->execute([$sid]);
$deptList = $deptStmt->fetchAll();

/* Subscriptions */
$payStatsStmt = $db->prepare("SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total FROM subscriptions WHERE school_id = ? GROUP BY status");
$payStatsStmt->execute([$sid]);
$payStats = $payStatsStmt->fetchAll();

$subTotals = ['paid'=>['cnt'=>0,'total'=>0],'pending'=>['cnt'=>0,'total'=>0],'overdue'=>['cnt'=>0,'total'=>0]];
foreach ($payStats as $ps) {
    if (isset($subTotals[$ps['status']])) {
        $subTotals[$ps['status']] = ['cnt'=>(int)$ps['cnt'], 'total'=>(float)$ps['total']];
    }
}

if ($isAllYears) {
    $paidSubs = $db->prepare("SELECT s.*, a.full_name FROM subscriptions s JOIN athletes a ON a.id = s.athlete_id WHERE s.school_id = ? AND s.status = 'paid' ORDER BY s.paid_at DESC, s.valid_from DESC LIMIT 100");
    $paidSubs->execute([$sid]);
} else {
    $paidSubs = $db->prepare("SELECT s.*, a.full_name FROM subscriptions s JOIN athletes a ON a.id = s.athlete_id WHERE s.school_id = ? AND s.status = 'paid' AND (YEAR(s.paid_at) = ? OR YEAR(s.valid_from) = ?) ORDER BY s.paid_at DESC LIMIT 100");
    $paidSubs->execute([$sid, $year, $year]);
}
$paidSubList = $paidSubs->fetchAll();

$overdueSubs = $db->prepare("SELECT s.*, a.full_name, a.phone, a.parent_phone FROM subscriptions s JOIN athletes a ON a.id = s.athlete_id WHERE s.school_id = ? AND s.status = 'overdue' ORDER BY s.valid_until ASC LIMIT 50");
$overdueSubs->execute([$sid]);
$overdueSubList = $overdueSubs->fetchAll();

/* Top athletes */
$topAth = $db->prepare("
    SELECT a.full_name, COUNT(s.id) AS sub_count, COALESCE(SUM(s.amount), 0) AS total_paid
    FROM athletes a LEFT JOIN subscriptions s ON s.athlete_id = a.id AND s.status = 'paid'
    WHERE a.school_id = ? AND a.active = 1
    GROUP BY a.id ORDER BY total_paid DESC LIMIT 20
");
$topAth->execute([$sid]);
$topAthList = $topAth->fetchAll();

$months      = ['Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος','Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
$monthsShort = ['Ιαν','Φεβ','Μαρ','Απρ','Μαΐ','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];

renderHead('Οικονομικά & Αναφορές');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<style>
/* ═══════════════════════════════════════════
   MERGED: ΟΙΚΟΝΟΜΙΚΑ & ΑΝΑΦΟΡΕΣ
═══════════════════════════════════════════ */
input,input:hover,input:focus,select,select:hover,select:focus,textarea,textarea:hover,textarea:focus{box-shadow:none!important;-webkit-box-shadow:none!important;background-image:none!important}
input:-webkit-autofill,input:-webkit-autofill:hover,input:-webkit-autofill:focus{-webkit-box-shadow:0 0 0 1000px #1a1f2e inset!important;-webkit-text-fill-color:var(--text,#e2e8f0)!important}

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

.page-body { animation: fadeIn .3s ease both; padding: .85rem !important; }
.anim-1 { animation: fadeUp .4s ease-out .04s both; }
.anim-2 { animation: fadeUp .4s ease-out .1s both; }
.anim-3 { animation: fadeUp .4s ease-out .16s both; }
.anim-4 { animation: fadeUp .4s ease-out .22s both; }

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; opacity: 1 !important; }
}

/* ── Tab Toggler ── */
.tab-toggle-bar {
    display: flex;
    gap: 0;
    margin-bottom: 1rem;
    background: var(--surface2,#141927);
    border-radius: 14px;
    padding: 4px;
    border: 1.5px solid var(--border,#1e2536);
    width: fit-content;
}
.tab-toggle-btn {
    min-height: 48px;
    padding: .6rem 1.4rem;
    font-size: clamp(.95rem,4vw,1.05rem) !important;
    font-weight: 700;
    border: none;
    border-radius: 11px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    transition: all .2s;
    background: transparent;
    color: var(--muted,#8892b0);
    white-space: nowrap;
    font-family: inherit;
}
.tab-toggle-btn:hover { background: rgba(255,255,255,.05); color: var(--text,#e2e8f0); }
.tab-toggle-btn.active {
    background: var(--red,#e63946);
    color: #fff;
    box-shadow: 0 4px 16px rgba(230,57,70,.35);
}

.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ── Page Header ── */
.page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .6rem; margin-bottom: .9rem; }
.page-header h2 { font-size: clamp(1.2rem,5vw,1.5rem) !important; font-weight: 800; display: flex; align-items: center; gap: .5rem; margin: 0; }

/* ── Stat Cards ── */
.stat-cards-row { display: grid; grid-template-columns: repeat(3,1fr); gap: .75rem; margin-bottom: .9rem; }
.stat-card { border-radius: 16px; padding: .95rem .85rem .85rem; display: flex; flex-direction: column; gap: .3rem; }
.stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; margin-bottom: .2rem; }
.icon-green { background: rgba(45,198,83,.15); color: var(--green,#2dc653); }
.icon-red   { background: rgba(230,57,70,.15);  color: var(--red,#e63946); }
.icon-blue  { background: rgba(59,130,246,.15); color: #3b82f6; }
.icon-gold  { background: rgba(240,165,0,.15); color: #f0a500; }
.stat-val { font-size: clamp(1.3rem,5.5vw,1.85rem) !important; font-weight: 800; line-height: 1.05; }
.stat-lbl { font-size: clamp(.85rem,3.5vw,.95rem) !important; color: var(--muted,#8892b0); font-weight: 600; }
.text-green { color: var(--green,#2dc653) !important; }
.text-red   { color: var(--red,#e63946) !important; }
.text-gold  { color: #f4a535 !important; }

/* ── Cards ── */
.chart-card, .tx-card { border-radius: 16px; margin-bottom: .9rem; overflow: hidden; }
.card { border-radius: 16px; overflow: hidden; margin-bottom: .9rem; }
.card-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; padding: .85rem 1rem; border-bottom: 1px solid var(--border,#1e2536); }
.card-title { font-size: clamp(1rem,4vw,1.1rem) !important; font-weight: 800; display: flex; align-items: center; gap: .45rem; }

/* ── Filters ── */
.filters-bar { display: flex; flex-wrap: wrap; gap: .45rem; align-items: center; }
.search-bar { position: relative; flex: 1; min-width: 120px; }
.search-bar .si { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--muted,#8892b0); pointer-events: none; font-size: .95rem; }
.search-bar input { width: 100%; padding-left: 2.25rem !important; }

.fc { font-size: clamp(.9rem,3.8vw,1rem) !important; min-height: 46px; padding: .5rem .75rem; border-radius: 10px !important; background: var(--surface2,#141927); border: 1.5px solid var(--border,#1e2536); color: var(--text,#e2e8f0); font-family: inherit; }
.fc:focus { outline: none; border-color: var(--red,#e63946) !important; box-shadow: 0 0 0 3px rgba(230,57,70,.15) !important; }
select.fc { cursor: pointer; }

/* ── Tables ── */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.table-wrap table { width: 100%; border-collapse: collapse; }
@media (max-width: 600px) {
    .table-wrap table { width: max-content; min-width: 100%; }
    .table-wrap td, .table-wrap th { white-space: nowrap; }
    .col-hide-mobile { display: none !important; }
}
.table-wrap th { font-size: clamp(.8rem,3vw,.88rem) !important; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; padding: .65rem .9rem; color: var(--muted,#8892b0); border-right: 1px solid rgba(255,255,255,.06); }
.table-wrap th:last-child { border-right: none; }
.table-wrap td { font-size: clamp(.9rem,3.5vw,.98rem) !important; padding: .7rem .9rem; vertical-align: middle; border-top: 1px solid rgba(255,255,255,.05); border-right: 1px solid rgba(255,255,255,.04); }
.table-wrap td:last-child { border-right: none; }
.table-wrap tbody tr:hover { background: rgba(255,255,255,.03); }
.amount-cell { font-size: clamp(1rem,4vw,1.1rem) !important; font-weight: 800; }

/* ── Buttons ── */
.btn { min-height: 46px; font-size: clamp(.95rem,4vw,1.02rem) !important; font-weight: 700 !important; display: inline-flex; align-items: center; justify-content: center; gap: .45rem; border-radius: 11px; transition: all .17s; text-decoration: none; padding: .5rem 1rem; cursor: pointer; border: none; white-space: nowrap; font-family: inherit; }
.btn:active { transform: scale(.96); }
.btn-sm { min-height: 42px; padding: .42rem .85rem; font-size: clamp(.9rem,3.8vw,.98rem) !important; }
.btn-icon { min-height: 42px; min-width: 42px; padding: 0; border-radius: 10px; }
.btn-lg { min-height: 56px; padding: .7rem 1.4rem; font-size: clamp(1.05rem,4.5vw,1.15rem) !important; border-radius: 13px; }
.w-100 { width: 100%; }

/* ── Badges ── */
.badge { display: inline-flex; align-items: center; gap: .3rem; padding: .32rem .8rem; border-radius: 999px; font-size: clamp(.82rem,3.5vw,.9rem) !important; font-weight: 700; white-space: nowrap; }
.badge-basic   { background: rgba(255,255,255,.08); color: var(--text,#e2e8f0); }
.badge-income  { background: rgba(45,198,83,.15);   color: var(--green,#2dc653); }
.badge-expense { background: rgba(230,57,70,.15);   color: var(--red,#e63946); }
.badge-paid    { background: rgba(45,198,83,.15);   color: #2dc653; }
.badge-pending { background: rgba(244,165,53,.15);  color: #f4a535; }
.badge-overdue { background: rgba(230,57,70,.15);   color: #e63946; }

/* ── Nav ── */
.nav-item { min-height: 48px !important; font-size: clamp(.95rem,3.5vw,1.02rem) !important; font-weight: 600 !important; padding: .65rem .9rem !important; border-radius: 10px !important; display: flex !important; align-items: center !important; gap: .7rem !important; transition: background .15s !important; text-decoration: none; }
.nav-item .icon { width: 22px; text-align: center; font-size: 1rem; flex-shrink: 0; }
.sidebar-school { margin:.25rem 1rem!important;padding:0!important;display:flex!important;align-items:center!important;font-weight:700!important;font-size:clamp(.85rem,3vw,.95rem)!important;color:var(--text,#f0f2ff)!important;background:none!important;border:none!important;box-shadow:none!important;overflow-wrap:anywhere!important;word-break:break-word!important; }
.sidebar-school:hover,.sidebar-school:focus{background:none!important;outline:none!important}

/* ── Empty State ── */
.empty-state { text-align: center; padding: 2.5rem 1rem; }
.empty-icon { font-size: 2.5rem; opacity:.4; margin-bottom: .65rem; }
.empty-state p { font-size: clamp(.95rem,3.5vw,1.05rem) !important; color: var(--muted,#8892b0); margin: 0; }

/* ── Modal ── */
.modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.72); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); z-index: 10000; align-items: center; justify-content: center; padding: 1rem; overflow-y: auto; }
.modal-backdrop.open { display: flex; }
.modal-box { background: var(--surface,#0d1117); border: 1.5px solid var(--border,#1e2536); border-radius: 22px; width: 100%; max-width: 520px; padding: 0; animation: popIn .28s cubic-bezier(.2,.8,.2,1) both; position: relative; box-shadow: 0 24px 80px rgba(0,0,0,.7); }
.modal-box.modal-sm { max-width: 400px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 1.1rem 1.25rem .9rem; border-bottom: 1.5px solid var(--border,#1e2536); }
.modal-title { font-size: clamp(1.1rem,4.5vw,1.3rem) !important; font-weight: 800; display: flex; align-items: center; gap: .5rem; }
.modal-close { width: 40px; height: 40px; border-radius: 10px; background: rgba(255,255,255,.06); border: none; color: var(--muted,#8892b0); font-size: 1.15rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .15s, color .15s; flex-shrink: 0; }
.modal-close:hover { background: rgba(230,57,70,.18); color: var(--red,#e63946); }
.modal-body { padding: 1.1rem 1.25rem; }
.modal-footer { display: flex; gap: .6rem; flex-wrap: wrap; padding: 0 1.25rem 1.25rem; }

/* ── Form ── */
.form-section-title { font-size: clamp(.82rem,3.5vw,.9rem) !important; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: var(--muted,#8892b0); margin-bottom: .7rem; margin-top: .2rem; display: flex; align-items: center; gap: .4rem; padding-bottom: .5rem; border-bottom: 1px solid var(--border,#1e2536); }
.form-row { display: grid; gap: .8rem; margin-bottom: .8rem; }
.form-row.col-2 { grid-template-columns: 1fr 1fr; }
.col-span-2 { grid-column: span 2; }
.form-label { font-size: clamp(.95rem,4vw,1.05rem) !important; font-weight: 700; display: block; margin-bottom: .35rem; }
.form-control { font-size: clamp(.95rem,4vw,1.05rem) !important; min-height: 50px; padding: .65rem .9rem; border-radius: 11px !important; border: 1.5px solid var(--border,#1e2536); background: var(--surface2,#141927); color: var(--text,#e2e8f0); width: 100%; transition: border-color .2s, box-shadow .2s; font-family: inherit; }
.form-control:focus { outline: none; border-color: var(--red,#e63946) !important; box-shadow: 0 0 0 3px rgba(230,57,70,.15) !important; }
select.form-control { cursor: pointer; }

/* ── Delete Modal ── */
.delete-icon-wrap { width: 80px; height: 80px; border-radius: 20px; background: rgba(230,57,70,.12); border: 2px solid rgba(230,57,70,.3); display: flex; align-items: center; justify-content: center; font-size: 2.4rem; color: var(--red,#e63946); margin: 0 auto .9rem; animation: popIn .3s .1s both; }
.delete-modal-title { font-size: clamp(1.25rem,5.5vw,1.6rem) !important; font-weight: 800; text-align: center; margin-bottom: .5rem; line-height: 1.2; }
.delete-modal-sub { font-size: clamp(.95rem,4vw,1.08rem) !important; color: var(--muted,#8892b0); text-align: center; margin-bottom: 1rem; line-height: 1.5; }
.delete-detail-box { background: rgba(230,57,70,.07); border: 1.5px solid rgba(230,57,70,.2); border-radius: 13px; padding: .9rem 1rem; margin-bottom: 1.1rem; font-size: clamp(.95rem,4vw,1.05rem) !important; font-weight: 600; }
.delete-detail-box strong { display: block; margin-bottom: .25rem; font-size: 1.1rem !important; }

/* ── Totals / Pagination ── */
.totals-row { display: flex; align-items: center; justify-content: flex-end; gap: 1.25rem; padding: .8rem 1rem; border-top: 1px solid var(--border,#1e2536); flex-wrap: wrap; }
.totals-row span { font-size: clamp(.9rem,3.8vw,1rem) !important; font-weight: 800; }

.pagination { display: flex; align-items: center; justify-content: center; gap: .35rem; padding: 1rem 1rem .9rem; border-top: 1px solid var(--border,#1e2536); flex-wrap: wrap; }
.btn-pag { min-width: 48px !important; min-height: 48px !important; font-size: clamp(1rem,4.5vw,1.15rem) !important; font-weight: 800 !important; border-radius: 12px !important; padding: .4rem .5rem !important; }
.btn-pag-active { background: var(--red,#e63946) !important; color: #fff !important; box-shadow: 0 4px 16px rgba(230,57,70,.35); }
.pag-ellipsis { font-size: clamp(1rem,4vw,1.15rem); font-weight: 800; color: var(--muted,#8892b0); padding: 0 .2rem; user-select: none; }
.pag-info { width: 100%; text-align: center; font-size: clamp(.88rem,3.8vw,.98rem) !important; color: var(--muted,#8892b0); font-weight: 600; margin-top: .3rem; }

/* ── Reports-specific ── */
.section-divider { display: flex; align-items: center; gap: .75rem; margin: 1.5rem 0 1rem; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: var(--muted,#8892b0); }
.section-divider::after { content: ''; flex: 1; height: 1px; background: var(--border,#1e2536); }

.stat-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 1rem; margin-bottom: 1rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.grid-3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }

.progress { height: 8px; border-radius: 999px; background: rgba(255,255,255,.07); overflow: hidden; margin-top: .3rem; }
.progress-bar { height: 100%; border-radius: 999px; }
.progress-bar.green { background: #2dc653; }
.progress-bar.red { background: #e63946; }
.progress-bar.blue { background: #3b82f6; }

/* ── Responsive ── */
@media (max-width: 700px) {
    .page-body { padding: .65rem !important; }
    .stat-cards-row { gap: .5rem; grid-template-columns: 1fr !important; }
    .stat-row { grid-template-columns: 1fr 1fr !important; }
    .grid-2, .grid-3 { grid-template-columns: 1fr !important; }
    .form-row.col-2 { grid-template-columns: 1fr !important; }
    .col-span-2 { grid-column: span 1; }
    .col-hide-mobile { display: none !important; }
    .modal-box { border-radius: 18px; }
    .tab-toggle-bar { width: 100%; }
    .tab-toggle-btn { flex: 1; justify-content: center; }
}
@media (max-width: 480px) {
    .stat-cards-row { grid-template-columns: 1fr !important; }
    .stat-row { grid-template-columns: 1fr 1fr !important; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; }
}

/* ══════════════════════════════════════════
   PRINT STYLES
══════════════════════════════════════════ */
@media print {
    @page { size: A4 portrait; margin: 18mm 15mm 18mm 15mm; }
    @page :first { margin-top: 12mm; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

    .sidebar, .topbar, #dm-overlay, .no-print,
    .page-header .btn, .page-header select,
    .screen-only, #menuBtn, .tab-toggle-bar,
    #economicsPanel { display: none !important; }

    #reportsPanel { display: block !important; }

    .chart-container canvas { display: none !important; }
    #print-chart-wrap { display: block !important; }

    html, body { background: #fff !important; color: #1a1a2e !important; font-family: 'Arial', Helvetica, sans-serif !important; font-size: 9.5pt !important; line-height: 1.4 !important; margin: 0 !important; padding: 0 !important; }
    .app-layout { display: block !important; }
    .main-content { margin-left: 0 !important; width: 100% !important; }
    .page-body { padding: 0 !important; margin: 0 !important; }

    .print-header, .print-footer, .print-only { display: block !important; }

    .print-header { margin-bottom: 8mm !important; }
    .ph-top { display: flex !important; align-items: flex-start !important; justify-content: space-between !important; padding-bottom: 5mm !important; border-bottom: 3px solid #1a1a2e !important; margin-bottom: 4mm !important; }
    .ph-logo-block .ph-appname { font-size: 22pt !important; font-weight: 900 !important; color: #1a1a2e !important; letter-spacing: .06em !important; line-height: 1 !important; }
    .ph-logo-block .ph-appname span { color: #c0392b !important; }
    .ph-logo-block .ph-tagline { font-size: 8pt !important; color: #666 !important; margin-top: 2px !important; letter-spacing: .04em !important; }
    .ph-school-block { text-align: right !important; }
    .ph-school-block .ph-school-name { font-size: 13pt !important; font-weight: 900 !important; color: #1a1a2e !important; text-transform: uppercase !important; letter-spacing: .05em !important; }
    .ph-school-block .ph-school-meta { font-size: 8pt !important; color: #555 !important; margin-top: 3px !important; line-height: 1.6 !important; }
    .ph-banner { background: #1a1a2e !important; color: #fff !important; padding: 3mm 5mm !important; display: flex !important; justify-content: space-between !important; align-items: center !important; border-radius: 3px !important; }
    .ph-banner .ph-report-title { font-size: 11pt !important; font-weight: 900 !important; letter-spacing: .08em !important; text-transform: uppercase !important; }
    .ph-banner .ph-report-meta { font-size: 8pt !important; color: #ccc !important; }

    .kpi-bar { display: grid !important; grid-template-columns: repeat(4,1fr) !important; gap: 3mm !important; margin: 5mm 0 !important; page-break-inside: avoid !important; }
    .kpi-item { border: 1.5px solid #ddd !important; border-radius: 4px !important; padding: 3mm 4mm !important; background: #fafafa !important; }
    .kpi-item.kpi-income  { border-left: 4px solid #1a7a2e !important; }
    .kpi-item.kpi-expense { border-left: 4px solid #c0392b !important; }
    .kpi-item.kpi-profit  { border-left: 4px solid #1a5276 !important; }
    .kpi-item.kpi-athletes{ border-left: 4px solid #b7770d !important; }
    .kpi-item .kpi-label { font-size: 7pt !important; color: #666 !important; font-weight: 700 !important; text-transform: uppercase !important; letter-spacing: .06em !important; margin-bottom: 2px !important; }
    .kpi-item .kpi-value { font-size: 14pt !important; font-weight: 900 !important; line-height: 1.1 !important; }
    .kpi-item.kpi-income  .kpi-value { color: #1a7a2e !important; }
    .kpi-item.kpi-expense .kpi-value { color: #c0392b !important; }
    .kpi-item.kpi-profit  .kpi-value { color: #1a5276 !important; }
    .kpi-item.kpi-athletes .kpi-value { color: #b7770d !important; }

    .stat-row { display: grid !important; grid-template-columns: repeat(4,1fr) !important; gap: 3mm !important; margin-bottom: 5mm !important; page-break-inside: avoid !important; }
    .stat-card { border: 1.5px solid #ddd !important; border-radius: 4px !important; padding: 3mm 4mm !important; background: #fafafa !important; display: flex !important; flex-direction: column !important; gap: 1mm !important; }
    .stat-icon { display: none !important; }
    .stat-val { font-size: 14pt !important; font-weight: 900 !important; line-height: 1.1 !important; }
    .stat-val.text-green { color: #1a7a2e !important; }
    .stat-val.text-red   { color: #c0392b !important; }
    .stat-val.text-gold  { color: #b7770d !important; }
    .stat-lbl { font-size: 7pt !important; color: #666 !important; font-weight: 700 !important; text-transform: uppercase !important; letter-spacing: .04em !important; }
    .stat-card small { font-size: 6.5pt !important; color: #888 !important; }

    .sub-kpi-bar { display: grid !important; grid-template-columns: repeat(3,1fr) !important; gap: 3mm !important; margin: 4mm 0 5mm !important; page-break-inside: avoid !important; }
    .sub-kpi-item { border: 1.5px solid #ddd !important; border-radius: 4px !important; padding: 3mm 4mm !important; background: #fafafa !important; }
    .sub-kpi-item.sub-paid    { border-left: 4px solid #1a7a2e !important; }
    .sub-kpi-item.sub-pending { border-left: 4px solid #b7770d !important; }
    .sub-kpi-item.sub-overdue { border-left: 4px solid #c0392b !important; }
    .sub-kpi-item .kpi-label { font-size: 7pt !important; color: #666 !important; font-weight: 700 !important; text-transform: uppercase !important; letter-spacing: .06em !important; margin-bottom: 2px !important; }
    .sub-kpi-item .kpi-value { font-size: 13pt !important; font-weight: 900 !important; line-height: 1.1 !important; }
    .sub-kpi-item .kpi-sub   { font-size: 7.5pt !important; color: #777 !important; margin-top: 1px !important; }
    .sub-kpi-item.sub-paid    .kpi-value { color: #1a7a2e !important; }
    .sub-kpi-item.sub-pending .kpi-value { color: #b7770d !important; }
    .sub-kpi-item.sub-overdue .kpi-value { color: #c0392b !important; }

    .p-section { background: #1a1a2e !important; color: #fff !important; font-size: 9pt !important; font-weight: 900 !important; letter-spacing: .1em !important; text-transform: uppercase !important; padding: 2.5mm 5mm !important; margin: 6mm 0 4mm !important; page-break-after: avoid !important; page-break-inside: avoid !important; }
    .p-section span { color: #aaa !important; font-weight: 400 !important; }
    .p-subsection { font-size: 9pt !important; font-weight: 900 !important; color: #1a1a2e !important; border-bottom: 2px solid #1a1a2e !important; padding-bottom: 1.5mm !important; margin: 5mm 0 3mm !important; page-break-after: avoid !important; letter-spacing: .04em !important; }

    .card { border: none !important; border-radius: 0 !important; background: transparent !important; box-shadow: none !important; margin-bottom: 5mm !important; overflow: visible !important; page-break-inside: avoid !important; }
    .card-header { display: none !important; }
    table { width: 100% !important; border-collapse: collapse !important; font-size: 8.5pt !important; margin-bottom: 0 !important; page-break-inside: auto !important; }
    thead { display: table-header-group !important; }
    thead tr th { background: #2c3e50 !important; color: #ffffff !important; font-size: 7.5pt !important; font-weight: 900 !important; text-transform: uppercase !important; letter-spacing: .07em !important; padding: 2.5mm 3mm !important; border: 1px solid #2c3e50 !important; white-space: nowrap !important; text-align: left !important; }
    tbody tr td { padding: 2mm 3mm !important; border: 1px solid #d5d8dc !important; font-size: 8.5pt !important; color: #1a1a2e !important; vertical-align: middle !important; background: #fff !important; }
    tbody tr:nth-child(even) td { background: #f2f3f4 !important; }
    tbody tr:hover td { background: inherit !important; }
    tfoot tr td { padding: 2.5mm 3mm !important; border: 1.5px solid #2c3e50 !important; font-size: 8.5pt !important; font-weight: 900 !important; color: #1a1a2e !important; background: #eaf0f6 !important; }

    #print-chart-wrap { margin: 0 0 5mm !important; page-break-inside: avoid !important; }
    #print-chart-wrap img { width: 100% !important; max-height: 70mm !important; object-fit: contain !important; display: block !important; border: 1px solid #ddd !important; border-radius: 3px !important; }

    .p-bar-chart { margin: 2mm 0 3mm !important; padding: 0 !important; }
    .p-bar-row { display: flex !important; align-items: center !important; margin-bottom: 2mm !important; gap: 3mm !important; }
    .p-bar-label { width: 36mm !important; font-size: 7.5pt !important; color: #1a1a2e !important; font-weight: 600 !important; flex-shrink: 0 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; }
    .p-bar-track { flex: 1 !important; height: 7px !important; background: #e5e7e9 !important; border-radius: 3px !important; overflow: hidden !important; }
    .p-bar-fill { height: 100% !important; border-radius: 3px !important; }
    .p-bar-fill.blue  { background: #1a5276 !important; }
    .p-bar-fill.green { background: #1a7a2e !important; }
    .p-bar-val { width: 20mm !important; font-size: 7.5pt !important; font-weight: 800 !important; color: #1a1a2e !important; text-align: right !important; flex-shrink: 0 !important; }

    .badge { font-size: 7.5pt !important; padding: .8mm 2.5mm !important; border-radius: 2px !important; font-weight: 900 !important; border: 1.5px solid currentColor !important; background: transparent !important; }
    .badge-paid    { color: #1a7a2e !important; }
    .badge-overdue { color: #c0392b !important; }
    .badge-pending { color: #b7770d !important; }
    .badge-income  { color: #1a7a2e !important; }
    .badge-expense { color: #c0392b !important; }

    .progress { background: #e5e7e9 !important; height: 5px !important; border-radius: 0 !important; }
    .progress-bar.green { background: #1a7a2e !important; }
    .progress-bar.red   { background: #c0392b !important; }
    .progress-bar.blue  { background: #1a5276 !important; }

    .print-break { page-break-before: always !important; }
    .no-break    { page-break-inside: avoid !important; }

    .print-footer { position: fixed !important; bottom: 0 !important; left: 0 !important; right: 0 !important; border-top: 1px solid #bbb !important; padding: 2mm 0 0 !important; font-size: 7pt !important; color: #888 !important; display: flex !important; justify-content: space-between !important; }

    a { color: #1a1a2e !important; text-decoration: none !important; }
    .screen-only { display: none !important; }
    .print-only  { display: block !important; }
}
</style>

<body>
<div class="app-layout">
<?php renderSidebar('economics'); ?>
<div id="dm-overlay"></div>

<div class="main-content">
<?php renderTopbar('Οικονομικά & Αναφορές'); ?>
<div class="page-body">

<!-- ════════════════════════════════════════════════════════════
     TAB TOGGLER
════════════════════════════════════════════════════════════ -->
<div class="tab-toggle-bar anim-1">
    <button class="tab-toggle-btn <?= $activeTab === 'economics' ? 'active' : '' ?>" data-tab="economics" type="button">
        <i class="fa-solid fa-chart-column"></i> Οικονομικά
    </button>
    <button class="tab-toggle-btn <?= $activeTab === 'reports' ? 'active' : '' ?>" data-tab="reports" type="button">
        <i class="fa-solid fa-file-chart-column"></i> Αναφορές
    </button>
</div>

<!-- ════════════════════════════════════════════════════════════
     TAB 1 — ΟΙΚΟΝΟΜΙΚΑ
════════════════════════════════════════════════════════════ -->
<div id="economicsPanel" class="tab-panel <?= $activeTab === 'economics' ? 'active' : '' ?>">

<div class="page-header anim-1">
    <h2>
        <i class="fa-solid fa-chart-column" style="color:var(--red,#e63946)"></i>
        Οικονομικά
        <span style="font-size:clamp(.88rem,3.8vw,.98rem)!important;font-weight:600;color:var(--muted,#8892b0)">
            — <?= h(formatGreekMonthYear($month . '-01')) ?>
        </span>
    </h2>
    <button class="btn btn-primary btn-sm" onclick="openAddModal()" type="button">
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
            <form method="GET" style="display:contents" id="monthForm">
                <input type="hidden" name="tab" value="economics">
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

</div><!-- /economicsPanel -->


<!-- ════════════════════════════════════════════════════════════
     TAB 2 — ΑΝΑΦΟΡΕΣ
════════════════════════════════════════════════════════════ -->
<div id="reportsPanel" class="tab-panel <?= $activeTab === 'reports' ? 'active' : '' ?>">

<!-- PRINT HEADER -->
<div class="print-header print-only" style="display:none">
    <div class="ph-top">
        <div class="ph-logo-block">
            <div class="ph-appname">MA<span>ster</span></div>
            <div class="ph-tagline">Πλατφόρμα Διαχείρισης Αθλητικών Συλλόγων</div>
        </div>
        <div class="ph-school-block">
            <div class="ph-school-name"><?= h($school['name'] ?? '') ?></div>
            <div class="ph-school-meta">
                <?php if (!empty($school['afm'])): ?>ΑΦΜ: <?= h($school['afm']) ?><?php endif; ?>
                <?php if (!empty($school['city'])): ?> &nbsp;|&nbsp; <?= h($school['city']) ?><?php endif; ?><br>
                <?php if (!empty($school['phone'])): ?>Τηλ: <?= h($school['phone']) ?> &nbsp;|&nbsp; <?php endif; ?>
                <?php if (!empty($school['email'])): ?><?= h($school['email']) ?><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="ph-banner">
        <div class="ph-report-title">&#9632; Αναφορά <?= h($yearTitle) ?></div>
        <div class="ph-report-meta">Εκτυπώθηκε: <?= date('d/m/Y') ?> &nbsp;·&nbsp; <?= date('H:i') ?></div>
    </div>
    <?php $profit_val = $rTotalIncome - $rTotalExpense; ?>
    <div style="height:4mm"></div>
    <div class="kpi-bar">
        <div class="kpi-item kpi-income">
            <div class="kpi-label">&#9650; Σύνολο Εσόδων <?= h($yearTitle) ?></div>
            <div class="kpi-value"><?= formatMoney($rTotalIncome) ?></div>
        </div>
        <div class="kpi-item kpi-expense">
            <div class="kpi-label">&#9660; Σύνολο Εξόδων <?= h($yearTitle) ?></div>
            <div class="kpi-value"><?= formatMoney($rTotalExpense) ?></div>
        </div>
        <div class="kpi-item kpi-profit">
            <div class="kpi-label">&#9632; Καθαρό Αποτέλεσμα</div>
            <div class="kpi-value" style="color:<?= $profit_val >= 0 ? '#1a5276' : '#c0392b' ?>"><?= formatMoney($profit_val) ?></div>
        </div>
        <div class="kpi-item kpi-athletes">
            <div class="kpi-label">&#9632; Ενεργοί Αθλητές</div>
            <div class="kpi-value" style="color:#b7770d">
                <?= $athleteCount ?>
                <span style="font-size:8pt;font-weight:400;color:#888">(+<?= $newAthletes ?> <?= $isAllYears ? 'συνολικά' : 'νέοι' ?>)</span>
            </div>
        </div>
    </div>
</div>

<!-- SCREEN HEADER -->
<div class="page-header screen-only">
    <h2>
        <i class="fa-solid fa-file-chart-column" style="color:var(--red,#e63946)"></i>
        Αναφορές
        <span style="font-size:.9rem;font-weight:600;color:var(--muted,#8892b0)">— <?= h($yearTitle) ?></span>
    </h2>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <select id="yearSelect" class="form-control" style="min-width:140px">
            <option value="all" <?= $isAllYears ? 'selected' : '' ?>>Όλα τα έτη</option>
            <?php for ($y = $currentYear; $y >= $firstDataYear; $y--): ?>
                <option value="<?= $y ?>" <?= (!$isAllYears && $y === (int)$year) ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button id="printBtn" class="btn btn-primary">
            <i class="fa-solid fa-print"></i> Εκτύπωση / PDF
        </button>
    </div>
</div>

<!-- STAT ROW -->
<div class="stat-row">
    <div class="stat-card card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div class="stat-val text-green"><?= formatMoney($rTotalIncome) ?></div>
        <div class="stat-lbl">Σύνολο Εσόδων <?= h($yearTitle) ?></div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-red"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div class="stat-val text-red"><?= formatMoney($rTotalExpense) ?></div>
        <div class="stat-lbl">Σύνολο Εξόδων <?= h($yearTitle) ?></div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon <?= $rProfit >= 0 ? 'icon-blue' : 'icon-red' ?>"><i class="fa-solid fa-scale-balanced"></i></div>
        <div class="stat-val <?= $rProfit >= 0 ? 'text-green' : 'text-red' ?>"><?= formatMoney($rProfit) ?></div>
        <div class="stat-lbl">Καθαρό Αποτέλεσμα</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-users"></i></div>
        <div class="stat-val text-gold"><?= $athleteCount ?></div>
        <div class="stat-lbl">Ενεργοί Αθλητές <small style="font-weight:400;font-size:.75rem">(+<?= $newAthletes ?> <?= h($newAthletesLabel) ?>)</small></div>
    </div>
</div>

<!-- SECTION 1 — FINANCIAL -->
<div class="section-divider screen-only"><i class="fa-solid fa-euro-sign"></i> 1. Οικονομική Ανάλυση <?= h($yearTitle) ?></div>
<div class="p-section print-only" style="display:none">1. Οικονομική Ανάλυση <span><?= h($yearTitle) ?></span></div>

<div id="print-chart-wrap" style="display:none">
    <div class="p-subsection" style="display:block;margin-top:0">Γράφημα Μηνιαίας Εξέλιξης Εσόδων &amp; Εξόδων</div>
    <img id="print-chart-img" src="" alt="Γράφημα μηνιαίας εξέλιξης">
</div>

<div class="p-subsection print-only" style="display:none">Μηνιαία Ανάλυση Εσόδων &amp; Εξόδων</div>

<div class="card no-break">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-table screen-only"></i> Μηνιαία Ανάλυση Εσόδων &amp; Εξόδων</div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Μήνας</th><th style="text-align:right">Έσοδα</th><th style="text-align:right">Έξοδα</th><th style="text-align:right">Αποτέλεσμα</th><th class="screen-only" style="min-width:140px">Γράφημα</th></tr>
            </thead>
            <tbody>
                <?php
                foreach ($monthsShort as $mi => $mn):
                    $inc    = $monthlyIncome[$mi];
                    $exp    = $monthlyExpense[$mi];
                    $res    = $inc - $exp;
                    $maxVal = max(max($monthlyIncome), max($monthlyExpense), 1);
                    $incPct = round($inc / $maxVal * 100);
                    $expPct = round($exp / $maxVal * 100);
                    $hasData = $inc > 0 || $exp > 0;
                ?>
                <tr style="<?= !$hasData ? 'opacity:.4' : '' ?>">
                    <td><strong><?= $months[$mi] ?></strong></td>
                    <td style="text-align:right;color:#2dc653;font-weight:700"><?= $inc > 0 ? formatMoney($inc) : '—' ?></td>
                    <td style="text-align:right;color:#e63946;font-weight:700"><?= $exp > 0 ? formatMoney($exp) : '—' ?></td>
                    <td style="text-align:right;font-weight:800;color:<?= $res >= 0 ? '#2dc653' : '#e63946' ?>"><?= ($inc > 0 || $exp > 0) ? formatMoney($res) : '—' ?></td>
                    <td class="screen-only" style="padding-top:.5rem;padding-bottom:.5rem">
                        <?php if ($hasData): ?>
                        <div style="display:flex;flex-direction:column;gap:3px">
                            <div class="progress" style="height:6px"><div class="progress-bar green" style="width:<?= $incPct ?>%"></div></div>
                            <div class="progress" style="height:6px"><div class="progress-bar red" style="width:<?= $expPct ?>%"></div></div>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>ΣΥΝΟΛΟ</strong></td>
                    <td style="text-align:right;font-weight:800;color:#2dc653"><?= formatMoney($rTotalIncome) ?></td>
                    <td style="text-align:right;font-weight:800;color:#e63946"><?= formatMoney($rTotalExpense) ?></td>
                    <td style="text-align:right;font-weight:800;color:<?= $rProfit >= 0 ? '#2dc653' : '#e63946' ?>"><?= formatMoney($rProfit) ?></td>
                    <td class="screen-only"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="p-subsection print-only" style="display:none">Κατηγορίες Εσόδων &amp; Εξόδων</div>

<div class="grid-2">
    <div class="card no-break">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-tags screen-only" style="color:#2dc653"></i> Κατηγορίες Εσόδων</div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Κατηγορία</th><th style="text-align:right">Ποσό</th><th style="text-align:right">Εγγρ.</th><th style="text-align:right">%</th></tr></thead>
                <tbody>
                    <?php if ($catList): foreach ($catList as $c): $pct = $rTotalIncome > 0 ? round($c['total'] / $rTotalIncome * 100) : 0; ?>
                    <tr>
                        <td><?= h($c['category']) ?></td>
                        <td style="text-align:right;font-weight:700;color:#2dc653"><?= formatMoney($c['total']) ?></td>
                        <td style="text-align:right;color:var(--muted,#8892b0)"><?= $c['cnt'] ?></td>
                        <td style="text-align:right;font-weight:700"><?= $pct ?>%</td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="4" style="text-align:center;color:var(--muted,#8892b0);padding:.75rem">Δεν υπάρχουν δεδομένα</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($catList): ?>
                <tfoot><tr><td><strong>Σύνολο</strong></td><td style="text-align:right;font-weight:800;color:#2dc653"><?= formatMoney($rTotalIncome) ?></td><td style="text-align:right"><?= array_sum(array_column($catList,'cnt')) ?></td><td style="text-align:right;font-weight:800">100%</td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="card no-break">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-tags screen-only" style="color:#e63946"></i> Κατηγορίες Εξόδων</div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Κατηγορία</th><th style="text-align:right">Ποσό</th><th style="text-align:right">Εγγρ.</th><th style="text-align:right">%</th></tr></thead>
                <tbody>
                    <?php if ($expCatList): foreach ($expCatList as $c): $pct = $rTotalExpense > 0 ? round($c['total'] / $rTotalExpense * 100) : 0; ?>
                    <tr>
                        <td><?= h($c['category']) ?></td>
                        <td style="text-align:right;font-weight:700;color:#e63946"><?= formatMoney($c['total']) ?></td>
                        <td style="text-align:right;color:var(--muted,#8892b0)"><?= $c['cnt'] ?></td>
                        <td style="text-align:right;font-weight:700"><?= $pct ?>%</td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="4" style="text-align:center;color:var(--muted,#8892b0);padding:.75rem">Δεν υπάρχουν δεδομένα</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($expCatList): ?>
                <tfoot><tr><td><strong>Σύνολο</strong></td><td style="text-align:right;font-weight:800;color:#e63946"><?= formatMoney($rTotalExpense) ?></td><td style="text-align:right"><?= array_sum(array_column($expCatList,'cnt')) ?></td><td style="text-align:right;font-weight:800">100%</td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<div class="p-subsection print-only print-break" style="display:none">Αναλυτικές Συναλλαγές <?= h($yearTitle) ?></div>

<?php if ($rTransactions): ?>
<div class="card print-break no-break">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-list screen-only"></i> Αναλυτικές Συναλλαγές <?= h($yearTitle) ?></div>
        <span style="font-size:.8rem;color:var(--muted,#8892b0)"><?= count($rTransactions) ?> εγγραφές</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Ημερομηνία</th><th>Τύπος</th><th>Κατηγορία</th><th>Περιγραφή</th><th>Αθλητής</th><th>Τρόπος</th><th style="text-align:right">Ποσό</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rTransactions as $t): ?>
                <tr>
                    <td style="white-space:nowrap"><?= formatDate($t['transaction_date']) ?></td>
                    <td><span class="badge <?= $t['type']==='income'?'badge-income':'badge-expense' ?>"><?= $t['type']==='income'?'Έσοδο':'Έξοδο' ?></span></td>
                    <td><?= h($t['category']) ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($t['description'] ?? '') ?></td>
                    <td><?= h($t['athlete_name'] ?? '—') ?></td>
                    <td style="white-space:nowrap"><?= ['cash'=>'Μετρητά','card'=>'Κάρτα','deposit'=>'Κατάθεση','other'=>'Άλλο'][$t['payment_method']] ?? $t['payment_method'] ?></td>
                    <td style="text-align:right;font-weight:700;color:<?= $t['type']==='income'?'#2dc653':'#e63946' ?>"><?= ($t['type']==='expense'?'-':'').formatMoney($t['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- SECTION 2 — ATHLETES -->
<div class="section-divider screen-only print-break"><i class="fa-solid fa-users"></i> 2. Ανάλυση Αθλητών</div>
<div class="p-section print-only print-break" style="display:none">2. Ανάλυση Αθλητών <span>— Στατιστικά ανά Τμήμα</span></div>

<div class="grid-2">
</div>

<div class="p-subsection print-only" style="display:none">Τμήματα Σχολής</div>

<?php if ($deptList): ?>
<div class="card no-break">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-folder-open screen-only" style="color:#3b82f6"></i> Τμήματα Σχολής</div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Τμήμα</th><th style="text-align:right">Αθλητές</th><th style="text-align:right">Μέγ. Χωρητ.</th><th style="text-align:right">Μηνιαία Συνδρομή</th><th>Πρόγραμμα</th></tr></thead>
            <tbody>
                <?php foreach ($deptList as $d): ?>
                <tr>
                    <td><strong><?= h($d['name']) ?></strong></td>
                    <td style="text-align:right;font-weight:700"><?= $d['athlete_count'] ?></td>
                    <td style="text-align:right;color:var(--muted,#8892b0)"><?= $d['max_athletes'] ?></td>
                    <td style="text-align:right;color:#2dc653;font-weight:700"><?= $d['monthly_fee'] > 0 ? formatMoney($d['monthly_fee']) : '—' ?></td>
                    <td style="font-size:.82rem;color:var(--muted,#8892b0)"><?= h(substr($d['schedule'] ?? '', 0, 60)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <td><strong>Σύνολο</strong></td>
                <td style="text-align:right;font-weight:800"><?= array_sum(array_column($deptList,'athlete_count')) ?></td>
                <td style="text-align:right"><?= array_sum(array_column($deptList,'max_athletes')) ?></td>
                <td colspan="2"></td>
            </tr></tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="p-subsection print-only" style="display:none">Top 20 Αθλητές ανά Πληρωμές</div>

<?php if ($topAthList): ?>
<div class="card no-break">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-ranking-star screen-only" style="color:#f4a535"></i> Αθλητές ανά Πληρωμή (Top 20)</div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Αθλητής</th><th style="text-align:right">Συνδρομές</th><th style="text-align:right">Σύνολο Πληρωμών</th></tr></thead>
            <tbody>
                <?php foreach ($topAthList as $i => $a): ?>
                <tr>
                    <td style="color:var(--muted,#8892b0);font-weight:800"><?= $i+1 ?></td>
                    <td><strong><?= h($a['full_name']) ?></strong></td>
                    <td style="text-align:right"><?= $a['sub_count'] ?></td>
                    <td style="text-align:right;font-weight:700;color:#2dc653"><?= formatMoney($a['total_paid']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- SECTION 3 — SUBSCRIPTIONS -->
<div class="section-divider screen-only print-break"><i class="fa-solid fa-credit-card"></i> 3. Ανάλυση Συνδρομών</div>
<div class="p-section print-only print-break" style="display:none">3. Ανάλυση Συνδρομών <span>— Πληρωμές, Εκκρεμείς &amp; Ληξιπρόθεσμες</span></div>

<div class="sub-kpi-bar print-only" style="display:none">
    <div class="sub-kpi-item sub-paid">
        <div class="kpi-label">&#10003; Πληρωμένες Συνδρομές</div>
        <div class="kpi-value"><?= $subTotals['paid']['cnt'] ?></div>
        <div class="kpi-sub"><?= formatMoney($subTotals['paid']['total']) ?> συνολικά</div>
    </div>
    <div class="sub-kpi-item sub-pending">
        <div class="kpi-label">&#9679; Εκκρεμείς Συνδρομές</div>
        <div class="kpi-value"><?= $subTotals['pending']['cnt'] ?></div>
        <div class="kpi-sub"><?= formatMoney($subTotals['pending']['total']) ?> σε εκκρεμότητα</div>
    </div>
    <div class="sub-kpi-item sub-overdue">
        <div class="kpi-label">&#9888; Ληξιπρόθεσμες</div>
        <div class="kpi-value"><?= $subTotals['overdue']['cnt'] ?></div>
        <div class="kpi-sub"><?= formatMoney($subTotals['overdue']['total']) ?> οφειλή</div>
    </div>
</div>

<div class="p-subsection print-only" style="display:none">Σύνοψη Κατάστασης Συνδρομών</div>

<div class="card no-break">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-chart-pie screen-only"></i> Σύνοψη Κατάστασης Συνδρομών</div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Κατάσταση</th><th style="text-align:right">Πλήθος</th><th style="text-align:right">Σύνολο Ποσού</th><th style="text-align:right">Μέσο Ποσό</th></tr></thead>
            <tbody>
                <?php foreach ($payStats as $ps): ?>
                <tr>
                    <td><span class="badge badge-<?= h($ps['status']) ?>"><?= ['paid'=>'Πληρωμένη','pending'=>'Εκκρεμής','overdue'=>'Ληξιπρόθεσμη'][$ps['status']] ?? $ps['status'] ?></span></td>
                    <td style="text-align:right;font-weight:800"><?= $ps['cnt'] ?></td>
                    <td style="text-align:right;font-weight:700"><?= formatMoney($ps['total']) ?></td>
                    <td style="text-align:right;color:var(--muted,#8892b0)"><?= $ps['cnt'] > 0 ? formatMoney($ps['total'] / $ps['cnt']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="p-subsection print-only" style="display:none">Πληρωμένες Συνδρομές <?= h($yearTitle) ?></div>

<?php if ($paidSubList): ?>
<div class="card no-break">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-circle-check screen-only" style="color:#2dc653"></i> Πληρωμένες Συνδρομές <?= h($yearTitle) ?></div>
        <span style="font-size:.8rem;color:var(--muted,#8892b0)"><?= count($paidSubList) ?> εγγραφές</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Αθλητής</th><th>Τύπος</th><th style="text-align:right">Ποσό</th><th>Πληρώθηκε</th><th>Ισχύει Έως</th><th>Τρόπος</th></tr></thead>
            <tbody>
                <?php foreach ($paidSubList as $s): ?>
                <tr>
                    <td><strong><?= h($s['full_name']) ?></strong></td>
                    <td><?= ['monthly'=>'Μηνιαία','quarterly'=>'Τριμηνιαία','annual'=>'Ετήσια','onetime'=>'Εφάπαξ'][$s['type']] ?? $s['type'] ?></td>
                    <td style="text-align:right;font-weight:700;color:#2dc653"><?= formatMoney($s['amount']) ?></td>
                    <td style="white-space:nowrap"><?= $s['paid_at'] ? formatDate($s['paid_at']) : '—' ?></td>
                    <td style="white-space:nowrap"><?= formatDate($s['valid_until']) ?></td>
                    <td><?= ['cash'=>'Μετρητά','card'=>'Κάρτα','deposit'=>'Κατάθεση'][$s['payment_method']] ?? $s['payment_method'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="p-subsection print-only" style="display:none">Ληξιπρόθεσμες Συνδρομές</div>

<?php if ($overdueSubList): ?>
<div class="card no-break">
    <div class="card-header" style="background:rgba(230,57,70,.06)">
        <div class="card-title" style="color:#e63946"><i class="fa-solid fa-triangle-exclamation screen-only"></i> Ληξιπρόθεσμες Συνδρομές</div>
        <span style="font-size:.8rem;color:#e63946"><?= count($overdueSubList) ?> εγγραφές</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Αθλητής</th><th>Τηλέφωνο</th><th style="text-align:right">Οφειλή</th><th>Έληξε</th><th>Εκκρ. από</th></tr></thead>
            <tbody>
                <?php foreach ($overdueSubList as $s):
                    $daysPast = $s['valid_until'] ? floor((time() - strtotime($s['valid_until'])) / 86400) : 0;
                ?>
                <tr>
                    <td><strong><?= h($s['full_name']) ?></strong></td>
                    <td style="color:var(--muted,#8892b0)"><?= h($s['phone'] ?: ($s['parent_phone'] ?? '—')) ?></td>
                    <td style="text-align:right;font-weight:700;color:#e63946"><?= formatMoney($s['amount']) ?></td>
                    <td style="white-space:nowrap;color:#e63946"><?= formatDate($s['valid_until']) ?></td>
                    <td style="white-space:nowrap"><span style="color:#e63946;font-weight:700"><?= $daysPast ?>d</span> πριν</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <td colspan="2"><strong>Σύνολο Οφειλών</strong></td>
                <td style="text-align:right;font-weight:800;color:#e63946"><?= formatMoney(array_sum(array_column($overdueSubList,'amount'))) ?></td>
                <td colspan="2"></td>
            </tr></tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- PRINT FOOTER -->
<div class="print-footer print-only" style="display:none">
    <span>MAster — Πλατφόρμα Διαχείρισης Αθλητικών Συλλόγων</span>
    <span><?= h($school['name'] ?? '') ?> · Αναφορά <?= h($yearTitle) ?> · <?= date('d/m/Y') ?></span>
</div>

<!-- SCREEN-ONLY CHART -->
<div class="screen-only">
    <div class="section-divider"><i class="fa-solid fa-chart-area"></i> Γράφημα Εξέλιξης</div>
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-chart-line" style="color:var(--gold,#f4a535)"></i> Μηνιαία Εξέλιξη <?= h($yearTitle) ?></div>
        </div>
        <div class="chart-container" style="padding:1rem">
            <canvas id="annualChart" style="max-height:240px"></canvas>
        </div>
    </div>
</div>

</div><!-- /reportsPanel -->


</div><!-- /page-body -->
</div><!-- /main-content -->
</div><!-- /app-layout -->


<!-- ════════════════════════════════════════════════════════════
     MODALS (Economics)
════════════════════════════════════════════════════════════ -->
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
/* ══════════════════════════════════════════
   JAVASCRIPT
══════════════════════════════════════════ */

/* ── Fix topbar ── */
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

/* ── Sidebar toggle ── */
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

/* ── Tab toggle ── */
(function(){
    var btns = document.querySelectorAll('.tab-toggle-btn');
    btns.forEach(function(btn){
        btn.addEventListener('click', function(){
            var tab = this.dataset.tab;

            // Update buttons
            btns.forEach(function(b){ b.classList.remove('active'); });
            this.classList.add('active');

            // Update panels
            document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
            var panel = document.getElementById(tab + 'Panel');
            if(panel) panel.classList.add('active');

            // Update URL without reload
            var url = new URL(window.location);
            url.searchParams.set('tab', tab);
            history.replaceState(null, '', url);

            // Init charts for the newly visible tab
            if(tab === 'economics') initFinChart();
            if(tab === 'reports') initAnnualChart();
        });
    });
})();

/* ── Modal helpers ── */
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
    bd.addEventListener('click',function(e){ if(e.target===bd) closeModal(bd.id); });
});

/* ── Add / Edit / Delete modals ── */
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

/* ── Category filter ── */
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

/* ── Auto-open edit modal ── */
(function(){
    var tx=<?= $editTxJson ?>;
    if(tx) openEditModal(tx);
})();

/* ── Live filter (Economics) ── */
function liveFilter() {
    var type   = document.getElementById('liveType').value;
    var cat    = document.getElementById('liveCat').value;
    var q      = document.getElementById('liveSearch').value.toLowerCase().trim();
    var rows   = document.querySelectorAll('.tx-row');
    var clearBtn = document.getElementById('liveClearBtn');

    rows.forEach(function(row) {
        var matchType = !type  || row.dataset.type === type;
        var matchCat  = !cat   || row.dataset.cat  === cat;
        var matchQ    = !q     || row.dataset.desc.indexOf(q) >= 0
                               || row.dataset.notes.indexOf(q) >= 0
                               || row.dataset.name.indexOf(q) >= 0;
        row.style.display = (matchType && matchCat && matchQ) ? '' : 'none';
    });
    if (clearBtn) clearBtn.style.display = (type || cat || q) ? '' : 'none';
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

/* ── Charts ── */
var finChartInstance = null;
var annualChartInstance = null;

function initFinChart() {
    if (finChartInstance) return;
    var ctx = document.getElementById('finChart');
    if (!ctx) return;
    finChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($chartData,'month'), JSON_UNESCAPED_UNICODE) ?>,
            datasets: [
                {label:'Έσοδα', data:<?= json_encode(array_column($chartData,'income')) ?>, backgroundColor:'rgba(45,198,83,.55)', borderColor:'rgba(45,198,83,1)', borderWidth:2, borderRadius:7},
                {label:'Έξοδα', data:<?= json_encode(array_column($chartData,'expense')) ?>, backgroundColor:'rgba(230,57,70,.55)', borderColor:'rgba(230,57,70,1)', borderWidth:2, borderRadius:7}
            ]
        },
        options: {
            responsive: true,
            plugins: {legend:{labels:{color:'#7a849e',font:{size:13,weight:'700'},padding:14}}},
            scales: {
                x:{ticks:{color:'#7a849e',font:{size:12}},grid:{color:'rgba(255,255,255,.05)'}},
                y:{ticks:{color:'#7a849e',font:{size:12}},grid:{color:'rgba(255,255,255,.05)'}}
            }
        }
    });
}

function initAnnualChart() {
    if (annualChartInstance) return;
    var ctx = document.getElementById('annualChart');
    if (!ctx) return;
    annualChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($monthsShort, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [
                {label:'Έσοδα', data:<?= json_encode($monthlyIncome) ?>, borderColor:'rgba(45,198,83,1)', backgroundColor:'rgba(45,198,83,.12)', fill:true, tension:.35, borderWidth:2.5, pointRadius:4},
                {label:'Έξοδα', data:<?= json_encode($monthlyExpense) ?>, borderColor:'rgba(230,57,70,1)', backgroundColor:'rgba(230,57,70,.10)', fill:true, tension:.35, borderWidth:2.5, pointRadius:4}
            ]
        },
        options: {
            animation: false,
            responsive: true,
            plugins: {legend:{labels:{color:'#7a849e',font:{size:13,weight:'700'}}}},
            scales: {
                x:{ticks:{color:'#7a849e'}},
                y:{ticks:{color:'#7a849e'},grid:{color:'rgba(255,255,255,.05)'}}
            }
        }
    });
}

/* Init chart for the active tab on page load */
<?php if ($activeTab === 'economics'): ?>
initFinChart();
<?php else: ?>
initAnnualChart();
<?php endif; ?>

/* ── Year select (Reports) ── */
document.getElementById('yearSelect')?.addEventListener('change', function(){
    var url = new URL(window.location);
    url.searchParams.set('year', this.value);
    url.searchParams.set('tab', 'reports');
    window.location.href = url.toString();
});

/* ── Print button ── */
document.getElementById('printBtn')?.addEventListener('click', function() {
    var wrap = document.getElementById('print-chart-wrap');
    var img  = document.getElementById('print-chart-img');
    var annualChartEl = document.getElementById('annualChart');

    // Make sure chart is initialized
    if (!annualChartInstance) initAnnualChart();

    if (annualChartInstance && wrap && img && annualChartEl) {
        var offCanvas = document.createElement('canvas');
        offCanvas.width  = annualChartEl.width  || 800;
        offCanvas.height = annualChartEl.height || 300;
        var ctx = offCanvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, offCanvas.width, offCanvas.height);
        ctx.drawImage(annualChartEl, 0, 0);
        img.src = offCanvas.toDataURL('image/png');
    }

    window.print();
});
</script>
</body>
</html>