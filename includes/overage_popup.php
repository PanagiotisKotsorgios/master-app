<?php
/**
 * ============================================================
 * includes/overage_popup.php — SMS/Email Overage Payment Modal
 * ============================================================
 * PURPOSE:
 *   Εμφανίζει popup όταν σχολή υπερβεί το μηνιαίο όριο SMS/email.
 *   Ο χρήστης μπορεί να αγοράσει επιπλέον πακέτο μέσω IRIS/τράπεζα.
 *   Ο admin κρατά προμήθεια (configurable από superadmin panel).
 *
 * USAGE:
 *   require_once __DIR__ . '/../includes/overage_popup.php';
 *   renderOveragePopup(); // Καλείται στο <body> — εμφανίζει modal αν χρειάζεται
 *
 * SETTINGS (admin/system-settings.php → "Πακέτα SMS/Email"):
 *   monthly_sms_limit        — μηνιαίο όριο SMS ανά σχολή (0 = unlimited)
 *   monthly_email_limit      — μηνιαίο όριο email ανά σχολή (0 = unlimited)
 *   sms_overage_pack_qty     — ποσότητα SMS ανά πακέτο (default: 500)
 *   sms_overage_pack_price   — τιμή πακέτου SMS €
 *   email_overage_pack_qty   — ποσότητα email ανά πακέτο (default: 500)
 *   email_overage_pack_price — τιμή πακέτου email €
 *   overage_commission_pct   — % προμήθεια που κρατάει ο admin
 *   bank_name, bank_iban, bank_beneficiary, bank_receipt_email — τραπεζικά στοιχεία
 *
 * SECURITY:
 *   ✓ schoolId() isolation
 *   ✓ CSRF token
 *   ✓ Prepared statements
 * ============================================================
 */

require_once __DIR__ . '/usage_tracker.php';

function renderOveragePopup(): void {
    if (!isLoggedIn() || isSuperAdmin()) return;

    $sid      = schoolId();
    $usage    = getMonthlyUsage($sid);
    $smsLimit = $usage['sms_limit'];
    $emlLimit = $usage['email_limit'];

    $smsOver   = $smsLimit > 0 && $usage['sms']   >= $smsLimit;
    $emailOver = $emlLimit > 0 && $usage['email'] >= $emlLimit;

    if (!$smsOver && !$emailOver) return;

    // Settings
    $smsPackQty    = (int)getSetting('sms_overage_pack_qty', '500');
    $smsPackPrice  = (float)getSetting('sms_overage_pack_price', '10.00');
    $emlPackQty    = (int)getSetting('email_overage_pack_qty', '500');
    $emlPackPrice  = (float)getSetting('email_overage_pack_price', '5.00');
    $commPct       = (float)getSetting('overage_commission_pct', '20');
    $bankName      = getSetting('bank_name', '');
    $bankIban      = getSetting('bank_iban', '');
    $bankBenef     = getSetting('bank_beneficiary','');
    // School name (needed for reference hint)
    try {
        $snStmt = getDB()->prepare("SELECT name FROM schools WHERE id=?");
        $snStmt->execute([$sid]);
        $schoolDisplayName = $snStmt->fetchColumn() ?: APP_NAME;
    } catch (Exception $e) {
        $schoolDisplayName = APP_NAME;
    }

    $bankRefHint   = str_replace(
        ['{SCHOOL_ID}', '{SCHOOL_NAME}'],
        [(string)$sid,  $schoolDisplayName],
        getSetting('bank_reference_hint', 'MASTER-{SCHOOL_NAME}')
    );
    $bankEmail     = getSetting('bank_receipt_email','') ?: (function_exists('getMailFromEmail') ? getMailFromEmail() : '');
    $bankInstr     = getSetting('bank_instructions', '') ?: '';
    $irisPhone     = getSetting('iris_phone', '');
    $irisAfm       = getSetting('iris_afm', '');

    // Ποιο τύπο υπέρβαση εμφανίζουμε (SMS πρώτα, email δεύτερο)
    $overType     = $smsOver ? 'sms' : 'email';
    $packQty      = $overType === 'sms' ? $smsPackQty  : $emlPackQty;
    $packPrice    = $overType === 'sms' ? $smsPackPrice : $emlPackPrice;
    $usedCount    = $overType === 'sms' ? $usage['sms'] : $usage['email'];
    $limitCount   = $overType === 'sms' ? $smsLimit     : $emlLimit;
    $typeLabel    = $overType === 'sms' ? 'SMS' : 'Email';
    $commAmount   = round($packPrice * $commPct / 100, 2);
    $netAmount    = round($packPrice - $commAmount, 2);

    // Friendly reference: "Πακέτο SMS — Όνομα Σχολής"
    $refCode      = 'Πακέτο ' . $typeLabel . ' — ' . $schoolDisplayName;
    $emailSubject = 'Αγορά πακέτου ' . $typeLabel . ' — ' . $schoolDisplayName;

    ob_start();
    ?>
<!-- ══ OVERAGE POPUP MODAL ══════════════════════════════════════ -->
<style>
#overageModal{display:flex;position:fixed;inset:0;z-index:99999;align-items:flex-start;justify-content:center;background:rgba(0,0,0,.7);padding:1rem;overflow-y:auto}
#overageBox{background:#111520;border:1.5px solid #e63946;border-radius:16px;max-width:400px;width:100%;margin:auto;position:relative}
.ovR{display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.05);border:1px solid #1e2536;border-radius:7px;padding:.35rem .6rem;gap:.4rem}
.ovR span{font-size:.85rem;font-weight:700;color:#f0f2ff;font-family:monospace;word-break:break-all;flex:1}
.ovCp{background:none;border:none;color:#8892b0;cursor:pointer;font-size:.8rem;padding:.1rem .2rem;flex-shrink:0}
.ovLbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#8892b0;margin-bottom:.18rem}
</style>
<div id="overageModal">
<div id="overageBox">
  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.85rem 1rem .75rem;border-bottom:1px solid rgba(255,255,255,.07)">
    <div style="display:flex;align-items:center;gap:.6rem">
      <i class="fas <?= $overType === 'sms' ? 'fa-comment-sms' : 'fa-envelope' ?>" style="color:#e63946;font-size:1rem"></i>
      <div>
        <div style="font-size:.95rem;font-weight:800;color:#f0f2ff">Πακέτο Επέκτασης +<?= number_format($packQty) ?> <?= $typeLabel ?></div>
        <div style="font-size:.78rem;color:#8892b0"><?= number_format($usedCount) ?>/<?= number_format($limitCount) ?> <?= $typeLabel ?> · <strong style="color:#f0a500">€<?= number_format($packPrice, 2) ?></strong> συμπ. ΦΠΑ</div>
      </div>
    </div>
    <button onclick="document.getElementById('overageModal').style.display='none'"
            style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);color:#8892b0;border-radius:8px;width:32px;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.95rem">
      <i class="fas fa-xmark"></i>
    </button>
  </div>

  <!-- Body -->
  <div style="padding:.9rem 1rem">
    <!-- Payment details -->
    <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#8892b0;margin-bottom:.5rem">
      <i class="fas fa-building-columns" style="color:#3b82f6"></i> Πληρωμή — IRIS / Τραπεζική Μεταφορά
    </div>
    <div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:.75rem">
      <?php if ($bankBenef): ?>
      <div><div class="ovLbl">Δικαιούχος</div>
        <div class="ovR"><span><?= h($bankBenef) ?></span><button class="ovCp" onclick="copyOvg('<?= h($bankBenef) ?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
      <?php endif; ?>
      <?php if ($bankName): ?>
      <div><div class="ovLbl">Τράπεζα</div>
        <div class="ovR"><span><?= h($bankName) ?></span></div>
      </div>
      <?php endif; ?>
      <?php if ($bankIban): ?>
      <div><div class="ovLbl">IBAN</div>
        <div class="ovR"><span><?= h($bankIban) ?></span><button class="ovCp" onclick="copyOvg('<?= h(str_replace(' ','',$bankIban)) ?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
      <?php endif; ?>
      <?php if ($irisPhone): ?>
      <div><div class="ovLbl"><i class="fas fa-bolt" style="color:#f0a500"></i> IRIS — Τηλέφωνο</div>
        <div class="ovR"><span><?= h($irisPhone) ?></span><button class="ovCp" onclick="copyOvg('<?= h($irisPhone) ?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
      <?php endif; ?>
      <?php if ($irisAfm): ?>
      <div><div class="ovLbl"><i class="fas fa-bolt" style="color:#f0a500"></i> IRIS — ΑΦΜ</div>
        <div class="ovR"><span><?= h($irisAfm) ?></span><button class="ovCp" onclick="copyOvg('<?= h($irisAfm) ?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
      <?php endif; ?>
      <div><div class="ovLbl">Ποσό</div>
        <div class="ovR"><span style="color:#f0a500">€<?= number_format($packPrice, 2) ?></span><button class="ovCp" onclick="copyOvg('<?= number_format($packPrice, 2) ?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
      <div><div class="ovLbl">Αιτιολογία</div>
        <div class="ovR"><span><?= h($refCode) ?></span><button class="ovCp" onclick="copyOvg('<?= h($refCode) ?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
    </div>

    <?php if ($bankEmail): ?>
    <div style="font-size:.78rem;color:#fcd34d;background:rgba(240,165,0,.07);border:1px solid rgba(240,165,0,.18);border-radius:8px;padding:.55rem .75rem;margin-bottom:.75rem;line-height:1.5">
      <i class="fas fa-envelope"></i> Στείλτε αποδεικτικό στο <strong style="color:#fff"><?= h($bankEmail) ?></strong><br>
      <strong>Θέμα:</strong> <strong style="color:#fff"><?= h($emailSubject) ?></strong><br>
      <span style="color:#a78bfa">Αιτιολογία πληρωμής: <?= h($refCode) ?></span> · Ενεργοποίηση εντός 24ω.
    </div>
    <a href="mailto:<?= h($bankEmail) ?>?subject=<?= urlencode($emailSubject) ?>&body=<?= urlencode("Επισυνάπτω αποδεικτικό πληρωμής για αγορά πακέτου {$typeLabel}.\n\nΑιτιολογία: {$refCode}\nΠοσό: €" . number_format($packPrice, 2) . "\nΣύλλογος: {$schoolDisplayName}") ?>"
       style="display:block;width:100%;text-align:center;font-weight:800;font-size:.9rem;padding:.7rem 1rem;border-radius:10px;text-decoration:none;background:rgba(59,130,246,.15);color:#93c5fd;border:1.5px solid rgba(59,130,246,.35);margin-bottom:.5rem">
      <i class="fas fa-paper-plane"></i> Στείλε Αποδεικτικό μέσω Email
    </a>
    <?php endif; ?>

    <button onclick="document.getElementById('overageModal').style.display='none'"
            style="display:block;width:100%;text-align:center;font-weight:600;font-size:.85rem;padding:.6rem 1rem;border-radius:10px;background:transparent;color:#8892b0;border:1px solid rgba(255,255,255,.1);cursor:pointer">
      Θα το κάνω αργότερα
    </button>
  </div>
</div>
</div>

<script>
function copyOvg(text, btn) {
    navigator.clipboard.writeText(text).then(function() {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check" style="color:#2dc653"></i>';
        setTimeout(function() { btn.innerHTML = orig; }, 1500);
    });
}
</script>
<?php
    echo ob_get_clean();
}
