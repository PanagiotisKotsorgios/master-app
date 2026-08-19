<?php
/**
 * unsubscribe.php — one-click newsletter unsubscribe endpoint.
 *
 * URL: /unsubscribe.php?t={unsubscribe_token}
 * Marks the subscriber row as 'unsubscribed' and shows a small
 * confirmation page. Idempotent — safe to visit multiple times.
 */

require_once __DIR__ . '/includes/config.php';

$token = trim($_GET['t'] ?? '');
$ok = false;
$message = 'Ο σύνδεσμος διαγραφής δεν είναι έγκυρος.';

if (preg_match('/^[a-f0-9]{48}$/', $token)) {
    try {
        $db = getDB();
        $st = $db->prepare("SELECT id, email, status FROM newsletter_subscribers WHERE unsubscribe_token = ? LIMIT 1");
        $st->execute([$token]);
        $sub = $st->fetch();
        if ($sub) {
            if ($sub['status'] !== 'unsubscribed') {
                $db->prepare("UPDATE newsletter_subscribers
                                 SET status = 'unsubscribed', unsubscribed_at = NOW()
                               WHERE id = ?")
                   ->execute([(int)$sub['id']]);
            }
            $ok = true;
            $message = 'Η διεύθυνση <strong>' . h($sub['email']) . '</strong> αφαιρέθηκε από το newsletter.';
        }
    } catch (Throwable $e) {
        error_log('[MAster unsubscribe] failed: ' . $e->getMessage());
        $message = 'Προσωρινό πρόβλημα. Δοκιμάστε ξανά αργότερα.';
    }
}
?><!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Διαγραφή από Newsletter — MAster</title>
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
    radial-gradient(1000px 600px at 20% 0%, rgba(230,57,70,.12), transparent 60%),
    radial-gradient(800px 500px at 80% 100%, rgba(240,165,0,.08), transparent 60%),
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
}
.icon.ok  {background:linear-gradient(135deg,rgba(45,198,83,.25),rgba(45,198,83,.1));color:#7bffb4;border:1px solid rgba(45,198,83,.3)}
.icon.err {background:linear-gradient(135deg,rgba(230,57,70,.25),rgba(230,57,70,.1));color:#ffb0b8;border:1px solid rgba(230,57,70,.3)}
h1{font-size:1.4rem;font-weight:800;margin-bottom:.5rem;letter-spacing:-.02em}
p{color:#c9cee1;line-height:1.6;font-size:.98rem;margin-bottom:1.5rem}
.home{
  display:inline-flex;align-items:center;gap:.5rem;
  padding:.7rem 1.4rem;border-radius:10px;
  background:linear-gradient(135deg,#e63946,#c72832);
  color:#fff;font-weight:700;font-size:.9rem;text-decoration:none;
  transition:transform .18s ease;
}
.home:hover{transform:translateY(-1px)}
</style>
</head>
<body>
<div class="box">
  <?php if ($ok): ?>
    <div class="icon ok"><i class="fa-solid fa-check"></i></div>
    <h1>Διαγραφή επιτυχής</h1>
  <?php else: ?>
    <div class="icon err"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <h1>Δεν βρέθηκε</h1>
  <?php endif; ?>
  <p><?= $message /* already escaped where dynamic */ ?></p>
  <a class="home" href="<?= APP_URL ?>/"><i class="fa-solid fa-house"></i> Επιστροφή στην αρχική</a>
</div>
</body>
</html>
