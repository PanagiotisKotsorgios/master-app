<?php
/**
 * athlete/settings.php — Change password + preferences
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
requireAthleteLogin();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $cur   = $_POST['current_password'] ?? '';
        $new   = $_POST['new_password']     ?? '';
        $again = $_POST['new_password2']    ?? '';

        if (strlen($new) < 8) {
            $err = 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.';
        } elseif ($new !== $again) {
            $err = 'Οι δύο νέοι κωδικοί δεν συμπίπτουν.';
        } else {
            $db = getDB();
            $row = $db->prepare("SELECT password_hash FROM athlete_users WHERE id = ?");
            $row->execute([athleteUserId()]);
            $hash = $row->fetchColumn();
            if (!$hash || !password_verify($cur, $hash)) {
                $err = 'Ο τρέχων κωδικός δεν είναι σωστός.';
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $db->prepare("UPDATE athlete_users SET password_hash = ?, first_login = 0 WHERE id = ?")
                   ->execute([$newHash, athleteUserId()]);
                $_SESSION['athlete_first_login'] = false;
                $msg = 'Ο κωδικός σας άλλαξε με επιτυχία.';
            }
        }
    } catch (Throwable $e) {
        error_log('[athlete/settings.php] ' . $e->getMessage());
        $err = 'Παρουσιάστηκε σφάλμα. Δοκιμάστε ξανά.';
    }
}

$athletePageTitle = 'Ρυθμίσεις';
$athleteActiveNav = 'settings';
include __DIR__ . '/_layout_head.php';
?>

<div class="ap-head">
  <h1>Ρυθμίσεις λογαριασμού</h1>
  <p>Αλλαγή κωδικού πρόσβασης και προτιμήσεις.</p>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><i class="fas fa-circle-check"></i><span><?= h($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-err"><i class="fas fa-circle-exclamation"></i><span><?= h($err) ?></span></div><?php endif; ?>

<div class="card">
  <h2><i class="fas fa-key"></i> Αλλαγή κωδικού</h2>
  <form method="POST" novalidate style="max-width:520px">
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <div class="form-row">
      <label for="current_password">Τρέχων κωδικός</label>
      <input type="password" name="current_password" id="current_password" autocomplete="current-password" required>
    </div>
    <div class="form-row">
      <label for="new_password">Νέος κωδικός (τουλ. 8 χαρακτήρες)</label>
      <input type="password" name="new_password" id="new_password" autocomplete="new-password" required minlength="8">
    </div>
    <div class="form-row">
      <label for="new_password2">Επιβεβαίωση νέου κωδικού</label>
      <input type="password" name="new_password2" id="new_password2" autocomplete="new-password" required minlength="8">
    </div>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Αποθήκευση</button>
  </form>
</div>

<div class="card">
  <h2><i class="fas fa-user"></i> Στοιχεία λογαριασμού</h2>
  <div class="kv">
    <div class="k">Email σύνδεσης</div><div class="v"><?= h($_SESSION['athlete_email'] ?? '') ?></div>
    <div class="k">Σχολή</div>          <div class="v"><?= h(athleteSchoolName()) ?></div>
  </div>
  <p style="color:var(--muted);font-size:.85rem;margin-top:.9rem">
    Για αλλαγή email ή προσωπικών στοιχείων επικοινώνησε με τη σχολή σου.
  </p>
</div>

<?php include __DIR__ . '/_layout_foot.php'; ?>
