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
 * admin/broadcast.php — Μαζική Αποστολή (Super Admin)
 * ============================================================
 * PURPOSE:
 *   Μαζική αποστολή Email ή SMS σε owners σχολών.
 *   Υποστηρίζει: φίλτρο πλάνου/κατάστασης, dry-run preview.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf()
 *   ✓ SMS: max 160 chars (mb_strlen)
 *   ✓ Email body: nl2br + htmlspecialchars (αποτρέπει HTML injection)
 *   ✓ Dry-run: preview χωρίς αποστολή
 *   ✓ Audit log μετά από κάθε broadcast
 *   ✓ Prepared statements για recipient query
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── POST: Αποστολή Broadcast ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Επαλήθευση CSRF token — αποτρέπει Cross-Site Request Forgery ──
    verifyCsrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'send_broadcast') {
        $subject   = trim($_POST['subject'] ?? '');
        $body      = trim($_POST['body'] ?? '');
        $planFilter= trim($_POST['target_plan'] ?? '');    // Φίλτρο πλάνου (basic/pro/κενό=όλα)
        $statFilter= trim($_POST['target_status'] ?? '');  // Φίλτρο κατάστασης (active/trial/expired)
        $dryRun    = !empty($_POST['dry_run']);             // Preview mode — δεν στέλνει πραγματικά

        if (!$subject || !$body) { flash('Θέμα και περιεχόμενο είναι υποχρεωτικά.','danger'); redirect(APP_URL.'/admin/broadcast.php'); }

        // ── Εύρεση παραληπτών (owner ανά ενεργή σχολή) ──────────────────────
        $where=['s.active=1',"u.role='owner'"]; $params=[];
        if ($planFilter) { $where[]="p.slug=?"; $params[]=$planFilter; }
        if ($statFilter) { $where[]="s.plan_status=?"; $params[]=$statFilter; }

        $stmt=$db->prepare("SELECT u.email,u.name,s.name as school_name,s.id as school_id FROM schools s JOIN plans p ON p.id=s.plan_id JOIN users u ON u.school_id=s.id AND u.role='owner' WHERE ".implode(' AND ',$where)." ORDER BY s.name");
        $stmt->execute($params);
        $recipients=$stmt->fetchAll();

        if ($dryRun) {
            // Αποθήκευση preview σε session και redirect για προβολή
            $_SESSION['bc_preview']=['subject'=>$subject,'body'=>$body,'recipients'=>$recipients];
            flash('Preview: '.count($recipients).' παραλήπτες.','success');
            redirect(APP_URL.'/admin/broadcast.php?preview=1');
        }

        // ── Πραγματική Αποστολή ──────────────────────────────────────────────
        $sent=0; $failed=0;
        $htmlBody=nl2br(htmlspecialchars($body)); // Μετατροπή newlines → <br>
        $logoUrl = rtrim(APP_URL, '/') . '/assets/img/logo-tr.png';
        $year    = date('Y');
        // HTML template με brand styling MAster
        $tpl='<!DOCTYPE html><html lang="el"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:28px 32px 24px;text-align:center;border-bottom:2px solid #2a1a50">
          <img src="' . $logoUrl . '" alt="MAster" style="height:64px;width:auto;max-width:200px;object-fit:contain;display:block;margin:0 auto 10px">
          <p style="margin:0;font-size:.78rem;color:#8892b0">Ανακοίνωση από το MAster</p>
        </td>
      </tr>
      <tr>
        <td style="padding:28px 32px 8px">
          <h2 style="margin:0 0 16px;font-size:1.15rem;color:#f0f2ff;font-weight:800">{SUBJECT}</h2>
          <div style="color:#d0d8f0;font-size:.96rem;line-height:1.85">{BODY}</div>
        </td>
      </tr>
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr>
        <td style="padding:16px 32px 24px;text-align:center;font-size:.74rem;color:#4a5270;line-height:1.7">
          &copy; ' . $year . ' MAster &nbsp;&middot;&nbsp; Αυτόματο μήνυμα.
        </td>
      </tr>
    </table>
  </td></tr>
</table></body></html>';

        // Προετοιμασμένο statement για log — αποφεύγει N prepare calls
        $logStmt=$db->prepare("INSERT INTO broadcast_log (subject,recipient_email,school_id,status) VALUES (?,?,?,?)");
        foreach ($recipients as $r) {
            // Αντικατάσταση placeholders με δεδομένα παραλήπτη
            $html=str_replace(['{SUBJECT}','{BODY}','{name}','{school}'],[$subject,$htmlBody,$r['name'],$r['school_name']],$tpl);
            $ok=sendEmail($r['email'],$subject,$html);
            $ok ? $sent++ : $failed++;
            $logStmt->execute([$subject,$r['email'],$r['school_id'],$ok?'sent':'failed']);
        }
        auditLog('broadcast_sent','broadcast',0,"Sent:$sent Failed:$failed Subject:$subject");
        flash("Αποστολή ολοκληρώθηκε! ✅ $sent επιτυχείς".($failed?" · ❌ $failed αποτυχίες":''), $failed===0?'success':'danger');
        redirect(APP_URL.'/admin/broadcast.php');
    }

    // ── Save Newsletter Template ──────────────────────────────────────────
    if ($action === 'save_template') {
        $tName = trim($_POST['template_name'] ?? '');
        $tSubj = trim($_POST['tpl_subject']  ?? '');
        $tBody = trim($_POST['tpl_body']     ?? '');
        if ($tName && $tSubj && $tBody) {
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS newsletter_templates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    subject VARCHAR(500) NOT NULL,
                    body TEXT NOT NULL,
                    created_by INT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                $db->prepare("INSERT INTO newsletter_templates (name, subject, body, created_by) VALUES (?,?,?,?)")
                   ->execute([$tName, $tSubj, $tBody, $_SESSION['user_id'] ?? null]);
                flash("✅ Πρότυπο «$tName» αποθηκεύτηκε.");
            } catch (Exception $e) {
                flash('Σφάλμα αποθήκευσης προτύπου.', 'danger');
            }
        } else {
            flash('Συμπληρώστε όλα τα πεδία του προτύπου.', 'danger');
        }
        redirect(APP_URL.'/admin/broadcast.php');
    }

    // ── Delete Newsletter Template ────────────────────────────────────────
    if ($action === 'delete_template') {
        $tId = (int)($_POST['template_id'] ?? 0);
        if ($tId) {
            try {
                $db->prepare("DELETE FROM newsletter_templates WHERE id=?")->execute([$tId]);
                flash('Πρότυπο διαγράφηκε.');
            } catch (Exception $e) {}
        }
        redirect(APP_URL.'/admin/broadcast.php');
    }

    // ── SMS Broadcast ─────────────────────────────────────────────────────
    if ($action === 'send_sms_broadcast') {
        $smsBody    = trim($_POST['sms_body'] ?? '');
        $planFilter = trim($_POST['target_plan'] ?? '');
        $statFilter = trim($_POST['target_status'] ?? '');
        $dryRun     = !empty($_POST['dry_run']);
        if (!$smsBody) { flash('Το μήνυμα SMS είναι υποχρεωτικό.','danger'); redirect(APP_URL.'/admin/broadcast.php'); }
        if (mb_strlen($smsBody) > 160) { flash('Max 160 χαρακτήρες.','danger'); redirect(APP_URL.'/admin/broadcast.php'); }
        $where=['s.active=1',"u.role='owner'",'(s.phone IS NOT NULL AND s.phone != "")'];
        $params=[];
        if ($planFilter) { $where[]="p.slug=?"; $params[]=$planFilter; }
        if ($statFilter) { $where[]="s.plan_status=?"; $params[]=$statFilter; }
        $stmt=$db->prepare("SELECT u.name,s.name as school_name,s.phone,s.id as school_id FROM schools s JOIN plans p ON p.id=s.plan_id JOIN users u ON u.school_id=s.id AND u.role='owner' WHERE ".implode(' AND ',$where)." ORDER BY s.name");
        $stmt->execute($params); $recipients=$stmt->fetchAll();
        if ($dryRun) { flash('SMS Preview: '.count($recipients).' παραλήπτες με τηλέφωνο.','success'); redirect(APP_URL.'/admin/broadcast.php'); }
        require_once __DIR__.'/../includes/mailer.php';
        $sent=0; $failed=0;
        foreach ($recipients as $r) {
            $msg=str_replace(['{name}','{school}'],[$r['name'],$r['school_name']],$smsBody);
            $ok=function_exists('sendSms')?sendSms($r['phone'],$msg,$smsErr):false;
            $ok?$sent++:$failed++;
        }
        auditLog('sms_broadcast','broadcast',0,"Sent:$sent Failed:$failed");
        flash("SMS αποστολή! ✅ $sent επιτυχείς".($failed?" · ❌ $failed αποτυχίες":''),$failed===0?'success':'danger');
        redirect(APP_URL.'/admin/broadcast.php');
    }
}

// ── Στατιστικά: αριθμός owners ανά κατηγορία ────────────────────────────────
$totalOwners = $db->query("SELECT COUNT(DISTINCT u.school_id) FROM users u JOIN schools s ON s.id=u.school_id WHERE u.role='owner' AND s.active=1")->fetchColumn();
$proOwners   = $db->query("SELECT COUNT(DISTINCT u.school_id) FROM users u JOIN schools s ON s.id=u.school_id JOIN plans p ON p.id=s.plan_id WHERE u.role='owner' AND s.active=1 AND p.slug='pro'")->fetchColumn();
$trialOwners = $db->query("SELECT COUNT(DISTINCT u.school_id) FROM users u JOIN schools s ON s.id=u.school_id WHERE u.role='owner' AND s.active=1 AND s.plan_status='trial'")->fetchColumn();

// ── Preview από session (αν υπάρχει μετά από dry run) ──────────────────────
$preview=null;
if (isset($_GET['preview']) && !empty($_SESSION['bc_preview'])) {
    $preview=$_SESSION['bc_preview']; unset($_SESSION['bc_preview']); // Single-use
}

// ── Ιστορικό αποστολών — paginated + searchable ─────────────────────────────
$histSearch = trim($_GET['hist_q'] ?? '');
$histPage   = max(1, (int)($_GET['hist_page'] ?? 1));
$histLimit  = in_array((int)($_GET['hist_per'] ?? 10), [10,25,50,100]) ? (int)($_GET['hist_per'] ?? 10) : 10;
$histWhere  = ['1=1']; $histParams = [];
if ($histSearch) { $histWhere[] = "subject LIKE ?"; $histParams[] = "%$histSearch%"; }
$histWhereStr = implode(' AND ', $histWhere);
$history=[];
$histTotal = 0; $histTotalPages = 1;
try {
    $hc = $db->prepare("SELECT COUNT(DISTINCT CONCAT(subject, DATE(sent_at))) FROM broadcast_log WHERE $histWhereStr");
    $hc->execute($histParams); $histTotal = (int)$hc->fetchColumn();
    $histTotalPages = max(1, (int)ceil($histTotal / max(1, $histLimit)));
    $histOffset = ($histPage - 1) * $histLimit;
    $hStmt = $db->prepare("SELECT subject,COUNT(*) as total,SUM(status='sent') as sent,SUM(status='failed') as failed,MAX(sent_at) as sent_at FROM broadcast_log WHERE $histWhereStr GROUP BY subject,DATE(sent_at) ORDER BY MAX(sent_at) DESC LIMIT $histLimit OFFSET $histOffset");
    $hStmt->execute($histParams);
    $history = $hStmt->fetchAll();
} catch(Exception $e){ error_log('[broadcast.php] history query error: ' . $e->getMessage()); }

$plans=$db->query("SELECT * FROM plans WHERE active=1")->fetchAll(); // Για dropdown πλάνου

// ── Newsletter Templates ──────────────────────────────────────────────────
$templates = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS newsletter_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        subject VARCHAR(500) NOT NULL,
        body TEXT NOT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $templates = $db->query("SELECT id, name, subject, body, LEFT(body,120) as body_preview, created_at FROM newsletter_templates ORDER BY updated_at DESC LIMIT 20")->fetchAll();
} catch (Exception $e) {}

// ── Quarterly stats last run ──────────────────────────────────────────────
$lastQuarterlySent = null;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quarterly_stats_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL,
        quarter VARCHAR(7) NOT NULL,
        status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
        sent_at DATETIME NOT NULL,
        INDEX idx_school (school_id),
        INDEX idx_quarter (quarter)
    )");
    $lastQuarterlySent = $db->query("SELECT MAX(sent_at) FROM quarterly_stats_log")->fetchColumn();
} catch (Exception $e) {}

renderHead('Broadcast Email');
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
.stat-card{border-radius:18px;padding:1.1rem 1rem;display:flex;flex-direction:column;gap:.4rem}
.stat-icon{width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:.25rem}
.icon-blue{background:rgba(59,130,246,.15);color:#3b82f6}
.icon-gold{background:rgba(240,165,0,.15);color:var(--gold,#f0a500)}
.icon-green{background:rgba(45,198,83,.15);color:var(--green,#2dc653)}
.stat-val{font-size:clamp(1.4rem,5vw,2rem)!important;font-weight:800;line-height:1}
.stat-lbl{font-size:clamp(.82rem,3vw,.92rem)!important;color:var(--muted,#8892b0);font-weight:600}
.card{border-radius:18px;overflow:hidden;margin-bottom:1.1rem}
.card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.95rem 1.2rem;border-bottom:1px solid var(--border,#1e2536)}
.card-title{font-size:clamp(1rem,3.5vw,1.12rem)!important;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0}
.card-body{padding:1.3rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.form-grid .span-2{grid-column:span 2}
.form-group{display:flex;flex-direction:column;gap:.38rem}
.form-label{font-size:clamp(.9rem,3vw,.98rem)!important;font-weight:700;color:var(--text,#e2e8f0);display:flex;align-items:center;gap:.4rem}
.form-control{font-size:clamp(.92rem,3vw,1rem)!important;min-height:48px;padding:.65rem .95rem;border-radius:11px!important;transition:border-color .2s,box-shadow .2s;width:100%}
.form-control:focus{outline:none;border-color:var(--red,#e63946)!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
textarea.form-control{min-height:180px;resize:vertical}
.form-hint{font-size:.8rem!important;color:var(--muted,#8892b0)}
.btn{min-height:46px;font-size:clamp(.9rem,3vw,1rem)!important;font-weight:700!important;display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border-radius:11px;transition:all .18s;text-decoration:none;padding:.55rem 1.1rem;cursor:pointer;border:none;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-sm{min-height:38px;padding:.38rem .85rem;font-size:.88rem!important;border-radius:9px}
.alert{border-radius:12px;padding:.85rem 1rem;font-size:clamp(.88rem,3vw,.96rem)!important;line-height:1.6;display:flex;align-items:flex-start;gap:.65rem;margin-bottom:1rem}
.alert-warning{background:rgba(240,165,0,.12);color:#fbbf24;border:1px solid rgba(240,165,0,.2)}
.alert-info{background:rgba(59,130,246,.12);color:#93c5fd;border:1px solid rgba(59,130,246,.2)}
.alert i{flex-shrink:0;margin-top:.15rem}
.preview-list{background:rgba(255,255,255,.03);border:1px solid var(--border,#1e2536);border-radius:12px;max-height:260px;overflow-y:auto;padding:.5rem .75rem}
.preview-item{display:flex;align-items:center;gap:.5rem;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:clamp(.86rem,3vw,.94rem)!important}
.preview-item:last-child{border-bottom:none}
.table-wrap{overflow-x:auto}
.table-wrap table{width:100%;border-collapse:collapse}
.table-wrap th{font-size:clamp(.76rem,2.5vw,.84rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;padding:.6rem .9rem}
.table-wrap td{font-size:clamp(.9rem,3vw,.98rem)!important;padding:.75rem .9rem;vertical-align:middle}
.table-wrap tbody tr:hover{background:rgba(255,255,255,.03)}
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .65rem;border-radius:999px;font-size:clamp(.78rem,3vw,.86rem)!important;font-weight:700}
.char-count{font-size:.8rem!important;color:var(--muted,#8892b0);margin-top:.25rem}
@media(max-width:900px){.page-body{padding:1rem!important}.stats-row{gap:.75rem}}
@media(max-width:700px){.page-body{padding:.85rem!important}.form-grid{grid-template-columns:1fr!important}.form-grid .span-2{grid-column:span 1!important}}
@media(max-width:480px){.page-body{padding:.65rem!important}.card{border-radius:14px}.stats-row{grid-template-columns:1fr 1fr}.stats-row .stat-card:last-child{grid-column:span 2}}
</style>
<body>
<div class="app-layout">
<?php renderSidebar('admin_broadcast'); ?>
<div id="dm-overlay"></div>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-bullhorn"></i> Broadcast'); ?>
<div class="page-body">

<div class="page-header anim-1">
    <h2><i class="fa-solid fa-bullhorn" style="color:var(--red,#e63946)"></i> Broadcast Email</h2>
</div>

<div class="stats-row anim-2">
    <div class="stat-card card">
        <div class="stat-icon icon-blue"><i class="fa-solid fa-school"></i></div>
        <div class="stat-val"><?= $totalOwners ?></div>
        <div class="stat-lbl">Σύνολο Owners</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-star"></i></div>
        <div class="stat-val"><?= $proOwners ?></div>
        <div class="stat-lbl">Pro Owners</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-green"><i class="fa-regular fa-clock"></i></div>
        <div class="stat-val"><?= $trialOwners ?></div>
        <div class="stat-lbl">Trial Owners</div>
    </div>
</div>

<?php if($preview): ?>
<div class="card anim-2">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-eye" style="color:#3b82f6"></i> Preview Broadcast</div>
        <a href="<?= APP_URL ?>/admin/broadcast.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i> Κλείσιμο</a>
    </div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:1rem">
            <i class="fa-solid fa-circle-info"></i>
            <div><strong><?= count($preview['recipients']) ?> παραλήπτες</strong> θα λάβουν αυτό το email.</div>
        </div>
        <div style="background:rgba(255,255,255,.04);border:1px solid var(--border,#1e2536);border-radius:12px;padding:1rem;margin-bottom:1rem">
            <div style="font-weight:800;font-size:1.05rem;margin-bottom:.5rem"><?= h($preview['subject']) ?></div>
            <div style="color:var(--muted,#8892b0);white-space:pre-wrap;font-size:.95rem"><?= h($preview['body']) ?></div>
        </div>
        <div style="font-size:.9rem;font-weight:700;margin-bottom:.5rem">Παραλήπτες:</div>
        <div class="preview-list">
            <?php foreach($preview['recipients'] as $r): ?>
            <div class="preview-item">
                <i class="fa-solid fa-school" style="color:var(--muted);font-size:.85rem;flex-shrink:0"></i>
                <strong><?= h($r['school_name']) ?></strong>
                <span style="color:var(--muted,#8892b0)">— <?= h($r['email']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:6px;margin-bottom:1rem">
  <button type="button" class="btn btn-primary btn-sm" id="tabEmailBtn" onclick="switchBC('email')"><i class="fa-solid fa-envelope"></i> Email Broadcast</button>
  <button type="button" class="btn btn-ghost btn-sm" id="tabSmsBtn" onclick="switchBC('sms')"><i class="fa-solid fa-mobile-screen-button"></i> SMS Broadcast</button>
</div>

<!-- Email Compose -->
<div id="bcEmail">
<div class="card anim-3">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-envelope" style="color:var(--gold,#f0a500)"></i> Email Broadcast</div>
    </div>
    <div class="card-body">
        <div class="alert alert-warning" style="margin-bottom:1.2rem">
            <i class="fa-solid fa-circle-info"></i>
            <div>Χρησιμοποιήστε <code style="background:rgba(255,255,255,.1);padding:.1rem .35rem;border-radius:4px">{name}</code> για όνομα χρήστη και <code style="background:rgba(255,255,255,.1);padding:.1rem .35rem;border-radius:4px">{school}</code> για όνομα σχολής.</div>
        </div>
        <form method="POST">
            <input type="hidden" name="_action" value="send_broadcast">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="form-grid" style="margin-bottom:1.1rem">
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-filter"></i> Φίλτρο Πλάνου</label>
                    <select name="target_plan" class="form-control">
                        <option value="">Όλα τα πλάνα</option>
                        <?php foreach($plans as $p): ?>
                        <option value="<?= h($p['slug']) ?>"><?= h($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-circle-half-stroke"></i> Φίλτρο Κατάστασης</label>
                    <select name="target_status" class="form-control">
                        <option value="">Όλες οι καταστάσεις</option>
                        <option value="active">Ενεργές</option>
                        <option value="trial">Δοκιμαστικές</option>
                        <option value="expired">Ληγμένες</option>
                    </select>
                </div>
                <div class="form-group span-2">
                    <label class="form-label"><i class="fa-solid fa-heading"></i> Θέμα Email</label>
                    <input name="subject" class="form-control" required placeholder="π.χ. Νέα λειτουργία στο MAster!">
                </div>
                <div class="form-group span-2">
                    <label class="form-label"><i class="fa-solid fa-align-left"></i> Περιεχόμενο</label>
                    <textarea name="body" id="bodyTxt" class="form-control" required
                        oninput="document.getElementById('cc').textContent=this.value.length+' χαρ.'"
                        placeholder="Γεια σου {name},&#10;&#10;Θέλαμε να σε ενημερώσουμε...&#10;&#10;Η ομάδα MAster"></textarea>
                    <div id="cc" class="char-count">0 χαρ.</div>
                </div>
            </div>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                <button type="submit" name="dry_run" value="1" class="btn btn-secondary">
                    <i class="fa-solid fa-eye"></i> Προεπισκόπηση Παραληπτών
                </button>
                <button type="submit" class="btn btn-primary" onclick="return confirm('Αποστολή broadcast σε ΟΛΑ τα επιλεγμένα emails;')">
                    <i class="fa-solid fa-paper-plane"></i> Αποστολή Broadcast
                </button>
            </div>
        </form>
    </div>
</div>
</div><!-- /bcEmail -->

<!-- SMS Compose -->
<div id="bcSms" style="display:none">
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-mobile-screen-button" style="color:#a855f7"></i> SMS Broadcast</div>
    </div>
    <div class="card-body">
        <div class="alert alert-warning" style="margin-bottom:1.2rem">
            <i class="fa-solid fa-circle-info"></i>
            <div>Μόνο σχολές με <strong>καταχωρημένο τηλέφωνο</strong>. Χρησιμοποιήστε <code>{name}</code> και <code>{school}</code>. Max <strong>160 χαρακτήρες</strong>.</div>
        </div>
        <form method="POST">
            <input type="hidden" name="_action" value="send_sms_broadcast">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="form-grid" style="margin-bottom:1.1rem">
                <div class="form-group">
                    <label class="form-label">Φίλτρο Πλάνου</label>
                    <select name="target_plan" class="form-control">
                        <option value="">Όλα</option>
                        <?php foreach($plans as $p): ?>
                        <option value="<?= h($p['slug']) ?>"><?= h($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Φίλτρο Κατάστασης</label>
                    <select name="target_status" class="form-control">
                        <option value="">Όλες</option>
                        <option value="active">Ενεργές</option>
                        <option value="trial">Δοκιμαστικές</option>
                    </select>
                </div>
                <div class="form-group span-2">
                    <label class="form-label">Μήνυμα SMS (max 250 χαρ.)</label>
                    <textarea name="sms_body" id="smsTxt" class="form-control" rows="4" maxlength="250" required
                        oninput="updateSmsAdmin()"
                        placeholder="Γεια {name}, νέα ενημέρωση από το MAster..."></textarea>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:.3rem">
                        <div id="smscc" style="font-size:.78rem;color:var(--muted,#8892b0);font-weight:700">0 / 250</div>
                        <div id="smsDots" style="display:none;font-size:.95rem;color:rgba(240,165,0,.8);font-weight:900">…</div>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                <button type="submit" name="dry_run" value="1" class="btn btn-secondary">
                    <i class="fa-solid fa-eye"></i> Preview Παραληπτών
                </button>
                <button type="submit" class="btn btn-primary" style="background:#a855f7;box-shadow:none" onclick="return confirm('Αποστολή SMS σε όλες τις επιλεγμένες σχολές;')">
                    <i class="fa-solid fa-paper-plane"></i> Αποστολή SMS
                </button>
            </div>
        </form>
    </div>
</div>
</div><!-- /bcSms -->

<!-- Newsletter Templates -->
<div class="card" style="margin-bottom:1.1rem">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-bookmark" style="color:#3b82f6"></i> Πρότυπα Newsletter</div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('newTplForm').style.display=document.getElementById('newTplForm').style.display==='none'?'block':'none'"><i class="fa-solid fa-plus"></i> Νέο Πρότυπο</button>
    </div>
    <!-- New template form -->
    <div id="newTplForm" style="display:none;border-bottom:1px solid var(--border)">
        <div class="card-body">
        <form method="POST">
            <input type="hidden" name="_action" value="save_template">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div class="form-grid" style="margin-bottom:1rem">
                <div class="form-group">
                    <label class="form-label">Όνομα Προτύπου</label>
                    <input name="template_name" class="form-control" required placeholder="π.χ. Ανακοίνωση Χειμώνα">
                </div>
                <div class="form-group">
                    <label class="form-label">Θέμα Email</label>
                    <input name="tpl_subject" class="form-control" required placeholder="Θέμα email">
                </div>
                <div class="form-group span-2">
                    <label class="form-label">Περιεχόμενο</label>
                    <textarea name="tpl_body" class="form-control" rows="5" required placeholder="Γεια σου {name},&#10;&#10;..."></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση Προτύπου</button>
        </form>
        </div>
    </div>
    <!-- Templates list -->
    <?php if(empty($templates)): ?>
    <div class="card-body" style="color:var(--muted);font-size:.9rem;text-align:center;padding:1.5rem">Δεν υπάρχουν αποθηκευμένα πρότυπα ακόμη.</div>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Όνομα</th><th>Θέμα</th><th>Προεπισκόπηση</th><th>Ημ/νία</th><th></th></tr></thead>
        <tbody>
        <?php foreach($templates as $t): ?>
        <tr>
            <td style="font-weight:700;white-space:nowrap"><?= h($t['name']) ?></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($t['subject']) ?></td>
            <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:.85rem"><?= h($t['body_preview']) ?>...</td>
            <td style="white-space:nowrap;color:var(--muted);font-size:.85rem"><?= date('d/m/Y', strtotime($t['created_at'])) ?></td>
            <td style="white-space:nowrap">
                <button type="button" class="btn btn-ghost btn-sm" title="Φόρτωση στο compose"
                    onclick="loadTemplate(<?= (int)$t['id'] ?>)">
                    <i class="fa-solid fa-upload"></i>
                </button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Διαγραφή προτύπου;')">
                    <input type="hidden" name="_action" value="delete_template">
                    <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red)"><i class="fa-solid fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- Quarterly Stats -->
<div class="card" style="margin-bottom:1.1rem">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-chart-line" style="color:#2dc653"></i> Τριμηνιαίες Αναφορές</div>
    </div>
    <div class="card-body">
        <p style="color:var(--muted);font-size:.9rem;margin-bottom:1rem">
            Αυτόματη αποστολή αναφορών στατιστικών κάθε 3 μήνες σε <strong>κάθε club owner</strong>.
            Περιλαμβάνει: αθλητές, έσοδα, email/SMS αποστολές, ποσοστό πληρωμών.
        </p>
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
            <div style="background:rgba(45,198,83,.08);border:1px solid rgba(45,198,83,.2);border-radius:10px;padding:.6rem 1.1rem;font-size:.88rem">
                <span style="color:var(--muted)">Τελευταία εκτέλεση:</span>
                <strong style="color:#2dc653;margin-left:.4rem"><?= $lastQuarterlySent ? date('d/m/Y H:i', strtotime($lastQuarterlySent)) : 'Ποτέ' ?></strong>
            </div>
            <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:.6rem 1.1rem;font-size:.88rem">
                <span style="color:var(--muted)">Cron Schedule:</span>
                <code style="color:#3b82f6;margin-left:.4rem">0 9 1 1,4,7,10 *</code>
            </div>
            <?php $cronOk = defined('CRON_SECRET') && CRON_SECRET !== ''; ?>
            <a href="<?= $cronOk ? APP_URL . '/cron/quarterly-stats.php?token=' . h(CRON_SECRET) : '#' ?>"
               class="btn btn-secondary btn-sm"
               <?= $cronOk ? "onclick=\"return confirm('Αποστολή τριμηνιαίων αναφορών σε ΟΛΑ τα clubs τώρα;')\" target=\"_blank\"" : "onclick=\"alert('Set CRON_SECRET env var first.');return false\"" ?>>
                <i class="fa-solid fa-play"></i> Εκτέλεση Τώρα
            </a>
        </div>
    </div>
</div>

<!-- History -->
<div class="card">
    <div class="d-flex ai-center jc-between" style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--muted,#8892b0)"></i> Ιστορικό Αποστολών</div>
    </div>
    <!-- Search + per-page -->
    <form method="GET" class="d-flex gap-sm flex-wrap ai-center" style="padding:.65rem 1rem;border-bottom:1px solid var(--border)">
        <?php foreach(array_filter($_GET, fn($k)=>!in_array($k,['hist_q','hist_page','hist_per']),ARRAY_FILTER_USE_KEY) as $gk=>$gv): ?>
        <input type="hidden" name="<?= h($gk) ?>" value="<?= h($gv) ?>">
        <?php endforeach; ?>
        <div class="search-bar" style="flex:1;min-width:180px">
            <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input name="hist_q" value="<?= h($histSearch) ?>" placeholder="Αναζήτηση θέματος...">
        </div>
        <select name="hist_per" class="form-control" style="width:130px" onchange="this.form.submit()">
            <?php foreach([10,25,50,100] as $n): ?>
            <option value="<?= $n ?>"<?= $histLimit==$n?' selected':'' ?>><?= $n ?> / σελίδα</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i> Φίλτρο</button>
        <?php if($histSearch): ?><a href="?<?= http_build_query(array_diff_key($_GET,['hist_q'=>'','hist_page'=>''])) ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>Θέμα</th><th>Επιτυχείς</th><th>Αποτυχίες</th><th>Σύνολο</th><th>Ημ/νία</th></tr></thead>
        <tbody>
            <?php if(empty($history)): ?>
            <tr><td colspan="5" class="text-center text-muted" style="padding:2rem">Δεν βρέθηκαν αποστολές<?= $histSearch ? ' για "'.h($histSearch).'"' : '' ?></td></tr>
            <?php else: foreach($history as $b): ?>
            <tr>
                <td style="font-weight:700;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($b['subject']) ?></td>
                <td><span class="badge" style="background:rgba(45,198,83,.15);color:#2dc653"><?= $b['sent'] ?></span></td>
                <td><?= $b['failed']>0?'<span class="badge" style="background:rgba(230,57,70,.15);color:#e63946">'.$b['failed'].'</span>':'<span style="color:var(--muted)">—</span>' ?></td>
                <td style="color:var(--muted,#8892b0)"><?= $b['total'] ?></td>
                <td style="color:var(--muted,#8892b0);font-size:.88rem;white-space:nowrap"><?= date('d/m/Y H:i',strtotime($b['sent_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table></div>
    <!-- Pagination -->
    <?php if($histTotalPages > 1 || $histTotal > 0): ?>
    <div class="pagination mt-1" style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;padding:.6rem 1rem">
        <?php if($histPage>1):?><a href="?<?= http_build_query(array_merge($_GET,['hist_page'=>1])) ?>" class="page-btn" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a><?php endif;?>
        <?php if($histPage>1):?><a href="?<?= http_build_query(array_merge($_GET,['hist_page'=>$histPage-1])) ?>" class="page-btn"><i class="fa-solid fa-chevron-left"></i></a><?php endif;?>
        <?php for($pp=max(1,$histPage-2);$pp<=min($histTotalPages,$histPage+2);$pp++):?>
        <a href="?<?= http_build_query(array_merge($_GET,['hist_page'=>$pp])) ?>" class="page-btn <?=$pp==$histPage?'active':''?>"><?=$pp?></a>
        <?php endfor;?>
        <?php if($histPage<$histTotalPages):?><a href="?<?= http_build_query(array_merge($_GET,['hist_page'=>$histPage+1])) ?>" class="page-btn"><i class="fa-solid fa-chevron-right"></i></a><?php endif;?>
        <?php if($histPage<$histTotalPages):?><a href="?<?= http_build_query(array_merge($_GET,['hist_page'=>$histTotalPages])) ?>" class="page-btn" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a><?php endif;?>
        <span style="font-size:.8rem;color:var(--muted);margin-left:.4rem"><?=$histTotal?> αποστολές · <?=$histPage?> / <?=$histTotalPages?></span>
    </div>
    <?php endif; ?>
</div>

</div></div></div>
<script>
(function(){var sb=document.getElementById('sidebar'),ov=document.getElementById('dm-overlay'),mb=document.getElementById('menuBtn');if(!sb||!mb)return;function open(){sb.classList.add('open');ov&&ov.classList.add('on');document.body.style.overflow='hidden'}function close(){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow=''}mb.onclick=function(e){e.stopPropagation();sb.classList.contains('open')?close():open()};ov&&ov.addEventListener('click',close);document.addEventListener('keydown',function(e){if(e.key==='Escape')close()});window.addEventListener('resize',function(){if(window.innerWidth>900){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow=''}});})();
</script>
<script>
var NL_TEMPLATES = <?= json_encode(array_column($templates, null, 'id'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
function loadTemplate(id) {
    var tpl = NL_TEMPLATES[id];
    if (!tpl) return;
    var subjectEl = document.querySelector('input[name="subject"]');
    var bodyEl    = document.getElementById('bodyTxt');
    if (subjectEl) subjectEl.value = tpl.subject;
    if (bodyEl)    { bodyEl.value = tpl.body; document.getElementById('cc').textContent = tpl.body.length + ' χαρ.'; }
    switchBC('email');
    subjectEl && subjectEl.scrollIntoView({behavior:'smooth', block:'center'});
}
function switchBC(tab) {
    document.getElementById('bcEmail').style.display = tab==='email'?'':'none';
    document.getElementById('bcSms').style.display   = tab==='sms'?'':'none';
    document.getElementById('tabEmailBtn').className = tab==='email'?'btn btn-primary btn-sm':'btn btn-ghost btn-sm';
    document.getElementById('tabSmsBtn').className   = tab==='sms'?'btn btn-primary btn-sm':'btn btn-ghost btn-sm';
    if(tab==='sms') document.getElementById('tabSmsBtn').style.background='#a855f7';
    else document.getElementById('tabSmsBtn').style.background='';
}
function updateSmsAdmin() {
    var el   = document.getElementById('smsTxt');
    var cc   = document.getElementById('smscc');
    var dots = document.getElementById('smsDots');
    if (!el) return;
    var n = el.value.length;
    if (cc) cc.textContent = n + ' / 250';
    if (dots) dots.style.display = n >= 250 ? '' : 'none';
}
</script>
</body></html>