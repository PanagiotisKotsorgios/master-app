<?php
/**
 * events/certificate.php — Printable participation / medal certificate
 * URL: /events/certificate.php?reg=REGISTRATION_ID
 * Public link — anyone with the ID can print (used for social sharing).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/events_bracket.php';

$regId = (int)($_GET['reg'] ?? 0);
if (!$regId) { http_response_code(400); exit('Bad request'); }

$db = getDB();
$st = $db->prepare("
    SELECT r.*, e.title AS event_title, e.slug AS event_slug, e.starts_at, e.venue_name,
           c.name AS cat_name,
           a.full_name AS athlete_name,
           s.name AS school_name,
           res.place, res.medal
    FROM event_registrations r
    JOIN events e ON e.id = r.event_id
    LEFT JOIN event_categories c ON c.id = r.category_id
    LEFT JOIN athletes a ON a.id = r.athlete_id
    LEFT JOIN schools s ON s.id = r.registering_school_id
    LEFT JOIN event_results res ON res.registration_id = r.id
    WHERE r.id = ? LIMIT 1
");
$st->execute([$regId]);
$reg = $st->fetch();
if (!$reg) { http_response_code(404); exit('Not found'); }

$medalEmoji = ['gold'=>'🥇','silver'=>'🥈','bronze'=>'🥉'][$reg['medal'] ?? ''] ?? '';
$medalText  = ['gold'=>'Χρυσό μετάλλιο','silver'=>'Ασημένιο μετάλλιο','bronze'=>'Χάλκινο μετάλλιο'][$reg['medal'] ?? ''] ?? '';
$isMedal    = !empty($reg['medal']) && $reg['medal'] !== 'none';
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Πιστοποιητικό Συμμετοχής — <?= h($reg['athlete_name'] ?? '') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;600;700;800&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
@page{size:A4 landscape;margin:0}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#1a1a2e;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;color:#000}
.cert{width:297mm;height:210mm;background:linear-gradient(135deg,#fdfbf7,#fff);position:relative;box-shadow:0 20px 60px rgba(0,0,0,.5);padding:20mm;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden}
.cert::before{content:'';position:absolute;top:0;left:0;right:0;height:10mm;background:linear-gradient(90deg,#e63946,#8b0d1a)}
.cert::after{content:'';position:absolute;bottom:0;left:0;right:0;height:10mm;background:linear-gradient(90deg,#8b0d1a,#e63946)}
.corner{position:absolute;width:60mm;height:60mm;border:6px solid #e63946;opacity:.15}
.corner.tl{top:15mm;left:15mm;border-right:none;border-bottom:none}
.corner.br{bottom:15mm;right:15mm;border-left:none;border-top:none}
.header{text-align:center;position:relative;z-index:1}
.badge{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:.4em;color:#e63946;margin-bottom:.5rem}
h1{font-family:'Playfair Display',serif;font-size:3.4rem;color:#0d0d1a;line-height:1.1;margin-bottom:.5rem}
.subline{color:#666;font-size:1.1rem;font-style:italic}
.body{text-align:center;padding:1rem 0;position:relative;z-index:1}
.athlete{font-family:'Playfair Display',serif;font-size:3rem;color:#e63946;font-weight:900;margin:1rem 0;padding:.5rem 2rem;border-bottom:3px solid #e63946;display:inline-block}
.detail-line{font-size:1.15rem;color:#333;line-height:1.9;margin-top:1rem}
.detail-line strong{color:#0d0d1a}
.medal{font-size:4rem;margin:1rem 0;line-height:1}
.medal-text{font-family:'Bebas Neue',sans-serif;font-size:1.8rem;letter-spacing:.1em;color:#f0a500;margin-top:.35rem}
.footer{display:flex;justify-content:space-between;align-items:flex-end;padding-top:1rem;position:relative;z-index:1}
.event-info{text-align:left}
.event-info .lbl{font-size:.75rem;text-transform:uppercase;letter-spacing:.1em;color:#666;font-weight:700}
.event-info .val{font-size:1rem;color:#0d0d1a;font-weight:700;margin-top:.25rem}
.brand{text-align:right;font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:.15em;color:#e63946}
.brand em{font-style:normal;color:#0d0d1a}
.print-btn{position:fixed;top:1rem;right:1rem;padding:.75rem 1.5rem;background:#e63946;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:800;font-family:inherit;font-size:1rem;box-shadow:0 4px 20px rgba(230,57,70,.5)}
@media print{body{background:#fff;padding:0}.cert{box-shadow:none;margin:0}.print-btn{display:none}}
</style>
<?php include __DIR__ . "/../includes/prelogin_polish.php"; ?>
</head>
<body>

<button class="print-btn" onclick="window.print()"><i>🖨️</i> Εκτύπωση / PDF</button>

<div class="cert">
  <div class="corner tl"></div>
  <div class="corner br"></div>

  <div class="header">
    <div class="badge">CERTIFICATE OF <?= $isMedal ? 'ACHIEVEMENT' : 'PARTICIPATION' ?></div>
    <h1><?= $isMedal ? 'Πιστοποιητικό Διάκρισης' : 'Πιστοποιητικό Συμμετοχής' ?></h1>
    <div class="subline">Απονέμεται στον/στην αθλητή/-τρια</div>
  </div>

  <div class="body">
    <div class="athlete"><?= h($reg['athlete_name'] ?? '—') ?></div>

    <div class="detail-line">
      του συλλόγου <strong><?= h($reg['school_name'] ?? '—') ?></strong>,<br>
      για τη συμμετοχή στην κατηγορία <strong><?= h($reg['cat_name'] ?? '—') ?></strong>
    </div>

    <?php if ($isMedal): ?>
      <div class="medal"><?= $medalEmoji ?></div>
      <div class="medal-text"><?= h($medalText) ?> · <?= (int)$reg['place'] ?>η θέση</div>
    <?php endif; ?>
  </div>

  <div class="footer">
    <div class="event-info">
      <div class="lbl">Διοργάνωση</div>
      <div class="val"><?= h($reg['event_title']) ?></div>
      <div class="lbl" style="margin-top:.75rem">Ημερομηνία / Τοποθεσία</div>
      <div class="val"><?= h(formatDate(substr($reg['starts_at']??'',0,10))) ?><?php if ($reg['venue_name']): ?> · <?= h($reg['venue_name']) ?><?php endif; ?></div>
    </div>
    <div class="brand">
      MA<em>ster</em><br>
      <span style="font-size:.7rem;letter-spacing:.05em;color:#999">master-app.gr</span>
    </div>
  </div>
</div>

</body>
</html>
