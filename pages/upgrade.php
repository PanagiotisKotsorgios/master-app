<?php

/**
 * ============================================================
 * pages/upgrade.php — Αναβάθμιση Πλάνου
 * ============================================================
 * PURPOSE:
 *   Εμφανίζει pricing page με οδηγίες πληρωμής μέσω
 *   τραπεζικής μεταφοράς / IRIS (static flow).
 *   Μετά την πληρωμή, ο χρήστης στέλνει αποδεικτικό στο
 *   email του admin, ο οποίος ενεργοποιεί χειροκίνητα.
 *
 * SECURITY:
 *   ✓ requireLogin()
 *   ✓ h() για output
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
if (isSuperAdmin()) redirect(APP_URL . '/admin/');

$db     = getDB();
$sid    = schoolId();
$school = $db->prepare("SELECT * FROM schools WHERE id=?");
$school->execute([$sid]);
$school = $school->fetch();

$currentPlan = schoolPlan();
$currentSlug = $currentPlan['slug'] ?? 'basic';

if ($currentSlug === 'pro') {
    redirect(APP_URL . '/pages/settings.php?tab=plan');
}

$billing  = $_GET['billing'] ?? 'monthly';
$isAnnual = $billing === 'annual';

// Στοιχεία τραπεζικής μεταφοράς από ρυθμίσεις
$bankName        = getBankName();
$bankIban        = getBankIban();
$bankBeneficiary = getBankBeneficiary();
$bankRefHint     = str_replace(
    ['{SCHOOL_ID}', '{SCHOOL_NAME}'],
    [$sid,          $school['name'] ?? ''],
    getBankReference()
);
$bankReceiptEmail= getBankReceiptEmail();
$bankInstructions= getBankInstructions();
$hasBankDetails  = $bankName && $bankIban;
$irisAfm         = getIrisAfm();
$irisPhone       = getIrisPhone();
$hasIris         = $irisAfm || $irisPhone;

renderHead('Αναβάθμιση σε Pro');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg: #07090f; --card: #111520; --border: #2a3248;
  --red: #e63946; --gold: #f0a500; --white: #f0f2ff; --muted: #8892b0; --green: #2dc653;
  --blue: #3b82f6; --iris: #6b5ce7;
}
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg); color: var(--white);
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 2rem;
}
.wrap { max-width: 540px; width: 100%; }

h1 { font-size: clamp(1.6rem,5vw,2.2rem); font-weight: 800; text-align: center; margin-bottom: .4rem; }
.sub { text-align: center; color: var(--muted); margin-bottom: 2rem; font-size: clamp(.9rem,3vw,1rem); line-height: 1.6; }
.sub strong { color: var(--white); }

/* ── Billing toggle ── */
.toggle-wrap {
  display: flex; align-items: center; justify-content: center;
  gap: .75rem; margin-bottom: 2rem; flex-wrap: wrap;
}
.toggle-track {
  width: 56px; height: 30px;
  background: var(--border);
  border-radius: 50px; position: relative;
  cursor: pointer; border: none;
  transition: background .25s;
}
.toggle-track.annual { background: var(--gold); }
.toggle-knob {
  position: absolute; top: 4px; left: 4px;
  width: 22px; height: 22px;
  background: #fff; border-radius: 50%;
  transition: transform .28s cubic-bezier(.2,.8,.2,1);
  pointer-events: none;
}
.toggle-track.annual .toggle-knob { transform: translateX(26px); }
.toggle-lbl { font-size: clamp(.92rem,3.5vw,1rem); font-weight: 700; color: var(--muted); transition: color .2s; }
.toggle-lbl.on { color: var(--white); }
.save-badge {
  background: rgba(45,198,83,.15); color: var(--green);
  font-size: .72rem; font-weight: 800;
  padding: .25rem .7rem; border-radius: 50px;
  border: 1px solid rgba(45,198,83,.3);
}

/* ── Pro card ── */
.pro-card {
  background: var(--card);
  border: 2px solid var(--gold);
  border-radius: 24px;
  padding: 2rem 1.75rem 1.5rem;
  position: relative;
  overflow: hidden;
  box-shadow: 0 0 0 4px rgba(240,165,0,.1), 0 24px 60px rgba(0,0,0,.5);
}
.plan-ribbon {
  position: absolute; top: 20px; left: -32px;
  background: var(--gold); color: #111;
  font-size: .6rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .06em;
  padding: .25rem 3rem;
  transform: rotate(-45deg);
}
.plan-tag {
  display: inline-flex; align-items: center; gap: .35rem;
  font-size: .72rem; font-weight: 800; text-transform: uppercase;
  letter-spacing: .07em; padding: .25rem .75rem;
  border-radius: 8px; margin-bottom: .75rem;
  background: rgba(240,165,0,.15); color: var(--gold);
}
.plan-name { font-size: clamp(1.6rem,5vw,2rem); font-weight: 800; color: var(--gold); margin-bottom: .25rem; }
.plan-desc { font-size: clamp(.88rem,3vw,.95rem); color: var(--muted); margin-bottom: 1.25rem; line-height: 1.5; }
.price-wrap { display: flex; align-items: flex-end; gap: .2rem; margin-bottom: .3rem; }
.price-currency { font-size: 1.5rem; font-weight: 800; color: var(--gold); line-height: 1.7; }
.price-amount   { font-size: clamp(3rem,8vw,3.8rem); font-weight: 800; color: var(--gold); line-height: 1; }
.price-period   { font-size: 1rem; font-weight: 400; color: var(--muted); line-height: 2.4; }
.price-annual-note { font-size: .9rem; font-weight: 700; color: var(--green); min-height: 1.4em; margin-bottom: 1.5rem; }

/* ── Features ── */
.feat-divider { height: 1px; background: rgba(255,255,255,.07); margin: 1.25rem 0; }
.feats { list-style: none; display: flex; flex-direction: column; gap: .55rem; margin-bottom: 1.25rem; }
.feats li { display: flex; align-items: center; gap: .6rem; font-size: clamp(.9rem,3.5vw,.97rem); color: var(--white); }
.feats li .fi {
  width: 20px; height: 20px; border-radius: 50%;
  background: rgba(45,198,83,.15); border: 1px solid rgba(45,198,83,.3);
  display: flex; align-items: center; justify-content: center;
  color: var(--green); font-size: .65rem; flex-shrink: 0;
}

/* ── Payment method selector ── */
.pay-methods-title {
  font-size: .72rem; font-weight: 800; text-transform: uppercase;
  letter-spacing: .08em; color: var(--muted); margin-bottom: .65rem;
}
.pay-methods { display: flex; flex-direction: column; gap: .65rem; margin-bottom: 1.2rem; }
.pay-method-btn {
  display: flex; align-items: center; gap: .85rem;
  width: 100%; padding: .9rem 1.1rem;
  background: rgba(255,255,255,.04);
  border: 1.5px solid var(--border);
  border-radius: 14px;
  cursor: pointer;
  color: var(--white);
  font-family: inherit;
  transition: all .18s;
  text-align: left;
  text-decoration: none;
}
.pay-method-btn:hover {
  border-color: var(--gold);
  background: rgba(240,165,0,.06);
  transform: translateY(-1px);
}
.pay-method-btn.iris:hover { border-color: var(--iris); background: rgba(107,92,231,.08); }
.pay-method-btn.bank:hover { border-color: var(--blue); background: rgba(59,130,246,.08); }
.pay-method-btn:disabled {
  opacity: .55; cursor: not-allowed; transform: none;
}
.pay-method-icon {
  width: 42px; height: 42px; border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem; flex-shrink: 0;
}
.pay-method-icon.iris { background: rgba(107,92,231,.18); color: var(--iris); }
.pay-method-icon.bank { background: rgba(59,130,246,.15); color: var(--blue); }
.pay-method-meta { flex: 1; }
.pay-method-label { font-size: .97rem; font-weight: 700; display: block; margin-bottom: .15rem; }
.pay-method-desc  { font-size: .78rem; color: var(--muted); display: block; line-height: 1.45; }
.pay-method-badge {
  font-size: .65rem; font-weight: 800; padding: .18rem .55rem;
  border-radius: 50px; flex-shrink: 0;
}
.badge-instant { background: rgba(45,198,83,.15); color: var(--green); border: 1px solid rgba(45,198,83,.3); }
.badge-manual  { background: rgba(240,165,0,.12); color: var(--gold); border: 1px solid rgba(240,165,0,.25); }

/* ── Bank details panel ── */
.bank-panel {
  display: none;
  background: rgba(59,130,246,.06);
  border: 1.5px solid rgba(59,130,246,.25);
  border-radius: 14px;
  padding: 1.1rem 1.2rem;
  margin-bottom: 1rem;
}
.bank-panel.open { display: block; }
.bank-panel h4 {
  font-size: .82rem; font-weight: 800; text-transform: uppercase;
  letter-spacing: .07em; color: var(--blue); margin-bottom: .9rem;
  display: flex; align-items: center; gap: .4rem;
}
.bank-row { margin-bottom: .7rem; }
.bank-row label { font-size: .72rem; font-weight: 700; color: var(--muted); display: block; margin-bottom: .2rem; text-transform: uppercase; letter-spacing: .06em; }
.bank-value {
  font-size: .93rem; font-weight: 700; color: var(--white);
  background: rgba(255,255,255,.05);
  border: 1px solid var(--border);
  border-radius: 8px; padding: .45rem .7rem;
  display: flex; align-items: center; justify-content: space-between; gap: .5rem;
  font-family: 'Courier New', monospace;
  letter-spacing: .04em;
}
.bank-value.mono { font-family: 'Courier New', monospace; font-size: .88rem; }
.copy-btn {
  background: none; border: none; color: var(--muted);
  cursor: pointer; padding: .1rem .2rem; border-radius: 4px;
  font-size: .82rem; transition: color .15s;
  flex-shrink: 0;
}
.copy-btn:hover { color: var(--white); }
.bank-instructions {
  font-size: .82rem; color: var(--muted); line-height: 1.6;
  background: rgba(255,255,255,.03);
  border-radius: 8px; padding: .65rem .85rem;
  margin-top: .8rem; white-space: pre-line;
}
.bank-confirm-btn {
  display: block; width: 100%;
  text-align: center; font-weight: 800;
  font-size: clamp(.95rem,3.5vw,1rem);
  padding: .85rem 1rem; border-radius: 14px; border: none;
  cursor: pointer; margin-top: .85rem;
  background: rgba(59,130,246,.18);
  color: #93c5fd;
  border: 1.5px solid rgba(59,130,246,.4);
  transition: all .18s; font-family: inherit;
  text-decoration: none; display: block;
}
.bank-confirm-btn:hover { background: rgba(59,130,246,.28); border-color: var(--blue); color: #fff; }

/* Trust line */
.trust {
  display: flex; align-items: center; justify-content: center;
  gap: 1.25rem; margin-top: 1.5rem; flex-wrap: wrap;
  font-size: clamp(.76rem,3vw,.82rem); color: var(--muted);
}
.trust span { display: flex; align-items: center; gap: .35rem; }
.back-link {
  display: block; text-align: center; margin-top: 1rem;
  color: var(--muted); font-size: .88rem; text-decoration: none;
  transition: color .15s;
}
.back-link:hover { color: var(--white); }

@media (max-width: 560px) {
  body { padding: 1rem; align-items: flex-start; padding-top: 4rem; }
  .pro-card { padding: 1.5rem 1.25rem; }
}
</style>

<a href="<?= APP_URL ?>" style="position:fixed;top:1rem;left:1rem;display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.07);border:1.5px solid var(--border);color:var(--white);text-decoration:none;padding:.55rem 1rem;border-radius:10px;font-size:.9rem;font-weight:700;transition:all .2s;z-index:999"
  onmouseover="this.style.background='rgba(255,255,255,.13)'" onmouseout="this.style.background='rgba(255,255,255,.07)'">
  <i class="fas fa-arrow-left"></i> Κεντρική
</a>

<div class="wrap">
  <h1>⭐ Αναβαθμίστε σε Pro</h1>
  <p class="sub">Ξεκλειδώστε <strong>όλες τις δυνατότητες</strong> του MAster<br>και αυτοματοποιήστε πλήρως τον σύλλογό σας.</p>

  <!-- Billing Toggle -->
  <div class="toggle-wrap">
    <span class="toggle-lbl <?= !$isAnnual ? 'on' : '' ?>" id="lbl-m">Μηνιαία</span>
    <button class="toggle-track <?= $isAnnual ? 'annual' : '' ?>" id="billingToggle" type="button" onclick="toggleBilling()" aria-label="Εναλλαγή χρέωσης">
      <div class="toggle-knob"></div>
    </button>
    <span class="toggle-lbl <?= $isAnnual ? 'on' : '' ?>" id="lbl-a">Ετήσια</span>
    <span class="save-badge"><i class="fas fa-tag" style="font-size:.65rem"></i> Γλιτώστε €60/έτος</span>
  </div>

  <!-- Pro card -->
  <div class="pro-card">
    <div class="plan-ribbon">Δημοφιλές</div>

    <span class="plan-tag"><i class="fas fa-star" style="font-size:.65rem"></i> Pro</span>
    <div class="plan-name">Pro</div>
    <div class="plan-desc">Πλήρης αυτοματοποίηση και διαχείριση για σοβαρούς συλλόγους</div>

    <div class="price-wrap">
      <span class="price-currency">€</span>
      <span class="price-amount" id="price-amount"><?= $isAnnual ? '20' : '25' ?></span>
      <span class="price-period">/μήνα</span>
    </div>
    <div style="font-size:.78rem;color:var(--muted);letter-spacing:.04em;margin-top:-.15rem;margin-bottom:.55rem">συμπ. ΦΠΑ</div>
    <div class="price-annual-note" id="annual-note">
      <?= $isAnnual ? '€240/έτος — γλιτώστε €60 σε σχέση με μηνιαία' : '&nbsp;' ?>
    </div>

    <!-- Features -->
    <div style="font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.65rem">
      <i class="fas fa-arrow-up" style="color:var(--gold)"></i> Τι κερδίζετε με Pro
    </div>
    <ul class="feats">
      <li><span class="fi"><i class="fas fa-check"></i></span> <strong>Απεριόριστοι</strong> αθλητές</li>
      <li><span class="fi"><i class="fas fa-check"></i></span> SMS υπενθυμίσεις &amp; ειδοποιήσεις</li>
      <li><span class="fi"><i class="fas fa-check"></i></span> Πλήρη οικονομικά &amp; αναφορές</li>
      <li><span class="fi"><i class="fas fa-check"></i></span> Γραφήματα &amp; dashboards</li>
      <li><span class="fi"><i class="fas fa-check"></i></span> Προτεραιότητα υποστήριξης</li>
    </ul>

    <div class="feat-divider"></div>

    <!-- ══ Payment Instructions ══ -->
    <div class="pay-methods-title"><i class="fas fa-building-columns" style="color:var(--blue)"></i> Τρόπος Πληρωμής — IRIS / Τραπεζική Μεταφορά</div>

    <?php if ($hasIris): ?>
    <!-- IRIS panel -->
    <div style="background:rgba(107,92,231,.07);border:1.5px solid rgba(107,92,231,.3);border-radius:14px;padding:1.1rem 1.2rem;margin-bottom:.85rem">
      <h4 style="font-size:.82rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--iris);margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem">
        <i class="fas fa-bolt"></i> Πληρωμή μέσω IRIS
      </h4>
      <div style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem;line-height:1.55">
        Ανοίξτε την εφαρμογή της τράπεζάς σας, επιλέξτε <strong style="color:var(--white)">Πληρωμή μέσω IRIS</strong> και αναζητήστε:
      </div>
      <?php if ($irisPhone): ?>
      <div class="bank-row">
        <label>Τηλέφωνο IRIS</label>
        <div class="bank-value mono">
          <span><?= h($irisPhone) ?></span>
          <button class="copy-btn" onclick="copyText('<?= h($irisPhone) ?>',this)" title="Αντιγραφή"><i class="fas fa-copy"></i></button>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($irisAfm): ?>
      <div class="bank-row">
        <label>ΑΦΜ IRIS</label>
        <div class="bank-value mono">
          <span><?= h($irisAfm) ?></span>
          <button class="copy-btn" onclick="copyText('<?= h($irisAfm) ?>',this)" title="Αντιγραφή"><i class="fas fa-copy"></i></button>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($bankBeneficiary): ?>
      <div class="bank-row">
        <label>Δικαιούχος</label>
        <div class="bank-value"><span><?= h($bankBeneficiary) ?></span></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Bank details panel (always visible) -->
    <div class="bank-panel open" id="bankPanel">
      <h4><i class="fas fa-building-columns"></i> Στοιχεία Τραπεζικού Λογαριασμού</h4>

      <?php if ($hasBankDetails): ?>

        <?php if ($bankBeneficiary): ?>
        <div class="bank-row">
          <label>Δικαιούχος</label>
          <div class="bank-value">
            <span><?= h($bankBeneficiary) ?></span>
            <button class="copy-btn" onclick="copyText('<?= h($bankBeneficiary) ?>',this)" title="Αντιγραφή"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <?php endif; ?>

        <div class="bank-row">
          <label>Τράπεζα</label>
          <div class="bank-value"><span><?= h($bankName) ?></span></div>
        </div>

        <div class="bank-row">
          <label>IBAN</label>
          <div class="bank-value mono">
            <span><?= h($bankIban) ?></span>
            <button class="copy-btn" onclick="copyText('<?= h(str_replace(' ','',$bankIban)) ?>',this)" title="Αντιγραφή IBAN"><i class="fas fa-copy"></i></button>
          </div>
        </div>

        <div class="bank-row">
          <label>Ποσό <span id="bank-amount-label"><?= $isAnnual ? '(€240 ετήσια)' : '(€25 μηνιαία)' ?></span></label>
          <div class="bank-value">
            <span id="bank-amount-val"><?= $isAnnual ? '240,00 €' : '25,00 €' ?></span>
            <button class="copy-btn" onclick="copyText(document.getElementById('bank-amount-raw').value,this)" title="Αντιγραφή"><i class="fas fa-copy"></i></button>
            <input type="hidden" id="bank-amount-raw" value="<?= $isAnnual ? '240' : '25' ?>">
          </div>
        </div>

        <?php if ($bankRefHint): ?>
        <div class="bank-row">
          <label>Αιτιολογία / Κωδικός αναφοράς</label>
          <div class="bank-value mono">
            <span><?= h($bankRefHint) ?></span>
            <button class="copy-btn" onclick="copyText('<?= h($bankRefHint) ?>',this)" title="Αντιγραφή"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($bankInstructions): ?>
        <div class="bank-instructions"><?= h($bankInstructions) ?></div>
        <?php endif; ?>

        <div style="background:rgba(240,165,0,.08);border:1px solid rgba(240,165,0,.2);border-radius:9px;padding:.7rem .9rem;margin-top:.85rem;font-size:.82rem;color:#fcd34d;line-height:1.55">
          <i class="fas fa-envelope" style="margin-right:.4rem"></i>
          Μετά την πληρωμή, στείλτε το αποδεικτικό στο
          <strong style="color:#fff"><?= h($bankReceiptEmail) ?></strong>
          και ο λογαριασμός σας θα ενεργοποιηθεί εντός 24 ωρών.
        </div>

        <a href="mailto:<?= h($bankReceiptEmail) ?>?subject=Απόδειξη+πληρωμής+MAster+-+<?= h($school['name'] ?? '') ?>&body=Επισυνάπτω+το+αποδεικτικό+τραπεζικής+μεταφοράς+για+τον+λογαριασμό+μου+στο+MAster.%0A%0AΚωδικός+αναφοράς:+<?= urlencode($bankRefHint) ?>"
           class="bank-confirm-btn">
          <i class="fas fa-paper-plane"></i> Στείλτε το αποδεικτικό μέσω Email
        </a>

      <?php else: ?>
        <div style="color:var(--muted);font-size:.87rem;text-align:center;padding:1rem 0">
          <i class="fas fa-circle-info" style="color:var(--blue)"></i>
          Τα στοιχεία τραπεζικής μεταφοράς δεν έχουν ρυθμιστεί ακόμα.<br>
          Επικοινωνήστε μαζί μας στο <strong><?= h($bankReceiptEmail) ?></strong>
        </div>
      <?php endif; ?>
    </div>

  </div><!-- /pro-card -->

  <!-- Trust indicators -->
  <div class="trust">
    <span><i class="fas fa-shield-halved" style="color:var(--green)"></i> Ασφαλείς πληρωμές</span>
    <span><i class="fas fa-building-columns" style="color:var(--blue)"></i> IRIS / Τραπεζική μεταφορά</span>
    <span><i class="fas fa-clock" style="color:var(--gold)"></i> Ενεργοποίηση εντός 24ω</span>
  </div>

  <a href="<?= APP_URL ?>/dashboard/" class="back-link">← Επιστροφή στο dashboard</a>

</div><!-- /wrap -->

<script>
var isAnnual = <?= $isAnnual ? 'true' : 'false' ?>;

var PRICES = {
  monthly: { amount: '25', display: '25,00 €', raw: '25', note: '&nbsp;',                                        label: '(€25 μηνιαία)' },
  annual:  { amount: '20', display: '240,00 €', raw: '240', note: '€240/έτος — γλιτώστε €60 σε σχέση με μηνιαία', label: '(€240 ετήσια)' }
};

function toggleBilling() {
  isAnnual = !isAnnual;
  var track = document.getElementById('billingToggle');
  track.classList.toggle('annual', isAnnual);
  document.getElementById('lbl-m').classList.toggle('on', !isAnnual);
  document.getElementById('lbl-a').classList.toggle('on',  isAnnual);
  updatePrices();
}

function updatePrices() {
  var d = isAnnual ? PRICES.annual : PRICES.monthly;
  document.getElementById('price-amount').textContent = d.amount;
  document.getElementById('annual-note').innerHTML    = d.note;
  var bankAmtVal = document.getElementById('bank-amount-val');
  var bankAmtRaw = document.getElementById('bank-amount-raw');
  var bankAmtLbl = document.getElementById('bank-amount-label');
  if (bankAmtVal) bankAmtVal.textContent = d.display;
  if (bankAmtRaw) bankAmtRaw.value       = d.raw;
  if (bankAmtLbl) bankAmtLbl.textContent = d.label;
}

function copyText(text, btn) {
  navigator.clipboard.writeText(text).then(function() {
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check" style="color:var(--green)"></i>';
    setTimeout(function() { btn.innerHTML = orig; }, 1500);
  });
}
</script>
</body></html>