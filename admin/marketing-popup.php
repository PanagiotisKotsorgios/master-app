<?php
/**
 * ============================================================
 * admin/marketing-popup.php — Διαχείριση Popup Καμπάνιας
 * ============================================================
 * Superadmin-only:
 *   • Δημιουργία / επεξεργασία του ενεργού popup που εμφανίζεται
 *     μία φορά ανά χρήστη μετά τη σύνδεση.
 *   • Ενεργοποίηση / απενεργοποίηση, χρονικό παράθυρο, ακροατήριο.
 *   • Λίστα ενδιαφερόμενων χρηστών (όσοι πάτησαν το CTA).
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireSuperAdmin();

$db = getDB();

// ── POST actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['_action'] ?? '';

    if ($act === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $title         = trim($_POST['title'] ?? '');
        $body          = trim($_POST['body_html'] ?? '');
        $ctaLabel      = trim($_POST['cta_label'] ?? 'Ενδιαφέρομαι');
        $dismissLabel  = trim($_POST['dismiss_label'] ?? 'Αργότερα');
        $icon          = trim($_POST['icon'] ?? 'fa-solid fa-globe');
        $notifyEmail   = trim($_POST['notify_email'] ?? '');
        $enabled       = !empty($_POST['enabled']) ? 1 : 0;
        $audience      = in_array(($_POST['audience'] ?? 'all'), ['all','club_admins','parents','employees'], true) ? $_POST['audience'] : 'all';
        $startsAt      = trim($_POST['starts_at'] ?? '');
        $endsAt        = trim($_POST['ends_at'] ?? '');

        if (!$title || !$body) {
            flash('Τίτλος και περιεχόμενο είναι υποχρεωτικά.', 'danger');
            redirect(APP_URL.'/admin/marketing-popup.php');
        }
        if ($notifyEmail !== '' && !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
            flash('Μη έγκυρο email παραλήπτη.', 'danger');
            redirect(APP_URL.'/admin/marketing-popup.php');
        }

        $startsAtDb = $startsAt !== '' ? date('Y-m-d H:i:s', strtotime($startsAt)) : null;
        $endsAtDb   = $endsAt   !== '' ? date('Y-m-d H:i:s', strtotime($endsAt))   : null;

        if ($id > 0) {
            $stmt = $db->prepare("UPDATE marketing_popups
                SET title=?, body_html=?, cta_label=?, dismiss_label=?, icon=?,
                    notify_email=?, enabled=?, audience=?, starts_at=?, ends_at=?
              WHERE id=?");
            $stmt->execute([$title, $body, $ctaLabel, $dismissLabel, $icon,
                $notifyEmail ?: null, $enabled, $audience, $startsAtDb, $endsAtDb, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO marketing_popups
                (title, body_html, cta_label, dismiss_label, icon, notify_email, enabled, audience, starts_at, ends_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $body, $ctaLabel, $dismissLabel, $icon,
                $notifyEmail ?: null, $enabled, $audience, $startsAtDb, $endsAtDb]);
            $id = (int)$db->lastInsertId();
        }
        flash('Το popup αποθηκεύτηκε.', 'success');
        redirect(APP_URL.'/admin/marketing-popup.php?id=' . $id);
    }

    if ($act === 'reset_seen') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM marketing_popup_actions WHERE popup_id=?")->execute([$id]);
            flash('Οι εμφανίσεις μηδενίστηκαν — το popup θα ξαναφανεί σε όλους.', 'success');
        }
        redirect(APP_URL.'/admin/marketing-popup.php?id=' . $id);
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM marketing_popups WHERE id=?")->execute([$id]);
            flash('Το popup διαγράφηκε.', 'success');
        }
        redirect(APP_URL.'/admin/marketing-popup.php');
    }
}

// ── Load list + selected popup ──────────────────────────────
$popups = $db->query("SELECT * FROM marketing_popups ORDER BY updated_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$selectedId = (int)($_GET['id'] ?? ($popups[0]['id'] ?? 0));
$current    = null;
foreach ($popups as $p) if ((int)$p['id'] === $selectedId) { $current = $p; break; }

$leads = [];
if ($current) {
    $leadsStmt = $db->prepare("
        SELECT a.*, u.name AS live_name, u.email AS live_email, s.name AS school_name
          FROM marketing_popup_actions a
          LEFT JOIN users u ON u.id = a.user_id
          LEFT JOIN schools s ON s.id = a.school_id
         WHERE a.popup_id = ? AND a.action='interested'
         ORDER BY a.created_at DESC
         LIMIT 200
    ");
    $leadsStmt->execute([$current['id']]);
    $leads = $leadsStmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $db->prepare("
        SELECT
          SUM(action='interested') AS interested_cnt,
          SUM(action='dismissed')  AS dismissed_cnt,
          COUNT(*)                 AS total_cnt
          FROM marketing_popup_actions WHERE popup_id=?
    ");
    $countStmt->execute([$current['id']]);
    $stats = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$defaultNotify = function_exists('getSetting')
    ? getSetting('marketing_popup_notify_email', getMailFromEmail())
    : getMailFromEmail();
$csrf = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Marketing Popup — MAster Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  body { font-family: 'Inter','DM Sans',system-ui,sans-serif; }
  .page-wrap { max-width: 1120px; margin: 0 auto; padding: 1.5rem; }
  .grid { display: grid; grid-template-columns: 1fr 320px; gap: 1.25rem; }
  @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
  .card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.25rem 1.4rem; box-shadow:0 1px 2px rgba(15,23,42,.04); }
  .card h3 { margin:0 0 .8rem; font-size:1.05rem; font-weight:800; color:#0f172a; }
  label { display:block; font-weight:700; font-size:.85rem; color:#334155; margin:.8rem 0 .35rem; }
  input[type=text], input[type=email], input[type=datetime-local], textarea, select {
    width:100%; padding:.6rem .8rem; border:1px solid #cbd5e1; border-radius:8px;
    font-size:.95rem; font-family:inherit; background:#fff; color:#0f172a;
  }
  textarea { min-height:150px; resize:vertical; }
  input:focus, textarea:focus, select:focus {
    outline:none; border-color:#e63946; box-shadow:0 0 0 3px rgba(230,57,70,.14);
  }
  .row { display:flex; gap:.75rem; }
  .row > * { flex:1; }
  .switch { display:flex; align-items:center; gap:.6rem; margin-top:.3rem; }
  .switch input { width:auto; }
  .btn { padding:.55rem 1.15rem; border-radius:10px; font-weight:700; font-size:.9rem; border:none; cursor:pointer; }
  .btn-primary { background:#e63946; color:#fff; }
  .btn-primary:hover { background:#dc2836; }
  .btn-ghost { background:#fff; color:#0f172a; border:1px solid #cbd5e1; }
  .btn-ghost:hover { background:#f8fafc; }
  .btn-danger { background:#fff; color:#b91c1c; border:1px solid #fecaca; }
  .btn-danger:hover { background:#fef2f2; }
  .stat { padding:.85rem 1rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; }
  .stat .num { font-size:1.4rem; font-weight:800; color:#0f172a; }
  .stat .lbl { font-size:.78rem; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
  table { width:100%; border-collapse:collapse; margin-top:.5rem; font-size:.9rem; }
  th { text-align:left; font-weight:700; color:#334155; background:#f8fafc; padding:.5rem .7rem; border-bottom:1px solid #e2e8f0; }
  td { padding:.55rem .7rem; border-bottom:1px solid #f1f5f9; color:#0f172a; }
  .pill { display:inline-block; padding:.15rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; }
  .pill-on  { background:#dcfce7; color:#166534; }
  .pill-off { background:#f1f5f9; color:#475569; }
  .picker { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:.75rem; }
  .picker a { padding:.35rem .7rem; border-radius:8px; border:1px solid #e2e8f0; text-decoration:none; color:#0f172a; font-size:.85rem; }
  .picker a.active { background:#e63946; color:#fff; border-color:#e63946; }
  .flash { padding:.7rem 1rem; border-radius:10px; margin-bottom:1rem; font-size:.9rem; }
  .flash.success { background:#f0fdf4; border:1px solid #bbf7d0; color:#14532d; }
  .flash.danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
  .hint { font-size:.8rem; color:#64748b; margin-top:.3rem; }
</style>
</head>
<body>
<?php renderSidebar('admin_marketing_popup'); ?>
<div class="main-content" id="mainContent">
<?php renderTopbar('<i class="fa-solid fa-bullhorn"></i> Marketing Popup'); ?>

<div class="page-wrap">

  <?php if ($flash = getFlash()): ?>
    <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
  <?php endif; ?>

  <?php if (!empty($popups)): ?>
    <div class="picker">
      <?php foreach ($popups as $p): ?>
        <a href="?id=<?= (int)$p['id'] ?>" class="<?= (int)$p['id'] === $selectedId ? 'active' : '' ?>">
          <?= htmlspecialchars(mb_strimwidth($p['title'], 0, 40, '…')) ?>
          <?= $p['enabled'] ? '<span class="pill pill-on" style="margin-left:.3rem">ON</span>' : '' ?>
        </a>
      <?php endforeach; ?>
      <a href="?id=0" class="<?= $selectedId === 0 ? 'active' : '' ?>">+ Νέο popup</a>
    </div>
  <?php endif; ?>

  <div class="grid">
    <form method="post" class="card">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="id" value="<?= (int)($current['id'] ?? 0) ?>">

      <h3><i class="fa-solid fa-pen"></i> <?= $current ? 'Επεξεργασία' : 'Νέο' ?> popup</h3>

      <label>Τίτλος</label>
      <input type="text" name="title" required maxlength="180"
             value="<?= htmlspecialchars($current['title'] ?? 'Δωρεάν επαγγελματική ιστοσελίδα για τη σχολή σας') ?>">

      <label>Περιεχόμενο (HTML)</label>
      <textarea name="body_html" required><?= htmlspecialchars($current['body_html'] ?? '<p>Θέλετε μια σύγχρονη, mobile-first ιστοσελίδα για τη σχολή σας — <strong>χωρίς κόστος</strong>;</p><p>Πατήστε <strong>Ενδιαφέρομαι</strong> για να επικοινωνήσουμε μαζί σας.</p>') ?></textarea>
      <div class="hint">Επιτρέπονται &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;ul&gt;/&lt;li&gt;.</div>

      <div class="row">
        <div>
          <label>Κείμενο CTA</label>
          <input type="text" name="cta_label" maxlength="60"
                 value="<?= htmlspecialchars($current['cta_label'] ?? 'Ενδιαφέρομαι') ?>">
        </div>
        <div>
          <label>Κείμενο απόρριψης</label>
          <input type="text" name="dismiss_label" maxlength="60"
                 value="<?= htmlspecialchars($current['dismiss_label'] ?? 'Αργότερα') ?>">
        </div>
      </div>

      <div class="row">
        <div>
          <label>Font-Awesome εικονίδιο</label>
          <input type="text" name="icon" maxlength="32"
                 value="<?= htmlspecialchars($current['icon'] ?? 'fa-solid fa-globe') ?>">
          <div class="hint">π.χ. <code>fa-solid fa-globe</code>, <code>fa-solid fa-bullhorn</code>, <code>fa-solid fa-gift</code>.</div>
        </div>
        <div>
          <label>Ακροατήριο</label>
          <select name="audience">
            <?php foreach ([
              'all'         => 'Όλοι οι χρήστες',
              'club_admins' => 'Μόνο σχολές (owners/admins)',
              'parents'     => 'Μόνο γονείς',
              'employees'   => 'Μόνο εργαζόμενοι',
            ] as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($current['audience'] ?? 'all') === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row">
        <div>
          <label>Έναρξη (προαιρετικό)</label>
          <input type="datetime-local" name="starts_at"
                 value="<?= !empty($current['starts_at']) ? date('Y-m-d\TH:i', strtotime($current['starts_at'])) : '' ?>">
        </div>
        <div>
          <label>Λήξη (προαιρετικό)</label>
          <input type="datetime-local" name="ends_at"
                 value="<?= !empty($current['ends_at']) ? date('Y-m-d\TH:i', strtotime($current['ends_at'])) : '' ?>">
        </div>
      </div>

      <label>Email για ειδοποίηση Brevo (όταν πατηθεί «<?= htmlspecialchars($current['cta_label'] ?? 'Ενδιαφέρομαι') ?>»)</label>
      <input type="email" name="notify_email"
             value="<?= htmlspecialchars($current['notify_email'] ?? '') ?>"
             placeholder="<?= htmlspecialchars($defaultNotify) ?>">
      <div class="hint">Αν αφεθεί κενό, χρησιμοποιείται η προεπιλογή <code><?= htmlspecialchars($defaultNotify) ?></code>.</div>

      <div class="switch">
        <label style="margin:0;display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" name="enabled" value="1" <?= !empty($current['enabled']) ? 'checked' : '' ?>>
          <span><strong>Ενεργό</strong> — να εμφανίζεται σε χρήστες που δεν το έχουν δει</span>
        </label>
      </div>

      <div style="display:flex;gap:.6rem;margin-top:1.2rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
        <?php if ($current): ?>
          <button type="submit" class="btn btn-ghost" formaction="?" formmethod="post"
                  onclick="this.form._action.value='reset_seen';return confirm('Το popup θα ξαναφανεί σε όλους τους χρήστες. Συνέχεια;')">
            <i class="fa-solid fa-rotate-right"></i> Επαναφορά εμφανίσεων
          </button>
          <button type="submit" class="btn btn-danger"
                  onclick="this.form._action.value='delete';return confirm('Διαγραφή popup και όλων των συλλεγμένων ενεργειών;')">
            <i class="fa-solid fa-trash"></i> Διαγραφή
          </button>
        <?php endif; ?>
      </div>
    </form>

    <aside>
      <?php if ($current): ?>
        <div class="card" style="margin-bottom:1rem">
          <h3><i class="fa-solid fa-chart-simple"></i> Στατιστικά</h3>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
            <div class="stat"><div class="num" style="color:#16a34a"><?= (int)($stats['interested_cnt'] ?? 0) ?></div><div class="lbl">Interested</div></div>
            <div class="stat"><div class="num" style="color:#64748b"><?= (int)($stats['dismissed_cnt'] ?? 0) ?></div><div class="lbl">Dismissed</div></div>
          </div>
          <div class="stat" style="margin-top:.6rem"><div class="num"><?= (int)($stats['total_cnt'] ?? 0) ?></div><div class="lbl">Σύνολο εμφανίσεων</div></div>
        </div>
        <div class="card">
          <h3><i class="fa-solid fa-circle-info"></i> Οδηγίες</h3>
          <p style="font-size:.85rem;color:#475569;line-height:1.55;margin:0">
            Το popup εμφανίζεται <strong>μία φορά</strong> σε κάθε χρήστη. Είτε πατήσει
            «<?= htmlspecialchars($current['cta_label']) ?>» είτε «<?= htmlspecialchars($current['dismiss_label']) ?>»,
            καταγράφεται και δεν εμφανίζεται ξανά. Χρησιμοποιήστε
            «Επαναφορά εμφανίσεων» για νέα καμπάνια.
          </p>
        </div>
      <?php endif; ?>
    </aside>
  </div>

  <?php if ($current && !empty($leads)): ?>
    <div class="card" style="margin-top:1.25rem">
      <h3><i class="fa-solid fa-address-book"></i> Ενδιαφερόμενοι (<?= count($leads) ?>)</h3>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Ημ/νία</th>
              <th>Όνομα</th>
              <th>Email</th>
              <th>Τηλέφωνο</th>
              <th>Σχολή</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($leads as $l): ?>
              <tr>
                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($l['created_at']))) ?></td>
                <td><?= htmlspecialchars($l['live_name'] ?? $l['user_name'] ?? '—') ?></td>
                <td><a href="mailto:<?= htmlspecialchars($l['live_email'] ?? $l['user_email'] ?? '') ?>"><?= htmlspecialchars($l['live_email'] ?? $l['user_email'] ?? '—') ?></a></td>
                <td><?= htmlspecialchars($l['user_phone'] ?? '—') ?></td>
                <td><?= htmlspecialchars($l['school_name'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>
</div>
</body>
</html>
