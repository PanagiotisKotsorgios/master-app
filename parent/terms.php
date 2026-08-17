<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';

if (!isParent()) {
    redirect(APP_URL . '/parent/login.php');
}

$db  = getDB();
$pid = parentUserId();
$error = '';

// Already accepted in DB → set session and never show again
$stmt = $db->prepare("SELECT terms_accepted_at FROM parent_users WHERE id = ?");
$stmt->execute([$pid]);
$accepted = $stmt->fetchColumn();

if ($accepted) {
    $_SESSION['parent_terms_accepted'] = true;
    redirect(APP_URL . '/parent/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        if (!empty($_POST['accept_terms'])) {
            $smsConsent = !empty($_POST['sms_consent']) ? 1 : 0;
            // Generate unsubscribe token if not already set
            $tokenRow = $db->prepare("SELECT unsubscribe_token FROM parent_users WHERE id = ?");
            $tokenRow->execute([$pid]);
            $existingToken = $tokenRow->fetchColumn();
            $unsToken = $existingToken ?: bin2hex(random_bytes(32));
            $db->prepare("UPDATE parent_users
                          SET terms_accepted_at = NOW(),
                              terms_version     = '1.0',
                              sms_opt_out       = ?,
                              sms_consent_at    = ?,
                              unsubscribe_token = ?
                          WHERE id = ?")
               ->execute([$smsConsent ? 0 : 1, $smsConsent ? date('Y-m-d H:i:s') : null, $unsToken, $pid]);
            $_SESSION['parent_terms_accepted'] = true;
            $_SESSION['parent_sms_opt_out']    = !$smsConsent;

            // Log consent for GDPR audit trail (pseudonymized IP)
            try {
                $rawIp    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $salt     = date('Y-m-d'); // daily rotation
                $ipHash   = hash('sha256', $rawIp . $salt);
                $ua       = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
                $db->prepare("INSERT INTO consent_log (parent_user_id, event_type, ip_hash, user_agent, terms_version) VALUES (?, 'terms_accepted', ?, ?, '1.0')")
                   ->execute([$pid, $ipHash, $ua]);
                if ($smsConsent) {
                    $db->prepare("INSERT INTO consent_log (parent_user_id, event_type, ip_hash, user_agent, terms_version) VALUES (?, 'sms_consent_given', ?, ?, '1.0')")
                       ->execute([$pid, $ipHash, $ua]);
                }
            } catch (\Throwable $e) { /* non-critical */ }

            redirect(APP_URL . '/parent/index.php');
        }
        $error = 'Πρέπει να αποδεχτείτε τους Όρους για να συνεχίσετε.';
    } catch (Throwable $e) {
        $error = 'Σφάλμα. Παρακαλώ προσπαθήστε ξανά.';
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title>Αποδοχή Όρων Χρήσης — Portal Γονέων — MAster</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
:root {
  --bg:    #07090f;
  --card:  #111520;
  --brd:   #1e2536;
  --red:   #e63946;
  --green: #2dc653;
  --text:  #f0f2ff;
  --muted: #b0bdd6;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg); color: var(--text);
  min-height: 100vh; min-height: 100dvh;
  display: flex; flex-direction: column;
  align-items: center; justify-content: flex-start;
  padding: 2rem 1.25rem 3rem;
}
.terms-wrap { width: 100%; max-width: 720px; }
.terms-logo {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 2rem; letter-spacing: .08em;
  color: var(--text); text-align: center;
  margin-bottom: 1.5rem;
}
.terms-logo span { color: var(--red); }
.terms-card {
  background: var(--card); border: 1px solid var(--brd);
  border-radius: 18px; overflow: hidden;
}
.terms-header {
  background: rgba(230,57,70,.08); border-bottom: 1px solid var(--brd);
  padding: 1.5rem 2rem;
}
.terms-header h1 {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.8rem; letter-spacing: .05em; color: var(--text);
}
.terms-header p { font-size: .9rem; color: var(--muted); margin-top: .4rem; }
.terms-body {
  padding: 1.5rem 2rem;
  max-height: 55vh; overflow-y: auto;
  border-bottom: 1px solid var(--brd);
  font-size: .9rem; line-height: 1.7; color: var(--muted);
}
.terms-body::-webkit-scrollbar { width: 5px; }
.terms-body::-webkit-scrollbar-thumb { background: var(--brd); border-radius: 99px; }
.terms-body h2 {
  font-size: 1rem; font-weight: 800; color: var(--text);
  margin: 1.2rem 0 .4rem;
}
.terms-body ul { padding-left: 1.2rem; margin-top: .3rem; }
.terms-body li { margin-bottom: .25rem; }
.terms-footer { padding: 1.5rem 2rem; }
.terms-check {
  display: flex; align-items: flex-start; gap: .75rem;
  margin-bottom: 1.25rem; cursor: pointer;
}
.terms-check input[type="checkbox"] {
  width: 20px; height: 20px; min-width: 20px;
  accent-color: var(--red); cursor: pointer; margin-top: .1rem;
}
.pp-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
  padding: .8rem 2rem; border-radius: 12px;
  font-size: 1rem; font-weight: 800; cursor: pointer; font-family: inherit;
  border: none; transition: all .2s; min-height: 48px;
  text-decoration: none; width: 100%;
}
.pp-btn-red  { background: var(--red); color: #fff; }
.pp-btn-red:hover:not(:disabled) { background: #c0303b; }
.pp-btn-red:disabled { opacity: .45; cursor: not-allowed; }
.pp-btn-dark { background: #1a2035; color: var(--muted); border: 1px solid var(--brd); margin-top: .75rem; }
.pp-btn-dark:hover { background: #222c44; color: var(--text); }
.error-msg {
  background: rgba(230,57,70,.1); border: 1px solid rgba(230,57,70,.3);
  color: var(--red); border-radius: 10px; padding: .85rem 1rem;
  font-size: .9rem; font-weight: 700; margin-bottom: 1rem;
  display: flex; align-items: center; gap: .5rem;
}
.logout-link {
  display: block; text-align: center; margin-top: 1rem;
  font-size: .85rem; color: var(--muted); text-decoration: none;
}
.logout-link:hover { color: var(--red); }

/* ── MODALS ── */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.78);
  display: none;
  align-items: center; justify-content: center;
  z-index: 9999; padding: 1.25rem;
}
.modal-overlay.is-open { display: flex; }
.modal-box {
  background: var(--card); border: 1px solid var(--brd);
  padding: 2rem 1.75rem; border-radius: 18px;
  width: 100%; max-width: 420px; text-align: center;
  animation: mIn .2s ease-out both;
}
@keyframes mIn {
  from { opacity: 0; transform: scale(.93) translateY(10px); }
  to   { opacity: 1; transform: scale(1)   translateY(0); }
}
.modal-icon { font-size: 2.6rem; margin-bottom: .75rem; }
.modal-box h3 {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.7rem; letter-spacing: .04em;
  color: var(--text); margin-bottom: .6rem;
}
.modal-box p {
  color: var(--muted); font-size: .95rem;
  line-height: 1.65; margin-bottom: 1.5rem;
}
.modal-actions { display: flex; flex-direction: column; gap: .6rem; }
.modal-actions .pp-btn { margin-top: 0; }

@media (max-width: 480px) {
  .terms-header, .terms-body, .terms-footer { padding: 1.2rem 1.25rem; }
}
</style>
</head>
<body>

<div class="terms-wrap">
  <div class="terms-logo">MA<span>ster</span></div>

  <div class="terms-card">
    <div class="terms-header">
      <h1><i class="fas fa-file-contract" style="color:var(--red);margin-right:.4rem"></i>Όροι Χρήσης Πύλης Γονέων</h1>
      <p>Απαιτείται αποδοχή για πρόσβαση στον λογαριασμό σας.</p>
    </div>

    <div class="terms-body" id="termsText">
      <h2>1. Παροχός Υπηρεσίας</h2>
      <p>Η Πύλη Γονέων MAster παρέχεται από την <strong>ΚΟΤΣΟΡΓΙΟΣ ΠΑΝΑΓΙΩΤΗΣ</strong> (ατομική επιχείρηση), Εργατικές Κατοικίες 113 Λιμάνι Μεσολόγγι, 30200, Ελλάδα. Email: <strong>pkotsorgios654@gmail.com</strong>.</p>
      <p>Η Πύλη λειτουργεί <strong>κατ' εντολή της σχολής</strong> στην οποία είναι εγγεγραμμένο το παιδί σας, η οποία είναι ο Υπεύθυνος Επεξεργασίας των δεδομένων σας σύμφωνα με τον ΓΚΠΔ.</p>

      <h2>2. Τι Προσφέρει η Πύλη</h2>
      <p>Μέσω της Πύλης έχετε πρόσβαση αποκλειστικά σε:</p>
      <ul>
        <li>Ιστορικό πληρωμών και κατάσταση συνδρομής του παιδιού σας</li>
        <li>Στοιχεία επικοινωνίας της σχολής για εκκρεμείς οφειλές</li>
      </ul>
      <p>Δεν είναι διαθέσιμα δεδομένα άλλων αθλητών ή γονέων.</p>

      <h2>3. Δεδομένα που Επεξεργαζόμαστε</h2>
      <p>Για τη λειτουργία της Πύλης επεξεργαζόμαστε τα εξής δεδομένα σας:</p>
      <ul>
        <li><strong>Email:</strong> αποθηκεύεται ως αναγνωριστικό λογαριασμού</li>
        <li><strong>Κωδικός πρόσβασης:</strong> αποθηκεύεται κρυπτογραφημένος (bcrypt) — δεν είναι ποτέ ορατός</li>
        <li><strong>Ημερομηνία τελευταίας σύνδεσης</strong></li>
        <li><strong>Σχέση με παιδιά-αθλητές:</strong> μόνο ο σύνδεσμος (ID) — τα στοιχεία των παιδιών διαχειρίζονται από τη σχολή</li>
        <li><strong>Προτιμήσεις ειδοποιήσεων:</strong> αν έχετε επιλέξει opt-out από SMS/email</li>
        <li><strong>Ημερομηνία αποδοχής παρόντων Όρων</strong> και έκδοση αποδεχθέντων Όρων</li>
      </ul>
      <p><strong>Νομική βάση:</strong> Εκτέλεση υπηρεσίας κατ' εντολή της σχολής (άρθρο 6§1β ΓΚΠΔ). Για SMS: ρητή συγκατάθεση (άρθρο 6§1α ΓΚΠΔ + Ν.3471/2006).</p>

      <h2>4. Ειδοποιήσεις — Email &amp; SMS</h2>
      <p><strong>Email:</strong> Ενδέχεται να σας αποστέλλουμε email για εκκρεμείς πληρωμές ή ανανεώσεις συνδρομής, βάσει εντολής της σχολής. Η νομική βάση είναι το έννομο συμφέρον της σχολής (άρθρο 6§1στ ΓΚΠΔ).</p>
      <p><strong>SMS:</strong> Αποστέλλεται <strong>μόνο εφόσον δώσετε ρητή συγκατάθεση</strong> παρακάτω, σύμφωνα με τον Ν.3471/2006 (ePrivacy) και τον ΓΚΠΔ.</p>
      <p><strong>Διακοπή ειδοποιήσεων — Opt-out:</strong></p>
      <ul>
        <li><strong>Μέσω Πύλης:</strong> Ρυθμίσεις → Ειδοποιήσεις → απενεργοποίηση email/SMS (άμεση ισχύς)</li>
        <li><strong>Email opt-out:</strong> Κάθε email περιέχει σύνδεσμο «Διακοπή ειδοποιήσεων» — ένα κλικ αρκεί</li>
        <li><strong>SMS opt-out:</strong> Αποστείλτε SMS με το κείμενο <strong>STOP</strong> στον αριθμό <strong>+30 6986788178</strong></li>
        <li><strong>Email αίτημα:</strong> Αποστείλτε email με θέμα <strong>STOP</strong> στο <strong>pkotsorgios654@gmail.com</strong></li>
      </ul>
      <p>Κάθε αίτημα opt-out ισχύει <strong>εντός 48 ωρών</strong>. Λαμβάνετε επιβεβαιωτικό email.</p>

      <h2>5. Τα Δικαιώματά Σας (ΓΚΠΔ)</h2>
      <p>Έχετε δικαίωμα <strong>πρόσβασης, διόρθωσης, διαγραφής, περιορισμού επεξεργασίας, φορητότητας και εναντίωσης</strong> στα δεδομένα σας. Για δεδομένα που αφορούν τα παιδιά σας (εισαγμένα από τη σχολή), τα αιτήματα απευθύνονται στη σχολή ως Υπεύθυνο Επεξεργασίας.</p>
      <p>Για δεδομένα λογαριασμού Πύλης (email, κωδικός, προτιμήσεις): υποβολή αιτήματος από <a href="settings.php" style="color:var(--red)">Ρυθμίσεις</a> ή στο <strong>pkotsorgios654@gmail.com</strong>. Απάντηση εντός 30 ημερολογιακών ημερών.</p>
      <p>Έχετε επίσης δικαίωμα καταγγελίας στην <strong>ΑΠΔΠΧ</strong> (<a href="https://www.dpa.gr" style="color:var(--red)" target="_blank">www.dpa.gr</a>).</p>

      <h2>6. Διαγραφή Λογαριασμού</h2>
      <p>Διαγράψτε τον λογαριασμό σας από <a href="settings.php" style="color:var(--red)">Ρυθμίσεις → Διαγραφή Λογαριασμού</a>. Η διαγραφή είναι <strong>άμεση και μόνιμη</strong>. Τα δεδομένα των παιδιών δεν επηρεάζονται.</p>

      <h2>7. Ασφάλεια</h2>
      <p>Οι κωδικοί αποθηκεύονται αποκλειστικά με bcrypt hashing. Η επικοινωνία κρυπτογραφείται με TLS. Τα δεδομένα αποθηκεύονται σε servers εντός ΕΕ.</p>

      <h2>8. Τροποποίηση Όρων</h2>
      <p>Σε ουσιαστική αλλαγή Όρων θα σας ειδοποιήσουμε μέσω email τουλάχιστον 14 ημέρες πριν και θα σας ζητηθεί εκ νέου αποδοχή κατά την επόμενη σύνδεση.</p>

      <h2>9. Εφαρμοστέο Δίκαιο</h2>
      <p>Εφαρμόζεται ελληνικό δίκαιο (ΓΚΠΔ, Ν.4624/2019, Ν.3471/2006). Αρμόδια: Δικαστήρια Μεσολογγίου.</p>
    </div>

    <div class="terms-footer">
      <?php if ($error): ?>
      <div class="error-msg"><i class="fas fa-circle-exclamation"></i><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" id="termsForm">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

        <label class="terms-check">
          <input type="checkbox" name="accept_terms" id="acceptCheck" value="1">
          <span>Διάβασα και αποδέχομαι τους <strong>Όρους Χρήσης</strong> και την <strong>Πολιτική Απορρήτου</strong>.</span>
        </label>

        <label class="terms-check" style="margin-bottom:1.5rem">
          <input type="checkbox" name="sms_consent" id="smsConsentCheck" value="1">
          <span>Συμφωνώ να λαμβάνω <strong>SMS ειδοποιήσεις</strong> για οφειλές και συνδρομές. <em style="color:var(--muted);font-size:.85em">(Προαιρετικό — μπορείτε να αλλάξετε γνώμη από τις Ρυθμίσεις.)</em></span>
        </label>

        <button type="submit" class="pp-btn pp-btn-red" id="acceptBtn" disabled>
          <i class="fas fa-check-circle"></i> Αποδοχή &amp; Συνέχεια
        </button>
      </form>

      <button type="button" class="pp-btn pp-btn-dark" id="rejectBtn">
        <i class="fas fa-xmark-circle"></i> Δεν αποδέχομαι
      </button>

      <a href="<?= APP_URL ?>/logout.php" class="logout-link">
        <i class="fas fa-right-from-bracket"></i> Έξοδος από τον λογαριασμό
      </a>
    </div>
  </div>
</div>

<!-- ── Modal: checkbox not ticked ── -->
<div id="mustAcceptModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-box">
    <div class="modal-icon" style="color:var(--red)"><i class="fas fa-circle-exclamation"></i></div>
    <h3>Απαιτείται Επιλογή</h3>
    <p>Τσεκάρετε το πλαίσιο για να δηλώσετε ότι διαβάσατε και αποδέχεστε τους Όρους.</p>
    <div class="modal-actions">
      <button class="pp-btn pp-btn-red" onclick="closeModal('mustAcceptModal')">
        <i class="fas fa-arrow-left"></i> Πίσω
      </button>
    </div>
  </div>
</div>

<!-- ── Modal: reject — NOT forced logout, just inform and let them decide ── -->
<div id="rejectModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-box">
    <div class="modal-icon" style="color:var(--muted)"><i class="fas fa-ban"></i></div>
    <h3>Δεν Αποδέχεστε;</h3>
    <p>
      Δεν πειράζει — μπορείτε να αποδεχτείτε αργότερα.
      Η σελίδα αυτή θα εμφανίζεται σε <strong style="color:var(--text)">κάθε σύνδεση</strong> έως ότου αποδεχτείτε τους Όρους.
    </p>
    <div class="modal-actions">
      <!-- Close modal, stay on terms page -->
      <button class="pp-btn pp-btn-red" onclick="closeModal('rejectModal')">
        <i class="fas fa-arrow-left"></i> Επιστροφή — θα το διαβάσω
      </button>
      <!-- Voluntary logout without accepting -->
      <a href="<?= APP_URL ?>/logout.php" class="pp-btn pp-btn-dark">
        <i class="fas fa-right-from-bracket"></i> Αποσύνδεση τώρα
      </a>
    </div>
  </div>
</div>

<script>
(function () {
  var cb        = document.getElementById('acceptCheck');
  var acceptBtn = document.getElementById('acceptBtn');
  var rejectBtn = document.getElementById('rejectBtn');
  var form      = document.getElementById('termsForm');

  cb.addEventListener('change', function () {
    acceptBtn.disabled = !cb.checked;
  });

  form.addEventListener('submit', function (e) {
    if (!cb.checked) {
      e.preventDefault();
      openModal('mustAcceptModal');
    }
  });

  rejectBtn.addEventListener('click', function () {
    openModal('rejectModal');
  });

  document.querySelectorAll('.modal-overlay').forEach(function (o) {
    o.addEventListener('click', function (e) {
      if (e.target === o) closeModal(o.id);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay.is-open').forEach(function (m) {
        closeModal(m.id);
      });
    }
  });
})();

function openModal(id)  { document.getElementById(id).classList.add('is-open'); }
function closeModal(id) { document.getElementById(id).classList.remove('is-open'); }
</script>
</body>
</html>