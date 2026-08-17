<?php
/**
 * pages/event_updates.php — Announcements CRUD for an event (organiser)
 *  URL: ?id=<event_id>
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireLogin();
$sid = schoolId();
$id  = (int)($_GET['id'] ?? 0);
$ev  = eventGet($id);
if (!$ev || (int)$ev['organiser_school_id'] !== $sid) {
    flash('Δεν έχετε πρόσβαση.', 'error');
    redirect(APP_URL . '/pages/events.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'save') {
            eventUpdateSave($id, $_POST, isset($_POST['upd_id']) && $_POST['upd_id'] !== '' ? (int)$_POST['upd_id'] : null);
            flash('Η ενημέρωση αποθηκεύτηκε — θα σταλεί σε followers στο επόμενο cron.');
        }
        if ($action === 'delete') {
            eventUpdateDelete((int)$_POST['upd_id'], $id);
            flash('Διαγράφηκε.', 'info');
        }
    } catch (Throwable $e) {
        flash('Σφάλμα: ' . $e->getMessage(), 'error');
    }
    redirect($_SERVER['REQUEST_URI']);
}

$updates  = eventUpdatesForEvent($id);
$followers= eventFollowers($id);
$editing  = null;
if (isset($_GET['edit'])) {
    foreach ($updates as $u) if ((int)$u['id'] === (int)$_GET['edit']) { $editing = $u; break; }
}

renderHead('Ενημερώσεις · ' . $ev['title']);
$flash = getFlash();
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Ενημερώσεις · ' . $ev['title']); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <div style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.12);border:1px solid rgba(<?= $flash['type']==='error'?'230,57,70':'45,198,83' ?>,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <div style="margin-bottom:1rem"><a href="<?= h(eventManageUrl($id)) ?>" style="color:#8892b0;text-decoration:none;font-size:.9rem"><i class="fa-solid fa-arrow-left"></i> Πίσω στη διαχείριση</a></div>

  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
    <h2 style="margin:0;font-size:1.3rem;color:#f0f2ff">Ενημερώσεις event</h2>
    <div style="color:#8892b0;font-size:.85rem"><?= count($followers) ?> followers</div>
  </div>

  <!-- Compose form -->
  <form method="POST" style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.25rem;margin-bottom:1.25rem">
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <input type="hidden" name="_action" value="save">
    <input type="hidden" name="upd_id" value="<?= $editing ? (int)$editing['id'] : '' ?>">

    <h3 style="margin:0 0 .85rem;color:#e63946;font-size:.95rem;text-transform:uppercase;letter-spacing:.08em">
      <?= $editing ? 'Επεξεργασία ενημέρωσης' : '+ Νέα ενημέρωση' ?>
    </h3>

    <label style="display:block;margin-bottom:.75rem">
      <div style="font-size:.82rem;color:#c8cfe0;font-weight:700;margin-bottom:.3rem">Τίτλος</div>
      <input type="text" name="title" required maxlength="160" value="<?= h($editing['title'] ?? '') ?>" style="width:100%;padding:.7rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff">
    </label>
    <label style="display:block;margin-bottom:.75rem">
      <div style="font-size:.82rem;color:#c8cfe0;font-weight:700;margin-bottom:.3rem">Κείμενο</div>
      <textarea name="body_md" rows="6" required style="width:100%;padding:.7rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff;resize:vertical;font-family:inherit"><?= h($editing['body_md'] ?? '') ?></textarea>
    </label>
    <label style="display:flex;align-items:center;gap:.4rem;color:#c8cfe0;margin-bottom:1rem">
      <input type="checkbox" name="pinned" value="1" <?= !empty($editing['pinned'])?'checked':'' ?>>
      <span>Καρφίτσωμα στην κορυφή</span>
    </label>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> <?= $editing ? 'Ενημέρωση' : 'Δημοσίευση' ?></button>
      <?php if ($editing): ?><a href="?id=<?= $id ?>" class="btn btn-ghost">Άκυρο</a><?php endif; ?>
    </div>
  </form>

  <!-- List -->
  <?php if (!$updates): ?>
    <div style="background:#111520;border:1px dashed #2a3248;border-radius:14px;padding:2rem;text-align:center;color:#8892b0">
      Δεν έχετε δημοσιεύσει ενημερώσεις ακόμα.
    </div>
  <?php else: foreach ($updates as $u): ?>
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:.75rem">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:.4rem">
        <div>
          <?php if ($u['pinned']): ?><span style="color:#f0a500;font-size:.75rem;font-weight:800">📌 CARFΙΤΣΩΜΕΝΟ</span><br><?php endif; ?>
          <h3 style="margin:.15rem 0 .2rem;color:#f0f2ff;font-size:1.05rem"><?= h($u['title']) ?></h3>
          <div style="color:#6b7494;font-size:.78rem"><?= h(date('d/m/Y H:i', strtotime($u['published_at']))) ?></div>
        </div>
        <div style="display:flex;gap:.35rem">
          <a href="?id=<?= $id ?>&edit=<?= (int)$u['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-pen"></i></a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Διαγραφή;')">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="_action" value="delete">
            <input type="hidden" name="upd_id" value="<?= (int)$u['id'] ?>">
            <button class="btn btn-ghost btn-sm" style="color:#e63946"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
      </div>
      <div style="color:#c8cfe0;white-space:pre-wrap;line-height:1.6"><?= h($u['body_md']) ?></div>
    </div>
  <?php endforeach; endif; ?>

</div>
</div>
</div>
</body></html>
