<?php
/**
 * admin/school_approvals.php — Superadmin approval queue for clubs/schools.
 *
 * All existing schools are grandfathered to 'approved' by
 * migration 006. Admins use this page to move a school between
 * pending / approved / rejected / suspended and to see the
 * audit trail per school.
 */

require_once __DIR__ . '/../includes/config.php';
requireSuperAdmin();

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $sid       = (int)($_POST['school_id']  ?? 0);
    $newStatus = trim($_POST['new_status']  ?? '');
    $reason    = trim($_POST['reason']      ?? '');
    try {
        if ($sid <= 0) throw new RuntimeException('Missing school.');
        schoolSetApproval($sid, $newStatus, $reason);
        $flash = ['type' => 'success', 'msg' => 'Η κατάσταση ενημερώθηκε.'];
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

$filter = $_GET['status'] ?? 'pending';
$allowedFilters = ['pending','approved','rejected','suspended','all'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'pending';

$db = getDB();
$where = ($filter === 'all') ? '1=1' : 'approval_status = ' . $db->quote($filter);
$schools = $db->query("SELECT id, name, email, plan_status, approval_status, approved_at, created_at
                         FROM schools
                        WHERE $where
                        ORDER BY (approval_status = 'pending') DESC, created_at DESC
                        LIMIT 300")->fetchAll();

$counts = [];
foreach (['pending','approved','rejected','suspended'] as $s) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM schools WHERE approval_status = ?');
    $stmt->execute([$s]);
    $counts[$s] = (int)$stmt->fetchColumn();
}
?><!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Έγκριση Σχολών · Admin — MAster</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="shortcut icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#07090f;color:#f0f2ff;min-height:100vh}
a{color:inherit;text-decoration:none}
.top{position:sticky;top:0;background:rgba(7,9,15,.9);backdrop-filter:blur(10px);border-bottom:1px solid #1e2536;padding:1rem 1.25rem;z-index:10}
.top-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.top-title{font-size:1.05rem;font-weight:800;display:flex;align-items:center;gap:.6rem}
.top-title i{color:#e63946}
.top-back{padding:.5rem .85rem;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);font-size:.85rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem}
.wrap{max-width:1200px;margin:0 auto;padding:1.5rem 1.25rem 3rem}
h1{font-size:clamp(1.4rem,3vw,1.9rem);font-weight:800;letter-spacing:-.02em;margin-bottom:.35rem}
.lead{color:#8892b0;margin-bottom:1.25rem;font-size:.95rem}
.filters{display:flex;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap}
.filters a{padding:.55rem .9rem;border-radius:8px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);font-size:.85rem;font-weight:600;color:#c9cee1;display:inline-flex;align-items:center;gap:.4rem}
.filters a.active{background:linear-gradient(135deg,#e63946,#c72832);border-color:transparent;color:#fff}
.filters .badge{background:rgba(255,255,255,.1);padding:.1rem .5rem;border-radius:99px;font-size:.72rem;font-weight:700}
.flash{padding:.85rem 1.1rem;border-radius:10px;margin-bottom:1rem;font-weight:600}
.flash.success{background:linear-gradient(180deg,rgba(45,198,83,.12),rgba(45,198,83,.06));border:1px solid rgba(45,198,83,.28);color:#d5ffd8}
.flash.error  {background:linear-gradient(180deg,rgba(230,57,70,.12),rgba(230,57,70,.06));border:1px solid rgba(230,57,70,.28);color:#ffe6e8}
.table-wrap{background:linear-gradient(180deg,rgba(19,23,34,.7),rgba(13,16,23,.7));border:1px solid rgba(255,255,255,.06);border-radius:14px;overflow:auto}
table{width:100%;border-collapse:collapse;min-width:780px}
th,td{padding:.85rem 1rem;text-align:left;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle;font-size:.9rem}
th{background:rgba(255,255,255,.03);color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.02)}
.school{font-weight:700}
.muted{color:#6b7494;font-size:.78rem}
.badge-status{padding:.2rem .55rem;border-radius:99px;font-size:.7rem;font-weight:700}
.b-pending  {background:rgba(240,165,0,.15);color:#ffd870;border:1px solid rgba(240,165,0,.3)}
.b-approved {background:rgba(45,198,83,.15);color:#7bffb4;border:1px solid rgba(45,198,83,.3)}
.b-rejected {background:rgba(230,57,70,.15);color:#ffb0b8;border:1px solid rgba(230,57,70,.3)}
.b-suspended{background:rgba(155,110,255,.15);color:#e6d5ff;border:1px solid rgba(155,110,255,.3)}
.actions{display:flex;flex-wrap:wrap;gap:.35rem}
.btn{padding:.4rem .7rem;border-radius:7px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:#f0f2ff;font-family:inherit;font-size:.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem}
.btn.approve{background:rgba(45,198,83,.15);border-color:rgba(45,198,83,.35);color:#a9ffcb}
.btn.reject {background:rgba(230,57,70,.15);border-color:rgba(230,57,70,.35);color:#ffb0b8}
.btn.suspend{background:rgba(155,110,255,.15);border-color:rgba(155,110,255,.35);color:#e6d5ff}
.btn.pending{background:rgba(240,165,0,.15);border-color:rgba(240,165,0,.35);color:#ffd870}
.empty{padding:2.5rem 1rem;text-align:center;color:#6b7494}
</style>
</head>
<body>

<div class="top">
  <div class="top-inner">
    <div class="top-title"><i class="fa-solid fa-user-check"></i> Έγκριση Σχολών</div>
    <a class="top-back" href="<?= APP_URL ?>/admin/"><i class="fa-solid fa-arrow-left"></i> Admin</a>
  </div>
</div>

<div class="wrap">
  <h1>Ουρά εγκρίσεων</h1>
  <p class="lead">Χρησιμοποιήστε αυτό το tab μόνο αν θέλετε χειροκίνητη έγκριση νέων συλλόγων. Οι υπάρχουσες σχολές είναι ήδη σε κατάσταση <em>approved</em> και δεν επηρεάζονται.</p>

  <?php if ($flash): ?>
    <div class="flash <?= h($flash['type']) ?>"><i class="fa-solid fa-<?= $flash['type'] === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i> <?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="filters">
    <?php foreach (['pending','approved','rejected','suspended','all'] as $f):
      $labels = ['pending'=>'Εκκρεμείς','approved'=>'Εγκεκριμένες','rejected'=>'Απορριφθείσες','suspended'=>'Ανασταλμένες','all'=>'Όλες'];
      $badge = $f === 'all' ? array_sum($counts) : ($counts[$f] ?? 0);
    ?>
      <a href="?status=<?= $f ?>" class="<?= $filter === $f ? 'active' : '' ?>">
        <?= h($labels[$f]) ?> <span class="badge"><?= (int)$badge ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="table-wrap">
    <?php if (!$schools): ?>
      <div class="empty">
        <div style="font-size:2.5rem;color:#4a5270;margin-bottom:.5rem"><i class="fa-regular fa-face-smile"></i></div>
        Καμία σχολή σε αυτή την κατάσταση.
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Σχολή</th>
            <th>Email</th>
            <th>Πλάνο</th>
            <th>Approval</th>
            <th>Εγγραφή</th>
            <th style="min-width:280px">Ενέργειες</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($schools as $s):
            $cls = 'b-' . $s['approval_status'];
          ?>
            <tr>
              <td class="school">
                <?= h($s['name']) ?>
                <div class="muted">#<?= (int)$s['id'] ?></div>
              </td>
              <td><?= h($s['email'] ?? '—') ?></td>
              <td><?= h($s['plan_status']) ?></td>
              <td><span class="badge-status <?= $cls ?>"><?= h($s['approval_status']) ?></span></td>
              <td class="muted"><?= h($s['created_at'] ? date('d/m/Y', strtotime($s['created_at'])) : '—') ?></td>
              <td class="actions">
                <?php foreach (['approved'=>'approve','pending'=>'pending','rejected'=>'reject','suspended'=>'suspend'] as $t => $cls2):
                  if ($s['approval_status'] === $t) continue;
                ?>
                  <form method="POST" onsubmit="return confirm('Αλλαγή σε <?= $t ?>;')">
                    <?= csrfField() ?>
                    <input type="hidden" name="school_id" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="new_status" value="<?= $t ?>">
                    <button class="btn <?= $cls2 ?>" type="submit"><?= h($t) ?></button>
                  </form>
                <?php endforeach; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
