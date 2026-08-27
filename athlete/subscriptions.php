<?php
/**
 * athlete/subscriptions.php — athlete's own subscription + payments history
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
requireAthleteLogin();

$db  = getDB();
$sid = athleteSchoolId();
$aid = athleteRecordId();

$subs = [];
try {
    $stmt = $db->prepare("
        SELECT valid_from, valid_until, amount, status, paid_at, payment_method, notes
        FROM subscriptions
        WHERE athlete_id = ? AND school_id = ?
        ORDER BY valid_from DESC
        LIMIT 60
    ");
    $stmt->execute([$aid, $sid]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\PDOException $e) {}

$pays = [];
try {
    $stmt = $db->prepare("
        SELECT month, amount, paid_at, payment_method, notes
        FROM payments
        WHERE athlete_id = ? AND school_id = ?
        ORDER BY month DESC
        LIMIT 60
    ");
    $stmt->execute([$aid, $sid]);
    $pays = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\PDOException $e) {}

$athletePageTitle = 'Συνδρομές';
$athleteActiveNav = 'subscriptions';
include __DIR__ . '/_layout_head.php';
?>

<div class="ap-head">
  <h1>Συνδρομές &amp; πληρωμές</h1>
  <p>Ιστορικό της δικής σου συνδρομής στη σχολή.</p>
</div>

<div class="card">
  <h2><i class="fas fa-euro-sign"></i> Συνδρομές (<?= count($subs) ?>)</h2>
  <?php if (empty($subs)): ?>
    <p style="color:var(--muted);text-align:center;padding:1.5rem">Δεν υπάρχουν καταχωρημένες συνδρομές.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr><th>Από</th><th>Έως</th><th>Ποσό</th><th>Κατάσταση</th><th>Πληρώθηκε</th><th>Μέθοδος</th></tr>
        </thead>
        <tbody>
        <?php foreach ($subs as $s):
          $st = $s['status'] ?? 'pending';
          $pill = $st === 'paid' ? 'ok' : ($st === 'pending' ? 'warn' : 'err');
          $lbl  = $st === 'paid' ? 'Πληρωμένη' : ($st === 'pending' ? 'Εκκρεμεί' : 'Καθυστερεί');
        ?>
          <tr>
            <td><?= $s['valid_from']  ? date('d/m/Y', strtotime($s['valid_from']))  : '—' ?></td>
            <td><?= $s['valid_until'] ? date('d/m/Y', strtotime($s['valid_until'])) : '—' ?></td>
            <td style="font-weight:800"><?= number_format((float)$s['amount'], 2, ',', '.') ?> €</td>
            <td><span class="pill <?= $pill ?>"><?= $lbl ?></span></td>
            <td><?= $s['paid_at'] ? date('d/m/Y', strtotime($s['paid_at'])) : '—' ?></td>
            <td style="color:var(--muted)"><?= h($s['payment_method'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2><i class="fas fa-clock-rotate-left"></i> Μηνιαίες πληρωμές (<?= count($pays) ?>)</h2>
  <?php if (empty($pays)): ?>
    <p style="color:var(--muted);text-align:center;padding:1.5rem">Δεν υπάρχουν καταχωρημένες πληρωμές.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Μήνας</th><th>Ποσό</th><th>Πληρώθηκε</th><th>Μέθοδος</th><th>Σημείωση</th></tr></thead>
        <tbody>
        <?php foreach ($pays as $p): ?>
          <tr>
            <td style="font-weight:700"><?= h($p['month']) ?></td>
            <td style="font-weight:800"><?= number_format((float)$p['amount'], 2, ',', '.') ?> €</td>
            <td><?= $p['paid_at'] ? date('d/m/Y', strtotime($p['paid_at'])) : '—' ?></td>
            <td style="color:var(--muted)"><?= h($p['payment_method'] ?? '—') ?></td>
            <td style="color:var(--muted);font-size:.85rem"><?= h($p['notes'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_foot.php'; ?>
