<?php
/**
 * ============================================================
 * employee/analytics.php — Platform Analytics & Statistics
 * ============================================================
 */

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL); ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/privileges.php';
require_once __DIR__ . '/layout.php';

empRequire('analytics_view');

$db        = getDB();
$canExport = empCan('analytics_export');

// ── Helper ───────────────────────────────────────────────────
function sc(PDO $db, string $sql, array $p = [], mixed $def = 0): mixed {
    try { $s=$db->prepare($sql);$s->execute($p);$v=$s->fetchColumn();return $v!==false?$v:$def; }
    catch(Throwable $e){error_log('analytics sc: '.$e->getMessage());return $def;}
}
function rw(PDO $db, string $sql, array $p = []): array {
    try { $s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    catch(Throwable $e){error_log('analytics rw: '.$e->getMessage());return [];}
}

// ── Core Stats ───────────────────────────────────────────────
$stats = [
    'total_schools'     => (int)sc($db,"SELECT COUNT(*) FROM schools"),
    'active_schools'    => (int)sc($db,"SELECT COUNT(*) FROM schools WHERE plan_status='active'"),
    'trial_schools'     => (int)sc($db,"SELECT COUNT(*) FROM schools WHERE plan_status='trial'"),
    'expired_schools'   => (int)sc($db,"SELECT COUNT(*) FROM schools WHERE plan_status='expired'"),
    'suspended_schools' => (int)sc($db,"SELECT COUNT(*) FROM schools WHERE subscription_status IN('suspended','past_due','cancelled') AND active=1"),
    'total_users'       => (int)sc($db,"SELECT COUNT(*) FROM users WHERE active=1"),
    'total_athletes'    => (int)sc($db,"SELECT COUNT(*) FROM athletes WHERE active=1"),
    'revenue_total'     => (float)sc($db,"SELECT COALESCE(SUM(amount),0) FROM school_plan_payments",[],'0'),
    'revenue_month'     => (float)sc($db,"SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())",[],'0'),
    'payments_count'    => (int)sc($db,"SELECT COUNT(*) FROM school_plan_payments"),
    'subscriptions'     => (int)sc($db,"SELECT COUNT(*) FROM subscriptions"),
    'subs_paid'         => (int)sc($db,"SELECT COUNT(*) FROM subscriptions WHERE status='paid'"),
    'subs_overdue'      => (int)sc($db,"SELECT COUNT(*) FROM subscriptions WHERE status='overdue'"),
    'reminders_sent'    => (int)sc($db,"SELECT COUNT(*) FROM reminder_logs WHERE status='sent'"),
    'email_sent'        => (int)sc($db,"SELECT COUNT(*) FROM reminder_logs WHERE type='email' AND status='sent'"),
    'sms_sent'          => (int)sc($db,"SELECT COUNT(*) FROM reminder_logs WHERE type='sms' AND status='sent'"),
    'new_schools_month' => (int)sc($db,"SELECT COUNT(*) FROM schools WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"),
    'new_athletes_month'=> (int)sc($db,"SELECT COUNT(*) FROM athletes WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND active=1"),
];

// ── Top Schools by Athletes ──────────────────────────────────
$topSchools = rw($db,"
    SELECT s.name, s.subscription_status, p.name AS plan_name,
           COUNT(a.id) AS athletes
    FROM schools s
    LEFT JOIN athletes a ON a.school_id=s.id AND a.active=1
    LEFT JOIN plans p ON p.id=s.plan_id
    WHERE s.active=1
    GROUP BY s.id ORDER BY athletes DESC LIMIT 15
");

// ── Sport Distribution ───────────────────────────────────────
$sportDist = rw($db,"SELECT s.name as sport, COUNT(a.id) as cnt FROM athletes a JOIN schools s ON s.id=a.school_id WHERE a.active=1 GROUP BY a.school_id ORDER BY cnt DESC LIMIT 12");

// ── Monthly Growth (12 months) ───────────────────────────────
$monthly = [];
for ($i=11;$i>=0;$i--) {
    $m   = date('Y-m', strtotime("-$i months"));
    $s   = "$m-01";
    $e   = date('Y-m-t', strtotime($s));
    $sch = (int)sc($db,"SELECT COUNT(*) FROM schools WHERE created_at BETWEEN ? AND ?",[$s,"$e 23:59:59"]);
    $ath = (int)sc($db,"SELECT COUNT(*) FROM athletes WHERE created_at BETWEEN ? AND ?",[$s,"$e 23:59:59"]);
    $rev = (float)sc($db,"SELECT COALESCE(SUM(amount),0) FROM school_plan_payments WHERE paid_at BETWEEN ? AND ?",[$s,"$e 23:59:59"],'0');
    $monthly[] = ['month'=>date('M y',strtotime($s)),'schools'=>$sch,'athletes'=>$ath,'revenue'=>$rev];
}

// ── Plan Distribution ────────────────────────────────────────
$planDist = rw($db,"SELECT p.name, COUNT(s.id) as cnt FROM schools s JOIN plans p ON p.id=s.plan_id WHERE s.active=1 GROUP BY p.id ORDER BY cnt DESC");

// ── Recent Payments ──────────────────────────────────────────
$recentPayments = rw($db,"
    SELECT spp.amount, spp.paid_at, spp.method, s.name AS school_name, p.name AS plan_name
    FROM school_plan_payments spp
    LEFT JOIN schools s ON s.id=spp.school_id
    LEFT JOIN plans p ON p.id=spp.plan_id
    ORDER BY spp.paid_at DESC LIMIT 10
");

// ── Subscription Rate ────────────────────────────────────────
$subRate = $stats['subscriptions'] > 0 ? round(($stats['subs_paid']/$stats['subscriptions'])*100,1) : 0;

// ── Render ───────────────────────────────────────────────────
renderEmpHead('Platform Analytics');
?><body>
<?php renderEmpSidebar('analytics'); ?>
<div class="emp-main">
<?php renderEmpTopbar('Platform Analytics'); ?>

<style>
/* ── Print / PDF styles ── */
@media print {
  .emp-sidebar,.emp-topbar,.no-print{display:none!important}
  .emp-main{margin-left:0!important}
  .emp-content{padding:0!important}
  body{background:#fff!important;color:#000!important}
  .stat-card,.card,.a-stat,.chart-box{background:#fff!important;border:1px solid #ccc!important;box-shadow:none!important;break-inside:avoid}
  .stat-val,.a-stat .val{color:#000!important}
  .stat-label,.card-title,.a-stat .lbl{color:#333!important}
  table{border-collapse:collapse;width:100%}
  th,td{border:1px solid #999;padding:6px 10px;font-size:.82rem;color:#000}
  th{background:#eee!important;font-weight:700}
  .chart-container{display:none}
  .print-section{display:block!important}
  h2,h3{color:#000!important}
  .page-break{page-break-before:always}
}

/* ── Analytics-specific styles ── */
.analytics-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:1rem;margin-bottom:1.4rem}
.analytics-grid.wide{grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}
.a-stat{background:rgba(17,21,32,.92);border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:1.15rem 1.2rem;transition:.2s}
.a-stat:hover{border-color:rgba(255,255,255,.18);transform:translateY(-2px)}
.a-stat .lbl{font-size:.75rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.5rem;display:flex;align-items:center;gap:.45rem}
.a-stat .val{font-size:1.9rem;font-weight:800;line-height:1}
.a-stat .sub{font-size:.75rem;color:var(--muted);margin-top:.35rem}
.a-stat.green .val{color:var(--green)}
.a-stat.blue  .val{color:var(--blue)}
.a-stat.gold  .val{color:var(--gold)}
.a-stat.red   .val{color:var(--red)}
.a-stat.accent .val{color:var(--accent)}
.chart-box{background:rgba(17,21,32,.92);border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:1.2rem 1.3rem;margin-bottom:1.2rem}
.chart-box .ch-title{font-size:.9rem;font-weight:800;margin-bottom:1rem;display:flex;align-items:center;gap:.55rem}
.chart-container{position:relative;height:220px}
.chart-container.tall{height:280px}
.bar-row{display:flex;align-items:center;gap:.75rem;margin-bottom:.65rem;font-size:.82rem}
.bar-row .bar-label{width:120px;flex-shrink:0;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bar-row .bar-track{flex:1;background:rgba(255,255,255,.06);border-radius:99px;height:8px;overflow:hidden}
.bar-row .bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--accent),var(--blue))}
.bar-row .bar-cnt{width:40px;text-align:right;font-weight:700;color:#fff}
.export-bar{display:flex;gap:.7rem;align-items:center;flex-wrap:wrap;margin-bottom:1.4rem}
.export-bar .locked{opacity:.45;cursor:not-allowed}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem}
@media(max-width:900px){.two-col{grid-template-columns:1fr}}
</style>

<div class="emp-content">

  <!-- Header + Export Bar -->
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.2rem">
    <div>
      <div class="section-title"><i class="fa-solid fa-chart-line" style="color:var(--blue)"></i> Platform Analytics</div>
      <div class="section-sub">Πλήρης ανάλυση δεδομένων πλατφόρμας · <?= date('d M Y, H:i') ?></div>
    </div>
    <div class="export-bar no-print">
      <?php if ($canExport): ?>
        <button onclick="window.print()" class="btn btn-ghost"><i class="fa-solid fa-print"></i> Εκτύπωση</button>
        <a href="<?= APP_URL ?>/employee/export.php?report=analytics&format=csv" class="btn btn-ghost"><i class="fa-solid fa-file-csv"></i> CSV</a>
        <button onclick="exportPDF()" class="btn btn-primary"><i class="fa-solid fa-file-pdf"></i> PDF</button>
      <?php else: ?>
        <span class="locked btn btn-ghost" title="Απαιτείται δικαίωμα analytics_export"><i class="fa-solid fa-lock"></i> Εξαγωγή κλειδωμένη</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- KPI Stats Row 1 -->
  <div class="analytics-grid wide">
    <div class="a-stat blue"><div class="lbl"><i class="fa-solid fa-school"></i> Σχολές</div><div class="val"><?= $stats['total_schools'] ?></div><div class="sub"><?= $stats['active_schools'] ?> active · <?= $stats['trial_schools'] ?> trial</div></div>
    <div class="a-stat green"><div class="lbl"><i class="fa-solid fa-person-running"></i> Αθλητές</div><div class="val"><?= number_format($stats['total_athletes']) ?></div><div class="sub">+<?= $stats['new_athletes_month'] ?> αυτόν τον μήνα</div></div>
    <div class="a-stat accent"><div class="lbl"><i class="fa-solid fa-users"></i> Χρήστες</div><div class="val"><?= number_format($stats['total_users']) ?></div><div class="sub">Ενεργοί λογαριασμοί</div></div>
    <div class="a-stat gold"><div class="lbl"><i class="fa-solid fa-euro-sign"></i> Σύνολο εσόδων</div><div class="val">€<?= number_format($stats['revenue_total'],0,',','.') ?></div><div class="sub">€<?= number_format($stats['revenue_month'],0,',','.') ?> αυτόν τον μήνα</div></div>
    <div class="a-stat"><div class="lbl"><i class="fa-solid fa-credit-card"></i> Πληρωμές</div><div class="val"><?= number_format($stats['payments_count']) ?></div><div class="sub">School plan payments</div></div>
    <div class="a-stat red"><div class="lbl"><i class="fa-solid fa-triangle-exclamation"></i> Ληξιπρόθεσμες</div><div class="val"><?= $stats['subs_overdue'] ?></div><div class="sub">από <?= $stats['subscriptions'] ?> συνδρομές</div></div>
  </div>

  <!-- KPI Stats Row 2 -->
  <div class="analytics-grid">
    <div class="a-stat"><div class="lbl"><i class="fa-solid fa-receipt"></i> Συνδρομές</div><div class="val"><?= number_format($stats['subscriptions']) ?></div><div class="sub">Αθλητών</div></div>
    <div class="a-stat green"><div class="lbl"><i class="fa-solid fa-circle-check"></i> Πληρωμένες</div><div class="val"><?= $stats['subs_paid'] ?></div><div class="sub"><?= $subRate ?>% ποσοστό</div></div>
    <div class="a-stat"><div class="lbl"><i class="fa-solid fa-bell"></i> Reminders</div><div class="val"><?= number_format($stats['reminders_sent']) ?></div><div class="sub">Απεστάλησαν</div></div>
    <div class="a-stat blue"><div class="lbl"><i class="fa-solid fa-envelope"></i> Email</div><div class="val"><?= number_format($stats['email_sent']) ?></div><div class="sub">Σύνολο</div></div>
    <div class="a-stat"><div class="lbl"><i class="fa-solid fa-message"></i> SMS</div><div class="val"><?= number_format($stats['sms_sent']) ?></div><div class="sub">Σύνολο</div></div>
    <div class="a-stat red"><div class="lbl"><i class="fa-solid fa-ban"></i> Ανασταλμένες</div><div class="val"><?= $stats['suspended_schools'] ?></div><div class="sub">Σχολές</div></div>
    <div class="a-stat green"><div class="lbl"><i class="fa-solid fa-plus-circle"></i> Νέες σχολές</div><div class="val"><?= $stats['new_schools_month'] ?></div><div class="sub">Αυτόν τον μήνα</div></div>
  </div>

  <!-- Charts Row -->
  <div class="two-col">
    <div class="chart-box">
      <div class="ch-title"><i class="fa-solid fa-chart-line" style="color:var(--blue)"></i> Μηνιαία Ανάπτυξη (12 μήνες)</div>
      <div class="chart-container tall"><canvas id="growthChart"></canvas></div>
    </div>
    <div class="chart-box">
      <div class="ch-title"><i class="fa-solid fa-chart-bar" style="color:var(--gold)"></i> Μηνιαία Έσοδα (€)</div>
      <div class="chart-container tall"><canvas id="revenueChart"></canvas></div>
    </div>
  </div>

  <!-- Sport Distribution -->
  <div class="two-col">
    <div class="chart-box">
      <div class="ch-title"><i class="fa-solid fa-medal" style="color:var(--accent)"></i> Αθλητές ανά Σχολή</div>
      <?php
        $maxSport = max(array_column($sportDist,'cnt') ?: [1]);
        foreach($sportDist as $r):
          $pct = round(($r['cnt']/$maxSport)*100);
      ?>
      <div class="bar-row">
        <div class="bar-label" title="<?= h($r['sport']) ?>"><?= h($r['sport']) ?></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
        <div class="bar-cnt"><?= number_format($r['cnt']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Plan Distribution + Top Schools -->
  <div class="two-col">
    <div class="chart-box">
      <div class="ch-title"><i class="fa-solid fa-tags" style="color:var(--gold)"></i> Κατανομή Πλάνων</div>
      <div class="chart-container"><canvas id="planChart"></canvas></div>
    </div>

    <div class="chart-box" style="overflow-x:auto">
      <div class="ch-title"><i class="fa-solid fa-trophy" style="color:var(--gold)"></i> Top 15 Σχολές (Αθλητές)</div>
      <table style="width:100%;font-size:.82rem;border-collapse:collapse">
        <thead><tr style="border-bottom:1px solid rgba(255,255,255,.08)">
          <th style="text-align:left;padding:.5rem .6rem;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase">#</th>
          <th style="text-align:left;padding:.5rem .6rem;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase">Σχολή</th>
          <th style="text-align:left;padding:.5rem .6rem;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase">Πλάνο</th>
          <th style="text-align:right;padding:.5rem .6rem;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase">Αθλητές</th>
        </tr></thead>
        <tbody>
        <?php foreach($topSchools as $i=>$s): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
            <td style="padding:.45rem .6rem;color:var(--muted)"><?= $i+1 ?></td>
            <td style="padding:.45rem .6rem;font-weight:600"><?= h($s['name']) ?></td>
            <td style="padding:.45rem .6rem;font-size:.78rem;color:var(--muted)"><?= h($s['plan_name'] ?? '—') ?></td>
            <td style="padding:.45rem .6rem;text-align:right;font-weight:800;color:var(--blue)"><?= number_format($s['athletes']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Print-only: top schools full table -->
  <div class="print-section" style="display:none">
    <div class="page-break"></div>
    <h2 style="margin-bottom:1rem">Top Σχολές</h2>
    <table>
      <thead><tr><th>#</th><th>Σχολή</th><th>Πλάνο</th><th>Κατάσταση</th><th>Αθλητές</th></tr></thead>
      <tbody>
      <?php foreach($topSchools as $i=>$s): ?>
        <tr><td><?= $i+1 ?></td><td><?= h($s['name']) ?></td><td><?= h($s['plan_name']??'—') ?></td><td><?= h($s['subscription_status']??'—') ?></td><td><?= number_format($s['athletes']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Recent Payments -->
  <div class="chart-box" style="overflow-x:auto;margin-bottom:1.4rem">
    <div class="ch-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--green)"></i> Τελευταίες Πληρωμές</div>
    <table style="width:100%;font-size:.82rem;border-collapse:collapse">
      <thead><tr style="border-bottom:1px solid rgba(255,255,255,.08)">
        <th style="text-align:left;padding:.5rem .7rem;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase">Σχολή</th>
        <th style="text-align:left;padding:.5rem .7rem;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase">Πλάνο</th>
        <th style="text-align:left;padding:.5rem .7rem;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase">Μέθοδος</th>
        <th style="text-align:right;padding:.5rem .7rem;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase">Ποσό</th>
        <th style="text-align:right;padding:.5rem .7rem;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase">Ημερομηνία</th>
      </tr></thead>
      <tbody>
      <?php foreach($recentPayments as $p): ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
          <td style="padding:.5rem .7rem;font-weight:600"><?= h($p['school_name']??'—') ?></td>
          <td style="padding:.5rem .7rem;color:var(--muted)"><?= h($p['plan_name']??'—') ?></td>
          <td style="padding:.5rem .7rem;color:var(--muted)"><?= h($p['method']??'—') ?></td>
          <td style="padding:.5rem .7rem;text-align:right;font-weight:800;color:var(--green)">€<?= number_format($p['amount'],2,',','.') ?></td>
          <td style="padding:.5rem .7rem;text-align:right;color:var(--muted);font-size:.78rem"><?= date('d/m/Y H:i',strtotime($p['paid_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div><!-- /emp-content -->
<?php renderEmpClose(); ?>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const MONTHS   = <?= json_encode(array_column($monthly,'month')) ?>;
const SCHOOLS  = <?= json_encode(array_column($monthly,'schools')) ?>;
const ATHLETES = <?= json_encode(array_column($monthly,'athletes')) ?>;
const REVENUE  = <?= json_encode(array_column($monthly,'revenue')) ?>;
const PLANS    = <?= json_encode(array_column($planDist,'name')) ?>;
const PLAN_CNT = <?= json_encode(array_column($planDist,'cnt')) ?>;

Chart.defaults.color = '#8892b0';
Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';

new Chart(document.getElementById('growthChart'), {
  type: 'line',
  data: {
    labels: MONTHS,
    datasets: [
      {label:'Σχολές',data:SCHOOLS,borderColor:'#58a6ff',backgroundColor:'rgba(88,166,255,.12)',tension:.4,fill:true,pointRadius:3},
      {label:'Αθλητές',data:ATHLETES,borderColor:'#2dc653',backgroundColor:'rgba(45,198,83,.08)',tension:.4,fill:true,pointRadius:3}
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{boxWidth:12,font:{size:11}}}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:10}}}}}
});

new Chart(document.getElementById('revenueChart'), {
  type: 'bar',
  data: {
    labels: MONTHS,
    datasets:[{label:'Έσοδα (€)',data:REVENUE,backgroundColor:'rgba(240,165,0,.7)',borderColor:'#f0a500',borderWidth:1,borderRadius:6}]
  },
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'€'+v.toLocaleString('el-GR'),font:{size:10}}},x:{ticks:{font:{size:10}}}}}
});

new Chart(document.getElementById('planChart'), {
  type: 'doughnut',
  data: {
    labels: PLANS,
    datasets:[{data:PLAN_CNT,backgroundColor:['#58a6ff','#2dc653','#f0a500','#e63946','#d9d1ff','#2dc6c6'],borderWidth:2,borderColor:'#111520'}]
  },
  options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'right',labels:{boxWidth:12,font:{size:11},padding:14}}}}
});

function exportPDF() {
  const script = document.createElement('script');
  script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
  script.onload = function() {
    const s2 = document.createElement('script');
    s2.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js';
    s2.onload = buildPDF;
    document.head.appendChild(s2);
  };
  document.head.appendChild(script);
}

function buildPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });
  const BW = doc.internal.pageSize.getWidth();
  let y = 20;

  doc.setFontSize(18); doc.setFont(undefined,'bold');
  doc.text('Platform Analytics Report', BW/2, y, {align:'center'}); y+=8;
  doc.setFontSize(10); doc.setFont(undefined,'normal');
  doc.text('Εξαγωγή: ' + new Date().toLocaleString('el-GR'), BW/2, y, {align:'center'}); y+=10;

  doc.setFontSize(12); doc.setFont(undefined,'bold');
  doc.text('Βασικά Στατιστικά', 14, y); y+=4;
  doc.autoTable({
    startY: y,
    head: [['Δείκτης','Τιμή']],
    body: [
      ['Σύνολο Σχολών','<?= $stats['total_schools'] ?>'],
      ['Ενεργές Σχολές','<?= $stats['active_schools'] ?>'],
      ['Trial Σχολές','<?= $stats['trial_schools'] ?>'],
      ['Σύνολο Αθλητών','<?= number_format($stats['total_athletes']) ?>'],
      ['Σύνολο Χρηστών','<?= number_format($stats['total_users']) ?>'],
      ['Συνολικά Έσοδα','€<?= number_format($stats['revenue_total'],2,',','.') ?>'],
      ['Έσοδα Τρέχοντος Μήνα','€<?= number_format($stats['revenue_month'],2,',','.') ?>'],
      ['Σύνολο Πληρωμών','<?= number_format($stats['payments_count']) ?>'],
      ['Συνδρομές Αθλητών','<?= number_format($stats['subscriptions']) ?>'],
      ['Πληρωμένες Συνδρομές','<?= $stats['subs_paid'] ?> (<?= $subRate ?>%)'],
      ['Ληξιπρόθεσμες','<?= $stats['subs_overdue'] ?>'],
      ['Email Αποστολές','<?= number_format($stats['email_sent']) ?>'],
      ['SMS Αποστολές','<?= number_format($stats['sms_sent']) ?>'],
    ],
    styles:{fontSize:9,cellPadding:3},
    headStyles:{fillColor:[30,35,55],textColor:255,fontStyle:'bold'},
    alternateRowStyles:{fillColor:[245,245,245]},
    margin:{left:14,right:14}
  });
  y = doc.lastAutoTable.finalY + 10;

  if (y > 220) { doc.addPage(); y = 20; }
  doc.setFontSize(12); doc.setFont(undefined,'bold');
  doc.text('Μηνιαία Ανάπτυξη', 14, y); y+=4;
  doc.autoTable({
    startY: y,
    head: [['Μήνας','Νέες Σχολές','Νέοι Αθλητές','Έσοδα (€)']],
    body: MONTHS.map((m,i) => [m, SCHOOLS[i], ATHLETES[i], '€'+REVENUE[i].toLocaleString('el-GR',{minimumFractionDigits:2})]),
    styles:{fontSize:9,cellPadding:3},
    headStyles:{fillColor:[30,35,55],textColor:255,fontStyle:'bold'},
    alternateRowStyles:{fillColor:[245,245,245]},
    margin:{left:14,right:14}
  });

  doc.addPage(); y=20;
  doc.setFontSize(12); doc.setFont(undefined,'bold');
  doc.text('Top 15 Σχολές', 14, y); y+=4;
  doc.autoTable({
    startY: y,
    head: [['#','Σχολή','Πλάνο','Αθλητές']],
    body: <?= json_encode(array_values(array_map(fn($i,$s)=>[$i+1,$s['name']??'—',$s['plan_name']??'—',$s['athletes']],array_keys($topSchools),$topSchools))) ?>,
    styles:{fontSize:9,cellPadding:3},
    headStyles:{fillColor:[30,35,55],textColor:255,fontStyle:'bold'},
    alternateRowStyles:{fillColor:[245,245,245]},
    margin:{left:14,right:14}
  });

  doc.save('analytics_' + new Date().toISOString().slice(0,10) + '.pdf');
}
</script>