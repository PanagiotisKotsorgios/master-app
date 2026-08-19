<?php
/**
 * pending-approval.php — Shown when a school is NOT approved and
 * hits a page guarded by requireApprovedSchool().
 *
 * Renders in the same brand palette as pre-login pages. Provides
 * a link back to the dashboard for pages that don't need approval.
 */

require_once __DIR__ . '/includes/config.php';

// Not logged in? Send to login.
if (!isLoggedIn()) redirect(APP_URL . '/login.php');

$status = $_SESSION['school_approval_reason'] ?? schoolApprovalStatus();

$labels = [
    'pending'   => ['Σε εκκρεμότητα έγκρισης',   'Η αίτηση της σχολής σας ελέγχεται από τον διαχειριστή.'],
    'rejected'  => ['Απορρίφθηκε',                'Η αίτηση της σχολής σας δεν εγκρίθηκε. Επικοινωνήστε με τον διαχειριστή για λεπτομέρειες.'],
    'suspended' => ['Προσωρινή αναστολή',         'Ο λογαριασμός της σχολής σας είναι προσωρινά ανενεργός. Επικοινωνήστε με τον διαχειριστή.'],
];
[$title, $body] = $labels[$status] ?? ['Απαιτείται έγκριση', 'Η ενέργεια αυτή δεν είναι διαθέσιμη ακόμα.'];
?><!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> — MAster</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;
  min-height:100vh;min-height:100dvh;
  display:flex;align-items:center;justify-content:center;padding:2rem 1rem;
  background:
    radial-gradient(1000px 600px at 20% 0%, rgba(240,165,0,.12), transparent 60%),
    radial-gradient(800px 500px at 80% 100%, rgba(230,57,70,.08), transparent 60%),
    #07090f;
}
.box{
  max-width:520px;width:100%;
  background:linear-gradient(180deg,rgba(19,23,34,.85),rgba(13,16,23,.85));
  border:1px solid rgba(255,255,255,.07);border-radius:20px;
  padding:2.25rem 1.75rem;text-align:center;
  backdrop-filter:blur(20px);
  box-shadow:0 30px 60px -20px rgba(0,0,0,.6);
}
.icon{
  width:76px;height:76px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 1.25rem;font-size:2rem;
  background:linear-gradient(135deg,rgba(240,165,0,.25),rgba(240,165,0,.1));
  color:#ffd870;border:1px solid rgba(240,165,0,.3);
}
h1{font-size:1.4rem;font-weight:800;margin-bottom:.5rem;letter-spacing:-.02em}
p{color:#c9cee1;line-height:1.6;font-size:.98rem;margin-bottom:1.5rem}
.status{
  display:inline-block;padding:.3rem .75rem;border-radius:99px;
  background:rgba(240,165,0,.15);border:1px solid rgba(240,165,0,.3);
  color:#ffd870;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;
  margin-bottom:1rem;
}
.actions{display:flex;flex-direction:column;gap:.5rem;align-items:center}
.btn{
  display:inline-flex;align-items:center;gap:.5rem;
  padding:.7rem 1.4rem;border-radius:10px;
  font-weight:700;font-size:.9rem;text-decoration:none;
  transition:transform .18s ease;
}
.btn-primary{background:linear-gradient(135deg,#e63946,#c72832);color:#fff}
.btn-outline{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);color:#c9cee1}
.btn:hover{transform:translateY(-1px)}
</style>
</head>
<body>
<div class="box">
  <div class="icon"><i class="fa-solid fa-user-clock"></i></div>
  <div class="status"><?= h($status) ?></div>
  <h1><?= h($title) ?></h1>
  <p><?= h($body) ?></p>
  <div class="actions">
    <a class="btn btn-primary" href="<?= APP_URL ?>/dashboard/"><i class="fa-solid fa-house"></i> Πίσω στο dashboard</a>
    <a class="btn btn-outline" href="<?= APP_URL ?>/contact.php"><i class="fa-solid fa-envelope"></i> Επικοινωνία με admin</a>
  </div>
</div>
</body>
</html>
