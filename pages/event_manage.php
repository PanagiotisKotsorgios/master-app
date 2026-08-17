<?php
/**
 * pages/event_manage.php — Manage a single event (organiser)
 * ============================================================
 * Tabs: overview | categories | registrations | payments | public link
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();
$sid    = schoolId();
$userId = userId();
$id     = (int)($_GET['id'] ?? 0);
$ev     = eventGet($id);

if (!$ev || (int)$ev['organiser_school_id'] !== $sid) {
    flash('Το event δεν βρέθηκε ή δεν έχετε δικαίωμα.', 'error');
    redirect(APP_URL . '/pages/events.php');
}

$tab = $_GET['tab'] ?? 'overview';

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'cat_create') {
            eventCategoryCreate($id, [
                'name'       => $_POST['name'] ?? '',
                'gender'     => $_POST['gender'] ?? 'MX',
                'min_age'    => $_POST['min_age'] ?? '',
                'max_age'    => $_POST['max_age'] ?? '',
                'min_weight' => $_POST['min_weight'] ?? '',
                'max_weight' => $_POST['max_weight'] ?? '',
                'belt_from'  => $_POST['belt_from'] ?? '',
                'belt_to'    => $_POST['belt_to'] ?? '',
                'style'      => $_POST['style'] ?? '',
                'max_slots'  => $_POST['max_slots'] ?? '',
                'fee_override' => $_POST['fee_override'] ?? '',
                'format'     => $_POST['format'] ?? 'single_elim',
                'pool_size'  => $_POST['pool_size'] ?? 4,
                'display_order' => $_POST['display_order'] ?? 0,
            ]);
            flash('Η κατηγορία προστέθηκε.');
            redirect(eventManageUrl($id) . '&tab=categories');
        }
        if ($action === 'cat_delete') {
            eventCategoryDelete((int)$_POST['category_id'], $id);
            flash('Η κατηγορία διαγράφηκε.');
            redirect(eventManageUrl($id) . '&tab=categories');
        }
        if ($action === 'reg_status') {
            eventRegistrationUpdateStatus((int)$_POST['reg_id'], $id, $_POST['status'] ?? '', $userId);
            flash('Ενημερώθηκε.');
            redirect(eventManageUrl($id) . '&tab=registrations');
        }
        if ($action === 'pay_verify') {
            eventPaymentVerify((int)$_POST['pay_id'], $id, $userId, $_POST['notes'] ?? '');
            flash('Η πληρωμή επιβεβαιώθηκε.');
            redirect(eventManageUrl($id) . '&tab=payments');
        }
        if ($action === 'pay_reject') {
            eventPaymentReject((int)$_POST['pay_id'], $id, $userId, $_POST['notes'] ?? '');
            flash('Η πληρωμή απορρίφθηκε.');
            redirect(eventManageUrl($id) . '&tab=payments');
        }
        if ($action === 'pay_refund') {
            $amt = (float)($_POST['refund_amount'] ?? 0);
            eventPaymentRefund((int)$_POST['pay_id'], $id, $userId, $amt, $_POST['notes'] ?? '');
            flash('Καταγράφηκε επιστροφή ' . number_format($amt, 2, ',', '.') . '€.');
            redirect(eventManageUrl($id) . '&tab=payments');
        }
        if ($action === 'field_save') {
            eventCustomFieldSave($id, $_POST, isset($_POST['field_id']) && $_POST['field_id']!=='' ? (int)$_POST['field_id'] : null);
            flash('Το πεδίο αποθηκεύτηκε.');
            redirect(eventManageUrl($id) . '&tab=fields');
        }
        if ($action === 'field_delete') {
            eventCustomFieldDelete((int)$_POST['field_id'], $id);
            flash('Διαγράφηκε.', 'info');
            redirect(eventManageUrl($id) . '&tab=fields');
        }
    } catch (Throwable $e) {
        flash('Σφάλμα: ' . $e->getMessage(), 'error');
        redirect(eventManageUrl($id) . '&tab=' . $tab);
    }
}

$categories    = eventCategories($id);
$registrations = eventRegistrationsForOrganiser($id);
$payments      = eventPaymentsForEvent($id);
$customFields  = eventCustomFields($id);

renderHead('Διαχείριση: ' . $ev['title']);
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

  <!-- Header strip -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
      <div>
        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#e63946;font-weight:700"><?= h(eventTypeLabel($ev['type'])) ?></div>
        <h1 style="margin:.25rem 0 .3rem;font-size:1.4rem;color:#f0f2ff"><?= h($ev['title']) ?></h1>
        <div style="color:#8892b0;font-size:.9rem">
          <?= eventStatusBadge($ev['status']) ?>
          <span style="margin-left:.5rem"><?= h(eventVisibilityLabel($ev['visibility'])) ?></span>
          <?php if ($ev['starts_at']): ?>
            <span style="margin-left:.75rem"><i class="fa-regular fa-calendar"></i> <?= h(formatDate(substr($ev['starts_at'],0,10))) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="<?= h(eventPublicUrl($ev)) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye"></i> Δημόσια</a>
        <a href="<?= APP_URL ?>/pages/event_referee.php?id=<?= (int)$ev['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-gavel"></i> Referee</a>
        <a href="<?= APP_URL ?>/events/display.php?slug=<?= h($ev['slug']) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fa-solid fa-tv"></i> Venue</a>
        <a href="<?= APP_URL ?>/events/results.php?slug=<?= h($ev['slug']) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fa-solid fa-medal"></i> Αποτελέσματα</a>
        <a href="<?= APP_URL ?>/pages/event_edit.php?id=<?= (int)$ev['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-pen"></i> Επεξεργασία</a>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;border-bottom:1px solid #1e2536;margin-bottom:1.25rem">
    <?php foreach ([
        'overview' => 'Επισκόπηση',
        'categories' => 'Κατηγορίες (' . count($categories) . ')',
        'registrations' => 'Εγγραφές (' . count($registrations) . ')',
        'payments' => 'Πληρωμές (' . count($payments) . ')',
        'fields' => 'Custom πεδία (' . count($customFields) . ')',
        'updates' => 'Ενημερώσεις',
        'share' => 'Κοινοποίηση',
    ] as $k => $lbl):
      $act = $tab === $k;
    ?>
      <a href="?id=<?= $id ?>&tab=<?= $k ?>" style="padding:.6rem 1rem;color:<?= $act?'#e63946':'#8892b0' ?>;text-decoration:none;font-weight:700;font-size:.9rem;border-bottom:2px solid <?= $act?'#e63946':'transparent' ?>;margin-bottom:-1px"><?= h($lbl) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'overview'): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.85rem">
      <?php
      $stats = [
          ['label'=>'Εγγραφές', 'v'=>count($registrations), 'icon'=>'user-plus'],
          ['label'=>'Εγκεκριμένες', 'v'=>count(array_filter($registrations, fn($r)=>$r['status']==='approved')), 'icon'=>'check'],
          ['label'=>'Εκκρεμείς πληρωμές', 'v'=>count(array_filter($registrations, fn($r)=>$r['payment_status']!=='verified')), 'icon'=>'clock'],
          ['label'=>'Σύλλογοι', 'v'=>count(array_unique(array_column($registrations,'registering_school_id'))), 'icon'=>'school'],
      ];
      foreach ($stats as $st): ?>
        <div style="background:#111520;border:1px solid #1e2536;border-radius:12px;padding:1.1rem 1.25rem">
          <div style="font-size:.72rem;text-transform:uppercase;color:#6b7494;font-weight:700"><?= h($st['label']) ?></div>
          <div style="font-size:1.8rem;font-weight:800;color:#f0f2ff;margin-top:.35rem"><?= (int)$st['v'] ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem;margin-top:1rem">
      <h3 style="margin:0 0 .75rem;color:#e63946;font-size:1rem">Οδηγός επόμενων βημάτων</h3>
      <ol style="color:#c8cfe0;line-height:1.8;padding-left:1.2rem;margin:0">
        <?php if (!$categories): ?><li>Πρόσθεσε κατηγορίες (Κατηγορίες tab).</li><?php endif; ?>
        <?php if ($ev['status']==='draft'): ?><li>Άνοιξε τις εγγραφές (Επεξεργασία → Κατάσταση: Ανοιχτές εγγραφές).</li><?php endif; ?>
        <?php if ($ev['visibility']!=='public'): ?><li>Κάνε το event δημόσιο για να το βρουν άλλοι σύλλογοι (Επεξεργασία → Ορατότητα).</li><?php endif; ?>
        <li>Μοιράσου τη δημόσια σελίδα (Κοινοποίηση tab).</li>
      </ol>
    </div>

  <?php elseif ($tab === 'categories'): ?>
    <!-- ADD CATEGORY -->
    <form method="POST" style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem;margin-bottom:1rem">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="_action" value="cat_create">
      <h3 style="margin:0 0 1rem;color:#e63946;font-size:.95rem;text-transform:uppercase;letter-spacing:.08em">+ Προσθήκη κατηγορίας</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
        <input type="text" name="name" placeholder="Όνομα (π.χ. -60kg U18 Άνδρες)" required style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <select name="gender" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
          <option value="MX">Μικτό</option>
          <option value="M">Άνδρες</option>
          <option value="F">Γυναίκες</option>
        </select>
        <input type="number" name="min_age" placeholder="Ηλικία από" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <input type="number" name="max_age" placeholder="Ηλικία έως" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <input type="number" step="0.1" name="min_weight" placeholder="Βάρος από (kg)" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <input type="number" step="0.1" name="max_weight" placeholder="Βάρος έως (kg)" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <input type="text" name="belt_from" placeholder="Ζώνη από" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <input type="text" name="belt_to" placeholder="Ζώνη έως" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <input type="text" name="style" placeholder="Στυλ (kata/kumite)" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <input type="number" name="max_slots" placeholder="Max θέσεις" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <input type="number" step="0.01" name="fee_override" placeholder="Χρέωση κατηγορίας (€)" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <select name="format" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
          <option value="single_elim">Single elimination</option>
          <option value="double_elim">Double elimination</option>
          <option value="round_robin">Round robin</option>
          <option value="pool_ko">Pool + KO</option>
          <option value="pool_only">Μόνο pool</option>
          <option value="exhibition">Επίδειξη</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:.85rem"><i class="fa-solid fa-plus"></i> Προσθήκη</button>
    </form>

    <!-- LIST -->
    <?php if (!$categories): ?>
      <p style="color:#6b7494">Δεν έχουν οριστεί κατηγορίες ακόμα.</p>
    <?php else: ?>
      <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;overflow:hidden">
        <table style="width:100%;border-collapse:collapse">
          <thead style="background:#0d1017">
            <tr style="color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em">
              <th style="text-align:left;padding:.7rem 1rem">Όνομα</th>
              <th style="text-align:left;padding:.7rem 1rem">Φύλο</th>
              <th style="text-align:left;padding:.7rem 1rem">Ηλικία</th>
              <th style="text-align:left;padding:.7rem 1rem">Βάρος</th>
              <th style="text-align:left;padding:.7rem 1rem">Format</th>
              <th style="text-align:left;padding:.7rem 1rem">Χρέωση</th>
              <th style="padding:.7rem 1rem"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $c): ?>
            <tr style="border-top:1px solid #1e2536;color:#f0f2ff">
              <td style="padding:.7rem 1rem;font-weight:700"><?= h($c['name']) ?></td>
              <td style="padding:.7rem 1rem"><?= ['M'=>'Α','F'=>'Γ','MX'=>'Μικτό'][$c['gender']] ?></td>
              <td style="padding:.7rem 1rem"><?= h(($c['min_age']??'—') . '-' . ($c['max_age']??'—')) ?></td>
              <td style="padding:.7rem 1rem"><?= h(($c['min_weight']??'—') . '-' . ($c['max_weight']??'—')) ?></td>
              <td style="padding:.7rem 1rem"><?= h($c['format']) ?></td>
              <td style="padding:.7rem 1rem"><?= $c['fee_override']!==null ? number_format((float)$c['fee_override'],2,',','.').'€' : '<i style="color:#6b7494">default</i>' ?></td>
              <td style="padding:.7rem 1rem;text-align:right;white-space:nowrap">
                <a href="<?= APP_URL ?>/pages/event_bracket.php?id=<?= $id ?>&cat=<?= (int)$c['id'] ?>" class="btn btn-ghost btn-sm" title="Bracket">
                  <i class="fa-solid fa-diagram-project"></i> Bracket
                </a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Διαγραφή κατηγορίας;')">
                  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                  <input type="hidden" name="_action" value="cat_delete">
                  <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-ghost btn-sm" style="color:#e63946"><i class="fa-solid fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'registrations'): ?>
    <?php if (!$registrations): ?>
      <p style="color:#6b7494">Δεν υπάρχουν εγγραφές ακόμα. Μόλις μοιραστείς τον δημόσιο σύνδεσμο, θα εμφανίζονται εδώ.</p>
    <?php else: ?>
      <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;overflow:auto">
        <table style="width:100%;border-collapse:collapse;min-width:800px">
          <thead style="background:#0d1017">
            <tr style="color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em">
              <th style="text-align:left;padding:.7rem 1rem">Αθλητής</th>
              <th style="text-align:left;padding:.7rem 1rem">Σύλλογος</th>
              <th style="text-align:left;padding:.7rem 1rem">Κατηγορία</th>
              <th style="text-align:left;padding:.7rem 1rem">Κατάσταση</th>
              <th style="text-align:left;padding:.7rem 1rem">Πληρωμή</th>
              <th style="padding:.7rem 1rem">Ενέργειες</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($registrations as $r): ?>
            <tr style="border-top:1px solid #1e2536;color:#f0f2ff">
              <td style="padding:.7rem 1rem;font-weight:700"><?= h($r['athlete_name'] ?? '—') ?></td>
              <td style="padding:.7rem 1rem"><?= h($r['school_name'] ?? '—') ?></td>
              <td style="padding:.7rem 1rem"><?= h($r['cat_name'] ?? '—') ?></td>
              <td style="padding:.7rem 1rem"><?= eventRegStatusBadge($r['status']) ?></td>
              <td style="padding:.7rem 1rem"><?= eventPaymentStatusBadge($r['payment_status']) ?></td>
              <td style="padding:.7rem 1rem;text-align:right;white-space:nowrap">
                <?php if ($r['status'] === 'pending'): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                  <input type="hidden" name="_action" value="reg_status">
                  <input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="status" value="approved">
                  <button class="btn btn-sm" style="background:#2dc653;color:#000"><i class="fa-solid fa-check"></i></button>
                </form>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                  <input type="hidden" name="_action" value="reg_status">
                  <input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="status" value="rejected">
                  <button class="btn btn-sm" style="background:#e63946;color:#fff"><i class="fa-solid fa-xmark"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'payments'): ?>
    <?php if (!$payments): ?>
      <p style="color:#6b7494">Δεν υπάρχουν πληρωμές ακόμα.</p>
    <?php else: ?>
      <?php foreach ($payments as $p): ?>
        <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.25rem;margin-bottom:.75rem">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
            <div>
              <div style="font-size:.72rem;text-transform:uppercase;color:#6b7494;font-weight:700"><?= h($p['school_name'] ?? '—') ?></div>
              <div style="font-size:1.15rem;color:#f0f2ff;font-weight:800;margin:.2rem 0"><?= number_format((float)$p['amount'],2,',','.') ?> €</div>
              <div style="color:#8892b0;font-size:.85rem">
                <?= h(strtoupper($p['method'])) ?> · Ref: <code style="background:#0d1017;padding:.1rem .4rem;border-radius:4px"><?= h($p['reference_code']) ?></code>
              </div>
            </div>
            <div style="text-align:right">
              <div style="margin-bottom:.5rem">
                <span class="badge <?= $p['status']==='verified'?'badge-paid':($p['status']==='rejected'?'badge-overdue':'badge-pending') ?>">
                  <?= h(['pending'=>'Εκκρεμεί','proof_uploaded'=>'Απόδειξη','verified'=>'Επιβεβαιωμένη','rejected'=>'Απορρίφθηκε','refunded'=>'Επιστράφηκε'][$p['status']] ?? $p['status']) ?>
                </span>
              </div>
              <?php if ($p['proof_file_path']): ?>
                <a href="<?= APP_URL ?>/events/download.php?p=<?= (int)$p['id'] ?>" class="btn btn-ghost btn-sm" target="_blank"><i class="fa-solid fa-file"></i> Προβολή απόδειξης</a>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($p['status'] !== 'verified' && $p['status'] !== 'rejected' && $p['status'] !== 'refunded'): ?>
            <div style="margin-top:.85rem;padding-top:.85rem;border-top:1px solid #1e2536;display:flex;gap:.5rem;flex-wrap:wrap">
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="_action" value="pay_verify">
                <input type="hidden" name="pay_id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm" style="background:#2dc653;color:#000"><i class="fa-solid fa-check"></i> Επιβεβαίωση</button>
              </form>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="_action" value="pay_reject">
                <input type="hidden" name="pay_id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm" style="background:#e63946;color:#fff"><i class="fa-solid fa-xmark"></i> Απόρριψη</button>
              </form>
            </div>
          <?php elseif ($p['status'] === 'verified'): ?>
            <?php $q = eventPaymentRefundQuote($ev, $p); ?>
            <div style="margin-top:.85rem;padding-top:.85rem;border-top:1px solid #1e2536">
              <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap" onsubmit="return confirm('Επιστροφή χρημάτων;')">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="_action" value="pay_refund">
                <input type="hidden" name="pay_id" value="<?= (int)$p['id'] ?>">
                <label style="color:#8892b0;font-size:.82rem">Ποσό επιστροφής (€)
                  <input type="number" step="0.01" min="0" max="<?= (float)$p['amount'] ?>" name="refund_amount" value="<?= number_format($q['amount'], 2, '.', '') ?>" style="padding:.4rem;background:#0d1017;border:1px solid #2a3248;border-radius:6px;color:#f0f2ff;width:100px">
                </label>
                <input type="text" name="notes" placeholder="Σημείωση" style="padding:.4rem;background:#0d1017;border:1px solid #2a3248;border-radius:6px;color:#f0f2ff;flex:1;min-width:180px">
                <button class="btn btn-sm" style="background:#f0a500;color:#000"><i class="fa-solid fa-rotate-left"></i> Επιστροφή</button>
              </form>
              <div style="color:#6b7494;font-size:.75rem;margin-top:.35rem"><?= h($q['reason']) ?> — προτεινόμενο <?= (int)$q['pct'] ?>% (<?= number_format($q['amount'], 2, ',', '.') ?>€)</div>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php elseif ($tab === 'fields'): ?>
    <!-- ADD/EDIT CUSTOM FIELD -->
    <?php
    $editingField = null;
    if (isset($_GET['field'])) foreach ($customFields as $cf) if ((int)$cf['id']===(int)$_GET['field']) { $editingField = $cf; break; }
    ?>
    <form method="POST" style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem;margin-bottom:1rem">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="_action" value="field_save">
      <input type="hidden" name="field_id" value="<?= $editingField ? (int)$editingField['id'] : '' ?>">
      <h3 style="margin:0 0 1rem;color:#e63946;font-size:.95rem;text-transform:uppercase;letter-spacing:.08em">
        <?= $editingField ? 'Επεξεργασία πεδίου' : '+ Νέο πεδίο εγγραφής' ?>
      </h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
        <input type="text" name="label" required maxlength="160" placeholder="Ετικέτα (π.χ. Μέγεθος T-shirt)" value="<?= h($editingField['label'] ?? '') ?>" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <input type="text" name="code" maxlength="60" placeholder="Κωδικός (auto)" value="<?= h($editingField['code'] ?? '') ?>" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff;font-family:monospace">
        <select name="field_type" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
          <?php foreach (['text'=>'Κείμενο','textarea'=>'Πολυγραμμικό','select'=>'Λίστα','number'=>'Αριθμός','date'=>'Ημερομηνία','checkbox'=>'Checkbox'] as $v=>$lbl): ?>
            <option value="<?= $v ?>" <?= ($editingField['field_type']??'text')===$v?'selected':'' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="number" name="display_order" placeholder="Σειρά" value="<?= (int)($editingField['display_order'] ?? 0) ?>" style="padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
        <label style="display:flex;align-items:center;gap:.4rem;color:#c8cfe0;padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px">
          <input type="checkbox" name="required" value="1" <?= !empty($editingField['required'])?'checked':'' ?>>
          <span>Υποχρεωτικό</span>
        </label>
      </div>
      <label style="display:block;margin-top:.75rem">
        <div style="font-size:.82rem;color:#c8cfe0;font-weight:700;margin-bottom:.3rem">Options (μόνο για Λίστα — μία επιλογή ανά γραμμή)</div>
        <textarea name="options" rows="3" style="width:100%;padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff;font-family:inherit"><?= h($editingField['options'] ?? '') ?></textarea>
      </label>
      <label style="display:block;margin-top:.75rem">
        <div style="font-size:.82rem;color:#c8cfe0;font-weight:700;margin-bottom:.3rem">Help text</div>
        <input type="text" name="help_text" maxlength="255" value="<?= h($editingField['help_text'] ?? '') ?>" style="width:100%;padding:.6rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
      </label>
      <div style="margin-top:.85rem"><button class="btn btn-primary"><i class="fa-solid fa-save"></i> Αποθήκευση</button>
      <?php if ($editingField): ?><a href="?id=<?= $id ?>&tab=fields" class="btn btn-ghost">Άκυρο</a><?php endif; ?>
      </div>
    </form>

    <!-- LIST -->
    <?php if (!$customFields): ?>
      <p style="color:#8892b0">Δεν έχετε ορίσει επιπλέον πεδία. Παραδείγματα: T-shirt size (camp), Insurance, Ειδικές διατροφικές απαιτήσεις.</p>
    <?php else: ?>
      <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;overflow:hidden">
        <table style="width:100%;border-collapse:collapse">
          <thead style="background:#0d1017">
            <tr style="color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em">
              <th style="text-align:left;padding:.7rem 1rem">Ετικέτα</th>
              <th style="text-align:left;padding:.7rem 1rem">Κωδικός</th>
              <th style="text-align:left;padding:.7rem 1rem">Τύπος</th>
              <th style="text-align:center;padding:.7rem 1rem">Υποχρ.</th>
              <th style="padding:.7rem 1rem"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($customFields as $f): ?>
            <tr style="border-top:1px solid #1e2536;color:#f0f2ff">
              <td style="padding:.7rem 1rem;font-weight:700"><?= h($f['label']) ?></td>
              <td style="padding:.7rem 1rem"><code style="background:#0d1017;padding:.1rem .4rem;border-radius:4px"><?= h($f['code']) ?></code></td>
              <td style="padding:.7rem 1rem"><?= h($f['field_type']) ?></td>
              <td style="padding:.7rem 1rem;text-align:center"><?= $f['required'] ? '✓' : '·' ?></td>
              <td style="padding:.7rem 1rem;text-align:right;white-space:nowrap">
                <a href="?id=<?= $id ?>&tab=fields&field=<?= (int)$f['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-pen"></i></a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Διαγραφή;')">
                  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                  <input type="hidden" name="_action" value="field_delete">
                  <input type="hidden" name="field_id" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn-ghost btn-sm" style="color:#e63946"><i class="fa-solid fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'updates'): ?>
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.5rem;text-align:center">
      <p style="color:#c8cfe0;margin-bottom:1rem">Διαχείριση ανακοινώσεων του event.</p>
      <a href="<?= APP_URL ?>/pages/event_updates.php?id=<?= $id ?>" class="btn btn-primary"><i class="fa-solid fa-bullhorn"></i> Άνοιγμα Ενημερώσεων</a>
    </div>

  <?php elseif ($tab === 'share'): ?>
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.5rem">
      <h3 style="margin:0 0 .75rem;color:#e63946;font-size:1rem">Δημόσιος σύνδεσμος</h3>
      <?php $url = eventPublicUrl($ev); ?>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <input type="text" readonly value="<?= h($url) ?>" onclick="this.select()" style="flex:1;min-width:280px;padding:.75rem 1rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff;font-family:monospace;font-size:.9rem">
        <a href="<?= h($url) ?>" target="_blank" class="btn btn-primary"><i class="fa-solid fa-arrow-up-right-from-square"></i> Άνοιγμα</a>
      </div>
      <p style="color:#6b7494;font-size:.85rem;margin:.85rem 0 0">
        Μοιράσου αυτόν τον σύνδεσμο σε άλλους συλλόγους, σε Viber / Facebook / Instagram. Ο καθένας μπορεί να δει το event και να δηλώσει συμμετοχή για τους αθλητές του (εφόσον έχει MAster λογαριασμό).
      </p>
      <?php if ($ev['visibility'] !== 'public'): ?>
        <div style="margin-top:1rem;padding:.85rem 1rem;background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.35);border-radius:8px;color:#ffb3b8">
          ⚠ Η ορατότητα είναι "<?= h(eventVisibilityLabel($ev['visibility'])) ?>". Ο σύνδεσμος λειτουργεί, αλλά το event δεν εμφανίζεται στη δημόσια λίστα.
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
</div>
</div>
</body></html>
