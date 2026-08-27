<?php
/**
 * pages/event_participate.php — Register YOUR athletes into ANOTHER club's event
 * ============================================================
 *  - Pick category → check eligible athletes → bulk register
 *  - Shows your current registrations for this event + payment status
 *  - "Prepare payment" bundles unpaid regs into an event_payment
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();
$sid    = schoolId();
$userId = userId();
$id     = (int)($_GET['id'] ?? 0);
$ev     = eventGet($id);
if (!$ev) { flash('Το event δεν βρέθηκε.', 'error'); redirect(APP_URL . '/pages/events_browse.php'); }
if (!in_array($ev['visibility'], ['public','unlisted'], true) && (int)$ev['organiser_school_id'] !== $sid) {
    flash('Δεν έχετε πρόσβαση σε αυτό το event.', 'error');
    redirect(APP_URL . '/pages/events_browse.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'register') {
            $catId    = (int)($_POST['category_id'] ?? 0);
            $athletes = array_map('intval', (array)($_POST['athlete_ids'] ?? []));
            $notes    = trim($_POST['notes'] ?? '');
            $customIn = (array)($_POST['custom'] ?? []);
            $done = 0; $skipped = 0;
            foreach ($athletes as $athId) {
                if ($athId <= 0) continue;
                try {
                    $regId = eventRegisterAthlete($id, $catId, $athId, $sid, $userId, $notes);
                    eventRegistrationSaveCustom($regId, $id, $customIn);
                    $done++;
                } catch (Throwable $e) {
                    $skipped++;
                    error_log('[event_participate] skip athlete ' . $athId . ': ' . $e->getMessage());
                }
            }
            flash("Καταχωρήθηκαν $done συμμετοχές" . ($skipped ? " ($skipped παραλείφθηκαν)" : '') . '.');
        }
        if ($action === 'withdraw') {
            eventRegistrationWithdraw((int)$_POST['reg_id'], $sid, $_POST['reason'] ?? '');
            flash('Η εγγραφή ακυρώθηκε.');
        }
        if ($action === 'pay_start') {
            $method = in_array($_POST['method'] ?? '', ['bank','iris','cash'], true) ? $_POST['method'] : 'bank';
            $payId  = eventPaymentCreate($id, $sid, $method, $userId);
            // Save optional payer note in the payment.meta JSON so the
            // organiser can read it on their manage view.
            $note = trim((string)($_POST['payer_note'] ?? ''));
            if ($note !== '' && $payId > 0) {
                $note = mb_substr($note, 0, 500);
                $meta = json_encode(['payer_note' => $note], JSON_UNESCAPED_UNICODE);
                getDB()->prepare("UPDATE event_payments SET meta = ? WHERE id = ?")
                       ->execute([$meta, $payId]);
            }
            flash('Δημιουργήθηκε πληρωμή. Ανεβάστε την απόδειξη παρακάτω.');
            redirect(APP_URL . '/pages/event_participate.php?id=' . $id . '#pay-' . $payId);
        }
        if ($action === 'pay_proof') {
            $payId = (int)($_POST['pay_id'] ?? 0);
            $rel   = eventUploadStore($_FILES['proof'] ?? [], $id, 'private', ['pdf','jpg','jpeg','png','webp'], 8000);
            if (!$rel) throw new RuntimeException('Επιλέξτε αρχείο απόδειξης.');
            eventPaymentAttachProof($payId, $sid, $rel);
            // Update payer note if provided alongside proof
            $note = trim((string)($_POST['payer_note'] ?? ''));
            if ($note !== '') {
                $note = mb_substr($note, 0, 500);
                $meta = json_encode(['payer_note' => $note], JSON_UNESCAPED_UNICODE);
                getDB()->prepare("UPDATE event_payments SET meta = ? WHERE id = ? AND paying_school_id = ?")
                       ->execute([$meta, $payId, $sid]);
            }
            flash('Η απόδειξη ανέβηκε. Ο διοργανωτής θα την επιβεβαιώσει.');
        }
    } catch (Throwable $e) {
        flash('Σφάλμα: ' . $e->getMessage(), 'error');
    }
    redirect(APP_URL . '/pages/event_participate.php?id=' . $id);
}

$categories = eventCategories($id);
$myRegs     = eventRegistrationsForParticipant($id, $sid);
$myPays     = eventPaymentsForSchool($id, $sid);
$fields     = eventCustomFields($id);

// Athletes of this school.
// NOTE: gender / belt / sport aren't guaranteed columns on the athletes
// table (the base schema doesn't have them). We select the columns we
// KNOW exist, then fill the optional ones so the downstream markup
// never trips on a missing key.
$athStmt = getDB()->prepare("SELECT id, full_name, birthdate FROM athletes WHERE school_id = ? AND active = 1 ORDER BY full_name");
$athStmt->execute([$sid]);
$myAthletes = $athStmt->fetchAll();
foreach ($myAthletes as &$__a) {
    $__a['gender'] = $__a['gender'] ?? '';
    $__a['belt']   = $__a['belt']   ?? '';
    $__a['sport']  = $__a['sport']  ?? '';
}
unset($__a);

renderHead('Συμμετοχή: ' . $ev['title']);
$flash = getFlash();
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar($ev['title']); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <div style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.12);border:1px solid rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <!-- Event summary -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem;margin-bottom:1rem">
    <div style="font-size:.72rem;text-transform:uppercase;color:#e63946;font-weight:700;letter-spacing:.1em"><?= h(eventTypeLabel($ev['type'])) ?></div>
    <h2 style="margin:.3rem 0 .5rem;color:#f0f2ff;font-size:1.35rem"><?= h($ev['title']) ?></h2>
    <div style="color:#8892b0;font-size:.9rem">
      <?= eventStatusBadge($ev['status']) ?>
      <?php if ($ev['starts_at']): ?><span style="margin-left:.75rem"><i class="fa-regular fa-calendar"></i> <?= h(formatDate(substr($ev['starts_at'],0,10))) ?></span><?php endif; ?>
      <?php if ($ev['venue_name']): ?><span style="margin-left:.75rem"><i class="fa-solid fa-location-dot"></i> <?= h($ev['venue_name']) ?></span><?php endif; ?>
      <span style="margin-left:.75rem"><i class="fa-solid fa-euro-sign"></i> <?= $ev['fee_model']==='free' ? 'Δωρεάν' : number_format((float)$ev['fee_amount'],2,',','.').'€ / αθλητή' ?></span>
    </div>
    <?php if ($ev['description']): ?>
      <p style="color:#c8cfe0;line-height:1.6;margin:.85rem 0 0"><?= nl2br(h($ev['description'])) ?></p>
    <?php endif; ?>
  </div>

  <!-- Register form -->
  <?php if (!$categories): ?>
    <div style="background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.35);border-radius:10px;padding:1rem;color:#f0a500">
      Ο διοργανωτής δεν έχει προσθέσει κατηγορίες ακόμα.
    </div>
  <?php elseif (!in_array($ev['status'], ['open','draft'], true)): ?>
    <div style="background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.35);border-radius:10px;padding:1rem;color:#ffb3b8">
      Οι εγγραφές δεν είναι ανοιχτές αυτή τη στιγμή.
    </div>
  <?php else: ?>
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem;margin-bottom:1rem">
      <h3 style="margin:0 0 1rem;color:#e63946;font-size:1rem;text-transform:uppercase;letter-spacing:.08em">Δήλωση αθλητών</h3>

      <form method="POST" id="regForm">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="_action" value="register">

        <label style="display:block;margin-bottom:1rem">
          <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Κατηγορία</div>
          <select name="category_id" id="catSelect" required style="width:100%;padding:.75rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
            <option value="">— Επιλέξτε —</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" data-min-age="<?= (int)($c['min_age']??0) ?>" data-max-age="<?= (int)($c['max_age']??0) ?>" data-gender="<?= h($c['gender']) ?>">
                <?= h($c['name']) ?>
                <?php if ($c['gender']!=='MX') echo ' · ' . ['M'=>'Α','F'=>'Γ'][$c['gender']]; ?>
                <?php if ($c['min_age'] || $c['max_age']) echo ' · ' . ($c['min_age']??'—') . '-' . ($c['max_age']??'—') . ' ετών'; ?>
                <?php if ($c['fee_override']!==null) echo ' · ' . number_format((float)$c['fee_override'],2,',','.') . '€'; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.5rem">Αθλητές</div>
        <?php if (!$myAthletes): ?>
          <p style="color:#8892b0">Δεν έχετε ενεργούς αθλητές στη σχολή σας.</p>
        <?php else: ?>
          <div style="max-height:340px;overflow:auto;border:1px solid #2a3248;border-radius:8px;background:#0d1017">
            <?php foreach ($myAthletes as $a):
              $age = null;
              if (!empty($a['birthdate']) && $a['birthdate'] !== '0000-00-00') {
                  try { $age = (new DateTime())->diff(new DateTime($a['birthdate']))->y; } catch (Exception $e) {}
              }
            ?>
              <label style="display:flex;align-items:center;gap:.65rem;padding:.5rem .8rem;border-bottom:1px solid #1e2536;cursor:pointer" class="ath-row" data-age="<?= (int)($age ?? 0) ?>" data-gender="<?= h(strtoupper(substr($a['gender'] ?? '', 0, 1))) ?>">
                <input type="checkbox" name="athlete_ids[]" value="<?= (int)$a['id'] ?>">
                <span style="color:#f0f2ff;font-weight:600;flex:1"><?= h($a['full_name']) ?></span>
                <span style="color:#6b7494;font-size:.8rem">
                  <?= $age !== null ? $age . ' ετών' : '—' ?>
                  <?= !empty($a['belt']) ? ' · ' . h($a['belt']) : '' ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <label style="display:block;margin-top:1rem">
          <div style="font-size:.85rem;font-weight:700;color:#f0f2ff;margin-bottom:.35rem">Σημειώσεις (προαιρετικά)</div>
          <textarea name="notes" rows="2" maxlength="500" style="width:100%;padding:.7rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff"></textarea>
        </label>

        <?php if ($fields): ?>
          <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid #1e2536">
            <div style="font-size:.85rem;color:#e63946;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.75rem">Επιπλέον στοιχεία (ίδια για όλους τους αθλητές παραπάνω)</div>
            <?php foreach ($fields as $f):
              $name = 'custom[' . h($f['code']) . ']';
              $req = $f['required'] ? 'required' : '';
            ?>
              <label style="display:block;margin-bottom:.75rem">
                <div style="color:#c8cfe0;font-size:.85rem;font-weight:700;margin-bottom:.3rem"><?= h($f['label']) ?><?php if ($f['required']): ?> <span style="color:#e63946">*</span><?php endif; ?></div>
                <?php if ($f['field_type'] === 'textarea'): ?>
                  <textarea name="<?= $name ?>" rows="3" <?= $req ?> style="width:100%;padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff;font-family:inherit"></textarea>
                <?php elseif ($f['field_type'] === 'select'): ?>
                  <select name="<?= $name ?>" <?= $req ?> style="width:100%;padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
                    <option value="">— Επιλέξτε —</option>
                    <?php foreach (preg_split('/\r?\n/', $f['options'] ?? '') as $opt): $opt = trim($opt); if (!$opt) continue; ?>
                      <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($f['field_type'] === 'checkbox'): ?>
                  <label style="display:flex;align-items:center;gap:.4rem;color:#c8cfe0;padding:.5rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px"><input type="checkbox" name="<?= $name ?>" value="1"> <span>Ναι</span></label>
                <?php else:
                  $type = $f['field_type']==='number' ? 'number' : ($f['field_type']==='date'?'date':'text');
                ?>
                  <input type="<?= $type ?>" name="<?= $name ?>" <?= $req ?> style="width:100%;padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
                <?php endif; ?>
                <?php if ($f['help_text']): ?><div style="color:#6b7494;font-size:.78rem;margin-top:.2rem"><?= h($f['help_text']) ?></div><?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary" style="margin-top:1rem"><i class="fa-solid fa-user-plus"></i> Καταχώριση συμμετοχών</button>
      </form>
    </div>
  <?php endif; ?>

  <!-- My registrations -->
  <?php if ($myRegs): ?>
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem;margin-bottom:1rem">
    <h3 style="margin:0 0 .85rem;color:#e63946;font-size:1rem;text-transform:uppercase;letter-spacing:.08em">Οι συμμετοχές μου (<?= count($myRegs) ?>)</h3>
    <table style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="color:#6b7494;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;border-bottom:1px solid #1e2536">
          <th style="text-align:left;padding:.5rem 0">Αθλητής</th>
          <th style="text-align:left;padding:.5rem 0">Κατηγορία</th>
          <th style="text-align:left;padding:.5rem 0">Ποσό</th>
          <th style="text-align:left;padding:.5rem 0">Κατάσταση</th>
          <th style="text-align:left;padding:.5rem 0">Πληρωμή</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($myRegs as $r): ?>
          <tr style="border-bottom:1px solid #1e2536;color:#f0f2ff">
            <td style="padding:.6rem 0"><?= h($r['athlete_name'] ?? '—') ?></td>
            <td><?= h($r['cat_name'] ?? '—') ?></td>
            <td><?= number_format((float)$r['amount'],2,',','.') ?>€</td>
            <td><?= eventRegStatusBadge($r['status']) ?></td>
            <td><?= eventPaymentStatusBadge($r['payment_status']) ?></td>
            <td style="text-align:right">
              <?php if ($r['status']!=='withdrawn' && $r['payment_status']!=='verified'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Ακύρωση εγγραφής;')">
                  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                  <input type="hidden" name="_action" value="withdraw">
                  <input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-ghost btn-sm" style="color:#e63946"><i class="fa-solid fa-xmark"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php
    $unpaidCount = 0; $unpaidTotal = 0.0;
    foreach ($myRegs as $r) {
        if (in_array($r['payment_status'], ['unpaid','proof_uploaded'], true) && $r['status'] !== 'withdrawn') {
            $unpaidCount++; $unpaidTotal += (float)$r['amount'];
        }
    }
    // Are they already covered by an existing pending payment?
    $covered = false;
    foreach ($myPays as $p) if (in_array($p['status'], ['pending','proof_uploaded'], true)) { $covered = true; break; }
    ?>
    <?php if ($unpaidCount && !$covered): ?>
      <div style="margin-top:1rem;padding:1rem;background:#0d1017;border-radius:10px;border:1px solid #1e2536">
        <div style="color:#c8cfe0;font-size:.9rem;margin-bottom:.65rem">
          Εκκρεμούν <strong style="color:#f0f2ff"><?= $unpaidCount ?></strong> συμμετοχές συνολικά <strong style="color:#f0a500"><?= number_format($unpaidTotal,2,',','.') ?>€</strong>.
        </div>
        <?php
          $__mLabels = ['bank'=>'Τραπεζικό έμβασμα','iris'=>'IRIS','cash'=>'Μετρητά (on-site)'];
        ?>
        <form method="POST" style="display:grid;grid-template-columns:1fr;gap:.75rem">
          <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
          <input type="hidden" name="_action" value="pay_start">
          <div>
            <label style="display:block;font-size:.78rem;font-weight:700;color:#a9b3c9;margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.06em">Τρόπος πληρωμής</label>
            <select name="method" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              <?php foreach (explode(',', $ev['payment_methods']) as $m):
                  $m = trim($m);
                  if (!in_array($m, ['bank','iris','cash'], true)) continue; ?>
                <option value="<?= h($m) ?>"><?= h($__mLabels[$m] ?? strtoupper($m)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:.78rem;font-weight:700;color:#a9b3c9;margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.06em">
              Σημείωση προς τον διοργανωτή (προαιρετικό)
            </label>
            <textarea name="payer_note" rows="2" maxlength="500"
                      placeholder="π.χ. Έκανα την κατάθεση σήμερα, ακολουθεί απόδειξη — ή: Θα πληρώσω on-site την ημέρα του πρωταθλήματος."
                      style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;font-family:inherit;resize:vertical;min-height:64px"></textarea>
          </div>
          <button class="btn btn-primary" style="min-height:48px;font-size:.98rem;font-weight:800">
            <i class="fa-solid fa-credit-card"></i> Έναρξη πληρωμής
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- My payments -->
  <?php if ($myPays): ?>
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem">
    <h3 style="margin:0 0 .85rem;color:#e63946;font-size:1rem;text-transform:uppercase;letter-spacing:.08em">Οι πληρωμές μου</h3>
    <?php foreach ($myPays as $p): ?>
      <div id="pay-<?= (int)$p['id'] ?>" style="border:1px solid #1e2536;border-radius:10px;padding:1rem;margin-bottom:.75rem;background:#0d1017">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:.6rem">
          <div>
            <div style="color:#f0f2ff;font-size:1.1rem;font-weight:800"><?= number_format((float)$p['amount'],2,',','.') ?>€</div>
            <div style="color:#8892b0;font-size:.85rem">Ref: <code style="background:#111520;padding:.15rem .45rem;border-radius:4px"><?= h($p['reference_code']) ?></code></div>
          </div>
          <span class="badge <?= $p['status']==='verified'?'badge-paid':($p['status']==='rejected'?'badge-overdue':'badge-pending') ?>">
            <?= h(['pending'=>'Εκκρεμεί απόδειξη','proof_uploaded'=>'Σε έλεγχο','verified'=>'Επιβεβαιωμένη','rejected'=>'Απορρίφθηκε','refunded'=>'Επιστράφηκε'][$p['status']] ?? $p['status']) ?>
          </span>
        </div>
        <?php if ($p['method'] === 'bank' && $ev['bank_iban']): ?>
          <div style="background:#111520;padding:.75rem;border-radius:8px;color:#c8cfe0;font-size:.85rem;line-height:1.7;margin-bottom:.6rem">
            <strong>Τράπεζα:</strong> <?= h($ev['bank_name'] ?? '—') ?><br>
            <strong>Δικαιούχος:</strong> <?= h($ev['bank_beneficiary'] ?? '—') ?><br>
            <strong>IBAN:</strong> <code><?= h($ev['bank_iban']) ?></code><br>
            <strong>Αιτιολογία:</strong> <code style="color:#f0a500"><?= h($p['reference_code']) ?></code>
          </div>
        <?php endif; ?>

        <?php if (in_array($p['status'], ['pending','proof_uploaded','rejected'], true)): ?>
          <form method="POST" enctype="multipart/form-data" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="_action" value="pay_proof">
            <input type="hidden" name="pay_id" value="<?= (int)$p['id'] ?>">
            <input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp" required style="color:#c8cfe0;font-size:.85rem">
            <button class="btn btn-primary btn-sm"><i class="fa-solid fa-upload"></i> Ανέβασμα απόδειξης</button>
          </form>
        <?php endif; ?>
        <?php if ($p['proof_file_path']): ?>
          <a href="<?= APP_URL ?>/events/download.php?p=<?= (int)$p['id'] ?>" target="_blank" style="color:#e63946;font-size:.85rem;text-decoration:none;margin-top:.5rem;display:inline-block">
            <i class="fa-solid fa-file"></i> Προβολή απόδειξης
          </a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
</div>
</div>

<script>
// Filter athletes list by selected category constraints (age/gender)
(function(){
  var sel = document.getElementById('catSelect');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var opt = this.selectedOptions[0];
    if (!opt) return;
    var minA = +opt.dataset.minAge || 0;
    var maxA = +opt.dataset.maxAge || 0;
    var g    = opt.dataset.gender || 'MX';
    document.querySelectorAll('.ath-row').forEach(function(row){
      var age = +row.dataset.age;
      var rg  = row.dataset.gender;
      var ok  = true;
      if (minA && age && age < minA) ok = false;
      if (maxA && age && age > maxA) ok = false;
      if (g !== 'MX' && rg && rg !== g) ok = false;
      row.style.opacity = ok ? '1' : '.35';
      var cb = row.querySelector('input[type=checkbox]');
      if (cb) cb.disabled = !ok;
    });
  });
})();
</script>
</body></html>
