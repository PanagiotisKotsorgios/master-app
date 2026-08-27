<?php
/**
 * athlete/documents.php — Athlete uploads/views their own documents
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
requireAthleteLogin();

$msg = '';
$err = '';

$allowedMimes = [
    'application/pdf'  => 'pdf',
    'image/jpeg'       => 'jpg',
    'image/png'        => 'png',
    'image/webp'       => 'webp',
];
$maxBytes = 8 * 1024 * 1024; // 8 MB

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    try {
        verifyCsrf();
        $types = athleteDocTypes();
        $type  = $_POST['type'] ?? '';
        if (!isset($types[$type])) $type = 'other';
        $title       = trim((string)($_POST['title'] ?? ''));
        $issuedDate  = trim((string)($_POST['issued_date'] ?? '')) ?: null;
        $expiresAt   = trim((string)($_POST['expires_at']  ?? '')) ?: null;

        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = 'Παρακαλώ επίλεξε αρχείο.';
        } elseif (($_FILES['file']['size'] ?? 0) > $maxBytes) {
            $err = 'Το αρχείο ξεπερνά το όριο των 8 MB.';
        } else {
            $tmp  = $_FILES['file']['tmp_name'];
            $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : ($_FILES['file']['type'] ?? '');
            if (!isset($allowedMimes[$mime])) {
                $err = 'Επιτρέπονται μόνο αρχεία PDF, JPG, PNG ή WEBP.';
            } else {
                $ext = $allowedMimes[$mime];
                $sid = athleteSchoolId();
                $aid = athleteRecordId();

                $dir = __DIR__ . '/../uploads/athletes/' . $aid . '/docs';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                if (!is_dir($dir) || !is_writable($dir)) {
                    $err = 'Αδυναμία αποθήκευσης αρχείου.';
                } else {
                    $safeType = preg_replace('/[^a-z0-9]/', '', $type);
                    $fileName = $safeType . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $target   = $dir . '/' . $fileName;
                    if (!@move_uploaded_file($tmp, $target)) {
                        $err = 'Το ανέβασμα απέτυχε. Δοκίμασε ξανά.';
                    } else {
                        $relPath = 'uploads/athletes/' . $aid . '/docs/' . $fileName;
                        $db = getDB();
                        $stmt = $db->prepare("
                            INSERT INTO athlete_documents
                              (school_id, athlete_id, type, title, file_path, file_size, mime_type,
                               issued_date, expires_at, uploaded_by, verified_by_school)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'athlete', 0)
                        ");
                        $stmt->execute([
                            $sid, $aid, $type,
                            $title !== '' ? $title : null,
                            $relPath,
                            (int)$_FILES['file']['size'],
                            $mime,
                            $issuedDate,
                            $expiresAt,
                        ]);
                        $msg = 'Το έγγραφο ανέβηκε και εστάλη στη σχολή σου για επιβεβαίωση.';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[athlete/documents.php] ' . $e->getMessage());
        $err = 'Παρουσιάστηκε σφάλμα. Δοκίμασε ξανά.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        verifyCsrf();
        $docId = (int)($_POST['id'] ?? 0);
        $db = getDB();
        $r = $db->prepare("SELECT id, file_path, verified_by_school FROM athlete_documents WHERE id=? AND athlete_id=? AND school_id=?");
        $r->execute([$docId, athleteRecordId(), athleteSchoolId()]);
        $doc = $r->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            $err = 'Το έγγραφο δεν βρέθηκε.';
        } elseif ((int)$doc['verified_by_school']) {
            $err = 'Δεν μπορείς να διαγράψεις επιβεβαιωμένο έγγραφο. Επικοινώνησε με τη σχολή.';
        } else {
            $abs = __DIR__ . '/../' . $doc['file_path'];
            if (is_file($abs)) @unlink($abs);
            $db->prepare("DELETE FROM athlete_documents WHERE id=? AND athlete_id=?")
               ->execute([$docId, athleteRecordId()]);
            $msg = 'Το έγγραφο διαγράφηκε.';
        }
    } catch (Throwable $e) {
        error_log('[athlete/documents.php delete] ' . $e->getMessage());
        $err = 'Σφάλμα διαγραφής.';
    }
}

$docs  = getAthleteOwnDocuments();
$types = athleteDocTypes();

$athletePageTitle = 'Έγγραφά μου';
$athleteActiveNav = 'documents';
include __DIR__ . '/_layout_head.php';
?>

<div class="ap-head">
  <h1>Τα έγγραφά μου</h1>
  <p>Ανέβασε δελτίο αθλητή, πιστοποιητικά Dan, ζώνης, ιατρικό. Η σχολή θα επιβεβαιώσει κάθε έγγραφο.</p>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><i class="fas fa-circle-check"></i><span><?= h($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-err"><i class="fas fa-circle-exclamation"></i><span><?= h($err) ?></span></div><?php endif; ?>

<div class="card">
  <h2><i class="fas fa-upload"></i> Ανέβασμα εγγράφου</h2>
  <form method="POST" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <input type="hidden" name="action" value="upload">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.9rem">
      <div class="form-row">
        <label for="type">Τύπος εγγράφου</label>
        <select name="type" id="type" required>
          <?php foreach ($types as $k => $t): ?>
            <option value="<?= h($k) ?>"><?= h($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label for="title">Τίτλος (προαιρετικό)</label>
        <input type="text" name="title" id="title" placeholder="π.χ. 1st Dan Karate 2024">
      </div>
      <div class="form-row">
        <label for="issued_date">Ημ. Έκδοσης</label>
        <input type="date" name="issued_date" id="issued_date">
      </div>
      <div class="form-row">
        <label for="expires_at">Ημ. Λήξης (αν έχει)</label>
        <input type="date" name="expires_at" id="expires_at">
      </div>
    </div>
    <div class="form-row">
      <label for="file">Αρχείο (PDF/JPG/PNG/WEBP, έως 8MB)</label>
      <input type="file" name="file" id="file" accept="application/pdf,image/jpeg,image/png,image/webp" required
             style="padding:.55rem;background:var(--card2);border:1.5px dashed var(--brd);border-radius:10px;cursor:pointer">
    </div>
    <button type="submit" class="btn btn-primary"><i class="fas fa-cloud-arrow-up"></i> Ανέβασε</button>
  </form>
</div>

<div class="card">
  <h2><i class="fas fa-folder-open"></i> Τα έγγραφά μου (<?= count($docs) ?>)</h2>
  <?php if (empty($docs)): ?>
    <p style="color:var(--muted);text-align:center;padding:2rem 1rem">
      <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.5"></i>
      Δεν έχεις ανεβάσει έγγραφα ακόμα.
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
              <a href="<?= APP_URL . '/' . h($d['file_path']) ?>" target="_blank" class="btn btn-ghost" style="padding:.4rem .7rem;font-size:.82rem">
                <i class="fas fa-eye"></i> Άνοιγμα
              </a>
              <?php if (!(int)$d['verified_by_school']): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Διαγραφή εγγράφου;')">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="btn btn-ghost" style="padding:.4rem .7rem;font-size:.82rem;color:#ff8891;border-color:rgba(230,57,70,.3)">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_foot.php'; ?>
