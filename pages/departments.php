<?php

/**
 * ============================================================
 * pages/departments.php — Διαχείριση Τμημάτων
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
renderPaymentWall();

$db  = getDB();
$sid = schoolId();

if (!function_exists('sportLabel')) {
    function sportLabel(string $sport): string {
        $map = [
            'taekwondo_wtf'    => 'Taekwondo (WTF/WT)',
            'taekwondo_itf'    => 'Taekwondo (ITF)',
            'karate_shotokan'  => 'Karate Shotokan',
            'karate_kyokushin' => 'Karate Kyokushin',
            'karate'           => 'Karate',
            'taekwondo'        => 'Taekwondo',
            'bjj'              => 'Brazilian Jiu-Jitsu',
            'judo'             => 'Judo',
            'kickboxing'       => 'Kickboxing',
            'boxing'           => 'Πυγμαχία',
            'mma'              => 'MMA',
            'wrestling'        => 'Πάλη (Wrestling)',
            'pankration'       => 'Παγκράτιο',
            'sambo'            => 'Sambo',
            'other'            => 'Άλλο',
        ];
        return $map[$sport] ?? ($sport !== '' ? htmlspecialchars($sport, ENT_QUOTES, 'UTF-8') : '—');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['_action'] ?? '') === 'assign_athlete_to_dept')) {
    verifyCsrf();
    $deptId    = (int)($_POST['dept_id'] ?? 0);
    $athleteId = (int)($_POST['athlete_id'] ?? 0);
    if ($deptId && $athleteId) {
        $db->prepare("UPDATE athletes SET department_id=? WHERE id=? AND school_id=?")
           ->execute([$deptId, $athleteId, $sid]);
    }
    redirect(APP_URL . '/pages/departments.php');
}


if (!function_exists('normalizeSport')) {
    function normalizeSport(?string $sport): ?string {
        $sport = trim((string)$sport);
        if ($sport === '') return null;

        $allowed = [
            'taekwondo_wtf',
            'taekwondo_itf',
            'karate_shotokan',
            'karate_kyokushin',
            'karate',
            'taekwondo',
            'bjj',
            'judo',
            'kickboxing',
            'boxing',
            'mma',
            'wrestling',
            'pankration',
            'sambo',
            'other',
        ];

        return in_array($sport, $allowed, true) ? $sport : null;
    }
}

// ── Auto-migration: βεβαιώνουμε ότι υπάρχει η στήλη department_id ──
(function() use ($db) {
    try {
        $cols = $db->query("DESCRIBE athletes")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('department_id', $cols, true)) {
            $db->exec("ALTER TABLE athletes ADD COLUMN `department_id` INT DEFAULT NULL");
        }
    } catch (Exception $e) {}

    try {
        $sportCol = $db->query("SHOW COLUMNS FROM departments LIKE 'sport'")->fetch(PDO::FETCH_ASSOC);
        if ($sportCol && stripos((string)($sportCol['Type'] ?? ''), 'enum(') === 0) {
            $db->exec("ALTER TABLE departments MODIFY COLUMN `sport` VARCHAR(50) DEFAULT NULL");
        }
    } catch (Exception $e) {}
})();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $a = $_POST;

    if (($a['_action'] ?? '') === 'save_dept') {
        $id    = (int)($a['id'] ?? 0);
        $sport = normalizeSport($a['sport'] ?? null) ?? 'other';

        $name        = trim((string)($a['name'] ?? ''));
        $schedule    = trim((string)($a['schedule'] ?? ''));
        $maxAthletes = max(0, (int)($a['max_athletes'] ?? 0));
        $monthlyFee  = trim((string)($a['monthly_fee'] ?? '0'));
        $active      = isset($a['active']) ? (int)$a['active'] : 1;

        if ($id > 0) {
            $db->prepare("
                UPDATE departments
                SET name=?, schedule=?, max_athletes=?, monthly_fee=?, sport=?, active=?
                WHERE id=? AND school_id=?
            ")->execute([$name, $schedule, $maxAthletes, $monthlyFee, $sport, $active, $id, $sid]);

            flash('Τμήμα ενημερώθηκε!');
        } else {
            $db->prepare("
                INSERT INTO departments (school_id, name, schedule, max_athletes, monthly_fee, sport, active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ")->execute([$sid, $name, $schedule, $maxAthletes, $monthlyFee, $sport]);

            flash('Τμήμα δημιουργήθηκε!');
        }
    }

    if (($a['_action'] ?? '') === 'delete_dept') {
        $db->prepare("UPDATE departments SET active=0 WHERE id=? AND school_id=?")
           ->execute([(int)$a['id'], $sid]);
        flash('Τμήμα απενεργοποιήθηκε.', 'danger');
    }

    if (($a['_action'] ?? '') === 'hard_delete_dept') {
        $id = (int)$a['id'];
        $db->prepare("UPDATE athletes SET department_id=NULL WHERE department_id=? AND school_id=?")
           ->execute([$id, $sid]);
        $db->prepare("DELETE FROM departments WHERE id=? AND school_id=?")
           ->execute([$id, $sid]);
        flash('Τμήμα διαγράφηκε οριστικά.', 'danger');
    }

    redirect(APP_URL . '/pages/departments.php');
}

// Load departments
$depts = $db->prepare("
    SELECT d.*, COUNT(a.id) AS athlete_count
    FROM departments d
    LEFT JOIN athletes a ON a.department_id=d.id AND a.active=1
    WHERE d.school_id=?
    GROUP BY d.id
    ORDER BY d.active DESC, d.name
");
$depts->execute([$sid]);
$deptList = $depts->fetchAll(PDO::FETCH_ASSOC);

// Load athletes per department
$athletesByDept = [];
$allAthletes = $db->prepare("
    SELECT id, full_name, phone, parent_phone, department_id
    FROM athletes
    WHERE school_id=? AND active=1 AND department_id IS NOT NULL
    ORDER BY full_name
");
$allAthletes->execute([$sid]);
foreach ($allAthletes->fetchAll(PDO::FETCH_ASSOC) as $ath) {
    $athletesByDept[$ath['department_id']][] = $ath;
}


// Athletes available to assign (active, any dept or no dept)
$availableAthletes = $db->prepare("
    SELECT id, full_name, department_id
    FROM athletes
    WHERE school_id=? AND active=1
    ORDER BY full_name
");
$availableAthletes->execute([$sid]);
$availableAthletesList = $availableAthletes->fetchAll(PDO::FETCH_ASSOC);

$stDA = $db->prepare("SELECT COUNT(*) FROM departments WHERE school_id=? AND active=1"); $stDA->execute([$sid]); $totalActive   = (int)$stDA->fetchColumn();
$stDI = $db->prepare("SELECT COUNT(*) FROM departments WHERE school_id=? AND active=0"); $stDI->execute([$sid]); $totalInactive = (int)$stDI->fetchColumn();
$stAD = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id=? AND active=1 AND department_id IS NOT NULL"); $stAD->execute([$sid]); $totalAthletes = (int)$stAD->fetchColumn();
$fullDepts     = count(array_filter($deptList, fn($d) => (int)$d['max_athletes'] > 0 && (int)$d['athlete_count'] >= (int)$d['max_athletes']));

renderHead('Τμήματα');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
.topbar{position:relative!important;top:auto!important;z-index:auto!important}
.main-content>div[style*="border-bottom"]{position:relative!important;top:auto!important}

@media(max-width:900px){
  #menuBtn{display:inline-flex!important;min-width:44px!important;min-height:44px!important;align-items:center!important;justify-content:center!important;font-size:1.2rem!important;cursor:pointer!important}
  .sidebar{position:fixed!important;top:0!important;left:0!important;bottom:0!important;width:min(280px,80vw)!important;z-index:9999!important;transform:translateX(-110%)!important;transition:transform .28s cubic-bezier(.2,.8,.2,1)!important;overflow-y:auto;-webkit-overflow-scrolling:touch}
  .sidebar.open{transform:translateX(0)!important;box-shadow:6px 0 40px rgba(0,0,0,.6)!important}
  .main-content{margin-left:0!important;width:100%!important}
}
#dm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;cursor:pointer}
#dm-overlay.on{display:block}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.page-body{animation:fadeIn .35s ease both}
.anim-1{opacity:0;animation:fadeUp .42s ease-out .05s both}
.anim-2{opacity:0;animation:fadeUp .42s ease-out .12s both}
.anim-3{opacity:0;animation:fadeUp .42s ease-out .19s both}
@media(prefers-reduced-motion:reduce){.page-body,.anim-1,.anim-2,.anim-3{animation:none!important;opacity:1}}

.stat-cards-row{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;margin-bottom:1.1rem}
.stat-card{border-radius:18px;padding:1rem 1.1rem;display:flex;flex-direction:row;align-items:center;gap:.85rem;overflow:hidden}
.stat-icon{width:48px;height:48px;min-width:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.icon-blue{background:rgba(59,130,246,.15);color:#3b82f6}
.icon-green{background:rgba(45,198,83,.15);color:var(--green,#2dc653)}
.icon-gold{background:rgba(240,165,0,.15);color:var(--gold,#f0a500)}
.icon-red{background:rgba(230,57,70,.15);color:var(--red,#e63946)}
.stat-text{display:flex;flex-direction:column;gap:.1rem;min-width:0}
.stat-lbl{font-size:clamp(.78rem,2vw,.88rem)!important;color:var(--muted,#8892b0);font-weight:600;line-height:1.2;white-space:nowrap}
.stat-val{font-size:clamp(1.4rem,3.5vw,2rem)!important;font-weight:800;line-height:1}
@media(max-width:860px){.stat-cards-row{grid-template-columns:repeat(2,1fr);gap:.75rem}}
@media(max-width:480px){.stat-cards-row{grid-template-columns:repeat(2,1fr);gap:.6rem}.stat-card{padding:.7rem .85rem;gap:.6rem}.stat-icon{width:38px;height:38px;min-width:38px;font-size:1rem;border-radius:10px}}

.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem}
.page-header h2{font-size:clamp(1.15rem,4vw,1.5rem)!important;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0}

.card{border-radius:18px;overflow:hidden}
.card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.9rem 1.1rem;border-bottom:1px solid var(--border,#1e2536)}
.card-title{font-size:clamp(1rem,3.5vw,1.15rem)!important;font-weight:800;display:flex;align-items:center;gap:.45rem}

.filters-bar{display:flex;flex-wrap:wrap;gap:.55rem;align-items:stretch;margin-bottom:.9rem}
.filters-bar .form-control,.filters-bar select{font-size:clamp(.88rem,3.5vw,.95rem)!important;height:40px;padding:0 .75rem;min-height:40px}
.search-bar{position:relative;flex:1;min-width:0}
.search-bar .search-icon{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--muted,#8892b0);pointer-events:none;z-index:2}
.search-bar input{width:100%;padding-left:2.35rem!important;min-height:44px!important;border-radius:12px!important;font-size:clamp(.9rem,3.5vw,1rem)!important;background:var(--input-bg,#0f1219);border:1px solid var(--border,#1e2536);color:var(--text,#e2e8f0)}
.search-bar input:focus{outline:none;border-color:var(--red,#e63946)!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
@media(max-width:700px){
  .filters-bar{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:.65rem!important}
  .search-bar{grid-column:1/-1!important;width:100%!important}
  .filters-bar select,.filters-bar a.btn{width:100%!important;justify-content:center!important}
}
@media(max-width:420px){.filters-bar{grid-template-columns:1fr!important}}

.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}
.table-wrap table{width:max-content;min-width:100%;border-collapse:collapse}
.table-wrap th{font-size:clamp(.76rem,2.5vw,.84rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;padding:.6rem .9rem;border-right:1px solid rgba(255,255,255,.06)}
.table-wrap th:last-child{border-right:none}
.table-wrap td{font-size:clamp(.9rem,3vw,.98rem)!important;padding:.7rem .9rem;vertical-align:middle;white-space:nowrap;border-right:1px solid rgba(255,255,255,.05)}
.table-wrap td:last-child{border-right:none}
.table-wrap tbody tr{border-bottom:1px solid rgba(255,255,255,.04)}
.col-hide-sm{display:table-cell}
@media(max-width:700px){.col-hide-sm{display:none!important}}
@media(max-width:600px){.table-wrap th,.table-wrap td{padding:.45rem .65rem;font-size:.82rem!important}}
.table-wrap tbody tr{transition:background .15s}
.table-wrap tbody tr:hover{background:rgba(255,255,255,.03)}
.dept-row{cursor:pointer}
.dept-row td{cursor:pointer;transition:background .12s}
.dept-row:hover td{background:rgba(255,255,255,.035)}
.dept-name-cell{font-weight:700;display:block;font-size:clamp(.92rem,3vw,1rem)!important}
.dept-sub{font-size:clamp(.75rem,2.5vw,.8rem)!important;color:var(--muted,#8892b0);margin-top:.08rem}

.progress-wrap{min-width:80px}
.progress{height:6px;background:rgba(255,255,255,.07);border-radius:999px;overflow:hidden;margin-top:.35rem}
.progress-bar{height:100%;border-radius:999px;background:var(--red,#e63946);transition:width .4s ease}
.progress-bar.green{background:var(--green,#2dc653)}
.progress-bar.gold{background:var(--gold,#f0a500)}

.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .65rem;border-radius:999px;font-size:clamp(.76rem,2.8vw,.82rem)!important;font-weight:700;white-space:nowrap}
.badge-active{background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.25)}
.badge-inactive{background:rgba(255,255,255,.07);color:var(--muted,#8892b0);border:1px solid rgba(255,255,255,.1)}
.badge-full{background:rgba(230,57,70,.12);color:#e63946;border:1px solid rgba(230,57,70,.25)}
.badge-warn{background:rgba(240,165,0,.12);color:#f0a500;border:1px solid rgba(240,165,0,.25)}

.btn{min-height:38px;font-size:clamp(.88rem,3vw,.95rem)!important;font-weight:700!important;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;transition:all .18s;text-decoration:none;padding:.45rem .9rem;cursor:pointer;border:none;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-sm{min-height:34px;padding:.35rem .75rem}
.btn-primary{background:#e63946;color:#fff}
.btn-primary:hover{background:#cf2f3b}
.btn-ghost{background:rgba(255,255,255,.04);color:var(--text,#e2e8f0);border:1px solid var(--border,#1e2536)}
.btn-action-text{display:inline-flex;align-items:center;gap:.28rem;font-size:.78rem!important;font-weight:700;padding:.3rem .58rem;min-height:28px;border-radius:8px;text-decoration:none;transition:all .18s;cursor:pointer;border:none;white-space:nowrap;line-height:1}
.btn-edit-dept{background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.25)}
.btn-edit-dept:hover{background:rgba(59,130,246,.22);color:#60a5fa}
.btn-del-dept{background:rgba(230,57,70,.1);color:#e63946;border:1px solid rgba(230,57,70,.25)}
.btn-del-dept:hover{background:rgba(230,57,70,.2);color:#ff5a66}

.form-label{font-size:clamp(.92rem,3.5vw,1rem)!important;font-weight:700;display:block;margin-bottom:.4rem}
.form-control{font-size:clamp(.92rem,3.5vw,1rem)!important;min-height:44px;padding:.65rem .9rem;border-radius:10px!important;transition:border-color .2s,box-shadow .2s;width:100%;background:var(--input-bg,#0f1219);border:1px solid var(--border,#1e2536);color:var(--text,#e2e8f0)}
.form-control:focus{outline:none;border-color:var(--red,#e63946)!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
select.form-control{cursor:pointer}
.form-section-title{font-size:clamp(.8rem,3vw,.88rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted,#8892b0);margin-bottom:.75rem;display:flex;align-items:center;gap:.45rem;padding-bottom:.5rem;border-bottom:1px solid var(--border,#1e2536)}
.form-row{display:grid;gap:.85rem}
.form-row.col-2{grid-template-columns:1fr 1fr}
@media(max-width:700px){.form-row.col-2{grid-template-columns:1fr!important}}

.nav-item{min-height:46px!important;font-size:clamp(.92rem,3vw,1rem)!important;font-weight:600!important;padding:.65rem .9rem!important;border-radius:10px!important;display:flex!important;align-items:center!important;gap:.7rem!important;transition:background .15s,color .15s!important;text-decoration:none}
.nav-item .icon{width:22px;text-align:center;font-size:1rem;flex-shrink:0}
.sidebar-school{margin:.25rem 1rem!important;padding:0!important;display:flex!important;align-items:center!important;justify-content:flex-start!important;text-align:left!important;font-weight:700!important;font-size:clamp(.82rem,3vw,.92rem)!important;line-height:1.25!important;color:var(--text,#f0f2ff)!important;white-space:normal!important;overflow:visible!important;text-overflow:unset!important;overflow-wrap:anywhere!important;word-break:break-word!important;background:none!important;border:none!important;box-shadow:none!important;border-radius:0!important;transform:none!important;filter:none!important}
.sidebar-school:hover,.sidebar-school:focus,.sidebar-school:active{background:none!important;border:none!important;box-shadow:none!important;transform:none!important;outline:none!important;filter:none!important}

@media(max-width:700px){.col-hide-mobile{display:none!important}}

.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);z-index:10000;align-items:center;justify-content:center;padding:1rem}
.modal-backdrop.open{display:flex}
@keyframes modalIn{from{opacity:0;transform:translateY(22px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-box{
  background:var(--card-bg,#131929);
  border:1px solid var(--border,#1e2536);
  border-radius:22px;
  width:100%;
  max-width:540px;
  max-height:90vh;
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  animation:modalIn .28s cubic-bezier(.2,.8,.2,1) both;
  box-shadow:0 24px 80px rgba(0,0,0,.6);
  scrollbar-width:thin;
  scrollbar-color:#e63946 rgba(255,255,255,.08);
}
.modal-box::-webkit-scrollbar{width:10px}
.modal-box::-webkit-scrollbar-track{background:rgba(255,255,255,.06);border-radius:999px}
.modal-box::-webkit-scrollbar-thumb{background:linear-gradient(180deg,#e63946,#c92e3a);border-radius:999px;border:2px solid rgba(19,25,41,.95)}
.modal-box::-webkit-scrollbar-thumb:hover{background:linear-gradient(180deg,#ff4d5a,#d12f3b)}

.detail-modal-box{
  background:var(--card-bg,#131929);
  border:1px solid var(--border,#1e2536);
  border-radius:22px;
  width:100%;
  max-width:680px;
  max-height:90vh;
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  animation:modalIn .28s cubic-bezier(.2,.8,.2,1) both;
  box-shadow:0 24px 80px rgba(0,0,0,.6);
  scrollbar-width:thin;
  scrollbar-color:#e63946 rgba(255,255,255,.08);
}
.detail-modal-box::-webkit-scrollbar{width:10px}
.detail-modal-box::-webkit-scrollbar-track{background:rgba(255,255,255,.06);border-radius:999px}
.detail-modal-box::-webkit-scrollbar-thumb{background:linear-gradient(180deg,#e63946,#c92e3a);border-radius:999px;border:2px solid rgba(19,25,41,.95)}
.detail-modal-box::-webkit-scrollbar-thumb:hover{background:linear-gradient(180deg,#ff4d5a,#d12f3b)}

@media(max-width:600px){
  .modal-box,
  .detail-modal-box{
    scrollbar-width:thin;
    scrollbar-color:#e63946 rgba(255,255,255,.1);
  }
}

.modal-header{display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border,#1e2536);position:sticky;top:0;background:var(--card-bg,#131929);z-index:1;border-radius:22px 22px 0 0}
.modal-title{font-size:clamp(1rem,3.5vw,1.15rem)!important;font-weight:800;display:flex;align-items:center;gap:.5rem}
.modal-close{width:36px;height:36px;border-radius:10px;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:all .18s;flex-shrink:0}
.modal-close:hover{background:rgba(230,57,70,.12);border-color:var(--red,#e63946);color:var(--red,#e63946)}
.modal-body{padding:1.25rem}
.modal-footer{padding:.9rem 1.25rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.6rem;justify-content:flex-end;flex-wrap:wrap}
@keyframes checkPop{0%{transform:scale(0)}70%{transform:scale(1.2)}100%{transform:scale(1)}}
.modal-success{display:none;text-align:center;padding:2rem 1rem}
.modal-success.show{display:block}
.modal-success .check-circle{width:64px;height:64px;background:rgba(45,198,83,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:var(--green,#2dc653);margin:0 auto 1rem;animation:checkPop .35s cubic-bezier(.2,.8,.2,1) both}

.detail-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);z-index:10100;align-items:center;justify-content:center;padding:1rem}
.detail-modal-backdrop.open{display:flex}
@media(max-width:600px){
  .detail-modal-box{max-width:100%;max-height:92dvh;border-radius:20px 20px 0 0}
  .detail-modal-backdrop{align-items:flex-end!important;padding:0!important}
}

.detail-dept-header{padding:1.4rem 1.4rem .9rem;border-bottom:1px solid var(--border,#1e2536);display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;position:sticky;top:0;background:var(--card-bg,#131929);z-index:2;border-radius:22px 22px 0 0}
@media(max-width:600px){.detail-dept-header{border-radius:20px 20px 0 0}}
.detail-dept-icon{width:52px;height:52px;min-width:52px;border-radius:14px;background:rgba(240,165,0,.12);border:1px solid rgba(240,165,0,.2);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#f0a500;flex-shrink:0}
.detail-dept-name{font-size:clamp(1.1rem,4.5vw,1.4rem)!important;font-weight:800;line-height:1.2;margin-bottom:.25rem}
.detail-dept-meta{font-size:clamp(.82rem,3vw,.9rem)!important;color:var(--muted,#8892b0);display:flex;flex-wrap:wrap;gap:.35rem .75rem;margin-top:.2rem}
.detail-dept-meta span{display:flex;align-items:center;gap:.3rem}

.detail-body{padding:1.1rem 1.4rem}
.detail-section-title{font-size:clamp(.78rem,2.8vw,.84rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted,#8892b0);margin-bottom:.65rem;display:flex;align-items:center;gap:.4rem;padding-bottom:.45rem;border-bottom:1px solid var(--border,#1e2536)}
.detail-info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.5rem .75rem;margin-bottom:1.1rem}
@media(max-width:480px){.detail-info-grid{grid-template-columns:1fr}}
.detail-info-item{background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.05);border-radius:10px;padding:.55rem .75rem}
.detail-info-lbl{font-size:clamp(.72rem,2.5vw,.78rem)!important;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted,#8892b0);margin-bottom:.18rem}
.detail-info-val{font-size:clamp(.9rem,3.2vw,.98rem)!important;font-weight:700;color:var(--text,#e2e8f0)}

.detail-capacity-bar{height:10px;background:rgba(255,255,255,.07);border-radius:999px;overflow:hidden;margin:.5rem 0}
.detail-capacity-fill{height:100%;border-radius:999px;transition:width .5s ease}

.detail-athlete-row{display:flex;align-items:center;gap:.65rem;padding:.55rem .75rem;border-radius:10px;transition:background .15s;text-decoration:none;color:inherit}
.detail-athlete-row:hover{background:rgba(255,255,255,.04)}
.detail-athlete-avatar{width:34px;height:34px;min-width:34px;border-radius:50%;background:rgba(230,57,70,.14);border:1px solid rgba(230,57,70,.25);display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:800;color:var(--red,#e63946);flex-shrink:0}
.detail-athlete-name{font-size:clamp(.88rem,3vw,.95rem)!important;font-weight:700;line-height:1.2}
.detail-athlete-sub{font-size:clamp(.72rem,2.5vw,.78rem)!important;color:var(--muted,#8892b0);margin-top:.08rem}
.detail-empty{text-align:center;padding:1.5rem 1rem;color:var(--muted,#8892b0);font-size:.9rem}
.detail-empty i{display:block;font-size:1.75rem;margin-bottom:.5rem;opacity:.35}

.detail-footer{padding:.85rem 1.4rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;justify-content:space-between}
@media(max-width:600px){
    .detail-footer{flex-direction:column!important;gap:.5rem!important;}
    .detail-footer>button,
    .detail-footer>a{width:100%!important;justify-content:center!important;}
    #dm_footer_actions{
        display:grid!important;
        grid-template-columns:1fr 1fr!important;
        gap:.45rem!important;
        width:100%!important;
    }
    #dm_footer_actions .btn{
        justify-content:center!important;
        font-size:.78rem!important;
        padding:.45rem .5rem!important;
        min-height:38px!important;
    }
}

.pg-btn{
  min-width:34px;height:34px;border-radius:8px;border:1px solid var(--border,#1e2536);
  background:rgba(255,255,255,.03);color:var(--text,#e2e8f0);cursor:pointer;font-weight:700
}
.pg-btn.active{background:rgba(230,57,70,.12);border-color:rgba(230,57,70,.35);color:#e63946}
</style>

<body>
<div class="app-layout">
<?php renderSidebar('departments'); ?>
<div id="dm-overlay"></div>

<div class="main-content">
<?php renderTopbar('Τμήματα'); ?>

<div class="page-body">

<div class="stat-cards-row anim-1">
    <?php foreach ([
        ['Ενεργά', $totalActive, 'icon-green', 'fa-folder-open'],
        ['Ανενεργά', $totalInactive, 'icon-red', 'fa-folder'],
    ] as [$lbl, $val, $ico, $fa]): ?>
    <div class="stat-card card">
        <div class="stat-icon <?= $ico ?>"><i class="fa-solid <?= $fa ?>"></i></div>
        <div class="stat-text">
            <div class="stat-lbl"><?= $lbl ?></div>
            <div class="stat-val"><?= $val ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="page-header anim-2">
    <h2>
        <i class="fa-solid fa-folder-open" style="color:var(--red,#e63946)"></i>
        Τμήματα
        <span style="font-size:clamp(.82rem,3vw,.9rem);font-weight:600;color:var(--muted,#8892b0)" id="deptCount">(<?= count($deptList) ?>)</span>
    </h2>
    <button type="button" id="openDeptModal" class="btn btn-primary btn-sm" onclick="openDeptCreateModal()">
        <i class="fa-solid fa-plus"></i> Νέο Τμήμα
    </button>
</div>

<div class="filters-bar anim-2">
    <div class="search-bar">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="search" id="deptSearch" placeholder="Αναζήτηση τμήματος..." autocomplete="off">
    </div>
</div>

<div class="card anim-3 p-0">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;border-bottom:1px solid var(--border,#1e2536);flex-wrap:wrap;gap:.4rem">
        <span style="font-size:clamp(.82rem,3vw,.9rem);color:var(--muted,#8892b0)" id="deptCountLabel">
            <?= count($deptList) ?> τμήματα
        </span>
        <span style="font-size:clamp(.78rem,2.5vw,.84rem);color:var(--muted,#8892b0)">
            <i class="fa-solid fa-hand-pointer" style="opacity:.5;margin-right:.3rem"></i>Κάντε κλικ για λεπτομέρειες
        </span>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Τμήμα</th>
                    <th>Άθλημα</th>
                    <th class="col-hide-sm">Πρόγραμμα</th>
                    <th>Αθλητές</th>
                    <th class="col-hide-sm">Συνδρομή</th>
                    <th>Κατάσταση</th>
                    <th>Ενέργειες</th>
                </tr>
            </thead>
            <tbody id="deptTableBody">
                <?php foreach ($deptList as $d):
                    $pct      = (int)$d['max_athletes'] > 0 ? min(100, round((int)$d['athlete_count'] / (int)$d['max_athletes'] * 100)) : 0;
                    $full     = $pct >= 100;
                    $warn     = $pct >= 80;
                    $barClass = $full ? '' : ($warn ? 'gold' : 'green');
                    $dJson    = htmlspecialchars(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                    $athJson  = htmlspecialchars(json_encode($athletesByDept[$d['id']] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                ?>
                <tr class="dept-row"
                    data-name="<?= htmlspecialchars(mb_strtolower((string)$d['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>"
                    data-search="<?= htmlspecialchars(
                        mb_strtolower(trim(
                            (string)$d['name'] . ' ' .
                            sportLabel((string)($d['sport'] ?? '')) . ' ' .
                            (string)($d['schedule'] ?? '')
                        ), 'UTF-8'), ENT_QUOTES, 'UTF-8'
                    ) ?>"
                    data-status="<?= $d['active'] ? 'active' : 'inactive' ?>"
                    onclick="openDetailModal(<?= $dJson ?>, <?= $athJson ?>)">

                    <td>
                        <span class="dept-name-cell">
                            <i class="fa-solid fa-folder<?= $d['active'] ? '-open' : '' ?>"
                               style="color:<?= $d['active'] ? 'var(--gold,#f0a500)' : 'var(--muted,#8892b0)' ?>;margin-right:.4rem;font-size:.9em"></i>
                            <?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php if (!empty($d['schedule'])): ?>
                        <span class="dept-sub d-mobile-show" style="display:none">
                            <i class="fa-regular fa-calendar" style="opacity:.6"></i> <?= htmlspecialchars($d['schedule'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php endif; ?>
                    </td>

                    <td style="font-size:clamp(.86rem,3vw,.93rem)!important"><?= sportLabel((string)($d['sport'] ?? 'other')) ?></td>

                    <td class="col-hide-sm" style="color:var(--muted,#8892b0);font-size:clamp(.86rem,3vw,.93rem)!important;max-width:180px;overflow:hidden;text-overflow:ellipsis">
                        <?= !empty($d['schedule']) ? htmlspecialchars($d['schedule'], ENT_QUOTES, 'UTF-8') : '—' ?>
                    </td>

                    <td>
                        <div style="display:flex;align-items:baseline;gap:.3rem">
                            <span style="font-weight:800;color:<?= $full ? 'var(--red,#e63946)' : ($warn ? 'var(--gold,#f0a500)' : 'var(--text,#e2e8f0)') ?>">
                                <?= (int)$d['athlete_count'] ?>
                            </span>
                            <?php if ((int)$d['max_athletes'] > 0): ?>
                            <span style="font-size:.8em;color:var(--muted,#8892b0)">/ <?= (int)$d['max_athletes'] ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ((int)$d['max_athletes'] > 0): ?>
                        <div class="progress-wrap" style="min-width:70px">
                            <div class="progress">
                                <div class="progress-bar <?= $barClass ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </td>

                    <td class="col-hide-sm" style="font-weight:700">
                        <?= (float)$d['monthly_fee'] > 0 ? number_format((float)$d['monthly_fee'], 2, ',', '.') . '€' : '—' ?>
                    </td>

                    <td>
                        <?php if ($full): ?>
                            <span class="badge badge-full"><i class="fa-solid fa-circle-exclamation"></i> Πλήρες</span>
                        <?php elseif ($warn): ?>
                            <span class="badge badge-warn"><i class="fa-solid fa-triangle-exclamation"></i> Σχεδόν πλήρες</span>
                        <?php elseif ($d['active']): ?>
                            <span class="badge badge-active"><i class="fa-solid fa-circle-check"></i> Ενεργό</span>
                        <?php else: ?>
                            <span class="badge badge-inactive"><i class="fa-solid fa-circle-xmark"></i> Ανενεργό</span>
                        <?php endif; ?>
                    </td>

                    <td onclick="event.stopPropagation()">
                        <div style="display:flex;gap:.3rem;align-items:center;flex-wrap:wrap">
                            <button type="button"
                                    class="btn-action-text btn-edit-dept"
                                    onclick='openEditModal(<?= $dJson ?>)'>
                                <i class="fa-solid fa-pen-to-square"></i> Επεξεργασία
                            </button>

                            <?php if ($d['active']): ?>
                            <button type="button"
                                    class="btn-action-text"
                                    style="background:rgba(240,165,0,.1);color:#f0a500;border:1px solid rgba(240,165,0,.25)"
                                    onclick="confirmDeleteDept(<?= (int)$d['id'] ?>, '<?= addslashes(htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8')) ?>')">
                                <i class="fa-solid fa-toggle-off"></i> Απενεργοποίηση
                            </button>
                            <?php else: ?>
                            <button type="button"
                                    class="btn-action-text"
                                    style="background:rgba(45,198,83,.1);color:#2dc653;border:1px solid rgba(45,198,83,.25)"
                                    onclick="confirmActivateDept(<?= (int)$d['id'] ?>, '<?= addslashes(htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8')) ?>', '<?= htmlspecialchars((string)($d['schedule'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', <?= (int)$d['max_athletes'] ?>, '<?= htmlspecialchars((string)$d['monthly_fee'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars((string)($d['sport'] ?? 'other'), ENT_QUOTES, 'UTF-8') ?>', '<?= addslashes(htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8')) ?>')">
                                <i class="fa-solid fa-toggle-on"></i> Ενεργοποίηση
                            </button>
                            <?php endif; ?>

                            <button type="button"
                                    class="btn-action-text btn-del-ath"
                                    style="background:rgba(230,57,70,.1);color:#e63946;border:1px solid rgba(230,57,70,.25)"
                                    onclick="confirmHardDeleteDept(<?= (int)$d['id'] ?>, '<?= addslashes(htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8')) ?>')">
                                <i class="fa-solid fa-trash"></i> Διαγραφή
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (!$deptList): ?>
                <tr>
                    <td colspan="7">
                        <div style="text-align:center;padding:2.25rem 1rem">
                            <div style="font-size:2.5rem;margin-bottom:.65rem;opacity:.4"><i class="fa-solid fa-folder-open"></i></div>
                            <p style="color:var(--muted,#8892b0);margin:0">Δεν υπάρχουν τμήματα — δημιουργήστε το πρώτο!</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($deptList): ?>
    <div id="deptNoResults" style="display:none;text-align:center;padding:2rem 1rem;color:var(--muted,#8892b0)">
        <i class="fa-solid fa-magnifying-glass" style="display:block;font-size:1.75rem;margin-bottom:.5rem;opacity:.4"></i>
        Δεν βρέθηκαν τμήματα
    </div>
    <?php endif; ?>
</div>

</div>
</div>
</div>

<!-- DETAIL MODAL -->
<div class="detail-modal-backdrop" id="detailModal" onclick="if(event.target===this)closeDetailModal()">
    <div class="detail-modal-box">
        <div class="detail-dept-header">
            <div style="display:flex;align-items:center;gap:.85rem;flex:1;min-width:0">
                <div class="detail-dept-icon" id="dm_icon"><i class="fa-solid fa-folder-open"></i></div>
                <div style="min-width:0">
                    <div class="detail-dept-name" id="dm_name">—</div>
                    <div class="detail-dept-meta" id="dm_meta"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeDetailModal()" aria-label="Κλείσιμο" style="flex-shrink:0">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="detail-body">
            <div id="dm_capacity_section" style="margin-bottom:1.1rem">
                <div class="detail-section-title"><i class="fa-solid fa-gauge-high"></i> Πληρότητα</div>
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.35rem">
                    <span style="font-size:.92rem;color:var(--muted,#8892b0)" id="dm_capacity_label">0 / 0 αθλητές</span>
                    <span style="font-size:.92rem;font-weight:800" id="dm_capacity_pct">0%</span>
                </div>
                <div class="detail-capacity-bar">
                    <div class="detail-capacity-fill" id="dm_capacity_fill" style="width:0%;background:var(--green,#2dc653)"></div>
                </div>
            </div>

            <div class="detail-section-title"><i class="fa-solid fa-sliders"></i> Στοιχεία</div>
            <div class="detail-info-grid" id="dm_info_grid"></div>

            <div class="detail-section-title" style="margin-top:.25rem">
                <i class="fa-solid fa-users"></i> Αθλητές στο τμήμα
                <span id="dm_ath_count" style="margin-left:.4rem;font-size:.8rem;font-weight:600;color:var(--muted,#8892b0)"></span>
            </div>

            <div id="dm_ath_search_wrap" style="display:none;margin-bottom:.6rem;position:relative">
                <span style="position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--muted,#8892b0);pointer-events:none;font-size:.82rem">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </span>
                <input type="search" id="dm_ath_search" placeholder="Αναζήτηση αθλητή..." autocomplete="off"
                    style="width:100%;padding:.42rem .7rem .42rem 2rem;font-size:clamp(.82rem,3vw,.9rem);height:36px;border-radius:9px;background:var(--input-bg,#0f1219);border:1px solid var(--border,#1e2536);color:var(--text,#e2e8f0);outline:none;transition:border-color .18s"
                    onfocus="this.style.borderColor='var(--red,#e63946)'"
                    onblur="this.style.borderColor='var(--border,#1e2536)'">
            </div>

            <div id="dm_athletes_list"></div>
            <div id="dm_ath_pagination" style="display:flex;align-items:center;justify-content:center;gap:.3rem;flex-wrap:wrap;padding:.5rem 0 0"></div>
        </div>

        <div class="detail-footer">
            <button type="button" onclick="closeDetailModal()" class="btn btn-ghost btn-sm">
                <i class="fa-solid fa-xmark"></i> Κλείσιμο
            </button>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap" id="dm_footer_actions"></div>
        </div>
    </div>
</div>

<!-- SAVE / EDIT MODAL -->
<div class="modal-backdrop" id="deptModal" role="dialog" aria-modal="true" aria-labelledby="deptModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="deptModalTitle">
                <i class="fa-solid fa-folder-open" id="modalTitleIcon" style="color:var(--green,#2dc653)"></i>
                <span id="modalTitleText">Νέο Τμήμα</span>
            </div>
            <button type="button" class="modal-close" id="closeDeptModal" aria-label="Κλείσιμο">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div id="deptModalForm">
            <form method="POST" id="deptForm">
                <input type="hidden" name="_action" value="save_dept">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="id" id="m_id" value="">

                <div class="modal-body">
                    <div class="form-section-title"><i class="fa-solid fa-folder-open"></i> Βασικά Στοιχεία</div>

                    <div style="margin-bottom:.75rem">
                        <label class="form-label" for="m_name">
                            <i class="fa-solid fa-tag" style="color:var(--red,#e63946);margin-right:.3rem"></i>Όνομα Τμήματος <span style="color:var(--red,#e63946)">*</span>
                        </label>
                        <input id="m_name" name="name" class="form-control" placeholder="π.χ. Τμήμα Ενηλίκων Α" required>
                    </div>

                    <div style="margin-bottom:.85rem">
                        <label class="form-label" for="m_schedule">
                            <i class="fa-regular fa-calendar" style="color:#3b82f6;margin-right:.3rem"></i>Πρόγραμμα
                        </label>
                        <input id="m_schedule" name="schedule" class="form-control" placeholder="π.χ. Δευτ-Τετ-Παρ 17:00-18:30">
                    </div>

                    <div class="form-section-title" style="margin-top:.85rem"><i class="fa-solid fa-sliders"></i> Ρυθμίσεις</div>

                    <div class="form-row col-2">
                        <div>
                            <label class="form-label" for="m_max_athletes">
                                <i class="fa-solid fa-users" style="color:var(--gold,#f0a500);margin-right:.3rem"></i>Μέγ. Αθλητές
                            </label>
                            <input type="number" id="m_max_athletes" name="max_athletes" class="form-control" value="30" min="0">
                        </div>

                        <div>
                            <label class="form-label" for="m_monthly_fee">
                                <i class="fa-solid fa-euro-sign" style="color:var(--green,#2dc653);margin-right:.3rem"></i>Μην. Συνδρομή (€)
                            </label>
                            <input type="number" step=".01" id="m_monthly_fee" name="monthly_fee" class="form-control" value="0" placeholder="0.00">
                        </div>

                        <div>
                            <label class="form-label" for="m_sport">
                                <i class="fa-solid fa-person-running" style="color:#3b82f6;margin-right:.3rem"></i>Άθλημα
                            </label>
                            <select id="m_sport" name="sport" class="form-control">
                                <option value="taekwondo_wtf">Taekwondo (WTF / WT)</option>
                                <option value="taekwondo_itf">Taekwondo (ITF)</option>
                                <option value="taekwondo">Taekwondo</option>
                                <option value="karate_shotokan">Karate Shotokan</option>
                                <option value="karate_kyokushin">Karate Kyokushin</option>
                                <option value="karate">Karate</option>
                                <option value="bjj">Brazilian Jiu-Jitsu (BJJ)</option>
                                <option value="judo">Judo</option>
                                <option value="kickboxing">Kickboxing</option>
                                <option value="boxing">Boxing (Πυγμαχία)</option>
                                <option value="mma">MMA</option>
                                <option value="wrestling">Πάλη (Wrestling)</option>
                                <option value="pankration">Παγκράτιο</option>
                                <option value="sambo">Sambo</option>
                                <option value="other">Άλλο</option>
                            </select>
                        </div>

                        <div id="m_active_wrap" style="display:none">
                            <label class="form-label" for="m_active">
                                <i class="fa-solid fa-toggle-on" style="color:var(--green,#2dc653);margin-right:.3rem"></i>Κατάσταση
                            </label>
                            <select id="m_active" name="active" class="form-control">
                                <option value="1">Ενεργό</option>
                                <option value="0">Ανενεργό</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" id="cancelDeptModal" class="btn btn-ghost">
                        <i class="fa-solid fa-xmark"></i> Ακύρωση
                    </button>
                    <button type="submit" class="btn btn-primary" style="min-height:46px;font-size:1rem!important">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span id="modalSubmitLabel">Δημιουργία Τμήματος</span>
                    </button>
                </div>
            </form>
        </div>

        <div class="modal-success" id="deptModalSuccess">
            <div class="check-circle"><i class="fa-solid fa-check"></i></div>
            <p style="font-weight:800;font-size:1.1rem!important;color:var(--text,#e2e8f0);margin-bottom:.4rem" id="successMsg">Τμήμα δημιουργήθηκε!</p>
            <p>Γίνεται ανακατεύθυνση...</p>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteDeptModal" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title" style="color:#f0a500"><i class="fa-solid fa-toggle-off"></i> Απενεργοποίηση Τμήματος</div>
            <button type="button" class="modal-close" onclick="closeDeleteDeptModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:clamp(.95rem,3.5vw,1rem)!important;margin:0 0 .5rem">Να απενεργοποιηθεί το τμήμα <strong id="deleteDeptName" style="color:var(--text,#e2e8f0)"></strong>;</p>
            <p style="font-size:.88rem!important;color:var(--muted,#8892b0);margin:0">Μπορείτε να το ξανά ενεργοποιήσετε από το κουμπί <strong>Ενεργοποίηση</strong> στη λίστα.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeDeleteDeptModal()"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
            <form method="POST" id="deleteDeptForm" style="display:inline">
                <input type="hidden" name="_action" value="delete_dept">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="id" id="deleteDeptId" value="">
                <button type="submit" class="btn btn-primary" style="background:#f0a500;color:#0a0d16"><i class="fa-solid fa-toggle-off"></i> Απενεργοποίηση</button>
            </form>
        </div>
    </div>
</div>

<!-- ACTIVATE MODAL (Bug 7 Fix: Beautiful activation confirm modal) -->
<div class="modal-backdrop" id="activateDeptModal" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title" style="color:#2dc653"><i class="fa-solid fa-toggle-on"></i> Ενεργοποίηση Τμήματος</div>
            <button type="button" class="modal-close" onclick="closeActivateDeptModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
                <div style="width:52px;height:52px;min-width:52px;border-radius:14px;background:rgba(45,198,83,.15);border:1px solid rgba(45,198,83,.3);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#2dc653;flex-shrink:0">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <div>
                    <div style="font-size:1rem;font-weight:800;color:var(--text,#e2e8f0)" id="activateDeptName"></div>
                    <div style="font-size:.85rem;color:var(--muted,#8892b0);margin-top:.2rem">Το τμήμα θα ενεργοποιηθεί και θα εμφανίζεται στη λίστα.</div>
                </div>
            </div>
            <div style="background:rgba(45,198,83,.07);border:1px solid rgba(45,198,83,.22);border-radius:12px;padding:.75rem 1rem;font-size:.88rem;color:#a8f0c0;line-height:1.65">
                <i class="fa-solid fa-circle-check" style="color:#2dc653;margin-right:.4rem"></i>
                Οι αθλητές που ανήκουν σε αυτό το τμήμα θα συνεχίσουν να το βλέπουν.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeActivateDeptModal()"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
            <form method="POST" id="activateDeptForm" style="display:inline">
                <input type="hidden" name="_action" value="save_dept">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="id" id="activateDeptId" value="">
                <input type="hidden" name="name" id="activateDeptNameInput" value="">
                <input type="hidden" name="schedule" id="activateDeptSchedule" value="">
                <input type="hidden" name="max_athletes" id="activateDeptMax" value="">
                <input type="hidden" name="monthly_fee" id="activateDeptFee" value="">
                <input type="hidden" name="sport" id="activateDeptSport" value="">
                <input type="hidden" name="active" value="1">
                <button type="submit" class="btn btn-primary" style="background:#2dc653;color:#0a0d16;min-height:46px;font-size:1rem!important">
                    <i class="fa-solid fa-toggle-on"></i> Ενεργοποίηση
                </button>
            </form>
        </div>
    </div>
</div>

<!-- HARD DELETE MODAL -->
<div class="modal-backdrop" id="hardDeleteDeptModal" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title" style="color:var(--red,#e63946)"><i class="fa-solid fa-triangle-exclamation"></i> Οριστική Διαγραφή Τμήματος</div>
            <button type="button" class="modal-close" onclick="closeHardDeleteDeptModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:clamp(.95rem,3.5vw,1rem)!important;margin:0 0 .5rem">Να διαγραφεί <strong>οριστικά</strong> το τμήμα <strong id="hardDeleteDeptName" style="color:var(--text,#e2e8f0)"></strong>;</p>
            <p style="font-size:.88rem!important;color:#e63946;font-weight:700;margin:0"><i class="fa-solid fa-exclamation-circle"></i> Η ενέργεια δεν αναιρείται. Οι αθλητές του τμήματος δεν διαγράφονται αλλά αποσυνδέονται από αυτό.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeHardDeleteDeptModal()"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
            <form method="POST" id="hardDeleteDeptForm" style="display:inline">
                <input type="hidden" name="_action" value="hard_delete_dept">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="id" id="hardDeleteDeptId" value="">
                <button type="submit" class="btn btn-primary" style="background:var(--red,#e63946)"><i class="fa-solid fa-trash"></i> Οριστική Διαγραφή</button>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    var sb=document.getElementById('sidebar'),ov=document.getElementById('dm-overlay'),mb=document.getElementById('menuBtn');
    if(!sb||!mb)return;
    function open(){sb.classList.add('open');ov&&ov.classList.add('on');document.body.style.overflow='hidden';}
    function close(){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}
    mb.onclick=function(e){e.stopPropagation();sb.classList.contains('open')?close():open();};
    ov&&ov.addEventListener('click',close);
    sb.querySelectorAll('a.nav-item').forEach(function(l){
        l.addEventListener('click',function(){if(window.innerWidth<=900)setTimeout(close,80);});
    });
    document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
    window.addEventListener('resize',function(){
        if(window.innerWidth>900){
            sb.classList.remove('open');
            ov&&ov.classList.remove('on');
            document.body.style.overflow='';
        }
    });
})();

// Live filter — Greek-friendly, accent-insensitive, fuzzy (subsequence)
// Searches across name + sport + schedule via each row's data-search.
(function(){
    var srch   = document.getElementById('deptSearch');
    var rows   = Array.from(document.querySelectorAll('#deptTableBody .dept-row'));
    var noRes  = document.getElementById('deptNoResults');
    var cntLbl = document.getElementById('deptCountLabel');

    // Normalise: strip diacritics, lowercase, unify Greek final sigma,
    // collapse whitespace. Handles Έφηβοι → εφηβοι, ώ → ω, ς → σ.
    function norm(s){
        if(!s) return '';
        return s.toString()
            .normalize('NFD')                      // separate combining marks
            .replace(/[̀-ͯ]/g, '')       // drop diacritics (tonos, diaeresis)
            .toLowerCase()
            .replace(/ς/g, 'σ')          // Greek final sigma ς → σ
            .replace(/\s+/g, ' ')
            .trim();
    }

    // Fuzzy: every char of needle appears in haystack in order.
    // Combined with substring check → tolerates typos/missing letters.
    function subseq(haystack, needle){
        var i = 0, j = 0;
        while(i < haystack.length && j < needle.length){
            if(haystack[i] === needle[j]) j++;
            i++;
        }
        return j === needle.length;
    }

    // Precompute normalised search string per row (avoids re-normalising
    // on every keystroke).
    rows.forEach(function(r){
        var raw = r.dataset.search || r.dataset.name || (r.textContent || '');
        r.__searchIdx = norm(raw);
    });

    function filter(){
        var raw = srch && srch.value ? srch.value : '';
        var q   = norm(raw);
        var shown = 0;

        // Split query into whitespace tokens — every token must match
        // (AND semantics) so multi-word queries stay predictable.
        var tokens = q ? q.split(' ').filter(Boolean) : [];

        rows.forEach(function(r){
            var hay = r.__searchIdx || '';
            var show;
            if(tokens.length === 0){
                show = true;
            } else {
                show = tokens.every(function(t){
                    // Substring wins first (cheapest, most-relevant),
                    // subsequence catches typos and skipped letters.
                    return hay.indexOf(t) >= 0 || subseq(hay, t);
                });
            }
            r.style.display = show ? '' : 'none';
            if(show) shown++;
        });

        if(cntLbl){
            cntLbl.textContent = shown + ' τμήματα';
        }

        if(noRes){
            if(rows.length === 0){
                noRes.style.display = 'none';
            } else {
                noRes.style.display = shown ? 'none' : 'block';
            }
        }
    }

    if(srch){
        srch.addEventListener('input', filter);
    }

    filter();
})();

var SPORT_LABELS = {
    taekwondo_wtf:'Taekwondo (WTF/WT)',
    taekwondo_itf:'Taekwondo (ITF)',
    taekwondo:'Taekwondo',
    karate_shotokan:'Karate Shotokan',
    karate_kyokushin:'Karate Kyokushin',
    karate:'Karate',
    kickboxing:'Kickboxing',
    boxing:'Πυγμαχία',
    mma:'MMA',
    bjj:'BJJ',
    judo:'Judo',
    wrestling:'Πάλη (Wrestling)',
    pankration:'Παγκράτιο',
    sambo:'Sambo',
    other:'Άλλο'
};

function sportLabelJs(s){return SPORT_LABELS[s] || s || '—';}
function escHtml(str){
    return String(str || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
}

function openDetailModal(dept, athletes){
    var isActive = parseInt(dept.active) === 1;

    document.getElementById('dm_icon').innerHTML = '<i class="fa-solid fa-folder-' + (isActive ? 'open' : '') + '"></i>';
    document.getElementById('dm_icon').style.background = isActive ? 'rgba(240,165,0,.12)' : 'rgba(255,255,255,.05)';
    document.getElementById('dm_icon').style.borderColor = isActive ? 'rgba(240,165,0,.2)' : 'rgba(255,255,255,.08)';
    document.getElementById('dm_icon').style.color = isActive ? '#f0a500' : '#6b7494';

    document.getElementById('dm_name').textContent = dept.name || '—';

    var metaParts = [];
    if (dept.sport) metaParts.push('<span><i class="fa-solid fa-person-running" style="opacity:.6"></i> ' + sportLabelJs(dept.sport) + '</span>');
    if (dept.schedule) metaParts.push('<span><i class="fa-regular fa-calendar" style="opacity:.6"></i> ' + escHtml(dept.schedule) + '</span>');
    document.getElementById('dm_meta').innerHTML = metaParts.join('') || '<span style="color:var(--muted,#8892b0)">Χωρίς πρόγραμμα</span>';

    var max = parseInt(dept.max_athletes) || 0;
    var cnt = parseInt(dept.athlete_count) || 0;
    var pct = max > 0 ? Math.min(100, Math.round(cnt / max * 100)) : 0;

    var fill = document.getElementById('dm_capacity_fill');
    var capSection = document.getElementById('dm_capacity_section');

    if(max > 0){
        capSection.style.display = '';
        document.getElementById('dm_capacity_label').textContent = cnt + ' / ' + max + ' αθλητές';
        document.getElementById('dm_capacity_pct').textContent = pct + '%';
        document.getElementById('dm_capacity_pct').style.color = pct >= 100 ? '#e63946' : (pct >= 80 ? '#f0a500' : '#2dc653');
        fill.style.width = pct + '%';
        fill.style.background = pct >= 100 ? '#e63946' : (pct >= 80 ? '#f0a500' : '#2dc653');
    } else {
        capSection.style.display = 'none';
    }

    var fee = parseFloat(dept.monthly_fee) || 0;
    var infoItems = [
        {lbl:'Άθλημα', val:sportLabelJs(dept.sport)},
        {lbl:'Μηνιαία Συνδρομή', val:fee > 0 ? fee.toLocaleString('el-GR', {minimumFractionDigits:2}) + '€' : '—'},
        {lbl:'Μέγ. Αθλητές', val:max > 0 ? max : 'Απεριόριστο'},
        {lbl:'Ενεργοί Αθλητές', val:cnt},
        {lbl:'Πρόγραμμα', val:dept.schedule || '—'},
        {lbl:'Κατάσταση', val:isActive ? '✓ Ενεργό' : '✗ Ανενεργό'}
    ];

    document.getElementById('dm_info_grid').innerHTML = infoItems.map(function(item){
        return '<div class="detail-info-item"><div class="detail-info-lbl">' + item.lbl + '</div><div class="detail-info-val">' + escHtml(item.val) + '</div></div>';
    }).join('');

    var athContainer = document.getElementById('dm_athletes_list');
    var _dmAth = {all:[], filtered:[], pg:1, PER:10};

    function buildAthRow(a){
        var initials = (a.full_name || '?').charAt(0).toUpperCase();
        var sub = [sportLabelJs(a.sport)].filter(Boolean).join(' · ');
        var phone = a.parent_phone || a.phone || '';
        return '<a href="' + window.location.pathname.replace('departments.php','athletes.php') + '?view=' + a.id + '" class="detail-athlete-row" onclick="event.stopPropagation()">' +
            '<div class="detail-athlete-avatar">' + escHtml(initials) + '</div>' +
            '<div style="flex:1;min-width:0"><div class="detail-athlete-name">' + escHtml(a.full_name) + '</div>' + (sub ? '<div class="detail-athlete-sub">' + escHtml(sub) + '</div>' : '') + '</div>' +
            (phone ? '<span style="font-size:.78rem;color:var(--muted,#8892b0);flex-shrink:0;padding-left:.4rem">' + escHtml(phone) + '</span>' : '') +
        '</a>';
    }

    function renderAthList(){
        var s = _dmAth;
        var page = s.filtered.slice((s.pg - 1) * s.PER, s.pg * s.PER);
        var tot = Math.ceil(s.filtered.length / s.PER) || 1;

        if(s.filtered.length === 0){
            athContainer.innerHTML = '<div class="detail-empty"><i class="fa-solid fa-magnifying-glass"></i>Δεν βρέθηκαν αθλητές</div>';
        } else {
            athContainer.innerHTML = page.map(buildAthRow).join('');
        }

        var pgW = document.getElementById('dm_ath_pagination');
        pgW.innerHTML = '';
        if(tot <= 1) return;

        var ph = '';
        if(s.pg > 1) ph += '<button class="pg-btn" data-p="1">&laquo;</button><button class="pg-btn" data-p="' + (s.pg - 1) + '">&lsaquo;</button>';
        for(var i = Math.max(1, s.pg - 2); i <= Math.min(tot, s.pg + 2); i++){
            ph += '<button class="pg-btn' + (i === s.pg ? ' active' : '') + '" data-p="' + i + '">' + i + '</button>';
        }
        if(s.pg < tot) ph += '<button class="pg-btn" data-p="' + (s.pg + 1) + '">&rsaquo;</button><button class="pg-btn" data-p="' + tot + '">&raquo;</button>';

        pgW.innerHTML = ph;
        pgW.querySelectorAll('.pg-btn').forEach(function(b){
            b.addEventListener('click', function(){
                _dmAth.pg = parseInt(b.dataset.p);
                renderAthList();
            });
        });
    }

    var sw = document.getElementById('dm_ath_search_wrap');
    var si = document.getElementById('dm_ath_search');
    document.getElementById('dm_ath_count').textContent = '(' + (athletes ? athletes.length : 0) + ')';

    if(!athletes || athletes.length === 0){
        athContainer.innerHTML = '<div class="detail-empty"><i class="fa-solid fa-users"></i>Δεν υπάρχουν αθλητές στο τμήμα</div>';
        sw.style.display = 'none';
        document.getElementById('dm_ath_pagination').innerHTML = '';
    } else {
        sw.style.display = athletes.length > _dmAth.PER ? 'block' : 'none';
        if(si) si.value = '';
        _dmAth.all = athletes;
        _dmAth.filtered = athletes.slice();
        _dmAth.pg = 1;
        renderAthList();

        if(si){
            si.oninput = function(){
                var q = this.value.toLowerCase().trim();
                _dmAth.filtered = q ? _dmAth.all.filter(function(a){
                    return String(a.full_name || '').toLowerCase().indexOf(q) >= 0;
                }) : _dmAth.all.slice();
                _dmAth.pg = 1;
                renderAthList();
            };
        }
    }

    // BUG 11 FIX: Add athlete button to dept detail modal footer
var addAthleteUrl = window.location.pathname.replace('departments.php','athletes.php') + '?action=add&department_id=' + dept.id;    document.getElementById('dm_footer_actions').innerHTML =
'<button type="button" class="btn btn-sm" style="background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.3);font-weight:700" onclick="closeDetailModal();setTimeout(function(){openAddAthleteDeptModal(' + dept.id + ',\'' + escHtml(dept.name||'') + '\')},120)"><i class="fa-solid fa-user-plus"></i> Προσθήκη Αθλητή</button>' +

        '<button type="button" class="btn btn-sm" style="background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.25);font-weight:700" onclick=\'event.stopPropagation();closeDetailModal();openEditModal(' + JSON.stringify(dept).replace(/'/g, '&#39;') + ')\'><i class="fa-solid fa-pen-to-square"></i> Επεξεργασία</button>' +
        '<button type="button" class="btn btn-sm" style="background:rgba(230,57,70,.1);color:#e63946;border:1px solid rgba(230,57,70,.25);font-weight:700" onclick="event.stopPropagation();closeDetailModal();confirmDeleteDept(' + dept.id + ', \'' + escHtml(dept.name || '') + '\')"><i class="fa-solid fa-trash"></i> Απενεργοποίηση</button>';

    document.getElementById('detailModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeDetailModal(){
    document.getElementById('detailModal').classList.remove('open');
    document.body.style.overflow = '';
}

function confirmDeleteDept(id, name){
    document.getElementById('deleteDeptId').value = id;
    document.getElementById('deleteDeptName').textContent = name;
    document.getElementById('deleteDeptModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDeleteDeptModal(){
    document.getElementById('deleteDeptModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('deleteDeptModal').addEventListener('click', function(e){
    if(e.target === this) closeDeleteDeptModal();
});

function confirmHardDeleteDept(id, name){
    document.getElementById('hardDeleteDeptId').value = id;
    document.getElementById('hardDeleteDeptName').textContent = name;
    document.getElementById('hardDeleteDeptModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeHardDeleteDeptModal(){
    document.getElementById('hardDeleteDeptModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('hardDeleteDeptModal').addEventListener('click', function(e){
    if(e.target === this) closeHardDeleteDeptModal();
});

// BUG 7 FIX: Activate dept modal JS
function confirmActivateDept(id, name, schedule, maxAthletes, monthlyFee, sport) {
    document.getElementById('activateDeptId').value = id;
    document.getElementById('activateDeptNameInput').value = name;
    document.getElementById('activateDeptSchedule').value = schedule;
    document.getElementById('activateDeptMax').value = maxAthletes;
    document.getElementById('activateDeptFee').value = monthlyFee;
    document.getElementById('activateDeptSport').value = sport;
    document.getElementById('activateDeptName').textContent = name;
    document.getElementById('activateDeptModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeActivateDeptModal(){
    document.getElementById('activateDeptModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('activateDeptModal').addEventListener('click', function(e){
    if(e.target === this) closeActivateDeptModal();
});

// Save/Edit modal
(function(){
    var modal      = document.getElementById('deptModal');
    var openBtn    = document.getElementById('openDeptModal');
    var closeBtn   = document.getElementById('closeDeptModal');
    var cancelBtn  = document.getElementById('cancelDeptModal');
    var form       = document.getElementById('deptForm');
    var formWrap   = document.getElementById('deptModalForm');
    var success    = document.getElementById('deptModalSuccess');
    var titleIcon  = document.getElementById('modalTitleIcon');
    var titleText  = document.getElementById('modalTitleText');
    var submitLbl  = document.getElementById('modalSubmitLabel');
    var successMsg = document.getElementById('successMsg');
    var activeWrap = document.getElementById('m_active_wrap');

    function resetForm(){
        if(!form) return;
        form.reset();
        document.getElementById('m_id').value = '';
        document.getElementById('m_name').value = '';
        document.getElementById('m_schedule').value = '';
        document.getElementById('m_max_athletes').value = '30';
        document.getElementById('m_monthly_fee').value = '0';
        document.getElementById('m_sport').value = 'taekwondo_wtf';
        document.getElementById('m_active').value = '1';

        activeWrap.style.display = 'none';
        titleIcon.style.color = 'var(--green,#2dc653)';
        titleText.textContent = 'Νέο Τμήμα';
        submitLbl.textContent = 'Δημιουργία Τμήματος';
        successMsg.textContent = 'Τμήμα δημιουργήθηκε!';
        formWrap.style.display = '';
        success.classList.remove('show');
    }

    function openModal(){
        if(!modal) return;
        resetForm();
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        setTimeout(function(){
            var f = document.getElementById('m_name');
            if(f) f.focus();
        }, 80);
    }

    function closeModal(){
        if(!modal) return;
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    window.openDeptCreateModal = openModal;
    window.closeDeptCreateModal = closeModal;

    if(openBtn) openBtn.addEventListener('click', function(e){
        e.preventDefault();
        openModal();
    });

    if(closeBtn) closeBtn.addEventListener('click', closeModal);
    if(cancelBtn) cancelBtn.addEventListener('click', closeModal);

    if(modal){
        modal.addEventListener('click', function(e){
            if(e.target === modal) closeModal();
        });
    }

    if(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            formWrap.style.display = 'none';
            success.classList.add('show');
            setTimeout(function(){ form.submit(); }, 700);
        });
    }

    window.openEditModal = function(d){
        if(!modal) return;
        resetForm();

        document.getElementById('m_id').value = d.id || '';
        document.getElementById('m_name').value = d.name || '';
        document.getElementById('m_schedule').value = d.schedule || '';
        document.getElementById('m_max_athletes').value = d.max_athletes || 0;
        document.getElementById('m_monthly_fee').value = d.monthly_fee || 0;
        document.getElementById('m_sport').value = d.sport || 'taekwondo_wtf';
        document.getElementById('m_active').value = d.active != null ? d.active : 1;

        activeWrap.style.display = '';
        titleIcon.style.color = 'var(--gold,#f0a500)';
        titleText.textContent = 'Επεξεργασία Τμήματος';
        submitLbl.textContent = 'Αποθήκευση Αλλαγών';
        successMsg.textContent = 'Τμήμα ενημερώθηκε!';

        modal.classList.add('open');
        document.body.style.overflow = 'hidden';

        setTimeout(function(){
            var f = document.getElementById('m_name');
            if(f) f.focus();
        }, 80);
    };
})();

document.addEventListener('keydown', function(e){
    if(e.key !== 'Escape') return;
    closeDetailModal();
    closeDeleteDeptModal();
    closeHardDeleteDeptModal();
    closeActivateDeptModal();
    if(window.closeDeptCreateModal) window.closeDeptCreateModal();
});
</script>
	
	
	<!-- ADD ATHLETE TO DEPT MODAL -->
<div class="modal-backdrop" id="addAthleteDeptModal" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:520px">
        <div class="modal-header">
            <div class="modal-title" style="color:#2dc653">
                <i class="fa-solid fa-user-plus"></i>
                <span>Προσθήκη Αθλητή στο Τμήμα</span>
            </div>
            <button type="button" class="modal-close" onclick="closeAddAthleteDeptModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body" style="padding-bottom:.5rem">
            <div style="margin-bottom:.85rem;padding:.65rem .9rem;border-radius:12px;background:rgba(45,198,83,.07);border:1px solid rgba(45,198,83,.18);font-size:.9rem;color:#a8f0c0">
                <i class="fa-solid fa-folder-open" style="margin-right:.4rem;color:#2dc653"></i>
                Τμήμα: <strong id="aad_dept_name" style="color:var(--text,#e2e8f0)">—</strong>
            </div>

            <div style="position:relative;margin-bottom:.75rem">
                <span style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--muted,#8892b0);pointer-events:none;font-size:.85rem">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </span>
                <input type="search" id="aad_search"
                    placeholder="Αναζήτηση αθλητή..."
                    autocomplete="off"
                    style="width:100%;padding:.5rem .8rem .5rem 2.25rem;border-radius:10px;background:var(--input-bg,#0f1219);border:1px solid var(--border,#1e2536);color:var(--text,#e2e8f0);font-size:.93rem;outline:none;min-height:40px;transition:border-color .18s"
                    onfocus="this.style.borderColor='var(--red,#e63946)'"
                    onblur="this.style.borderColor='var(--border,#1e2536)'">
            </div>

            <div style="font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#8892b0);margin-bottom:.45rem;padding-bottom:.4rem;border-bottom:1px solid var(--border,#1e2536)">
                <i class="fa-solid fa-users" style="margin-right:.3rem"></i>
                Αθλητές
                <span id="aad_count" style="font-weight:600;margin-left:.3rem"></span>
            </div>

            <div id="aad_list" style="max-height:320px;overflow-y:auto;margin:0 -.1rem;
                scrollbar-width:thin;scrollbar-color:#e63946 rgba(255,255,255,.08)"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeAddAthleteDeptModal()">
                <i class="fa-solid fa-xmark"></i> Κλείσιμο
            </button>
        </div>
    </div>
</div>

<form method="POST" id="aad_form" style="display:none">
    <input type="hidden" name="_action" value="assign_athlete_to_dept">
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <input type="hidden" name="dept_id"    id="aad_dept_id"    value="">
    <input type="hidden" name="athlete_id" id="aad_athlete_id" value="">
</form>

<script>
(function(){
    var ALL_ATHLETES = <?= json_encode($availableAthletesList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var _aad = { deptId: 0, deptName: '', athletes: [] };

    var SPORT_MAP = {
        taekwondo_wtf:'Taekwondo (WTF/WT)', taekwondo_itf:'Taekwondo (ITF)',
        taekwondo:'Taekwondo', karate_shotokan:'Karate Shotokan',
        karate_kyokushin:'Karate Kyokushin', karate:'Karate',
        bjj:'BJJ', judo:'Judo', kickboxing:'Kickboxing', boxing:'Πυγμαχία',
        mma:'MMA', wrestling:'Πάλη', pankration:'Παγκράτιο', sambo:'Sambo', other:'Άλλο'
    };
    function sl(s){ return SPORT_MAP[s] || s || ''; }
    function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

var PAGE_SIZE = 15;
var _visibleCount = PAGE_SIZE;

function renderList(q, reset) {
    if (reset !== false) _visibleCount = PAGE_SIZE;

    var q2 = (q || '').toLowerCase().trim();
    var filtered = _aad.athletes.filter(function(a){
        return !q2 || (a.full_name||'').toLowerCase().indexOf(q2) >= 0;
    });

    document.getElementById('aad_count').textContent = '(' + filtered.length + ')';

    if (!filtered.length) {
        document.getElementById('aad_list').innerHTML =
            '<div style="text-align:center;padding:1.8rem 1rem;color:var(--muted,#8892b0);font-size:.88rem">' +
            '<i class="fa-solid fa-magnifying-glass" style="display:block;font-size:1.5rem;opacity:.35;margin-bottom:.5rem"></i>Δεν βρέθηκαν αθλητές</div>';
        return;
    }

    var slice = filtered.slice(0, _visibleCount);
    var hasMore = filtered.length > _visibleCount;

    var html = slice.map(function(a){
        var initials = (a.full_name||'?').charAt(0).toUpperCase();
        var sub = [sl(a.sport)].filter(Boolean).join(' · ');
        var inDept  = a.department_id && parseInt(a.department_id) === _aad.deptId;
        var inOther = a.department_id && parseInt(a.department_id) !== _aad.deptId;
        return '<div style="display:flex;align-items:center;gap:.65rem;padding:.5rem .65rem;border-radius:10px;transition:background .13s;cursor:' + (inDept?'default':'pointer') + ';opacity:' + (inDept?.65:1) + '"' +
            (!inDept ? ' onclick="assignAthlete(' + a.id + ')" onmouseenter="this.style.background=\'rgba(255,255,255,.04)\'" onmouseleave="this.style.background=\'\'"' : '') + '>' +
            '<div style="width:36px;height:36px;min-width:36px;border-radius:50%;background:rgba(230,57,70,.13);border:1px solid rgba(230,57,70,.25);display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:800;color:var(--red,#e63946);flex-shrink:0">' + esc(initials) + '</div>' +
            '<div style="flex:1;min-width:0">' +
                '<div style="font-weight:700;font-size:.95rem">' + esc(a.full_name) + '</div>' +
                (sub ? '<div style="font-size:.78rem;color:var(--muted,#8892b0)">' + esc(sub) + '</div>' : '') +
            '</div>' +
            (inDept ?
                '<span style="font-size:.75rem;font-weight:700;padding:.18rem .55rem;border-radius:999px;background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.25);white-space:nowrap"><i class="fa-solid fa-check" style="margin-right:.25rem"></i>Ήδη εδώ</span>' :
                (inOther ?
                    '<span style="font-size:.75rem;font-weight:700;padding:.18rem .55rem;border-radius:999px;background:rgba(240,165,0,.1);color:#f0a500;border:1px solid rgba(240,165,0,.25);white-space:nowrap"><i class="fa-solid fa-folder" style="margin-right:.25rem"></i>Σε άλλο τμήμα</span>' :
                    '<span style="font-size:.75rem;font-weight:700;padding:.18rem .55rem;border-radius:999px;background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.25);white-space:nowrap;pointer-events:none"><i class="fa-solid fa-plus" style="margin-right:.25rem"></i>Προσθήκη</span>'
                )
            ) +
        '</div>';
    }).join('');

    if (hasMore) {
        html += '<div id="aad_load_more" style="text-align:center;padding:.75rem;font-size:.84rem;color:var(--muted,#8892b0)">' +
            '<i class="fa-solid fa-circle-notch fa-spin" style="margin-right:.35rem;opacity:.5"></i>' +
            (filtered.length - _visibleCount) + ' ακόμα αθλητές...</div>';
    }

    var listEl = document.getElementById('aad_list');
    listEl.innerHTML = html;

    // Attach scroll listener on the list container
    listEl._filterQuery = q;
    listEl.onscroll = hasMore ? function() {
        var sentinel = document.getElementById('aad_load_more');
        if (!sentinel) return;
        var rect = sentinel.getBoundingClientRect();
        var parentRect = listEl.getBoundingClientRect();
        if (rect.top <= parentRect.bottom + 60) {
            _visibleCount += PAGE_SIZE;
            renderList(listEl._filterQuery, false);
        }
    } : null;
}

    window.openAddAthleteDeptModal = function(deptId, deptName) {
        _aad.deptId   = deptId;
        _aad.deptName = deptName;
        _aad.athletes = ALL_ATHLETES;

        document.getElementById('aad_dept_id').value   = deptId;
        document.getElementById('aad_dept_name').textContent = deptName;
        document.getElementById('aad_search').value    = '';

        renderList('');

        document.getElementById('addAthleteDeptModal').classList.add('open');
        document.body.style.overflow = 'hidden';
        setTimeout(function(){ document.getElementById('aad_search').focus(); }, 80);
    };

    window.closeAddAthleteDeptModal = function() {
        document.getElementById('addAthleteDeptModal').classList.remove('open');
        document.body.style.overflow = '';
    };

    window.assignAthlete = function(athleteId) {
        document.getElementById('aad_athlete_id').value = athleteId;
        document.getElementById('aad_form').submit();
    };

var aadSearchEl = document.getElementById('aad_search');
aadSearchEl.addEventListener('input', function(){
    renderList(this.value);
});
aadSearchEl.addEventListener('keydown', function(e){
    if (e.key === 'Enter') {
        e.preventDefault();
        this.blur();
        var list = document.getElementById('aad_list');
        if (list) list.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
});

    document.getElementById('addAthleteDeptModal').addEventListener('click', function(e){
        if (e.target === this) window.closeAddAthleteDeptModal();
    });
})();
</script>
</body>
</html>