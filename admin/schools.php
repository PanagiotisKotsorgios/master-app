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
        error_log('[schools.php] EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
 * admin/schools.php — Διαχείριση Σχολών (Super Admin)
 * ============================================================
 * PURPOSE:
 *   Full CRUD σχολών, impersonation, trial extension,
 *   manual plan change, admin notes, CSV export, churn filter.
 *
 * ACTIONS (POST _action):
 *   save_school, extend_trial, manual_plan, save_note,
 *   toggle_sms_addon, impersonate, delete_school
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf() σε κάθε POST
 *   ✓ Impersonation: session flag + audit log (ανιχνεύσιμο)
 *   ✓ delete: soft-delete (active=0), όχι hard DELETE
 *   ✓ Prepared statements
 *   ✓ h() output, (int) cast για IDs
 *   ✓ CSV export: BOM + proper headers
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();
$db = getDB();

// CSV Export
if (isset($_GET['export_csv'])) {
    $rows = $db->query("
        SELECT s.id,s.name,s.email,s.city,s.phone,s.afm,
               p.name as plan_name,s.plan_status,s.plan_expires,s.trial_ends,s.active,s.created_at,
               (SELECT COUNT(*) FROM athletes a WHERE a.school_id=s.id AND a.active=1) as athletes,
               (SELECT MAX(created_at) FROM audit_log al WHERE al.school_id=s.id AND al.action='login') as last_login
        FROM schools s JOIN plans p ON p.id=s.plan_id ORDER BY s.created_at DESC
    ")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="schools_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['ID','Όνομα','Email','Πόλη','Τηλέφωνο','ΑΦΜ','Πλάνο','Κατάσταση','Λήξη','Trial Λήξη','Ενεργό','Εγγραφή','Αθλητές','Τελ. Σύνδεση']);
    foreach ($rows as $r) fputcsv($out,[$r['id'],$r['name'],$r['email'],$r['city']??'',$r['phone']??'',$r['afm']??'',$r['plan_name'],$r['plan_status'],$r['plan_expires']??'',$r['trial_ends']??'',$r['active']?'Ναι':'Όχι',$r['created_at'],$r['athletes'],$r['last_login']??'Ποτέ']);
    fclose($out); exit;
}

// Impersonate
if (isset($_GET['impersonate'])) {
    $sid = (int)$_GET['impersonate'];
    $stImp = $db->prepare("SELECT * FROM schools WHERE id=? LIMIT 1"); $stImp->execute([$sid]); $school = $stImp->fetch();
    $stUsr = $db->prepare("SELECT * FROM users WHERE school_id=? AND role='owner' LIMIT 1"); $stUsr->execute([$sid]); $user = $stUsr->fetch();
    if ($school && $user) {
        $_SESSION['user_id']=$user['id']; $_SESSION['school_id']=$user['school_id'];
        $_SESSION['school_name']=$school['name'];
        $_SESSION['user']=['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']];
        $_SESSION['impersonating']=true;
        flash('Impersonating '.$school['name'].' — <a href="'.APP_URL.'/admin/">Επιστροφή στο Admin</a>');
        redirect(APP_URL.'/dashboard/');
    }
}

// POST
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $a=$_POST;

    if ($a['_action']==='save_school') {
        $id=(int)($a['id']??0);
        $f=['name','afm','doy','address','city','phone','email','iban','plan_id','plan_status','plan_expires','trial_ends','active'];
        $data=array_map(fn($k)=>trim($a[$k]??'')?:null,$f);
        if ($id) {
            $sql="UPDATE schools SET ".implode(',',array_map(fn($k)=>"$k=?",$f))." WHERE id=?";
            $db->prepare($sql)->execute([...$data,$id]);
            $smsAddon=isset($a['sms_addon'])?1:0;
            $smsExp=trim($a['sms_addon_expires']??'')?:null;
            $db->prepare("UPDATE schools SET sms_addon=?,sms_addon_expires=? WHERE id=?")->execute([$smsAddon,$smsExp,$id]);
            try { $db->prepare("UPDATE schools SET admin_note=? WHERE id=?")->execute([trim($a['admin_note']??''),$id]); } catch(\Exception $e){}
            flash('✅ Σχολή ενημερώθηκε!');
        } else {
            $sql="INSERT INTO schools (".implode(',',$f).") VALUES (?".str_repeat(',?',count($f)-1).")";
            $db->prepare($sql)->execute($data);
            $newId=$db->lastInsertId();
            if (!empty($a['owner_email'])&&!empty($a['owner_name'])) {
                $pw=password_hash($a['owner_password']??'changeme123',PASSWORD_DEFAULT);
                $db->prepare("INSERT INTO users (school_id,name,email,password,role) VALUES (?,?,?,?,'owner')")->execute([$newId,$a['owner_name'],$a['owner_email'],$pw]);
            }
            flash('✅ Σχολή δημιουργήθηκε!');
        }
    }

    if ($a['_action']==='extend_trial') {
        $id=(int)$a['id']; $days=max(1,min(365,(int)($a['days']??14)));
        $db->prepare("UPDATE schools SET trial_ends=DATE_ADD(GREATEST(COALESCE(trial_ends,CURDATE()),CURDATE()),INTERVAL ? DAY),plan_status='trial' WHERE id=?")->execute([$days,$id]);
        auditLog('extend_trial','school',$id,"Παράταση trial κατά {$days} ημέρες");

        // ── Auto-email: trial extension notification ──
        try {
            $newEnd = $db->prepare("SELECT trial_ends, name, email FROM schools WHERE id=? LIMIT 1");
            $newEnd->execute([$id]);
            $sr = $newEnd->fetch();
            $ownerRow = $db->prepare("SELECT name FROM users WHERE school_id=? AND role='owner' LIMIT 1");
            $ownerRow->execute([$id]);
            $ownerName = $ownerRow->fetchColumn() ?: 'Διαχειριστή';
            if ($sr && filter_var($sr['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $trialEnd = $sr['trial_ends'] ? date('d/m/Y', strtotime($sr['trial_ends'])) : '—';
                $dbg = null;
                $bodyText = "Αγαπητέ/ή $ownerName,\n\nΤο δοκιμαστικό σας διάστημα στη σχολή «{$sr['name']}» παρατάθηκε κατά $days ημέρες.\n\nΝέα λήξη δοκιμής: $trialEnd\n\n— MAster";
                sendEmail($sr['email'], "Παράταση Δοκιμαστικής Περιόδου — {$sr['name']}", buildEmailHtml($bodyText, 'MAster', 'Παράταση Δοκιμαστικής Περιόδου'), $bodyText, $ownerName, $dbg);
            }
        } catch (Throwable $mailEx) {
            error_log('[schools.php extend_trial] email error: ' . $mailEx->getMessage());
        }

        flash("✅ Trial παρατάθηκε κατά {$days} ημέρες.");
    }

    if ($a['_action']==='manual_plan') {
        $id=(int)$a['id']; $planId=(int)$a['plan_id'];
        $expires=trim($a['plan_expires']??'')?:null;
        $db->prepare("UPDATE schools SET plan_id=?,plan_status='active',plan_expires=? WHERE id=?")->execute([$planId,$expires,$id]);
        $amount=(float)(trim($a['amount']??'') ?: 0);
        $rawMethod=$a['payment_method']??'cash';
        // Map form values to DB enum('card','bank','cash')
        $methodMap=['bank_transfer'=>'bank','manual'=>'cash','card'=>'card','cash'=>'cash','bank'=>'bank'];
        $method=$methodMap[$rawMethod]??'cash';
        $note=trim($a['payment_note']??'Χειροκίνητη ενεργοποίηση από admin');
        // period enum only allows 'monthly' or 'annual'
        $rawPeriod=$a['period']??'monthly';
        $period=in_array($rawPeriod,['monthly','annual'])?$rawPeriod:'monthly';
        if ($expires) $db->prepare("INSERT INTO school_plan_payments (school_id,plan_id,amount,period,valid_until,method,notes) VALUES (?,?,?,?,?,?,?)")->execute([$id,$planId,$amount,$period,$expires,$method,$note]);
        auditLog('manual_plan_change','school',$id,"Plan ID:{$planId}, λήξη:{$expires}");

        // ── Auto-email: notify school owner about plan activation/renewal ──
        try {
            $schoolRow = $db->prepare("SELECT s.name, s.email, u.name as owner_name, p.name as plan_name FROM schools s LEFT JOIN users u ON u.school_id=s.id AND u.role='owner' LEFT JOIN plans p ON p.id=? WHERE s.id=? LIMIT 1");
            $schoolRow->execute([$planId, $id]);
            $sr = $schoolRow->fetch();
            $toEmail = trim($sr['email'] ?? '');
            if ($sr && $toEmail && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                sendSchoolPlanActivationEmail(
                    $toEmail,
                    $sr['owner_name'] ?? 'Διαχειριστή',
                    $sr['name'] ?? '',
                    $sr['plan_name'] ?? 'Premium',
                    $expires ?? '',
                    $amount,
                    $method
                );
            }
        } catch (Throwable $mailEx) {
            error_log('[schools.php manual_plan] email error: ' . $mailEx->getMessage());
        }

        flash('✅ Πλάνο ενημερώθηκε χειροκίνητα!');
    }

    if ($a['_action']==='save_note') {
        $id=(int)$a['id'];
        try { $db->prepare("UPDATE schools SET admin_note=? WHERE id=?")->execute([trim($a['admin_note']),$id]); flash('✅ Σημείωση αποθηκεύτηκε.'); } catch(\Exception $e){ flash('Σφάλμα: η στήλη admin_note δεν υπάρχει ακόμα. Τρέξε το migration SQL.','error'); }
    }

    if ($a['_action']==='toggle_sms_addon') {
        $id=(int)$a['id']; $cur=$db->prepare("SELECT sms_addon FROM schools WHERE id=?"); $cur->execute([$id]);
        $new=(int)!$cur->fetchColumn(); $exp=$new?date('Y-m-d',strtotime('+1 month')):null;
        $db->prepare("UPDATE schools SET sms_addon=?,sms_addon_expires=? WHERE id=?")->execute([$new,$exp,$id]);
        flash($new?'✅ SMS addon ενεργοποιήθηκε.':'SMS addon απενεργοποιήθηκε.');
    }

    if ($a['_action']==='delete_school') {
        $id=(int)$a['id'];
        foreach(['users','athletes','subscriptions','transactions','departments','adult_members','adult_subscriptions'] as $t) {
            try { $db->prepare("DELETE FROM $t WHERE school_id=?")->execute([$id]); } catch(\Exception $e){}
        }
        $db->prepare("DELETE FROM schools WHERE id=?")->execute([$id]);
        flash('Σχολή διαγράφηκε.'); redirect(APP_URL.'/admin/schools.php');
    }

    if ($a['_action']==='toggle_school') {
        $db->prepare("UPDATE schools SET active=NOT active WHERE id=?")->execute([(int)$a['id']]);
        flash('Κατάσταση ενημερώθηκε.');
    }
    redirect(APP_URL.'/admin/schools.php');
}

$hasAdminNote=false;
try { $db->query("SELECT admin_note FROM schools LIMIT 1"); $hasAdminNote=true; } catch(\Exception $e){}

$plans=$db->query("SELECT * FROM plans WHERE active=1")->fetchAll();
$search=trim($_GET['q']??''); $filterStatus=trim($_GET['status']??''); $filterPlan=(int)($_GET['plan_id']??0);
$filterChurn=isset($_GET['churn']); $page=max(1,(int)($_GET['page']??1)); $limit = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;
$where=['1=1']; $params=[];
if($search){ $where[]="(s.name LIKE ? OR s.email LIKE ? OR s.city LIKE ? OR s.phone LIKE ?)"; $params=array_merge($params,["%$search%","%$search%","%$search%","%$search%"]); }
if($filterStatus){ $where[]="s.plan_status=?"; $params[]=$filterStatus; }
if($filterPlan){ $where[]="s.plan_id=?"; $params[]=$filterPlan; }
if($filterChurn) $where[]="s.id NOT IN (SELECT DISTINCT school_id FROM audit_log WHERE action='login' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY))";
$whereStr=implode(' AND ',$where);
$countStmt=$db->prepare("SELECT COUNT(*) FROM schools s WHERE $whereStr"); $countStmt->execute($params);
$totalRows=(int)$countStmt->fetchColumn(); $totalPages = max(1, (int)ceil($totalRows / max(1, $limit))); $offset=($page-1)*$limit;
$noteCol=$hasAdminNote?",s.admin_note":",NULL as admin_note";
$stmt=$db->prepare("SELECT s.*,p.name as plan_name,p.slug as plan_slug{$noteCol},(SELECT COUNT(*) FROM athletes a WHERE a.school_id=s.id AND a.active=1) as athlete_count,(SELECT COUNT(*) FROM users u WHERE u.school_id=s.id) as user_count,(SELECT MAX(created_at) FROM audit_log al WHERE al.school_id=s.id AND al.action='login') as last_login FROM schools s JOIN plans p ON p.id=s.plan_id WHERE $whereStr ORDER BY s.created_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params); $schools=$stmt->fetchAll();
$editId=(int)($_GET['edit']??0); $editSchool=null;
if($editId){ $stEdit=$db->prepare("SELECT * FROM schools WHERE id=? LIMIT 1"); $stEdit->execute([$editId]); $editSchool=$stEdit->fetch(); if($hasAdminNote&&$editSchool){ $n=$db->prepare("SELECT admin_note FROM schools WHERE id=?"); $n->execute([$editId]); $editSchool['admin_note']=$n->fetchColumn(); } }
$churnCount=(int)$db->query("SELECT COUNT(*) FROM schools WHERE id NOT IN (SELECT DISTINCT school_id FROM audit_log WHERE action='login' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY))")->fetchColumn();
renderHead('Διαχείριση Σχολών');
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
.quick-actions{display:flex;gap:5px;flex-wrap:wrap}
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9998;align-items:center;justify-content:center}
.modal-backdrop.open{display:flex}
.modal-box{background:var(--card,#111520);border:1px solid var(--border,#1e2536);border-radius:16px;padding:1.5rem;min-width:320px;max-width:500px;width:100%;max-height:90vh;overflow-y:auto}
.modal-title{font-size:1rem;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.note-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#f0a500;margin-left:3px;vertical-align:middle}
</style>
<body><div class="app-layout">
<?php renderSidebar('admin_schools'); ?>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-school"></i> Διαχείριση Σχολών'); ?>
<div class="page-body">

<?php if ($editSchool||isset($_GET['add'])): $es=$editSchool; ?>
<div class="d-flex ai-center gap-md mb-3">
  <a href="<?=APP_URL?>/admin/schools.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Πίσω</a>
  <h2><?=$editSchool?'Επεξεργασία: '.h($editSchool['name']):'Νέα Σχολή'?></h2>
</div>
<div class="card">
<form method="POST">
  <input type="hidden" name="_action" value="save_school">
  <input type="hidden" name="csrf_token" value="<?=csrf()?>">
  <input type="hidden" name="id" value="<?=$es['id']??''?>">
  <div class="mb-3"><div class="text-muted text-sm mb-2">Στοιχεία Σχολής</div>
    <div class="form-row col-3">
      <div class="form-group"><label class="form-label">Όνομα *</label><input name="name" class="form-control" value="<?=h($es['name']??'')?>" required></div>
      <div class="form-group"><label class="form-label">ΑΦΜ</label><input name="afm" class="form-control" value="<?=h($es['afm']??'')?>"></div>
      <div class="form-group"><label class="form-label">ΔΟΥ</label><input name="doy" class="form-control" value="<?=h($es['doy']??'')?>"></div>
      <div class="form-group"><label class="form-label">Πόλη</label><input name="city" class="form-control" value="<?=h($es['city']??'')?>"></div>
      <div class="form-group"><label class="form-label">Τηλέφωνο</label><input name="phone" class="form-control" value="<?=h($es['phone']??'')?>"></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?=h($es['email']??'')?>"></div>
      <div class="form-group"><label class="form-label">IBAN</label><input name="iban" class="form-control" value="<?=h($es['iban']??'')?>"></div>
      <div class="form-group"><label class="form-label">Διεύθυνση</label><input name="address" class="form-control" value="<?=h($es['address']??'')?>"></div>
    </div>
  </div>
  <div class="mb-3"><div class="text-muted text-sm mb-2">Πλάνο & Κατάσταση</div>
    <div class="form-row col-3">
      <div class="form-group"><label class="form-label">Πλάνο</label><select name="plan_id" class="form-control"><?php foreach($plans as $p):?><option value="<?=$p['id']?>" <?=($es['plan_id']??1)==$p['id']?'selected':''?>><?=h($p['name'])?></option><?php endforeach;?></select></div>
      <div class="form-group"><label class="form-label">Κατάσταση</label><select name="plan_status" class="form-control"><?php foreach(['active'=>'Ενεργό','trial'=>'Δοκιμαστικό','expired'=>'Έληξε','suspended'=>'Ανάκληση'] as $v=>$l):?><option value="<?=$v?>" <?=($es['plan_status']??'trial')===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></div>
      <div class="form-group"><label class="form-label">Λήξη Πλάνου</label><input type="date" name="plan_expires" class="form-control" value="<?=h($es['plan_expires']??'')?>"></div>
      <div class="form-group"><label class="form-label">Trial Λήξη</label><input type="date" name="trial_ends" class="form-control" value="<?=h($es['trial_ends']??'')?>"></div>
      <div class="form-group"><label class="form-label">Ενεργό</label><select name="active" class="form-control"><option value="1" <?=($es['active']??1)?'selected':''?>>Ναι</option><option value="0" <?=!($es['active']??1)?'selected':''?>>Όχι</option></select></div>
    </div>
  </div>
  <div class="mb-3"><div class="text-muted text-sm mb-2"><i class="fa-solid fa-mobile-screen-button" style="color:#a855f7"></i> SMS Addon</div>
    <div class="form-row col-3">
      <div class="form-group"><label class="form-label">Ενεργό</label><label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin-top:.3rem"><input type="checkbox" name="sms_addon" value="1" <?=!empty($es['sms_addon'])?'checked':''?>> Ενεργοποιημένο</label></div>
      <div class="form-group"><label class="form-label">Λήξη SMS Addon</label><input type="date" name="sms_addon_expires" class="form-control" value="<?=h($es['sms_addon_expires']??'')?>"></div>
    </div>
  </div>
  <div class="mb-3"><div class="text-muted text-sm mb-2"><i class="fa-solid fa-note-sticky" style="color:#f0a500"></i> Εσωτερική Σημείωση (ορατή μόνο στον Admin)</div>
    <textarea name="admin_note" class="form-control" rows="3" placeholder="π.χ. Πλήρωσε με κατάθεση, συμφωνία προσφοράς, επικοινωνία..."><?=h($es['admin_note']??'')?></textarea>
  </div>
  <?php if(!$es):?>
  <div class="mb-3"><div class="text-muted text-sm mb-2">Στοιχεία Ιδιοκτήτη</div>
    <div class="form-row col-3">
      <div class="form-group"><label class="form-label">Όνομα</label><input name="owner_name" class="form-control"></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" name="owner_email" class="form-control"></div>
      <div class="form-group"><label class="form-label">Κωδικός</label><input type="password" name="owner_password" class="form-control" placeholder="Αφήστε κενό → changeme123"></div>
    </div>
  </div>
  <?php endif;?>
  <div class="d-flex gap-sm">
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
    <a href="<?=APP_URL?>/admin/schools.php" class="btn btn-secondary">Ακύρωση</a>
  </div>
</form>
</div>

<?php else: ?>
<div class="d-flex jc-between ai-center mb-3 flex-wrap gap-sm">
  <form method="GET" class="d-flex gap-sm flex-wrap ai-end">
    <div class="form-group" style="margin-bottom:0;min-width:200px"><div class="search-bar"><span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span><input name="q" value="<?=h($search)?>" placeholder="Όνομα, email, πόλη..."></div></div>
    <select name="status" class="form-control" style="min-width:140px">
      <option value="">Όλες</option>
      <option value="active" <?=$filterStatus==='active'?'selected':''?>>Ενεργές</option>
      <option value="trial" <?=$filterStatus==='trial'?'selected':''?>>Δοκιμή</option>
      <option value="expired" <?=$filterStatus==='expired'?'selected':''?>>Ληγμένες</option>
      <option value="suspended" <?=$filterStatus==='suspended'?'selected':''?>>Αναστολή</option>
    </select>
    <select name="plan_id" class="form-control" style="min-width:120px">
      <option value="">Όλα πλάνα</option>
      <?php foreach($plans as $p):?><option value="<?=$p['id']?>" <?=$filterPlan==$p['id']?'selected':''?>><?=h($p['name'])?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i></button>
    <a href="?churn=1" class="btn btn-ghost btn-sm" title="Ανενεργές 30+ ημέρες" style="<?=$filterChurn?'background:rgba(230,57,70,.15);color:var(--danger)':''?>">
      <i class="fa-solid fa-user-slash"></i> Churn
      <?php if($churnCount):?><span class="badge badge-overdue" style="margin-left:3px"><?=$churnCount?></span><?php endif;?>
    </a>
    <a href="<?=APP_URL?>/admin/schools.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i></a>
  </form>
  <div class="d-flex gap-sm">
    <a href="?export_csv=1" class="btn btn-ghost btn-sm" title="Εξαγωγή CSV"><i class="fa-solid fa-file-csv" style="color:#2dc653"></i> CSV</a>
    <a href="?add=1" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Νέα Σχολή</a>
  </div>
</div>

<div class="card p-0">
  <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border)" class="text-sm text-muted">
    <?=$totalRows?> σχολές<?=$filterChurn?' — <strong style="color:#e63946">Churn mode</strong>':''?>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Σχολή</th><th>Πλάνο</th><th>Κατάσταση</th><th>Αθλητές</th><th>Τελ. Σύνδεση</th><th>Λήξη</th><th>Εγγραφή</th><th>Ενέργειες</th></tr></thead>
    <tbody>
    <?php foreach($schools as $s):
      $isChurn=$s['last_login']&&strtotime($s['last_login'])<strtotime('-30 days');
      $hasNote=!empty($s['admin_note']);
    ?>
    <tr style="<?=!$s['active']?'opacity:.5':''?>;<?=$isChurn?'background:rgba(230,57,70,.03)':''?>">
      <td><div class="fw-600"><?=h($s['name'])?><?php if($hasNote):?><span class="note-dot" title="Υπάρχει σημείωση"></span><?php endif;?></div><div class="text-xs text-muted"><?=h($s['email']??'')?><?=$s['city']?' · '.h($s['city']):''?></div></td>
      <td><?=planBadge($s['plan_slug'])?><?php if(!empty($s['sms_addon'])):?><span class="badge" style="background:rgba(168,85,247,.15);color:#a855f7;margin-left:3px;font-size:.6rem">SMS</span><?php endif;?></td>
      <td><span class="badge <?=match($s['plan_status']){'active'=>'badge-paid','trial'=>'badge-pending','expired'=>'badge-overdue','suspended'=>'badge-overdue',default=>'badge-basic'}?>"><?=['active'=>'Ενεργή','trial'=>'Δοκιμή','expired'=>'Έληξε','suspended'=>'Αναστολή'][$s['plan_status']]?></span></td>
      <td><?=$s['athlete_count']?></td>
      <td><span class="text-xs <?=$isChurn?'':'text-muted'?>" style="<?=$isChurn?'color:#e63946':''?>"><?=$s['last_login']?($isChurn?'⚠ ':''). formatDate(substr($s['last_login'],0,10)):'<span class="text-muted">Ποτέ</span>'?></span></td>
      <td class="text-xs"><?=$s['plan_expires']?formatDate($s['plan_expires']):($s['trial_ends']?'Trial: '.formatDate($s['trial_ends']):'—')?></td>
      <td class="text-xs"><?=formatDate(substr($s['created_at'],0,10))?></td>
      <td><div class="quick-actions">
        <a href="?edit=<?=$s['id']?>" class="btn btn-ghost btn-sm" title="Επεξεργασία"><i class="fa-solid fa-pen-to-square"></i></a>
        <a href="<?= APP_URL ?>/admin/impersonate.php?school=<?=$s['id']?>" class="btn btn-ghost btn-sm" title="Impersonate με OTP (recommended)"><i class="fa-solid fa-shield-halved"></i></a>
        <a href="?impersonate=<?=$s['id']?>" class="btn btn-ghost btn-sm" title="Instant impersonate (legacy)" onclick="return confirm('Login ως <?=h(addslashes($s['name']))?>;')"><i class="fa-solid fa-user-secret"></i></a>
        <button type="button" class="btn btn-ghost btn-sm" title="Παράταση Trial" onclick="openExtendTrial(<?=$s['id']?>,'<?=h(addslashes($s['name']))?>','<?=h($s['trial_ends']??'')?>')"><i class="fa-solid fa-clock-rotate-left" style="color:#f0a500"></i></button>
        <button type="button" class="btn btn-ghost btn-sm" title="Χειροκίνητο Πλάνο" onclick="openManualPlan(<?=$s['id']?>,'<?=h(addslashes($s['name']))?>',<?=$s['plan_id']?>,'<?=h($s['plan_expires']??'')?>')"><i class="fa-solid fa-wand-magic-sparkles" style="color:#3b82f6"></i></button>
        <button type="button" class="btn btn-ghost btn-sm" title="Σημείωση Admin" onclick="openNote(<?=$s['id']?>,'<?=h(addslashes($s['name']))?>',<?=json_encode($s['admin_note']??'')?>)"><i class="fa-solid fa-note-sticky" style="color:<?=$hasNote?'#f0a500':'var(--muted)'?>"></i></button>
        <form method="POST" style="display:inline"><input type="hidden" name="_action" value="toggle_school"><input type="hidden" name="csrf_token" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=$s['id']?>"><button type="submit" class="btn btn-ghost btn-sm" title="<?=$s['active']?'Απενεργοποίηση':'Ενεργοποίηση'?>"><?=$s['active']?'<i class="fa-solid fa-circle-pause" style="color:var(--danger)"></i>':'<i class="fa-solid fa-circle-play" style="color:var(--success)"></i>'?></button></form>
        <form method="POST" style="display:inline" onsubmit="return confirm('ΠΡΟΣΟΧΗ: Θα διαγραφούν ΟΛΑ τα δεδομένα. Συνέχεια;')"><input type="hidden" name="_action" value="delete_school"><input type="hidden" name="csrf_token" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=$s['id']?>"><button type="submit" class="btn btn-ghost btn-sm"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button></form>
      </div></td>
    </tr>
    <?php endforeach;?>
    <?php if(empty($schools)):?><tr><td colspan="8" class="text-center text-muted" style="padding:2rem">Δεν βρέθηκαν σχολές</td></tr><?php endif;?>
    </tbody>
  </table></div>
</div>
<?php if ($totalPages > 1): ?>
<div class="pagination mt-2" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.35rem 0">
  <form method="GET" style="display:inline-flex;align-items:center;margin-right:.5rem">
    <?php foreach(array_filter($_GET, fn($k)=>$k!=='per_page'&&$k!=='page', ARRAY_FILTER_USE_KEY) as $gk=>$gv): ?>
    <input type="hidden" name="<?= h($gk) ?>" value="<?= h($gv) ?>">
    <?php endforeach; ?>
    <select name="per_page" class="pg-size-select" onchange="this.form.submit()" style="font-size:.8rem;padding:.28rem .5rem;border-radius:7px;border:1px solid var(--border,#1e2536);background:var(--card,#111827);color:var(--text,#e2e8f0);cursor:pointer">
      <?php foreach([10,25,50,100] as $n): ?>
      <option value="<?= $n ?>"<?= $limit==$n?' selected':'' ?>><?= $n ?> / σελίδα</option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php if($page>1):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>" class="page-btn" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a><?php endif;?>
  <?php if($page>1):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="page-btn" title="Προηγούμενη"><i class="fa-solid fa-chevron-left"></i></a><?php endif;?>
  <?php if($page>3):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>" class="page-btn">1</a><?php if($page>4):?><span class="page-btn" style="pointer-events:none">…</span><?php endif;?><?php endif;?>
  <?php for($pp=max(1,$page-2);$pp<=min($totalPages,$page+2);$pp++):?>
  <a href="?<?= http_build_query(array_merge($_GET,['page'=>$pp])) ?>" class="page-btn <?=$pp==$page?'active':''?>"><?=$pp?></a>
  <?php endfor;?>
  <?php if($page<$totalPages-2):?><?php if($page<$totalPages-3):?><span class="page-btn" style="pointer-events:none">…</span><?php endif;?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>" class="page-btn"><?=$totalPages?></a><?php endif;?>
  <?php if($page<$totalPages):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="page-btn" title="Επόμενη"><i class="fa-solid fa-chevron-right"></i></a><?php endif;?>
  <?php if($page<$totalPages):?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>" class="page-btn" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a><?php endif;?>
  <span style="font-size:.8rem;color:var(--muted);margin-left:.4rem"><?=$page?> / <?=$totalPages?></span>
</div>
<?php endif; ?>
<?php endif;?>
</div></div></div>

<!-- MODAL: Παράταση Trial -->
<div class="modal-backdrop" id="modalExtend">
  <div class="modal-box">
    <div class="modal-title"><i class="fa-solid fa-clock-rotate-left" style="color:#f0a500"></i> Παράταση Trial</div>
    <div class="text-muted text-sm mb-3" id="extendSchoolName"></div>
    <form method="POST">
      <input type="hidden" name="_action" value="extend_trial">
      <input type="hidden" name="csrf_token" value="<?=csrf()?>">
      <input type="hidden" name="id" id="extendId">
      <div class="form-group mb-2"><label class="form-label">Τρέχουσα λήξη trial</label><div class="text-muted text-sm" id="extendCurrentEnd">—</div></div>
      <div class="form-group mb-3"><label class="form-label">Παράταση κατά (ημέρες)</label><input type="number" name="days" class="form-control" value="14" min="1" max="365" required><div class="text-muted text-xs mt-1">Προστίθενται από σήμερα ή την τρέχουσα λήξη</div></div>
      <div class="d-flex gap-sm">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Παράταση</button>
        <button type="button" class="btn btn-secondary" onclick="closeModal('modalExtend')">Ακύρωση</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Χειροκίνητο Πλάνο -->
<div class="modal-backdrop" id="modalPlan">
  <div class="modal-box">
    <div class="modal-title"><i class="fa-solid fa-wand-magic-sparkles" style="color:#3b82f6"></i> Χειροκίνητη Αλλαγή Πλάνου</div>
    <div class="text-muted text-sm mb-3" id="planSchoolName"></div>
    <form method="POST">
      <input type="hidden" name="_action" value="manual_plan">
      <input type="hidden" name="csrf_token" value="<?=csrf()?>">
      <input type="hidden" name="id" id="planId">
      <div class="form-row col-2" style="margin-bottom:.75rem">
        <div class="form-group"><label class="form-label">Πλάνο</label><select name="plan_id" class="form-control" id="planSelect"><?php foreach($plans as $p):?><option value="<?=$p['id']?>"><?=h($p['name'])?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Λήξη *</label><input type="date" name="plan_expires" class="form-control" id="planExpires" required></div>
        <div class="form-group"><label class="form-label">Περίοδος</label><select name="period" class="form-control"><option value="monthly">Μηνιαία</option><option value="annual">Ετήσια</option></select></div>
        <div class="form-group"><label class="form-label">Ποσό (€)</label><input type="number" name="amount" class="form-control" step="0.01" placeholder="π.χ. 12.00"></div>
        <div class="form-group"><label class="form-label">Τρόπος πληρωμής</label><select name="payment_method" class="form-control"><option value="bank">Κατάθεση</option><option value="cash">Μετρητά</option><option value="card">Κάρτα</option></select></div>
        <div class="form-group"><label class="form-label">Σημείωση</label><input type="text" name="payment_note" class="form-control" placeholder="π.χ. Κατάθεση Eurobank"></div>
      </div>
      <div class="d-flex gap-sm">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Εφαρμογή</button>
        <button type="button" class="btn btn-secondary" onclick="closeModal('modalPlan')">Ακύρωση</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Σημείωση -->
<div class="modal-backdrop" id="modalNote">
  <div class="modal-box">
    <div class="modal-title"><i class="fa-solid fa-note-sticky" style="color:#f0a500"></i> Εσωτερική Σημείωση</div>
    <div class="text-muted text-sm mb-3" id="noteSchoolName"></div>
    <form method="POST">
      <input type="hidden" name="_action" value="save_note">
      <input type="hidden" name="csrf_token" value="<?=csrf()?>">
      <input type="hidden" name="id" id="noteId">
      <div class="form-group mb-3"><label class="form-label">Σημείωση (ορατή μόνο σε σας)</label><textarea name="admin_note" id="noteText" class="form-control" rows="4" placeholder="π.χ. Πλήρωσε με κατάθεση, θέλει τιμολόγιο..."></textarea></div>
      <div class="d-flex gap-sm">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
        <button type="button" class="btn btn-secondary" onclick="closeModal('modalNote')">Ακύρωση</button>
      </div>
    </form>
  </div>
</div>

<script>
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-backdrop').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('open')})});
function openExtendTrial(id,name,trialEnd){document.getElementById('extendId').value=id;document.getElementById('extendSchoolName').textContent=name;document.getElementById('extendCurrentEnd').textContent=trialEnd||'Δεν έχει οριστεί';document.getElementById('modalExtend').classList.add('open')}
function openManualPlan(id,name,planId,expires){document.getElementById('planId').value=id;document.getElementById('planSchoolName').textContent=name;document.getElementById('planExpires').value=expires||'';var sel=document.getElementById('planSelect');for(var i=0;i<sel.options.length;i++){if(parseInt(sel.options[i].value)===planId){sel.selectedIndex=i;break}}if(!expires){var d=new Date();d.setMonth(d.getMonth()+1);document.getElementById('planExpires').value=d.toISOString().split('T')[0]}document.getElementById('modalPlan').classList.add('open')}
function openNote(id,name,note){document.getElementById('noteId').value=id;document.getElementById('noteSchoolName').textContent=name;document.getElementById('noteText').value=note||'';document.getElementById('modalNote').classList.add('open')}
</script>
</body></html>