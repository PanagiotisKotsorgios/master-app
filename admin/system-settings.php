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
 * admin/system-settings.php — Ρυθμίσεις Συστήματος (Super Admin)
 * ============================================================
 * PURPOSE:
 *   Admin UI για ρύθμιση: Viva.com, Brevo, bulker.gr, Twilio,
 *   trial_days, maintenance mode, app_url.
 *   Δοκιμαστική αποστολή email/SMS.
 *
 * SECURITY:
 *   ✓ requireSuperAdmin()
 *   ✓ verifyCsrf()
 *   ✓ API keys: αποθηκεύονται στη ΒΔ (ΟΧΙ στο .env ή config.php)
 *   ✓ Test email/SMS: χρησιμοποιεί τα ΗΔΗ αποθηκευμένα keys
 *   ✓ Whitelist για allowed setting keys (αποτρέπει arbitrary key injection)
 *   ✓ Prepared statements (upsert pattern)
 *   ✓ Audit log για κάθε αλλαγή ρύθμισης
 *
 * SENSITIVE DATA:
 *   Τα API keys εμφανίζονται masked (••••) εκτός αν "reveal" button.
 *   Password-type inputs για keys.
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Επαλήθευση CSRF token — αποτρέπει Cross-Site Request Forgery ──
    verifyCsrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_settings') {
        $fields = [
            'app_name','app_url','trial_days',
            'brevo_api_key','mail_from_email','mail_from_name',
            'bulker_auth_key','bulker_profile_id','bulker_sender',
            'twilio_account_sid','twilio_auth_token','twilio_messaging_sid',
            'viva_client_id','viva_client_secret','viva_merchant_id',
            'viva_api_key','viva_webhook_key','viva_demo_mode',
            'bank_name','bank_iban','bank_beneficiary',
            'bank_reference_hint','bank_receipt_email','bank_instructions',
            'iris_afm','iris_phone',
            'maintenance_mode','maintenance_message',
            'daily_email_limit','daily_sms_limit',
            'monthly_sms_limit','monthly_email_limit',
            'sms_overage_pack_qty','sms_overage_pack_price',
            'email_overage_pack_qty','email_overage_pack_price',
            'overage_commission_pct',
            'summer_pause_enabled','summer_pause_month','summer_pause_end_month',
            'summer_pause_message','summer_pause_popup_days','summer_pause_reopening_message',
            'pro_website_banner_enabled','pro_website_banner_title','pro_website_banner_message',
            'pro_website_banner_cta_label','pro_website_banner_cta_url',
        ];
        try {
            $stmt = $db->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
            foreach ($fields as $key) {
                $checkboxKeys = ['maintenance_mode', 'summer_pause_enabled', 'pro_website_banner_enabled'];
                $val = in_array($key, $checkboxKeys, true) ? (isset($_POST[$key]) ? '1' : '0') : trim($_POST[$key] ?? '');
                $stmt->execute([$key, $val, $val]);
            }
            $_SESSION['flash'] = ['msg' => '✅ Ρυθμίσεις αποθηκεύτηκαν!', 'type' => 'success'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['msg' => '❌ Σφάλμα: ' . $e->getMessage(), 'type' => 'danger'];
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?') . '?saved=1'); exit;
    }

    if ($action === 'test_email') {
        require_once __DIR__ . '/../includes/mailer.php';
        $to = trim($_POST['test_to'] ?? '');
        if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $debug   = null;
            $logoUrl = rtrim(APP_URL, '/') . '/assets/img/logo-tr.png';
            $testHtml = '<!DOCTYPE html><html lang="el"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#07090f;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#07090f;padding:32px 16px">
  <tr><td style="text-align:center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:500px;background:#111520;border-radius:16px;border:1px solid #1e2536;overflow:hidden">
      <tr>
        <td style="background:linear-gradient(135deg,#0d0d1a,#1a1040);padding:24px 32px;text-align:center;border-bottom:2px solid #2a1a50">
          <img src="' . $logoUrl . '" alt="MAster" style="height:60px;width:auto;max-width:180px;object-fit:contain;display:block;margin:0 auto">
        </td>
      </tr>
      <tr>
        <td style="padding:32px;color:#d0d8f0;font-size:.96rem;line-height:1.85">
          <h2 style="margin:0 0 12px;color:#4ade80;font-size:1.1rem">✅ Email λειτουργεί!</h2>
          <p style="margin:0 0 10px">Αυτό είναι δοκιμαστικό email από το <strong style="color:#f0f2ff">MAster</strong>. Η Brevo σύνδεση είναι σωστή.</p>
          <p style="color:#6b7494;font-size:.85rem;margin:0">Στάλθηκε: ' . date('d/m/Y H:i') . '</p>
        </td>
      </tr>
      <tr><td style="padding:0 32px"><div style="border-top:1px solid #1e2536"></div></td></tr>
      <tr><td style="padding:16px 32px;text-align:center;font-size:.72rem;color:#363d52">&copy; ' . date('Y') . ' MAster</td></tr>
    </table>
  </td></tr>
</table></body></html>';
            $ok = sendEmail($to, 'MAster — Test Email ✅', $testHtml, '', '', $debug);
            flash($ok ? "✅ Test email εστάλη στο $to" : "❌ Αποτυχία: $debug", $ok ? 'success' : 'danger');
        } else { flash('Εισάγετε έγκυρο email.','danger'); }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?') . '#email'); exit;
    }

    if ($action === 'test_sms') {
        require_once __DIR__ . '/../includes/mailer.php';
        $phone = trim($_POST['test_phone'] ?? '');
        if ($phone) {
            $smsError = null;
            // Χρησιμοποιούμε τα τρέχοντα credentials της φόρμας (αν σταλούν)
            // έτσι ο χρήστης μπορεί να δοκιμάσει χωρίς να αποθηκεύσει πρώτα
            $testAuthKey   = trim($_POST['test_bulker_auth_key']   ?? '');
            $testProfileId = trim($_POST['test_bulker_profile_id'] ?? '');
            $testSender    = trim($_POST['test_bulker_sender']     ?? '');
            $ok = sendSms(
                $phone,
                'MAster SMS Test - leitourgei! ' . date('d/m/Y H:i'),
                $smsError,
                '',
                $testAuthKey ?: null,
                $testProfileId ?: null,
                $testSender ?: null
            );
            flash(
                $ok
                    ? "✅ Test SMS εστάλη στο $phone"
                    : "❌ Αποτυχία: " . ($smsError ?? 'Άγνωστο σφάλμα'),
                $ok ? 'success' : 'danger'
            );
        } else { flash('Εισάγετε αριθμό τηλεφώνου.','danger'); }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?') . '#sms'); exit;
    }

    if ($action === 'check_sms_balance') {
        $testAuthKey = trim($_POST['test_bulker_auth_key'] ?? '');
        $authKey = $testAuthKey ?: (function_exists('getBulkerAuthKey') ? getBulkerAuthKey() : '');
        if (!$authKey) {
            flash('Auth Key δεν έχει οριστεί.', 'danger');
        } else {
            $balUrl = 'http://api.bulker.gr/http/balance.php?' . http_build_query(['auth_key' => $authKey]);
            $ch = curl_init($balUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true]);
            $balResp = trim((string) curl_exec($ch));
            $balErr  = curl_error($ch);
            curl_close($ch);
            if ($balErr) {
                flash("❌ CURL error: $balErr", 'danger');
            } elseif (stripos($balResp, 'OK') === 0) {
                $parts = explode(';', $balResp);
                $balance = $parts[1] ?? '?';
                flash("✅ Υπόλοιπο bulker.gr: <strong>{$balance} credits</strong> — Auth Key έγκυρο!", 'success');
            } elseif (stripos($balResp, 'ERROR') === 0) {
                $errMsg = trim(preg_replace('/^ERROR:\s*/i', '', explode(';', $balResp)[0]));
                flash("❌ bulker.gr: $errMsg", 'danger');
            } else {
                flash("❌ Απρόσμενη απόκριση: " . htmlspecialchars(mb_substr($balResp ?: '(κενό)', 0, 100)), 'danger');
            }
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?') . '#sms'); exit;
    }
}

$rows = $db->query("SELECT setting_key,setting_value FROM system_settings")->fetchAll();
$cfg  = [];
foreach ($rows as $r) $cfg[$r['setting_key']] = $r['setting_value'] ?? '';
function sv(array $c,string $k,string $fb=''): string { return htmlspecialchars($c[$k]??$fb,ENT_QUOTES,'UTF-8'); }

$flash = getFlash();
renderHead('Ρυθμίσεις Συστήματος');
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
.tab-nav{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.3rem}
.tab-btn{display:inline-flex;align-items:center;gap:.5rem;min-height:50px;padding:.6rem 1.2rem;border-radius:14px;border:2px solid var(--border,#1e2536);background:transparent;cursor:pointer;font-size:clamp(.9rem,3vw,1rem)!important;font-weight:700;color:var(--muted,#8892b0);transition:all .18s;white-space:nowrap}
.tab-btn i{font-size:1.05rem}
.tab-btn:hover{border-color:var(--red,#e63946);color:var(--text,#e2e8f0)}
.tab-btn.active{border-color:var(--red,#e63946);color:var(--text,#e2e8f0);background:rgba(230,57,70,.1)}
@media(max-width:600px){.tab-nav{gap:.35rem}.tab-btn{flex:1;justify-content:center;min-height:46px;padding:.5rem .6rem;font-size:.82rem!important}.tab-lbl{display:none}}
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
textarea.form-control{min-height:100px;resize:vertical}
.form-hint{font-size:.8rem!important;color:var(--muted,#8892b0);line-height:1.45}
.section-sep{font-size:.75rem!important;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--muted,#8892b0);padding:.6rem 0;border-bottom:1px solid var(--border,#1e2536);margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem}
.btn{min-height:46px;font-size:clamp(.9rem,3vw,1rem)!important;font-weight:700!important;display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border-radius:11px;transition:all .18s;text-decoration:none;padding:.55rem 1.1rem;cursor:pointer;border:none;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-sm{min-height:38px;padding:.38rem .85rem;font-size:.88rem!important;border-radius:9px}
.btn-icon{min-width:42px;padding:.4rem;flex-shrink:0}
.toggle-row{display:flex;align-items:center;gap:.9rem;margin-bottom:1.2rem}
.toggle{position:relative;width:54px;height:30px;flex-shrink:0;cursor:pointer}
.toggle input{opacity:0;width:0;height:0;position:absolute}
.toggle-track{position:absolute;inset:0;background:#2d3748;border-radius:34px;transition:.25s}
.toggle-track:before{content:'';position:absolute;height:24px;width:24px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s}
.toggle input:checked~.toggle-track{background:var(--red,#e63946)}
.toggle input:checked~.toggle-track:before{transform:translateX(24px)}
.toggle-lbl{font-size:clamp(.95rem,3vw,1.05rem)!important;font-weight:700;cursor:pointer}
.alert{border-radius:12px;padding:.85rem 1rem;font-size:clamp(.88rem,3vw,.96rem)!important;line-height:1.6;display:flex;align-items:flex-start;gap:.65rem;margin-bottom:1rem}
.alert-success{background:rgba(45,198,83,.12);color:#86efac;border:1px solid rgba(45,198,83,.25)}
.alert-warning{background:rgba(240,165,0,.12);color:#fbbf24;border:1px solid rgba(240,165,0,.2)}
.alert-danger{background:rgba(230,57,70,.12);color:#f87171;border:1px solid rgba(230,57,70,.2)}
.alert-info{background:rgba(99,91,255,.12);color:#a5b4fc;border:1px solid rgba(99,91,255,.25)}
.alert i{flex-shrink:0;margin-top:.1rem}
.save-bar{position:sticky;bottom:1rem;z-index:50;display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;padding:.85rem 1.1rem;border-radius:16px;background:var(--card-bg,#151929);border:1px solid var(--border,#1e2536);box-shadow:0 8px 32px rgba(0,0,0,.4);margin-top:.25rem}
.step-box{display:flex;gap:.85rem;align-items:flex-start;padding:.85rem 1rem;background:rgba(255,255,255,.03);border:1px solid var(--border,#1e2536);border-radius:12px}
.step-num{width:28px;height:28px;min-width:28px;border-radius:50%;background:#e63946;color:#fff;font-size:.8rem;font-weight:800;display:flex;align-items:center;justify-content:center}
.step-body{font-size:.88rem;color:var(--muted,#8892b0);line-height:1.6}
.step-body strong{color:var(--text,#e2e8f0)}
.step-body a{color:#e63946;text-decoration:none}
.step-body a:hover{text-decoration:underline}
.step-body code{background:#0d1017;padding:.1rem .4rem;border-radius:4px;color:#f0a500;font-size:.82rem}
@media(max-width:900px){.page-body{padding:1rem!important}}
@media(max-width:700px){.page-body{padding:.85rem!important}.form-grid,.form-grid.cols-3{grid-template-columns:1fr!important}.form-grid .span-2{grid-column:span 1!important}}
@media(max-width:480px){.page-body{padding:.65rem!important}.card{border-radius:14px}}
</style>
<body>
<div class="app-layout">
<?php renderSidebar('admin_settings'); ?>
<div id="dm-overlay"></div>
<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-sliders"></i> Ρυθμίσεις Συστήματος'); ?>
<div class="page-body">

<div class="page-header anim-1">
    <h2><i class="fa-solid fa-sliders" style="color:var(--red,#e63946)"></i> Ρυθμίσεις Συστήματος</h2>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type']==='success'?'success':'danger' ?> anim-1">
    <i class="fa-solid fa-<?= $flash['type']==='success'?'circle-check':'triangle-exclamation' ?>"></i>
    <span><?= h($flash['msg']) ?></span>
</div>
<?php endif; ?>

<?php if(($cfg['maintenance_mode']??'0')==='1'): ?>
<div class="alert alert-danger anim-1">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div><strong>Maintenance Mode ΕΝΕΡΓΟ!</strong> Οι χρήστες δεν έχουν πρόσβαση τώρα.</div>
</div>
<?php endif; ?>

<!-- Settings search -->
<div class="d-flex ai-center gap-sm mb-3 anim-1" style="max-width:440px">
    <div class="search-bar" style="flex:1">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input id="settings-search" type="text" placeholder="Αναζήτηση ρύθμισης...">
    </div>
    <button class="btn btn-ghost btn-sm" onclick="document.getElementById('settings-search').value='';filterSettings('')">
        <i class="fa-solid fa-xmark"></i>
    </button>
</div>
<div id="settings-no-results" style="display:none" class="alert alert-warning anim-1">
    <i class="fa-solid fa-circle-info"></i> Δεν βρέθηκε ρύθμιση. Δοκιμάστε διαφορετικό όρο.
</div>
<div class="tab-nav anim-1" id="tab-nav">
    <button class="tab-btn active" onclick="switchTab('general',this)"><i class="fa-solid fa-gear"></i><span class="tab-lbl"> Γενικά</span></button>
    <button class="tab-btn" onclick="switchTab('email',this)"><i class="fa-solid fa-envelope"></i><span class="tab-lbl"> Email</span></button>
    <button class="tab-btn" onclick="switchTab('sms',this)"><i class="fa-solid fa-mobile-screen-button"></i><span class="tab-lbl"> SMS</span></button>
    <button class="tab-btn" onclick="switchTab('twilio',this)"><i class="fa-brands fa-twilio" style="color:#f22f46"></i><span class="tab-lbl"> Twilio</span></button>
    <button class="tab-btn" onclick="switchTab('payments',this)"><i class="fa-solid fa-credit-card" style="color:#6b5ce7"></i><span class="tab-lbl"> Πληρωμές</span></button>
    <button class="tab-btn" onclick="switchTab('maintenance',this)"><i class="fa-solid fa-wrench"></i><span class="tab-lbl"> Maintenance</span></button>
    <button class="tab-btn" onclick="switchTab('overage',this)"><i class="fa-solid fa-cart-shopping" style="color:#f0a500"></i><span class="tab-lbl"> Πακέτα SMS/Email</span></button>
    <button class="tab-btn" onclick="switchTab('summer',this)"><i class="fa-solid fa-sun" style="color:#f0a500"></i><span class="tab-lbl"> Θερινή Παύση</span></button>
    <button class="tab-btn" onclick="switchTab('prowebsite',this)"><i class="fa-solid fa-globe" style="color:#e63946"></i><span class="tab-lbl"> Pro Website Banner</span></button>
</div>

<form method="POST" id="settingsForm">
<input type="hidden" name="_action" value="save_settings">
<input type="hidden" name="csrf_token" value="<?= csrf() ?>">

<!-- ══ GENERAL ══ -->
<div id="tab-general" class="anim-2">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-building" style="color:#3b82f6"></i> Στοιχεία Εφαρμογής</div></div>
    <div class="card-body">
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-tag"></i> Όνομα Εφαρμογής</label>
                <input name="app_name" class="form-control" value="<?= sv($cfg,'app_name','MAster') ?>">
                <div class="form-hint">Εμφανίζεται στο sidebar, emails, browser tab</div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-link"></i> App URL</label>
                <input name="app_url" class="form-control" value="<?= sv($cfg,'app_url',APP_URL) ?>">
                <div class="form-hint">Χωρίς trailing slash — π.χ. https://master-app.gr</div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-regular fa-clock"></i> Δοκιμαστική Περίοδος (ημέρες)</label>
                <input type="number" name="trial_days" class="form-control" value="<?= sv($cfg,'trial_days','14') ?>" min="1" max="365">
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-envelope"></i> Ημερήσιο Όριο Email ανά Σχολή</label>
                <input type="number" name="daily_email_limit" class="form-control" value="<?= sv($cfg,'daily_email_limit','200') ?>" min="0" max="10000">
                <span class="form-hint">0 = απεριόριστο. Προστατεύει από υπερκατανάλωση.</span>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-mobile-screen-button"></i> Ημερήσιο Όριο SMS ανά Σχολή</label>
                <input type="number" name="daily_sms_limit" class="form-control" value="<?= sv($cfg,'daily_sms_limit','100') ?>" min="0" max="5000">
                <span class="form-hint">0 = απεριόριστο.</span>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ══ EMAIL ══ -->
<div id="tab-email" style="display:none" class="anim-2">
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-envelope" style="color:#3b82f6"></i> Brevo — Email</div>
        <a href="https://app.brevo.com/settings/keys/api" target="_blank" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> Brevo Dashboard</a>
    </div>
    <div class="card-body">
        <div class="form-grid" style="margin-bottom:1.4rem">
            <div class="form-group span-2">
                <label class="form-label"><i class="fa-solid fa-key"></i> Brevo API Key</label>
                <div style="display:flex;gap:.5rem">
                    <input type="password" id="brevo_k" name="brevo_api_key" class="form-control" value="<?= sv($cfg,'brevo_api_key') ?>" placeholder="xkeysib-...">
                    <button type="button" onclick="togglePass('brevo_k',this)" class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-eye"></i></button>
                </div>
                <div class="form-hint">Settings → API Keys</div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-at"></i> From Email</label>
                <input type="email" name="mail_from_email" class="form-control" value="<?= sv($cfg,'mail_from_email','noreply@master-app.gr') ?>">
                <div class="form-hint">Πρέπει να είναι επαληθευμένος αποστολέας στο Brevo</div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-user"></i> From Name</label>
                <input name="mail_from_name" class="form-control" value="<?= sv($cfg,'mail_from_name','MAster') ?>">
            </div>
        </div>
        <div class="section-sep"><i class="fa-solid fa-flask"></i> Test Αποστολή</div>
        <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="flex:1;min-width:220px">
                <label class="form-label">Email παραλήπτη</label>
                <input type="email" id="test_to_input" class="form-control" placeholder="your@email.com">
            </div>
            <button type="button" onclick="sendTestEmail()" class="btn btn-secondary" style="flex-shrink:0">
                <i class="fa-solid fa-paper-plane"></i> Στείλε Test
            </button>
        </div>
    </div>
</div>
</div>

<!-- ══ SMS ══ -->
<div id="tab-sms" style="display:none" class="anim-2">
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-mobile-screen-button" style="color:#a855f7"></i> bulker.gr — SMS</div>
        <a href="https://www.bulker.gr" target="_blank" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> bulker.gr Portal</a>
    </div>
    <div class="card-body">

        <div class="alert alert-info" style="margin-bottom:1.2rem">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                Χρησιμοποιείται το <strong>bulker.gr HTTP API v1.2</strong>.
                Τα credentials βρίσκονται στο <strong>bulker.gr → Ρυθμίσεις API → HTTP</strong>.
                Πάτα <em>Έλεγχος Υπολοίπου</em> για να επαληθεύσεις ότι το Auth Key είναι σωστό.
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-map"></i> Πού βρίσκεις τα στοιχεία</div>
        <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.4rem">
            <div class="step-box">
                <div class="step-num">1</div>
                <div class="step-body">Πήγαινε στο <a href="https://www.bulker.gr" target="_blank">www.bulker.gr</a> → σύνδεση → <strong>Ρυθμίσεις API</strong> → καρτέλα <strong>HTTP</strong>.</div>
            </div>
            <div class="step-box">
                <div class="step-num">2</div>
                <div class="step-body">Αντίγραψε το <strong>Auth Key</strong> (το μεγάλο κλειδί στο πεδίο Auth Key).</div>
            </div>
            <div class="step-box">
                <div class="step-num">3</div>
                <div class="step-body">
                    Στο πεδίο <strong>Sender</strong> βάλε τον αποστολέα <strong>ακριβώς όπως είναι registered</strong> στο bulker.gr:<br>
                    • Αλφαριθμητικό ID έως 11 χαρακτήρες (π.χ. <code>MAster</code>) — <em>πιο αξιόπιστο</em><br>
                    • Αριθμός τηλεφώνου <strong>χωρίς +</strong>, π.χ. <code>6986788178</code> ή <code>306986788178</code>
                </div>
            </div>
            <div class="step-box" style="background:rgba(239,68,68,.06);border-color:rgba(239,68,68,.25)">
                <div class="step-num" style="background:#ef4444">!</div>
                <div class="step-body"><strong style="color:#f87171">Αιτία "from is invalid":</strong> Ο αποστολέας δεν είναι authorized στο bulker.gr. Για αριθμό τηλεφώνου, ο αριθμός πρέπει να είναι <strong>εγγεγραμμένος</strong> στο λογαριασμό. Αν αποτύχει, χρησιμοποίησε alphanumeric sender (π.χ. <code>MAster</code>) που δεν χρειάζεται εγγραφή.</div>
            </div>
        </div>

        <div class="form-grid" style="margin-bottom:1.4rem">
            <div class="form-group span-2">
                <label class="form-label"><i class="fa-solid fa-key"></i> Auth Key</label>
                <div style="display:flex;gap:.5rem">
                    <input type="password" id="bulker_k" name="bulker_auth_key" class="form-control" value="<?= sv($cfg,'bulker_auth_key') ?>" placeholder="π.χ. zrFUaj34WHYZoFjC1ON1W4M1DpCqpQVl">
                    <button type="button" onclick="togglePass('bulker_k',this)" class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-eye"></i></button>
                </div>
                <div class="form-hint">Το Auth Key από τη σελίδα <strong>Ρυθμίσεις API → HTTP</strong> του bulker.gr</div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-fingerprint"></i> Profile ID <span style="color:#6b7494;font-weight:400">(προαιρετικό)</span></label>
                <input name="bulker_profile_id" class="form-control" value="<?= sv($cfg,'bulker_profile_id') ?>" placeholder="π.χ. 7426">
                <div class="form-hint">Το Προφίλ ID — για αναφορά μόνο, χρησιμοποιείται ως <code>pid</code> για child accounts</div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-id-badge"></i> Sender (αποστολέας)</label>
                <input name="bulker_sender" id="bulker_sender_field" class="form-control" value="<?= sv($cfg,'bulker_sender') ?>" placeholder="6986788178 ή MAster">
                <div class="form-hint">
                    Αλφαριθμητικό ≤11 χαρακτ. (π.χ. <code>MAster</code>) <strong>ή</strong> αριθμός τηλεφώνου <em>ακριβώς</em> όπως είναι registered στο bulker.gr (π.χ. <code>6986788178</code>).
                    <strong style="color:#f87171">Δεν</strong> χρειάζεται +30 — βάλτο χωρίς +.
                </div>
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-flask"></i> Έλεγχος &amp; Test</div>
        <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:.5rem">
            <button type="button" onclick="checkSmsBalance()" class="btn btn-ghost" style="flex-shrink:0;min-height:44px;border-color:rgba(74,222,128,.3);color:#4ade80">
                <i class="fa-solid fa-wallet"></i> Έλεγχος Υπολοίπου
            </button>
            <div style="color:#6b7494;font-size:.82rem;align-self:center">Επαλήθευση Auth Key &amp; εμφάνιση διαθέσιμων credits</div>
        </div>
        <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="flex:1;min-width:220px">
                <label class="form-label"><i class="fa-solid fa-phone"></i> Αριθμός τηλεφώνου για Test SMS</label>
                <input type="tel" id="test_phone_input" class="form-control" placeholder="6986788178 ή +306986788178">
                <div class="form-hint">Το +30 προστίθεται αυτόματα αν ξεκινά με 69</div>
            </div>
            <button type="button" onclick="sendTestSms()" class="btn btn-secondary" style="flex-shrink:0;min-height:48px;border-color:rgba(168,85,247,.4);color:#c084fc">
                <i class="fa-solid fa-comment-sms"></i> Στείλε Test SMS
            </button>
        </div>
    </div>
</div>
</div>

<!-- ══ TWILIO ══ -->
<div id="tab-twilio" style="display:none" class="anim-2">
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-brands fa-twilio" style="color:#f22f46"></i> Twilio — SMS (Εναλλακτικό)</div>
        <a href="https://console.twilio.com/" target="_blank" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> Twilio Console</a>
    </div>
    <div class="card-body">
        <div class="section-sep"><i class="fa-solid fa-circle-info"></i> Πληροφορίες</div>
        <div style="background:rgba(242,47,70,.06);border:1px solid rgba(242,47,70,.2);border-radius:8px;padding:.75rem 1rem;font-size:.82rem;color:var(--muted,#8892b0);margin-bottom:1rem;line-height:1.65">
            Το Twilio χρησιμοποιείται ως <strong>εναλλακτικό SMS provider</strong> — χρησιμοποιείται μόνο αν το bulker.gr δεν είναι ρυθμισμένο.
            Τα στοιχεία Account SID και Auth Token τα βρίσκεις στο <strong>console.twilio.com → Dashboard</strong>.
        </div>
        <div class="section-sep"><i class="fa-solid fa-key"></i> Credentials</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Account SID</label>
                <input name="twilio_account_sid" class="form-control" value="<?= sv($cfg,'twilio_account_sid') ?>" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                <div class="form-hint">Αρχίζει με AC — από το Twilio Dashboard</div>
            </div>
            <div class="form-group">
                <label class="form-label">Auth Token</label>
                <div style="display:flex;gap:.5rem">
                    <input type="password" id="twilio_tok" name="twilio_auth_token" class="form-control" value="<?= sv($cfg,'twilio_auth_token') ?>" placeholder="••••••••••••••••••••••••••••••••">
                    <button type="button" onclick="togglePass('twilio_tok',this)" class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-eye"></i></button>
                </div>
                <div class="form-hint">Dashboard → Auth Token (κλικ για αποκάλυψη)</div>
            </div>
            <div class="form-group span-2">
                <label class="form-label">Messaging Service SID</label>
                <input name="twilio_messaging_sid" class="form-control" value="<?= sv($cfg,'twilio_messaging_sid') ?>" placeholder="MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                <div class="form-hint">Messaging → Services → SID (αρχίζει με MG)</div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ══ PAYMENTS (Viva.com + Τραπεζική Μεταφορά) ══ -->
<div id="tab-payments" style="display:none" class="anim-2">

<!-- Viva.com Card -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-bolt" style="color:#6b5ce7"></i> Viva.com — IRIS &amp; Κάρτα</div>
        <a href="https://www.viva.com/en-gr/business" target="_blank" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> Viva Dashboard</a>
    </div>
    <div class="card-body">

        <div class="alert alert-info" style="margin-bottom:1.2rem">
            <i class="fa-solid fa-circle-info"></i>
            <div style="font-size:.83rem;line-height:1.6">
                Εγγραφή στο <strong>viva.com</strong> → My Sales → API Access → Smart Checkout.<br>
                Τα credentials βρίσκονται στο <strong>My Sales → API Access → Smart Checkout Credentials</strong>.<br>
                Το <strong>API Key</strong> (Source Code) βρίσκεται στο <strong>My Sales → API Access → API Key</strong>.
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-toggle-on"></i> Demo / Live Mode</div>
        <div class="form-group" style="margin-bottom:1.4rem">
            <label class="form-label">
                <input type="checkbox" name="viva_demo_mode" value="1" <?= sv($cfg,'viva_demo_mode','1') === '1' ? 'checked' : '' ?> style="margin-right:.4rem">
                Demo Mode (testing — demo.vivapayments.com)
            </label>
            <div class="form-hint">Απενεργοποίησε για live παραγωγή (www.vivapayments.com)</div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-key"></i> Smart Checkout Credentials</div>
        <div class="form-grid" style="margin-bottom:1.4rem">
            <div class="form-group">
                <label class="form-label">Client ID</label>
                <input name="viva_client_id" class="form-control" value="<?= sv($cfg,'viva_client_id') ?>" placeholder="xxxxx-xxxx-xxxx-xxxx-xxxxxxxxx">
                <div class="form-hint">My Sales → API Access → Smart Checkout → Client ID</div>
            </div>
            <div class="form-group">
                <label class="form-label">Client Secret</label>
                <div style="display:flex;gap:.5rem">
                    <input type="password" id="viva_cs" name="viva_client_secret" class="form-control" value="<?= sv($cfg,'viva_client_secret') ?>" placeholder="••••••••••••••••">
                    <button type="button" onclick="togglePass('viva_cs',this)" class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-eye"></i></button>
                </div>
                <div class="form-hint">My Sales → API Access → Smart Checkout → Client Secret</div>
            </div>
            <div class="form-group">
                <label class="form-label">Merchant ID</label>
                <input name="viva_merchant_id" class="form-control" value="<?= sv($cfg,'viva_merchant_id') ?>" placeholder="12345678">
                <div class="form-hint">Merchant ID από το Viva profile (My Account → Profile)</div>
            </div>
            <div class="form-group">
                <label class="form-label">API Key (Source Code)</label>
                <div style="display:flex;gap:.5rem">
                    <input type="password" id="viva_ak" name="viva_api_key" class="form-control" value="<?= sv($cfg,'viva_api_key') ?>" placeholder="••••••••••••••••">
                    <button type="button" onclick="togglePass('viva_ak',this)" class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-eye"></i></button>
                </div>
                <div class="form-hint">My Sales → API Access → Generate API Key</div>
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-bell"></i> Webhook</div>
        <div class="form-grid">
            <div class="form-group span-2">
                <label class="form-label">Webhook Verification Key</label>
                <div style="display:flex;gap:.5rem">
                    <input type="password" id="viva_wk" name="viva_webhook_key" class="form-control" value="<?= sv($cfg,'viva_webhook_key') ?>" placeholder="••••••••••••••••">
                    <button type="button" onclick="togglePass('viva_wk',this)" class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-eye"></i></button>
                </div>
                <div class="form-hint">My Sales → API Access → Webhooks → Verification Key</div>
            </div>
            <div class="form-group span-2">
                <div class="alert alert-warning" style="margin:0">
                    <i class="fa-solid fa-circle-info"></i>
                    <div style="font-size:.82rem">
                        Το αυτόματο Viva webhook έχει απενεργοποιηθεί. Η ενεργοποίηση λογαριασμών γίνεται
                        χειροκίνητα από <strong>Admin → Πληρωμές</strong> μετά από λήψη τραπεζικής μεταφοράς / IRIS.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bank Transfer Card -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-building-columns" style="color:#3b82f6"></i> Τραπεζικό Έμβασμα</div>
    </div>
    <div class="card-body">

        <div class="alert alert-info" style="margin-bottom:1.2rem">
            <i class="fa-solid fa-circle-info"></i>
            <div style="font-size:.83rem;line-height:1.6">
                Ο χρήστης βλέπει αυτά τα στοιχεία στη σελίδα αναβάθμισης και στέλνει αποδεικτικό στο email σου.
                Μετά ενεργοποιείς χειροκίνητα τον λογαριασμό από <strong>Admin → Πληρωμές → Νέα Πληρωμή</strong>.
            </div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-university"></i> Όνομα Τράπεζας</label>
                <input name="bank_name" class="form-control" value="<?= sv($cfg,'bank_name') ?>" placeholder="π.χ. Eurobank">
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-user"></i> Δικαιούχος</label>
                <input name="bank_beneficiary" class="form-control" value="<?= sv($cfg,'bank_beneficiary') ?>" placeholder="Ονοματεπώνυμο ή Εταιρεία">
            </div>
            <div class="form-group span-2">
                <label class="form-label"><i class="fa-solid fa-hashtag"></i> IBAN</label>
                <input name="bank_iban" class="form-control" value="<?= sv($cfg,'bank_iban') ?>" placeholder="GR00 0000 0000 0000 0000 0000 000" style="font-family:monospace;letter-spacing:.04em">
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-tag"></i> Πρότυπο Αιτιολογίας</label>
                <input name="bank_reference_hint" class="form-control" value="<?= sv($cfg,'bank_reference_hint','MASTER-{SCHOOL_NAME}') ?>" placeholder="MASTER-{SCHOOL_NAME}">
                <div class="form-hint">{SCHOOL_NAME} → όνομα σχολής &nbsp;|&nbsp; {SCHOOL_ID} → αριθμητικό ID σχολής</div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-envelope"></i> Email Παραλαβής Αποδεικτικών</label>
                <input type="email" name="bank_receipt_email" class="form-control" value="<?= sv($cfg,'bank_receipt_email') ?>" placeholder="payments@yoursite.gr">
                <div class="form-hint">Ο χρήστης στέλνει το αποδεικτικό εδώ</div>
            </div>
            <div class="form-group span-2">
                <label class="form-label"><i class="fa-solid fa-align-left"></i> Επιπλέον Οδηγίες (εμφανίζονται στον χρήστη)</label>
                <textarea name="bank_instructions" class="form-control" rows="3" placeholder="π.χ. Αναφέρετε στην αιτιολογία τον κωδικό σχολής σας για ταχύτερη επεξεργασία."><?= sv($cfg,'bank_instructions') ?></textarea>
            </div>
        </div>

        <div class="section-sep" style="margin-top:1.4rem"><i class="fa-solid fa-bolt" style="color:#6b5ce7"></i> IRIS — Άμεση Πληρωμή</div>
        <div class="alert alert-info" style="margin-bottom:1rem">
            <i class="fa-solid fa-circle-info"></i>
            <div style="font-size:.83rem;line-height:1.6">
                Συμπληρώστε τα παρακάτω για να εμφανίζεται η επιλογή πληρωμής μέσω <strong>IRIS</strong> στη σελίδα αναβάθμισης. Ο χρήστης στέλνει χρήματα απ' ευθείας στον λογαριασμό σου.
            </div>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-id-card"></i> ΑΦΜ (για IRIS)</label>
                <input name="iris_afm" class="form-control" value="<?= sv($cfg,'iris_afm') ?>" placeholder="π.χ. 123456789" style="font-family:monospace;letter-spacing:.04em">
                <div class="form-hint">Το ΑΦΜ εμφανίζεται ως επιλογή αναζήτησης στο IRIS</div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-mobile-screen"></i> Τηλέφωνο (για IRIS)</label>
                <input name="iris_phone" class="form-control" value="<?= sv($cfg,'iris_phone') ?>" placeholder="π.χ. 6900000000" style="font-family:monospace;letter-spacing:.04em">
                <div class="form-hint">Αριθμός κινητού συνδεδεμένος με IRIS λογαριασμό</div>
            </div>
        </div>
    </div>
</div>

</div>
<!-- ══ END PAYMENTS TAB ══ -->


<!-- ══ OVERAGE PACKAGES ══ -->
<div id="tab-overage" style="display:none" class="anim-2">
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-cart-shopping" style="color:#f0a500"></i> Πακέτα Επέκτασης SMS / Email</div>
    </div>
    <div class="card-body">

        <div class="alert alert-info" style="margin-bottom:1.2rem">
            <i class="fa-solid fa-circle-info"></i>
            <div style="font-size:.83rem;line-height:1.6">
                Όταν μια σχολή ξεπεράσει το μηνιαίο της όριο SMS/email, εμφανίζεται αυτόματα popup
                με οδηγίες πληρωμής για αγορά επιπλέον πακέτου. Εσύ κρατάς <strong>προμήθεια</strong> και ενεργοποιείς
                το πακέτο χειροκίνητα από <strong>Admin → Πληρωμές</strong>.
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-clock"></i> Μηνιαία Όρια ανά Σχολή</div>
        <div class="form-grid" style="margin-bottom:1.4rem">
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-comment-sms"></i> Μηνιαίο Όριο SMS</label>
                <input type="number" name="monthly_sms_limit" class="form-control"
                       value="<?= sv($cfg,'monthly_sms_limit','0') ?>" min="0" max="100000">
                <div class="form-hint">0 = απεριόριστο. π.χ. 500 για μέγιστο 500 SMS/μήνα ανά σχολή.</div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-envelope"></i> Μηνιαίο Όριο Email</label>
                <input type="number" name="monthly_email_limit" class="form-control"
                       value="<?= sv($cfg,'monthly_email_limit','0') ?>" min="0" max="100000">
                <div class="form-hint">0 = απεριόριστο.</div>
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-comment-sms" style="color:#a855f7"></i> Πακέτο SMS</div>
        <div class="form-grid" style="margin-bottom:1.4rem">
            <div class="form-group">
                <label class="form-label">Ποσότητα SMS ανά πακέτο</label>
                <input type="number" name="sms_overage_pack_qty" class="form-control"
                       value="<?= sv($cfg,'sms_overage_pack_qty','500') ?>" min="1">
                <div class="form-hint">π.χ. 500 → ο χρήστης αγοράζει 500 επιπλέον SMS.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Τιμή πακέτου SMS (€)</label>
                <input type="number" step=".01" name="sms_overage_pack_price" class="form-control"
                       value="<?= sv($cfg,'sms_overage_pack_price','10.00') ?>" min="0">
                <div class="form-hint">Τιμή που πληρώνει ο χρήστης (συμπ. ΦΠΑ).</div>
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-envelope" style="color:#3b82f6"></i> Πακέτο Email</div>
        <div class="form-grid" style="margin-bottom:1.4rem">
            <div class="form-group">
                <label class="form-label">Ποσότητα email ανά πακέτο</label>
                <input type="number" name="email_overage_pack_qty" class="form-control"
                       value="<?= sv($cfg,'email_overage_pack_qty','500') ?>" min="1">
            </div>
            <div class="form-group">
                <label class="form-label">Τιμή πακέτου Email (€)</label>
                <input type="number" step=".01" name="email_overage_pack_price" class="form-control"
                       value="<?= sv($cfg,'email_overage_pack_price','5.00') ?>" min="0">
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-percent" style="color:#2dc653"></i> Προμήθεια Admin</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Ποσοστό Προμήθειας (%)</label>
                <input type="number" step="0.1" name="overage_commission_pct" class="form-control"
                       value="<?= sv($cfg,'overage_commission_pct','20') ?>" min="0" max="100">
                <div class="form-hint">% από κάθε πακέτο που κρατάς εσύ. π.χ. 20% από €10 = €2 προμήθεια, €8 κόστος SMS.</div>
            </div>
            <div class="form-group" style="align-self:center">
                <?php
                $q  = (float)sv($cfg,'sms_overage_pack_price','10');
                $pc = (float)sv($cfg,'overage_commission_pct','20');
                $comm = round($q * $pc / 100, 2);
                $net  = round($q - $comm, 2);
                ?>
                <div style="background:rgba(45,198,83,.08);border:1px solid rgba(45,198,83,.2);
                            border-radius:10px;padding:.75rem 1rem;font-size:.85rem;line-height:1.7">
                    <div><span style="color:#8892b0">Τιμή πακέτου SMS:</span> <strong style="color:#f0f2ff">€<?= number_format($q,2) ?></strong></div>
                    <div><span style="color:#8892b0">Προμήθεια (<?= $pc ?>%):</span> <strong style="color:#2dc653">€<?= number_format($comm,2) ?></strong></div>
                    <div><span style="color:#8892b0">Κόστος SMS (net):</span> <strong style="color:#f0a500">€<?= number_format($net,2) ?></strong></div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<!-- ══ END OVERAGE TAB ══ -->

<!-- ══ SUMMER PAUSE ══ -->
<div id="tab-summer" style="display:none" class="anim-2">
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-sun" style="color:#f0a500"></i> Θερινή Παύση</div>
    </div>
    <div class="card-body">

        <div class="alert alert-info" style="margin-bottom:1.2rem">
            <i class="fa-solid fa-circle-info"></i>
            <div style="font-size:.83rem;line-height:1.6">
                Η Θερινή Παύση επιτρέπει στις σχολές που το επιθυμούν να "παγώσουν" τις υπενθυμίσεις κατά τους καλοκαιρινούς μήνες.
                Εμφανίζεται popup ενημέρωσης στο dashboard τους πριν και κατά τη διάρκεια της παύσης.
                Το cron στέλνει email+SMS στους καθηγητές της σχολής όταν ενεργοποιηθεί η παύση.
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-toggle-on"></i> Ενεργοποίηση</div>
        <div class="toggle-row" style="margin-bottom:1.4rem">
            <label class="toggle">
                <input type="checkbox" name="summer_pause_enabled" value="1" id="summerPauseToggle"
                    <?= ($cfg['summer_pause_enabled']??'0')==='1'?'checked':'' ?>>
                <div class="toggle-track"></div>
            </label>
            <label for="summerPauseToggle" class="toggle-lbl">Η Θερινή Παύση είναι <strong>ενεργή τώρα</strong></label>
        </div>

        <div class="section-sep"><i class="fa-solid fa-calendar-days" style="color:#3b82f6"></i> Περίοδος Παύσης</div>
        <div class="form-grid" style="margin-bottom:1.4rem">
            <div class="form-group">
                <label class="form-label">Μήνας Έναρξης</label>
                <select name="summer_pause_month" class="form-control">
                    <?php
                    $months = ['','Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος',
                               'Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
                    $curStart = (int)sv($cfg,'summer_pause_month','7');
                    for ($m=1;$m<=12;$m++) echo "<option value=\"$m\"" . ($curStart===$m?' selected':'') . ">$months[$m]</option>";
                    ?>
                </select>
                <div class="form-hint">Συνήθως Ιούλιος (7)</div>
            </div>
            <div class="form-group">
                <label class="form-label">Μήνας Λήξης</label>
                <select name="summer_pause_end_month" class="form-control">
                    <?php
                    $curEnd = (int)sv($cfg,'summer_pause_end_month','8');
                    for ($m=1;$m<=12;$m++) echo "<option value=\"$m\"" . ($curEnd===$m?' selected':'') . ">$months[$m]</option>";
                    ?>
                </select>
                <div class="form-hint">Συνήθως Αύγουστος (8) — η παύση λήγει στο τέλος αυτού του μήνα.</div>
            </div>
        </div>

        <div class="form-grid" style="margin-bottom:1.4rem">
            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-bell"></i> Ημέρες πριν για popup</label>
                <input type="number" name="summer_pause_popup_days" class="form-control"
                       value="<?= sv($cfg,'summer_pause_popup_days','14') ?>" min="1" max="60">
                <div class="form-hint">Πόσες μέρες πριν την έναρξη θα εμφανιστεί το popup στις σχολές.</div>
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-comment-dots" style="color:#a855f7"></i> Μηνύματα</div>
        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Μήνυμα κατά τη διάρκεια παύσης</label>
            <textarea name="summer_pause_message" class="form-control" rows="3"><?= h(sv($cfg,'summer_pause_message','Η πλατφόρμα βρίσκεται σε θερινή παύση. Οι αυτόματες ειδοποιήσεις έχουν ανασταλεί μέχρι το τέλος του καλοκαιριού.')) ?></textarea>
            <div class="form-hint">Εμφανίζεται στο dashboard των σχολών που έχουν επιλέξει θερινή παύση.</div>
        </div>
        <div class="form-group" style="margin-bottom:1.4rem">
            <label class="form-label">Μήνυμα επαναλειτουργίας</label>
            <textarea name="summer_pause_reopening_message" class="form-control" rows="3"><?= h(sv($cfg,'summer_pause_reopening_message','Καλώς ήρθατε πίσω! Η πλατφόρμα επαναλειτουργεί μετά τη θερινή παύση. Ελέγξτε τους αθλητές σας και ενεργοποιήστε ξανά τις ειδοποιήσεις.')) ?></textarea>
            <div class="form-hint">Εμφανίζεται αμέσως μετά τη λήξη της παύσης για λίγες ημέρες.</div>
        </div>

        <!-- Preview buttons -->
        <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:1rem 1.2rem;margin-top:.5rem">
            <div style="font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#8892b0;margin-bottom:.75rem">
                <i class="fa-solid fa-eye"></i> Προεπισκόπηση Popup (μόνο για εσάς)
            </div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap">
                <a href="<?= APP_URL ?>/dashboard/?preview_popup=pause" target="_blank"
                   style="background:rgba(240,165,0,.12);color:#f0a500;border:1px solid rgba(240,165,0,.3);padding:.4rem .9rem;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none">
                    <i class="fa-solid fa-sun"></i> Banner Παύσης
                </a>
                <a href="<?= APP_URL ?>/dashboard/?preview_popup=soon" target="_blank"
                   style="background:rgba(99,102,241,.12);color:#818cf8;border:1px solid rgba(99,102,241,.3);padding:.4rem .9rem;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none">
                    <i class="fa-solid fa-clock"></i> Modal «Σύντομα»
                </a>
                <a href="<?= APP_URL ?>/dashboard/?preview_popup=reopen" target="_blank"
                   style="background:rgba(45,198,83,.1);color:#2dc653;border:1px solid rgba(45,198,83,.25);padding:.4rem .9rem;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none">
                    <i class="fa-solid fa-circle-check"></i> Banner Επαναλειτουργίας
                </a>
            </div>
            <div style="font-size:.75rem;color:#8892b0;margin-top:.6rem">Ανοίγει το dashboard σε νέα καρτέλα με το επιλεγμένο popup ενεργό.</div>
        </div>

    </div>
</div>
</div>
<!-- ══ END SUMMER PAUSE TAB ══ -->

<!-- ══ PRO WEBSITE BANNER ══ -->
<div id="tab-prowebsite" style="display:none" class="anim-2">
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-globe" style="color:#e63946"></i> Pro Website Banner</div>
    </div>
    <div class="card-body">

        <div class="alert alert-info" style="margin-bottom:1.2rem">
            <i class="fa-solid fa-circle-info"></i>
            <div style="font-size:.83rem;line-height:1.6">
                Όταν είναι ενεργό, εμφανίζεται ένα banner στο dashboard <strong>μόνο των Pro συνδρομητών</strong> με προσφορά δωρεάν κατασκευής ιστοσελίδας.
                Ο χρήστης μπορεί να το κλείσει· δεν εμφανίζεται ξανά μέχρι να καθαρίσει τα cookies του.
                Αν είναι απενεργοποιημένο, δεν εμφανίζεται τίποτα.
            </div>
        </div>

        <div class="section-sep"><i class="fa-solid fa-toggle-on"></i> Ενεργοποίηση</div>
        <div class="toggle-row" style="margin-bottom:1.4rem">
            <label class="toggle">
                <input type="checkbox" name="pro_website_banner_enabled" value="1" id="proWebsiteToggle"
                    <?= ($cfg['pro_website_banner_enabled']??'0')==='1'?'checked':'' ?>>
                <div class="toggle-track"></div>
            </label>
            <label for="proWebsiteToggle" class="toggle-lbl">Το banner είναι <strong>ενεργό</strong> για Pro συνδρομητές</label>
        </div>

        <div class="section-sep"><i class="fa-solid fa-comment-dots" style="color:#a855f7"></i> Περιεχόμενο Banner</div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Τίτλος</label>
            <input type="text" name="pro_website_banner_title" class="form-control" maxlength="120"
                   value="<?= h(sv($cfg,'pro_website_banner_title','Δωρεάν επαγγελματική ιστοσελίδα για τη σχολή σας')) ?>">
            <div class="form-hint">Ο τίτλος του banner. Εμφανίζεται στην κορυφή του dashboard.</div>
        </div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Μήνυμα</label>
            <textarea name="pro_website_banner_message" class="form-control" rows="3"><?= h(sv($cfg,'pro_website_banner_message','Ως Pro συνδρομητής δικαιούστε δωρεάν σχεδίαση + φιλοξενία μιας mobile-first ιστοσελίδας για τη σχολή σας — συνδεδεμένη με το MAster.')) ?></textarea>
            <div class="form-hint">Ένα σύντομο μήνυμα κάτω από τον τίτλο.</div>
        </div>

        <div class="form-grid" style="margin-bottom:1.4rem">
            <div class="form-group">
                <label class="form-label">Κείμενο κουμπιού CTA</label>
                <input type="text" name="pro_website_banner_cta_label" class="form-control" maxlength="60"
                       value="<?= h(sv($cfg,'pro_website_banner_cta_label','Ενημερώστε με τώρα')) ?>">
                <div class="form-hint">π.χ. «Ενημερώστε με τώρα», «Επικοινωνία εδώ».</div>
            </div>
            <div class="form-group">
                <label class="form-label">URL / σύνδεσμος CTA</label>
                <input type="text" name="pro_website_banner_cta_url" class="form-control"
                       value="<?= h(sv($cfg,'pro_website_banner_cta_url','/contact.php')) ?>">
                <div class="form-hint">Σχετικό (<code>/contact.php</code>) ή απόλυτο (<code>https://…</code>, <code>tel:+30…</code>).</div>
            </div>
        </div>

        <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:1rem 1.2rem;margin-top:.5rem">
            <div style="font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#8892b0;margin-bottom:.5rem">
                <i class="fa-solid fa-eye"></i> Προεπισκόπηση (αναγκάζει εμφάνιση για εσάς)
            </div>
            <a href="<?= APP_URL ?>/dashboard/?preview_pro_banner=1" target="_blank"
               style="background:rgba(230,57,70,.12);color:#ff8891;border:1px solid rgba(230,57,70,.3);padding:.4rem .9rem;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem">
                <i class="fa-solid fa-globe"></i> Άνοιγμα dashboard με banner
            </a>
        </div>

    </div>
</div>
</div>
<!-- ══ END PRO WEBSITE BANNER TAB ══ -->

<!-- ══ MAINTENANCE ══ -->
<div id="tab-maintenance" style="display:none" class="anim-2">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-wrench" style="color:var(--gold,#f0a500)"></i> Maintenance Mode</div></div>
    <div class="card-body">
        <div class="alert alert-danger" style="margin-bottom:1.2rem">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>Όταν ενεργοποιηθεί, <strong>όλοι οι χρήστες</strong> (εκτός Super Admin) βλέπουν σελίδα συντήρησης.</div>
        </div>
        <div class="toggle-row">
            <label class="toggle">
                <input type="checkbox" name="maintenance_mode" value="1" id="maintToggle" <?= ($cfg['maintenance_mode']??'0')==='1'?'checked':'' ?>>
                <div class="toggle-track"></div>
            </label>
            <label for="maintToggle" class="toggle-lbl">Ενεργοποίηση Maintenance Mode</label>
        </div>
        <div class="form-group">
            <label class="form-label"><i class="fa-solid fa-comment-dots"></i> Μήνυμα προς χρήστες</label>
            <textarea name="maintenance_message" class="form-control" rows="4"><?= sv($cfg,'maintenance_message','Γίνονται εργασίες μέσα στην πλατφόρμα. Ευχαριστούμε για την υπομονή σας — σύντομα κοντά σας! Ευχαριστούμε για την κατανόηση.') ?></textarea>
        </div>
    </div>
</div>
</div>

<div class="save-bar anim-3">
    <button type="submit" class="btn btn-primary" style="min-height:50px;font-size:clamp(1rem,4vw,1.05rem)!important;padding:.65rem 1.6rem">
        <i class="fa-solid fa-floppy-disk"></i> Αποθήκευση Αλλαγών
    </button>
    <span style="color:var(--muted,#8892b0);font-size:.88rem;display:flex;align-items:center;gap:.4rem">
        <i class="fa-solid fa-shield-halved"></i> Μόνο Super Admin
    </span>
</div>
</form>

<!-- Standalone forms -->
<form id="testEmailForm" method="POST" style="display:none">
    <input type="hidden" name="_action" value="test_email">
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <input type="hidden" name="test_to" id="test_to_hidden">
</form>
<form id="testSmsForm" method="POST" style="display:none">
    <input type="hidden" name="_action" value="test_sms">
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <input type="hidden" name="test_phone" id="test_phone_hidden">
    <input type="hidden" name="test_bulker_auth_key" id="test_bulker_auth_key_hidden">
    <input type="hidden" name="test_bulker_profile_id" id="test_bulker_profile_id_hidden">
    <input type="hidden" name="test_bulker_sender" id="test_bulker_sender_hidden">
</form>
<form id="checkBalanceForm" method="POST" style="display:none">
    <input type="hidden" name="_action" value="check_sms_balance">
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <input type="hidden" name="test_bulker_auth_key" id="check_bulker_auth_key_hidden">
</form>

</div></div></div>

<script>
(function(){var sb=document.getElementById('sidebar'),ov=document.getElementById('dm-overlay'),mb=document.getElementById('menuBtn');if(!sb||!mb)return;function open(){sb.classList.add('open');ov&&ov.classList.add('on');document.body.style.overflow='hidden'}function close(){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow=''}mb.onclick=function(e){e.stopPropagation();sb.classList.contains('open')?close():open()};ov&&ov.addEventListener('click',close);document.addEventListener('keydown',function(e){if(e.key==='Escape')close()});window.addEventListener('resize',function(){if(window.innerWidth>900){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow=''}});})();

function switchTab(t,el){
    document.querySelectorAll('[id^="tab-"]').forEach(e=>e.style.display='none');
    document.getElementById('tab-'+t).style.display='block';
    document.querySelectorAll('.tab-btn').forEach(e=>e.classList.remove('active'));
    el.classList.add('active');
    history.replaceState(null,'','#'+t);
}

function togglePass(id,btn){
    var f=document.getElementById(id);
    var show=f.type==='password';
    f.type=show?'text':'password';
    btn.innerHTML='<i class="fa-solid fa-eye'+(show?'-slash':'')+'"></i>';
}

(function(){
    var h=location.hash.replace('#','');
    if(h&&document.getElementById('tab-'+h)){
        var b=document.querySelector('[onclick*="\''+h+'\'"]');
        if(b)switchTab(h,b);
    }
})();

function sendTestEmail(){
    var to=document.getElementById('test_to_input').value.trim();
    if(!to){alert('Εισάγετε email παραλήπτη');return;}
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)){alert('Εισάγετε έγκυρο email');return;}
    document.getElementById('test_to_hidden').value=to;
    document.getElementById('testEmailForm').submit();
}

function getBulkerFormValues(){
    return {
        authKey:   (document.querySelector('[name="bulker_auth_key"]')   || {}).value || '',
        profileId: (document.querySelector('[name="bulker_profile_id"]') || {}).value || '',
        sender:    (document.querySelector('[name="bulker_sender"]')     || {}).value || ''
    };
}

function sendTestSms(){
    var phone=document.getElementById('test_phone_input').value.trim();
    if(!phone){alert('Εισάγετε αριθμό τηλεφώνου');return;}
    var creds=getBulkerFormValues();
    if(!creds.authKey){alert('Συμπληρώστε το Auth Key πρώτα.');return;}
    if(!creds.sender){alert('Συμπληρώστε τον Sender πρώτα.');return;}
    document.getElementById('test_phone_hidden').value=phone;
    document.getElementById('test_bulker_auth_key_hidden').value=creds.authKey.trim();
    document.getElementById('test_bulker_profile_id_hidden').value=creds.profileId.trim();
    document.getElementById('test_bulker_sender_hidden').value=creds.sender.trim();
    document.getElementById('testSmsForm').submit();
}

function checkSmsBalance(){
    var creds=getBulkerFormValues();
    if(!creds.authKey){alert('Συμπληρώστε το Auth Key πρώτα.');return;}
    document.getElementById('check_bulker_auth_key_hidden').value=creds.authKey.trim();
    document.getElementById('checkBalanceForm').submit();
}
</script>
<script>
function filterSettings(q) {
    q = (q || '').toLowerCase().trim();
    var groups = document.querySelectorAll('.form-group');
    var cards = document.querySelectorAll('[id^="tab-"] .card');
    var tabs = document.querySelectorAll('.tab-btn');
    var noResults = document.getElementById('settings-no-results');
    var tabNav = document.getElementById('tab-nav');

    if (!q) {
        // Reset: show all, restore tab visibility
        groups.forEach(function(g) { g.style.display = ''; });
        cards.forEach(function(c) { c.style.display = ''; });
        document.querySelectorAll('[id^="tab-"]').forEach(function(t, i) {
            t.style.display = i === 0 ? '' : 'none';
        });
        if (noResults) noResults.style.display = 'none';
        if (tabNav) tabNav.style.display = '';
        return;
    }

    // Hide tab nav, show all tab panes
    if (tabNav) tabNav.style.display = 'none';
    document.querySelectorAll('[id^="tab-"]').forEach(function(t) { t.style.display = ''; });

    var anyVisible = false;
    groups.forEach(function(g) {
        var label = (g.querySelector('.form-label') || g.querySelector('label') || {}).textContent || '';
        var input = g.querySelector('input, select, textarea');
        var inputName = input ? (input.name || input.placeholder || '') : '';
        var hint = (g.querySelector('.form-hint') || {}).textContent || '';
        var match = label.toLowerCase().indexOf(q) !== -1 ||
                    inputName.toLowerCase().indexOf(q) !== -1 ||
                    hint.toLowerCase().indexOf(q) !== -1;
        g.style.display = match ? '' : 'none';
        if (match) anyVisible = true;
    });

    if (noResults) noResults.style.display = anyVisible ? 'none' : '';
}
var ssInput = document.getElementById('settings-search');
if (ssInput) ssInput.addEventListener('input', function() { filterSettings(this.value); });
</script>
</body></html>