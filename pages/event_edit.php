<?php
/**
 * pages/event_edit.php — Create or edit an event (organiser)
 * ============================================================
 *  - GET  ?id=…   edit; GET no id → create
 *  - POST         verifyCsrf, save
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();
$sid    = schoolId();
$userId = userId();
$id     = (int)($_GET['id'] ?? 0);
$ev     = $id ? eventGet($id) : null;

if ($id && (!$ev || (int)$ev['organiser_school_id'] !== $sid)) {
    flash('Το event δεν βρέθηκε ή δεν έχετε δικαίωμα επεξεργασίας.', 'error');
    redirect(APP_URL . '/pages/events.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $payload = [
            'title'                  => $_POST['title'] ?? '',
            'subtitle'               => $_POST['subtitle'] ?? '',
            'description'            => $_POST['description'] ?? '',
            'type'                   => $_POST['type'] ?? 'friendly',
            'sport'                  => $_POST['sport'] ?? '',
            'sport_style'            => $_POST['sport_style'] ?? '',
            'visibility'             => $_POST['visibility'] ?? 'public',
            'venue_name'             => $_POST['venue_name'] ?? '',
            'venue_address'          => $_POST['venue_address'] ?? '',
            'venue_url'              => $_POST['venue_url'] ?? '',
            'starts_at'              => $_POST['starts_at'] ?? null,
            'ends_at'                => $_POST['ends_at'] ?? null,
            'registration_opens_at'  => $_POST['registration_opens_at'] ?? null,
            'registration_closes_at' => $_POST['registration_closes_at'] ?? null,
            'payment_due_at'         => $_POST['payment_due_at'] ?? null,
            'max_participants'       => $_POST['max_participants'] ?? '',
            'ring_count'             => $_POST['ring_count'] ?? 1,
            'fee_model'              => $_POST['fee_model'] ?? 'per_athlete',
            'fee_amount'             => $_POST['fee_amount'] ?? 0,
            'late_fee_amount'        => $_POST['late_fee_amount'] ?? 0,
            'late_fee_starts_at'     => $_POST['late_fee_starts_at'] ?? null,
            'refund_policy'          => $_POST['refund_policy'] ?? '',
            'payment_methods'        => $_POST['payment_methods'] ?? ['bank','iris','cash'],
            'bank_iban'              => $_POST['bank_iban'] ?? '',
            'bank_beneficiary'       => $_POST['bank_beneficiary'] ?? '',
            'bank_name'              => $_POST['bank_name'] ?? '',
            'bank_reference_template'=> $_POST['bank_reference_template'] ?? 'MASTER-EV{event_id}-CL{school_id}',
            'contact_email'          => $_POST['contact_email'] ?? '',
            'contact_phone'          => $_POST['contact_phone'] ?? '',
        ];
        if (empty(trim($payload['title']))) throw new RuntimeException('Ο τίτλος είναι υποχρεωτικός.');

        // Normalise empty datetime fields to null
        foreach (['starts_at','ends_at','registration_opens_at','registration_closes_at','payment_due_at','late_fee_starts_at'] as $k) {
            if ($payload[$k] === '' || $payload[$k] === null) $payload[$k] = null;
            elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $payload[$k])) $payload[$k] = str_replace('T', ' ', $payload[$k]) . ':00';
        }

        if ($id) {
            if (!empty($_POST['status'])) $payload['status'] = $_POST['status'];
            eventUpdate($id, $payload, $sid);
            flash('Αποθηκεύτηκαν οι αλλαγές.');
            redirect(eventManageUrl($id));
        } else {
            $newId = eventCreate($payload, $sid, $userId);
            flash('Το event δημιουργήθηκε. Προσθέστε κατηγορίες για να ανοίξετε εγγραφές.');
            redirect(eventManageUrl($newId));
        }
    } catch (Throwable $e) {
        flash('Σφάλμα: ' . $e->getMessage(), 'error');
    }
}

$title = $ev ? 'Επεξεργασία: ' . $ev['title'] : 'Νέα Διοργάνωση';
renderHead($title);
$flash = getFlash();
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar($title); ?>
<div class="page-body" style="max-width:960px">

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>" style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.12);border:1px solid rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem">

      <!-- Basics -->
      <div style="grid-column:1/-1;background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem 1.35rem">
        <h3 style="margin:0 0 1rem;font-size:1rem;color:#e63946;text-transform:uppercase;letter-spacing:.08em">Βασικά στοιχεία</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem">
          <label style="grid-column:1/-1">
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Τίτλος *</div>
            <input type="text" name="title" required maxlength="200" value="<?= h($ev['title'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label style="grid-column:1/-1">
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Υπότιτλος</div>
            <input type="text" name="subtitle" maxlength="255" value="<?= h($ev['subtitle'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>

          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Τύπος</div>
            <select name="type" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
              <?php foreach (['championship','friendly','camp','seminar','meeting','exam'] as $t): ?>
                <option value="<?= $t ?>" <?= ($ev['type'] ?? 'friendly')===$t?'selected':'' ?>><?= h(eventTypeLabel($t)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Ορατότητα</div>
            <select name="visibility" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
              <?php foreach (['public','unlisted','invite_only'] as $v): ?>
                <option value="<?= $v ?>" <?= ($ev['visibility'] ?? 'public')===$v?'selected':'' ?>><?= h(eventVisibilityLabel($v)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Άθλημα</div>
            <input type="text" name="sport" placeholder="π.χ. taekwondo" value="<?= h($ev['sport'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Στυλ / Ειδικότητα</div>
            <input type="text" name="sport_style" placeholder="π.χ. kata, kumite, WTF, ITF" value="<?= h($ev['sport_style'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>

          <?php if ($ev): ?>
          <label style="grid-column:1/-1">
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Κατάσταση</div>
            <select name="status" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
              <?php foreach (['draft','open','closed','in_progress','completed','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $ev['status']===$s?'selected':'' ?>><?= h(eventStatusLabel($s)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php endif; ?>

          <label style="grid-column:1/-1">
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Περιγραφή</div>
            <textarea name="description" rows="4" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff;resize:vertical"><?= h($ev['description'] ?? '') ?></textarea>
          </label>
        </div>
      </div>

      <!-- Venue -->
      <div style="grid-column:1/-1;background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem 1.35rem">
        <h3 style="margin:0 0 1rem;font-size:1rem;color:#e63946;text-transform:uppercase;letter-spacing:.08em">Τοποθεσία</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem">
          <label style="grid-column:1/-1">
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Όνομα χώρου</div>
            <input type="text" name="venue_name" value="<?= h($ev['venue_name'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Διεύθυνση</div>
            <input type="text" name="venue_address" value="<?= h($ev['venue_address'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Χάρτης URL (Google Maps)</div>
            <input type="url" name="venue_url" value="<?= h($ev['venue_url'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
        </div>
      </div>

      <!-- Dates -->
      <div style="grid-column:1/-1;background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem 1.35rem">
        <h3 style="margin:0 0 1rem;font-size:1rem;color:#e63946;text-transform:uppercase;letter-spacing:.08em">Ημερομηνίες</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem">
          <?php
          $dtLabels = [
              'starts_at' => 'Έναρξη event',
              'ends_at' => 'Λήξη event',
              'registration_opens_at' => 'Άνοιγμα εγγραφών',
              'registration_closes_at' => 'Κλείσιμο εγγραφών',
              'payment_due_at' => 'Λήξη πληρωμής',
              'late_fee_starts_at' => 'Έναρξη late fee',
          ];
          foreach ($dtLabels as $k => $lbl):
              $val = $ev[$k] ?? null;
              if ($val) $val = str_replace(' ', 'T', substr($val, 0, 16));
          ?>
            <label>
              <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem"><?= h($lbl) ?></div>
              <input type="datetime-local" name="<?= $k ?>" value="<?= h($val ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Capacity & fees -->
      <div style="grid-column:1/-1;background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem 1.35rem">
        <h3 style="margin:0 0 1rem;font-size:1rem;color:#e63946;text-transform:uppercase;letter-spacing:.08em">Χωρητικότητα & Κόστος</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.85rem">
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Μέγιστοι συμμετέχοντες</div>
            <input type="number" min="0" name="max_participants" placeholder="άπειροι" value="<?= h((string)($ev['max_participants'] ?? '')) ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Αριθμός Τερέν (rings)</div>
            <input type="number" min="1" max="30" name="ring_count" value="<?= h((string)($ev['ring_count'] ?? 1)) ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Μοντέλο χρέωσης</div>
            <select name="fee_model" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
              <?php foreach (['per_athlete'=>'ανά αθλητή','per_team'=>'ανά ομάδα','flat'=>'σταθερό','free'=>'δωρεάν'] as $v=>$lbl): ?>
                <option value="<?= $v ?>" <?= ($ev['fee_model'] ?? 'per_athlete')===$v?'selected':'' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Ποσό (€)</div>
            <input type="number" step="0.01" min="0" name="fee_amount" value="<?= h((string)($ev['fee_amount'] ?? 0)) ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Late fee (€)</div>
            <input type="number" step="0.01" min="0" name="late_fee_amount" value="<?= h((string)($ev['late_fee_amount'] ?? 0)) ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label style="grid-column:1/-1">
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Πολιτική επιστροφών</div>
            <textarea name="refund_policy" rows="2" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff;resize:vertical"><?= h($ev['refund_policy'] ?? '') ?></textarea>
          </label>
        </div>
      </div>

      <!-- Payment -->
      <div style="grid-column:1/-1;background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem 1.35rem">
        <h3 style="margin:0 0 1rem;font-size:1rem;color:#e63946;text-transform:uppercase;letter-spacing:.08em">Τρόποι πληρωμής</h3>
        <?php $currentPM = explode(',', $ev['payment_methods'] ?? 'bank,iris,cash'); ?>
        <div style="display:flex;flex-wrap:wrap;gap:1.25rem;margin-bottom:1rem">
          <?php foreach (['bank'=>'Τραπεζικό έμβασμα','iris'=>'IRIS','viva'=>'Viva','stripe'=>'Stripe','cash'=>'Μετρητά (on-site)'] as $v => $lbl): ?>
            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer">
              <input type="checkbox" name="payment_methods[]" value="<?= $v ?>" <?= in_array($v, $currentPM, true)?'checked':'' ?>>
              <span style="color:#f0f2ff;font-size:.9rem"><?= h($lbl) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem">
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Δικαιούχος</div>
            <input type="text" name="bank_beneficiary" value="<?= h($ev['bank_beneficiary'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Τράπεζα</div>
            <input type="text" name="bank_name" value="<?= h($ev['bank_name'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label style="grid-column:1/-1">
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">IBAN</div>
            <input type="text" name="bank_iban" value="<?= h($ev['bank_iban'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff;font-family:monospace">
          </label>
          <label style="grid-column:1/-1">
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Πρότυπο αριθμού αναφοράς</div>
            <input type="text" name="bank_reference_template" value="<?= h($ev['bank_reference_template'] ?? 'MASTER-EV{event_id}-CL{school_id}') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff;font-family:monospace">
            <small style="color:#6b7494">Placeholders: <code>{event_id}</code>, <code>{school_id}</code>.</small>
          </label>
        </div>
      </div>

      <!-- Contact -->
      <div style="grid-column:1/-1;background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem 1.35rem">
        <h3 style="margin:0 0 1rem;font-size:1rem;color:#e63946;text-transform:uppercase;letter-spacing:.08em">Επικοινωνία</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem">
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Email επικοινωνίας</div>
            <input type="email" name="contact_email" value="<?= h($ev['contact_email'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
          <label>
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Τηλέφωνο</div>
            <input type="text" name="contact_phone" value="<?= h($ev['contact_phone'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
          </label>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:.75rem;justify-content:flex-end">
      <a href="<?= APP_URL ?>/pages/events.php" class="btn btn-ghost">Ακύρωση</a>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Αποθήκευση</button>
    </div>
  </form>
</div>
</div>
</div>
</body></html>
