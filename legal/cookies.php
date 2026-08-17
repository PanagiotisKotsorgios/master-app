<?php
require_once __DIR__ . '/legal-base.php';
legalHeader('Πολιτική Cookies & Παρόμοιων Τεχνολογιών', '30 Μαρτίου 2026');
?>

<style>
.table-wrap {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  margin: 1.25rem 0;
  border-radius: var(--radius);
  background:
    linear-gradient(to right, var(--bg2) 20%, transparent),
    linear-gradient(to left,  var(--bg2) 20%, transparent) 100% 0,
    radial-gradient(farthest-side at 0   50%, rgba(0,0,0,.25), transparent),
    radial-gradient(farthest-side at 100% 50%, rgba(0,0,0,.25), transparent) 100% 0;
  background-repeat: no-repeat;
  background-size: 40px 100%, 40px 100%, 14px 100%, 14px 100%;
  background-attachment: local, local, scroll, scroll;
}
.table-wrap .legal-table {
  margin: 0;
  min-width: 420px;
}
@media(max-width:480px){
  .table-wrap .legal-table th,
  .table-wrap .legal-table td {
    font-size: .75rem;
    padding: .45rem .6rem;
  }
  .table-wrap .legal-table code {
    font-size: .72rem;
  }
}
</style>

<div class="info-box">
  <p><strong>Σε σύντομη μορφή:</strong> Χρησιμοποιούμε cookies και παρόμοιες τεχνολογίες (localStorage) απαραίτητες για τη λειτουργία της πλατφόρμας. Cookies ανάλυσης χρησιμοποιούνται <strong>μόνο με τη ρητή συγκατάθεσή σας</strong>. Δεν χρησιμοποιούμε cookies για διαφημιστικούς σκοπούς.</p>
</div>

<h2><i class="fas fa-cookie-bite" style="color:var(--red);margin-right:.5rem"></i>1. Τι Είναι τα Cookies & Παρόμοιες Τεχνολογίες</h2>
<p>Τα cookies είναι μικρά αρχεία κειμένου που αποθηκεύονται στη συσκευή σας. Παρόμοιες τεχνολογίες περιλαμβάνουν:</p>
<ul>
  <li><strong>localStorage / sessionStorage:</strong> Αποθήκευση δεδομένων στον browser χωρίς λήξη (localStorage) ή έως το κλείσιμο του παραθύρου (sessionStorage)</li>
  <li><strong>Pixels / web beacons:</strong> Μικρές διαφανείς εικόνες που καταγράφουν αλληλεπίδραση</li>
</ul>
<p>Η παρούσα Πολιτική καλύπτει <strong>οποιαδήποτε αποθήκευση ή πρόσβαση σε πληροφορία στον τερματικό εξοπλισμό</strong> σύμφωνα με το άρθρο 5§3 της Οδηγίας ePrivacy 2002/58/ΕΚ και τον Ν.3471/2006 (ελληνική εφαρμογή). Η αποθήκευση ή πρόσβαση σε πληροφορία στον τερματικό εξοπλισμό επιτρέπεται μόνο με συγκατάθεση μετά από σαφή ενημέρωση, <strong>εκτός των τεχνικώς απαραίτητων</strong>.</p>

<h2><i class="fas fa-list-check" style="color:var(--red);margin-right:.5rem"></i>2. Κατηγορίες Cookies & Τεχνολογιών που Χρησιμοποιούμε</h2>

<h3>2.1 Απολύτως Απαραίτητα (Χωρίς Συγκατάθεση)</h3>
<p>Αυτά είναι αναγκαία για την ασφαλή λειτουργία της πλατφόρμας. Δεν μπορούν να απενεργοποιηθούν καθώς χωρίς αυτά η πλατφόρμα δεν λειτουργεί.</p>
<div class="table-wrap">
<table class="legal-table">
  <thead>
    <tr><th>Τεχνολογία</th><th>Τύπος</th><th>Σκοπός</th><th>Διάρκεια</th></tr>
  </thead>
  <tbody>
    <tr><td><code>session_id</code></td><td>Cookie (HTTP)</td><td>Διατήρηση σύνδεσης χρήστη (PHP session)</td><td>Λήξη session</td></tr>
    <tr><td><code>csrf_token</code></td><td>Cookie (HTTP)</td><td>Προστασία από CSRF επιθέσεις</td><td>Λήξη session</td></tr>
    <tr><td><code>cookie_consent</code></td><td>Cookie (HTTP)</td><td>Αποθήκευση επιλογής συγκατάθεσης (necessary/analytics)</td><td>12 μήνες</td></tr>
    <tr><td><code>ck_consent</code></td><td>localStorage</td><td>Εφεδρική αποθήκευση επιλογής consent στον browser</td><td>Μέχρι εκκαθάριση browser</td></tr>
  </tbody>
</table>
</div>

<h3>2.2 Λειτουργικά (Απαιτείται Συγκατάθεση — Default OFF)</h3>
<p>Βελτιώνουν την εμπειρία χρήσης αποθηκεύοντας επιλογές που ζητάτε εσείς. Σύμφωνα με την αρχή ελαχιστοποίησης και τις EDPB κατευθύνσεις, αντιμετωπίζονται ως <strong>μη απολύτως απαραίτητα</strong> και ενεργοποιούνται <strong>μόνο μετά από ρητή επιλογή σας</strong> στο banner ή τις ρυθμίσεις cookies (default OFF). Περιλαμβάνονται cookies προτιμήσεων και «Να με θυμάσαι», καθώς σύμφωνα με τις κατευθύνσεις EDPB και πρακτική DPAs, το <code>remember_me</code> δεν θεωρείται αυτόματα strictly necessary εκτός αν αιτηθεί ρητά από τον χρήστη στη συγκεκριμένη συνεδρία.</p>
<div class="table-wrap">
<table class="legal-table">
  <thead>
    <tr><th>Τεχνολογία</th><th>Τύπος</th><th>Σκοπός</th><th>Διάρκεια</th></tr>
  </thead>
  <tbody>
    <tr><td><code>remember_me</code></td><td>Cookie (HTTP)</td><td>«Να με θυμάσαι» — παραμονή σύνδεσης κατόπιν ρητής επιλογής χρήστη</td><td>30 ημέρες</td></tr>
    <tr><td><code>ui_theme</code></td><td>Cookie (HTTP)</td><td>Αποθήκευση προτιμήσεων διεπαφής</td><td>12 μήνες</td></tr>
    <tr><td><code>locale</code></td><td>Cookie (HTTP)</td><td>Γλώσσα εφαρμογής</td><td>12 μήνες</td></tr>
    <tr><td><code>last_club_id</code></td><td>Cookie (HTTP)</td><td>Τελευταίο ενεργό σωματείο (multi-club)</td><td>30 ημέρες</td></tr>
  </tbody>
</table>
</div>

<div class="info-box">
  <p><strong>Σημείωση για popup/UI cookies:</strong> Τυχόν cookies ή localStorage εγγραφές που χρησιμοποιούνται για την αποθήκευση κατάστασης UI στοιχείων (π.χ. εάν έχει εμφανιστεί ένα popup) που <em>δεν είναι αναγκαία για τη λειτουργία</em> ενεργοποιούνται μόνο μετά από επιλογή του χρήστη στο banner cookies («Λειτουργικά»).</p>
</div>

<h3>2.3 Αποθήκευση Απόδειξης Συγκατάθεσης (Consent Proof) — Απαραίτητο</h3>
<p>Για να αποδείξουμε τη συμμόρφωσή μας με τον ΓΚΠΔ, αποθηκεύουμε αρχεία συγκατάθεσης:</p>
<div class="table-wrap">
<table class="legal-table">
  <thead>
    <tr><th>Τεχνολογία</th><th>Τύπος</th><th>Δεδομένα</th><th>Διάρκεια</th><th>Σκοπός</th></tr>
  </thead>
  <tbody>
    <tr><td>Consent proof log</td><td>Server-side log</td><td><strong>Ψευδωνυμοποιημένο</strong> hash IP (με salt/rotation, περιορισμένη πρόσβαση), timestamp, user agent, έκδοση κειμένου συγκατάθεσης, επιλογή (accepted/rejected categories). Σημείωση: πρόκειται για ψευδωνυμοποιημένα, όχι ανώνυμα, δεδομένα.</td><td>12 μήνες</td><td>Απόδειξη συμμόρφωσης ΓΚΠΔ (άρθρα 5§2, 7§1) — αποκλειστικά</td></tr>
    <tr><td><code>ck_consent</code> (localStorage)</td><td>localStorage</td><td>Επιλεγμένες κατηγορίες cookies, timestamp επιλογής</td><td>Μέχρι εκκαθάριση browser</td><td>Αποφυγή επαναλαμβανόμενης εμφάνισης banner</td></tr>
  </tbody>
</table>
</div>

<h3>2.4 Cookies Ανάλυσης — <strong>Απαιτείται Ρητή Συγκατάθεση</strong></h3>
<p>Χρησιμοποιούνται μόνο αφού δώσετε ρητή συγκατάθεση. Εάν επιλέξετε «Απόρριψη μη απαραίτητων» ή «Μόνο Απαραίτητα», αυτά <strong>δεν ενεργοποιούνται ποτέ</strong>.</p>
<div class="table-wrap">
<table class="legal-table">
  <thead>
    <tr><th>Πάροχος</th><th>Cookie/Τεχνολογία</th><th>Σκοπός</th><th>Διάρκεια</th><th>Μηχανισμός διαβίβασης</th></tr>
  </thead>
  <tbody>
    <tr><td>Google Analytics (αν ενεργό)</td><td><code>_ga</code>, <code>_gid</code>, <code>_gat</code></td><td>Στατιστικά επισκεψιμότητας (ανωνυμοποιημένα, IP anonymization ενεργό)</td><td>2 χρόνια / 24ω / 1 λεπτό</td><td>EU-US DPF</td></tr>
    <tr><td>Εσωτερικά analytics</td><td><code>usage_stats</code> (localStorage)</td><td>Πλοήγηση εντός dashboard (για βελτίωση UX)</td><td>90 ημέρες</td><td>—</td></tr>
  </tbody>
</table>
</div>
<div class="info-box gold">
  <p><strong>Σημείωση Google Analytics:</strong> Έχουμε ενεργοποιήσει ανωνυμοποίηση IP (<code>anonymizeIp: true</code>) και δεν μεταφέρουμε άμεσα αναγνωρίσιμα προσωπικά δεδομένα. Η Google LLC είναι πιστοποιημένη στο EU-US Data Privacy Framework. Ισχύει το <a href="https://business.safety.google/adsprocessorterms/" target="_blank">Google Analytics Data Processing Agreement</a>.</p>
</div>

<h3>2.5 Cookies Τρίτων Παρόχων Υπηρεσιών (Απαραίτητα για Λειτουργία)</h3>
<div class="table-wrap">
<table class="legal-table">
  <thead>
    <tr><th>Πάροχος</th><th>Σκοπός</th><th>Τόπος</th><th>Μηχανισμός</th><th>Πολιτική Απορρήτου</th></tr>
  </thead>
  <tbody>
    <tr><td><strong>Cloudflare</strong></td><td>CDN, DDoS προστασία, WAF, __cf_bm (bot management)</td><td>ΗΠΑ / παγκόσμια</td><td>EU-US DPF + SCCs</td><td><a href="https://www.cloudflare.com/privacypolicy/" target="_blank">cloudflare.com</a></td></tr>
  </tbody>
</table>
</div>

<h3>2.6 Φόρτωση Πόρων Τρίτων (CDN / Γραμματοσειρές)</h3>
<p>Κατά την πρόσβαση στον ιστότοπο, ενδέχεται να φορτώνονται γραμματοσειρές ή βιβλιοθήκες από τρίτους παρόχους (π.χ. Google Fonts, cdnjs.cloudflare.com). Αυτό συνεπάγεται μετάδοση τεχνικών δεδομένων (π.χ. διεύθυνση IP, user-agent) προς τους παρόχους. Αξιολογούμε σε συνεχή βάση τη δυνατότητα self-hosting αυτών των πόρων για περιορισμό των διαβιβάσεων.</p>

<h2><i class="fas fa-sliders" style="color:var(--red);margin-right:.5rem"></i>3. Διαχείριση & Ανάκληση Συγκατάθεσης</h2>

<h3>3.1 Μέσω του Banner Συγκατάθεσης</h3>
<p>Κατά την πρώτη επίσκεψη, εμφανίζεται banner με <strong>ισοδύναμες επιλογές</strong>:</p>
<ul>
  <li><strong>«Αποδοχή Όλων»</strong> — επιτρέπει απαραίτητα + λειτουργικά + analytics</li>
  <li><strong>«Απόρριψη Μη Απαραίτητων»</strong> — επιτρέπει μόνο απαραίτητα (ίδιο οπτικό βάρος με «Αποδοχή Όλων»)</li>
  <li><strong>«Ρυθμίσεις»</strong> — ανοίγει αναλυτικό modal για granular επιλογές</li>
</ul>
<p>Στο modal ρυθμίσεων, οι μη απαραίτητες κατηγορίες εμφανίζονται με <strong>default OFF</strong> και ενεργοποιούνται μόνο μετά από ενεργή επιλογή.</p>

<div class="info-box green">
  <p><strong>Ανάκληση συγκατάθεσης:</strong> Μπορείτε να αλλάξετε ή ανακαλέσετε τις επιλογές σας ανά πάσα στιγμή, με <strong>την ίδια ευκολία</strong> που τις δώσατε, μέσω:</p>
  <ul style="margin-top:.5rem">
    <li>Του εικονιδίου <strong>🍪 Cookies</strong> στο footer κάθε σελίδας</li>
    <li>Των ρυθμίσεων λογαριασμού (αν είστε συνδεδεμένοι)</li>
  </ul>
  <p style="margin-top:.5rem">Η ανάκληση συγκατάθεσης ισχύει <strong>από το σημείο ανάκλησης και μετά</strong> — δεν έχει αναδρομική ισχύ.</p>
</div>

<h3>3.2 Μέσω του Browser</h3>
<ul>
  <li><strong>Chrome:</strong> Ρυθμίσεις → Απόρρητο → Cookies και δεδομένα ιστοτόπων</li>
  <li><strong>Firefox:</strong> Επιλογές → Απόρρητο & Ασφάλεια → Cookies</li>
  <li><strong>Safari:</strong> Προτιμήσεις → Απόρρητο</li>
  <li><strong>Edge:</strong> Ρυθμίσεις → Cookies και άδειες ιστοτόπων</li>
</ul>
<div class="info-box">
  <p><strong>Προσοχή:</strong> Η απενεργοποίηση απαραίτητων cookies ή η εκκαθάριση localStorage ενδέχεται να επηρεάσει τη λειτουργία της πλατφόρμας (π.χ. αδυναμία σύνδεσης, επαναφορά preferences).</p>
</div>

<h2><i class="fas fa-rotate" style="color:var(--red);margin-right:.5rem"></i>4. Ενημέρωση Πολιτικής</h2>
<p>Ενδέχεται να ενημερώνουμε την παρούσα Πολιτική. Σε ουσιώδεις αλλαγές (π.χ. προσθήκη νέας κατηγορίας cookies ή νέου παρόχου), θα σας ζητήσουμε εκ νέου ρητή συγκατάθεση για τα non-essential cookies. Η ημερομηνία τελευταίας ενημέρωσης εμφανίζεται στην κορυφή αυτής της σελίδας.</p>

<h2><i class="fas fa-envelope" style="color:var(--red);margin-right:.5rem"></i>5. Επικοινωνία</h2>
<p>Για ερωτήσεις σχετικά με cookies και τερματικές τεχνολογίες: <a href="mailto:pkotsorgios654@gmail.com">pkotsorgios654@gmail.com</a></p>
<p>Για ερωτήσεις σχετικά με την επεξεργασία προσωπικών δεδομένων: βλ. <a href="privacy.php">Πολιτική Απορρήτου</a> και <a href="gdpr.php">GDPR / DPA</a>.</p>

<?php legalFooter(); ?>