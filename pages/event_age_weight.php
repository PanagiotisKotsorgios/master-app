<?php
/**
 * pages/event_age_weight.php — Age groups + weight classes editor
 * ============================================================
 * Organiser defines age groups (e.g. "Παίδες 10-12") and their
 * weight classes (e.g. "-45kg", "-52kg"). Then clicks "Generate"
 * to materialise the cartesian product into event_categories,
 * which the rest of the bracket/registration pipeline consumes.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'age_add') {
            eventAgeGroupCreate($id, $_POST);
            flash('Η ηλικιακή κατηγορία προστέθηκε.', 'success');
        } elseif ($action === 'age_delete') {
            eventAgeGroupDelete((int)$_POST['age_group_id'], $id);
            flash('Η ηλικιακή κατηγορία διαγράφηκε.', 'success');
        } elseif ($action === 'wc_add') {
            $agId = (int)$_POST['age_group_id'];
            eventWeightClassCreate($agId, $_POST);
            flash('Η κατηγορία βάρους προστέθηκε.', 'success');
        } elseif ($action === 'wc_delete') {
            eventWeightClassDelete((int)$_POST['weight_class_id'], (int)$_POST['age_group_id']);
            flash('Η κατηγορία βάρους διαγράφηκε.', 'success');
        } elseif ($action === 'generate') {
            $n = eventGenerateCategoriesFromAgeWeight($id);
            flash($n > 0
                ? "Δημιουργήθηκαν $n νέες κατηγορίες."
                : 'Όλες οι κατηγορίες υπάρχουν ήδη.',
                $n > 0 ? 'success' : 'info');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(APP_URL . '/pages/event_age_weight.php?id=' . (int)$id);
}

$groups = eventAgeGroups($id);

renderHead('Ηλικίες & Βάρη: ' . $ev['title']);
$flash = getFlash();
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Ηλικίες & Βάρη — ' . $ev['title']); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <?php $cbg = ['error'=>'230,57,70','success'=>'45,198,83','info'=>'78,132,255'][$flash['type']] ?? '78,132,255'; ?>
    <div style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(<?= $cbg ?>,.12);border:1px solid rgba(<?= $cbg ?>,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between">
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#e63946;font-weight:700">Ιεραρχία Κατηγοριών</div>
      <h1 style="margin:.25rem 0 .35rem;font-size:1.3rem;color:#f0f2ff"><?= h($ev['title']) ?></h1>
      <p style="color:#8892b0;font-size:.88rem">Ορίστε ηλικιακές κατηγορίες και μέσα σε καθεμία τις κατηγορίες βάρους. Πατήστε <em>Δημιουργία κατηγοριών</em> για να γίνουν αυτόματα event_categories.</p>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="generate">
      <button type="submit" style="padding:.7rem 1.2rem;background:linear-gradient(135deg,#e63946,#c72832);color:#fff;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-family:inherit">
        <i class="fa-solid fa-wand-magic-sparkles"></i> Δημιουργία κατηγοριών
      </button>
    </form>
  </div>

  <!-- Add age group -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
    <h3 style="margin:0 0 .8rem;font-size:1rem;font-weight:800">➕ Προσθήκη ηλικιακής κατηγορίας</h3>
    <form method="POST" style="display:grid;grid-template-columns:2fr .8fr .8fr .8fr auto;gap:.5rem;align-items:end">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="age_add">
      <label style="font-size:.75rem;color:#8892b0">Όνομα
        <input required name="name" placeholder="Παίδες" style="width:100%;padding:.55rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.25rem">
      </label>
      <label style="font-size:.75rem;color:#8892b0">Ελάχ. ηλικία
        <input type="number" min="1" max="99" name="min_age" style="width:100%;padding:.55rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.25rem">
      </label>
      <label style="font-size:.75rem;color:#8892b0">Μέγ. ηλικία
        <input type="number" min="1" max="99" name="max_age" style="width:100%;padding:.55rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.25rem">
      </label>
      <label style="font-size:.75rem;color:#8892b0">Φύλο
        <select name="gender" style="width:100%;padding:.55rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.25rem">
          <option value="MX">Και τα δύο</option>
          <option value="M">Άνδρες / Αγόρια</option>
          <option value="F">Γυναίκες / Κορίτσια</option>
        </select>
      </label>
      <button type="submit" style="padding:.55rem 1rem;background:rgba(45,198,83,.15);border:1px solid rgba(45,198,83,.35);color:#a9ffcb;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit">
        <i class="fa-solid fa-plus"></i>
      </button>
    </form>
  </div>

  <!-- Age groups list -->
  <?php if (!$groups): ?>
    <div style="padding:2.5rem 1.5rem;text-align:center;color:#6b7494;background:#111520;border:1px solid #1e2536;border-radius:14px">
      <div style="font-size:2.6rem;color:#4a5270;margin-bottom:.5rem"><i class="fa-solid fa-layer-group"></i></div>
      Δεν υπάρχουν ηλικιακές κατηγορίες ακόμα. Προσθέστε την πρώτη παραπάνω.
    </div>
  <?php else: ?>
    <?php foreach ($groups as $ag):
      $classes = eventWeightClasses((int)$ag['id']);
    ?>
      <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
          <div>
            <h3 style="margin:0;font-size:1.05rem;color:#f0f2ff"><?= h($ag['name']) ?></h3>
            <div style="color:#8892b0;font-size:.85rem;margin-top:.25rem">
              <?php if ($ag['min_age'] || $ag['max_age']): ?>
                <i class="fa-regular fa-user"></i> <?= (int)$ag['min_age'] ?>–<?= (int)$ag['max_age'] ?> ετών
              <?php endif; ?>
              <span style="margin-left:.5rem"><i class="fa-solid fa-venus-mars"></i>
                <?= ['M'=>'Άνδρες','F'=>'Γυναίκες','MX'=>'Και τα δύο'][$ag['gender']] ?? '—' ?>
              </span>
              <span style="margin-left:.5rem">·  <?= count($classes) ?> βάρη</span>
            </div>
          </div>
          <form method="POST" onsubmit="return confirm('Διαγραφή ηλικιακής κατηγορίας και όλων των βαρών της;')">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="age_delete">
            <input type="hidden" name="age_group_id" value="<?= (int)$ag['id'] ?>">
            <button type="submit" style="padding:.4rem .75rem;background:rgba(230,57,70,.15);border:1px solid rgba(230,57,70,.35);color:#ffb0b8;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.8rem">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
        </div>

        <!-- Weight classes -->
        <?php if ($classes): ?>
          <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.8rem;padding-top:.8rem;border-top:1px solid rgba(255,255,255,.05)">
            <?php foreach ($classes as $wc): ?>
              <span style="display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .7rem;background:rgba(78,132,255,.1);border:1px solid rgba(78,132,255,.25);border-radius:99px;color:#dbe6ff;font-size:.82rem;font-weight:700">
                <?= h($wc['name']) ?>
                <?php if ($wc['fee_amount']): ?>
                  <em style="color:#a9c1ff;font-size:.72rem">· <?= number_format((float)$wc['fee_amount'], 2, ',', '.') ?> €</em>
                <?php endif; ?>
                <form method="POST" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="_action" value="wc_delete">
                  <input type="hidden" name="age_group_id" value="<?= (int)$ag['id'] ?>">
                  <input type="hidden" name="weight_class_id" value="<?= (int)$wc['id'] ?>">
                  <button type="submit" title="Αφαίρεση" style="background:none;border:none;color:#ffb0b8;cursor:pointer;padding:0;font-size:.78rem">×</button>
                </form>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Add weight class -->
        <form method="POST" style="display:grid;grid-template-columns:1.6fr .9fr .9fr .9fr auto;gap:.4rem;align-items:end;margin-top:.9rem;padding-top:.8rem;border-top:1px dashed rgba(255,255,255,.06)">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="wc_add">
          <input type="hidden" name="age_group_id" value="<?= (int)$ag['id'] ?>">
          <label style="font-size:.7rem;color:#8892b0">Όνομα
            <input required name="name" placeholder="-45kg" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
          </label>
          <label style="font-size:.7rem;color:#8892b0">Από (kg)
            <input type="number" step="0.1" name="min_weight" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
          </label>
          <label style="font-size:.7rem;color:#8892b0">Έως (kg)
            <input type="number" step="0.1" name="max_weight" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
          </label>
          <label style="font-size:.7rem;color:#8892b0">Fee (€)
            <input type="number" step="0.01" name="fee_amount" placeholder="opt." style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
          </label>
          <button type="submit" style="padding:.5rem .8rem;background:rgba(45,198,83,.15);border:1px solid rgba(45,198,83,.35);color:#a9ffcb;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit">
            <i class="fa-solid fa-plus"></i>
          </button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div style="margin-top:1.25rem">
    <a href="<?= APP_URL ?>/pages/event_manage.php?id=<?= (int)$id ?>" style="color:#8892b0;font-size:.9rem;text-decoration:none">
      <i class="fa-solid fa-arrow-left"></i> Πίσω στη διαχείριση event
    </a>
  </div>

</div>
</div>
</div>
</body>
</html>
