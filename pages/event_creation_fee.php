<?php
/**
 * pages/event_creation_fee.php — Organiser pays the €50 creation fee.
 *
 * Only relevant when the event has creation_fee_amount > 0 and
 * creation_fee_status != 'waived'. Otherwise redirects back to
 * event manage.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();
$sid = schoolId();
$id  = (int)($_GET['id'] ?? 0);
$ev  = eventGet($id);
if (!$ev || (int)$ev['organiser_school_id'] !== $sid) {
    flash('Το event δεν βρέθηκε ή δεν έχετε δικαίωμα.', 'error');
    redirect(APP_URL . '/pages/events.php');
}

if (!eventRequiresCreationFee($ev)) {
    flash('Το event δεν απαιτεί fee δημιουργίας.', 'info');
    redirect(APP_URL . '/pages/event_manage.php?id=' . (int)$id);
}

// Ensure a pending payment row exists so we always have somewhere to attach proof.
$feeAmount = (float)$ev['creation_fee_amount'];
$paymentId = eventCreationFeeCreatePayment($id, $sid, $feeAmount);
$payment   = eventPaymentGet($paymentId);

// ── POST: upload proof ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        if (empty($_FILES['proof']['tmp_name'])) {
            throw new RuntimeException('Επιλέξτε αρχείο αποδεικτικού πληρωμής.');
        }
        $rel = eventUploadStore($_FILES['proof'], $id, 'private', ['pdf','jpg','jpeg','png','webp'], 8000);
        eventPaymentAttachProof($paymentId, $sid, $rel);
        eventCreationFeeSyncFromPayment($id);
        flash('Το αποδεικτικό ανέβηκε. Ο διαχειριστής θα το επιβεβαιώσει σύντομα.', 'success');
        redirect(APP_URL . '/pages/event_creation_fee.php?id=' . (int)$id);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
    }
}

$payment = eventPaymentGet($paymentId); // reload after any update

$statusLabels = [
    'waived'         => ['Δεν απαιτείται', '#c9cee1'],
    'unpaid'         => ['Εκκρεμεί πληρωμή', '#ffd870'],
    'proof_uploaded' => ['Αποδεικτικό ανέβηκε — έλεγχος', '#a9c1ff'],
    'verified'       => ['Επιβεβαιωμένο', '#7bffb4'],
];
[$stLbl, $stCol] = $statusLabels[$ev['creation_fee_status']] ?? ['—', '#c9cee1'];

renderHead('Fee δημιουργίας: ' . $ev['title']);
$flash = getFlash();
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Fee δημιουργίας event'); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <div style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.12);border:1px solid rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.35rem 1.5rem;margin-bottom:1rem">
    <div style="display:flex;align-items:center;gap:.7rem;margin-bottom:.5rem">
      <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#e63946,#c72832);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem">
        <i class="fa-solid fa-file-invoice-dollar"></i>
      </div>
      <div>
        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#e63946;font-weight:700">Fee δημιουργίας event</div>
        <h1 style="margin:0;font-size:1.25rem;color:#f0f2ff"><?= h($ev['title']) ?></h1>
      </div>
    </div>
    <p style="color:#8892b0;font-size:.9rem;line-height:1.55;margin-top:.6rem">
      Για να δημοσιευθεί το event χρειάζεται εφάπαξ πληρωμή <strong style="color:#f0f2ff"><?= number_format($feeAmount, 2, ',', '.') ?> €</strong>.
      Πληρώστε με τραπεζικό έμβασμα και ανεβάστε το αποδεικτικό εδώ.
    </p>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.25rem">
      <div style="color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:.3rem">Ποσό</div>
      <div style="font-size:1.4rem;font-weight:800"><?= number_format($feeAmount, 2, ',', '.') ?> €</div>
    </div>
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.25rem">
      <div style="color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:.3rem">Κατάσταση</div>
      <div style="font-size:1rem;font-weight:800;color:<?= $stCol ?>"><?= h($stLbl) ?></div>
    </div>
  </div>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.35rem 1.5rem;margin-bottom:1rem">
    <h3 style="margin:0 0 .5rem;font-size:1rem;font-weight:800">Στοιχεία τραπέζης</h3>
    <table style="width:100%;font-size:.9rem;color:#c9cee1">
      <tr><td style="padding:.3rem 0;color:#8892b0">Τράπεζα</td><td style="padding:.3rem 0"><?= h(getBankName() ?: 'Θα δοθεί σε επόμενο βήμα') ?></td></tr>
      <tr><td style="padding:.3rem 0;color:#8892b0">IBAN</td><td style="padding:.3rem 0;font-family:monospace"><?= h(getBankIban() ?: '—') ?></td></tr>
      <tr><td style="padding:.3rem 0;color:#8892b0">Δικαιούχος</td><td style="padding:.3rem 0"><?= h(getBankBeneficiary() ?: '—') ?></td></tr>
      <tr><td style="padding:.3rem 0;color:#8892b0">Αιτιολογία</td><td style="padding:.3rem 0;font-family:monospace"><?= h($payment['reference_code'] ?? ('MASTER-EV' . (int)$id . '-CREATION')) ?></td></tr>
    </table>
  </div>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.35rem 1.5rem">
    <h3 style="margin:0 0 .8rem;font-size:1rem;font-weight:800">Αποδεικτικό πληρωμής</h3>
    <?php if (!empty($payment['proof_file_path'])): ?>
      <div style="padding:.75rem 1rem;background:rgba(78,132,255,.1);border:1px solid rgba(78,132,255,.3);border-radius:10px;margin-bottom:.75rem;color:#a9c1ff">
        <i class="fa-regular fa-file-lines"></i> Αρχείο ήδη ανεβασμένο. Μπορείτε να ανεβάσετε νέο για αντικατάσταση.
      </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center">
      <?= csrfField() ?>
      <input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp" required
             style="flex:1;min-width:220px;padding:.55rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#c9cee1">
      <button type="submit"
              style="padding:.6rem 1.1rem;border-radius:8px;background:linear-gradient(135deg,#e63946,#c72832);color:#fff;border:none;font-weight:700;cursor:pointer;font-family:inherit">
        <i class="fa-solid fa-upload"></i> Ανέβασμα
      </button>
    </form>
    <p style="color:#6b7494;font-size:.78rem;margin-top:.6rem">PDF, JPG, PNG ή WEBP · έως 8 MB</p>
  </div>

  <div style="margin-top:1.25rem">
    <a href="<?= APP_URL ?>/pages/event_manage.php?id=<?= (int)$id ?>"
       style="color:#8892b0;font-size:.9rem;text-decoration:none">
      <i class="fa-solid fa-arrow-left"></i> Πίσω στη διαχείριση event
    </a>
  </div>

</div>
</div>
</div>
</body>
</html>
