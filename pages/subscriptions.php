<?php

/**
 * ============================================================
 * pages/subscriptions.php — Διαχείριση Συνδρομών Αθλητών
 * ============================================================
 * PURPOSE:
 *   Καταχώρηση, επεξεργασία και ακύρωση συνδρομών.
 *   Αυτόματος υπολογισμός ληξιπρόθεσμων, bulk actions.
 *
 * SECURITY:
 *   ✓ requireLogin() + renderPaymentWall()
 *   ✓ verifyCsrf() σε κάθε POST
 *   ✓ Ownership check: JOIN athletes WHERE school_id=?
 *   ✓ Prepared statements
 *   ✓ (int) cast σε IDs
 *   ✓ Whitelist για status values (paid/pending/overdue)
 *   ✓ Whitelist για payment_method (cash/card/deposit)
 *   ✓ amount: (float) cast + επαλήθευση >= 0
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
renderPaymentWall();

$db  = getDB();
$sid = schoolId();

// ── Greek months ──
$greekMonths      = ['','Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος','Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
$greekMonthsShort = ['','Ιαν','Φεβ','Μαρ','Απρ','Μαι','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];

// ── Debt helpers (mirror athletes.php exactly) ──
function subGetDebtStartDate(array $athlete): ?string {
    $dfm = $athlete['debt_from_month'] ?? null;
    if ($dfm && preg_match('/^\d{4}-\d{2}$/', $dfm)) return $dfm . '-01';
    $reg = $athlete['registration_date'] ?? null;
    return ($reg && $reg !== '0000-00-00') ? $reg : null;
}

function subGetDebtSummary($db, int $athleteId, ?string $startDate, float $monthlyFee): array {
    if (!$startDate || $startDate === '0000-00-00' || $monthlyFee <= 0) {
        return ['months' => 0, 'balance' => 0.0, 'unpaid' => []];
    }

    $stmt = $db->prepare("SELECT valid_from, valid_until, amount FROM subscriptions WHERE athlete_id=? AND status='paid'");
    $stmt->execute([$athleteId]);
    $subs = $stmt->fetchAll();

    $start = (new DateTime($startDate))->modify('first day of this month');
    $now   = (new DateTime())->modify('first day of this month');
    $gm    = ['','Ιαν','Φεβ','Μαρ','Απρ','Μαι','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];

    $debtMonths   = 0;
    $debtBalance  = 0.0;
    $unpaidMonths = [];

    $cur = clone $start;
    while ($cur <= $now) {
        $mEnd = (clone $cur)->modify('last day of this month');
        $paidForMonth = 0.0;

        foreach ($subs as $s) {
            if (new DateTime($s['valid_from']) <= $mEnd && new DateTime($s['valid_until']) >= $cur) {
                $paidForMonth += floatval($s['amount'] ?? 0);
            }
        }

        $remaining = max(0, $monthlyFee - $paidForMonth);

        if ($remaining > 0.009) {
            $debtMonths++;
            $debtBalance += $remaining;
            $unpaidMonths[] = [
                'month'     => $cur->format('Y-m'),
                'label'     => $gm[(int)$cur->format('m')] . ' ' . $cur->format('Y'),
                'paid'      => $paidForMonth,
                'remaining' => $remaining,
            ];
        }

        $cur->modify('+1 month');
    }

    return [
        'months'  => $debtMonths,
        'balance' => $debtBalance,
        'unpaid'  => $unpaidMonths,
    ];
}

// ── Helper: get pending exam fees for an athlete ──
function subGetAthleteExamFeeDebts(PDO $db, int $athleteId, int $schoolId): array {
    $stmt = $db->prepare(
        "SELECT id, amount, description, transaction_date, notes
         FROM transactions
         WHERE school_id = ?
           AND athlete_id = ?
           AND category = 'Εξετάσεις Ζωνών'
           AND notes LIKE '%:pending%'
         ORDER BY transaction_date DESC"
    );
    $stmt->execute([$schoolId, $athleteId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        if (preg_match('/exam_fee:(\d+):pending/', $row['notes'], $matches)) {
            $row['participant_id'] = (int)$matches[1];
        } else {
            $row['participant_id'] = 0;
        }
    }
    return $rows;
}

// ── Helper: sync exam fee payment to transactions table ──
function subSyncExamFeeTransaction(
    PDO    $db,
    string $action,
    int    $schoolId,
    int    $participantId,
    int    $examId      = 0,
    int    $athleteId   = 0,
    float  $amount      = 0.0,
    string $examDate    = '',
    string $athleteName = '',
    bool   $isPaid      = false
): void {
    $baseMarker = 'exam_fee:' . $participantId;

    if ($action === 'delete') {
        $db->prepare("DELETE FROM transactions WHERE school_id = ? AND notes LIKE ?")
           ->execute([$schoolId, '%' . $baseMarker . '%']);
        return;
    }

    if ($amount <= 0) return;

    $txDate = $examDate !== '' ? $examDate : date('Y-m-d');
    $desc   = 'Τέλος Εξέτασης #' . $examId . ($athleteName !== '' ? ' — ' . $athleteName : '');
    $notes  = $isPaid ? $baseMarker : $baseMarker . ':pending';

    $find = $db->prepare("SELECT id FROM transactions WHERE school_id = ? AND notes LIKE ? LIMIT 1");
    $find->execute([$schoolId, '%' . $baseMarker . '%']);
    $txId = (int)($find->fetchColumn() ?: 0);

    if ($txId > 0) {
        $db->prepare(
            "UPDATE transactions SET amount=?, transaction_date=?, description=?, athlete_id=?, notes=? WHERE id=?"
        )->execute([$amount, $txDate, $desc, $athleteId > 0 ? $athleteId : null, $notes, $txId]);
        return;
    }

    $db->prepare(
        "INSERT INTO transactions
            (school_id, type, category, amount, description, transaction_date, payment_method, athlete_id, notes)
         VALUES (?, 'income', 'Εξετάσεις Ζωνών', ?, ?, ?, 'cash', ?, ?)"
    )->execute([$schoolId, $amount, $desc, $txDate, $athleteId > 0 ? $athleteId : null, $notes]);
}

// ── Helper: sync a subscription payment to the transactions table ──────────
function syncSubscriptionTransaction(
    PDO $db,
    string $action,
    int $schoolId,
    int $subId,
    int $athleteId        = 0,
    float $amount         = 0,
    string $paidAt        = '',
    string $method        = 'cash',
    string $validFrom     = '',
    string $validUntil    = '',
    string $athleteName   = ''
): void {
    $marker = 'sub_id:' . $subId;

    if ($action === 'delete') {
        $db->prepare("DELETE FROM transactions WHERE school_id=? AND notes LIKE ?")
           ->execute([$schoolId, '%' . $marker . '%']);
        return;
    }

    if ($amount <= 0) return;

    $txDate   = $paidAt ?: date('Y-m-d');
    $pmMethod = in_array($method, ['cash','card','deposit','other'], true) ? $method : 'cash';

    $gm = ['','Ιαν','Φεβ','Μαρ','Απρ','Μαι','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];
    $desc = 'Συνδρομή';
    if ($validFrom) {
        $ts  = strtotime($validFrom);
        $desc = 'Συνδρομή ' . ($gm[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    }
    if ($athleteName) $desc .= ' — ' . $athleteName;

    $notes = $marker;

    $existing = $db->prepare("SELECT id FROM transactions WHERE school_id=? AND notes LIKE ? LIMIT 1");
    $existing->execute([$schoolId, '%' . $marker . '%']);
    $txId = $existing->fetchColumn();

    if ($txId) {
        $db->prepare("UPDATE transactions SET amount=?, transaction_date=?, payment_method=?, description=?, athlete_id=? WHERE id=?")
           ->execute([$amount, $txDate, $pmMethod, $desc, $athleteId ?: null, $txId]);
    } else {
        $db->prepare("INSERT INTO transactions (school_id, type, category, amount, description, transaction_date, payment_method, athlete_id, notes) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$schoolId, 'income', 'Συνδρομές', $amount, $desc, $txDate, $pmMethod, $athleteId ?: null, $notes]);
    }
}

// ── Helper: get athlete name by id ──────────────────────────────────────────
function getAthleteName(PDO $db, int $athleteId): string {
    $s = $db->prepare("SELECT full_name FROM athletes WHERE id=? LIMIT 1");
    $s->execute([$athleteId]);
    return (string)($s->fetchColumn() ?: '');
}

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST;

    // Save payment (both monthly subscriptions and exam fees)
    if (($a['_action'] ?? '') === 'save_payment') {
        verifyCsrf();

        $athId  = (int)$a['athlete_id'];
        $months = (array)($a['months'] ?? []);
        $examIds = (array)($a['exam_ids'] ?? []);   // list of exam participant IDs to pay
        $amount = floatval(str_replace(',', '.', $a['amount'] ?? 0));
        $method = $a['payment_method'] ?? 'cash';
        $paidAt = trim($a['paid_at'] ?? '') ?: date('Y-m-d');
        $notes  = trim($a['notes'] ?? '');

        if ($amount < 0) {
            flash('Το ποσό δεν μπορεί να είναι αρνητικό.', 'danger');
            redirect(APP_URL . '/pages/subscriptions.php');
        }

        if (!in_array($method, ['cash', 'card', 'deposit'], true)) {
            $method = 'cash';
        }

        // Verify athlete belongs to this school
        $chk = $db->prepare("SELECT id FROM athletes WHERE id=? AND school_id=?");
        $chk->execute([$athId, $sid]);
        if (!$chk->fetch()) {
            flash('Αθλητής δεν βρέθηκε.', 'danger');
            redirect(APP_URL . '/pages/subscriptions.php');
        }

        // --- Process monthly subscriptions ---
        // FIX: Each month gets its own amount from month_amounts[] POST param.
        // This correctly handles partial payments where each month may have a different remaining balance.
        $monthAmounts = $_POST['month_amounts'] ?? []; // [YYYY-MM => amount]
        $savedMonths = 0;
        $savedSubIds = [];
        foreach ($months as $month) {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) continue;

            // Use per-month amount if provided, otherwise fall back to global $amount
            $monthAmt = isset($monthAmounts[$month])
                ? max(0, floatval(str_replace(',', '.', $monthAmounts[$month])))
                : $amount;

            if ($monthAmt <= 0) continue; // skip zero-amount months

            $validFrom  = $month . '-01';
            $validUntil = date('Y-m-t', strtotime($validFrom));

            try {
                // Always INSERT a new row for each payment (allows multiple partial payments per month)
                $db->prepare(
                    "INSERT INTO subscriptions
                        (athlete_id, school_id, valid_from, valid_until, amount, payment_method, paid_at, notes, status)
                     VALUES (?,?,?,?,?,?,?,?,'paid')"
                )->execute([$athId, $sid, $validFrom, $validUntil, $monthAmt, $method, $paidAt, $notes]);
                $savedSubIds[] = (int)$db->lastInsertId();
                $savedMonths++;
            } catch (Exception $e) {
                // Fallback for old schema with unique constraint
                try {
                    $db->prepare(
                        "INSERT INTO subscriptions
                            (athlete_id, school_id, valid_from, valid_until, amount, payment_method, paid_at, notes, status)
                         VALUES (?,?,?,?,?,?,?,?,'paid')
                         ON DUPLICATE KEY UPDATE
                            amount=amount + VALUES(amount),
                            payment_method=VALUES(payment_method),
                            paid_at=VALUES(paid_at),
                            notes=VALUES(notes),
                            status='paid'"
                    )->execute([$athId, $sid, $validFrom, $validUntil, $monthAmt, $method, $paidAt, $notes]);
                    $getSubId = $db->prepare("SELECT id FROM subscriptions WHERE athlete_id=? AND school_id=? AND valid_from=? ORDER BY id DESC LIMIT 1");
                    $getSubId->execute([$athId, $sid, $validFrom]);
                    $existingId = (int)$getSubId->fetchColumn();
                    if ($existingId) $savedSubIds[] = $existingId;
                    $savedMonths++;
                } catch (Exception $e2) {}
            }
        }

        if ($savedMonths > 0) {
            $athName = getAthleteName($db, $athId);
            foreach ($savedSubIds as $ssId) {
                $sr = $db->prepare("SELECT id, valid_from, valid_until, amount FROM subscriptions WHERE id=? LIMIT 1");
                $sr->execute([$ssId]);
                $ss = $sr->fetch();
                if ($ss) {
                    syncSubscriptionTransaction($db, 'upsert', $sid, (int)$ss['id'], $athId, (float)$ss['amount'], $paidAt, $method, $ss['valid_from'], $ss['valid_until'], $athName);
                }
            }

            // Send subscription confirmation email to athlete/parent
            try {
                $athRow = $db->prepare("SELECT id, school_id, full_name, email, parent_email FROM athletes WHERE id=? AND school_id=? LIMIT 1");
                $athRow->execute([$athId, $sid]);
                $athData = $athRow->fetch();
                if ($athData) {
                    // Summarise all saved months into one email (total amount, date range)
                    $totalAmt = 0.0;
                    $allFrom  = []; $allUntil = [];
                    foreach ($savedSubIds as $eId) {
                        $es = $db->prepare("SELECT valid_from, valid_until, amount FROM subscriptions WHERE id=? LIMIT 1");
                        $es->execute([$eId]);
                        $er = $es->fetch();
                        if ($er) { $totalAmt += (float)$er['amount']; $allFrom[] = $er['valid_from']; $allUntil[] = $er['valid_until']; }
                    }
                    sort($allFrom); rsort($allUntil);
                    $subSummary = ['amount' => $totalAmt, 'valid_from' => $allFrom[0] ?? null, 'valid_until' => $allUntil[0] ?? null];
                    sendNewSubscriptionEmail($athData, $subSummary);
                }
            } catch (Throwable $mailEx) {
                error_log('[subscriptions] subscription email error: ' . $mailEx->getMessage());
            }
        }

        // Belt exam fee processing removed (belt_exam_participants table no longer exists)
        $savedExams = 0;

        $msg = [];
        if ($savedMonths > 0) $msg[] = $savedMonths . ' πληρωμή' . ($savedMonths === 1 ? 'ή' : 'ές') . ' μηνών';
        if ($savedExams > 0) $msg[] = $savedExams . ' τέλο' . ($savedExams === 1 ? 'ς' : 'ς') . ' εξέτασης';
        if (!empty($msg)) {
            flash('Καταχωρήθηκαν ' . implode(' και ', $msg) . '!');
            auditLog('save_payment', 'athlete', $athId);
        } else {
            flash('Δεν επιλέχθηκε τίποτα προς πληρωμή.', 'danger');
        }

        redirect(APP_URL . '/pages/subscriptions.php');
    }

    // --- Other POST actions (delete_payment, edit_payment) remain unchanged ---
    if (($a['_action'] ?? '') === 'delete_payment') {
        verifyCsrf();
        $pid = (int)$a['id'];
        syncSubscriptionTransaction($db, 'delete', $sid, $pid);
        $db->prepare("DELETE FROM subscriptions WHERE id=? AND school_id=?")->execute([$pid, $sid]);
        flash('Πληρωμή διαγράφηκε.', 'danger');
        $back = trim($a['redirect_back'] ?? '');
        redirect($back ?: APP_URL . '/pages/subscriptions.php');
    }

    if (($a['_action'] ?? '') === 'edit_payment') {
        verifyCsrf();
        $pid    = (int)$a['id'];
        $amount = floatval(str_replace(',', '.', $a['amount'] ?? 0));
        $method = in_array($a['payment_method'] ?? '', ['cash','card','deposit'], true) ? $a['payment_method'] : 'cash';
        $paidAt = trim($a['paid_at'] ?? '') ?: date('Y-m-d');
        $notes  = trim($a['notes'] ?? '');
        $status = in_array($a['status'] ?? '', ['paid','pending','overdue'], true) ? $a['status'] : 'paid';
        if ($amount < 0) $amount = 0;
        $db->prepare("UPDATE subscriptions SET amount=?, payment_method=?, paid_at=?, notes=?, status=? WHERE id=? AND school_id=?")
           ->execute([$amount, $method, $paidAt, $notes, $status, $pid, $sid]);
        if ($status === 'paid' && $amount > 0) {
            $subRow = $db->prepare("SELECT athlete_id, valid_from, valid_until FROM subscriptions WHERE id=? LIMIT 1");
            $subRow->execute([$pid]);
            $sr = $subRow->fetch();
            if ($sr) {
                $athName = getAthleteName($db, (int)$sr['athlete_id']);
                syncSubscriptionTransaction($db, 'upsert', $sid, $pid, (int)$sr['athlete_id'], $amount, $paidAt, $method, $sr['valid_from'], $sr['valid_until'], $athName);
            }
        } else {
            syncSubscriptionTransaction($db, 'delete', $sid, $pid);
        }
        flash('Η πληρωμή ενημερώθηκε!');
        $back = trim($a['redirect_back'] ?? '');
        redirect($back ?: APP_URL . '/pages/subscriptions.php');
    }
}

// ── List filters ──
$subSearch = trim($_GET['sq'] ?? '');

$perPage = 10;
$listPage   = max(1, (int)($_GET['lpage'] ?? 1));
$listOffset = ($listPage - 1) * $perPage;

$where  = "a.school_id=? AND a.active=1"; $params = [$sid];
if ($subSearch) { $where .= " AND a.full_name LIKE ?"; $params[] = "%$subSearch%"; }

$countStmt = $db->prepare("SELECT COUNT(*) FROM athletes a WHERE $where");
$countStmt->execute($params);
$listTotal = (int)$countStmt->fetchColumn();
$listPages = max(1, ceil($listTotal / $perPage));

$stmt = $db->prepare(
    "SELECT a.*, d.name as dept_name FROM athletes a
     LEFT JOIN departments d ON d.id=a.department_id
     WHERE $where ORDER BY a.full_name LIMIT $perPage OFFSET $listOffset"
);
$stmt->execute($params);
$athleteRows = $stmt->fetchAll();

// ── Compute debt for each listed athlete and also fetch pending exam fees ──
$rowDebts = [];
foreach ($athleteRows as $row) {
    $ds = subGetDebtStartDate($row);
    $summary = subGetDebtSummary($db, $row['id'], $ds, floatval($row['monthly_fee'] ?? 0));
    $rowDebts[$row['id']] = [
        'months'  => $summary['months'],
        'balance' => $summary['balance'],
        'fee'     => floatval($row['monthly_fee'] ?? 0),
        'exam_fee_list' => subGetAthleteExamFeeDebts($db, (int)$row['id'], $sid),
    ];
}

// ── Summary counts ──
$totalOk   = 0; $totalDebt = 0;
foreach ($rowDebts as $d) {
    $hasExamDebt = !empty($d['exam_fee_list']);
    ($d['balance'] <= 0.009 && !$hasExamDebt) ? $totalOk++ : $totalDebt++;
}

// ── All athletes for the modal dropdown (active) ──
$allAthStmt = $db->prepare(
    "SELECT id, full_name, registration_date, debt_from_month, monthly_fee
     FROM athletes WHERE school_id=? AND active=1 ORDER BY full_name"
);
$allAthStmt->execute([$sid]);
$allAthletesList = $allAthStmt->fetchAll();

// ── Build JS maps for subscription ranges and exam fees per athlete ──
$athSubRanges = [];
$athExamFees = [];
foreach ($allAthletesList as $al) {
    $ps = $db->prepare("SELECT valid_from, valid_until, amount FROM subscriptions WHERE athlete_id=? AND status='paid'");
    $ps->execute([$al['id']]);
    $athSubRanges[$al['id']] = $ps->fetchAll(PDO::FETCH_ASSOC);

    $examList = subGetAthleteExamFeeDebts($db, (int)$al['id'], $sid);
    $athExamFees[$al['id']] = $examList;
}

function subPageUrl(int $p): string {
    $q = $_GET; $q['lpage'] = $p;
    return '?' . http_build_query($q);
}

renderHead('Πληρωμές');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── Reset topbar positioning ── */
.topbar { position: relative !important; top: auto !important; z-index: auto !important; }

/* ── Mobile sidebar ── */
@media (max-width: 900px) {
    #menuBtn { display: inline-flex !important; min-width: 44px !important; min-height: 44px !important; align-items: center !important; justify-content: center !important; font-size: 1.2rem !important; cursor: pointer !important; }
    .sidebar { position: fixed !important; top: 0 !important; left: 0 !important; bottom: 0 !important; width: min(280px,80vw) !important; z-index: 9999 !important; transform: translateX(-110%) !important; transition: transform .28s cubic-bezier(.2,.8,.2,1) !important; overflow-y: auto; }
    .sidebar.open { transform: translateX(0) !important; box-shadow: 6px 0 40px rgba(0,0,0,.6) !important; }
    .main-content { margin-left: 0 !important; width: 100% !important; }
}
#dm-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9998; cursor: pointer; }
#dm-overlay.on { display: block; }

/* ── Prevent content overflow ── */
.main-content { overflow-x: hidden !important; min-width: 0 !important; }
.page-body { overflow-x: hidden !important; box-sizing: border-box; }

/* ── Animations ── */
@keyframes fadeUp  { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeIn  { from { opacity:0; } to { opacity:1; } }
@keyframes slideUp { from { opacity:0; transform:translateY(40px); } to { opacity:1; transform:translateY(0); } }
.page-body { animation: fadeIn .35s ease both; }
.anim-1 { opacity:0; animation: fadeUp .42s ease-out .05s both; }
.anim-2 { opacity:0; animation: fadeUp .42s ease-out .12s both; }
.anim-3 { opacity:0; animation: fadeUp .42s ease-out .19s both; }
.anim-4 { opacity:0; animation: fadeUp .42s ease-out .26s both; }
@media (prefers-reduced-motion: reduce) { .page-body,.anim-1,.anim-2,.anim-3,.anim-4 { animation:none!important; opacity:1; } }

/* ── Cards ── */
.card { border-radius: 18px; overflow: hidden; }
.card-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; padding: .9rem 1.1rem; border-bottom: 1px solid var(--border, #1e2536); }
.card-title { font-size: clamp(1rem, 3.5vw, 1.1rem) !important; font-weight: 800; display: flex; align-items: center; gap: .45rem; }

/* ── Stat cards ── */
.stat-cards-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.1rem; }
.stat-card { border-radius: 18px; padding: 1rem 1.1rem; display: flex; flex-direction: row; align-items: center; gap: .85rem; }
.stat-icon { width: 48px; height: 48px; min-width: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
.icon-blue  { background: rgba(59,130,246,.15); color: #3b82f6; }
.icon-green { background: rgba(45,198,83,.15);  color: #2dc653; }
.icon-red   { background: rgba(230,57,70,.15);  color: #e63946; }
.stat-text  { display: flex; flex-direction: column; gap: .1rem; }
.stat-lbl   { font-size: clamp(.78rem, 2vw, .88rem) !important; color: var(--muted, #8892b0); font-weight: 600; line-height: 1.2; }
.stat-val   { font-size: clamp(1.4rem, 3.5vw, 2rem) !important; font-weight: 800; line-height: 1; }
@media (max-width: 700px) {
    .stat-cards-row { gap: .6rem; }
    .stat-card { padding: .7rem .8rem; gap: .55rem; }
    .stat-icon { width: 38px; height: 38px; min-width: 38px; font-size: .95rem; border-radius: 11px; }
    .stat-val  { font-size: clamp(1.2rem, 5vw, 1.5rem) !important; }
}
@media (max-width: 360px) {
    .stat-cards-row { grid-template-columns: 1fr 1fr; }
    .stat-cards-row .stat-card:nth-child(3) { grid-column: span 2; }
}

/* ── Page header ── */
.page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem; margin-bottom: 1rem; }
.page-header h2 { font-size: clamp(1.15rem, 4vw, 1.5rem) !important; font-weight: 800; display: flex; align-items: center; gap: .5rem; margin: 0; }

@media (max-width: 900px) { .page-body { padding: 1rem !important; } }
@media (max-width: 600px) { .page-body { padding: .75rem !important; } }
@media (max-width: 400px) { .page-body { padding: .55rem !important; } }

/* ── Filters ── */
.filters-bar { display: flex; flex-wrap: wrap; gap: .55rem; align-items: center; margin-bottom: .9rem; }
.filters-bar .form-control,
.filters-bar select { font-size: clamp(.88rem, 3.5vw, .95rem) !important; height: 40px; min-height: 40px; padding: 0 .75rem; }
.search-bar { position: relative; flex: 1; min-width: 160px; }
.search-bar .search-icon { position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); color: var(--muted,#8892b0); pointer-events: none; z-index: 2; }
.search-bar input { width: 100%; padding-left: 2.4rem !important; font-size: clamp(.9rem,3.5vw,1rem) !important; min-height: 40px; border-radius: 12px !important; }
@media (max-width: 700px) {
    .filters-bar { display: flex !important; flex-wrap: nowrap !important; gap: .5rem !important; align-items: center; }
    .filters-bar .search-bar { flex: 1 1 0; min-width: 0; }
}

/* ── Table ── */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
.table-wrap table { width: max-content; min-width: 100%; border-collapse: collapse; }
.table-wrap th, .table-wrap td { white-space: nowrap; }
.table-wrap th { font-size: .78rem !important; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; padding: .6rem 1rem; }
.table-wrap td { font-size: 1rem !important; padding: .75rem 1rem; vertical-align: middle; line-height: 1.4; }
.table-wrap tbody tr { transition: background .15s; cursor: pointer; }
.table-wrap tbody tr:hover { background: rgba(255,255,255,.03); }
.td-name { font-weight: 800; font-size: 1.05rem !important; }
@media (max-width: 700px) {
    .table-wrap th { font-size: .82rem !important; padding: .65rem .9rem; letter-spacing: .02em; }
    .table-wrap td { font-size: 1.05rem !important; padding: .8rem .9rem; }
    .td-name { font-size: 1.1rem !important; }
}
@media (max-width: 480px) {
    .table-wrap th { font-size: .88rem !important; padding: .7rem .75rem; }
    .table-wrap td { font-size: 1.1rem !important; padding: .85rem .75rem; }
    .td-name { font-size: 1.15rem !important; }
    .debt-badge { font-size: .88rem !important; padding: .28rem .75rem; }
}
@media (max-width: 380px) {
    .table-wrap th { font-size: .9rem !important; }
    .table-wrap td { font-size: 1.15rem !important; padding: .9rem .65rem; }
    .td-name { font-size: 1.2rem !important; }
}

/* ── Buttons ── */
.btn { min-height: 38px; font-size: clamp(.88rem, 3vw, .95rem) !important; font-weight: 700 !important; display: inline-flex; align-items: center; gap: .4rem; border-radius: 10px; transition: all .18s; text-decoration: none; padding: .45rem .9rem; cursor: pointer; border: none; white-space: nowrap; }
.btn:active { transform: scale(.97); }
.btn-sm { min-height: 34px; padding: .4rem .8rem; }
.btn-action-text { display:inline-flex;align-items:center;gap:.28rem;font-size:.78rem !important;font-weight:700;padding:.3rem .58rem;min-height:28px;border-radius:8px;text-decoration:none;transition:all .18s;cursor:pointer;border:none;white-space:nowrap;line-height:1; }

/* ── Status badges ── */
.debt-badge { display: inline-flex; align-items: center; gap: .3rem; padding: .22rem .6rem; border-radius: 20px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
.debt-badge.ok   { background: rgba(45,198,83,.12);  color: #2dc653; border: 1px solid rgba(45,198,83,.3); }
.debt-badge.low  { background: rgba(240,165,0,.12);  color: #f0a500; border: 1px solid rgba(240,165,0,.3); }
.debt-badge.high { background: rgba(230,57,70,.12);  color: #e63946; border: 1px solid rgba(230,57,70,.3); }

/* ── Pagination ── */
.pagination-wrap { display: flex; align-items: center; justify-content: center; gap: .35rem; padding: 1rem 1.25rem; border-top: 1px solid var(--border,#1e2536); flex-wrap: wrap; }
.pagination-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; height: 44px; border-radius: 10px; border: 1px solid #1e2536; color: #8892b0; text-decoration: none; transition: all .2s; }
.pagination-btn:hover { border-color: #e63946; color: #fff; }
.pagination-btn.active { border-color: #e63946; background: rgba(230,57,70,.15); color: #e63946; font-weight: 800; }

/* ── Empty state ── */
.empty-state { text-align: center; padding: 2.5rem 1rem; }
.empty-state .empty-icon { font-size: 2.5rem; margin-bottom: .65rem; opacity: .4; }
.empty-state p { font-size: .95rem !important; color: var(--muted,#8892b0); margin: 0 0 .75rem; }

/* ══════════════════════════════════════════
   PAYMENT MODAL — centered, responsive, never crops
   With red scrollbar to indicate scrollable area
══════════════════════════════════════════ */
@keyframes modalOverlayIn  { from { opacity:0; } to { opacity:1; } }
@keyframes modalOverlayOut { from { opacity:1; } to { opacity:0; } }
@keyframes modalBoxIn      { from { opacity:0; transform:scale(.93) translateY(14px); } to { opacity:1; transform:scale(1) translateY(0); } }
@keyframes modalBoxOut     { from { opacity:1; transform:scale(1)   translateY(0);    } to { opacity:0; transform:scale(.93) translateY(14px); } }

#paymentModal {
    display: flex;
    position: fixed;
    inset: 0;
    z-index: 10500;
    background: rgba(0,0,0,.82);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    align-items: center;
    justify-content: center;
    padding: 1rem;
    box-sizing: border-box;
    opacity: 0;
    pointer-events: none;
    overscroll-behavior: contain;
}
#paymentModal.open {
    opacity: 1;
    pointer-events: all;
    animation: modalOverlayIn .28s ease both;
}
#paymentModal.closing {
    animation: modalOverlayOut .22s ease both;
    pointer-events: none;
}

#paymentModalBox {
    background: #111827;
    border: 1.5px solid rgba(230,57,70,.3);
    border-radius: 22px;
    width: 100%;
    max-width: 520px;
    height: auto;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    opacity: 0;
    transform: scale(.93) translateY(14px);
    box-shadow: 0 8px 40px rgba(0,0,0,.5);
    min-height: 0;
}
#paymentModal.open #paymentModalBox {
    animation: modalBoxIn .3s cubic-bezier(.22,1,.36,1) both;
}
#paymentModal.closing #paymentModalBox {
    animation: modalBoxOut .22s cubic-bezier(.22,1,.36,1) both;
}
#paymentModalBox::before { display: none; }

.modal-head {
    flex-shrink: 0;
    position: sticky;
    top: 0;
    z-index: 2;
    background: #111827;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .85rem 1.25rem .75rem;
    border-bottom: 1px solid rgba(255,255,255,.07);
    gap: .75rem;
}
.modal-head h3 {
    font-size: 1.05rem;
    font-weight: 800;
    color: #e63946;
    margin: 0;
    display: flex;
    align-items: center;
    gap: .45rem;
}
.modal-close-btn {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.1);
    background: rgba(255,255,255,.04);
    color: #6b7494;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .95rem;
    transition: all .18s;
    flex-shrink: 0;
}
.modal-close-btn:hover {
    background: rgba(230,57,70,.15);
    color: #e63946;
    border-color: rgba(230,57,70,.3);
}

.modal-body {
    flex: 1 1 auto;
    overflow-x: auto;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    min-height: 0;
    padding: 1rem 1.25rem 1.5rem;
}
.modal-footer {
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
    z-index: 2;
    background: #0d1018;
    padding: .9rem 1.25rem;
    border-top: 1px solid rgba(255,255,255,.07);
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
    border-radius: 0 0 22px 22px;
}
.modal-footer .btn {
    flex: 1;
    min-width: 0;
    justify-content: center;
}

/* RED SCROLLBAR */
.modal-body::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}
.modal-body::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
}
.modal-body::-webkit-scrollbar-thumb {
    background: #e63946;
    border-radius: 10px;
}
.modal-body::-webkit-scrollbar-thumb:hover {
    background: #ff6b7a;
}
.modal-body {
    scrollbar-width: thin;
    scrollbar-color: #e63946 rgba(255,255,255,0.1);
}

/* Responsive adjustments */
@media (max-width: 600px) {
    #paymentModal {
        padding: 0.75rem;
    }
    #paymentModalBox {
        max-width: 100%;
        border-radius: 22px;
    }
    .modal-head {
        padding: 0.7rem 1rem;
    }
    .modal-body {
        padding: 0.75rem 1rem 1rem;
    }
    .modal-footer {
        padding: 0.75rem 1rem;
    }
}
@media (max-width: 480px) {
    #paymentModalBox {
        width: calc(100% - 1rem);
        margin: 0.5rem;
    }
    .modal-head {
        padding: 0.6rem 0.85rem;
    }
    .modal-body {
        padding: 0.65rem 0.85rem 1rem;
    }
    .modal-footer {
        padding: 0.65rem 0.85rem;
    }
    .modal-head h3 {
        font-size: 0.95rem;
    }
    .month-chip-btn {
        min-height: 52px;
        padding: 0.75rem 1rem;
    }
    .chip-label {
        font-size: 0.95rem;
    }
}
@media (max-width: 360px) {
    #paymentModalBox {
        width: calc(100% - 0.5rem);
        margin: 0.25rem;
    }
    .modal-head {
        padding: 0.5rem 0.7rem;
    }
    .modal-body {
        padding: 0.6rem 0.7rem 0.9rem;
    }
    .modal-footer {
        padding: 0.6rem 0.7rem;
    }
    .month-chip-btn {
        min-height: 56px;
        padding: 0.75rem 0.85rem;
    }
    .chip-label {
        font-size: 0.9rem;
    }
}

.modal-section-title { font-size: .72rem !important; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: var(--muted, #8892b0); margin-bottom: .6rem; display: flex; align-items: center; gap: .4rem; padding-bottom: .45rem; border-bottom: 1px solid rgba(255,255,255,.06); }

.form-label { font-size: .92rem !important; font-weight: 700; display: block; margin-bottom: .35rem; }
.form-control { font-size: .95rem !important; min-height: 46px; padding: .65rem .9rem; border-radius: 11px !important; transition: border-color .2s, box-shadow .2s; width: 100%; background: #0d1422; border: 1.5px solid rgba(255,255,255,.1); color: #f0f2ff; }
.form-control:focus { outline: none; border-color: #e63946 !important; box-shadow: 0 0 0 3px rgba(230,57,70,.15) !important; }
.form-control::placeholder { color: #4a5068; }
select.form-control { cursor: pointer; }

.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
@media (max-width: 420px) { .form-row-2 { grid-template-columns: 1fr; } }

.month-grid { display: flex; flex-direction: column; gap: .4rem; margin-top: .35rem; }

.month-chip-btn {
    display: flex;
    align-items: center;
    gap: .55rem;
    padding: .6rem .9rem;
    border-radius: 11px;
    border: 1.5px solid rgba(255,255,255,.08);
    background: rgba(255,255,255,.03);
    cursor: pointer;
    transition: all .18s;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
    width: 100%;
    box-sizing: border-box;
    min-height: 46px;
    flex-wrap: wrap;
    height: auto;
}
.month-chip-btn:hover { border-color: rgba(230,57,70,.35); background: rgba(230,57,70,.06); }
.month-chip-btn.selected { border-color: #e63946; background: rgba(230,57,70,.15); }
.month-chip-btn input[type=checkbox] { display: none; }
.chip-label {
    font-size: .9rem;
    font-weight: 700;
    color: #c8cce0;
    flex: 1;
    white-space: normal;
    word-break: break-word;
    line-height: 1.3;
    padding-right: 0.25rem;
}
.month-chip-btn.selected .chip-label { color: #ff8a93; }
.chip-check {
    width: 18px;
    height: 18px;
    min-width: 18px;
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,.18);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: .6rem;
    transition: all .18s;
    color: transparent;
}
.month-chip-btn.selected .chip-check { border-color: #e63946; background: #e63946; color: #fff; }

.total-preview { background: rgba(45,198,83,.07); border: 1px solid rgba(45,198,83,.2); border-radius: 11px; padding: .7rem 1rem; margin-top: .75rem; display: none; }
.total-preview.visible { display: block; }

.month-actions-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-bottom: .5rem; }
.month-actions-row .btn-tiny { font-size: .75rem !important; padding: .28rem .65rem; min-height: 28px; border-radius: 7px; font-weight: 700; cursor: pointer; border: 1px solid; transition: all .18s; display: inline-flex; align-items: center; gap: .25rem; }
.btn-tiny-red  { background: rgba(230,57,70,.1);   color: #e63946; border-color: rgba(230,57,70,.3); }
.btn-tiny-red:hover  { background: rgba(230,57,70,.2); }
.btn-tiny-grey { background: rgba(139,148,180,.08); color: #8892b0; border-color: rgba(139,148,180,.2); }
.btn-tiny-grey:hover { background: rgba(139,148,180,.15); }

.modal-info-box { background: rgba(59,130,246,.07); border: 1px solid rgba(59,130,246,.2); border-radius: 10px; padding: .6rem .9rem; font-size: .82rem; color: #7baef5; margin-bottom: .85rem; display: flex; align-items: flex-start; gap: .5rem; line-height: 1.4; }

.nav-item { min-height: 46px !important; font-size: clamp(.92rem, 3vw, 1rem) !important; font-weight: 600 !important; padding: .65rem .9rem !important; border-radius: 10px !important; display: flex !important; align-items: center !important; gap: .7rem !important; transition: background .15s, color .15s !important; text-decoration: none; }
.nav-item .icon { width: 22px; text-align: center; font-size: 1rem; flex-shrink: 0; }
.sidebar-school { margin: .25rem 1rem !important; padding: 0 !important; display: flex !important; align-items: center !important; font-weight: 700 !important; font-size: clamp(.82rem, 3vw, .92rem) !important; color: var(--text, #f0f2ff) !important; background: none !important; border: none !important; box-shadow: none !important; }

.col-hide-mobile { display: table-cell; }
@media (max-width: 600px) { .col-hide-mobile { display: none !important; } }

/* Extra small screen helpers */
@media (max-width: 480px) {
    .col-hide-sm { display: none !important; }
    .btn-pay-label { display: none; }
    .btn-action-text { padding: .3rem .55rem; }
    .card { border-radius: 14px; }
    .card-header { padding: .7rem .85rem; }
    .stat-card { border-radius: 14px; }
}
@media (max-width: 360px) {
    .page-header h2 { font-size: 1rem !important; }
    .stat-val { font-size: 1.15rem !important; }
}

/* ── Table borders and separators (same as dashboard) ── */
.table-wrap table,
.table-wrap th,
.table-wrap td {
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-collapse: collapse;
}
.table-wrap th {
    background: rgba(0, 0, 0, 0.2);
    font-weight: 800;
}
.table-wrap td {
    background: transparent;
}

/* ── Mobile layout improvements for debt items (horizontal display) ── */
@media (max-width: 700px) {
    .debt-info-inline {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        align-items: center;
    }
    .debt-info-inline > div {
        display: inline-flex;
        align-items: center;
        white-space: nowrap;
    }
    .debt-badge, .month-chip.unpaid {
        white-space: nowrap;
    }
}

/* ── Payment method dropdown options — always dark, never white ── */
.pay-method-opt {
    background: #0d1422 !important;
}
.pay-method-opt:hover {
    background: rgba(255,255,255,.06) !important;
}
.pay-method-opt.active-method {
    background: rgba(230,57,70,.15) !important;
}
</style>

<body>
<div class="app-layout">
<?php renderSidebar('subscriptions'); ?>
<div id="dm-overlay"></div>
<div class="main-content">
<?php renderTopbar('Πληρωμές'); ?>
<div class="page-body">

<!-- ── Stat cards ── -->
<div class="stat-cards-row anim-1">
    <div class="stat-card card">
        <div class="stat-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-text"><div class="stat-lbl">Ενήμεροι</div><div class="stat-val"><?= $totalOk ?></div></div>
    </div>
    <div class="stat-card card">
        <div class="stat-icon icon-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="stat-text"><div class="stat-lbl">Με Οφειλή</div><div class="stat-val"><?= $totalDebt ?></div></div>
    </div>
</div>

<!-- ── Page header ── -->
<div class="page-header anim-2" style="flex-wrap:nowrap;gap:.5rem">
    <h2 style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
        <i class="fa-solid fa-money-bill-wave" style="color:var(--red,#e63946)"></i>
        Πληρωμές
        <span style="font-size:clamp(.82rem,3vw,.9rem);font-weight:600;color:var(--muted,#8892b0);margin-left:.4rem">(<?= $listTotal ?>)</span>
    </h2>
    <button type="button" class="btn btn-primary btn-sm" onclick="openPaymentModal()" style="flex-shrink:0">
        <i class="fa-solid fa-plus"></i> <span>Νέα Πληρωμή</span>
    </button>
</div>

<!-- ── Filters ── -->
<form method="GET" class="anim-3" id="filterForm">
<div class="filters-bar anim-3">
    <div class="search-bar">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass" id="searchIcon"></i></span>
        <input type="text" id="searchInput" value="<?= h($subSearch) ?>"
               placeholder="Αναζήτηση αθλητή..."
               autocomplete="off"
               inputmode="search"
               enterkeyhint="search">
        <span id="searchSpinner" style="display:none;position:absolute;right:.8rem;top:50%;transform:translateY(-50%)">
            <i class="fa-solid fa-spinner fa-spin" style="color:#e63946;font-size:.9rem"></i>
        </span>
    </div>
</div>
</form>

<!-- ── Table card ── -->
<div class="card p-0 anim-4">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;border-bottom:1px solid var(--border,#1e2536);flex-wrap:wrap;gap:.4rem;">
        <span style="font-size:clamp(.82rem,3vw,.9rem);color:var(--muted,#8892b0);">
            <?= $listTotal ?> αθλητές<?= $subSearch ? ' — φιλτραρισμένα' : '' ?>
        </span>
        <?php if ($listPages > 1): ?>
        <span style="font-size:clamp(.82rem,3vw,.9rem);color:var(--muted,#8892b0);">Σελίδα <?= $listPage ?> / <?= $listPages ?></span>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Αθλητής</th>
                    <th>Κατάσταση</th>
                    <th>Οφειλές</th>
                    <th>Τελ. Πληρωμή</th>
                    <th>€/μήνα</th>
                    <th>Τμήμα</th>
                    <th>Ενέργεια</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($athleteRows as $row):
                    $debtInfo       = $rowDebts[$row['id']];
                    $debt           = $debtInfo['months'];
                    $debtBalance    = floatval($debtInfo['balance'] ?? 0);
                    $monthlyFee     = $debtInfo['fee'];
                    $examFeeList    = $debtInfo['exam_fee_list'];
                    $totalOwed      = $debtBalance + array_sum(array_column($examFeeList, 'amount'));

                    $lastSub = $db->prepare(
                        "SELECT valid_until FROM subscriptions WHERE athlete_id=? AND status='paid' ORDER BY valid_until DESC LIMIT 1"
                    );
                    $lastSub->execute([$row['id']]);
                    $lastUntil = $lastSub->fetchColumn();
                    $lastLabel = '';
                    if ($lastUntil) {
                        $lu = new DateTime($lastUntil);
                        $lastLabel = $greekMonthsShort[(int)$lu->format('m')] . ' ' . $lu->format('Y');
                    }
                ?>
                <tr onclick="openPaymentModal(<?= $row['id'] ?>)" title="Καταχώρηση πληρωμής για <?= h($row['full_name']) ?>">
                    <td class="td-name" style="white-space:nowrap"><?= h($row['full_name'] ?? '') ?></td>
                    <td style="white-space:nowrap">
                    <?php if ($totalOwed <= 0.009): ?>
                        <span class="debt-badge ok"><i class="fa-solid fa-circle-check"></i> Ενήμερος</span>
                    <?php elseif ($debt <= 2 && empty($examFeeList)): ?>
                        <span class="debt-badge low"><i class="fa-solid fa-clock"></i> Εκκρεμεί</span>
                    <?php else: ?>
                        <span class="debt-badge high"><i class="fa-solid fa-triangle-exclamation"></i> Ληξιπρόθεσμος</span>
                    <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;font-weight:700">
                        <?php if ($totalOwed > 0.009): ?>
                            <div class="debt-info-inline" style="display:flex;flex-direction:column;gap:.2rem">
                            <?php if ($debtBalance > 0.009): ?>
                                <span style="color:<?= $debt >= 3 ? '#e63946' : '#f0a500' ?>">
                                    <?= $debt ?> <?= $debt === 1 ? 'μήνας' : 'μήνες' ?>
                                    &nbsp;·&nbsp;<?= number_format($debtBalance, 2, ',', '.') ?>€
                                </span>
                            <?php endif; ?>
                            <?php foreach ($examFeeList as $ef): ?>
                                <div style="display:flex;align-items:center;gap:.3rem;flex-wrap:wrap">
                                    <span style="color:#f0a500;font-size:.84rem;">
                                        <i class="fa-solid fa-ribbon" style="font-size:.72rem"></i>
                                        Εξέταση <?= number_format((float)$ef['amount'], 2, ',', '.') ?>€
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:#2dc653;font-size:.82rem"><i class="fa-solid fa-check"></i> Καμία</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--muted,#8892b0);font-size:.84rem !important;white-space:nowrap">
                        <?= $lastLabel ?: '—' ?>
                    </td>
                    <td style="font-size:.84rem !important;white-space:nowrap">
                        <?= $monthlyFee > 0 ? number_format($monthlyFee, 2, ',', '.') . '€' : '—' ?>
                    </td>
                    <td style="color:var(--muted,#8892b0);font-size:.82rem !important;white-space:nowrap">
                        <?= h($row['dept_name'] ?? '—') ?>
                    </td>
<td onclick="event.stopPropagation()" style="white-space:nowrap">
                        <?php if ($totalOwed <= 0.009): ?>
                        <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:700;color:#2dc653;opacity:.75">
                            <i class="fa-solid fa-circle-check" style="font-size:.7rem"></i> Έχει πληρώσει
                        </span>
                        <?php else: ?>
                        <button type="button"
                            onclick="openPaymentModal(<?= $row['id'] ?>)"
                            class="btn-action-text"
                            style="background:rgba(45,198,83,.1);color:#2dc653;border:1px solid rgba(45,198,83,.25);">
                            <i class="fa-solid fa-plus"></i> Πληρωμή
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (!$athleteRows): ?>
                <tr><td colspan="5">
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-users"></i></div>
                        <p>Δεν βρέθηκαν αθλητές</p>
                        <a href="<?= APP_URL ?>/pages/athletes.php?action=add" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-plus"></i> Προσθήκη Αθλητή
                        </a>
                    </div>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($listPages > 1): ?>
    <div class="pagination-wrap">
        <?php if ($listPage > 1): ?>
            <a href="<?= subPageUrl(1) ?>" class="pagination-btn" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a>
            <a href="<?= subPageUrl($listPage - 1) ?>" class="pagination-btn"><i class="fa-solid fa-angle-left"></i></a>
        <?php endif; ?>
        <?php for ($i = max(1, $listPage - 2); $i <= min($listPages, $listPage + 2); $i++): ?>
            <a href="<?= subPageUrl($i) ?>" class="pagination-btn <?= $i === $listPage ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($listPage < $listPages): ?>
            <a href="<?= subPageUrl($listPage + 1) ?>" class="pagination-btn"><i class="fa-solid fa-angle-right"></i></a>
            <a href="<?= subPageUrl($listPages) ?>" class="pagination-btn" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a>
        <?php endif; ?>
        <span style="font-size:.88rem;color:#6b7494;margin-left:.5rem;">
            Σελίδα <strong style="color:#e2e8f0"><?= $listPage ?></strong> / <?= $listPages ?>
        </span>
    </div>
    <?php endif; ?>
</div>

</div><!-- /page-body -->
</div><!-- /main-content -->
</div><!-- /app-layout -->

<!-- ═══════════════════════════════════════════════════════════════════════════════════
     UNIFIED PAYMENT MODAL (Monthly subscriptions + Exam fees)
═══════════════════════════════════════════════════════════════════════════════════ -->
<div id="paymentModal" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle"
     onclick="if(event.target===this) closePaymentModal()">
    <div id="paymentModalBox">
        <div class="modal-head">
            <h3 id="paymentModalTitle">
                <i class="fa-solid fa-euro-sign"></i> Καταχώρηση Πληρωμής
            </h3>
            <button type="button" class="modal-close-btn" onclick="closePaymentModal()" aria-label="Κλείσιμο">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">
        <form method="POST" id="paymentForm">
            <input type="hidden" name="_action" value="save_payment">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

            <div class="modal-section-title"><i class="fa-solid fa-user"></i> Αθλητής</div>
            <div style="margin-bottom:1rem;">
                <label class="form-label">Επιλογή αθλητή <span style="color:#e63946">*</span></label>
                <select name="athlete_id" id="modalAthleteSel" class="form-control" required
                        onchange="onAthleteChange(this.value)">
                    <option value="">— Επιλέξτε αθλητή —</option>
                    <?php foreach ($allAthletesList as $al): ?>
                    <option value="<?= $al['id'] ?>"
                        data-regdate="<?= h($al['registration_date'] ?? '') ?>"
                        data-debtfrom="<?= h($al['debt_from_month'] ?? '') ?>"
                        data-fee="<?= floatval($al['monthly_fee'] ?? 0) ?>">
                        <?= h($al['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Monthly subscriptions section -->
            <div class="modal-section-title"><i class="fa-solid fa-calendar-days"></i> Μήνες Πληρωμής</div>
            <div id="monthsEmptyHint" style="display:none;color:var(--muted,#8892b0);font-size:.88rem;padding:.25rem 0 .75rem;">
                Επιλέξτε πρώτα αθλητή.
            </div>
            <div id="allPaidBox" style="display:none;background:rgba(45,198,83,.08);border:1px solid rgba(45,198,83,.25);border-radius:12px;padding:.75rem 1rem;margin-bottom:.75rem;">
                <i class="fa-solid fa-circle-check" style="color:#2dc653;margin-right:.4rem;"></i>
                <strong style="color:#2dc653;">Ενήμερος/η!</strong>
                <span style="color:#8892b0;font-size:.85rem;margin-left:.3rem;">Δεν υπάρχουν ανεξόφλητοι μήνες.</span>
            </div>
            <div id="monthsContainer" style="display:none;margin-bottom:.85rem;">
                <div class="month-actions-row">
                    <button type="button" class="btn-tiny btn-tiny-red" onclick="selectAllMonths()">
                        <i class="fa-solid fa-check-double"></i> Όλοι
                    </button>
                    <button type="button" class="btn-tiny btn-tiny-grey" onclick="deselectAllMonths()">
                        <i class="fa-solid fa-xmark"></i> Κανένας
                    </button>
                    <span id="selectedCountLabel" style="font-size:.78rem;color:#8892b0;margin-left:.25rem;"></span>
                </div>
                <div class="month-grid" id="monthGrid"></div>
            </div>

            <!-- Exam fees section — title + container shown only when fees exist -->
            <div id="examsSectionTitle" class="modal-section-title" style="display:none;margin-top:.5rem;"><i class="fa-solid fa-ribbon"></i> Εκκρεμή Τέλη Εξετάσεων</div>
            <div id="examsContainer" style="display:none;margin-bottom:.85rem;">
                <div class="month-actions-row">
                    <button type="button" class="btn-tiny btn-tiny-red" onclick="selectAllExams()">
                        <i class="fa-solid fa-check-double"></i> Όλα
                    </button>
                    <button type="button" class="btn-tiny btn-tiny-grey" onclick="deselectAllExams()">
                        <i class="fa-solid fa-xmark"></i> Κανένα
                    </button>
                    <span id="selectedExamsLabel" style="font-size:.78rem;color:#8892b0;margin-left:.25rem;"></span>
                </div>
                <div class="month-grid" id="examGrid"></div>
            </div>

            <div id="paymentDetailsSection" style="display:none;">
                <div class="modal-section-title" style="margin-top:.25rem;"><i class="fa-solid fa-credit-card"></i> Στοιχεία Πληρωμής</div>

                <!-- Per-month amount inputs: εμφανίζεται μόνο όταν υπάρχουν επιλεγμένοι μήνες -->
                <div id="amountRow" style="display:none;margin-bottom:.75rem;">
                    <label class="form-label">Ποσό πληρωμής ανά μήνα (€)</label>
                    <div id="perMonthAmounts"></div>
                    <!-- hidden fallback single amount for backward compat -->
                    <input type="hidden" name="amount" id="amountInput" value="0">
                </div>

                <!-- Ποσό εξέτασης: εμφανίζεται μόνο όταν υπάρχουν επιλεγμένα τέλη εξετάσεων -->
                <div id="examAmountRow" style="display:none;margin-bottom:.75rem;">
                    <label class="form-label">Ποσό πληρωμής εξέτασης (€)</label>
                    <input type="number" step=".01" min="0" name="exam_amount" id="examAmountInput"
                           class="form-control" placeholder="0.00">
                    <div id="examAmountHint" style="font-size:.72rem;color:#6b7494;margin-top:.25rem;"></div>
                </div>

                <div style="margin-bottom:.75rem;">
                    <label class="form-label">Τρόπος Πληρωμής</label>
                    <input type="hidden" name="payment_method" id="payMethodInput" value="cash">
                    <div style="position:relative">
                        <button type="button" id="payMethodDropBtn" onclick="togglePayMethodDrop()"
                            style="width:100%;min-height:46px;padding:.65rem .9rem;border-radius:11px;border:1.5px solid rgba(255,255,255,.1);background:#0d1422;color:#f0f2ff;display:flex;align-items:center;gap:.6rem;cursor:pointer;font-size:.95rem;font-weight:600;text-align:left;transition:border-color .2s">
                            <i class="fa-solid fa-money-bill-wave" id="payMethodIcon" style="color:#2dc653;width:16px;text-align:center"></i>
                            <span id="payMethodLabel">Μετρητά</span>
                            <i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:.72rem;color:#6b7494;transition:transform .2s" id="payMethodChevron"></i>
                        </button>
                        <div id="payMethodDropdown"
                            style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#0d1422;border:1.5px solid rgba(230,57,70,.25);border-radius:11px;overflow:hidden;z-index:100;box-shadow:0 8px 32px rgba(0,0,0,.7)">
                            <button type="button" class="pay-method-opt" data-value="cash" data-label="Μετρητά" data-icon="fa-money-bill-wave" data-color="#2dc653" onclick="pickPayMethod(this)"
                                style="width:100%;padding:.65rem .9rem;display:flex;align-items:center;gap:.65rem;border:none;color:#f0f2ff;cursor:pointer;font-size:.9rem;font-weight:600;text-align:left;-webkit-tap-highlight-color:transparent">
                                <i class="fa-solid fa-money-bill-wave" style="color:#2dc653;width:16px;text-align:center"></i> Μετρητά
                            </button>
                            <div style="height:1px;background:rgba(255,255,255,.06)"></div>
                            <button type="button" class="pay-method-opt" data-value="card" data-label="Κάρτα" data-icon="fa-credit-card" data-color="#3b82f6" onclick="pickPayMethod(this)"
                                style="width:100%;padding:.65rem .9rem;display:flex;align-items:center;gap:.65rem;border:none;color:#f0f2ff;cursor:pointer;font-size:.9rem;font-weight:600;text-align:left;-webkit-tap-highlight-color:transparent">
                                <i class="fa-solid fa-credit-card" style="color:#3b82f6;width:16px;text-align:center"></i> Κάρτα
                            </button>
                            <div style="height:1px;background:rgba(255,255,255,.06)"></div>
                            <button type="button" class="pay-method-opt" data-value="deposit" data-label="Κατάθεση" data-icon="fa-building-columns" data-color="#f0a500" onclick="pickPayMethod(this)"
                                style="width:100%;padding:.65rem .9rem;display:flex;align-items:center;gap:.65rem;border:none;color:#f0f2ff;cursor:pointer;font-size:.9rem;font-weight:600;text-align:left;-webkit-tap-highlight-color:transparent">
                                <i class="fa-solid fa-building-columns" style="color:#f0a500;width:16px;text-align:center"></i> Κατάθεση
                            </button>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:.75rem;">
                    <label class="form-label">Ημερομηνία</label>
                    <input type="date" name="paid_at" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div style="margin-bottom:.75rem;">
                    <label class="form-label">Σημειώσεις</label>
                    <textarea name="notes" class="form-control" placeholder="Προαιρετικά..." rows="3" style="min-height:80px;resize:vertical;"></textarea>
                </div>

                <div class="total-preview" id="totalPreview">
                    <div style="font-weight:800;color:#2dc653;font-size:.95rem;">
                        <i class="fa-solid fa-receipt" style="margin-right:.3rem;"></i>
                        Σύνολο: <span id="totalAmountLabel">0€</span>
                        <span id="totalBreakdown" style="color:#8892b0;font-size:.8rem;font-weight:500;margin-left:.35rem;"></span>
                    </div>
                </div>
            </div>
        </form>
        </div>

        <div class="modal-footer">
            <button type="submit" form="paymentForm" class="btn btn-primary" id="modalSubmitBtn" style="flex:2;min-height:48px;font-size:1rem !important;">
                <i class="fa-solid fa-floppy-disk"></i> Αποθήκευση
            </button>
            <button type="button" class="btn btn-secondary" onclick="closePaymentModal()" style="flex:1;min-height:48px;">
                Ακύρωση
            </button>
        </div>
    </div>
</div>

<script>
// Each athlete's paid subscription ranges: { athlete_id: [{valid_from, valid_until}, ...] }
var ATHLETE_SUB_RANGES = <?php echo json_encode($athSubRanges, JSON_HEX_TAG); ?>;
var ATHLETE_EXAM_FEES  = <?php echo json_encode($athExamFees, JSON_HEX_TAG); ?>;
var ATHLETE_DEBT_FROM = <?php
    $dfmMap = [];
    foreach ($allAthletesList as $al) {
        $dfmMap[$al['id']] = $al['debt_from_month'] ?? null;
    }
    echo json_encode($dfmMap, JSON_HEX_TAG);
?>;
var GREEK_MONTHS = ['','Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος','Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];

// ── Sidebar toggle ──
(function(){
    var sb=document.getElementById('sidebar'),ov=document.getElementById('dm-overlay'),mb=document.getElementById('menuBtn');
    if(!sb||!mb)return;
    function open(){sb.classList.add('open');ov&&ov.classList.add('on');document.body.style.overflow='hidden';}
    function close(){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}
    mb.onclick=function(e){e.stopPropagation();sb.classList.contains('open')?close():open();};
    ov&&ov.addEventListener('click',close);
    sb.querySelectorAll('a.nav-item').forEach(function(l){l.addEventListener('click',function(){if(window.innerWidth<=900)setTimeout(close,80);});});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
    window.addEventListener('resize',function(){if(window.innerWidth>900){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}});
})();

// ── Payment method dropdown ──
function togglePayMethodDrop() {
    var d = document.getElementById('payMethodDropdown');
    var c = document.getElementById('payMethodChevron');
    var open = d.style.display !== 'none';
    d.style.display = open ? 'none' : 'block';
    c.style.transform = open ? '' : 'rotate(180deg)';
}
function pickPayMethod(btn) {
    document.getElementById('payMethodInput').value = btn.dataset.value;
    document.getElementById('payMethodLabel').textContent = btn.dataset.label;
    document.getElementById('payMethodIcon').className = 'fa-solid ' + btn.dataset.icon;
    document.getElementById('payMethodIcon').style.color = btn.dataset.color;
    // Use CSS class instead of inline background styles
    document.querySelectorAll('.pay-method-opt').forEach(function(b){
        b.classList.toggle('active-method', b.dataset.value === btn.dataset.value);
    });
    document.getElementById('payMethodDropdown').style.display = 'none';
    document.getElementById('payMethodChevron').style.transform = '';
}
// Close on outside click
document.addEventListener('click', function(e){
    var wrap = document.getElementById('payMethodDropBtn');
    var drop = document.getElementById('payMethodDropdown');
    if (drop && wrap && !wrap.contains(e.target) && !drop.contains(e.target)) {
        drop.style.display = 'none';
        var c = document.getElementById('payMethodChevron');
        if (c) c.style.transform = '';
    }
});

// ── Live search (JS filter, no page reload) ──
(function(){
    var input    = document.getElementById('searchInput');
    var icon     = document.getElementById('searchIcon');
    var spinner  = document.getElementById('searchSpinner');
    var tbody    = document.querySelector('.table-wrap tbody');
    var countEl  = document.querySelector('.card > div:first-child span:first-child');
    var allRows  = Array.from(tbody ? tbody.querySelectorAll('tr[onclick]') : []);
    var emptyRow = tbody ? tbody.querySelector('tr:not([onclick])') : null;
    var t;

    if (!input || !tbody) return;

    // Store original text for each row
    allRows.forEach(function(row){
        row._searchText = row.querySelector('.td-name')
            ? row.querySelector('.td-name').textContent.trim().toLowerCase()
            : '';
    });

    function normalize(str) {
        return str
            .toLowerCase()
            .replace(/ά/g,'α').replace(/έ/g,'ε').replace(/ή/g,'η')
            .replace(/ί/g,'ι').replace(/ϊ/g,'ι').replace(/ΐ/g,'ι')
            .replace(/ό/g,'ο').replace(/ύ/g,'υ').replace(/ϋ/g,'υ')
            .replace(/ΰ/g,'υ').replace(/ώ/g,'ω').replace(/ς/g,'σ');
    }

    function doFilter(q) {
        var norm = normalize(q.trim());
        var visible = 0;

        allRows.forEach(function(row){
            var match = !norm || normalize(row._searchText).includes(norm);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        // Show/hide empty state
        if (emptyRow) emptyRow.style.display = visible === 0 ? '' : 'none';

        // Update count label
        if (countEl) {
            countEl.textContent = visible + ' αθλητές' + (norm ? ' — φιλτραρισμένα' : '');
        }
    }

    function showSearching() {
        if (spinner) spinner.style.display = '';
        if (icon)    icon.style.display    = 'none';
    }
    function hideSearching() {
        if (spinner) spinner.style.display = 'none';
        if (icon)    icon.style.display    = '';
    }

    input.addEventListener('input', function(){
        var q = this.value;
        showSearching();
        clearTimeout(t);
        t = setTimeout(function(){
            doFilter(q);
            hideSearching();
        }, 180);
    });

    // On Enter: dismiss mobile keyboard without reload
    input.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            this.blur();
            clearTimeout(t);
            showSearching();
            var q = this.value;
            setTimeout(function(){
                doFilter(q);
                hideSearching();
            }, 300);
        }
    });

    // Run on load if there's a pre-filled value
    if (input.value) doFilter(input.value);
})();

// ══ UNIFIED PAYMENT MODAL ══

function openPaymentModal(athleteId) {
    var modal = document.getElementById('paymentModal');
    modal.classList.remove('closing');
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    if (athleteId) {
        var sel = document.getElementById('modalAthleteSel');
        sel.value = athleteId;
        onAthleteChange(athleteId);
    } else {
        resetModalState();
    }
}

function closePaymentModal() {
    var modal = document.getElementById('paymentModal');
    modal.classList.remove('open');
    modal.classList.add('closing');
    setTimeout(function() {
        modal.classList.remove('closing');
        document.body.style.overflow = '';
        document.getElementById('paymentForm').reset();
        // Reset payment method display
        document.getElementById('payMethodInput').value = 'cash';
        document.getElementById('payMethodLabel').textContent = 'Μετρητά';
        document.getElementById('payMethodIcon').className = 'fa-solid fa-money-bill-wave';
        document.getElementById('payMethodIcon').style.color = '#2dc653';
        document.getElementById('payMethodDropdown').style.display = 'none';
        // Remove active-method class from all options (CSS handles background, no inline styles)
        document.querySelectorAll('.pay-method-opt').forEach(function(b){ b.classList.remove('active-method'); });
        resetModalState();
    }, 220);
}

function resetModalState() {
    document.getElementById('monthsEmptyHint').style.display = 'none';
    document.getElementById('allPaidBox').style.display = 'none';
    document.getElementById('monthsContainer').style.display = 'none';
    document.getElementById('monthGrid').innerHTML = '';
    document.getElementById('examsSectionTitle').style.display = 'none';
    document.getElementById('examsContainer').style.display = 'none';
    document.getElementById('examGrid').innerHTML = '';
    document.getElementById('paymentDetailsSection').style.display = 'none';
    document.getElementById('totalPreview').classList.remove('visible');
    document.getElementById('selectedCountLabel').textContent = '';
    document.getElementById('selectedExamsLabel').textContent = '';
    var ar = document.getElementById('amountRow'); if (ar) ar.style.display = 'none';
    var er = document.getElementById('examAmountRow'); if (er) er.style.display = 'none';
    var ei = document.getElementById('examAmountInput'); if (ei) { ei.value = ''; ei._userEdited = false; }
}

function getUnpaidMonths(startDateStr, subRanges, monthlyFee) {
    if (!startDateStr || monthlyFee <= 0) return [];

    var ranges = (subRanges || []).map(function(r) {
        return {
            from: new Date(r.valid_from + 'T00:00:00'),
            to:   new Date(r.valid_until + 'T00:00:00'),
            amount: parseFloat(r.amount || 0) || 0
        };
    });

    var start = new Date(startDateStr + 'T00:00:00');
    start.setDate(1);
    var now = new Date(); now.setDate(1);

    var result = [];
    var cur = new Date(start);

    while (cur <= now) {
        var y = cur.getFullYear();
        var m = cur.getMonth() + 1;
        var mEnd = new Date(y, cur.getMonth() + 1, 0);

        var paidForMonth = 0;
        ranges.forEach(function(r) {
            if (r.from <= mEnd && r.to >= cur) {
                paidForMonth += r.amount;
            }
        });

        var remaining = Math.max(0, monthlyFee - paidForMonth);

        if (remaining > 0.009) {
            var key = y + '-' + (m < 10 ? '0' : '') + m;
            result.push({
                month: key,
                label: GREEK_MONTHS[m] + ' ' + y,
                paid: paidForMonth,
                remaining: remaining
            });
        }

        cur.setMonth(cur.getMonth() + 1);
    }

    return result;
}

// ── FIX: hide "Επιλέξτε πρώτα αθλητή" hints once an athlete is selected ──
function onAthleteChange(athleteId) {
    resetModalState();

    if (!athleteId) {
        document.getElementById('monthsEmptyHint').style.display = '';
        return;
    }

    var sel     = document.getElementById('modalAthleteSel');
    var opt     = sel.options[sel.selectedIndex];
    var regDate = opt ? opt.dataset.regdate : '';
    var fee     = parseFloat(opt ? opt.dataset.fee : 0) || 0;

    var debtFrom = ATHLETE_DEBT_FROM[athleteId];
    var startDate = '';
    if (debtFrom && /^\d{4}-\d{2}$/.test(debtFrom)) {
        startDate = debtFrom + '-01';
    } else if (regDate) {
        startDate = regDate;
    }

    document.getElementById('amountInput').value = fee > 0 ? fee.toFixed(2) : '';

    // Unpaid months
    var ranges  = ATHLETE_SUB_RANGES[athleteId] || [];
    var unpaid  = getUnpaidMonths(startDate, ranges, fee);

    if (unpaid.length === 0) {
        document.getElementById('allPaidBox').style.display = '';
    } else {
        document.getElementById('monthsContainer').style.display = '';
        renderMonthChips(unpaid);
    }

    // Pending exam fees — only show section if there are fees
    var examFees = ATHLETE_EXAM_FEES[athleteId] || [];
    if (examFees.length > 0) {
        document.getElementById('examsSectionTitle').style.display = '';
        document.getElementById('examsContainer').style.display = '';
        renderExamChips(examFees);
    }

    // Show payment details only if there's something to pay
    if (unpaid.length > 0 || examFees.length > 0) {
        document.getElementById('paymentDetailsSection').style.display = '';
    }
    updateTotal();
}

function renderMonthChips(unpaidArr) {
    var grid = document.getElementById('monthGrid');
    grid.innerHTML = '';
    grid._unpaidData = unpaidArr;
    unpaidArr.forEach(function(item) {
        var lbl = document.createElement('label');
        lbl.className = 'month-chip-btn';
        lbl.dataset.remaining = item.remaining.toFixed(2);
        lbl.dataset.month = item.month;
        lbl.innerHTML =
            '<input type="checkbox" name="months[]" value="' + item.month + '">' +
            '<span class="chip-check"><i class="fa-solid fa-check" style="font-size:.58rem"></i></span>' +
            '<span class="chip-label">' + item.label + ' · Υπόλοιπο ' + item.remaining.toFixed(2).replace('.', ',') + '€</span>';
        lbl.addEventListener('click', function(){ setTimeout(updateTotal, 10); });
        grid.appendChild(lbl);
    });
    updateTotal();
}

function renderExamChips(examArr) {
    var grid = document.getElementById('examGrid');
    grid.innerHTML = '';
    grid._examData = examArr;
    examArr.forEach(function(item) {
        var fee = parseFloat(item.amount) || 0;
        var lbl = document.createElement('label');
        lbl.className = 'month-chip-btn';
        lbl.dataset.fee = fee;
        lbl.dataset.participantId = item.participant_id;
        lbl.innerHTML =
            '<input type="checkbox" name="exam_ids[]" value="' + item.participant_id + '">' +
            '<span class="chip-check"><i class="fa-solid fa-check" style="font-size:.58rem"></i></span>' +
            '<span class="chip-label">' + item.description + '</span>' +
            '<span style="font-size:.82rem;font-weight:800;color:#f0a500;margin-left:auto;white-space:nowrap;padding-left:.5rem">' + fee.toFixed(2).replace('.', ',') + '€</span>';
        lbl.addEventListener('click', function(){ setTimeout(updateTotal, 10); });
        grid.appendChild(lbl);
    });
    updateTotal();
}

function updateTotal() {
    var monthCheckboxes = document.querySelectorAll('#monthGrid input[type=checkbox]');
    var examCheckboxes  = document.querySelectorAll('#examGrid input[type=checkbox]');

    var checkedMonths = Array.from(monthCheckboxes).filter(function(cb){ return cb.checked; });
    var checkedExams  = Array.from(examCheckboxes).filter(function(cb){ return cb.checked; });

    monthCheckboxes.forEach(function(cb){ cb.parentElement.classList.toggle('selected', cb.checked); });
    examCheckboxes.forEach(function(cb){ cb.parentElement.classList.toggle('selected', cb.checked); });

    var monthCount = checkedMonths.length;
    var examCount  = checkedExams.length;

    document.getElementById('selectedCountLabel').textContent = monthCount > 0 ? monthCount + (monthCount === 1 ? ' μήνας' : ' μήνες') + ' επιλεγμένοι' : '';
    document.getElementById('selectedExamsLabel').textContent = examCount > 0 ? examCount + (examCount === 1 ? ' τέλος' : ' τέλη') + ' επιλεγμένα' : '';

    // Show/hide amount fields
    var amountRow    = document.getElementById('amountRow');
    var examAmtRow   = document.getElementById('examAmountRow');
    var examAmtHint  = document.getElementById('examAmountHint');
    var examAmtInput = document.getElementById('examAmountInput');

    if (amountRow) amountRow.style.display = monthCount > 0 ? '' : 'none';

    // Build per-month amount inputs
    var perMonthContainer = document.getElementById('perMonthAmounts');
    var amountHidden = document.getElementById('amountInput');
    if (perMonthContainer) {
        var existing = {};
        Array.from(perMonthContainer.querySelectorAll('input[data-month]')).forEach(function(inp){
            existing[inp.dataset.month] = inp;
        });

        if (monthCount === 0) {
            perMonthContainer.innerHTML = '';
            if (amountHidden) amountHidden.value = '0';
        } else {
            var checkedMonthKeys = {};
            checkedMonths.forEach(function(cb){
                var chip = cb.closest('.month-chip-btn');
                var month = chip ? chip.dataset.month : cb.value;
                var rem   = chip ? parseFloat(chip.dataset.remaining || 0) : 0;
                checkedMonthKeys[month] = rem;
            });

            // Remove inputs for unchecked months
            Object.keys(existing).forEach(function(m){
                if (!checkedMonthKeys[m]) existing[m].parentElement.remove();
            });

            // Add inputs for newly checked months
            checkedMonths.forEach(function(cb){
                var chip = cb.closest('.month-chip-btn');
                var month = chip ? chip.dataset.month : cb.value;
                var rem   = chip ? parseFloat(chip.dataset.remaining || 0) : 0;
                var label = chip ? chip.querySelector('.chip-label').textContent.split('·')[0].trim() : month;
                if (!existing[month]) {
                    var wrap = document.createElement('div');
                    wrap.style.cssText = 'display:flex;align-items:center;gap:.55rem;margin-bottom:.5rem;';
                    wrap.innerHTML =
                        '<span style="font-size:.82rem;font-weight:700;color:var(--muted,#8892b0);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + label + '</span>' +
                        '<input type="number" step=".01" min="0" max="' + rem.toFixed(2) + '" ' +
                               'name="month_amounts[' + month + ']" data-month="' + month + '" ' +
                               'value="' + rem.toFixed(2) + '" ' +
                               'class="form-control" style="max-width:110px;min-height:38px;font-size:.92rem!important;padding:.45rem .65rem!important;" ' +
                               'oninput="updateTotal()" ' +
                               'placeholder="0.00"> ' +
                        '<span style="font-size:.78rem;color:var(--muted,#8892b0);flex-shrink:0">€</span>';
                    perMonthContainer.appendChild(wrap);
                }
            });
        }
    }

    if (examAmtRow) {
        if (examCount > 0) {
            examAmtRow.style.display = '';
            var totalExamFee = checkedExams.reduce(function(s, cb) {
                var chip = cb.closest('.month-chip-btn');
                return s + (parseFloat(chip ? chip.dataset.fee : 0) || 0);
            }, 0);
            if (examAmtHint) {
                examAmtHint.textContent = 'Συνολικό τέλος: ' + totalExamFee.toFixed(2).replace('.', ',') + '€' +
                    (examCount > 1 ? ' (' + examCount + ' εξετάσεις)' : '');
            }
            if (examAmtInput && (!examAmtInput._userEdited || examAmtInput._lastExamCount !== examCount)) {
                examAmtInput.value = totalExamFee.toFixed(2);
                examAmtInput._userEdited = false;
                examAmtInput._lastExamCount = examCount;
            }
        } else {
            examAmtRow.style.display = 'none';
            if (examAmtInput) { examAmtInput.value = ''; examAmtInput._userEdited = false; }
        }
    }

    // Calculate totals
    var monthTotalAmt = 0;
    var monthParts = [];
    if (perMonthContainer) {
        Array.from(perMonthContainer.querySelectorAll('input[data-month]')).forEach(function(inp){
            var v = parseFloat(inp.value || '0') || 0;
            monthTotalAmt += v;
            var lbl = inp.parentElement.querySelector('span');
            if (v > 0) monthParts.push((lbl ? lbl.textContent.trim() : inp.dataset.month) + ': ' + v.toFixed(2).replace('.', ',') + '€');
        });
    }

    var examAmt   = examAmtInput ? (parseFloat(examAmtInput.value || '0') || 0) : 0;
    var examFeeTotal = checkedExams.reduce(function(s, cb) {
        var chip = cb.closest('.month-chip-btn');
        return s + (parseFloat(chip ? chip.dataset.fee : 0) || 0);
    }, 0);
    var cappedExamAmt = Math.min(examAmt, examFeeTotal);
    var total = monthTotalAmt + cappedExamAmt;

    var preview = document.getElementById('totalPreview');
    var totalEl = document.getElementById('totalAmountLabel');
    var breakEl = document.getElementById('totalBreakdown');

    if (monthCount > 0 || examCount > 0) {
        preview.classList.add('visible');
        totalEl.textContent = total.toFixed(2).replace('.', ',') + '€';
        var parts = [];
        if (monthParts.length) parts = parts.concat(monthParts);
        if (examCount > 0) {
            var isPartial = cappedExamAmt < examFeeTotal - 0.009;
            parts.push(examCount + (examCount === 1 ? ' τέλος' : ' τέλη') + (isPartial ? ' (μερική)' : ''));
        }
        breakEl.textContent = parts.length ? '(' + parts.join(' + ') + ')' : '';
    } else {
        preview.classList.remove('visible');
    }
}

function selectAllMonths()   { document.querySelectorAll('#monthGrid input[type=checkbox]').forEach(cb => { cb.checked=true; cb.parentElement.classList.add('selected'); }); updateTotal(); }
function deselectAllMonths() { document.querySelectorAll('#monthGrid input[type=checkbox]').forEach(cb => { cb.checked=false; cb.parentElement.classList.remove('selected'); }); updateTotal(); }
function selectAllExams()    { document.querySelectorAll('#examGrid input[type=checkbox]').forEach(cb => { cb.checked=true; cb.parentElement.classList.add('selected'); }); updateTotal(); }
function deselectAllExams()  { document.querySelectorAll('#examGrid input[type=checkbox]').forEach(cb => { cb.checked=false; cb.parentElement.classList.remove('selected'); }); updateTotal(); }

(function() {
    var ei = document.getElementById('examAmountInput');
    if (ei) ei.addEventListener('input', function() {
        this._userEdited = true;
        updateTotal();
    });
})();

document.getElementById('paymentForm').addEventListener('submit', function(e) {
    var monthGrid = document.getElementById('monthGrid');
    var examGrid  = document.getElementById('examGrid');
    var monthChecked = monthGrid && monthGrid.children.length > 0 && document.querySelectorAll('#monthGrid input[type=checkbox]:checked').length > 0;
    var examChecked  = examGrid && examGrid.children.length > 0 && document.querySelectorAll('#examGrid input[type=checkbox]:checked').length > 0;
    if (!monthChecked && !examChecked) {
        e.preventDefault();
        var container = document.getElementById('monthsContainer');
        if (container) container.style.outline = '2px solid #e63946';
        var examContainer = document.getElementById('examsContainer');
        if (examContainer) examContainer.style.outline = '2px solid #e63946';
        setTimeout(function(){ if(container) container.style.outline = ''; if(examContainer) examContainer.style.outline = ''; }, 1800);
        var old = document.getElementById('payError');
        if (old) old.remove();
        var hint = document.createElement('div');
        hint.style.cssText = 'color:#e63946;font-size:.82rem;font-weight:700;margin-top:.4rem;';
        hint.textContent = '⚠️ Επιλέξτε τουλάχιστον έναν μήνα ή ένα τέλος εξέτασης.';
        hint.id = 'payError';
        (container || examContainer).appendChild(hint);
        return;
    }

    // Inject per-exam paid amounts as hidden fields
    document.querySelectorAll('input[name^="exam_pay_amount"]').forEach(function(el){ el.remove(); });
    var examAmtInput = document.getElementById('examAmountInput');
    var totalExamPaid = examAmtInput ? (parseFloat(examAmtInput.value) || 0) : 0;
    var checkedExamCbs = Array.from(document.querySelectorAll('#examGrid input[type=checkbox]:checked'));
    var totalFee = checkedExamCbs.reduce(function(s, cb) {
        var chip = cb.closest('.month-chip-btn');
        return s + (parseFloat(chip ? chip.dataset.fee : 0) || 0);
    }, 0);
    checkedExamCbs.forEach(function(cb) {
        var chip = cb.closest('.month-chip-btn');
        var fee  = parseFloat(chip ? chip.dataset.fee : 0) || 0;
        var proportion = totalFee > 0 ? fee / totalFee : 1;
        var paid = Math.min(totalExamPaid * proportion, fee);
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'exam_pay_amount[' + cb.value + ']';
        h.value = paid.toFixed(2);
        document.getElementById('paymentForm').appendChild(h);
    });
});

// ── AUTO-OPEN MODAL WHEN LOADED WITH ?action=add&athlete_id=... ──
window.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const action = urlParams.get('action');
    const athleteId = urlParams.get('athlete_id');
    if (action === 'add' && athleteId && athleteId !== '0') {
        const aid = parseInt(athleteId, 10);
        if (!isNaN(aid)) {
            openPaymentModal(aid);
            if (history.replaceState) {
                const cleanUrl = window.location.pathname;
                history.replaceState(null, '', cleanUrl);
            }
        }
    }
});
</script>
</body>
</html>