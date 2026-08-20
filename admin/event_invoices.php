<?php
/**
 * admin/event_invoices.php — Superadmin invoice upload for event payments
 * ============================================================
 * Lists every VERIFIED event_payment and lets the admin upload a
 * PDF invoice for it. Once uploaded, the corresponding school can
 * download it from /pages/event_invoices.php.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events.php';
requireSuperAdmin();

$flash = null;

// ── POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action    = $_POST['action']     ?? '';
    $paymentId = (int)($_POST['payment_id'] ?? 0);

    try {
        if ($action === 'upload' && $paymentId > 0) {
            if (empty($_FILES['invoice_pdf']['tmp_name'])) {
                throw new RuntimeException('Δεν επιλέχθηκε αρχείο.');
            }
            eventPaymentUploadInvoice(
                $paymentId,
                $_FILES['invoice_pdf']['tmp_name'],
                $_FILES['invoice_pdf']['name'] ?? '',
                userId()
            );
            $flash = ['type' => 'success', 'msg' => 'Το τιμολόγιο ανέβηκε επιτυχώς.'];
        } elseif ($action === 'remove' && $paymentId > 0) {
            eventPaymentRemoveInvoice($paymentId);
            $flash = ['type' => 'success', 'msg' => 'Το τιμολόγιο αφαιρέθηκε.'];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

$filter    = $_GET['filter'] ?? 'pending';
$pendingOnly = ($filter === 'pending');
$payments  = eventPaymentsForAdmin($pendingOnly);
$totalPending = count(eventPaymentsForAdmin(true));
$totalAll     = count(eventPaymentsForAdmin(false));
?><!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Τιμολόγια Διοργανώσεων · Admin — MAster</title>
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
  table{width:100%;border-collapse:collapse;min-width:820px}
  th,td{padding:.85rem 1rem;text-align:left;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle;font-size:.9rem}
  th{background:rgba(255,255,255,.03);color:#8892b0;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em}
  tr:last-child td{border-bottom:none}
  tr:hover td{background:rgba(255,255,255,.02)}

  .amount{font-weight:800;font-variant-numeric:tabular-nums}
  .school{font-weight:700}
  .event-title{color:#c9cee1;font-size:.85rem}
  .muted{color:#6b7494;font-size:.78rem}

  .badge-status{padding:.2rem .55rem;border-radius:99px;font-size:.7rem;font-weight:700}
  .b-verified{background:rgba(45,198,83,.15);color:#7bffb4;border:1px solid rgba(45,198,83,.3)}
  .b-invoice-ok{background:rgba(78,132,255,.15);color:#a9c1ff;border:1px solid rgba(78,132,255,.3)}
  .b-missing{background:rgba(240,165,0,.15);color:#ffd870;border:1px solid rgba(240,165,0,.3)}

  .actions form{display:inline-block}
  .btn{padding:.4rem .75rem;border-radius:7px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:#f0f2ff;font-family:inherit;font-size:.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem}
  .btn:hover{border-color:rgba(230,57,70,.4)}
  .btn-primary{background:linear-gradient(135deg,#e63946,#c72832);border-color:transparent;color:#fff}
  .btn-danger{background:rgba(230,57,70,.15);border-color:rgba(230,57,70,.35);color:#ffb0b8}
  .btn-download{background:rgba(78,132,255,.15);border-color:rgba(78,132,255,.35);color:#a9c1ff}

  .upload-form{display:flex;gap:.35rem;align-items:center}
  .upload-form input[type=file]{max-width:150px;font-size:.7rem;color:#c9cee1}

  .empty{padding:2.5rem 1rem;text-align:center;color:#6b7494}

  @media (max-width:820px){
    th,td{padding:.6rem .55rem;font-size:.78rem}
    .btn{font-size:.7rem;padding:.35rem .55rem}
  }
</style>
</head>
<body>

<div class="top">
  <div class="top-inner">
    <div class="top-title"><i class="fa-solid fa-file-invoice"></i> Τιμολόγια Διοργανώσεων</div>
    <a class="top-back" href="<?= APP_URL ?>/admin/"><i class="fa-solid fa-arrow-left"></i> Admin</a>
  </div>
</div>

<div class="wrap">
  <h1>Ανέβασμα τιμολογίων</h1>
  <p class="lead">Για κάθε επιβεβαιωμένη πληρωμή event ανέβασε το αντίστοιχο PDF τιμολόγιο. Η σχολή θα μπορεί να το κατεβάσει από το δικό της tab.</p>

  <?php if ($flash): ?>
    <div class="flash <?= h($flash['type']) ?>"><i class="fa-solid fa-<?= $flash['type'] === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i> <?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="filters">
    <a href="?filter=pending" class="<?= $pendingOnly ? 'active' : '' ?>">
      <i class="fa-regular fa-clock"></i> Εκκρεμείς <span class="badge"><?= (int)$totalPending ?></span>
    </a>
    <a href="?filter=all" class="<?= !$pendingOnly ? 'active' : '' ?>">
      <i class="fa-solid fa-list"></i> Όλες <span class="badge"><?= (int)$totalAll ?></span>
    </a>
  </div>

  <div class="table-wrap">
    <?php if (!$payments): ?>
      <div class="empty">
        <div style="font-size:2.5rem;color:#4a5270;margin-bottom:.6rem"><i class="fa-solid fa-check-double"></i></div>
        <?php if ($pendingOnly): ?>
          <strong>Καμία εκκρεμής πληρωμή χωρίς τιμολόγιο.</strong>
        <?php else: ?>
          Δεν υπάρχουν επιβεβαιωμένες πληρωμές ακόμα.
        <?php endif; ?>
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Πληρωμή</th>
            <th>Σχολή</th>
            <th>Event</th>
            <th>Ποσό</th>
            <th>Κατάσταση</th>
            <th style="min-width:250px">Τιμολόγιο</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p):
            $hasInvoice = !empty($p['invoice_file_path']);
          ?>
            <tr>
              <td>
                #<?= (int)$p['id'] ?>
                <div class="muted">Επιβεβ.: <?= h($p['verified_at'] ? date('d/m/Y', strtotime($p['verified_at'])) : '—') ?></div>
              </td>
              <td class="school"><?= h($p['school_name'] ?? '—') ?></td>
              <td>
                <div class="event-title"><?= h($p['event_title']) ?></div>
                <a class="muted" href="<?= APP_URL ?>/events/<?= h($p['event_slug']) ?>" target="_blank" rel="noopener">
                  <i class="fa-solid fa-arrow-up-right-from-square"></i> Άνοιγμα
                </a>
              </td>
              <td class="amount"><?= number_format((float)$p['amount'], 2, ',', '.') ?> €</td>
              <td>
                <span class="badge-status b-verified"><i class="fa-solid fa-check"></i> Επιβεβαιωμένη</span>
                <?php if ($hasInvoice): ?>
                  <div style="margin-top:.35rem"><span class="badge-status b-invoice-ok"><i class="fa-regular fa-file-pdf"></i> Έχει τιμολόγιο</span></div>
                <?php else: ?>
                  <div style="margin-top:.35rem"><span class="badge-status b-missing"><i class="fa-regular fa-hourglass"></i> Εκκρεμεί</span></div>
                <?php endif; ?>
              </td>
              <td class="actions">
                <?php if ($hasInvoice): ?>
                  <a class="btn btn-download" href="<?= APP_URL ?>/<?= h($p['invoice_file_path']) ?>" target="_blank" rel="noopener">
                    <i class="fa-regular fa-eye"></i> Προβολή
                  </a>
                  <form method="POST" onsubmit="return confirm('Αφαίρεση του τιμολογίου;')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i></button>
                  </form>
                <?php else: ?>
                  <form method="POST" enctype="multipart/form-data" class="upload-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                    <input type="file" name="invoice_pdf" accept="application/pdf" required>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Ανέβασμα</button>
                  </form>
                <?php endif; ?>
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
