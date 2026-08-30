<?php
/**
 * athlete/documents.php — Read-only view of school-managed athlete documents
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
requireAthleteLogin();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('Η σελίδα εγγράφων είναι μόνο για προβολή.');
}

$docs  = getAthleteOwnDocuments();
$types = athleteDocTypes();

$athletePageTitle = 'Έγγραφά μου';
$athleteActiveNav = 'documents';
include __DIR__ . '/_layout_head.php';
?>

<div class="ap-head">
  <h1>Τα έγγραφά μου</h1>
  <p>Εδώ βλέπεις τα έγγραφα που διαχειρίζεται και έχει επιβεβαιώσει η σχολή σου.</p>
</div>

<div class="card">
  <h2><i class="fas fa-folder-open"></i> Τα έγγραφά μου (<?= count($docs) ?>)</h2>
  <?php if (empty($docs)): ?>
    <p style="color:var(--muted);text-align:center;padding:2rem 1rem">
      <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.5"></i>
      Η σχολή σου δεν έχει ανεβάσει έγγραφα ακόμα.
    </p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Τύπος</th>
            <th>Τίτλος</th>
            <th>Ημ. Έκδοσης</th>
            <th>Ημ. Λήξης</th>
            <th>Κατάσταση</th>
            <th style="text-align:right">Ενέργειες</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($docs as $d):
          $t = $types[$d['type']] ?? $types['other'];
          $expired = $d['expires_at'] && strtotime($d['expires_at']) < time();
        ?>
          <tr>
            <td>
              <span style="display:inline-flex;align-items:center;gap:.45rem;font-weight:700">
                <i class="fas <?= h($t['icon']) ?>" style="color:<?= h($t['color']) ?>"></i>
                <?= h($t['label']) ?>
              </span>
            </td>
            <td><?= h($d['title'] ?? '—') ?></td>
            <td><?= $d['issued_date']  ? date('d/m/Y', strtotime($d['issued_date']))  : '—' ?></td>
            <td>
              <?php if ($d['expires_at']): ?>
                <span style="<?= $expired?'color:#ff8891;font-weight:800':'' ?>">
                  <?= date('d/m/Y', strtotime($d['expires_at'])) ?>
                </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <?php if ((int)$d['verified_by_school']): ?>
                <span class="pill ok"><i class="fas fa-circle-check"></i> Επιβεβαιωμένο</span>
              <?php else: ?>
                <span class="pill warn"><i class="fas fa-clock"></i> Σε εκκρεμότητα</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;white-space:nowrap">
              <a href="<?= APP_URL . '/' . h($d['file_path']) ?>" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:.4rem .7rem;font-size:.82rem">
                <i class="fas fa-eye"></i> Άνοιγμα
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_foot.php'; ?>
