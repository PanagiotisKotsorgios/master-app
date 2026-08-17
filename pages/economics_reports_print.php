<?php
/**
 * economics_reports_print.php
 * Clean printable report page for PDF / print export
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
renderPaymentWall();

if (!planHas('economics_enabled')) {
    flash('Τα Οικονομικά απαιτούν Pro πλάνο.', 'danger');
    redirect(APP_URL . '/pages/upgrade.php');
}

$db  = getDB();
$sid = schoolId();

if (!function_exists('greekMonthName')) {
    function greekMonthName(int $m): string {
        return [1=>'Ιανουάριος',2=>'Φεβρουάριος',3=>'Μάρτιος',4=>'Απρίλιος',5=>'Μάιος',6=>'Ιούνιος',7=>'Ιούλιος',8=>'Αύγουστος',9=>'Σεπτέμβριος',10=>'Οκτώβριος',11=>'Νοέμβριος',12=>'Δεκέμβριος'][$m] ?? '';
    }
}
if (!function_exists('greekMonthShort')) {
    function greekMonthShort(int $m): string {
        return [1=>'Ιαν',2=>'Φεβ',3=>'Μαρ',4=>'Απρ',5=>'Μάι',6=>'Ιουν',7=>'Ιουλ',8=>'Αυγ',9=>'Σεπ',10=>'Οκτ',11=>'Νοε',12=>'Δεκ'][$m] ?? '';
    }
}

$yearParam  = $_GET['year'] ?? date('Y');
$isAllYears = ($yearParam === 'all');

if ($isAllYears) {
    $year = 'all';
} else {
    $year = (int)$yearParam;
    if ($year < 2000 || $year > 2099) $year = (int)date('Y');
}

$schoolStmt = $db->prepare("SELECT * FROM schools WHERE id=?");
$schoolStmt->execute([$sid]);
$school = $schoolStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$yearTitle = $isAllYears ? 'Όλα τα έτη' : (string)$year;

$monthlyIncome = [];
$monthlyExpense = [];

for ($m = 1; $m <= 12; $m++) {
    if ($isAllYears) {
        $i = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='income' AND MONTH(transaction_date)=?");
        $i->execute([$sid, $m]);
        $monthlyIncome[] = (float)$i->fetchColumn();

        $x = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='expense' AND MONTH(transaction_date)=?");
        $x->execute([$sid, $m]);
        $monthlyExpense[] = (float)$x->fetchColumn();
    } else {
        $mm = sprintf('%04d-%02d', $year, $m);
        $s  = $mm . '-01';
        $e  = date('Y-m-t', strtotime($s));

        $i = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='income' AND transaction_date BETWEEN ? AND ?");
        $i->execute([$sid, $s, $e]);
        $monthlyIncome[] = (float)$i->fetchColumn();

        $x = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND type='expense' AND transaction_date BETWEEN ? AND ?");
        $x->execute([$sid, $s, $e]);
        $monthlyExpense[] = (float)$x->fetchColumn();
    }
}

$rTotalIncome  = array_sum($monthlyIncome);
$rTotalExpense = array_sum($monthlyExpense);
$rProfit       = $rTotalIncome - $rTotalExpense;

if ($isAllYears) {
    $catStmt = $db->prepare("SELECT category, SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE school_id=? AND type='income' GROUP BY category ORDER BY total DESC");
    $catStmt->execute([$sid]);
    $catList = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    $expCatStmt = $db->prepare("SELECT category, SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE school_id=? AND type='expense' GROUP BY category ORDER BY total DESC");
    $expCatStmt->execute([$sid]);
    $expCatList = $expCatStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $catStmt = $db->prepare("SELECT category, SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE school_id=? AND type='income' AND YEAR(transaction_date)=? GROUP BY category ORDER BY total DESC");
    $catStmt->execute([$sid, $year]);
    $catList = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    $expCatStmt = $db->prepare("SELECT category, SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE school_id=? AND type='expense' AND YEAR(transaction_date)=? GROUP BY category ORDER BY total DESC");
    $expCatStmt->execute([$sid, $year]);
    $expCatList = $expCatStmt->fetchAll(PDO::FETCH_ASSOC);
}

$stmtAC = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id=? AND active=1");
$stmtAC->execute([$sid]);
$athleteCount = (int)$stmtAC->fetchColumn();

if ($isAllYears) {
    $stmtANew = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id=? AND registration_date IS NOT NULL");
    $stmtANew->execute([$sid]);
    $newAthletes = (int)$stmtANew->fetchColumn();
    $newAthletesLabel = 'συνολικά';
} else {
    $stmtANew = $db->prepare("SELECT COUNT(*) FROM athletes WHERE school_id=? AND YEAR(registration_date)=?");
    $stmtANew->execute([$sid, $year]);
    $newAthletes = (int)$stmtANew->fetchColumn();
    $newAthletesLabel = 'φέτος';
}

$months      = ['Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος','Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
$monthsShort = ['Ιαν','Φεβ','Μαρ','Απρ','Μαΐ','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];

$generatedAt = date('d/m/Y H:i');
$schoolName  = trim((string)($school['name'] ?? 'Η Σχολή μου'));
?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h('Αναφορές - ' . $yearTitle) ?></title>
    <style>
        :root{
            --text:#1a1a2e;
            --muted:#667085;
            --border:#d9dde3;
            --soft:#f7f8fa;
            --green:#16a34a;
            --red:#dc2626;
            --gold:#d97706;
        }

        *{box-sizing:border-box}

        html,body{
            margin:0;
            padding:0;
            background:#ffffff;
            color:var(--text);
            font-family:Arial, Helvetica, sans-serif;
            -webkit-print-color-adjust:exact;
            print-color-adjust:exact;
        }

        body{
            padding:20px;
        }

        .container{
            max-width:1100px;
            margin:0 auto;
        }

        .top-actions{
            display:flex;
            gap:.75rem;
            flex-wrap:wrap;
            justify-content:flex-end;
            margin-bottom:1rem;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:.45rem;
            min-height:44px;
            padding:.65rem 1rem;
            border-radius:10px;
            border:1px solid var(--border);
            background:#fff;
            color:var(--text);
            text-decoration:none;
            font-weight:700;
            cursor:pointer;
            font-size:14px;
        }

        .btn-primary{
            background:#111827;
            color:#fff;
            border-color:#111827;
        }

        .doc{
            border:1px solid var(--border);
            border-radius:16px;
            overflow:hidden;
            background:#fff;
        }

        .doc-header{
            padding:20px 22px 16px;
            border-bottom:1px solid var(--border);
            background:linear-gradient(to bottom, #fff, #fafafa);
            break-inside:avoid;
            page-break-inside:avoid;
        }

        .doc-title{
            font-size:26px;
            font-weight:800;
            margin:0 0 6px;
            line-height:1.2;
        }

        .doc-subtitle{
            color:var(--muted);
            font-size:14px;
            margin:0;
            line-height:1.6;
            word-break:break-word;
        }

        .summary-grid{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:14px;
            padding:18px 22px;
            break-inside:avoid;
            page-break-inside:avoid;
        }

        .stat-card{
            border:1px solid var(--border);
            border-radius:14px;
            padding:14px;
            background:#fff;
            break-inside:avoid;
            page-break-inside:avoid;
        }

        .stat-label{
            font-size:12px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.05em;
            color:var(--muted);
            margin-bottom:8px;
        }

        .stat-value{
            font-size:26px;
            font-weight:800;
            line-height:1.1;
            word-break:break-word;
        }

        .text-green{color:var(--green)}
        .text-red{color:var(--red)}
        .text-gold{color:var(--gold)}

        .section{
            padding:0 22px 22px;
        }

        .section-title{
            margin:4px 0 14px;
            font-size:13px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.08em;
            color:var(--muted);
            display:flex;
            align-items:center;
            gap:10px;
            break-inside:avoid;
            page-break-inside:avoid;
        }

        .section-title::after{
            content:"";
            height:1px;
            flex:1;
            background:var(--border);
        }

        .card{
            border:1px solid var(--border);
            border-radius:14px;
            overflow:hidden;
            background:#fff;
            margin-bottom:16px;
            break-inside:avoid;
            page-break-inside:avoid;
        }

        .card-header{
            padding:12px 14px;
            border-bottom:1px solid var(--border);
            background:var(--soft);
            font-size:16px;
            font-weight:800;
            break-inside:avoid;
            page-break-inside:avoid;
        }

        .grid-2{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:16px;
        }

        .table-responsive{
            width:100%;
            overflow-x:auto;
            -webkit-overflow-scrolling:touch;
        }

        table{
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
            font-size:13px;
        }

        thead{
            display:table-header-group;
        }

        tfoot{
            display:table-row-group;
        }

        th{
            background:#2c3e50;
            color:#fff;
            text-align:left;
            padding:9px 8px;
            border:1px solid #2c3e50;
            font-size:12px;
            line-height:1.25;
            word-break:break-word;
        }

        td{
            padding:8px 8px;
            border:1px solid var(--border);
            vertical-align:top;
            line-height:1.35;
            word-break:break-word;
            overflow-wrap:anywhere;
        }

        tr,
        td,
        th{
            break-inside:avoid;
            page-break-inside:avoid;
        }

        tbody tr:nth-child(even) td{
            background:#fafafa;
        }

        tfoot td{
            font-weight:800;
            background:#f3f4f6;
        }

        .right{text-align:right}
        .center{text-align:center}

        .progress-stack{
            display:flex;
            flex-direction:column;
            gap:4px;
            min-width:100px;
        }

        .progress{
            height:7px;
            border-radius:999px;
            background:#eceff3;
            overflow:hidden;
        }

        .progress-bar{
            height:100%;
            border-radius:999px;
        }

        .progress-bar.green{background:var(--green)}
        .progress-bar.red{background:var(--red)}

        .footer-note{
            padding:0 22px 22px;
            color:var(--muted);
            font-size:12px;
            line-height:1.6;
            break-inside:avoid;
            page-break-inside:avoid;
        }

        @media (max-width:900px){
            body{padding:12px}
            .summary-grid{grid-template-columns:1fr 1fr}
            .grid-2{grid-template-columns:1fr}
            .doc-title{font-size:23px}
            .stat-value{font-size:22px}
            .top-actions{justify-content:stretch}
            .top-actions .btn{flex:1}
        }

        @media (max-width:520px){
            body{padding:10px}
            .summary-grid{grid-template-columns:1fr}
            .doc-header,
            .summary-grid,
            .section,
            .footer-note{padding-left:12px;padding-right:12px}
            .doc-title{font-size:20px}
            .doc-subtitle{font-size:13px}
            .stat-value{font-size:20px}
            .card-header{font-size:14px;padding:10px 12px}
            table{font-size:11.5px}
            th,td{padding:7px 6px}
            .progress-stack{min-width:74px}
        }

        @media print{
            @page{
                size:A4 portrait;
                margin:12mm 10mm;
            }

            html,body{
                background:#fff !important;
            }

            body{
                padding:0;
                font-size:9.2pt;
            }

            .top-actions{
                display:none !important;
            }

            .container{
                max-width:none;
                width:100%;
            }

            .doc{
                border:none;
                border-radius:0;
                overflow:visible;
            }

            .doc-header{
                padding:0 0 10px 0;
            }

            .summary-grid{
                padding:12px 0 14px 0;
                gap:10px;
            }

            .section{
                padding:0 0 14px 0;
            }

            .footer-note{
                padding:0;
                margin-top:8px;
            }

            .card{
                border:1px solid #cfd5dd;
                margin-bottom:10px;
            }

            .card-header{
                padding:8px 10px;
                font-size:11pt;
            }

            table{
                width:100%;
                table-layout:fixed;
                font-size:8.2pt;
            }

            th{
                font-size:7.7pt;
                padding:6px 5px;
            }

            td{
                font-size:8.1pt;
                padding:5px 5px;
            }

            .summary-grid{
                grid-template-columns:repeat(4,1fr);
            }

            .stat-card{
                padding:10px;
            }

            .stat-label{
                font-size:7pt;
            }

            .stat-value{
                font-size:15pt;
            }

            tr,
            td,
            th,
            .card,
            .stat-card,
            .section-title,
            .doc-header,
            .footer-note{
                break-inside:avoid !important;
                page-break-inside:avoid !important;
            }

            a{
                color:inherit;
                text-decoration:none;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="top-actions">
        <button type="button" class="btn btn-primary" onclick="window.print()">Λήψη / Εκτύπωση PDF</button>
    </div>

    <div class="doc">
        <div class="doc-header">
            <h1 class="doc-title">Οικονομική Αναφορά — <?= h($yearTitle) ?></h1>
            <p class="doc-subtitle">
                <strong><?= h($schoolName) ?></strong><br>
                Δημιουργία αναφοράς: <?= h($generatedAt) ?>
            </p>
        </div>

        <div class="summary-grid">
            <div class="stat-card">
                <div class="stat-label">Σύνολο Εσόδων</div>
                <div class="stat-value text-green"><?= formatMoney($rTotalIncome) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Σύνολο Εξόδων</div>
                <div class="stat-value text-red"><?= formatMoney($rTotalExpense) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Καθαρό Αποτέλεσμα</div>
                <div class="stat-value <?= $rProfit >= 0 ? 'text-green' : 'text-red' ?>"><?= formatMoney($rProfit) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Ενεργοί Αθλητές</div>
                <div class="stat-value text-gold"><?= (int)$athleteCount ?></div>
                <div style="margin-top:6px;color:var(--muted);font-size:12px;">+<?= (int)$newAthletes ?> <?= h($newAthletesLabel) ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Οικονομική Ανάλυση</div>

            <div class="card">
                <div class="card-header">Μηνιαία Ανάλυση Εσόδων &amp; Εξόδων</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:24%">Μήνας</th>
                                <th class="right" style="width:18%">Έσοδα</th>
                                <th class="right" style="width:18%">Έξοδα</th>
                                <th class="right" style="width:20%">Αποτέλεσμα</th>
                                <th style="width:20%">Γράφημα</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($monthsShort as $mi => $mn):
                            $inc = $monthlyIncome[$mi];
                            $exp = $monthlyExpense[$mi];
                            $res = $inc - $exp;
                            $maxVal = max(max($monthlyIncome), max($monthlyExpense), 1);
                            $incPct = round(($inc / $maxVal) * 100);
                            $expPct = round(($exp / $maxVal) * 100);
                            $hasData = $inc > 0 || $exp > 0;
                        ?>
                            <tr style="<?= !$hasData ? 'opacity:.45' : '' ?>">
                                <td><strong><?= h($months[$mi]) ?></strong></td>
                                <td class="right" style="color:var(--green);font-weight:700"><?= $inc > 0 ? formatMoney($inc) : '—' ?></td>
                                <td class="right" style="color:var(--red);font-weight:700"><?= $exp > 0 ? formatMoney($exp) : '—' ?></td>
                                <td class="right" style="font-weight:800;color:<?= $res >= 0 ? 'var(--green)' : 'var(--red)' ?>">
                                    <?= ($inc > 0 || $exp > 0) ? formatMoney($res) : '—' ?>
                                </td>
                                <td>
                                    <?php if ($hasData): ?>
                                        <div class="progress-stack">
                                            <div class="progress"><div class="progress-bar green" style="width:<?= $incPct ?>%"></div></div>
                                            <div class="progress"><div class="progress-bar red" style="width:<?= $expPct ?>%"></div></div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td><strong>ΣΥΝΟΛΟ</strong></td>
                                <td class="right" style="color:var(--green)"><?= formatMoney($rTotalIncome) ?></td>
                                <td class="right" style="color:var(--red)"><?= formatMoney($rTotalExpense) ?></td>
                                <td class="right" style="color:<?= $rProfit >= 0 ? 'var(--green)' : 'var(--red)' ?>"><?= formatMoney($rProfit) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="grid-2">
                <div class="card">
                    <div class="card-header">Κατηγορίες Εσόδων</div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:40%">Κατηγορία</th>
                                    <th class="right" style="width:22%">Ποσό</th>
                                    <th class="right" style="width:18%">Εγγρ.</th>
                                    <th class="right" style="width:20%">%</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($catList): ?>
                                <?php foreach ($catList as $c):
                                    $pct = $rTotalIncome > 0 ? round(($c['total'] / $rTotalIncome) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><?= h((string)$c['category']) ?></td>
                                        <td class="right" style="font-weight:700;color:var(--green)"><?= formatMoney((float)$c['total']) ?></td>
                                        <td class="right"><?= (int)$c['cnt'] ?></td>
                                        <td class="right" style="font-weight:700"><?= $pct ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="center" style="color:var(--muted)">Δεν υπάρχουν δεδομένα</td></tr>
                            <?php endif; ?>
                            </tbody>
                            <?php if ($catList): ?>
                            <tfoot>
                                <tr>
                                    <td><strong>Σύνολο</strong></td>
                                    <td class="right" style="color:var(--green)"><?= formatMoney($rTotalIncome) ?></td>
                                    <td class="right"><?= array_sum(array_map(fn($x)=>(int)$x['cnt'], $catList)) ?></td>
                                    <td class="right">100%</td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Κατηγορίες Εξόδων</div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:40%">Κατηγορία</th>
                                    <th class="right" style="width:22%">Ποσό</th>
                                    <th class="right" style="width:18%">Εγγρ.</th>
                                    <th class="right" style="width:20%">%</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($expCatList): ?>
                                <?php foreach ($expCatList as $c):
                                    $pct = $rTotalExpense > 0 ? round(($c['total'] / $rTotalExpense) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><?= h((string)$c['category']) ?></td>
                                        <td class="right" style="font-weight:700;color:var(--red)"><?= formatMoney((float)$c['total']) ?></td>
                                        <td class="right"><?= (int)$c['cnt'] ?></td>
                                        <td class="right" style="font-weight:700"><?= $pct ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="center" style="color:var(--muted)">Δεν υπάρχουν δεδομένα</td></tr>
                            <?php endif; ?>
                            </tbody>
                            <?php if ($expCatList): ?>
                            <tfoot>
                                <tr>
                                    <td><strong>Σύνολο</strong></td>
                                    <td class="right" style="color:var(--red)"><?= formatMoney($rTotalExpense) ?></td>
                                    <td class="right"><?= array_sum(array_map(fn($x)=>(int)$x['cnt'], $expCatList)) ?></td>
                                    <td class="right">100%</td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer-note">
            Η αναφορά δημιουργήθηκε αυτόματα από το σύστημα οικονομικών.
        </div>
    </div>
</div>

<script>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 350);
});
</script>
</body>
</html>