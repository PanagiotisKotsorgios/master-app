<?php
/**
 * ============================================================
 * tools/backfill_subscription_transactions.php
 * ============================================================
 * ΕΚΤΕΛΕΣΤΕ ΜΙΑ ΦΟΡΑ για να συγχρονίσετε:
 *   1. Υπάρχουσες paid subscriptions → transactions (Συνδρομές)
 *   2. Υπάρχουσα paid exam fees       → transactions (Εξετάσεις Ζωνών)
 *
 * ΧΡΗΣΗ: https://master-app.gr/tools/backfill_subscription_transactions.php
 *    ή:  php tools/backfill_subscription_transactions.php
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../includes/layout.php';
    requireLogin();
    if (!isSuperAdmin()) { http_response_code(403); die('Απαιτούνται δικαιώματα superadmin.'); }
}

$db = getDB();
$gm = ['','Ιαν','Φεβ','Μαρ','Απρ','Μαι','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];

$subIns = 0; $subUpd = 0;
$examIns = 0; $examUpd = 0;

// ══════════════════════════════════════════════
// 1. SUBSCRIPTIONS → transactions
// ══════════════════════════════════════════════
$subs = $db->query("
    SELECT s.id, s.school_id, s.athlete_id, s.amount, s.paid_at,
           s.payment_method, s.valid_from, s.valid_until, a.full_name
    FROM subscriptions s
    JOIN athletes a ON a.id = s.athlete_id
    WHERE s.status = 'paid' AND s.amount > 0
    ORDER BY s.paid_at ASC, s.id ASC
")->fetchAll();

foreach ($subs as $s) {
    $marker = 'sub_id:' . $s['id'];
    $exists = $db->prepare("SELECT id FROM transactions WHERE school_id=? AND notes LIKE ? LIMIT 1");
    $exists->execute([$s['school_id'], '%' . $marker . '%']);
    $txId = $exists->fetchColumn();

    $txDate = $s['paid_at'] ?: date('Y-m-d');
    $pm     = in_array($s['payment_method'], ['cash','card','deposit'], true) ? $s['payment_method'] : 'cash';
    $ts     = strtotime($s['valid_from']);
    $desc   = 'Συνδρομή ' . ($gm[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    if ($s['full_name']) $desc .= ' — ' . $s['full_name'];

    if ($txId) {
        $db->prepare("UPDATE transactions SET amount=?,transaction_date=?,payment_method=?,description=?,athlete_id=? WHERE id=?")
           ->execute([$s['amount'], $txDate, $pm, $desc, $s['athlete_id'], $txId]);
        $subUpd++;
    } else {
        $db->prepare("INSERT INTO transactions (school_id,type,category,amount,description,transaction_date,payment_method,athlete_id,notes) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$s['school_id'],'income','Συνδρομές',$s['amount'],$desc,$txDate,$pm,$s['athlete_id'],$marker]);
        $subIns++;
    }
}

// ══════════════════════════════════════════════
// 2. BELT EXAM FEES — DISABLED (tables removed)
// ══════════════════════════════════════════════
// Belt exam tables (belt_exams, belt_exam_participants) have been removed.
// Exam fee backfill is no longer available.
$examFees = [];

// ══════════════════════════════════════════════
// Output
// ══════════════════════════════════════════════
$totalSubs  = count($subs);
$totalExams = count($examFees);

if (PHP_SAPI === 'cli') {
    echo "✅ Backfill ολοκληρώθηκε!\n\n";
    echo "ΣΥΝΔΡΟΜΕΣ:\n";
    echo "  Νέες:          $subIns\n";
    echo "  Ενημερωμένες:  $subUpd\n";
    echo "  Σύνολο:        $totalSubs\n\n";
    echo "ΤΕΛΗ ΕΞΕΤΑΣΕΩΝ:\n";
    echo "  Νέες:          $examIns\n";
    echo "  Ενημερωμένες:  $examUpd\n";
    echo "  Σύνολο:        $totalExams\n";
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Backfill</title>";
    echo "<style>body{font-family:monospace;background:#0d1117;color:#e2e8f0;padding:2rem;max-width:600px}
    h2{color:#2dc653} table{border-collapse:collapse;width:100%;margin:1rem 0}
    td,th{padding:.5rem 1rem;border:1px solid #1e2536;text-align:left}
    th{background:#1e2536;color:#8892b0;font-size:.8rem;text-transform:uppercase}
    .green{color:#2dc653} .gold{color:#f0a500} .muted{color:#8892b0}
    a{color:#3b82f6}</style></head><body>";
    echo "<h2>✅ Backfill ολοκληρώθηκε!</h2>";
    echo "<table><tr><th>Τύπος</th><th>Νέες</th><th>Ενημ.</th><th>Σύνολο</th></tr>";
    echo "<tr><td>Συνδρομές</td><td class='green'>$subIns</td><td class='gold'>$subUpd</td><td>$totalSubs</td></tr>";
    echo "<tr><td>Τέλη Εξετάσεων</td><td class='green'>$examIns</td><td class='gold'>$examUpd</td><td>$totalExams</td></tr>";
    echo "</table>";
    echo "<p class='muted'>Τώρα τα Οικονομικά και οι Αναφορές εμφανίζουν σωστά ποσά από συνδρομές <strong>και</strong> εξετάσεις.</p>";
    echo "<p style='margin-top:1.5rem'>";
    echo "<a href='../pages/economics.php'>→ Οικονομικά</a> &nbsp; ";
    echo "<a href='../pages/reports.php'>→ Αναφορές</a> &nbsp; ";
    echo "<a href='../dashboard/'>→ Dashboard</a>";
    echo "</p></body></html>";
}