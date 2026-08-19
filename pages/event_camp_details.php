<?php
/**
 * pages/event_camp_details.php — Camp-specific fields per registration.
 *
 * URL: /pages/event_camp_details.php?reg_id=N
 * Access: the registering school of that registration OR the
 * event organiser (both need it for logistics).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();
$sid   = schoolId();
$regId = (int)($_GET['reg_id'] ?? 0);

// Load the registration + event
$db = getDB();
$rst = $db->prepare("SELECT r.*, e.title AS event_title, e.type AS event_type,
                            e.organiser_school_id, e.starts_at AS event_starts_at
                       FROM event_registrations r
                       JOIN events e ON e.id = r.event_id
                      WHERE r.id = ? LIMIT 1");
$rst->execute([$regId]);
$reg = $rst->fetch();

if (!$reg) {
    flash('Η εγγραφή δεν βρέθηκε.', 'error');
    redirect(APP_URL . '/pages/events.php');
}
$isRegisteringSchool = ((int)$reg['registering_school_id'] === $sid);
$isOrganiser         = ((int)$reg['organiser_school_id']   === $sid);
if (!$isRegisteringSchool && !$isOrganiser && !isSuperAdmin()) {
    flash('Δεν έχετε δικαίωμα.', 'error');
    redirect(APP_URL . '/pages/events.php');
}
if ($reg['event_type'] !== 'camp') {
    flash('Αυτά τα πεδία είναι διαθέσιμα μόνο για camps.', 'info');
    redirect(APP_URL . '/pages/event_participate.php?id=' . (int)$reg['event_id']);
}

// ── POST save ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        campRegistrationSave($regId, $_POST);
        flash('Οι πληροφορίες camp αποθηκεύτηκαν.', 'success');
        redirect(APP_URL . '/pages/event_camp_details.php?reg_id=' . $regId);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
    }
}

$camp = campRegistrationGet($regId) ?? [];

renderHead('Camp: ' . $reg['event_title']);
$flash = getFlash();

$fld = fn(string $k, $default = '') => h((string)($camp[$k] ?? $default));
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Camp πληροφορίες — ' . $reg['event_title']); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <?php $cbg = ['error'=>'230,57,70','success'=>'45,198,83','info'=>'78,132,255'][$flash['type']] ?? '78,132,255'; ?>
    <div style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(<?= $cbg ?>,.12);border:1px solid rgba(<?= $cbg ?>,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem;display:flex;align-items:center;gap:.75rem">
    <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#f0a500,#e09400);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem">
      <i class="fa-solid fa-campground"></i>
    </div>
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#f0a500;font-weight:700">Camp Registration</div>
      <h1 style="margin:.15rem 0;font-size:1.2rem;color:#f0f2ff"><?= h($reg['event_title']) ?></h1>
      <div style="color:#8892b0;font-size:.85rem">Εγγραφή #<?= (int)$regId ?> · Ημερομηνία: <?= h($reg['event_starts_at'] ? date('d/m/Y', strtotime($reg['event_starts_at'])) : '—') ?></div>
    </div>
  </div>

  <form method="POST" style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.5rem;display:grid;grid-template-columns:repeat(2,1fr);gap:1rem">
    <?= csrfField() ?>

    <label style="grid-column:span 1">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Άφιξη</div>
      <input type="datetime-local" name="arrival_at" value="<?= $fld('arrival_at') ? substr(str_replace(' ', 'T', $camp['arrival_at']), 0, 16) : '' ?>" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
    </label>
    <label style="grid-column:span 1">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Αναχώρηση</div>
      <input type="datetime-local" name="departure_at" value="<?= $fld('departure_at') ? substr(str_replace(' ', 'T', $camp['departure_at']), 0, 16) : '' ?>" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
    </label>

    <label style="grid-column:span 1">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Μέγεθος T-Shirt</div>
      <select name="tshirt_size" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
        <option value="">—</option>
        <?php foreach (['XS','S','M','L','XL','XXL','3XL'] as $sz): ?>
          <option value="<?= $sz ?>" <?= ($camp['tshirt_size'] ?? '') === $sz ? 'selected' : '' ?>><?= $sz ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="grid-column:span 1">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Συνοδοί ενήλικες</div>
      <input type="number" min="0" max="20" name="accompanying_adults" value="<?= $fld('accompanying_adults', '0') ?>" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
    </label>

    <label style="grid-column:span 2">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Διατροφικές παρατηρήσεις</div>
      <input type="text" name="dietary_notes" maxlength="255" placeholder="π.χ. χορτοφάγος, αλλεργία σε φιστίκια" value="<?= $fld('dietary_notes') ?>" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
    </label>

    <label style="grid-column:span 2">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Ιατρικές παρατηρήσεις</div>
      <textarea name="medical_notes" maxlength="500" rows="2" placeholder="π.χ. άσθμα, τραυματισμός γόνατος" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;font-family:inherit;resize:vertical"><?= $fld('medical_notes') ?></textarea>
    </label>

    <label style="grid-column:span 1">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Προτίμηση συγκατοίκου</div>
      <input type="text" name="roommate_preference" maxlength="120" value="<?= $fld('roommate_preference') ?>" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
    </label>
    <label style="grid-column:span 1">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Μεταφορά</div>
      <select name="transportation" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
        <option value="">—</option>
        <option value="own" <?= ($camp['transportation'] ?? '') === 'own' ? 'selected' : '' ?>>Ίδιο μέσο</option>
        <option value="shared_bus" <?= ($camp['transportation'] ?? '') === 'shared_bus' ? 'selected' : '' ?>>Κοινό λεωφορείο</option>
        <option value="pickup_needed" <?= ($camp['transportation'] ?? '') === 'pickup_needed' ? 'selected' : '' ?>>Χρειάζεται παραλαβή</option>
      </select>
    </label>

    <label style="grid-column:span 1">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Επείγον — όνομα</div>
      <input type="text" name="emergency_contact_name" maxlength="120" value="<?= $fld('emergency_contact_name') ?>" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
    </label>
    <label style="grid-column:span 1">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Επείγον — τηλέφωνο</div>
      <input type="tel" name="emergency_contact_phone" maxlength="40" value="<?= $fld('emergency_contact_phone') ?>" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
    </label>

    <label style="grid-column:span 2">
      <div style="font-size:.75rem;color:#8892b0;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.35rem">Άλλες σημειώσεις</div>
      <textarea name="notes" rows="3" style="width:100%;padding:.6rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;font-family:inherit;resize:vertical"><?= $fld('notes') ?></textarea>
    </label>

    <div style="grid-column:span 2;display:flex;justify-content:space-between;align-items:center;margin-top:.5rem">
      <a href="<?= APP_URL ?>/pages/event_participate.php?id=<?= (int)$reg['event_id'] ?>" style="color:#8892b0;text-decoration:none;font-size:.9rem"><i class="fa-solid fa-arrow-left"></i> Πίσω</a>
      <button type="submit" style="padding:.7rem 1.4rem;background:linear-gradient(135deg,#e63946,#c72832);color:#fff;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-family:inherit"><i class="fa-solid fa-check"></i> Αποθήκευση</button>
    </div>
  </form>

</div>
</div>
</div>
</body>
</html>
