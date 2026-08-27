<?php
/**
 * athlete/events.php — Athlete browses public events + own school events
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
requireAthleteLogin();

$db  = getDB();
$sid = athleteSchoolId();

$events = [];
try {
    $stmt = $db->prepare("
        SELECT e.id, e.slug, e.title, e.subtitle, e.starts_at, e.status, e.visibility,
               e.type, e.organiser_school_id,
               s.name AS organiser
        FROM events e
        LEFT JOIN schools s ON s.id = e.organiser_school_id
        WHERE (e.visibility = 'public' OR e.organiser_school_id = ?)
          AND e.status IN ('open','in_progress','completed')
          AND (e.starts_at IS NULL OR e.starts_at > (NOW() - INTERVAL 30 DAY))
        ORDER BY e.starts_at ASC
        LIMIT 40
    ");
    $stmt->execute([$sid]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\PDOException $e) {}

$athletePageTitle = 'Διοργανώσεις';
$athleteActiveNav = 'events';
include __DIR__ . '/_layout_head.php';
?>

<div class="ap-head">
  <h1>Διοργανώσεις</h1>
  <p>Ενεργές διοργανώσεις της σχολής σου και ανοιχτές διοργανώσεις άλλων συλλόγων.</p>
</div>

<div class="card">
  <?php if (empty($events)): ?>
    <p style="color:var(--muted);text-align:center;padding:2rem 1rem">
      <i class="fas fa-calendar-xmark" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.5"></i>
      Δεν υπάρχουν ενεργές διοργανώσεις αυτή τη στιγμή.
    </p>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem">
      <?php foreach ($events as $e):
        $mine = (int)$e['organiser_school_id'] === $sid;
        $when = $e['starts_at'] ? date('d/m/Y H:i', strtotime($e['starts_at'])) : 'Χωρίς ημερομηνία';
      ?>
        <a href="<?= APP_URL ?>/events/view.php?slug=<?= urlencode($e['slug']) ?>"
           style="display:block;padding:1rem 1.1rem;background:var(--card2);border:1px solid var(--brd);border-radius:12px;transition:transform .15s,border-color .15s"
           onmouseover="this.style.transform='translateY(-2px)';this.style.borderColor='rgba(230,57,70,.4)'"
           onmouseout="this.style.transform='translateY(0)';this.style.borderColor='var(--brd)'">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;margin-bottom:.4rem">
            <div style="font-weight:800;color:var(--text);line-height:1.3"><?= h($e['title']) ?></div>
            <?php if ($mine): ?><span class="pill ok" style="flex-shrink:0">Δικός μας</span><?php endif; ?>
          </div>
          <?php if (!empty($e['subtitle'])): ?>
            <div style="color:var(--muted);font-size:.85rem;margin-bottom:.55rem;line-height:1.4"><?= h($e['subtitle']) ?></div>
          <?php endif; ?>
          <div style="display:flex;flex-wrap:wrap;gap:.5rem;font-size:.8rem;color:var(--muted)">
            <span><i class="fas fa-calendar-day" style="color:var(--red)"></i> <?= h($when) ?></span>
            <?php if (!empty($e['organiser'])): ?>
              <span><i class="fas fa-building" style="color:var(--red)"></i> <?= h($e['organiser']) ?></span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_foot.php'; ?>
