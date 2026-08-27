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
        $removeBanner = !empty($_POST['remove_banner']);
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
            'payment_methods'        => array_values(array_intersect(
                                          (array)($_POST['payment_methods'] ?? ['bank','iris','cash']),
                                          ['bank','iris','cash']  // whitelist — viva/stripe removed
                                        )) ?: ['bank','iris','cash'],
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
            // Banner: upload replacement, or remove-only, or leave as-is
            if (!empty($_FILES['banner']) && ($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $rel = eventUploadStore($_FILES['banner'], $id, 'public', ['jpg','jpeg','png','webp'], 4000);
                if ($rel) eventUpdate($id, ['banner_path' => $rel], $sid);
            } elseif ($removeBanner) {
                eventUpdate($id, ['banner_path' => null], $sid);
            }
            flash('Αποθηκεύτηκαν οι αλλαγές.');
            redirect(eventManageUrl($id));
        } else {
            $newId = eventCreate($payload, $sid, $userId);
            if (!empty($_FILES['banner']) && ($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                try {
                    $rel = eventUploadStore($_FILES['banner'], $newId, 'public', ['jpg','jpeg','png','webp'], 4000);
                    if ($rel) eventUpdate($newId, ['banner_path' => $rel], $sid);
                } catch (Throwable $e) {
                    error_log('[event_edit] banner upload skipped: ' . $e->getMessage());
                }
            }
            flash('Η διοργάνωση δημιουργήθηκε. Προσθέστε κατηγορίες για να ανοίξετε εγγραφές.');
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

  <form method="POST" enctype="multipart/form-data" novalidate>
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
            <input type="text" name="sport_style" placeholder="π.χ. Αγώνων / Φόρμας / WTF / ITF" value="<?= h($ev['sport_style'] ?? '') ?>" style="width:100%;padding:.7rem .85rem;background:#0d1017;border:1px solid #2a3248;border-radius:9px;color:#f0f2ff">
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

          <!-- Banner / cover image -->
          <div style="grid-column:1/-1">
            <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.5rem">
              <i class="fa-solid fa-image" style="color:#e63946;margin-right:.35rem"></i>
              Λογότυπο / Cover εικόνα διοργάνωσης
            </div>
            <?php if (!empty($ev['banner_path'])):
              $bannerUrl = rtrim(APP_URL, '/') . '/uploads/' . ltrim($ev['banner_path'], '/');
            ?>
              <div style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:.65rem">
                <img src="<?= h($bannerUrl) ?>" alt="Τρέχον banner"
                     style="max-width:220px;width:100%;height:auto;border-radius:10px;border:1px solid #2a3248;background:#0d1017;object-fit:cover">
                <label style="display:inline-flex;align-items:center;gap:.4rem;color:#ff8891;font-size:.85rem;cursor:pointer;padding:.5rem .8rem;border:1px solid rgba(230,57,70,.35);border-radius:8px;background:rgba(230,57,70,.05)">
                  <input type="checkbox" name="remove_banner" value="1" style="accent-color:#e63946">
                  Αφαίρεση τρέχοντος banner
                </label>
              </div>
            <?php endif; ?>
            <input type="file" name="banner" accept="image/jpeg,image/png,image/webp"
                   style="width:100%;padding:.55rem .7rem;background:#0d1017;border:1px dashed #2a3248;border-radius:9px;color:#c1c8d4;font-size:.88rem;cursor:pointer">
            <small style="color:#6b7494;display:block;margin-top:.3rem">JPG/PNG/WEBP · έως 4 MB · προτεινόμενο 1600×600 px για hero, ή 800×600 για card.</small>
          </div>
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
      <div style="grid-column:1/-1;background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.35rem 1.4rem">
        <h3 style="margin:0 0 1.1rem;font-size:1.05rem;color:#e63946;text-transform:uppercase;letter-spacing:.08em">Τρόποι πληρωμής</h3>

        <?php $currentPM = array_filter(explode(',', $ev['payment_methods'] ?? 'bank,iris,cash'), fn($m) => in_array(trim($m), ['bank','iris','cash'], true)); ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;margin-bottom:1.25rem">
          <?php foreach (['bank'=>['Τραπεζικό έμβασμα','fa-building-columns','#3b82f6'],
                          'iris'=>['IRIS','fa-bolt','#f0a500'],
                          'cash'=>['Μετρητά (on-site)','fa-money-bill-wave','#2dc653']] as $v => $meta):
              [$lbl, $ic, $col] = $meta;
              $checked = in_array($v, $currentPM, true);
          ?>
            <label class="pm-opt <?= $checked ? 'is-on' : '' ?>"
                   style="display:flex;align-items:center;gap:.75rem;cursor:pointer;
                          padding:.95rem 1.1rem;border-radius:12px;
                          background:<?= $checked ? 'rgba(230,57,70,.08)' : 'rgba(255,255,255,.03)' ?>;
                          border:2px solid <?= $checked ? 'rgba(230,57,70,.4)' : '#2a3248' ?>;
                          transition:all .15s;min-height:56px">
              <input type="checkbox" name="payment_methods[]" value="<?= $v ?>" <?= $checked?'checked':'' ?>
                     style="width:22px;height:22px;accent-color:#e63946;flex-shrink:0;cursor:pointer">
              <i class="fa-solid <?= $ic ?>" style="color:<?= $col ?>;font-size:1.25rem;width:24px;text-align:center"></i>
              <span style="color:#ffffff;font-size:1rem;font-weight:700"><?= h($lbl) ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem">
          <label>
            <div style="font-size:.95rem;font-weight:700;color:#ffffff;margin-bottom:.45rem">Δικαιούχος</div>
            <input type="text" name="bank_beneficiary" value="<?= h($ev['bank_beneficiary'] ?? '') ?>"
                   autocomplete="off" inputmode="text"
                   placeholder="π.χ. Αθλητικός Όμιλος ΝΙΚΗ"
                   style="width:100%;padding:.95rem 1rem;background:#0d1017;border:2px solid #2a3248;border-radius:10px;color:#ffffff;font-size:1rem;min-height:52px">
          </label>
          <label>
            <div style="font-size:.95rem;font-weight:700;color:#ffffff;margin-bottom:.45rem">Τράπεζα</div>
            <input type="text" name="bank_name" value="<?= h($ev['bank_name'] ?? '') ?>"
                   autocomplete="off"
                   placeholder="π.χ. Πειραιώς / Alpha / Eurobank"
                   style="width:100%;padding:.95rem 1rem;background:#0d1017;border:2px solid #2a3248;border-radius:10px;color:#ffffff;font-size:1rem;min-height:52px">
          </label>
          <label style="grid-column:1/-1">
            <div style="font-size:.95rem;font-weight:700;color:#ffffff;margin-bottom:.45rem">IBAN</div>
            <input type="text" name="bank_iban" value="<?= h($ev['bank_iban'] ?? '') ?>"
                   autocomplete="off" inputmode="text"
                   placeholder="GR16 0110 1250 0000 0001 2345 678"
                   style="width:100%;padding:.95rem 1rem;background:#0d1017;border:2px solid #2a3248;border-radius:10px;color:#ffffff;font-family:monospace;font-size:1.05rem;letter-spacing:.05em;min-height:52px">
          </label>
          <label style="grid-column:1/-1">
            <div style="font-size:.95rem;font-weight:700;color:#ffffff;margin-bottom:.45rem">Πρότυπο αριθμού αναφοράς</div>
            <input type="text" name="bank_reference_template" value="<?= h($ev['bank_reference_template'] ?? 'MASTER-EV{event_id}-CL{school_id}') ?>"
                   autocomplete="off"
                   style="width:100%;padding:.95rem 1rem;background:#0d1017;border:2px solid #2a3248;border-radius:10px;color:#ffffff;font-family:monospace;font-size:1rem;min-height:52px">
            <small style="color:#8892b0;display:block;margin-top:.4rem;font-size:.82rem">
              Ο κωδικός που θα εμφανίζεται στην απόδειξη κάθε συλλόγου. Placeholders: <code>{event_id}</code>, <code>{school_id}</code>.
            </small>
          </label>
        </div>
      </div>
      <style>
        .pm-opt:hover { background:rgba(255,255,255,.06) !important; border-color:rgba(230,57,70,.6) !important; }
        @media (max-width:480px){
          .pm-opt { padding:.85rem .95rem !important; min-height:60px !important; font-size:1.05rem !important; }
          .pm-opt i { font-size:1.35rem !important }
        }
      </style>

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
