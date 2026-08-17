<?php

/**
 * ============================================================
 * pages/competitions.php — Αγωνιστικά (Pro)
 * ============================================================
 * PURPOSE:
 *   Διαχείριση αγώνων και συμμετοχών αθλητών.
 *   Απαιτεί Pro plan. (Κρυφό από sidebar — δεν εμφανίζεται)
 *
 * SECURITY:
 *   ✓ requireLogin() + planHas('competitions_enabled')
 *   ✓ verifyCsrf() σε POST
 *   ✓ Ownership verification
 *   ✓ Prepared statements
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin(); renderPaymentWall();
// Αυτή η λειτουργία απαιτεί Pro πλάνο → ανακατεύθυνση αν δεν έχει
if (!planHas('competitions_enabled')) { flash('Τα Αγωνιστικά απαιτούν Pro πλάνο.','danger'); redirect(APP_URL.'/pages/upgrade.php'); }

$db = getDB(); $sid = schoolId();
$action = $_GET['action'] ?? '';
$compId = (int)($_GET['id'] ?? 0);

// ══════════════════════════════════════════
// ΧΕΙΡΙΣΜΟΣ POST ΑΙΤΗΜΑΤΩΝ
// save_comp: δημιουργία/ενημέρωση αγωνιστικού
// save_participant: αποτέλεσμα αθλητή σε αγωνιστικό
// ══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST;
    if ($a['_action'] === 'save_comp') {
        $id = (int)($a['id']??0);
        if ($id) {
            $db->prepare("UPDATE competitions SET name=?,comp_date=?,location=?,cost=?,comp_type=?,notes=? WHERE id=? AND school_id=?")->execute([$a['name'],$a['comp_date'],$a['location'],$a['cost']??0,$a['comp_type']??'',$a['notes'],$id,$sid]);
        } else {
            $db->prepare("INSERT INTO competitions (school_id,name,comp_date,location,cost,comp_type,notes) VALUES (?,?,?,?,?,?,?)")->execute([$sid,$a['name'],$a['comp_date'],$a['location'],$a['cost']??0,$a['comp_type']??'',$a['notes']]);
        }
        flash('Αγωνιστικό αποθηκεύτηκε!');
    }
    if ($a['_action'] === 'delete_comp') {
        $id = (int)($a['id']??0);
        $db->prepare("DELETE FROM competition_participants WHERE competition_id=?")->execute([$id]);
        $db->prepare("DELETE FROM competitions WHERE id=? AND school_id=?")->execute([$id,$sid]);
        flash('Διοργάνωση διαγράφηκε.','danger');
    }
    if ($a['_action'] === 'save_participant') {
        $cid=(int)$a['competition_id']; $aid=(int)$a['athlete_id'];
        $db->prepare("INSERT INTO competition_participants (competition_id,athlete_id,weight_category,result,medal,points) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE weight_category=?,result=?,medal=?,points=?")->execute([$cid,$aid,$a['weight_category'],$a['result'],$a['medal'],$a['points']??0,$a['weight_category'],$a['result'],$a['medal'],$a['points']??0]);
        flash('Αποτέλεσμα αποθηκεύτηκε!');
    }
    redirect(APP_URL.'/pages/competitions.php'.($compId?"?id=$compId":''));
}

// ── Φίλτρα αναζήτησης αγωνιστικών ──
$compSearch = trim($_GET['cq'] ?? '');
$compYear   = $_GET['cyear'] ?? '';
$compWhere  = "c.school_id=?"; $compParams = [$sid];
if ($compSearch) { $compWhere .= " AND c.name LIKE ?"; $compParams[] = "%$compSearch%"; }
if ($compYear)   { $compWhere .= " AND YEAR(c.comp_date)=?"; $compParams[] = $compYear; }

$comps = $db->prepare("SELECT c.*,COUNT(p.id) as part_count FROM competitions c LEFT JOIN competition_participants p ON p.competition_id=c.id WHERE $compWhere GROUP BY c.id ORDER BY c.comp_date DESC");
$comps->execute($compParams); $compList = $comps->fetchAll();

$stYears = $db->prepare("SELECT DISTINCT YEAR(comp_date) as yr FROM competitions WHERE school_id=? ORDER BY yr DESC"); $stYears->execute([$sid]); $years = $stYears->fetchAll(PDO::FETCH_COLUMN);

// ── Αν υπάρχει ?id=ID, φορτώνουμε λεπτομέρειες αγωνιστικού + αποτελέσματα + μετρητές μεταλλίων ──
$comp = null; $participants = []; $medals = ['gold'=>0,'silver'=>0,'bronze'=>0];
if ($compId) {
    $stComp = $db->prepare("SELECT * FROM competitions WHERE id=? AND school_id=? LIMIT 1"); $stComp->execute([$compId, $sid]); $comp = $stComp->fetch();
    $p = $db->prepare("SELECT p.*,a.full_name FROM competition_participants p JOIN athletes a ON a.id=p.athlete_id WHERE p.competition_id=?");
    $p->execute([$compId]); $participants = $p->fetchAll();
    foreach ($participants as $pt) { if (isset($medals[$pt['medal']])) $medals[$pt['medal']]++; }
}
$athletes = $db->prepare("SELECT id,full_name,weight FROM athletes WHERE school_id=? AND active=1 ORDER BY full_name");
$athletes->execute([$sid]); $athleteList = $athletes->fetchAll();

renderHead('Αγωνιστικά');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* ═══════════════════════════════════════════════
   COMPETITIONS PAGE — same accessibility system as athletes.php
═══════════════════════════════════════════════ */

.topbar { position: relative !important; top: auto !important; z-index: auto !important; }
.main-content > div[style*="border-bottom"] { position: relative !important; top: auto !important; }

@media (max-width: 900px) {
    #menuBtn { display: inline-flex !important; min-width: 44px !important; min-height: 44px !important; align-items: center !important; justify-content: center !important; font-size: 1.2rem !important; cursor: pointer !important; }
    .sidebar { position: fixed !important; top: 0 !important; left: 0 !important; bottom: 0 !important; width: min(280px,80vw) !important; z-index: 9999 !important; transform: translateX(-110%) !important; transition: transform .28s cubic-bezier(.2,.8,.2,1) !important; overflow-y: auto; -webkit-overflow-scrolling: touch; }
    .sidebar.open { transform: translateX(0) !important; box-shadow: 6px 0 40px rgba(0,0,0,.6) !important; }
    .main-content { margin-left: 0 !important; width: 100% !important; }
}
#dm-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9998; cursor: pointer; }
#dm-overlay.on { display: block; }

/* Animations */
@keyframes fadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
.page-body { animation: fadeIn .35s ease both; }
.anim-1 { opacity:0; animation: fadeUp .42s ease-out .05s both; }
.anim-2 { opacity:0; animation: fadeUp .42s ease-out .12s both; }
.anim-3 { opacity:0; animation: fadeUp .42s ease-out .19s both; }
.anim-4 { opacity:0; animation: fadeUp .42s ease-out .26s both; }
@media (prefers-reduced-motion: reduce) { .page-body,.anim-1,.anim-2,.anim-3,.anim-4 { animation:none!important; opacity:1; } }

/* ── Page header ── */
.page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem; margin-bottom: 1rem; }
.page-header h2 { font-size: clamp(1.15rem,4vw,1.5rem) !important; font-weight: 800; display: flex; align-items: center; gap: .5rem; margin: 0; }

/* ── Cards ── */
.card { border-radius: 18px; overflow: hidden; }
.card-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; padding: .9rem 1.1rem; border-bottom: 1px solid var(--border,#1e2536); }
.card-title { font-size: clamp(1rem,3.5vw,1.15rem) !important; font-weight: 800; display: flex; align-items: center; gap: .45rem; }

/* ── Stat cards (medal row) ── */
.stat-cards-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; margin-bottom: 1.1rem; }
.stat-card { border-radius: 18px; padding: 1.1rem 1rem 1rem; display: flex; flex-direction: column; align-items: flex-start; gap: .4rem; }
.stat-icon { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: .3rem; flex-shrink: 0; }
.icon-gold   { background: rgba(240,165,0,.15);  color: var(--gold,#f0a500); }
.icon-silver { background: rgba(192,192,192,.15); color: #c0c0c0; }
.icon-bronze { background: rgba(205,127,50,.15);  color: #cd7f32; }
.stat-val { font-size: clamp(1.6rem,6vw,2.2rem) !important; font-weight: 800; line-height: 1; }
.stat-lbl { font-size: clamp(.82rem,3vw,.92rem) !important; color: var(--muted,#8892b0); font-weight: 600; }

/* ── Competition list grid (cards) ── */
.comp-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }
@media (max-width: 900px) { .comp-grid { grid-template-columns: 1fr 1fr !important; } }
@media (max-width: 600px) { .comp-grid { grid-template-columns: 1fr !important; } }

/* Competition card */
.comp-card { border-radius: 18px; padding: 1.1rem; display: flex; flex-direction: column; gap: .5rem; transition: transform .18s, box-shadow .18s; }
.comp-card:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,0,0,.28); }
.comp-card-name { font-size: clamp(1rem,4vw,1.1rem) !important; font-weight: 800; line-height: 1.25; }
.comp-card-meta { font-size: clamp(.86rem,3.5vw,.95rem) !important; color: var(--muted,#8892b0); display: flex; flex-direction: column; gap: .3rem; }
.comp-card-meta span { display: flex; align-items: center; gap: .4rem; }
.comp-card .btn { margin-top: auto; }

/* ── Two-col layout for detail view ── */
.detail-grid { display: grid; grid-template-columns: 400px 1fr; gap: 1rem; align-items: start; }
@media (max-width: 1000px) { .detail-grid { grid-template-columns: 1fr !important; } }

/* ── Filters bar ── */
.filters-bar { display: flex; flex-wrap: wrap; gap: .55rem; align-items: center; margin-bottom: .9rem; }
.search-bar { position: relative; flex: 1; min-width: 150px; }
.search-bar .search-icon { position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); color: var(--muted,#8892b0); pointer-events: none; }
.search-bar input {
    width: 100%;
    padding-left: 2.4rem !important;
    font-size: clamp(.9rem,3.5vw,1rem) !important;
    min-height: 40px;
    border-radius: 10px !important;
    background: var(--input-bg, #0f1219);
    border: 1px solid var(--border, #1e2536);
    color: var(--text, #e2e8f0);
}
.search-bar input:focus {
    border-color: var(--red, #e63946) !important;
    outline: none;
    box-shadow: 0 0 0 3px rgba(230,57,70,.15) !important;
}

/* ── Tables ── */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.table-wrap table { width: 100%; border-collapse: collapse; }
.table-wrap th { font-size: clamp(.76rem,2.5vw,.84rem) !important; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; padding: .6rem .9rem; }
.table-wrap td { font-size: clamp(.9rem,3vw,.98rem) !important; padding: .75rem .9rem; vertical-align: middle; }
.table-wrap tbody tr { transition: background .15s; }
.table-wrap tbody tr:hover { background: rgba(255,255,255,.03); }

/* ── Buttons ── */
.btn { min-height: 38px; font-size: clamp(.88rem,3vw,.95rem) !important; font-weight: 700 !important; display: inline-flex; align-items: center; justify-content: center; gap: .4rem; border-radius: 10px; transition: all .18s; text-decoration: none; padding: .45rem .9rem; cursor: pointer; border: none; white-space: nowrap; }
.btn:active { transform: scale(.97); }
.btn-sm { min-height: 34px; padding: .35rem .75rem; }
.btn-lg { min-height: 48px; padding: .6rem 1.2rem; font-size: clamp(1rem,4vw,1.1rem) !important; }
.w-100 { width: 100%; }

/* ── Form elements ── */
.form-label { font-size: clamp(.92rem,3.5vw,1rem) !important; font-weight: 700; display: block; margin-bottom: .4rem; }
.form-control { font-size: clamp(.92rem,3.5vw,1rem) !important; min-height: 44px; padding: .65rem .9rem; border-radius: 10px !important; transition: border-color .2s, box-shadow .2s; width: 100%; background: var(--input-bg, #0f1219); border: 1px solid var(--border, #1e2536); color: var(--text, #e2e8f0); }
.form-control:focus { outline: none; border-color: var(--red,#e63946) !important; box-shadow: 0 0 0 3px rgba(230,57,70,.15) !important; }
textarea.form-control { min-height: 80px; resize: vertical; }
select.form-control { cursor: pointer; }
.form-section-title { font-size: clamp(.8rem,3vw,.88rem) !important; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: var(--muted,#8892b0); margin-bottom: .75rem; display: flex; align-items: center; gap: .45rem; padding-bottom: .5rem; border-bottom: 1px solid var(--border,#1e2536); }
.form-row { display: grid; gap: .85rem; }
.form-row.col-2 { grid-template-columns: 1fr 1fr; }
.col-span-2 { grid-column: span 2; }

/* ── Badges ── */
.badge { display: inline-flex; align-items: center; gap: .3rem; padding: .28rem .75rem; border-radius: 999px; font-size: clamp(.78rem,3vw,.86rem) !important; font-weight: 700; white-space: nowrap; }
.badge-basic  { background: rgba(255,255,255,.08); color: var(--text,#e2e8f0); }
.badge-gold   { background: rgba(240,165,0,.18);   color: var(--gold,#f0a500); }
.badge-silver { background: rgba(192,192,192,.18);  color: #c0c0c0; }
.badge-bronze { background: rgba(205,127,50,.18);   color: #cd7f32; }

/* ── Modal ── */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.modal { background: var(--bg2,#131929); border: 1px solid var(--border,#1e2536); border-radius: 20px; width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 80px rgba(0,0,0,.7); animation: fadeUp .3s ease both; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border,#1e2536); }
.modal-title { font-size: clamp(1.05rem,4vw,1.2rem) !important; font-weight: 800; display: flex; align-items: center; gap: .5rem; }
.modal-close { background: none; border: none; color: var(--muted,#8892b0); cursor: pointer; font-size: 1.25rem; width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: background .15s, color .15s; }
.modal-close:hover { background: rgba(255,255,255,.07); color: var(--text,#e2e8f0); }
.modal-body { padding: 1.1rem 1.25rem; }
.modal-footer { padding: .85rem 1.25rem; border-top: 1px solid var(--border,#1e2536); display: flex; justify-content: flex-end; gap: .65rem; }

/* ── Nav items ── */
.nav-item { min-height: 46px !important; font-size: clamp(.92rem,3vw,1rem) !important; font-weight: 600 !important; padding: .65rem .9rem !important; border-radius: 10px !important; display: flex !important; align-items: center !important; gap: .7rem !important; transition: background .15s, color .15s !important; text-decoration: none; }
.nav-item .icon { width: 22px; text-align: center; font-size: 1rem; flex-shrink: 0; }

/* ── Club name (exactly as athletes/subscriptions) ── */
.sidebar-school{
  margin: .25rem 1rem !important;
  padding: 0 !important;
  display: flex !important;
  align-items: center !important;
  justify-content: flex-start !important;
  text-align: left !important;
  font-weight: 700 !important;
  font-size: clamp(.82rem, 3vw, .92rem) !important;
  line-height: 1.25 !important;
  color: var(--text, #f0f2ff) !important;
  white-space: normal !important;
  overflow: visible !important;
  text-overflow: unset !important;
  overflow-wrap: anywhere !important;
  word-break: break-word !important;
  background: none !important;
  border: none !important;
  box-shadow: none !important;
  border-radius: 0 !important;
  transform: none !important;
  filter: none !important;
}
.sidebar-school:hover,
.sidebar-school:focus,
.sidebar-school:active{
  background: none !important;
  border: none !important;
  box-shadow: none !important;
  transform: none !important;
  outline: none !important;
  filter: none !important;
}

/* ── Empty state ── */
.empty-state { text-align: center; padding: 2.25rem 1rem; }
.empty-state .empty-icon { font-size: clamp(2rem,7vw,2.75rem); margin-bottom: .65rem; opacity:.5; }
.empty-state p { font-size: clamp(.92rem,3vw,1rem) !important; color:var(--muted,#8892b0); margin:0 0 .75rem; }

/* ── Utility ── */
.mb-2 { margin-bottom: .75rem !important; }
.mb-3 { margin-bottom: 1rem !important; }
.mt-1 { margin-top: .4rem !important; }
.col-hide-mobile { display: table-cell; }

/* ── Responsive improvements ── */
@media (max-width: 900px) { .page-body { padding: 1rem !important; } .stat-cards-row { gap: .75rem !important; } }
@media (max-width: 700px) {
    .page-body { padding: .85rem !important; }
    .form-row.col-2 { grid-template-columns: 1fr !important; }
    .col-span-2 { grid-column: span 1; }
    .col-hide-mobile { display: none !important; }
}
@media (max-width: 480px) {
    .page-body { padding: .65rem !important; }
    .card,.comp-card,.modal { border-radius: 14px; }
    .stat-cards-row { gap: .5rem !important; }
    .stat-icon { width: 38px; height: 38px; font-size: 1rem; }
    .stat-val { font-size: clamp(1.4rem,5vw,1.8rem) !important; }
    .stat-lbl { font-size: clamp(.7rem,2.8vw,.8rem) !important; }
    .filters-bar .search-bar input,
    .filters-bar select,
    .search-bar input { font-size: .9rem !important; min-height: 36px; }
    .btn-sm { min-height: 30px; padding: .25rem .6rem; font-size: .8rem !important; }
}
@media (max-width: 360px) {
    .page-body { padding: .5rem !important; }
    .stat-icon { width: 32px; height: 32px; }
    .stat-val { font-size: clamp(1.2rem,4.5vw,1.5rem) !important; }
    .filters-bar .search-bar input { font-size: .85rem !important; }
}
</style>

<body>
<div class="app-layout">
<?php renderSidebar('competitions'); ?>
<div id="dm-overlay"></div>

<div class="main-content">
<?php renderTopbar('<i class="fa-solid fa-trophy"></i> Αγωνιστικά'); ?>
<div class="page-body">

<?php if ($comp): ?>
<!-- ══════════════════════════════════════════
     COMPETITION DETAIL VIEW
══════════════════════════════════════════ -->

<!-- Back + Title -->
<div class="page-header anim-1">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
        <a href="<?= APP_URL ?>/pages/competitions.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Πίσω
        </a>
        <h2>
            <i class="fa-solid fa-trophy" style="color:var(--gold,#f0a500)"></i>
            <?= h($comp['name']) ?>
            <span style="font-size:clamp(.85rem,3vw,.95rem)!important;font-weight:600;color:var(--muted,#8892b0)">
                &mdash; <?= formatDate($comp['comp_date']) ?>
            </span>
        </h2>
    </div>
</div>

<!-- Medal stat cards -->
<div class="stat-cards-row anim-2">
    <div class="stat-card card">
        <div class="stat-icon icon-gold"><i class="fa-solid fa-medal"></i></div>
        <div class="stat-val"><?= $medals['gold'] ?></div>
        <div class="stat-lbl">Χρυσά</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-silver"><i class="fa-solid fa-medal"></i></div>
        <div class="stat-val"><?= $medals['silver'] ?></div>
        <div class="stat-lbl">Ασημένια</div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-bronze"><i class="fa-solid fa-medal"></i></div>
        <div class="stat-val"><?= $medals['bronze'] ?></div>
        <div class="stat-lbl">Χάλκινα</div>
    </div>
</div>

<!-- Detail grid: add form + results table -->
<?php
// Φίλτρο αναζήτησης αθλητών στη λεπτομέρεια αγωνιστικού
$partSearch = trim($_GET['pq'] ?? '');
$displayedParticipants = $partSearch
    ? array_filter($participants, fn($p) => str_contains(mb_strtolower($p['full_name']), mb_strtolower($partSearch)))
    : $participants;
?>
<div class="detail-grid anim-3">

    <!-- Add result form -->
    <div class="card" style="align-self:start">
        <div class="card-header">
            <div class="card-title">
                <i class="fa-solid fa-plus" style="color:var(--green,#2dc653)"></i> Προσθήκη Αποτελέσματος
            </div>
        </div>
        <form method="POST" style="padding:1.1rem">
            <input type="hidden" name="_action" value="save_participant">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="competition_id" value="<?= $compId ?>">

            <div class="form-section-title"><i class="fa-solid fa-user"></i> Αθλητής</div>
            <div class="form-group mb-2">
                <label class="form-label">Αθλητής</label>
                <select name="athlete_id" class="form-control">
                    <?php foreach ($athleteList as $a): ?>
                    <option value="<?= $a['id'] ?>"><?= h($a['full_name']) ?><?= $a['weight'] ? ' ('.$a['weight'].'kg)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-2">
                <label class="form-label">Κατηγορία Βάρους</label>
                <input name="weight_category" class="form-control" placeholder="π.χ. -63kg">
            </div>

            <div class="form-section-title" style="margin-top:.85rem"><i class="fa-solid fa-medal"></i> Αποτέλεσμα</div>
            <div class="form-group mb-2">
                <label class="form-label">Αποτέλεσμα</label>
                <input name="result" class="form-control" placeholder="π.χ. Πρωταθλητής Ελλάδας">
            </div>
            <div class="form-row col-2" style="margin-bottom:.85rem">
                <div class="form-group">
                    <label class="form-label">Μετάλλιο</label>
                    <select name="medal" class="form-control">
                        <option value="none">— Χωρίς —</option>
                        <option value="gold">🥇 Χρυσό</option>
                        <option value="silver">🥈 Ασημένιο</option>
                        <option value="bronze">🥉 Χάλκινο</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Πόντοι</label>
                    <input type="number" name="points" class="form-control" value="0" min="0">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="min-height:48px;font-size:clamp(1rem,4vw,1.05rem)!important">
                <i class="fa-solid fa-plus"></i> Προσθήκη
            </button>
        </form>
    </div>

    <!-- Results table -->
    <div class="card p-0" style="align-self:start">
        <div class="card-header">
            <div class="card-title">
                <i class="fa-solid fa-list" style="color:var(--red,#e63946)"></i>
                Αποτελέσματα
                <span style="font-size:clamp(.8rem,3vw,.9rem);font-weight:600;color:var(--muted,#8892b0)">(<?= count($participants) ?>)</span>
            </div>
            <form method="GET" style="display:flex;gap:.4rem;align-items:center">
                <input type="hidden" name="id" value="<?= $compId ?>">
                <div class="search-bar" style="min-width:120px;max-width:170px">
                    <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input name="pq" value="<?= h($partSearch) ?>" placeholder="Αναζήτηση...">
                </div>
                <button type="submit" class="btn btn-ghost btn-sm" title="Φίλτρο"><i class="fa-solid fa-filter"></i></button>
                <?php if ($partSearch): ?><a href="?id=<?= $compId ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Αθλητής</th>
                        <th class="col-hide-mobile">Κατηγορία</th>
                        <th class="col-hide-mobile">Αποτέλεσμα</th>
                        <th>Μετάλλιο</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayedParticipants as $p): ?>
                    <tr>
                        <td style="font-weight:700;font-size:clamp(.92rem,3vw,1rem)!important"><?= h($p['full_name']) ?></td>
                        <td class="col-hide-mobile" style="color:var(--muted,#8892b0)"><?= h($p['weight_category']??'—') ?></td>
                        <td class="col-hide-mobile"><?= h($p['result']??'—') ?></td>
                        <td>
                            <?= match($p['medal']) {
                                'gold'   => '<span class="badge badge-gold"><i class="fa-solid fa-medal"></i> Χρυσό</span>',
                                'silver' => '<span class="badge badge-silver"><i class="fa-solid fa-medal"></i> Ασημένιο</span>',
                                'bronze' => '<span class="badge badge-bronze"><i class="fa-solid fa-medal"></i> Χάλκινο</span>',
                                default  => '<span style="color:var(--muted,#8892b0)">—</span>',
                            } ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$displayedParticipants): ?>
                    <tr><td colspan="4">
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fa-solid fa-medal"></i></div>
                            <p>Δεν βρέθηκαν αποτελέσματα</p>
                        </div>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /detail-grid -->

<?php else: ?>
<!-- ══════════════════════════════════════════
     COMPETITION LIST
══════════════════════════════════════════ -->

<div class="page-header anim-1">
    <h2>
        <i class="fa-solid fa-trophy" style="color:var(--gold,#f0a500)"></i>
        Διοργανώσεις
        <span style="font-size:clamp(.82rem,3vw,.9rem);font-weight:600;color:var(--muted,#8892b0);margin-left:.3rem" id="compCount">(<?= count($compList) ?>)</span>
    </h2>
    <button onclick="openAddCompModal()" class="btn btn-primary btn-sm">
        <i class="fa-solid fa-plus"></i> Προσθήκη Διοργάνωσης
    </button>
</div>

<!-- Live search -->
<div class="filters-bar anim-2" style="margin-bottom:.9rem">
    <div class="search-bar" style="flex:1;min-width:200px">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="text" id="compSearch" placeholder="Αναζήτηση διοργάνωσης..." autocomplete="off">
    </div>
</div>

<!-- Table -->
<div class="card anim-3">
<?php if ($compList): ?>
<div class="table-wrap">
    <table id="compTable">
        <thead>
            <tr>
                <th>Όνομα Διοργάνωσης</th>
                <th>Ημερομηνία</th>
                <th class="col-hide-mobile">Τύπος</th>
                <th class="col-hide-mobile">Τόπος</th>
                <th>Αθλητές</th>
                <th class="col-hide-mobile">Κόστος</th>
                <th>Ενέργειες</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($compList as $c): ?>
            <tr class="comp-row" data-name="<?= strtolower(h($c['name'])) ?>" data-loc="<?= strtolower(h($c['location']??'')) ?>"
                style="cursor:pointer" onclick="openCompProfile(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)">
                <td style="font-weight:800;font-size:clamp(.92rem,3vw,1rem)"><?= h($c['name']) ?></td>
                <td style="white-space:nowrap"><?= formatDate($c['comp_date']) ?></td>
                <td class="col-hide-mobile" style="color:var(--muted,#8892b0)"><?= h($c['comp_type']??'—') ?></td>
                <td class="col-hide-mobile" style="color:var(--muted,#8892b0)"><?= h($c['location']??'—') ?></td>
                <td><span class="badge badge-basic"><i class="fa-solid fa-users"></i> <?= $c['part_count'] ?></span></td>
                <td class="col-hide-mobile"><?= formatMoney($c['cost']) ?></td>
                <td onclick="event.stopPropagation()">
                    <div style="display:flex;gap:.3rem;flex-wrap:nowrap">
                        <button class="btn btn-ghost btn-sm" title="Επεξεργασία"
                            onclick="openEditCompModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)">
                            <i class="fa-solid fa-pen-to-square" style="color:var(--gold,#f0a500)"></i>
                        </button>
                        <a href="?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm" title="Αποτελέσματα">
                            <i class="fa-solid fa-chart-bar" style="color:#3b82f6"></i>
                        </a>
                        <button class="btn btn-ghost btn-sm" title="Διαγραφή"
                            onclick="confirmDeleteComp(<?= $c['id'] ?>, '<?= addslashes(h($c['name'])) ?>')">
                            <i class="fa-solid fa-trash" style="color:var(--red,#e63946)"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div id="compNoResults" style="display:none;padding:2.25rem;text-align:center;color:var(--muted,#8892b0)">
    <i class="fa-solid fa-magnifying-glass" style="font-size:2rem;display:block;margin-bottom:.65rem;opacity:.4"></i>
    Δεν βρέθηκαν διοργανώσεις
</div>
<?php else: ?>
<div class="empty-state">
    <div class="empty-icon"><i class="fa-solid fa-trophy"></i></div>
    <p>Δεν υπάρχουν διοργανώσεις ακόμα</p>
    <button onclick="openAddCompModal()" class="btn btn-primary btn-sm" style="margin-top:.75rem">
        <i class="fa-solid fa-plus"></i> Προσθήκη Διοργάνωσης
    </button>
</div>
<?php endif; ?>
</div>

<?php endif; ?>

</div><!-- /page-body -->
</div><!-- /main-content -->
</div><!-- /app-layout -->

<!-- ── Modal: Add/Edit Competition ── -->
<div class="modal-backdrop" id="addCompModal" style="display:none" onclick="if(event.target===this)closeAddCompModal()">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="compModalTitle">
                <i class="fa-solid fa-trophy" style="color:var(--gold,#f0a500)"></i> <span id="compModalTitleText">Νέα Διοργάνωση</span>
            </div>
            <button class="modal-close" onclick="closeAddCompModal()" title="Κλείσιμο">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" id="compForm">
            <div class="modal-body">
                <input type="hidden" name="_action" value="save_comp">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="id" id="compFormId" value="">

                <div class="form-group mb-2">
                    <label class="form-label">Όνομα Διοργάνωσης <span style="color:var(--red,#e63946)">*</span></label>
                    <input id="compFormName" name="name" class="form-control" required autofocus placeholder="π.χ. Πρωτάθλημα Ελλάδας 2025">
                </div>

                <div class="form-row col-2" style="margin-bottom:.85rem">
                    <div class="form-group">
                        <label class="form-label">Ημερομηνία <span style="color:var(--red,#e63946)">*</span></label>
                        <input type="date" id="compFormDate" name="comp_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Τύπος Διοργάνωσης</label>
                        <input id="compFormType" name="comp_type" class="form-control" placeholder="π.χ. Πανελλήνιο, Τοπικό">
                    </div>
                    <div class="form-group col-span-2">
                        <label class="form-label">Τόπος</label>
                        <input id="compFormLocation" name="location" class="form-control" placeholder="π.χ. Αθήνα, ΣΕΦ">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Κόστος (€)</label>
                        <input type="number" step=".01" id="compFormCost" name="cost" class="form-control" value="0" placeholder="0.00">
                    </div>
                    <div class="form-group col-span-2">
                        <label class="form-label">Σημειώσεις</label>
                        <textarea id="compFormNotes" name="notes" class="form-control"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddCompModal()">Ακύρωση</button>
                <button type="submit" class="btn btn-primary" style="min-height:46px" id="compFormSubmitBtn">
                    <i class="fa-solid fa-plus"></i> <span id="compFormSubmitLabel">Δημιουργία</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal: Competition Profile ── -->
<div id="compProfileModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10500;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeCompProfile()">
<div style="background:var(--bg2,#131929);border:1px solid var(--border,#1e2536);border-radius:20px;width:100%;max-width:520px;max-height:88vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.7)">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border,#1e2536);position:sticky;top:0;background:var(--bg2,#131929);z-index:1">
        <div style="font-size:1.05rem;font-weight:800;display:flex;align-items:center;gap:.5rem;color:var(--gold,#f0a500)">
            <i class="fa-solid fa-trophy"></i> <span id="profileCompName"></span>
        </div>
        <button onclick="closeCompProfile()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:32px;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div style="padding:1.25rem" id="profileCompBody"></div>
    <div style="padding:.85rem 1.25rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.5rem;justify-content:flex-end">
        <a id="profileCompResultsLink" href="#" class="btn btn-primary btn-sm"><i class="fa-solid fa-chart-bar"></i> Αποτελέσματα</a>
        <button onclick="closeCompProfile()" style="min-height:34px;font-size:.9rem;font-weight:700;padding:.35rem .75rem;border-radius:9px;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)">Κλείσιμο</button>
    </div>
</div>
</div>

<!-- ── Modal: Delete Competition ── -->
<div id="deleteCompModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10500;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeDeleteCompModal()">
<div style="background:var(--bg2,#131929);border:1px solid var(--border,#1e2536);border-radius:20px;width:100%;max-width:380px;box-shadow:0 24px 80px rgba(0,0,0,.7)">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.2rem;border-bottom:1px solid var(--border,#1e2536)">
        <div style="font-size:1rem;font-weight:800;color:var(--red,#e63946);display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-trash"></i> Διαγραφή Διοργάνωσης</div>
        <button onclick="closeDeleteCompModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:32px;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div style="padding:1.1rem 1.2rem">
        <p style="margin:0 0 .4rem;font-size:.95rem;color:var(--text,#e2e8f0)">Να διαγραφεί η διοργάνωση <strong id="deleteCompName" style="color:var(--white,#f0f2ff)"></strong>;</p>
        <p style="margin:0;font-size:.83rem;color:var(--muted,#8892b0)">Θα διαγραφούν και τα αποτελέσματα.</p>
    </div>
    <div style="padding:.85rem 1.2rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.5rem;justify-content:flex-end">
        <button onclick="closeDeleteCompModal()" style="min-height:36px;font-size:.9rem;font-weight:700;padding:.4rem .85rem;border-radius:9px;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)">Ακύρωση</button>
        <form method="POST" id="deleteCompForm" style="display:inline">
            <input type="hidden" name="_action" value="delete_comp">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="id" id="deleteCompId" value="">
            <button type="submit" style="min-height:36px;font-size:.9rem;font-weight:700;padding:.4rem .85rem;border-radius:9px;cursor:pointer;border:none;background:var(--red,#e63946);color:#fff"><i class="fa-solid fa-trash"></i> Διαγραφή</button>
        </form>
    </div>
</div>
</div>

<script>
/* Topbar fix */
(function(){
    document.querySelectorAll('.topbar').forEach(function(el){
        var txt=(el.textContent||'').trim();
        if(txt==='...'||txt===''){el.remove();return;}
        var pos=window.getComputedStyle(el).position;
        if(pos==='fixed'||pos==='sticky'){el.style.setProperty('position','relative','important');el.style.setProperty('top','auto','important');}
    });
})();

/* Sidebar toggle */
(function(){
    var sidebar=document.getElementById('sidebar'),overlay=document.getElementById('dm-overlay'),menuBtn=document.getElementById('menuBtn');
    if(!sidebar||!menuBtn)return;
    function open(){sidebar.classList.add('open');overlay&&overlay.classList.add('on');document.body.style.overflow='hidden';}
    function close(){sidebar.classList.remove('open');overlay&&overlay.classList.remove('on');document.body.style.overflow='';}
    function toggle(){sidebar.classList.contains('open')?close():open();}
    menuBtn.onclick=function(e){e.stopPropagation();toggle();};
    overlay&&overlay.addEventListener('click',close);
    sidebar.querySelectorAll('a.nav-item').forEach(function(link){link.addEventListener('click',function(){if(window.innerWidth<=900)setTimeout(close,80);});});
    document.addEventListener('keydown',function(e){
        if(e.key==='Escape'){
            close();
            closeAddCompModal();
            closeCompProfile();
            closeDeleteCompModal();
        }
    });
    window.addEventListener('resize',function(){if(window.innerWidth>900){sidebar.classList.remove('open');overlay&&overlay.classList.remove('on');document.body.style.overflow='';}});
})();

/* Live search */
(function(){
    var inp = document.getElementById('compSearch');
    if(!inp) return;
    var rows = Array.from(document.querySelectorAll('#compTable .comp-row'));
    var noRes = document.getElementById('compNoResults');
    var cnt = document.getElementById('compCount');
    inp.addEventListener('input', function(){
        var q = this.value.toLowerCase().trim();
        var shown = 0;
        rows.forEach(function(r){
            var match = !q || r.dataset.name.indexOf(q)>=0 || r.dataset.loc.indexOf(q)>=0;
            r.style.display = match ? '' : 'none';
            if(match) shown++;
        });
        if(noRes) noRes.style.display = shown ? 'none' : 'block';
        if(cnt) cnt.textContent = '(' + shown + ')';
    });
})();

/* Add/Edit Competition Modal */
function openAddCompModal() {
    document.getElementById('compFormId').value = '';
    document.getElementById('compFormName').value = '';
    document.getElementById('compFormDate').value = '';
    document.getElementById('compFormType').value = '';
    document.getElementById('compFormLocation').value = '';
    document.getElementById('compFormCost').value = '0';
    document.getElementById('compFormNotes').value = '';
    document.getElementById('compModalTitleText').textContent = 'Νέα Διοργάνωση';
    document.getElementById('compFormSubmitLabel').textContent = 'Δημιουργία';
    document.getElementById('addCompModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setTimeout(function(){document.getElementById('compFormName').focus();},80);
}
function openEditCompModal(c) {
    document.getElementById('compFormId').value = c.id;
    document.getElementById('compFormName').value = c.name || '';
    document.getElementById('compFormDate').value = c.comp_date || '';
    document.getElementById('compFormType').value = c.comp_type || '';
    document.getElementById('compFormLocation').value = c.location || '';
    document.getElementById('compFormCost').value = c.cost || '0';
    document.getElementById('compFormNotes').value = c.notes || '';
    document.getElementById('compModalTitleText').textContent = 'Επεξεργασία Διοργάνωσης';
    document.getElementById('compFormSubmitLabel').textContent = 'Αποθήκευση';
    document.getElementById('addCompModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeAddCompModal() {
    document.getElementById('addCompModal').style.display = 'none';
    document.body.style.overflow = '';
}

/* Competition Profile Popup */
function openCompProfile(c) {
    document.getElementById('profileCompName').textContent = c.name;
    document.getElementById('profileCompResultsLink').href = '?id=' + c.id;
    var html = '<table style="width:100%;border-collapse:collapse">';
    if(c.comp_date) html += '<tr><td style="padding:.4rem 0;color:var(--muted,#8892b0);font-size:.88rem;width:40%"><i class="fa-regular fa-calendar"></i> Ημερομηνία</td><td style="font-weight:700">' + c.comp_date + '</td></tr>';
    if(c.comp_type) html += '<tr><td style="padding:.4rem 0;color:var(--muted,#8892b0);font-size:.88rem"><i class="fa-solid fa-tag"></i> Τύπος</td><td style="font-weight:600">' + escHtml(c.comp_type) + '</td></tr>';
    if(c.location)  html += '<tr><td style="padding:.4rem 0;color:var(--muted,#8892b0);font-size:.88rem"><i class="fa-solid fa-location-dot"></i> Τόπος</td><td>' + escHtml(c.location) + '</td></tr>';
    if(c.cost)      html += '<tr><td style="padding:.4rem 0;color:var(--muted,#8892b0);font-size:.88rem"><i class="fa-solid fa-euro-sign"></i> Κόστος</td><td style="color:var(--green,#2dc653);font-weight:700">€' + parseFloat(c.cost).toFixed(2) + '</td></tr>';
    html += '<tr><td style="padding:.4rem 0;color:var(--muted,#8892b0);font-size:.88rem"><i class="fa-solid fa-users"></i> Αθλητές</td><td><span style="font-weight:800">' + (c.part_count||0) + '</span></td></tr>';
    if(c.notes)     html += '<tr><td colspan="2" style="padding:.75rem 0 .4rem"><div style="background:rgba(255,255,255,.04);border-radius:8px;padding:.65rem .9rem;font-size:.88rem;color:var(--muted,#8892b0)">' + escHtml(c.notes) + '</div></td></tr>';
    html += '</table>';
    document.getElementById('profileCompBody').innerHTML = html;
    document.getElementById('compProfileModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeCompProfile() {
    document.getElementById('compProfileModal').style.display = 'none';
    document.body.style.overflow = '';
}

/* Delete Competition */
function confirmDeleteComp(id, name) {
    document.getElementById('deleteCompId').value = id;
    document.getElementById('deleteCompName').textContent = name;
    document.getElementById('deleteCompModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeDeleteCompModal() {
    document.getElementById('deleteCompModal').style.display = 'none';
    document.body.style.overflow = '';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>