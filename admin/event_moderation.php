<?php
/**
 * admin/event_moderation.php — Superadmin triage inbox for event_reports
 * ============================================================
 * Lists open reports; actions: resolve, hide event, delete event.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    $db = getDB();
    try {
        if ($action === 'resolve') {
            $db->prepare("UPDATE event_reports SET resolved = 1 WHERE id = ?")->execute([(int)$_POST['report_id']]);
            flash('Επιλύθηκε.');
        }
        if ($action === 'hide') {
            $eid = (int)$_POST['event_id'];
            $db->prepare("UPDATE events SET visibility = 'invite_only' WHERE id = ?")->execute([$eid]);
            $db->prepare("UPDATE event_reports SET resolved = 1 WHERE event_id = ?")->execute([$eid]);
            auditLog('event_moderated_hide', 'event', $eid);
            flash('Το event αποκρύφθηκε.');
        }
        if ($action === 'cancel') {
            $eid = (int)$_POST['event_id'];
            $db->prepare("UPDATE events SET status = 'cancelled' WHERE id = ?")->execute([$eid]);
            $db->prepare("UPDATE event_reports SET resolved = 1 WHERE event_id = ?")->execute([$eid]);
            auditLog('event_moderated_cancel', 'event', $eid);
            flash('Το event ματαιώθηκε.');
        }
    } catch (Throwable $e) {
        flash('Σφάλμα: ' . $e->getMessage(), 'error');
    }
    redirect($_SERVER['REQUEST_URI']);
}

$showResolved = !empty($_GET['resolved']);
$sql = "SELECT r.*, e.title AS event_title, e.slug, e.status, e.visibility, e.organiser_school_id, s.name AS org_name
        FROM event_reports r
        JOIN events e ON e.id = r.event_id
        LEFT JOIN schools s ON s.id = e.organiser_school_id
        WHERE r.resolved = ?
        ORDER BY r.created_at DESC LIMIT 200";
$st = getDB()->prepare($sql);
$st->execute([$showResolved ? 1 : 0]);
$reports = $st->fetchAll();

renderHead('Event moderation');
$flash = getFlash();
?>
<body>
<div class="app-layout">
<?php renderSidebar('admin_event_mod'); ?>
<div class="main-content">
<?php renderTopbar('Event Moderation'); ?>
<div class="page-body">

  <?php if ($flash): ?>
    <div style="margin-bottom:1rem;padding:.85rem 1rem;border-radius:10px;background:rgba(45,198,83,.12);border:1px solid rgba(45,198,83,.35);color:#f0f2ff"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <div style="display:flex;gap:.5rem;margin-bottom:1rem">
    <a href="?" class="btn btn-<?= !$showResolved?'primary':'ghost' ?> btn-sm">Ανοιχτές (<?= !$showResolved ? count($reports) : '' ?>)</a>
    <a href="?resolved=1" class="btn btn-<?= $showResolved?'primary':'ghost' ?> btn-sm">Επιλυμένες</a>
  </div>

  <?php if (!$reports): ?>
    <div style="background:#111520;border:1px dashed #2a3248;border-radius:14px;padding:2.5rem;text-align:center;color:#8892b0">
      <i class="fa-solid fa-check-circle" style="font-size:2.5rem;color:#2dc653;display:block;margin-bottom:.5rem"></i>
      Καμία αναφορά.
    </div>
  <?php else: foreach ($reports as $r): ?>
    <div style="background:#111520;border:1px solid #1e2536;border-radius:14px;padding:1.15rem 1.35rem;margin-bottom:.75rem">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:.5rem">
        <div>
          <div style="font-size:.72rem;text-transform:uppercase;color:#e63946;font-weight:700;letter-spacing:.1em"><?= h($r['reason']) ?></div>
          <h3 style="margin:.2rem 0;color:#f0f2ff;font-size:1.05rem"><?= h($r['event_title']) ?></h3>
          <div style="color:#6b7494;font-size:.82rem">από <?= h($r['org_name'] ?? '—') ?> · Report #<?= (int)$r['id'] ?> · <?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?></div>
        </div>
        <a href="<?= APP_URL ?>/events/view.php?slug=<?= h($r['slug']) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye"></i> Προβολή</a>
      </div>

      <?php if ($r['details']): ?>
        <div style="background:#0d1017;border-radius:8px;padding:.85rem 1rem;color:#c8cfe0;font-size:.9rem;margin:.75rem 0;line-height:1.5"><?= nl2br(h($r['details'])) ?></div>
      <?php endif; ?>

      <div style="color:#6b7494;font-size:.8rem;margin-bottom:.85rem">
        Reporter IP: <code style="background:#0d1017;padding:.1rem .4rem;border-radius:4px"><?= h($r['reporter_ip']) ?></code>
        <?php if ($r['reporter_email']): ?> · <?= h($r['reporter_email']) ?><?php endif; ?>
      </div>

      <?php if (!$r['resolved']): ?>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;border-top:1px solid #1e2536;padding-top:.75rem">
          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="_action" value="resolve">
            <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm" style="background:#2dc653;color:#000">Επίλυση χωρίς ενέργεια</button>
          </form>
          <form method="POST" style="display:inline" onsubmit="return confirm('Απόκρυψη event (invite_only);')">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="_action" value="hide">
            <input type="hidden" name="event_id" value="<?= (int)$r['event_id'] ?>">
            <button class="btn btn-sm" style="background:#f0a500;color:#000">Απόκρυψη event</button>
          </form>
          <form method="POST" style="display:inline" onsubmit="return confirm('ΜΑΤΑΙΩΣΗ event;')">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="_action" value="cancel">
            <input type="hidden" name="event_id" value="<?= (int)$r['event_id'] ?>">
            <button class="btn btn-sm" style="background:#e63946;color:#fff">Ματαίωση event</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>

</div>
</div>
</div>
</body></html>
