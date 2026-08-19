<?php
/**
 * pages/event_tatamis.php — Zones, tatamis, and schedule blocks.
 *
 * All three CRUDs on one page for the event organiser.
 * Downstream: event_matches.ring_number already exists, so
 * setting tatami.ring_number = 3 means every match with
 * ring_number = 3 belongs to that tatami.
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
    $act = $_POST['_action'] ?? '';
    try {
        switch ($act) {
            case 'zone_add':     eventZoneCreate($id, $_POST); break;
            case 'zone_delete':  eventZoneDelete((int)$_POST['zone_id'], $id); break;
            case 'tatami_add':   eventTatamiCreate($id, $_POST); break;
            case 'tatami_delete':eventTatamiDelete((int)$_POST['tatami_id'], $id); break;
            case 'block_add':    eventScheduleBlockCreate($id, $_POST); break;
            case 'block_delete': eventScheduleBlockDelete((int)$_POST['block_id'], $id); break;
        }
        flash('Ενημερώθηκε.', 'success');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(APP_URL . '/pages/event_tatamis.php?id=' . (int)$id);
}

$zones     = eventZones($id);
$tatamis   = eventTatamis($id);
$blocks    = eventScheduleBlocks($id);
$categories = eventCategories($id);

renderHead('Χώρος & Πρόγραμμα: ' . $ev['title']);
$flash = getFlash();
?>
<body>
<div class="app-layout">
<?php renderSidebar('events'); ?>
<div class="main-content">
<?php renderTopbar('Χώρος & Πρόγραμμα — ' . $ev['title']); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <?php $cbg = ['error'=>'230,57,70','success'=>'45,198,83','info'=>'78,132,255'][$flash['type']] ?? '78,132,255'; ?>
    <div style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(<?= $cbg ?>,.12);border:1px solid rgba(<?= $cbg ?>,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#e63946;font-weight:700">Physical Layout & Schedule</div>
    <h1 style="margin:.25rem 0;font-size:1.3rem;color:#f0f2ff"><?= h($ev['title']) ?></h1>
    <p style="color:#8892b0;font-size:.88rem">Ορίστε ζώνες → tatamis → schedule blocks. Το πεδίο <em>ring number</em> κάθε tatami αντιστοιχεί σε <code style="color:#c9cee1">event_matches.ring_number</code>.</p>
  </div>

  <!-- Zones -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
    <h3 style="margin:0 0 .8rem;font-size:1rem;font-weight:800"><i class="fa-solid fa-map"></i> Ζώνες Χώρου</h3>
    <?php if ($zones): ?>
      <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.8rem">
        <?php foreach ($zones as $z): ?>
          <span style="display:inline-flex;align-items:center;gap:.5rem;padding:.4rem .75rem;background:rgba(78,132,255,.1);border:1px solid rgba(78,132,255,.25);border-radius:99px;color:#dbe6ff;font-size:.85rem;font-weight:700">
            <?= h($z['name']) ?>
            <form method="POST" style="display:inline"><?= csrfField() ?>
              <input type="hidden" name="_action" value="zone_delete">
              <input type="hidden" name="zone_id" value="<?= (int)$z['id'] ?>">
              <button type="submit" title="Διαγραφή" style="background:none;border:none;color:#ffb0b8;cursor:pointer;padding:0;font-size:.85rem">×</button>
            </form>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="zone_add">
      <input required name="name" placeholder="Ονομασία ζώνης (π.χ. Main Hall)" style="flex:1;min-width:200px;padding:.55rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
      <button type="submit" style="padding:.55rem 1rem;background:rgba(45,198,83,.15);border:1px solid rgba(45,198,83,.35);color:#a9ffcb;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit"><i class="fa-solid fa-plus"></i></button>
    </form>
  </div>

  <!-- Tatamis -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
    <h3 style="margin:0 0 .8rem;font-size:1rem;font-weight:800"><i class="fa-solid fa-th-large"></i> Tatamis / Rings</h3>
    <?php if ($tatamis): ?>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:.9rem">
        <thead>
          <tr style="background:rgba(255,255,255,.03);color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em">
            <th style="padding:.55rem;text-align:left">Ring #</th>
            <th style="padding:.55rem;text-align:left">Name</th>
            <th style="padding:.55rem;text-align:left">Zone</th>
            <th style="padding:.55rem;text-align:left">Color</th>
            <th style="padding:.55rem"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tatamis as $t): ?>
            <tr style="border-top:1px solid rgba(255,255,255,.05)">
              <td style="padding:.5rem .55rem;font-weight:800"><?= (int)$t['ring_number'] ?></td>
              <td style="padding:.5rem .55rem"><?= h($t['name']) ?></td>
              <td style="padding:.5rem .55rem;color:#8892b0"><?= h($t['zone_name'] ?? '—') ?></td>
              <td style="padding:.5rem .55rem">
                <?php if ($t['color']): ?>
                  <span style="display:inline-block;width:16px;height:16px;background:<?= h($t['color']) ?>;border-radius:4px;border:1px solid rgba(255,255,255,.15);vertical-align:middle"></span>
                  <code style="color:#c9cee1;font-size:.8rem;margin-left:.3rem"><?= h($t['color']) ?></code>
                <?php else: ?><span style="color:#4a5270">—</span><?php endif; ?>
              </td>
              <td style="padding:.5rem .55rem;text-align:right">
                <form method="POST" onsubmit="return confirm('Διαγραφή tatami;')" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="_action" value="tatami_delete">
                  <input type="hidden" name="tatami_id" value="<?= (int)$t['id'] ?>">
                  <button type="submit" style="padding:.3rem .55rem;background:rgba(230,57,70,.15);border:1px solid rgba(230,57,70,.35);color:#ffb0b8;border-radius:6px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.78rem"><i class="fa-solid fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <form method="POST" style="display:grid;grid-template-columns:.6fr 1.4fr 1fr .8fr auto;gap:.5rem;align-items:end">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="tatami_add">
      <label style="font-size:.7rem;color:#8892b0">Ring #
        <input type="number" min="1" max="99" name="ring_number" placeholder="auto" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
      </label>
      <label style="font-size:.7rem;color:#8892b0">Name
        <input required name="name" placeholder="Tatami 1" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
      </label>
      <label style="font-size:.7rem;color:#8892b0">Zone
        <select name="zone_id" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
          <option value="">—</option>
          <?php foreach ($zones as $z): ?>
            <option value="<?= (int)$z['id'] ?>"><?= h($z['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:.7rem;color:#8892b0">Color
        <input type="color" name="color" style="width:100%;height:38px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;margin-top:.2rem;padding:.15rem">
      </label>
      <button type="submit" style="padding:.5rem .8rem;background:rgba(45,198,83,.15);border:1px solid rgba(45,198,83,.35);color:#a9ffcb;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit"><i class="fa-solid fa-plus"></i></button>
    </form>
  </div>

  <!-- Schedule blocks -->
  <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.1rem 1.35rem;margin-bottom:1rem">
    <h3 style="margin:0 0 .8rem;font-size:1rem;font-weight:800"><i class="fa-regular fa-clock"></i> Πρόγραμμα (Schedule Blocks)</h3>
    <?php if ($blocks): ?>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:.9rem">
        <thead>
          <tr style="background:rgba(255,255,255,.03);color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em">
            <th style="padding:.55rem;text-align:left">Type</th>
            <th style="padding:.55rem;text-align:left">Title</th>
            <th style="padding:.55rem;text-align:left">Tatami</th>
            <th style="padding:.55rem;text-align:left">From</th>
            <th style="padding:.55rem;text-align:left">To</th>
            <th style="padding:.55rem"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($blocks as $b): ?>
            <tr style="border-top:1px solid rgba(255,255,255,.05)">
              <td style="padding:.5rem .55rem"><span style="padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700;background:rgba(78,132,255,.1);color:#a9c1ff;border:1px solid rgba(78,132,255,.25)"><?= h($b['block_type']) ?></span></td>
              <td style="padding:.5rem .55rem;font-weight:700"><?= h($b['title']) ?><?php if ($b['category_name']): ?> <span style="color:#6b7494;font-size:.78rem">· <?= h($b['category_name']) ?></span><?php endif; ?></td>
              <td style="padding:.5rem .55rem;color:#8892b0"><?= h($b['tatami_name'] ?? '—') ?></td>
              <td style="padding:.5rem .55rem;font-family:monospace;font-size:.82rem"><?= h(date('d/m H:i', strtotime($b['starts_at']))) ?></td>
              <td style="padding:.5rem .55rem;font-family:monospace;font-size:.82rem"><?= h(date('d/m H:i', strtotime($b['ends_at']))) ?></td>
              <td style="padding:.5rem .55rem;text-align:right">
                <form method="POST" onsubmit="return confirm('Διαγραφή block;')" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="_action" value="block_delete">
                  <input type="hidden" name="block_id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" style="padding:.3rem .55rem;background:rgba(230,57,70,.15);border:1px solid rgba(230,57,70,.35);color:#ffb0b8;border-radius:6px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.78rem"><i class="fa-solid fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <form method="POST" style="display:grid;grid-template-columns:1fr 1.6fr 1fr 1fr 1fr auto;gap:.5rem;align-items:end">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="block_add">
      <label style="font-size:.7rem;color:#8892b0">Type
        <select name="block_type" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
          <option value="category">Category</option>
          <option value="break">Break</option>
          <option value="ceremony">Ceremony</option>
          <option value="other">Other</option>
        </select>
      </label>
      <label style="font-size:.7rem;color:#8892b0">Title
        <input required name="title" placeholder="π.χ. Παίδες -45kg" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
      </label>
      <label style="font-size:.7rem;color:#8892b0">Tatami
        <select name="tatami_id" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
          <option value="">— (event-wide)</option>
          <?php foreach ($tatamis as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?> (#<?= (int)$t['ring_number'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:.7rem;color:#8892b0">From
        <input required type="datetime-local" name="starts_at" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
      </label>
      <label style="font-size:.7rem;color:#8892b0">To
        <input required type="datetime-local" name="ends_at" style="width:100%;padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff;margin-top:.2rem">
      </label>
      <button type="submit" style="padding:.5rem .8rem;background:rgba(45,198,83,.15);border:1px solid rgba(45,198,83,.35);color:#a9ffcb;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit"><i class="fa-solid fa-plus"></i></button>
    </form>

    <?php if ($categories): ?>
      <details style="margin-top:.75rem;font-size:.78rem;color:#8892b0">
        <summary style="cursor:pointer">Advanced: link a block to an event_category</summary>
        <form method="POST" style="display:grid;grid-template-columns:1fr 1.6fr 1fr auto;gap:.5rem;align-items:end;margin-top:.5rem">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="block_add">
          <input type="hidden" name="block_type" value="category">
          <select required name="category_id" style="padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input required name="title" placeholder="Block title" style="padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
          <input required type="datetime-local" name="starts_at" style="padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
          <input required type="datetime-local" name="ends_at" style="padding:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#f0f2ff">
          <input type="hidden" name="tatami_id" value="">
          <button type="submit" style="padding:.5rem .8rem;background:rgba(45,198,83,.15);border:1px solid rgba(45,198,83,.35);color:#a9ffcb;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit;grid-column:span 4"><i class="fa-solid fa-plus"></i> Add category block</button>
        </form>
      </details>
    <?php endif; ?>
  </div>

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
