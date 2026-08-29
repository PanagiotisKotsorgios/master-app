<?php
/**
 * economics_reports.php
 * Simplified: single page — transactions + export button. No tab toggler.
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

/* ══ HELPERS ══ */
if (!function_exists('greekMonthName')) {
    function greekMonthName(int $m): string {
        return [1=>'Ιανουάριος',2=>'Φεβρουάριος',3=>'Μάρτιος',4=>'Απρίλιος',5=>'Μάιος',6=>'Ιούνιος',7=>'Ιούλιος',8=>'Αύγουστος',9=>'Σεπτέμβριος',10=>'Οκτώβριος',11=>'Νοέμβριος',12=>'Δεκέμβριος'][$m] ?? '';
    }
}
if (!function_exists('greekMonthShort')) {
    function greekMonthShort(int $m): string {
        return [1=>'Ιαν',2=>'Φεβ',3=>'Μαρ',4=>'Απρ',5=>'Μάι',6=>'Ιουν',7=>'Ιουλ',8=>'Αυγ',9=>'Σεπ',10=>'Οκτ',11=>'Νοε',12=>'Δεκ'][$m] ?? '';
    }
}
if (!function_exists('formatGreekMonthYear')) {
    function formatGreekMonthYear(string $d): string {
        $ts = strtotime($d); return $ts ? greekMonthName((int)date('n',$ts)).' '.date('Y',$ts) : '';
    }
}
if (!function_exists('formatGreekShortMonthYear')) {
    function formatGreekShortMonthYear(string $d): string {
        $ts = strtotime($d); return $ts ? greekMonthShort((int)date('n',$ts)).' '.date('Y',$ts) : '';
    }
}
if (!function_exists('parseGreekDateToDb')) {
    function parseGreekDateToDb(?string $value): ?string {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            [$y, $m, $d] = array_map('intval', explode('-', $value));
            return checkdate($m, $d, $y) ? $value : null;
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
            $day=(int)$m[1]; $month=(int)$m[2]; $year=(int)$m[3];
            if (!checkdate($month,$day,$year)) return null;
            return sprintf('%04d-%02d-%02d',$year,$month,$day);
        }
        return null;
    }
}
if (!function_exists('dbDateToGreekInput')) {
    function dbDateToGreekInput(?string $value): string {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $ts = strtotime($value);
            return $ts ? date('d/m/Y', $ts) : '';
        }
        return '';
    }
}

/* ══ MONTH ══ */
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}

/* ══ Which modal to auto-open ══ */
$openModal = $_GET['open_modal'] ?? '';

/* ══ POST: save_tx ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['_action'] ?? '') === 'save_tx')) {
    verifyCsrf();
    $a  = $_POST;
    $id = (int)($a['id'] ?? 0);
    $type = trim($a['type'] ?? '');
    if (!in_array($type, ['income','expense'], true)) {
        flash('Μη έγκυρος τύπος.','danger');
        redirect(APP_URL.'/pages/economics_reports.php?month='.urlencode($month));
    }
    $category         = trim(strip_tags($a['category'] ?? '')) ?: null;
    $amount           = (float)($a['amount'] ?? 0);
    $description      = trim($a['description'] ?? '') ?: null;
    $transaction_date = parseGreekDateToDb(trim($a['transaction_date'] ?? ''));
    if (!$transaction_date) {
        flash('Μη έγκυρη ημερομηνία. Χρησιμοποίησε μορφή ΗΗ/ΜΜ/ΕΕΕΕ.', 'danger');
        redirect(APP_URL.'/pages/economics_reports.php?month='.urlencode($month));
    }
    $payment_method   = trim($a['payment_method'] ?? '') ?: null;
    // Αφαίρεση εσωτερικού marker sub_id:XXX αν συμπεριλήφθηκε τυχαία στο submit
    $rawNotes = preg_replace('/^sub_id:\d+\s*/', '', trim($a['notes'] ?? ''));
    $notes    = $rawNotes !== '' ? $rawNotes : null;
    if ($amount < 0) {
        flash('Αρνητικό ποσό.','danger');
        redirect(APP_URL.'/pages/economics_reports.php?month='.urlencode($month));
    }
    if (!in_array($payment_method, ['cash','card','deposit','other'], true)) $payment_method = 'cash';
    $fields = ['type','category','amount','description','transaction_date','payment_method','notes'];
    $data   = [$type,$category,$amount,$description,$transaction_date,$payment_method,$notes];
    if ($id) {
        $db->prepare("UPDATE transactions SET ".implode(',',array_map(fn($k)=>"$k=?",$fields))." WHERE id=? AND school_id=?")->execute([...$data,$id,$sid]);
        flash('Εγγραφή ενημερώθηκε!');
    } else {
        $db->prepare("INSERT INTO transactions (school_id,".implode(',',$fields).") VALUES (?".str_repeat(',?',count($fields)).")")->execute([$sid,...$data]);
        flash('Εγγραφή προστέθηκε!');
    }
    redirect(APP_URL.'/pages/economics_reports.php?month='.urlencode($month));
}

/* ══ POST: delete_tx ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['_action'] ?? '') === 'delete_tx')) {
    verifyCsrf();
    $db->prepare("DELETE FROM transactions WHERE id=? AND school_id=?")->execute([(int)($_POST['id'] ?? 0),$sid]);
    flash('Εγγραφή διαγράφηκε.','danger');
    redirect(APP_URL.'/pages/economics_reports.php?month='.urlencode($month));
}

/* ══ ECONOMICS DATA ══ */
$mStart = $month.'-01';
$mEnd   = date('Y-m-t', strtotime($mStart));

$_pp     = isset($_GET['pp']) ? (int)$_GET['pp'] : 10;
$perPage = in_array($_pp,[10,25,50,100],true) ? $_pp : 10;
$txPage  = max(1,(int)($_GET['txpage'] ?? 1));

$txWhere  = "t.school_id=? AND t.transaction_date BETWEEN ? AND ?";
$txParams = [$sid,$mStart,$mEnd];

$countStmt = $db->prepare("SELECT COUNT(*) FROM transactions t WHERE $txWhere");
$countStmt->execute($txParams);
$txTotal = (int)$countStmt->fetchColumn();
$txPages = max(1,(int)ceil($txTotal/$perPage));
$offset  = ($txPage-1)*$perPage;

$txs = $db->prepare("SELECT t.*, a.full_name FROM transactions t LEFT JOIN athletes a ON a.id=t.athlete_id WHERE $txWhere ORDER BY t.transaction_date DESC, t.id DESC LIMIT $perPage OFFSET $offset");
$txs->execute($txParams);
$transactions = $txs->fetchAll();

$incomeStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='income' AND transaction_date BETWEEN ? AND ?");
$incomeStmt->execute([$sid,$mStart,$mEnd]);
$income = (float)$incomeStmt->fetchColumn();

$expenseStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='expense' AND transaction_date BETWEEN ? AND ?");
$expenseStmt->execute([$sid,$mStart,$mEnd]);
$expense = (float)$expenseStmt->fetchColumn();
$profit  = $income - $expense;

$chartData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m',strtotime("-$i months"));
    $s = $m.'-01'; $e = date('Y-m-t',strtotime($s));
    $inc = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='income' AND transaction_date BETWEEN ? AND ?");
    $inc->execute([$sid,$s,$e]);
    $exp = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='expense' AND transaction_date BETWEEN ? AND ?");
    $exp->execute([$sid,$s,$e]);
    $chartData[] = ['month'=>formatGreekShortMonthYear($s),'income'=>(float)$inc->fetchColumn(),'expense'=>(float)$exp->fetchColumn()];
}

/* Edit tx */
$editTxData = null;
if (isset($_GET['edit_id'])) {
    $stmtEdit = $db->prepare("SELECT * FROM transactions WHERE id=? AND school_id=?");
    $stmtEdit->execute([(int)$_GET['edit_id'],$sid]);
    $editTxData = $stmtEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editTxData) $openModal = 'modal-edit';
}

$incomeCategories  = ['Συνδρομές','Εξετάσεις','Αγωνιστικά','Άλλα έσοδα'];
$expenseCategories = ['Ενοίκιο','Εξοπλισμός','Ασφάλιστρα','Μισθοί','Διαφήμιση','Λοιπά έξοδα'];
$payMethods        = ['cash'=>'Μετρητά','card'=>'Κάρτα','deposit'=>'Κατάθεση','other'=>'Άλλο'];

function txPageUrl(int $p): string {
    $q = $_GET;
    $q['txpage'] = $p;
    unset($q['open_modal']);
    return '?' . http_build_query($q);
}

$currentYear = (int)date('Y');

renderHead('Οικονομικά');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<style>
input,select,textarea{box-shadow:none!important;-webkit-box-shadow:none!important;background-image:none!important}
input:-webkit-autofill,input:-webkit-autofill:hover,input:-webkit-autofill:focus{-webkit-box-shadow:0 0 0 1000px #1a1f2e inset!important;-webkit-text-fill-color:var(--text,#e2e8f0)!important}
.topbar{position:relative!important;top:auto!important;z-index:auto!important}

.modal-wrap{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);z-index:10000;align-items:center;justify-content:center;padding:.75rem;overflow-y:auto;border:none;margin:0;width:100%;height:100%}
.modal-wrap.is-open{display:flex!important}
.modal-box{background:var(--surface,#0d1117);border:1.5px solid var(--border,#1e2536);border-radius:22px;width:100%;max-width:520px;max-height:calc(100vh - 1.5rem);overflow-y:auto;padding:0;position:relative;box-shadow:0 24px 80px rgba(0,0,0,.7);animation:popIn .28s cubic-bezier(.2,.8,.2,1) both;scrollbar-width:auto;scrollbar-color:var(--red,#e63946) rgba(255,255,255,.08)}
.modal-box.modal-sm{max-width:400px}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.1rem .8rem;border-bottom:1.5px solid var(--border,#1e2536)}
.modal-title{font-size:clamp(1rem,4.2vw,1.22rem)!important;font-weight:800;display:flex;align-items:center;gap:.5rem;line-height:1.2}
.modal-close{width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.06);color:var(--muted,#8892b0);font-size:1.05rem;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:background .15s,color .15s;flex-shrink:0;border:none;cursor:pointer}
.modal-close:hover{background:rgba(230,57,70,.18);color:var(--red,#e63946)}
.modal-body{padding:1rem 1.1rem}
.modal-footer{display:flex;gap:.6rem;flex-wrap:wrap;padding:0 1.1rem 1.1rem}
.modal-wrap{cursor:pointer}
.modal-box{cursor:default}
.modal-box::-webkit-scrollbar{width:14px}
.modal-box::-webkit-scrollbar-track{background:rgba(255,255,255,.08);border-radius:999px}
.modal-box::-webkit-scrollbar-thumb{background:var(--red,#e63946);border-radius:999px;border:3px solid rgba(255,255,255,.08)}
.modal-box::-webkit-scrollbar-thumb:hover{background:#ff4d5a}

@keyframes popIn{from{opacity:0;transform:scale(.93) translateY(20px)}to{opacity:1;transform:scale(1) translateY(0)}}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.page-body{animation:fadeIn .3s ease both;padding:.85rem!important}
.anim-1{animation:fadeUp .4s ease-out .04s both}
.anim-2{animation:fadeUp .4s ease-out .1s both}
.anim-3{animation:fadeUp .4s ease-out .16s both}
.anim-4{animation:fadeUp .4s ease-out .22s both}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;opacity:1!important}}

.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;margin-bottom:.9rem}
.page-header h2{font-size:clamp(1.2rem,5vw,1.5rem)!important;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0}
.stat-cards-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:.9rem}
.stat-card{border-radius:16px;padding:.95rem .85rem .85rem;display:flex;flex-direction:column;gap:.3rem}
.stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;margin-bottom:.2rem}
.icon-green{background:rgba(45,198,83,.15);color:var(--green,#2dc653)}
.icon-red{background:rgba(230,57,70,.15);color:var(--red,#e63946)}
.icon-blue{background:rgba(59,130,246,.15);color:#3b82f6}
.stat-val{font-size:clamp(1.3rem,5.5vw,1.85rem)!important;font-weight:800;line-height:1.05}
.stat-lbl{font-size:clamp(.85rem,3.5vw,.95rem)!important;color:var(--muted,#8892b0);font-weight:600}
.text-green{color:var(--green,#2dc653)!important}
.text-red{color:var(--red,#e63946)!important}
.chart-card,.tx-card{border-radius:16px;margin-bottom:.9rem;overflow:hidden}
.card{border-radius:16px;overflow:hidden;margin-bottom:.9rem}
.card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.85rem 1rem;border-bottom:1px solid var(--border,#1e2536)}
.card-title{font-size:clamp(1rem,4vw,1.1rem)!important;font-weight:800;display:flex;align-items:center;gap:.45rem}
.filters-bar{display:flex;flex-wrap:wrap;gap:.45rem;align-items:center}
.fc{font-size:clamp(.9rem,3.8vw,1rem)!important;min-height:46px;padding:.5rem .75rem;border-radius:10px!important;background:var(--surface2,#141927);border:1.5px solid var(--border,#1e2536);color:var(--text,#e2e8f0);font-family:inherit}
.fc:focus{outline:none;border-color:var(--red,#e63946)!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.table-wrap table{width:100%;border-collapse:collapse}
.table-wrap th{font-size:clamp(.8rem,3vw,.88rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;padding:.65rem .9rem;color:var(--muted,#8892b0)}
.table-wrap td{font-size:clamp(.9rem,3.5vw,.98rem)!important;padding:.7rem .9rem;vertical-align:middle;border-top:1px solid rgba(255,255,255,.05)}
.table-wrap tbody tr:hover{background:rgba(255,255,255,.03)}
.amount-cell{font-size:clamp(1rem,4vw,1.1rem)!important;font-weight:800}
.btn{min-height:46px;font-size:clamp(.95rem,4vw,1.02rem)!important;font-weight:700!important;display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border-radius:11px;transition:all .17s;text-decoration:none;padding:.5rem 1rem;cursor:pointer;border:none;white-space:nowrap;font-family:inherit}
.btn:active{transform:scale(.96)}
.btn-sm{min-height:42px;padding:.42rem .85rem;font-size:clamp(.9rem,3.8vw,.98rem)!important}
.btn-icon{min-height:42px;min-width:42px;padding:0;border-radius:10px}
.btn-primary{background:var(--red,#e63946);color:#fff}
.btn-secondary{background:rgba(255,255,255,.08);color:var(--text,#e2e8f0);border:1px solid var(--border,#1e2536)}
.btn-ghost{background:transparent;color:var(--text,#e2e8f0);border:1px solid rgba(255,255,255,.08)}
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.32rem .8rem;border-radius:999px;font-size:clamp(.82rem,3.5vw,.9rem)!important;font-weight:700;white-space:nowrap}
.badge-basic{background:rgba(255,255,255,.08);color:var(--text,#e2e8f0)}
.badge-income{background:rgba(45,198,83,.15);color:var(--green,#2dc653)}
.badge-expense{background:rgba(230,57,70,.15);color:var(--red,#e63946)}
.empty-state{text-align:center;padding:2.5rem 1rem}
.empty-icon{font-size:2.5rem;opacity:.4;margin-bottom:.65rem}
.empty-state p{font-size:clamp(.95rem,3.5vw,1.05rem)!important;color:var(--muted,#8892b0);margin:0}
.form-section-title{font-size:clamp(.82rem,3.5vw,.9rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#8892b0);margin-bottom:.7rem;margin-top:.2rem;display:flex;align-items:center;gap:.4rem;padding-bottom:.5rem;border-bottom:1px solid var(--border,#1e2536)}
.form-row{display:grid;gap:.8rem;margin-bottom:.8rem}
.form-row.col-2{grid-template-columns:1fr 1fr}
.col-span-2{grid-column:span 2}
.form-label{font-size:clamp(.95rem,4vw,1.05rem)!important;font-weight:700;display:block;margin-bottom:.35rem}
.form-control{font-size:clamp(.95rem,4vw,1.05rem)!important;min-height:50px;padding:.65rem .9rem;border-radius:11px!important;border:1.5px solid var(--border,#1e2536);background:var(--surface2,#141927);color:var(--text,#e2e8f0);width:100%;transition:border-color .2s,box-shadow .2s;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--red,#e63946)!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
select.form-control{cursor:pointer}
.totals-row{display:flex;align-items:center;justify-content:flex-end;gap:1.25rem;padding:.8rem 1rem;border-top:1px solid var(--border,#1e2536);flex-wrap:wrap}
.totals-row span{font-size:clamp(.9rem,3.8vw,1rem)!important;font-weight:800}
.pagination{display:flex;align-items:center;justify-content:center;gap:.35rem;padding:1rem 1rem .9rem;border-top:1px solid var(--border,#1e2536);flex-wrap:wrap}
.btn-pag{min-width:48px!important;min-height:48px!important;font-size:clamp(1rem,4.5vw,1.15rem)!important;font-weight:800!important;border-radius:12px!important;padding:.4rem .5rem!important}
.btn-pag-active{background:var(--red,#e63946)!important;color:#fff!important;box-shadow:0 4px 16px rgba(230,57,70,.35)}
.pag-ellipsis{font-size:clamp(1rem,4vw,1.15rem);font-weight:800;color:var(--muted,#8892b0);padding:0 .2rem;user-select:none}
.pag-info{width:100%;text-align:center;font-size:clamp(.88rem,3.8vw,.98rem)!important;color:var(--muted,#8892b0);font-weight:600;margin-top:.3rem}
.delete-icon-wrap{width:80px;height:80px;border-radius:20px;background:rgba(230,57,70,.12);border:2px solid rgba(230,57,70,.3);display:flex;align-items:center;justify-content:center;font-size:2.4rem;color:var(--red,#e63946);margin:0 auto .9rem}
.delete-modal-title{font-size:clamp(1.25rem,5.5vw,1.6rem)!important;font-weight:800;text-align:center;margin-bottom:.5rem;line-height:1.2}
.delete-modal-sub{font-size:clamp(.95rem,4vw,1.08rem)!important;color:var(--muted,#8892b0);text-align:center;margin-bottom:1rem;line-height:1.5}
.delete-detail-box{background:rgba(230,57,70,.07);border:1.5px solid rgba(230,57,70,.2);border-radius:13px;padding:.9rem 1rem;margin-bottom:1.1rem;font-size:clamp(.95rem,4vw,1.05rem)!important;font-weight:600}
#dm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;cursor:pointer}
#dm-overlay.on{display:block}
.nav-item{min-height:48px!important;font-size:clamp(.95rem,3.5vw,1.02rem)!important;font-weight:600!important;padding:.65rem .9rem!important;border-radius:10px!important;display:flex!important;align-items:center!important;gap:.7rem!important;transition:background .15s!important;text-decoration:none}
.nav-item .icon{width:22px;text-align:center;font-size:1rem;flex-shrink:0}

@media (max-width:900px){
    #menuBtn{display:inline-flex!important;min-width:48px!important;min-height:48px!important;align-items:center!important;justify-content:center!important;font-size:1.3rem!important;cursor:pointer!important}
    .sidebar{position:fixed!important;top:0!important;left:0!important;bottom:0!important;width:min(290px,85vw)!important;z-index:9999!important;transform:translateX(-110%)!important;transition:transform .28s cubic-bezier(.2,.8,.2,1)!important;overflow-y:auto}
    .sidebar.open{transform:translateX(0)!important;box-shadow:6px 0 40px rgba(0,0,0,.7)!important}
    .main-content{margin-left:0!important;width:100%!important}
}
@media (max-width:700px){
    .page-body{padding:.65rem!important}
    .stat-cards-row{gap:.5rem}
    .form-row.col-2{grid-template-columns:1fr!important}
    .col-span-2{grid-column:span 1}
    .col-hide-mobile{display:none!important}
    .modal-box{border-radius:16px;width:min(100%,430px);max-height:calc(100vh - 1rem)}
    .modal-header{padding:.9rem .95rem .75rem}
    .modal-body{padding:.9rem .95rem}
    .modal-footer{padding:0 .95rem .95rem}
}
@media (max-width:480px){
    .stat-cards-row{grid-template-columns:1fr!important}
    .modal-footer{flex-direction:column}
    .modal-footer .btn{width:100%}
    .modal-wrap{padding:.5rem;align-items:flex-start!important;padding-top:.6rem;padding-bottom:.6rem}
    .modal-box{margin:0 auto;width:100%;max-width:100%;max-height:calc(100vh - 1rem);border-radius:14px}
    .modal-title{font-size:clamp(.95rem,4vw,1.08rem)!important}
    .form-label{font-size:clamp(.88rem,3.7vw,.96rem)!important}
    .form-control{min-height:44px;padding:.55rem .75rem;font-size:clamp(.9rem,3.8vw,.98rem)!important}
    .form-section-title{font-size:clamp(.76rem,3.2vw,.84rem)!important;margin-bottom:.55rem;padding-bottom:.4rem}
    .form-row{gap:.65rem;margin-bottom:.65rem}
}
</style>

<script>
function openModal(id){var el=document.getElementById(id);if(!el)return;el.classList.add('is-open');document.body.style.overflow='hidden';setTimeout(function(){var f=el.querySelector('input:not([type=hidden]),select,textarea,button,a');if(f)f.focus();},80);}
function closeModal(id){var el=document.getElementById(id);if(!el)return;el.classList.remove('is-open');if(!document.querySelector('.modal-wrap.is-open'))document.body.style.overflow='';}
function closeAllModals(){document.querySelectorAll('.modal-wrap.is-open').forEach(function(el){el.classList.remove('is-open');});document.body.style.overflow='';}
document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.modal-wrap').forEach(function(wrap){
        wrap.addEventListener('click',function(e){
            if(e.target===wrap){
                if(wrap.id==='modal-edit') history.replaceState(null,'','<?= '?month='.urlencode($month) ?>');
                closeModal(wrap.id);
            }
        });
    });
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAllModals();});
    <?php if($openModal):?>openModal(<?=json_encode($openModal)?>);<?php endif;?>
});
</script>

<body id="page-top">
<div class="app-layout">
<?php renderSidebar('economics'); ?>
<div id="dm-overlay"></div>
<div class="main-content">
<?php renderTopbar('Οικονομικά'); ?>
<div class="page-body">

<!-- ── Οικονομικά / Αναλυτικά tab toggle ── -->
<div class="econ-tabs" role="tablist" aria-label="Οικονομικά">
  <a href="<?= APP_URL ?>/pages/economics_reports.php" class="econ-tab active" role="tab" aria-selected="true">
    <i class="fa-solid fa-wallet"></i><span>Ταμείο</span>
  </a>
  <a href="<?= APP_URL ?>/pages/payment_analytics.php" class="econ-tab" role="tab" aria-selected="false">
    <i class="fa-solid fa-chart-line"></i><span>Αναλυτικά</span>
  </a>
</div>
<style>
.econ-tabs{display:flex;gap:.4rem;background:#111520;border:1px solid #1e2536;border-radius:12px;padding:.35rem;margin-bottom:1rem;overflow-x:auto;-webkit-overflow-scrolling:touch}
.econ-tab{flex:1;min-width:150px;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.7rem .95rem;border-radius:9px;color:#c9cee1 !important;text-decoration:none;font-weight:700;font-size:.92rem;transition:all .18s;white-space:nowrap;border:1px solid transparent}
.econ-tab i{color:#8892b0}
.econ-tab:hover{background:rgba(255,255,255,.04);color:#ffffff !important;border-color:rgba(230,57,70,.35)}
.econ-tab:hover i{color:#e63946}
.econ-tab.active{background:linear-gradient(135deg,#e63946,#c72832);color:#ffffff !important;box-shadow:0 4px 14px -4px rgba(230,57,70,.5);border-color:transparent}
.econ-tab.active i{color:#ffffff !important}
</style>

<div class="page-header anim-1">
    <h2>
        <i class="fa-solid fa-chart-column" style="color:var(--red,#e63946)"></i>
        Οικονομικά
        <span style="font-size:.9rem;font-weight:600;color:var(--muted,#8892b0)">— <?=h(formatGreekMonthYear($month.'-01'))?></span>
    </h2>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <a href="<?=APP_URL?>/pages/economics_reports_print.php?year=<?=urlencode($currentYear)?>"
           class="btn btn-secondary btn-sm" target="_blank" rel="noopener">
            <i class="fa-solid fa-print"></i> Εκτύπωση / PDF
        </a>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('modal-add')">
            <i class="fa-solid fa-plus"></i> Νέα Κίνηση
        </button>
    </div>
</div>

<div class="stat-cards-row anim-2">
    <div class="stat-card card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div class="stat-val text-green"><?=formatMoney($income)?></div>
        <div class="stat-lbl">Έσοδα Μήνα</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-red"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div class="stat-val text-red"><?=formatMoney($expense)?></div>
        <div class="stat-lbl">Έξοδα Μήνα</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon <?=$profit>=0?'icon-blue':'icon-red'?>"><i class="fa-solid fa-scale-balanced"></i></div>
        <div class="stat-val <?=$profit>=0?'text-green':'text-red'?>"><?=formatMoney($profit)?></div>
        <div class="stat-lbl">Καθαρό Αποτέλεσμα</div>
    </div>
</div>

<div class="card chart-card anim-3">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-chart-bar" style="color:var(--gold,#f0a500)"></i> Εξέλιξη Τελευταίων 6 Μηνών</div>
    </div>
    <div style="padding:.85rem"><canvas id="finChart" style="max-height:200px"></canvas></div>
</div>

<div class="card tx-card anim-4">
    <div class="card-header" style="flex-wrap:wrap;gap:.55rem">
        <div class="card-title">
            <i class="fa-solid fa-list" style="color:var(--red,#e63946)"></i>
            Κινήσεις
            <?php if($txTotal):?><span class="badge badge-basic"><?=$txTotal?></span><?php endif;?>
        </div>
        <div class="filters-bar">
            <form method="GET" id="monthForm">
                <input type="text" name="month" class="fc js-month-mask"
                    value="<?=h($month)?>" inputmode="numeric" maxlength="7"
                    placeholder="YYYY-MM" style="min-width:145px;max-width:165px" autocomplete="off">
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Ημ/νία</th><th>Τύπος</th>
                <th class="col-hide-mobile">Κατηγορία</th>
                <th>Περιγραφή</th><th>Ποσό</th>
                <th class="col-hide-mobile">Τρόπος</th>
                <th style="text-align:center">Ενέργειες</th>
            </tr></thead>
            <tbody>
            <?php foreach($transactions as $t):?>
            <tr>
                <td style="white-space:nowrap"><?=formatDate($t['transaction_date'])?></td>
                <td><span class="badge <?=$t['type']==='income'?'badge-income':'badge-expense'?>">
                    <i class="fa-solid <?=$t['type']==='income'?'fa-arrow-up':'fa-arrow-down'?>"></i>
                    <?=$t['type']==='income'?'Έσοδο':'Έξοδο'?>
                </span></td>
                <td class="col-hide-mobile" style="color:var(--muted,#8892b0)"><?=h($t['category'])?></td>
                <td style="max-width:200px">
                    <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px"><?=h($t['description']?:'—')?></div>
                    <?php if(!empty($t['full_name'])):?>
                    <div style="color:var(--muted,#8892b0);font-size:.78em"><i class="fa-solid fa-user" style="font-size:.75em"></i> <?=h($t['full_name'])?></div>
                    <?php endif;?>
                </td>
                <td class="amount-cell <?=$t['type']==='income'?'text-green':'text-red'?>"><?=$t['type']==='income'?'+':'−'?><?=formatMoney($t['amount'])?></td>
                <td class="col-hide-mobile" style="color:var(--muted,#8892b0)"><?=h($payMethods[$t['payment_method']]??'—')?></td>
                <td>
                    <div style="display:flex;gap:.3rem;justify-content:center">
                        <a href="?month=<?=urlencode($month)?>&edit_id=<?=(int)$t['id']?>"
                           class="btn btn-ghost btn-sm" style="color:var(--gold,#f0a500)" title="Επεξεργασία">
                            <i class="fa-solid fa-pen-to-square"></i> Επεξεργασία
                        </a>
                        <button type="button" class="btn btn-ghost btn-sm" style="color:var(--red,#e63946)"
                                onclick="openModal('modal-delete-<?=(int)$t['id']?>')" title="Διαγραφή">
                            <i class="fa-solid fa-trash"></i> Διαγραφή
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach;?>
            <?php if(!$transactions):?>
            <tr><td colspan="7">
                <div class="empty-state">
                    <div class="empty-icon"><i class="fa-solid fa-receipt"></i></div>
                    <p>Δεν υπάρχουν κινήσεις για αυτόν τον μήνα</p>
                    <button type="button" class="btn btn-primary btn-sm" style="margin-top:.75rem"
                            onclick="openModal('modal-add')">
                        <i class="fa-solid fa-plus"></i> Προσθήκη Κίνησης
                    </button>
                </div>
            </td></tr>
            <?php endif;?>
            </tbody>
        </table>
    </div>

    <?php if($transactions):?>
    <div class="totals-row">
        <span style="color:var(--muted,#8892b0);font-weight:600">Σύνολο μήνα:</span>
        <span class="text-green"><i class="fa-solid fa-arrow-up"></i> <?=formatMoney($income)?></span>
        <span class="text-red"><i class="fa-solid fa-arrow-down"></i> <?=formatMoney($expense)?></span>
        <span style="border-left:1.5px solid var(--border,#1e2536);padding-left:1.25rem;margin-left:.25rem">
            = <span class="<?=$profit>=0?'text-green':'text-red'?>"><?=formatMoney(abs($profit))?></span>
        </span>
    </div>
    <?php endif;?>

    <?php if($txPages>1):?>
    <div class="pagination">
        <?php if($txPage>1):?>
            <a href="<?=h(txPageUrl(1))?>" class="btn btn-ghost btn-pag btn-icon" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a>
            <a href="<?=h(txPageUrl($txPage-1))?>" class="btn btn-ghost btn-pag btn-icon" title="Προηγούμενη"><i class="fa-solid fa-angle-left"></i></a>
        <?php endif;?>
        <?php
        $range=2; $pages_to_show=[];
        for($i=1;$i<=$txPages;$i++){if($i===1||$i===$txPages||($i>=$txPage-$range&&$i<=$txPage+$range))$pages_to_show[]=$i;}
        $prev=null;
        foreach($pages_to_show as $i):
            if($prev!==null&&$i-$prev>1):?><span class="pag-ellipsis">…</span><?php endif;?>
            <a href="<?=h(txPageUrl($i))?>" class="btn btn-pag <?=$i===$txPage?'btn-pag-active':'btn-ghost'?>"><?=$i?></a>
        <?php $prev=$i; endforeach;?>
        <?php if($txPage<$txPages):?>
            <a href="<?=h(txPageUrl($txPage+1))?>" class="btn btn-ghost btn-pag btn-icon" title="Επόμενη"><i class="fa-solid fa-angle-right"></i></a>
            <a href="<?=h(txPageUrl($txPages))?>" class="btn btn-ghost btn-pag btn-icon" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a>
        <?php endif;?>
        <span class="pag-info">Σελίδα <?=$txPage?> / <?=$txPages?> &nbsp;·&nbsp; <?=$txTotal?> κινήσεις</span>
    </div>
    <?php endif;?>
</div>

</div></div></div>

<!-- ADD MODAL -->
<div id="modal-add" class="modal-wrap" role="dialog" aria-modal="true" aria-labelledby="modal-add-title">
    <div class="modal-box" role="document">
        <div class="modal-header">
            <div class="modal-title" id="modal-add-title"><i class="fa-solid fa-plus" style="color:var(--green,#2dc653)"></i> Νέα Κίνηση</div>
            <button type="button" class="modal-close" onclick="closeModal('modal-add')" aria-label="Κλείσιμο"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="_action" value="save_tx">
                <input type="hidden" name="csrf_token" value="<?=csrf()?>">
                <div class="form-section-title"><i class="fa-solid fa-tags"></i> Ταξινόμηση</div>
                <div class="form-row col-2">
                    <div>
                        <label class="form-label">Τύπος</label>
                        <select name="type" class="form-control js-tx-type" data-target="add-category">
                            <option value="income">Έσοδο</option>
                            <option value="expense">Έξοδο</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Κατηγορία</label>
                        <select name="category" id="add-category" class="form-control js-category-select"></select>
                    </div>
                </div>
                <div class="form-section-title"><i class="fa-solid fa-euro-sign"></i> Ποσό &amp; Ημερομηνία</div>
                <div class="form-row col-2">
                    <div>
                        <label class="form-label">Ποσό (€) <span style="color:var(--red,#e63946)">*</span></label>
                        <input type="number" step=".01" name="amount" class="form-control" required placeholder="0.00" min="0">
                    </div>
                    <div>
                        <label class="form-label">Ημερομηνία <span style="color:var(--red,#e63946)">*</span></label>
                        <input type="text" name="transaction_date" class="form-control js-date-mask" required
                               inputmode="numeric" maxlength="10" placeholder="π.χ. 12/10/2025"
                               value="<?=date('d/m/Y')?>" autocomplete="off">
                    </div>
                    <div class="col-span-2">
                        <label class="form-label">Περιγραφή</label>
                        <input name="description" class="form-control" placeholder="π.χ. Συνδρομή Ιανουαρίου">
                    </div>
                    <div>
                        <label class="form-label">Τρόπος Πληρωμής</label>
                        <select name="payment_method" class="form-control">
                            <?php foreach($payMethods as $v=>$l):?><option value="<?=h($v)?>"><?=h($l)?></option><?php endforeach;?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Σημειώσεις</label>
                        <input name="notes" class="form-control" placeholder="Προαιρετικά...">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary" style="flex:1"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('modal-add')">Ακύρωση</button>
            </div>
        </form>
    </div>
</div>

<?php
$et=$editTxData; $etId=$et?(int)$et['id']:0; $etType=$et?$et['type']:'income';
$cleanMonthUrl='?month='.urlencode($month);
?>
<!-- EDIT MODAL -->
<div id="modal-edit" class="modal-wrap" role="dialog" aria-modal="true" aria-labelledby="modal-edit-title">
    <div class="modal-box" role="document">
        <div class="modal-header">
            <div class="modal-title" id="modal-edit-title"><i class="fa-solid fa-pen-to-square" style="color:var(--gold,#f0a500)"></i> Επεξεργασία Κίνησης</div>
            <button type="button" class="modal-close" aria-label="Κλείσιμο"
                    onclick="closeModal('modal-edit');history.replaceState(null,'','<?=$cleanMonthUrl?>')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="_action" value="save_tx">
                <input type="hidden" name="csrf_token" value="<?=csrf()?>">
                <input type="hidden" name="id" value="<?=$etId?>">
                <?php if(!$et):?>
                <p style="color:var(--muted,#8892b0);text-align:center;padding:1rem">Επιλέξτε μια κίνηση για επεξεργασία από τον πίνακα.</p>
                <?php else:?>
                <div class="form-section-title"><i class="fa-solid fa-tags"></i> Ταξινόμηση</div>
                <div class="form-row col-2">
                    <div>
                        <label class="form-label">Τύπος</label>
                        <select name="type" class="form-control js-tx-type" data-target="edit-category">
                            <option value="income" <?=$etType==='income'?'selected':''?>>Έσοδο</option>
                            <option value="expense" <?=$etType==='expense'?'selected':''?>>Έξοδο</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Κατηγορία</label>
                        <select name="category" id="edit-category" class="form-control js-category-select"
                                data-selected="<?=h($et['category']??'')?>"></select>
                    </div>
                </div>
                <div class="form-section-title"><i class="fa-solid fa-euro-sign"></i> Ποσό &amp; Ημερομηνία</div>
                <div class="form-row col-2">
                    <div>
                        <label class="form-label">Ποσό (€) <span style="color:var(--red,#e63946)">*</span></label>
                        <input type="number" step=".01" name="amount" class="form-control" required value="<?=h($et['amount'])?>" min="0">
                    </div>
                    <div>
                        <label class="form-label">Ημερομηνία <span style="color:var(--red,#e63946)">*</span></label>
                        <input type="text" name="transaction_date" class="form-control js-date-mask" required
                               inputmode="numeric" maxlength="10" placeholder="π.χ. 12/10/2025"
                               value="<?=h(dbDateToGreekInput($et['transaction_date']??''))?>" autocomplete="off">
                    </div>
                    <div class="col-span-2">
                        <label class="form-label">Περιγραφή</label>
                        <input name="description" class="form-control" value="<?=h($et['description']??'')?>" placeholder="π.χ. Συνδρομή Ιανουαρίου">
                    </div>
                    <div>
                        <label class="form-label">Τρόπος Πληρωμής</label>
                        <select name="payment_method" class="form-control">
                            <?php foreach($payMethods as $v=>$l):?>
                            <option value="<?=h($v)?>" <?=($et['payment_method']===$v)?'selected':''?>><?=h($l)?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Σημειώσεις</label>
                        <?php
                            // Αφαίρεση εσωτερικού marker sub_id:XXX (αποθηκεύεται αυτόματα, δεν είναι σημείωση χρήστη)
                            $displayNotes = preg_replace('/^sub_id:\d+\s*/', '', $et['notes'] ?? '');
                        ?>
                        <input name="notes" class="form-control" value="<?=h($displayNotes)?>" placeholder="Προαιρετικά...">
                    </div>
                </div>
                <?php endif;?>
            </div>
            <div class="modal-footer">
                <?php if($et):?>
                <button type="submit" class="btn btn-primary" style="flex:1"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
                <?php endif;?>
                <button type="button" class="btn btn-secondary btn-sm"
                        onclick="closeModal('modal-edit');history.replaceState(null,'','<?=$cleanMonthUrl?>')">Ακύρωση</button>
            </div>
        </form>
    </div>
</div>

<?php foreach($transactions as $t):
    $delId=(int)$t['id'];
    $delDesc=h($t['description']?:'Κίνηση');
    $delAmt=($t['type']==='income'?'+':'−').formatMoney($t['amount']);
    $delColor=$t['type']==='income'?'var(--green,#2dc653)':'var(--red,#e63946)';
?>
<div id="modal-delete-<?=$delId?>" class="modal-wrap" role="dialog" aria-modal="true">
    <div class="modal-box modal-sm" role="document">
        <div class="modal-header">
            <div class="modal-title" style="color:var(--red,#e63946)"><i class="fa-solid fa-triangle-exclamation"></i> Επιβεβαίωση Διαγραφής</div>
            <button type="button" class="modal-close" onclick="closeModal('modal-delete-<?=$delId?>')" aria-label="Κλείσιμο"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="padding-top:.9rem">
            <div class="delete-icon-wrap"><i class="fa-solid fa-trash"></i></div>
            <div class="delete-modal-title">Διαγραφή Κίνησης;</div>
            <div class="delete-modal-sub">Αυτή η ενέργεια <strong>δεν μπορεί να αναιρεθεί</strong>.</div>
            <div class="delete-detail-box"><strong><?=$delDesc?></strong> — <span style="color:<?=$delColor?>"><?=$delAmt?></span></div>
        </div>
        <form method="POST">
            <input type="hidden" name="_action" value="delete_tx">
            <input type="hidden" name="csrf_token" value="<?=csrf()?>">
            <input type="hidden" name="id" value="<?=$delId?>">
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" style="flex:1" onclick="closeModal('modal-delete-<?=$delId?>')"><i class="fa-solid fa-arrow-left"></i> Άκυρο</button>
                <button type="submit" class="btn" style="flex:1;background:var(--red,#e63946);color:#fff"><i class="fa-solid fa-trash"></i> Ναι, Διέγραψέ το</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach;?>

<script>
(function(){
    var sb=document.getElementById('sidebar'),ov=document.getElementById('dm-overlay'),mb=document.getElementById('menuBtn');
    if(!sb||!mb)return;
    function open(){sb.classList.add('open');ov&&ov.classList.add('on');document.body.style.overflow='hidden';}
    function close(){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}
    mb.onclick=function(e){e.stopPropagation();sb.classList.contains('open')?close():open();};
    ov&&ov.addEventListener('click',close);
    sb.querySelectorAll('a.nav-item').forEach(function(link){link.addEventListener('click',function(){if(window.innerWidth<=900)setTimeout(close,80);});});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
    window.addEventListener('resize',function(){if(window.innerWidth>900){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}});
})();

document.querySelectorAll('.topbar').forEach(function(el){
    var p=getComputedStyle(el).position;
    if(p==='fixed'||p==='sticky'){el.style.setProperty('position','relative','important');el.style.setProperty('top','auto','important');}
});

(function(){
    var ctx=document.getElementById('finChart');
    if(!ctx)return;
    try{
        new Chart(ctx.getContext('2d'),{
            type:'bar',
            data:{
                labels:<?=json_encode(array_column($chartData,'month'),JSON_UNESCAPED_UNICODE)?>,
                datasets:[
                    {label:'Έσοδα',data:<?=json_encode(array_column($chartData,'income'))?>,backgroundColor:'rgba(45,198,83,.55)',borderColor:'rgba(45,198,83,1)',borderWidth:2,borderRadius:7},
                    {label:'Έξοδα',data:<?=json_encode(array_column($chartData,'expense'))?>,backgroundColor:'rgba(230,57,70,.55)',borderColor:'rgba(230,57,70,1)',borderWidth:2,borderRadius:7}
                ]
            },
            options:{
                responsive:true,
                plugins:{legend:{labels:{color:'#7a849e',font:{size:13,weight:'700'},padding:14}}},
                scales:{
                    x:{ticks:{color:'#7a849e'},grid:{color:'rgba(255,255,255,.05)'}},
                    y:{ticks:{color:'#7a849e'},grid:{color:'rgba(255,255,255,.05)'}}
                }
            }
        });
    }catch(e){console.warn('finChart error',e);}
})();

const incomeCategories=<?=json_encode(array_values($incomeCategories),JSON_UNESCAPED_UNICODE)?>;
const expenseCategories=<?=json_encode(array_values($expenseCategories),JSON_UNESCAPED_UNICODE)?>;

function populateCategorySelect(selectEl,type,selectedValue){
    if(!selectEl)return;
    const items=type==='expense'?expenseCategories:incomeCategories;
    selectEl.innerHTML='';
    items.forEach(function(item){const opt=document.createElement('option');opt.value=item;opt.textContent=item;if(selectedValue&&selectedValue===item)opt.selected=true;selectEl.appendChild(opt);});
    if(!items.includes(selectedValue)&&items.length>0)selectEl.value=items[0];
}
function initTxCategoryFilters(scope){
    (scope||document).querySelectorAll('.js-tx-type').forEach(function(typeSelect){
        const targetId=typeSelect.getAttribute('data-target');
        const categorySelect=document.getElementById(targetId);
        if(!categorySelect)return;
        const selectedFromData=categorySelect.getAttribute('data-selected')||'';
        populateCategorySelect(categorySelect,typeSelect.value,selectedFromData);
        typeSelect.addEventListener('change',function(){populateCategorySelect(categorySelect,typeSelect.value,'');});
    });
}
function applyDateMask(input){
    if(!input)return;
    input.addEventListener('input',function(){let v=input.value.replace(/\D/g,'').slice(0,8);if(v.length>=5)v=v.slice(0,2)+'/'+v.slice(2,4)+'/'+v.slice(4);else if(v.length>=3)v=v.slice(0,2)+'/'+v.slice(2);input.value=v;});
    input.addEventListener('keypress',function(e){if(!/[0-9]/.test(e.key))e.preventDefault();});
    input.addEventListener('paste',function(e){e.preventDefault();const pasted=(e.clipboardData||window.clipboardData).getData('text')||'';let v=pasted.replace(/\D/g,'').slice(0,8);if(v.length>=5)v=v.slice(0,2)+'/'+v.slice(2,4)+'/'+v.slice(4);else if(v.length>=3)v=v.slice(0,2)+'/'+v.slice(2);input.value=v;});
}
function initDateMasks(scope){
    (scope||document).querySelectorAll('.js-date-mask').forEach(function(input){if(input.dataset.maskReady==='1')return;input.dataset.maskReady='1';applyDateMask(input);});
}
function applyMonthMask(input){
    if(!input)return;
    var lastValid=input.value;
    function isValidMonth(str){return /^(20\d{2})-(0[1-9]|1[0-2])$/.test(str);}
    function formatAndSet(value){
        var digits=value.replace(/\D/g,'').slice(0,6);
        if(digits.length<=4){input.value=digits;return input.value;}
        var year=digits.slice(0,4),monthDigits=digits.slice(4);
        if(monthDigits.length===1){var d=monthDigits[0];if(d>='1'&&d<='9')monthDigits='0'+d;else monthDigits='';}
        if(monthDigits.length>=2){monthDigits=monthDigits.slice(0,2);if(!/^(0[1-9]|1[0-2])$/.test(monthDigits))monthDigits='';}
        input.value=year+'-'+monthDigits;return input.value;
    }
    input.addEventListener('keydown',function(e){
        var allowedKeys=['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End','Enter'];
        if(allowedKeys.includes(e.key)){if(e.key==='Enter'){e.preventDefault();var val=input.value;if(isValidMonth(val))input.form.submit();else if(lastValid&&isValidMonth(lastValid)){input.value=lastValid;input.form.submit();}}return;}
        if(!/^\d$/.test(e.key)){e.preventDefault();return;}
        var val=input.value;
        if(/^20\d{2}-0[1-9]$/.test(val)){var first=val.slice(6,7),newMonth=first+e.key;if(/^(10|11|12)$/.test(newMonth)){input.value=val.slice(0,5)+newMonth;lastValid=input.value;e.preventDefault();return;}}
    });
    input.addEventListener('input',function(){var f=formatAndSet(input.value);if(isValidMonth(f))lastValid=f;});
    input.addEventListener('paste',function(e){e.preventDefault();var pasted=(e.clipboardData||window.clipboardData).getData('text')||'';var f=formatAndSet(pasted);if(isValidMonth(f))lastValid=f;});
    input.addEventListener('change',function(){var val=input.value;if(isValidMonth(val))input.form.submit();else{if(lastValid&&isValidMonth(lastValid))input.value=lastValid;else{var fb='<?=date('Y-m')?>';input.value=fb;lastValid=fb;}}});
    input.addEventListener('blur',function(){var val=input.value;if(isValidMonth(val))input.form.submit();else{if(lastValid&&isValidMonth(lastValid))input.value=lastValid;else{var fb='<?=date('Y-m')?>';input.value=fb;lastValid=fb;}if(isValidMonth(input.value))input.form.submit();}});
}
document.addEventListener('DOMContentLoaded',function(){
    initTxCategoryFilters(document);
    initDateMasks(document);
    document.querySelectorAll('.js-month-mask').forEach(function(input){applyMonthMask(input);});
});
</script>
</body>
</html>