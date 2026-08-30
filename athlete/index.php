<?php
/**
 * athlete/index.php — Athlete portal dashboard
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
requireAthleteLogin();

$athlete = currentAthlete();
if (!$athlete) {
    session_destroy();
    redirect(APP_URL . '/parent/login.php');
}

// ── Active subscription snapshot ──
$db  = getDB();
$sid = athleteSchoolId();
$aid = athleteRecordId();

$activeSub = null;
try {
    $stmt = $db->prepare("
        SELECT valid_from, valid_until, amount, status
        FROM subscriptions
        WHERE athlete_id = ? AND school_id = ?
        ORDER BY valid_until DESC
        LIMIT 1
    ");
    $stmt->execute([$aid, $sid]);
    $activeSub = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\PDOException $e) {}

$docCount = 0;
try {
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM athlete_documents
        WHERE school_id=? AND athlete_id=?
          AND verified_by_school=1
    ");
    $stmt->execute([$sid, $aid]);
    $docCount = (int)$stmt->fetchColumn();
} catch (\PDOException $e) {}

$expiredMedical = false;
if (!empty($athlete['medical_cert_expiry'])) {
    $expiredMedical = strtotime($athlete['medical_cert_expiry']) < time();
}

$athletePageTitle = 'Αρχική';
$athleteActiveNav = 'dashboard';
include __DIR__ . '/_layout_head.php';
?>

<div class="ap-head">
  <h1>Γεια σου, <?= h(explode(' ', $athlete['full_name'])[0]) ?> 👋</h1>
  <p>Το προσωπικό σου portal στη σχολή <strong style="color:var(--text)"><?= h(athleteSchoolName()) ?></strong>.</p>
</div>

<?php if (!empty($_SESSION['athlete_first_login'])): ?>
<div class="alert alert-info">
  <i class="fas fa-circle-info"></i>
  <span>Καλωσόρισες! Χρησιμοποιείς προσωρινό κωδικό. Άλλαξέ τον στις <a href="<?= APP_URL ?>/athlete/settings.php" style="color:#fff;text-decoration:underline;font-weight:700">Ρυθμίσεις</a>.</span>
</div>
<?php endif; ?>

<div class="grid-stats">
  <div class="stat b">
    <div class="lbl">Έγγραφα</div>
    <div class="val"><?= $docCount ?></div>
  </div>
  <div class="stat <?= $activeSub && $activeSub['status']==='paid' ? 'g' : 'r' ?>">
    <div class="lbl">Συνδρομή</div>
    <div class="val" style="font-size:1.15rem;font-family:'DM Sans';font-weight:800">
      <?php
        if (!$activeSub) echo '—';
        elseif ($activeSub['status'] === 'paid') echo 'Ενεργή';
        else echo 'Εκκρεμεί';
      ?>
    </div>
  </div>
  <div class="stat o">
    <div class="lbl">Ιατρικό λήγει</div>
    <div class="val" style="font-size:1.05rem;font-family:'DM Sans';font-weight:800;<?= $expiredMedical?'color:#ff8891':'' ?>">
      <?= $athlete['medical_cert_expiry'] ? date('d/m/Y', strtotime($athlete['medical_cert_expiry'])) : '—' ?>
    </div>
  </div>
</div>

<?php if ($expiredMedical): ?>
<div class="alert alert-err">
  <i class="fas fa-triangle-exclamation"></i>
  <span>Το ιατρικό σου πιστοποιητικό έχει <strong>λήξει</strong>. Ενημέρωσε τη σχολή και ανέβασε νέο στα <a href="<?= APP_URL ?>/athlete/documents.php" style="color:#fff;text-decoration:underline;font-weight:800">Έγγραφά μου</a>.</span>
</div>
<?php endif; ?>

<div class="card">
  <h2><i class="fas fa-id-badge"></i> Το δελτίο μου</h2>
  <div class="kv">
    <div class="k">Ονοματεπώνυμο</div>       <div class="v"><?= h($athlete['full_name']) ?></div>
    <?php if (!empty($athlete['birthdate'])): ?>
      <div class="k">Ημ. Γέννησης</div>       <div class="v"><?= date('d/m/Y', strtotime($athlete['birthdate'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($athlete['department_name'])): ?>
      <div class="k">Τμήμα</div>              <div class="v"><?= h($athlete['department_name']) ?></div>
    <?php endif; ?>
    <?php if (!empty($athlete['amka'])): ?>
      <div class="k">ΑΜΚΑ</div>               <div class="v"><?= h($athlete['amka']) ?></div>
    <?php endif; ?>
    <?php if (!empty($athlete['phone'])): ?>
      <div class="k">Τηλέφωνο</div>           <div class="v"><?= h($athlete['phone']) ?></div>
    <?php endif; ?>
    <?php if (!empty($athlete['email'])): ?>
      <div class="k">Email</div>              <div class="v"><?= h($athlete['email']) ?></div>
    <?php endif; ?>
    <?php if (!empty($athlete['registration_date'])): ?>
      <div class="k">Ημ. Εγγραφής</div>       <div class="v"><?= date('d/m/Y', strtotime($athlete['registration_date'])) ?></div>
    <?php endif; ?>
    <?php if ((float)$athlete['monthly_fee'] > 0): ?>
      <div class="k">Μηνιαία Συνδρομή</div>   <div class="v"><?= number_format((float)$athlete['monthly_fee'], 2, ',', '.') ?> €</div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2><i class="fas fa-bolt"></i> Γρήγορες Ενέργειες</h2>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap">
    <a href="<?= APP_URL ?>/athlete/documents.php"     class="btn btn-primary"><i class="fas fa-upload"></i> Ανέβασε έγγραφο</a>
    <a href="<?= APP_URL ?>/athlete/subscriptions.php" class="btn btn-ghost"><i class="fas fa-clock-rotate-left"></i> Ιστορικό πληρωμών</a>
    <a href="<?= APP_URL ?>/athlete/events.php"        class="btn btn-ghost"><i class="fas fa-calendar-plus"></i> Δες διοργανώσεις</a>
  </div>
</div>

<?php include __DIR__ . '/_layout_foot.php'; ?>
