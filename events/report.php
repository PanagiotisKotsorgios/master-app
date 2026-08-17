<?php
/**
 * events/report.php — Public "report this event" form + POST handler
 * ============================================================
 * Anti-abuse tool. Anyone can report; superadmin moderates in /admin/event_moderation.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';

$slug = trim($_GET['slug'] ?? $_POST['slug'] ?? '');
$ev   = $slug ? eventGetBySlug($slug) : null;
if (!$ev) { http_response_code(404); exit('Event not found'); }

$done = false; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $reason  = mb_substr(trim($_POST['reason']  ?? ''), 0, 60);
        $details = mb_substr(trim($_POST['details'] ?? ''), 0, 500);
        $email   = filter_var(strtolower(trim($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null;

        if (!$reason) throw new RuntimeException('Επιλέξτε λόγο.');

        // Simple per-IP rate limit: max 5 reports / IP / hour
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = trim(explode(',', $ip)[0]);

        $chk = getDB()->prepare("SELECT COUNT(*) FROM event_reports WHERE reporter_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $chk->execute([$ip]);
        if ((int)$chk->fetchColumn() >= 5) throw new RuntimeException('Πολλές αναφορές από αυτό το IP. Δοκιμάστε αργότερα.');

        getDB()->prepare("INSERT INTO event_reports (event_id, reporter_ip, reporter_email, reason, details) VALUES (?,?,?,?,?)")
            ->execute([(int)$ev['id'], $ip, $email, $reason, $details]);

        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="el"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Αναφορά event — MAster</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
.card{max-width:520px;width:100%;background:#111520;border:1px solid #1e2536;border-radius:14px;padding:2rem}
h1{font-size:1.4rem;margin-bottom:.5rem}
.sub{color:#8892b0;margin-bottom:1.5rem}
label{display:block;margin-bottom:1rem}
label div{color:#c8cfe0;font-size:.85rem;font-weight:700;margin-bottom:.35rem}
select,input,textarea{width:100%;padding:.7rem;background:#0d1017;border:1px solid #2a3248;border-radius:8px;color:#f0f2ff;font-family:inherit}
button{width:100%;padding:.85rem;background:#e63946;color:#fff;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-family:inherit}
.ok{background:rgba(45,198,83,.1);border:1px solid rgba(45,198,83,.35);color:#2dc653;padding:1rem;border-radius:10px;margin-bottom:1rem}
.err{background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.35);color:#ffb3b8;padding:1rem;border-radius:10px;margin-bottom:1rem}
a{color:#8892b0;text-decoration:none;font-size:.85rem;display:inline-block;margin-top:1rem}
</style>
</head><body>
<div class="card">
  <?php if ($done): ?>
    <div class="ok">✓ Η αναφορά υποβλήθηκε. Θα την εξετάσουμε το συντομότερο.</div>
    <a href="<?= h(eventPublicUrl($ev)) ?>">← Πίσω στο event</a>
  <?php else: ?>
    <h1>Αναφορά event</h1>
    <p class="sub"><?= h($ev['title']) ?></p>
    <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="slug" value="<?= h($slug) ?>">
      <label><div>Λόγος</div>
        <select name="reason" required>
          <option value="">— Επιλέξτε —</option>
          <option>spam</option>
          <option>παραπλανητικό περιεχόμενο</option>
          <option>ακατάλληλο περιεχόμενο</option>
          <option>πλαστοπροσωπία</option>
          <option>άλλο</option>
        </select>
      </label>
      <label><div>Λεπτομέρειες</div>
        <textarea name="details" rows="4" maxlength="500"></textarea>
      </label>
      <label><div>Email επικοινωνίας (προαιρετικά)</div>
        <input type="email" name="email">
      </label>
      <button type="submit">Υποβολή αναφοράς</button>
    </form>
    <a href="<?= h(eventPublicUrl($ev)) ?>">← Ακύρωση</a>
  <?php endif; ?>
</div>
</body></html>
