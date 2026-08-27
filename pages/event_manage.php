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
        // ── Bulk mark all pending regs of a school as paid ──
        if ($action === 'mark_school_paid') {
            $psid = (int)($_POST['school_id'] ?? 0);
            if ($psid > 0) {
                $upd = getDB()->prepare("
                    UPDATE event_registrations
                       SET payment_status = 'verified',
                           paid_at        = NOW(),
                           verified_at    = NOW(),
                           verified_by    = ?
                     WHERE event_id = ?
                       AND registering_school_id = ?
                       AND payment_status IN ('unpaid','proof_uploaded')
                       AND status NOT IN ('rejected','withdrawn')
                ");
                $upd->execute([$userId, $id, $psid]);
                $n = $upd->rowCount();
                if (function_exists('auditLog')) auditLog('event_bulk_paid', 'event', $id, "school=$psid n=$n");
                flash("Σημάνθηκαν $n εγγραφές ως πληρωμένες.");
            }
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
        <a href="<?= APP_URL ?>/pages/event_draws_print.php?id=<?= (int)$ev['id'] ?>" target="_blank"
           class="btn btn-ghost btn-sm"
           style="background:linear-gradient(135deg,rgba(59,130,246,.14),rgba(37,99,235,.05));border:1px solid rgba(59,130,246,.35);color:#93c5fd">
          <i class="fa-solid fa-print"></i> Εκτύπωση Κληρώσεων
        </a>
        <a href="<?= APP_URL ?>/pages/event_bracket.php?id=<?= (int)$ev['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-sitemap"></i> Λίστες / Pools</a>
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
        'fields' => 'Ειδικά πεδία (' . count($customFields) . ')',
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
    <!-- ADD CATEGORY (button opens modal) -->
    <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
      <div style="color:#a9b3c9;font-size:.92rem">
        <?= count($categories) ?> κατηγορίες · Ορίζονται από τον διοργανωτή για κάθε πρωτάθλημα.
      </div>
      <button type="button" class="btn btn-primary" onclick="openModal('modalAddCategory')" style="min-height:44px;font-size:.98rem">
        <i class="fa-solid fa-plus"></i> Προσθήκη Κατηγορίας
      </button>
    </div>

    <!-- Modal -->
    <div id="modalAddCategory" class="ev-modal-backdrop" role="dialog" aria-modal="true"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(5px);z-index:10500;align-items:center;justify-content:center;padding:1rem"
         onclick="if(event.target===this)closeModal('modalAddCategory')">
      <div style="background:#111520;border:1px solid #1e2536;border-radius:16px;max-width:600px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 30px 80px rgba(0,0,0,.6)">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.35rem;border-bottom:1px solid #1e2536">
          <h3 style="margin:0;font-size:1.05rem;color:#fff;font-weight:800">
            <i class="fa-solid fa-plus" style="color:#e63946"></i> Νέα Κατηγορία
          </h3>
          <button type="button" onclick="closeModal('modalAddCategory')"
                  style="background:rgba(255,255,255,.05);border:1px solid #2a3248;color:#fff;width:36px;height:36px;border-radius:10px;cursor:pointer">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <form method="POST" style="padding:1.1rem 1.35rem">
          <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
          <input type="hidden" name="_action" value="cat_create">
          <div style="display:grid;grid-template-columns:1fr;gap:.85rem">
            <label>
              <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Όνομα κατηγορίας *</div>
              <input type="text" name="name" placeholder="π.χ. -60kg U18 Άνδρες" required
                     style="width:100%;padding:.9rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:1rem;min-height:48px">
            </label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Φύλο</div>
                <select name="gender" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
                  <option value="MX">Μικτό</option>
                  <option value="M">Άνδρες</option>
                  <option value="F">Γυναίκες</option>
                </select>
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Format αγώνων</div>
                <select name="format" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
                  <?php foreach (['single_elim','double_elim','round_robin','pool_ko','pool_only','exhibition'] as $__f): ?>
                    <option value="<?= $__f ?>"><?= h(eventFormatLabel($__f)) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Ηλικία από</div>
                <input type="number" name="min_age" min="1" max="99" placeholder="π.χ. 14" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Ηλικία έως</div>
                <input type="number" name="max_age" min="1" max="99" placeholder="π.χ. 17" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Βάρος από (kg)</div>
                <input type="number" step="0.1" name="min_weight" placeholder="π.χ. 55" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Βάρος έως (kg)</div>
                <input type="number" step="0.1" name="max_weight" placeholder="π.χ. 60" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Ζώνη από</div>
                <input type="text" name="belt_from" placeholder="π.χ. Πράσινη" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Ζώνη έως</div>
                <input type="text" name="belt_to" placeholder="π.χ. Μαύρη 1st Dan" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Στυλ</div>
                <select name="style" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
                  <option value="">— Επιλογή —</option>
                  <option value="kumite">Αγώνων (Kumite)</option>
                  <option value="kata">Φόρμας (Kata)</option>
                </select>
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Max θέσεις</div>
                <input type="number" name="max_slots" min="0" placeholder="άπειρες" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              </label>
              <label style="grid-column:1/-1">
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Χρέωση κατηγορίας (€) — αν διαφέρει από την προεπιλεγμένη</div>
                <input type="number" step="0.01" name="fee_override" placeholder="κενό = default του event" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              </label>
            </div>
          </div>
          <div style="display:flex;gap:.5rem;justify-content:flex-end;flex-wrap:wrap;margin-top:1.2rem;padding-top:1rem;border-top:1px solid #1e2536">
            <button type="button" onclick="closeModal('modalAddCategory')" class="btn" style="background:rgba(255,255,255,.06);border:1px solid #2a3248;color:#fff;min-height:48px;padding:.7rem 1.2rem;font-size:.95rem;font-weight:700">
              Άκυρο
            </button>
            <button type="submit" class="btn btn-primary" style="min-height:48px;padding:.7rem 1.4rem;font-size:.98rem;font-weight:800">
              <i class="fa-solid fa-check"></i> Δημιουργία
            </button>
          </div>
        </form>
      </div>
    </div>

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
              <td style="padding:.7rem 1rem"><?= h(eventFormatLabel($c['format'] ?? '')) ?></td>
              <td style="padding:.7rem 1rem"><?= $c['fee_override']!==null ? number_format((float)$c['fee_override'],2,',','.').'€' : '<i style="color:#6b7494">default</i>' ?></td>
              <td style="padding:.7rem 1rem;text-align:right;white-space:nowrap">
                <a href="<?= APP_URL ?>/pages/event_draws_print.php?id=<?= $id ?>&cat=<?= (int)$c['id'] ?>" target="_blank" class="btn btn-ghost btn-sm" title="Εκτύπωση κληρώσεων κατηγορίας">
                  <i class="fa-solid fa-print"></i>
                </a>
                <a href="<?= APP_URL ?>/pages/event_bracket.php?id=<?= $id ?>&cat=<?= (int)$c['id'] ?>" class="btn btn-ghost btn-sm" title="Κληρώσεις κατηγορίας">
                  <i class="fa-solid fa-diagram-project"></i> Κληρώσεις
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
    <?php else:
      // Unique school + category lists for the filter dropdowns
      $regSchoolOpts = [];
      $regCatOpts    = [];
      foreach ($registrations as $r) {
          if (!empty($r['school_name'])) $regSchoolOpts[$r['school_name']] = true;
          if (!empty($r['cat_name']))    $regCatOpts[$r['cat_name']]      = true;
      }
      ksort($regSchoolOpts); ksort($regCatOpts);
    ?>
      <!-- Filters + live search -->
      <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1rem 1.1rem;margin-bottom:1rem;
                  display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.65rem;align-items:end">
        <div>
          <label style="display:block;font-size:.7rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Σύλλογος</label>
          <select id="regFilterSchool" onchange="regApplyFilters()"
                  style="width:100%;padding:.55rem .7rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:8px;color:#fff;font-size:.9rem;min-height:44px">
            <option value="">— Όλοι —</option>
            <?php foreach (array_keys($regSchoolOpts) as $sn): ?>
              <option value="<?= h(mb_strtolower($sn, 'UTF-8')) ?>"><?= h($sn) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:.7rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Κατηγορία</label>
          <select id="regFilterCat" onchange="regApplyFilters()"
                  style="width:100%;padding:.55rem .7rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:8px;color:#fff;font-size:.9rem;min-height:44px">
            <option value="">— Όλες —</option>
            <?php foreach (array_keys($regCatOpts) as $cn): ?>
              <option value="<?= h(mb_strtolower($cn, 'UTF-8')) ?>"><?= h($cn) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:.7rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Κατάσταση</label>
          <select id="regFilterStatus" onchange="regApplyFilters()"
                  style="width:100%;padding:.55rem .7rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:8px;color:#fff;font-size:.9rem;min-height:44px">
            <option value="">— Όλες —</option>
            <option value="pending">Εκκρεμούν</option>
            <option value="approved">Εγκρίθηκαν</option>
            <option value="checked_in">Παρόντες</option>
            <option value="rejected">Απορρίφθηκαν</option>
            <option value="withdrawn">Ακυρώθηκαν</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:.7rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Πληρωμή</label>
          <select id="regFilterPay" onchange="regApplyFilters()"
                  style="width:100%;padding:.55rem .7rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:8px;color:#fff;font-size:.9rem;min-height:44px">
            <option value="">— Όλες —</option>
            <option value="unpaid">Εκκρεμούν</option>
            <option value="verified">Πληρωμένοι</option>
            <option value="proof_uploaded">Αποδεικτικό ανέβηκε</option>
            <option value="waived">Απαλλαγή</option>
            <option value="refunded">Επιστροφή</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:.7rem;font-weight:700;color:#a9b3c9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem">Αναζήτηση</label>
          <input type="text" id="regFilterQ" placeholder="Όνομα αθλητή…" oninput="regApplyFilters()"
                 style="width:100%;padding:.55rem .7rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:8px;color:#fff;font-size:.9rem;min-height:44px">
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
          <button type="button" onclick="regClearFilters()"
                  style="background:rgba(255,255,255,.06);color:#fff;border:1px solid #2a3248;padding:.55rem 1rem;border-radius:8px;font-weight:700;cursor:pointer;min-height:44px">
            <i class="fa-solid fa-rotate-left"></i> Καθαρισμός
          </button>
          <span id="regFilterCount" style="color:#8892b0;font-size:.85rem">
            <?= count($registrations) ?> / <?= count($registrations) ?>
          </span>
        </div>
      </div>

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
          <tbody id="regTbody">
          <?php foreach ($registrations as $r): ?>
            <tr class="reg-row"
                data-name="<?= h(mb_strtolower($r['athlete_name'] ?? '', 'UTF-8')) ?>"
                data-school="<?= h(mb_strtolower($r['school_name'] ?? '', 'UTF-8')) ?>"
                data-cat="<?= h(mb_strtolower($r['cat_name'] ?? '', 'UTF-8')) ?>"
                data-status="<?= h($r['status'] ?? '') ?>"
                data-pay="<?= h($r['payment_status'] ?? '') ?>"
                style="border-top:1px solid #1e2536;color:#f0f2ff">
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
          <tbody id="regEmptyBody" style="display:none">
            <tr><td colspan="6" style="padding:2rem;text-align:center;color:#8892b0">
              <i class="fa-solid fa-magnifying-glass" style="font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.5"></i>
              Δεν βρέθηκαν εγγραφές με τα φίλτρα.
            </td></tr>
          </tbody>
        </table>
      </div>

      <script>
      (function(){
        var rows = document.querySelectorAll('#regTbody .reg-row');
        var totalCount = rows.length;
        window.regApplyFilters = function() {
          var sc = (document.getElementById('regFilterSchool').value || '').toLowerCase();
          var cat= (document.getElementById('regFilterCat').value    || '').toLowerCase();
          var st = document.getElementById('regFilterStatus').value  || '';
          var py = document.getElementById('regFilterPay').value     || '';
          var q  = (document.getElementById('regFilterQ').value      || '').toLowerCase().trim();
          var shown = 0;
          rows.forEach(function(r){
            var ok = true;
            if (sc  && r.getAttribute('data-school') !== sc)  ok = false;
            if (cat && r.getAttribute('data-cat')    !== cat) ok = false;
            if (st  && r.getAttribute('data-status') !== st)  ok = false;
            if (py  && r.getAttribute('data-pay')    !== py)  ok = false;
            if (q   && (r.getAttribute('data-name') || '').indexOf(q) === -1) ok = false;
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
          });
          var cnt = document.getElementById('regFilterCount');
          if (cnt) cnt.textContent = shown + ' / ' + totalCount;
          document.getElementById('regEmptyBody').style.display = shown === 0 ? '' : 'none';
        };
        window.regClearFilters = function() {
          ['regFilterSchool','regFilterCat','regFilterStatus','regFilterPay','regFilterQ']
            .forEach(function(id){ var el = document.getElementById(id); if (el) el.value = ''; });
          regApplyFilters();
        };
      })();
      </script>
    <?php endif; ?>

  <?php elseif ($tab === 'payments'): ?>
    <!-- ══ Εκκρεμείς Σχολές (grouped from registrations) ══ -->
    <?php
      // Aggregate unpaid registrations per school for this event
      $pendingBySchool = [];
      foreach ($registrations as $r) {
          if (!in_array($r['payment_status'], ['unpaid','proof_uploaded'], true)) continue;
          if (in_array($r['status'], ['rejected','withdrawn'], true)) continue;
          $key = (int)$r['registering_school_id'];
          if (!isset($pendingBySchool[$key])) {
              $pendingBySchool[$key] = [
                  'name'   => $r['school_name'] ?? '—',
                  'count'  => 0,
                  'total'  => 0.0,
                  'proof'  => 0,
                  'ids'    => [],
              ];
          }
          $pendingBySchool[$key]['count']++;
          $pendingBySchool[$key]['total'] += (float)$r['amount'];
          if ($r['payment_status'] === 'proof_uploaded') $pendingBySchool[$key]['proof']++;
          $pendingBySchool[$key]['ids'][] = (int)$r['id'];
      }
      $totalPendingClubs   = count($pendingBySchool);
      $totalPendingAmount  = array_sum(array_column($pendingBySchool, 'total'));
      // sort by biggest debt first
      uasort($pendingBySchool, fn($a,$b) => $b['total'] <=> $a['total']);
    ?>
    <?php if ($totalPendingClubs > 0): ?>
      <div style="background:#111520;border:1px solid rgba(230,57,70,.35);border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:1rem">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.85rem">
          <h3 style="margin:0;color:#ff8891;font-size:1rem;font-weight:800;display:flex;align-items:center;gap:.5rem">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Σχολές με εκκρεμείς πληρωμές
            <span style="background:rgba(230,57,70,.2);color:#ff8891;padding:.1rem .55rem;border-radius:50px;font-size:.72rem;font-weight:900"><?= $totalPendingClubs ?></span>
          </h3>
          <div style="color:#c8cfe0;font-size:.9rem">
            Συνολικό υπόλοιπο: <strong style="color:#f0a500"><?= number_format($totalPendingAmount, 2, ',', '.') ?> €</strong>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.7rem">
          <?php foreach ($pendingBySchool as $psid => $ps): ?>
            <div style="background:#0d1017;border:1px solid #1e2536;border-radius:10px;padding:.85rem 1rem">
              <div style="font-weight:800;color:#fff;font-size:.98rem;margin-bottom:.35rem"><?= h($ps['name']) ?></div>
              <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;color:#8892b0;font-size:.82rem">
                <span><?= $ps['count'] ?> συμμετοχές<?php if ($ps['proof']): ?> · <span style="color:#93c5fd"><?= $ps['proof'] ?> με αποδεικτικό</span><?php endif; ?></span>
                <strong style="color:#ff8891"><?= number_format($ps['total'], 2, ',', '.') ?> €</strong>
              </div>
              <div style="margin-top:.55rem;display:flex;gap:.35rem;flex-wrap:wrap">
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Σήμανση ΟΛΩΝ των <?= $ps['count'] ?> εγγραφών του <?= h($ps['name']) ?> ως Πληρωμένες;')">
                  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                  <input type="hidden" name="_action" value="mark_school_paid">
                  <input type="hidden" name="school_id" value="<?= (int)$psid ?>">
                  <button type="submit" title="Σήμανση όλων ως πληρωμένες"
                          style="background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;padding:.4rem .7rem;border-radius:8px;font-weight:700;font-size:.78rem;cursor:pointer">
                    <i class="fa-solid fa-check"></i> Έλαβα όλη την πληρωμή
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$payments && $totalPendingClubs === 0): ?>
      <p style="color:#6b7494">Δεν υπάρχουν εκκρεμείς ή καταχωρημένες πληρωμές ακόμα.</p>
    <?php elseif (!$payments): ?>
      <p style="color:#8892b0;font-size:.9rem">Δεν έχουν καταχωρηθεί αιτήματα πληρωμής μέσω του portal ακόμα — οι εγγραφές των παραπάνω σχολών εμφανίζονται ως εκκρεμείς.</p>
    <?php endif; ?>

    <?php if ($payments): ?>
      <?php foreach ($payments as $p):
        $__pmeta = $p['meta'] ? json_decode($p['meta'], true) : [];
        $__pnote = $__pmeta['payer_note'] ?? '';
        $__mLbl  = ['bank'=>'Τραπεζικό έμβασμα','iris'=>'IRIS','cash'=>'Μετρητά'][$p['method']] ?? strtoupper($p['method']);
      ?>
        <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.25rem;margin-bottom:.75rem">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
            <div>
              <div style="font-size:.72rem;text-transform:uppercase;color:#6b7494;font-weight:700"><?= h($p['school_name'] ?? '—') ?></div>
              <div style="font-size:1.15rem;color:#f0f2ff;font-weight:800;margin:.2rem 0"><?= number_format((float)$p['amount'],2,',','.') ?> €</div>
              <div style="color:#8892b0;font-size:.85rem">
                <?= h($__mLbl) ?> · Ref: <code style="background:#0d1017;padding:.1rem .4rem;border-radius:4px"><?= h($p['reference_code']) ?></code>
              </div>
              <?php if ($__pnote !== ''): ?>
              <div style="margin-top:.55rem;padding:.55rem .75rem;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.25);border-radius:8px;color:#c8dbff;font-size:.85rem;line-height:1.45">
                <i class="fa-solid fa-quote-left" style="color:#3b82f6;font-size:.72rem;margin-right:.35rem"></i>
                <?= nl2br(h($__pnote)) ?>
              </div>
              <?php endif; ?>
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
    <?php
    $editingField = null;
    if (isset($_GET['field'])) foreach ($customFields as $cf) if ((int)$cf['id']===(int)$_GET['field']) { $editingField = $cf; break; }
    $modalOpenOnLoad = $editingField ? true : false;
    ?>
    <!-- Header + open modal button -->
    <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
      <div style="color:#a9b3c9;font-size:.92rem">
        <?= count($customFields) ?> ειδικά πεδία · Επιπλέον στοιχεία που ζητούνται στην εγγραφή (T-shirt, ασφάλεια, διατροφή…).
      </div>
      <button type="button" class="btn btn-primary" onclick="openModal('modalAddField')" style="min-height:44px;font-size:.98rem">
        <i class="fa-solid fa-plus"></i> Νέο Ειδικό Πεδίο
      </button>
    </div>

    <!-- Modal (add/edit special field) -->
    <div id="modalAddField" class="ev-modal-backdrop" role="dialog" aria-modal="true"
         style="display:<?= $modalOpenOnLoad ? 'flex' : 'none' ?>;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(5px);z-index:10500;align-items:center;justify-content:center;padding:1rem"
         onclick="if(event.target===this)closeModal('modalAddField')">
      <div style="background:#111520;border:1px solid #1e2536;border-radius:16px;max-width:560px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 30px 80px rgba(0,0,0,.6)">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.35rem;border-bottom:1px solid #1e2536">
          <h3 style="margin:0;font-size:1.05rem;color:#fff;font-weight:800">
            <i class="fa-solid <?= $editingField ? 'fa-pen' : 'fa-plus' ?>" style="color:#e63946"></i>
            <?= $editingField ? 'Επεξεργασία πεδίου' : 'Νέο Ειδικό Πεδίο' ?>
          </h3>
          <a href="?id=<?= $id ?>&tab=fields" onclick="closeModal('modalAddField');return true"
             style="background:rgba(255,255,255,.05);border:1px solid #2a3248;color:#fff;width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none">
            <i class="fa-solid fa-xmark"></i>
          </a>
        </div>
        <form method="POST" style="padding:1.1rem 1.35rem">
          <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
          <input type="hidden" name="_action" value="field_save">
          <input type="hidden" name="field_id" value="<?= $editingField ? (int)$editingField['id'] : '' ?>">
          <div style="display:grid;grid-template-columns:1fr;gap:.85rem">
            <label>
              <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Ετικέτα *</div>
              <input type="text" name="label" required maxlength="160" placeholder="π.χ. Μέγεθος T-shirt" value="<?= h($editingField['label'] ?? '') ?>"
                     style="width:100%;padding:.85rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:1rem;min-height:48px">
            </label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Τύπος</div>
                <select name="field_type" style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
                  <?php foreach (['text'=>'Κείμενο','textarea'=>'Πολυγραμμικό','select'=>'Λίστα','number'=>'Αριθμός','date'=>'Ημερομηνία','checkbox'=>'Checkbox'] as $v=>$lbl): ?>
                    <option value="<?= $v ?>" <?= ($editingField['field_type']??'text')===$v?'selected':'' ?>><?= h($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Σειρά εμφάνισης</div>
                <input type="number" name="display_order" placeholder="0" value="<?= (int)($editingField['display_order'] ?? 0) ?>"
                       style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
              </label>
            </div>
            <label style="display:flex;align-items:center;gap:.7rem;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;cursor:pointer;min-height:48px">
              <input type="checkbox" name="required" value="1" <?= !empty($editingField['required'])?'checked':'' ?> style="width:20px;height:20px;accent-color:#e63946">
              <span style="color:#fff;font-weight:700;font-size:.95rem">Υποχρεωτικό στην εγγραφή</span>
            </label>
            <label>
              <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Επιλογές (μόνο για Λίστα — μία ανά γραμμή)</div>
              <textarea name="options" rows="3" placeholder="XS&#10;S&#10;M&#10;L&#10;XL"
                        style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-family:inherit;font-size:.95rem;resize:vertical;min-height:80px"><?= h($editingField['options'] ?? '') ?></textarea>
            </label>
            <label>
              <div style="font-size:.82rem;font-weight:700;color:#c9cee1;margin-bottom:.3rem">Βοηθητικό κείμενο (προαιρετικό)</div>
              <input type="text" name="help_text" maxlength="255" value="<?= h($editingField['help_text'] ?? '') ?>"
                     placeholder="π.χ. Απαιτείται για την παράδοση του πακέτου"
                     style="width:100%;padding:.75rem 1rem;background:#0d1017;border:1.5px solid #2a3248;border-radius:10px;color:#fff;font-size:.95rem;min-height:48px">
            </label>
            <input type="hidden" name="code" value="<?= h($editingField['code'] ?? '') ?>">
          </div>
          <div style="display:flex;gap:.5rem;justify-content:flex-end;flex-wrap:wrap;margin-top:1.2rem;padding-top:1rem;border-top:1px solid #1e2536">
            <a href="?id=<?= $id ?>&tab=fields" class="btn" style="background:rgba(255,255,255,.06);border:1px solid #2a3248;color:#fff;min-height:48px;padding:.7rem 1.2rem;font-size:.95rem;font-weight:700;text-decoration:none;display:inline-flex;align-items:center">
              Άκυρο
            </a>
            <button type="submit" class="btn btn-primary" style="min-height:48px;padding:.7rem 1.4rem;font-size:.98rem;font-weight:800">
              <i class="fa-solid fa-save"></i> Αποθήκευση
            </button>
          </div>
        </form>
      </div>
    </div>

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

<script>
/* Simple modal open/close helpers (mobile-friendly, esc-to-close, body scroll lock) */
window.openModal = function(id) {
  var m = document.getElementById(id);
  if (!m) return;
  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  var firstInput = m.querySelector('input[type="text"],input[type="number"],select,textarea');
  if (firstInput) setTimeout(function(){ firstInput.focus(); }, 100);
};
window.closeModal = function(id) {
  var m = document.getElementById(id);
  if (m) m.style.display = 'none';
  document.body.style.overflow = '';
};
document.addEventListener('keydown', function(e){
  if (e.key !== 'Escape') return;
  document.querySelectorAll('.ev-modal-backdrop').forEach(function(m){
    if (m.style.display && m.style.display !== 'none') { m.style.display = 'none'; document.body.style.overflow = ''; }
  });
});
</script>
</body></html>
