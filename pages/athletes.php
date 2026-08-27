<?php

/**
 * ============================================================
 * pages/athletes.php — Διαχείριση Αθλητών (FULLY CORRECTED)
 * ============================================================
 * FIXES:
 * - Exam fees history now always visible with edit/delete capabilities
 * - Added edit_exam_payment / delete_exam_payment actions
 * - Exam transactions table includes action buttons
 * - Improved visibility of payment details
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
renderPaymentWall();



if (!function_exists('fmtD')) {
    function fmtD(?string $d): string {
        if (!$d || $d === '0000-00-00') return '—';
        try { return (new DateTime($d))->format('d/m/Y'); } catch (Exception $e) { return '—'; }
    }
}

$db  = getDB();
$sid = schoolId();
$action = $_GET['action'] ?? '';

$_sr = $db->prepare("SELECT name FROM schools WHERE id=?");
$_sr->execute([$sid]);
$clubName = ($_sr->fetch()['name']) ?? 'MAster';

$viewId = (int)($_GET['view'] ?? 0);
$editId = (int)($_GET['edit'] ?? 0);

// ── Auto-migration ──
(function() use ($db) {
    try {
        $cols = $db->query("DESCRIBE athletes")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('debt_from_month',  $cols, true)) {
            $db->exec("ALTER TABLE athletes ADD COLUMN `debt_from_month` VARCHAR(7) DEFAULT NULL");
        }
        if (!in_array('debt_until_month', $cols, true)) {
            $db->exec("ALTER TABLE athletes ADD COLUMN `debt_until_month` VARCHAR(7) DEFAULT NULL");
        }

    } catch (Exception $e) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `school_exempt_months` (
          `id` int NOT NULL AUTO_INCREMENT, `school_id` int NOT NULL,
          `month` varchar(7) NOT NULL, `label` varchar(100) DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`), UNIQUE KEY `uniq` (`school_id`,`month`),
          CONSTRAINT `sem_fk1` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `athlete_pause_periods` (
          `id` int NOT NULL AUTO_INCREMENT, `school_id` int NOT NULL, `athlete_id` int NOT NULL,
          `pause_from` date NOT NULL, `pause_until` date NOT NULL,
          `note` varchar(255) DEFAULT NULL, `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`), KEY `aid` (`athlete_id`),
          CONSTRAINT `app_fk1` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE,
          CONSTRAINT `app_fk2` FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {}
})();

function getSchoolExemptMonths($db, int $sid): array {
    $s = $db->prepare("SELECT month, label FROM school_exempt_months WHERE school_id=? ORDER BY month");
    $s->execute([$sid]);
    return $s->fetchAll(PDO::FETCH_KEY_PAIR);
}
function getAthletePausePeriods($db, int $athleteId): array {
    $s = $db->prepare("SELECT * FROM athlete_pause_periods WHERE athlete_id=? ORDER BY pause_from");
    $s->execute([$athleteId]);
    return $s->fetchAll();
}
function isMonthPaused(DateTime $month, array $pausePeriods): bool {
    $mEnd = (clone $month)->modify('last day of this month');
    foreach ($pausePeriods as $p) {
        $pFrom  = new DateTime($p['pause_from']);
        $pUntil = new DateTime($p['pause_until']);
        if ($pFrom <= $mEnd && $pUntil >= $month) return true;
    }
    return false;
}
function getDebtStartDate(array $athlete): ?string {
    $dfm = $athlete['debt_from_month'] ?? null;
    if ($dfm && preg_match('/^\d{4}-\d{2}$/', $dfm)) {
        $startDate = $dfm . '-01';
        $nowFirst = (new DateTime())->modify('first day of this month')->format('Y-m-d');
        if ($startDate > $nowFirst) return null;
        return $startDate;
    }
    $reg = $athlete['registration_date'] ?? null;
    return ($reg && $reg !== '0000-00-00') ? $reg : null;
}
function getAthleteDebtSummary($db, int $athleteId, ?string $startDate, float $monthlyFee, array $exemptMonths, array $pausePeriods): array {
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
        $mKey = $cur->format('Y-m');

        if (!isset($exemptMonths[$mKey]) && !isMonthPaused($cur, $pausePeriods)) {
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
                    'month'     => $mKey,
                    'label'     => $gm[(int)$cur->format('m')] . ' ' . $cur->format('Y'),
                    'paid'      => $paidForMonth,
                    'remaining' => $remaining,
                ];
            }
        }

        $cur->modify('+1 month');
    }

    return [
        'months'  => $debtMonths,
        'balance' => $debtBalance,
        'unpaid'  => $unpaidMonths,
    ];
}

// UPDATED: get all exam transactions (not just pending) for full history
function getAthleteExamTransactions(PDO $db, int $athleteId, int $schoolId): array {
    $stmt = $db->prepare(
        "SELECT id, amount, description, transaction_date, payment_method, notes, category
         FROM transactions
         WHERE school_id = ?
           AND athlete_id = ?
           AND category = 'Εξετάσεις Ζώνων'
         ORDER BY transaction_date DESC"
    );
    $stmt->execute([$schoolId, $athleteId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// For debt calculation: only pending exam fees
function getAthleteExamFeeDebts(PDO $db, int $athleteId, int $schoolId): array {
    $stmt = $db->prepare(
        "SELECT id, amount, description, transaction_date
         FROM transactions
         WHERE school_id = ?
           AND athlete_id = ?
           AND category = 'Εξετάσεις Ζώνων'
           AND notes LIKE '%:pending%'
         ORDER BY transaction_date DESC"
    );
    $stmt->execute([$schoolId, $athleteId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getExamParticipantIdFromNotes(string $notes): int {
    if (preg_match('/exam_fee:(\d+)/', $notes, $m)) {
        return (int)$m[1];
    }
    return 0;
}
function syncSubscriptionTransaction(
    PDO $db,
    string $action,
    int $schoolId,
    int $subId,
    int $athleteId      = 0,
    float $amount       = 0,
    string $paidAt      = '',
    string $method      = 'cash',
    string $validFrom   = '',
    string $validUntil  = '',
    string $athleteName = ''
): void {
    $marker = 'sub_id:' . $subId;
    if ($action === 'delete') {
        $db->prepare("DELETE FROM transactions WHERE school_id=? AND notes LIKE ?")
           ->execute([$schoolId, '%' . $marker . '%']);
        return;
    }
    if ($amount <= 0) return;

    $txDate  = $paidAt ?: date('Y-m-d');
    $pmMeth  = in_array($method, ['cash','card','deposit','other'], true) ? $method : 'cash';
    $gm      = ['','Ιαν','Φεβ','Μαρ','Απρ','Μαι','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];
    $desc    = 'Συνδρομή';

    if ($validFrom) {
        $ts   = strtotime($validFrom);
        $desc = 'Συνδρομή ' . ($gm[(int)date('n',$ts)] ?? '') . ' ' . date('Y',$ts);
    }
    if ($athleteName) $desc .= ' — ' . $athleteName;

    $existing = $db->prepare("SELECT id FROM transactions WHERE school_id=? AND notes LIKE ? LIMIT 1");
    $existing->execute([$schoolId, '%' . $marker . '%']);
    $txId = $existing->fetchColumn();

    if ($txId) {
        $db->prepare("UPDATE transactions SET amount=?,transaction_date=?,payment_method=?,description=?,athlete_id=? WHERE id=?")
           ->execute([$amount, $txDate, $pmMeth, $desc, $athleteId ?: null, $txId]);
    } else {
        $db->prepare("INSERT INTO transactions (school_id,type,category,amount,description,transaction_date,payment_method,athlete_id,notes) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$schoolId,'income','Συνδρομές',$amount,$desc,$txDate,$pmMeth,$athleteId ?: null,$marker]);
    }
}

// ── POST HANDLERS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_exception_handler(function(Throwable $e) {
        error_log('[athletes.php] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (function_exists('flash') && function_exists('redirect') && defined('APP_URL')) {
            flash('Σφάλμα: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'danger');
            redirect(APP_URL . '/pages/athletes.php');
        }
        exit;
    });
    $a = $_POST;

    if (($a['_action'] ?? '') === 'save_exempt_months') {
        $db->prepare("DELETE FROM school_exempt_months WHERE school_id=?")->execute([$sid]);
        $ins = $db->prepare("INSERT IGNORE INTO school_exempt_months (school_id,month,label) VALUES (?,?,?)");
        foreach (($a['exempt_months'] ?? []) as $i => $m) {
            $m = trim($m);
            if ($m && preg_match('/^\d{4}-\d{2}$/', $m)) {
                $ins->execute([$sid, $m, trim($a['exempt_labels'][$i] ?? '')]);
            }
        }
        flash('Εξαιρεμένοι μήνες αποθηκεύτηκαν!');
        redirect(APP_URL.'/pages/athletes.php');
    }

    if (($a['_action'] ?? '') === 'add_pause_period') {
        $athId = (int)$a['athlete_id'];
        $from  = $a['pause_from'] ?? '';
        $until = $a['pause_until'] ?? '';
        $note  = trim($a['pause_note'] ?? '');
        
        // Convert from dd/mm/yyyy to Y-m-d
        if ($from && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $from, $m)) {
            $from = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        if ($until && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $until, $m)) {
            $until = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        
        if ($from && $until && $from <= $until) {
            $db->prepare("INSERT INTO athlete_pause_periods (school_id,athlete_id,pause_from,pause_until,note) VALUES (?,?,?,?,?)")
               ->execute([$sid, $athId, $from, $until, $note]);
            flash('Περίοδος παύσης προστέθηκε!');
        }
        redirect(APP_URL.'/pages/athletes.php?view='.$athId);
    }

    if (($a['_action'] ?? '') === 'delete_pause_period') {
        $ppId  = (int)$a['pp_id'];
        $athId = (int)$a['athlete_id'];
        $db->prepare("DELETE FROM athlete_pause_periods WHERE id=? AND school_id=?")->execute([$ppId, $sid]);
        flash('Περίοδος παύσης διαγράφηκε.', 'warning');
        redirect(APP_URL.'/pages/athletes.php?view='.$athId);
    }

    // EDIT EXAM PAYMENT
if (($a['_action'] ?? '') === 'edit_exam_payment') {
    $txId   = (int)($a['id'] ?? 0);
    $amount = max(0, floatval(str_replace(',', '.', $a['amount'] ?? 0)));
    $method = in_array($a['payment_method'] ?? '', ['cash','card','deposit','other'], true) ? $a['payment_method'] : 'cash';
    $txDate = trim($a['transaction_date'] ?? '') ?: date('Y-m-d');

    // Convert from dd/mm/yyyy to Y-m-d
    if ($txDate && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $txDate, $m)) {
        $txDate = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    $notes  = trim($a['notes'] ?? '');
    $status = (($a['status'] ?? 'paid') === 'pending') ? 'pending' : 'paid';

    // Καθάρισε τυχόν παλιά :pending markers
    $notes = preg_replace('/\s*:pending\s*/', ' ', $notes);
    $notes = trim(preg_replace('/\s+/', ' ', $notes));

    // Αν η κατάσταση είναι pending, πρόσθεσέ το ξανά
    if ($status === 'pending') {
        $notes = trim($notes . ' :pending');
    }

    $db->prepare("UPDATE transactions SET amount=?, payment_method=?, transaction_date=?, notes=? WHERE id=? AND school_id=?")
       ->execute([$amount, $method, $txDate, $notes, $txId, $sid]);

    flash('Η πληρωμή εξέτασης ενημερώθηκε!');
    redirect(APP_URL.'/pages/athletes.php?view='.($a['athlete_id'] ?? 0));
}

    // DELETE EXAM PAYMENT
    if (($a['_action'] ?? '') === 'delete_exam_payment') {
        $txId   = (int)($a['id'] ?? 0);
        $athId  = (int)($a['athlete_id'] ?? 0);
        $db->prepare("DELETE FROM transactions WHERE id=? AND school_id=? AND category='Εξετάσεις Ζώνων'")
           ->execute([$txId, $sid]);
        flash('Η πληρωμή εξέτασης διαγράφηκε.', 'danger');
        redirect(APP_URL.'/pages/athletes.php?view='.$athId);
    }

    if (($a['_action'] ?? '') === 'save_athlete') {
        $id      = (int)($a['id'] ?? 0);
        $rawDebt = trim($a['debt_from_month'] ?? '');
        if ($rawDebt && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $rawDebt, $dmx)) {
            $rawDebt = sprintf('%04d-%02d-%02d', (int)$dmx[3], (int)$dmx[2], (int)$dmx[1]);
        }
        $debtMode = trim($a['debt_mode'] ?? 'owes_now');
        $dfm     = null;

        if ($rawDebt && preg_match('/^(\d{4}-\d{2})/', $rawDebt, $m2)) {
            $dfm = $m2[1];
            if ($debtMode === 'future_start') {
                $nowYm   = date('Y-m');
                $nextYm  = date('Y-m', strtotime('first day of next month'));
                if ($dfm <= $nowYm) $dfm = $nextYm;
            }
            if ($debtMode === 'owes_now') {
                $nowYm = date('Y-m');
                if ($dfm > $nowYm) $dfm = $nowYm;
            }
        }

        $deptId   = (int)($a['department_id'] ?? 0);
        $inputFee = floatval(str_replace(',', '.', $a['monthly_fee'] ?? '0'));
        if ($deptId) {
            $dStmt = $db->prepare("SELECT monthly_fee FROM departments WHERE id=? AND school_id=?");
            $dStmt->execute([$deptId, $sid]);
            $dFee = floatval($dStmt->fetchColumn() ?: 0);
            if ($inputFee == 0 && $dFee > 0) {
                $a['monthly_fee'] = (string)$dFee;
            }
        }

        if (!empty($a['birthdate'])) {
            $bd = trim($a['birthdate']);
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $bd, $bdm)) {
                $a['birthdate'] = sprintf('%04d-%02d-%02d', (int)$bdm[3], (int)$bdm[2], (int)$bdm[1]);
            }
        }

        foreach (['full_name','father_name','owner_name'] as $nf) {
            if (isset($a[$nf])) $a[$nf] = preg_replace('/[0-9]/', '', $a[$nf]);
        }
        foreach (['phone','parent_phone','emergency_phone'] as $pf) {
            if (isset($a[$pf])) $a[$pf] = preg_replace('/[^0-9+\-\s]/', '', $a[$pf]);
        }

        if (!empty($a['birthdate']) && !empty($dfm)) {
            $bdYear = 0;
            if (preg_match('/^(\d{4})/', $a['birthdate'], $bym)) $bdYear = (int)$bym[1];
            elseif (preg_match('/(\d{4})$/', $a['birthdate'], $bym)) $bdYear = (int)$bym[1];
            $dfmYear = (int)substr($dfm, 0, 4);
            if ($bdYear > 0 && $dfmYear < $bdYear) $dfm = $bdYear . '-' . substr($dfm, 5);
        }

        $fields = [
            'full_name','father_name','birthdate','amka',
            'phone','parent_phone','email','parent_email',
            'address','emergency_phone','medical_cert_expiry','department_id','notes','monthly_fee'
        ];

        $not_null_defaults = ['monthly_fee' => '0'];
        $data = [];

        foreach ($fields as $f) {
            $val = trim((string)($a[$f] ?? ''));
            if ($val !== '') {
                $data[] = $val;
            } elseif (isset($not_null_defaults[$f])) {
                $data[] = $not_null_defaults[$f];
            } else {
                $data[] = null;
            }
        }

        if ($id) {
            $existing = $db->prepare("SELECT registration_date FROM athletes WHERE id=? AND school_id=?");
            $existing->execute([$id, $sid]);
            $existingReg = $existing->fetchColumn() ?: date('Y-m-d');

            $setClauses = implode(',', array_map(function($f){ return "$f=?"; }, $fields));
            $sql = "UPDATE athletes SET $setClauses, registration_date=?, debt_from_month=? WHERE id=? AND school_id=?";

            $params = array_merge($data, [$existingReg, $dfm, $id, $sid]);
            $db->prepare($sql)->execute($params);

            flash('Αθλητής ενημερώθηκε!');
            auditLog('edit_athlete', 'athlete', $id);
        } else {
            if (isAthleteLimit()) redirect(APP_URL.'/pages/athletes.php?action=add&limit_reached=1');

            $colList = implode(',', $fields);
            $placeholders = implode(',', array_fill(0, count($fields) + 3, '?'));
            $sql = "INSERT INTO athletes (school_id,$colList,registration_date,debt_from_month) VALUES ($placeholders)";

            $params = array_merge([$sid], $data, [date('Y-m-d'), $dfm]);
            $db->prepare($sql)->execute($params);

            $newId = (int)$db->lastInsertId();
            flash('Αθλητής προστέθηκε!');
            auditLog('add_athlete', 'athlete', $newId);

            // ── Auto-create parent portal account for minors ──
            $parentEmail = trim((string)($a['parent_email'] ?? ''));
            $birthdate   = trim((string)($a['birthdate']   ?? ''));

            // birthdate may still be DD/MM/YYYY if conversion above was skipped
            if ($birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $birthdate, $bdm2)) {
                    $birthdate = sprintf('%04d-%02d-%02d', (int)$bdm2[3], (int)$bdm2[2], (int)$bdm2[1]);
                }
            }

            $isMinor = false;
            if ($birthdate && filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                try {
                    $age = (int)(new DateTime())->diff(new DateTime($birthdate))->y;
                    $isMinor = ($age < 18);
                } catch (Exception $e) { $isMinor = false; }
            }
            if ($isMinor && $parentEmail) {
                require_once __DIR__ . '/../includes/mailer.php';
                // Check if parent account already exists for this school
                $pStmt = $db->prepare("SELECT id FROM parent_users WHERE school_id=? AND parent_email=? LIMIT 1");
                $pStmt->execute([$sid, $parentEmail]);
                $existingParent = $pStmt->fetchColumn();

                if ($existingParent) {
                    // Parent exists — just link the new athlete
                    $db->prepare("INSERT IGNORE INTO parent_children (parent_id, athlete_id) VALUES (?,?)")
                       ->execute([$existingParent, $newId]);
                    flash('Ο λογαριασμός γονέα υπάρχει ήδη — ο αθλητής συνδέθηκε με αυτόν.');
                } else {
                    // New parent — generate credentials and send email
                    $rawPassword  = bin2hex(random_bytes(5));
                    $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);
                    $db->prepare("INSERT INTO parent_users (school_id, parent_email, password_hash, first_login) VALUES (?,?,?,1)")
                       ->execute([$sid, $parentEmail, $passwordHash]);
                    $parentId = (int)$db->lastInsertId();
                    $db->prepare("INSERT IGNORE INTO parent_children (parent_id, athlete_id) VALUES (?,?)")
                       ->execute([$parentId, $newId]);

                    // Fetch school name for the email
                    $schStmt = $db->prepare("SELECT name FROM schools WHERE id=? LIMIT 1");
                    $schStmt->execute([$sid]);
                    $schoolName = (string)($schStmt->fetchColumn() ?: 'MAster');

                    $emailDebug2 = null;
                    $emailSent = sendParentCredentials($parentEmail, $rawPassword, trim((string)($a['full_name'] ?? '')), $schoolName, $emailDebug2);
                    if ($emailSent) {
                        flash('✉️ Στάλθηκαν κωδικοί πρόσβασης στο Portal Γονέων στο ' . htmlspecialchars($parentEmail, ENT_QUOTES, 'UTF-8') . '.');
                    } else {
                        $hint2 = $emailDebug2 ? ' [' . htmlspecialchars(substr($emailDebug2, 0, 120), ENT_QUOTES, 'UTF-8') . ']' : '';
                        flash('Λογαριασμός γονέα δημιουργήθηκε αλλά email δεν στάλθηκε' . $hint2 . '. Κωδικός: <strong>' . htmlspecialchars($rawPassword, ENT_QUOTES, 'UTF-8') . '</strong>', 'warning');
                    }
                }
            }
        }
        redirect(APP_URL.'/pages/athletes.php');
    }

    if (($a['_action'] ?? '') === 'resend_parent_credentials') {
        $id = (int)($a['id'] ?? 0);
        $athRow = $db->prepare("SELECT full_name, parent_email, birthdate FROM athletes WHERE id=? AND school_id=? LIMIT 1");
        $athRow->execute([$id, $sid]);
        $ath = $athRow->fetch();
        if (!$ath || !filter_var($ath['parent_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            flash('Δεν βρέθηκε έγκυρο email γονέα για αυτόν τον αθλητή.', 'danger');
            redirect(APP_URL . '/pages/athletes.php?view=' . $id);
        }
        require_once __DIR__ . '/../includes/mailer.php';
        $pEmail = $ath['parent_email'];
        // Check if parent account exists
        $pRow = $db->prepare("SELECT id FROM parent_users WHERE school_id=? AND parent_email=? LIMIT 1");
        $pRow->execute([$sid, $pEmail]);
        $parentId = (int)$pRow->fetchColumn();
        // Generate new password
        $rawPassword  = bin2hex(random_bytes(5));
        $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);
        if ($parentId) {
            // Reset password for existing account
            $db->prepare("UPDATE parent_users SET password_hash=?, first_login=1 WHERE id=?")->execute([$passwordHash, $parentId]);
        } else {
            // Create new account
            $db->prepare("INSERT INTO parent_users (school_id, parent_email, password_hash, first_login) VALUES (?,?,?,1)")
               ->execute([$sid, $pEmail, $passwordHash]);
            $parentId = (int)$db->lastInsertId();
            $db->prepare("INSERT IGNORE INTO parent_children (parent_id, athlete_id) VALUES (?,?)")->execute([$parentId, $id]);
        }
        // Ensure child link exists
        $db->prepare("INSERT IGNORE INTO parent_children (parent_id, athlete_id) VALUES (?,?)")->execute([$parentId, $id]);
        $schStmt = $db->prepare("SELECT name FROM schools WHERE id=? LIMIT 1");
        $schStmt->execute([$sid]);
        $schoolName = (string)($schStmt->fetchColumn() ?: 'MAster');
        $emailDebug = null;
        $emailSent = sendParentCredentials($pEmail, $rawPassword, trim((string)($ath['full_name'] ?? '')), $schoolName, $emailDebug);
        if ($emailSent) {
            flash('✉️ Νέοι κωδικοί στάλθηκαν στο ' . htmlspecialchars($pEmail, ENT_QUOTES, 'UTF-8') . '.');
        } else {
            $debugHint = $emailDebug ? ' [' . htmlspecialchars(substr($emailDebug, 0, 120), ENT_QUOTES, 'UTF-8') . ']' : '';
            flash('Email αδύνατο να σταλεί' . $debugHint . '. Νέος κωδικός πρόσβασης (δώστε τον χειροκίνητα): <strong>' . htmlspecialchars($rawPassword, ENT_QUOTES, 'UTF-8') . '</strong>', 'warning');
        }
        redirect(APP_URL . '/pages/athletes.php?view=' . $id);
    }

    // ── Grant / re-send athlete portal access (adult athletes only) ──
    if (($a['_action'] ?? '') === 'grant_athlete_portal') {
        $id = (int)($a['id'] ?? 0);
        $athRow = $db->prepare("SELECT full_name, email, birthdate FROM athletes WHERE id=? AND school_id=? LIMIT 1");
        $athRow->execute([$id, $sid]);
        $ath = $athRow->fetch();
        if (!$ath || !filter_var($ath['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            flash('Χρειάζεται έγκυρο email αθλητή για να δοθεί πρόσβαση.', 'danger');
            redirect(APP_URL . '/pages/athletes.php?view=' . $id);
        }
        // Adult check
        $adult = true;
        if (!empty($ath['birthdate'])) {
            try { $adult = ((new DateTime())->diff(new DateTime($ath['birthdate']))->y >= 18); }
            catch (Exception $e) { $adult = false; }
        }
        if (!$adult) {
            flash('Το Portal Αθλητή διατίθεται μόνο σε ενήλικες αθλητές (18+).', 'warning');
            redirect(APP_URL . '/pages/athletes.php?view=' . $id);
        }

        require_once __DIR__ . '/../includes/mailer.php';
        $aEmail = trim(strtolower($ath['email']));
        $rawPassword  = bin2hex(random_bytes(5));
        $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);

        // Upsert athlete_users
        $existStmt = $db->prepare("SELECT id FROM athlete_users WHERE athlete_id=? LIMIT 1");
        $existStmt->execute([$id]);
        $auId = (int)$existStmt->fetchColumn();
        if ($auId) {
            $db->prepare("UPDATE athlete_users SET email=?, password_hash=?, first_login=1, active=1 WHERE id=?")
               ->execute([$aEmail, $passwordHash, $auId]);
        } else {
            $db->prepare("INSERT INTO athlete_users (school_id, athlete_id, email, password_hash, first_login, active)
                          VALUES (?, ?, ?, ?, 1, 1)")
               ->execute([$sid, $id, $aEmail, $passwordHash]);
        }
        // Flip flag on athletes
        $db->prepare("UPDATE athletes SET athlete_portal_access=1 WHERE id=? AND school_id=?")->execute([$id, $sid]);

        $schStmt = $db->prepare("SELECT name FROM schools WHERE id=? LIMIT 1");
        $schStmt->execute([$sid]);
        $schoolName = (string)($schStmt->fetchColumn() ?: 'MAster');
        $emailDebug = null;
        $emailSent = sendAthleteCredentials($aEmail, $rawPassword, trim((string)$ath['full_name']), $schoolName, $emailDebug);
        if ($emailSent) {
            flash('✉️ Στάλθηκαν κωδικοί πρόσβασης Portal Αθλητή στο ' . htmlspecialchars($aEmail, ENT_QUOTES, 'UTF-8') . '.');
        } else {
            $hint = $emailDebug ? ' [' . htmlspecialchars(substr($emailDebug, 0, 120), ENT_QUOTES, 'UTF-8') . ']' : '';
            flash('Το email δεν στάλθηκε' . $hint . '. Δώστε χειροκίνητα τον κωδικό: <strong>' . htmlspecialchars($rawPassword, ENT_QUOTES, 'UTF-8') . '</strong>', 'warning');
        }
        redirect(APP_URL . '/pages/athletes.php?view=' . $id);
    }

    if (($a['_action'] ?? '') === 'revoke_athlete_portal') {
        $id = (int)($a['id'] ?? 0);
        $db->prepare("UPDATE athletes SET athlete_portal_access=0 WHERE id=? AND school_id=?")->execute([$id, $sid]);
        $db->prepare("UPDATE athlete_users SET active=0 WHERE athlete_id=? AND school_id=?")->execute([$id, $sid]);
        flash('Η πρόσβαση Portal Αθλητή απενεργοποιήθηκε.', 'warning');
        redirect(APP_URL . '/pages/athletes.php?view=' . $id);
    }

    if (($a['_action'] ?? '') === 'deactivate_athlete') {
        $id = (int)$a['id'];
        $db->prepare("UPDATE athletes SET active=0 WHERE id=? AND school_id=?")->execute([$id,$sid]);
        flash('Ο αθλητής απενεργοποιήθηκε.', 'warning');
        redirect(APP_URL.'/pages/athletes.php');
    }

    if (($a['_action'] ?? '') === 'reactivate_athlete') {
        $id = (int)$a['id'];
        $db->prepare("UPDATE athletes SET active=1 WHERE id=? AND school_id=?")->execute([$id,$sid]);
        flash('Ο αθλητής επανενεργοποιήθηκε!');
        redirect(APP_URL.'/pages/athletes.php?status=inactive');
    }

    if (($a['_action'] ?? '') === 'delete_athlete') {
        $id = (int)$a['id'];
        foreach (['subscriptions','weight_history','competition_participants','athlete_pause_periods'] as $tbl) {
            $db->prepare("DELETE FROM $tbl WHERE athlete_id=?")->execute([$id]);
        }
        $db->prepare("DELETE FROM reminder_logs WHERE athlete_id=? AND school_id=?")->execute([$id,$sid]);
        $db->prepare("DELETE FROM transactions WHERE athlete_id=? AND school_id=?")->execute([$id,$sid]);
        $db->prepare("DELETE FROM athletes WHERE id=? AND school_id=?")->execute([$id,$sid]);
        flash('Ο αθλητής διαγράφηκε οριστικά.','danger');
        redirect(APP_URL.'/pages/athletes.php');
    }

    if (($a['_action'] ?? '') === 'add_weight') {
        $db->prepare("INSERT INTO weight_history (athlete_id,weight,recorded_at,notes) VALUES (?,?,?,?)")
           ->execute([$a['athlete_id'],$a['weight'],$a['recorded_at'],$a['notes']??'']);
        $db->prepare("UPDATE athletes SET weight=? WHERE id=? AND school_id=?")->execute([$a['weight'],$a['athlete_id'],$sid]);
        flash('Βάρος καταγράφηκε!');
        redirect(APP_URL.'/pages/athletes.php?view='.$a['athlete_id']);
    }

    if (($a['_action'] ?? '') === 'edit_payment') {
        $pid    = (int)($a['id'] ?? 0);
        $amount = max(0, floatval(str_replace(',', '.', $a['amount'] ?? 0)));
        $method = in_array($a['payment_method'] ?? '', ['cash','card','deposit'], true) ? $a['payment_method'] : 'cash';
        $paidAt = trim($a['paid_at'] ?? '') ?: date('Y-m-d');
        
        // Convert from dd/mm/yyyy to Y-m-d
        if ($paidAt && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $paidAt, $m)) {
            $paidAt = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        
        $notes  = trim($a['notes'] ?? '');
        $status = in_array($a['status'] ?? '', ['paid','pending','overdue'], true) ? $a['status'] : 'paid';

        $own = $db->prepare("SELECT s.id FROM subscriptions s JOIN athletes a ON a.id=s.athlete_id WHERE s.id=? AND a.school_id=?");
        $own->execute([$pid, $sid]);

        if ($own->fetch()) {
            $db->prepare("UPDATE subscriptions SET amount=?, payment_method=?, paid_at=?, notes=?, status=? WHERE id=?")
               ->execute([$amount, $method, $paidAt, $notes, $status, $pid]);

            if ($status === 'paid' && $amount > 0) {
                $sr = $db->prepare("SELECT athlete_id, valid_from, valid_until FROM subscriptions WHERE id=? LIMIT 1");
                $sr->execute([$pid]);
                $subRow = $sr->fetch();
                if ($subRow) {
                    $athName2 = (string)$db->query("SELECT full_name FROM athletes WHERE id=" . (int)$subRow['athlete_id'])->fetchColumn();
                    syncSubscriptionTransaction($db,'upsert',$sid,$pid,(int)$subRow['athlete_id'],$amount,$paidAt,$method,$subRow['valid_from'],$subRow['valid_until'],$athName2);
                }
            } else {
                syncSubscriptionTransaction($db,'delete',$sid,$pid);
            }
            flash('Η πληρωμή ενημερώθηκε!');
        } else {
            flash('Δεν βρέθηκε η πληρωμή.', 'danger');
        }

        $back = trim($a['redirect_back'] ?? '');
        redirect($back ?: APP_URL.'/pages/athletes.php');
    }

    if (($a['_action'] ?? '') === 'delete_payment') {
        $pid = (int)($a['id'] ?? 0);
        $own = $db->prepare("SELECT s.id FROM subscriptions s JOIN athletes a ON a.id=s.athlete_id WHERE s.id=? AND a.school_id=?");
        $own->execute([$pid, $sid]);
        if ($own->fetch()) {
            syncSubscriptionTransaction($db,'delete',$sid,$pid);
            $db->prepare("DELETE FROM subscriptions WHERE id=?")->execute([$pid]);
            flash('Η πληρωμή διαγράφηκε.', 'danger');
        } else {
            flash('Δεν βρέθηκε η πληρωμή.', 'danger');
        }
        $back = trim($a['redirect_back'] ?? '');
        redirect($back ?: APP_URL.'/pages/athletes.php');
    }
}

$exemptMonths = getSchoolExemptMonths($db, $sid);
$greekMonths  = ['','Ιαν','Φεβ','Μαρ','Απρ','Μαι','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];

$depts = $db->prepare("SELECT id, name, monthly_fee FROM departments WHERE school_id=? AND active=1");
$depts->execute([$sid]);
$departments = $depts->fetchAll();

$search     = trim($_GET['q'] ?? '');
$dept       = (int)($_GET['dept'] ?? 0);
$status     = $_GET['status'] ?? 'active';
$debtFilter = $_GET['debt'] ?? '';
$perPage    = 10;
$page       = max(1,(int)($_GET['page'] ?? 1));
$offset     = ($page-1)*$perPage;

$where  = "a.school_id=?";
$params = [$sid];

if ($status === 'active')       $where .= " AND a.active=1";
elseif ($status === 'inactive') $where .= " AND a.active=0";
if ($search) { $where .= " AND a.full_name LIKE ?"; $params[] = "%$search%"; }
if ($dept)   { $where .= " AND a.department_id=?";  $params[] = $dept; }

$countStmt = $db->prepare("SELECT COUNT(*) FROM athletes a WHERE $where");
$countStmt->execute($params);
$totalAthletes = (int)$countStmt->fetchColumn();
$totalPages    = max(1, (int)ceil($totalAthletes / $perPage));

$stmt = $db->prepare("SELECT a.*, d.name as dept_name FROM athletes a LEFT JOIN departments d ON d.id=a.department_id WHERE $where ORDER BY a.full_name LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$athletes = $stmt->fetchAll();

foreach ($athletes as &$ath) {
    $pausePeriods         = getAthletePausePeriods($db, $ath['id']);
    $ath['_debt_start']   = getDebtStartDate($ath);
    $ath['_debt_summary'] = getAthleteDebtSummary(
        $db,
        $ath['id'],
        $ath['_debt_start'],
        floatval($ath['monthly_fee'] ?? 0),
        $exemptMonths,
        $pausePeriods
    );
    $ath['_debt']         = $ath['_debt_summary']['months'];
    $ath['_debt_balance'] = $ath['_debt_summary']['balance'];
    $ath['_is_paused']    = count($pausePeriods) > 0 && isMonthPaused((new DateTime())->modify('first day of this month'), $pausePeriods);
    $examDebts4List       = getAthleteExamFeeDebts($db, (int)$ath['id'], (int)$sid);
    $ath['_exam_fee_debt'] = (float)array_sum(array_column($examDebts4List, 'amount'));
}
unset($ath);

if ($debtFilter === 'owed') {
    $athletes = array_values(array_filter($athletes, function($a){ return $a['_debt']>0 || ($a['_exam_fee_debt'] ?? 0)>0; }));
} elseif ($debtFilter === 'ok') {
    $athletes = array_values(array_filter($athletes, function($a){ return $a['_debt']===0 && ($a['_exam_fee_debt'] ?? 0)<=0 && !$a['_is_paused']; }));
}

$allActiveStmt = $db->prepare("SELECT * FROM athletes WHERE school_id=? AND active=1");
$allActiveStmt->execute([$sid]);
$allActive = $allActiveStmt->fetchAll();
$totalOwed = 0;
$totalOwedAthletes = 0;
$totalCurrentlyPaused = 0;

foreach ($allActive as $a2) {
    $pp       = getAthletePausePeriods($db, $a2['id']);
    $nowMonth = (new DateTime())->modify('first day of this month');
    if (isMonthPaused($nowMonth, $pp)) { $totalCurrentlyPaused++; continue; }

    $ds = getDebtStartDate($a2);
    $summary = getAthleteDebtSummary(
        $db,
        $a2['id'],
        $ds,
        floatval($a2['monthly_fee'] ?? 0),
        $exemptMonths,
        $pp
    );

    $examDebtsForStats    = getAthleteExamFeeDebts($db, (int)$a2['id'], (int)$sid);
    $examDebtAmtForStats  = (float)array_sum(array_column($examDebtsForStats, 'amount'));

    if ($summary['balance'] > 0.009 || $examDebtAmtForStats > 0.009) {
        $totalOwedAthletes++;
        $totalOwed += $summary['balance'] + $examDebtAmtForStats;
    }
}

$athlete = null;
$weightHistory = [];
$athleteSubscriptions = [];
$debtMonths = 0;
$debtBalance = 0.0;
$unpaidMonths = [];
$pausePeriods = [];
$isCurrentlyPaused = false;
$examFeeDebts = [];
$examFeeDebtTotal = 0.0;
$examTransactions = [];

if ($viewId) {
    $s = $db->prepare("SELECT a.*,d.name as dept_name FROM athletes a LEFT JOIN departments d ON d.id=a.department_id WHERE a.id=? AND a.school_id=?");
    $s->execute([$viewId,$sid]);
    $athlete = $s->fetch();

    if ($athlete) {
        $wh = $db->prepare("SELECT * FROM weight_history WHERE athlete_id=? ORDER BY recorded_at DESC LIMIT 10");
        $wh->execute([$viewId]);
        $weightHistory = $wh->fetchAll();

        $ph = $db->prepare("SELECT * FROM subscriptions WHERE athlete_id=? ORDER BY valid_from DESC");
        $ph->execute([$viewId]);
        $athleteSubscriptions = $ph->fetchAll();
        
        // UPDATED: fetch ALL exam transactions for history display
        $examTransactions = getAthleteExamTransactions($db, $viewId, (int)$sid);

        $pausePeriods      = getAthletePausePeriods($db, $viewId);
        $nowMonth          = (new DateTime())->modify('first day of this month');
        $isCurrentlyPaused = isMonthPaused($nowMonth, $pausePeriods);
        $debtStart         = getDebtStartDate($athlete);

        $debtSummary = getAthleteDebtSummary(
            $db,
            $viewId,
            $debtStart,
            floatval($athlete['monthly_fee'] ?? 0),
            $exemptMonths,
            $pausePeriods
        );

        $debtMonths   = $debtSummary['months'];
        $debtBalance  = $debtSummary['balance'];
        $unpaidMonths = $debtSummary['unpaid'];
        $examFeeDebts     = getAthleteExamFeeDebts($db, $viewId, (int)$sid);
        $examFeeDebtTotal = (float)array_sum(array_column($examFeeDebts, 'amount'));
    }
}

$editAthlete = null;
if ($editId) {
    $s = $db->prepare("SELECT * FROM athletes WHERE id=? AND school_id=?");
    $s->execute([$editId,$sid]);
    $editAthlete = $s->fetch();
}

function pageUrl(int $p): string {
    $q=$_GET; $q['page']=$p; return '?'.http_build_query($q);
}

$limitReached = !empty($_GET['limit_reached']);
$athleteLimit = getAthleteLimit();
$athleteCount = getAthleteCount();
$nearLimit    = ($athleteLimit < 9999 && $athleteCount >= ($athleteLimit - 5));

// Στοιχεία Pro πλάνου για το upgrade popup
$_proStmt = $db->prepare("SELECT price_monthly, price_annual, features FROM plans WHERE slug='pro' LIMIT 1");
$_proStmt->execute();
$_proPlan = $_proStmt->fetch() ?: ['price_monthly'=>25.00,'price_annual'=>240.00,'features'=>''];
$_proMonthly = number_format((float)$_proPlan['price_monthly'], 2);
$_proAnnual  = number_format((float)$_proPlan['price_annual'], 2);
$_bankName   = getSetting('bank_name','');
$_bankIban   = getSetting('bank_iban','');
$_bankBenef  = getSetting('bank_beneficiary','');
$_bankEmail  = getSetting('bank_receipt_email','') ?: getMailFromEmail();
$_irisPhone  = getSetting('iris_phone','');
$_irisAfm    = getSetting('iris_afm','');
$_schoolNameStmt = $db->prepare("SELECT name FROM schools WHERE id=?");
$_schoolNameStmt->execute([schoolId()]);
$_schoolName   = $_schoolNameStmt->fetchColumn() ?: APP_NAME;
$_refCode      = 'Αναβάθμιση Pro — ' . $_schoolName;
$_emailSubject = 'Αναβάθμιση Pro — ' . $_schoolName;

renderHead('Αθλητές');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* (all previous styles remain unchanged) */
input,input:hover,input:focus,select,select:hover,select:focus,textarea,textarea:hover,textarea:focus{box-shadow:none!important;-webkit-box-shadow:none!important;background-image:none!important;}
input:-webkit-autofill,input:-webkit-autofill:hover,input:-webkit-autofill:focus{-webkit-box-shadow:0 0 0 1000px #1a1f2e inset!important;-webkit-text-fill-color:var(--text,#e2e8f0)!important;}
.topbar{position:relative!important;top:auto!important;z-index:auto!important}
@media(max-width:900px){#menuBtn{display:inline-flex!important;min-width:44px!important;min-height:44px!important;align-items:center!important;justify-content:center!important;font-size:1.2rem!important;cursor:pointer!important}.sidebar{position:fixed!important;top:0!important;left:0!important;bottom:0!important;width:min(280px,80vw)!important;z-index:9999!important;transform:translateX(-110%)!important;transition:transform .28s cubic-bezier(.2,.8,.2,1)!important;overflow-y:auto}.sidebar.open{transform:translateX(0)!important;box-shadow:6px 0 40px rgba(0,0,0,.6)!important}.main-content{margin-left:0!important;width:100%!important}}
#dm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;cursor:pointer}#dm-overlay.on{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.page-body{animation:fadeIn .35s ease both}.anim-1{opacity:0;animation:fadeUp .42s ease-out .05s both}.anim-2{opacity:0;animation:fadeUp .42s ease-out .12s both}.anim-3{opacity:0;animation:fadeUp .42s ease-out .19s both}.anim-4{opacity:0;animation:fadeUp .42s ease-out .26s both}
@media(prefers-reduced-motion:reduce){.page-body,.anim-1,.anim-2,.anim-3,.anim-4{animation:none!important;opacity:1}}

.stat-cards-row{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;margin-bottom:1.1rem}
.stat-card{border-radius:18px;padding:1rem 1.1rem;display:flex;flex-direction:row;align-items:center;gap:.85rem}
.stat-card.clickable{cursor:pointer;transition:transform .15s,box-shadow .15s}.stat-card.clickable:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3)}
.stat-icon{width:48px;height:48px;min-width:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.icon-blue{background:rgba(59,130,246,.15);color:#3b82f6}.icon-green{background:rgba(45,198,83,.15);color:#2dc653}.icon-gold{background:rgba(240,165,0,.15);color:#f0a500}.icon-red{background:rgba(230,57,70,.15);color:#e63946}.icon-purple{background:rgba(148,103,189,.15);color:#9467bd}
.stat-text{display:flex;flex-direction:column;gap:.1rem;min-width:0}
.stat-lbl{font-size:clamp(.78rem,2vw,.88rem)!important;color:var(--muted,#8892b0);font-weight:600;line-height:1.2}
.stat-val{font-size:clamp(1.4rem,3.5vw,2rem)!important;font-weight:800;line-height:1}
.stat-sub{font-size:clamp(.7rem,1.8vw,.78rem)!important;color:var(--muted,#8892b0);margin-top:.1rem}
@media(max-width:480px){.stat-cards-row{grid-template-columns:1fr;gap:.75rem}}

.card{border-radius:18px}
.card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.9rem 1.1rem;border-bottom:1px solid var(--border,#1e2536)}
.card-title{font-size:clamp(1rem,3.5vw,1.1rem)!important;font-weight:800;display:flex;align-items:center;gap:.45rem}
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem}
.page-header h2{font-size:clamp(1.15rem,4vw,1.5rem)!important;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0}

.filters-bar{display:flex;flex-wrap:wrap;gap:.55rem;align-items:center;margin-bottom:.9rem}
.search-bar{position:relative;flex:1 1 100%;min-width:0}
.search-bar .search-icon{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--muted,#8892b0);pointer-events:none;z-index:2;font-size:.9rem}
.search-bar input{display:block;width:100%;height:44px;padding:0 .75rem 0 2.5rem!important;border-radius:10px!important;font-size:clamp(.9rem,3.5vw,.97rem)!important;background-color:#1a1f2e!important;background-image:none!important;-webkit-appearance:none;appearance:none;border:1px solid #2a3147!important;color:var(--text,#e2e8f0);box-sizing:border-box;box-shadow:none!important;outline:none!important;}
.search-bar input:focus{border-color:#e63946!important;box-shadow:0 0 0 3px rgba(230,57,70,.18)!important;}
.filter-select-wrap{position:relative;height:44px;flex:1;min-width:130px;max-width:200px;display:flex;align-items:stretch;}
.filter-select-wrap select{width:100%;height:44px;padding:0 2rem 0 .75rem;font-size:clamp(.88rem,3.5vw,.95rem)!important;background-color:#1a1f2e!important;background-image:none!important;border:1px solid #2a3147!important;border-radius:10px;color:var(--text,#e2e8f0);-webkit-appearance:none;appearance:none;box-sizing:border-box;box-shadow:none!important;cursor:pointer;}
.filter-select-wrap select:focus{border-color:#e63946!important;box-shadow:0 0 0 3px rgba(230,57,70,.18)!important;}
.filter-select-wrap::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;right:.65rem;top:50%;transform:translateY(-50%);color:var(--muted,#8892b0);font-size:.65rem;pointer-events:none;}
@media(max-width:700px){.filters-bar{display:grid!important;grid-template-columns:1fr 1fr!important;gap:.55rem!important;align-items:stretch!important;}.search-bar{grid-column:1/-1!important;width:100%!important}.filter-select-wrap{width:100%!important;min-width:0!important;max-width:none!important;flex:none!important;}.filters-bar a.btn{grid-column:1/-1!important;justify-content:center!important;height:44px!important}}

.debt-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .55rem;border-radius:20px;font-size:.78rem;font-weight:700;white-space:nowrap}
.debt-badge.ok{background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.3)}
.debt-badge.low{background:rgba(240,165,0,.12);color:#f0a500;border:1px solid rgba(240,165,0,.3)}
.debt-badge.high{background:rgba(230,57,70,.12);color:#e63946;border:1px solid rgba(230,57,70,.3)}
.debt-badge.paused{background:rgba(148,103,189,.12);color:#9467bd;border:1px solid rgba(148,103,189,.3)}
.month-chip{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .55rem;border-radius:20px;font-size:.75rem;font-weight:700}
.month-chip.unpaid{background:rgba(230,57,70,.1);color:#e63946;border:1px solid rgba(230,57,70,.2)}
.exempt-chip{display:inline-flex;align-items:center;gap:.25rem;padding:.18rem .55rem;border-radius:20px;font-size:.74rem;font-weight:700;background:rgba(148,103,189,.1);color:#9467bd;border:1px solid rgba(148,103,189,.25)}
.pause-chip{display:inline-flex;align-items:center;gap:.25rem;padding:.18rem .55rem;border-radius:20px;font-size:.74rem;font-weight:700;background:rgba(148,103,189,.1);color:#9467bd;border:1px solid rgba(148,103,189,.25)}

.pause-row{display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;border-radius:10px;background:rgba(148,103,189,.07);border:1px solid rgba(148,103,189,.2);margin-bottom:.4rem;flex-wrap:wrap}
.pause-row .pause-dates{font-weight:700;color:var(--text,#e2e8f0);font-size:.92rem;display:flex;align-items:center;gap:.35rem}
.pause-row .pause-note{font-size:.82rem;color:var(--muted,#8892b0);flex:1}
.pause-row .pause-active-badge{background:rgba(148,103,189,.2);color:#9467bd;border:1px solid rgba(148,103,189,.4);border-radius:20px;padding:.1rem .45rem;font-size:.72rem;font-weight:800}
.pause-add-form{background:rgba(255,255,255,.03);border:1px solid var(--border,#1e2536);border-radius:12px;padding:.85rem 1rem;margin-top:.75rem}

.exempt-info-box{background:rgba(148,103,189,.06);border:1px solid rgba(148,103,189,.2);border-radius:12px;padding:.75rem 1rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.65rem}
.exempt-info-box .ei{color:#9467bd;font-size:1rem;flex-shrink:0;margin-top:.1rem}

.exempt-month-row{display:flex;align-items:center;gap:.5rem;padding:.45rem .5rem;border-radius:8px;background:rgba(255,255,255,.03);border:1px solid var(--border,#1e2536);margin-bottom:.4rem}
.exempt-month-row input{min-height:36px;padding:.35rem .65rem;border-radius:8px;font-size:.92rem;background:#1a1f2e;border:1px solid #2a3147;color:var(--text,#e2e8f0)}

.profile-header{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
.profile-avatar{width:clamp(52px,12vw,68px);height:clamp(52px,12vw,68px);border-radius:50%;background:rgba(230,57,70,.15);border:2px solid rgba(230,57,70,.35);display:flex;align-items:center;justify-content:center;font-size:clamp(1.3rem,4vw,1.8rem);font-weight:800;color:#e63946;flex-shrink:0}
.profile-name{font-size:clamp(1.15rem,4.5vw,1.6rem)!important;font-weight:800;line-height:1.2}
.profile-meta{font-size:clamp(.85rem,3vw,.95rem)!important;color:var(--muted,#8892b0);margin-top:.2rem;display:flex;flex-wrap:wrap;gap:.5rem .9rem}
.debt-info-box{border-radius:14px;padding:.85rem 1rem;margin-bottom:1rem}
.debt-info-box.ok{background:rgba(45,198,83,.07);border:1px solid rgba(45,198,83,.25)}
.debt-info-box.has-debt{background:rgba(230,57,70,.07);border:1px solid rgba(230,57,70,.25)}
.debt-info-box.is-paused{background:rgba(148,103,189,.07);border:1px solid rgba(148,103,189,.3)}
.debt-months-num{font-size:clamp(1.3rem,4vw,1.7rem);font-weight:800;color:#e63946}
.debt-label-sm{font-size:.85rem;color:#8892b0;margin-left:.35rem}
.info-callout{display:flex;align-items:flex-start;gap:.75rem;padding:.85rem 1rem;border-radius:12px;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.18);margin-bottom:1rem}
.info-callout .ci{flex-shrink:0;color:#3b82f6;font-size:1.15rem;margin-top:.1rem}
.info-callout p{margin:0;font-size:clamp(.86rem,3vw,.92rem)!important;color:var(--muted,#8892b0);line-height:1.55}
.info-callout strong{color:var(--text,#e2e8f0)}

.form-label{font-size:clamp(1rem,3.8vw,1.05rem)!important;font-weight:700;display:block;margin-bottom:.45rem;color:var(--text,#e2e8f0)}
.form-control{font-size:clamp(1rem,3.8vw,1.05rem)!important;min-height:48px;padding:.7rem 1rem;border-radius:10px!important;transition:border-color .2s,box-shadow .2s;width:100%;background:var(--input-bg,#0f1219);border:1px solid var(--border,#1e2536);color:var(--text,#e2e8f0)}
.form-control:focus{outline:none;border-color:#e63946!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
textarea.form-control{min-height:90px;resize:vertical}
.form-section-title{font-size:clamp(.88rem,3.2vw,.96rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#8892b0);margin-bottom:.85rem;margin-top:1.5rem;display:flex;align-items:center;gap:.5rem;padding-bottom:.55rem;border-bottom:1px solid var(--border,#1e2536)}
.form-row{display:grid;gap:1rem}.form-row.col-2{grid-template-columns:1fr 1fr}.form-row.col-3{grid-template-columns:1fr 1fr 1fr}
.form-hint{font-size:clamp(.76rem,2.5vw,.82rem)!important;color:var(--muted,#8892b0);margin-top:.3rem;display:flex;align-items:flex-start;gap:.3rem;line-height:1.4}
@media(max-width:700px){.form-row.col-3{grid-template-columns:1fr 1fr!important}}
@media(max-width:480px){.form-row.col-3,.form-row.col-2{grid-template-columns:1fr!important}}

.btn{min-height:38px;font-size:clamp(.88rem,3vw,.95rem)!important;font-weight:700!important;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;transition:all .18s;text-decoration:none;padding:.45rem .9rem;cursor:pointer;border:none;white-space:nowrap}
.btn:active{transform:scale(.97)}.btn-sm{min-height:34px;padding:.35rem .75rem}
.btn-action-text{display:inline-flex;align-items:center;gap:.28rem;font-size:.78rem!important;font-weight:700;padding:.3rem .58rem;min-height:28px;border-radius:8px;text-decoration:none;transition:all .18s;cursor:pointer;border:none;white-space:nowrap;line-height:1}
.btn-edit-ath{background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.25)}.btn-edit-ath:hover{background:rgba(59,130,246,.22)}
.btn-del-ath{background:rgba(230,57,70,.1);color:#e63946;border:1px solid rgba(230,57,70,.25)}.btn-del-ath:hover{background:rgba(230,57,70,.2)}
.btn-add-athlete{font-size:1.05rem!important;font-weight:800!important;padding:.7rem 1.6rem!important;min-height:48px!important;border-radius:12px!important;letter-spacing:.02em;gap:.5rem!important;}
@media(max-width:500px){.btn-add-athlete{font-size:.95rem!important;padding:.65rem 1.2rem!important;min-height:44px!important}}

.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}.table-wrap table{width:100%;border-collapse:collapse}
.table-wrap th{font-size:clamp(.72rem,2.2vw,.82rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;padding:.45rem .7rem;color:var(--muted,#8892b0)}
.table-wrap td{font-size:clamp(.82rem,2.8vw,.94rem)!important;padding:.45rem .7rem;vertical-align:middle}
.table-wrap tbody tr{transition:background .15s}.table-wrap tbody tr:hover{background:rgba(255,255,255,.03)}
.athlete-name{font-weight:700;display:block;font-size:clamp(.85rem,2.8vw,.95rem)!important}
.athlete-sub{font-size:clamp(.7rem,2.2vw,.76rem)!important;color:var(--muted,#8892b0);margin-top:.04rem}
.athlete-mobile-meta{display:none}
.athlete-row td{cursor:pointer}.athlete-row:hover td{background:rgba(255,255,255,.035)}
@media(max-width:700px){
  .col-hide-mobile{display:none!important}
  .athlete-mobile-meta{
    display:flex;
    flex-wrap:wrap;
    gap:.28rem;
    margin-top:.28rem
  }
  .athlete-mobile-chip{
    display:inline-flex;
    align-items:center;
    gap:.26rem;
    padding:.14rem .42rem;
    border-radius:999px;
    font-size:.66rem;
    font-weight:700;
    line-height:1.2;
    border:1px solid rgba(255,255,255,.09);
    background:rgba(255,255,255,.04);
    color:var(--muted,#8892b0)
  }
}

@media(max-width:500px){
  .table-wrap th{
    padding:.55rem .5rem!important;
    font-size:.85rem!important;
    letter-spacing:.01em
  }
  .table-wrap td{
    padding:.65rem .5rem!important;
    font-size:1rem!important
  }
  .athlete-name{
    font-size:1.05rem!important;
    font-weight:800!important
  }
  .athlete-sub{
    font-size:.82rem!important
  }
  .phone-cell a{
    font-size:.92rem!important;
    padding:.38rem .65rem!important;
    gap:.4rem!important
  }
  .phone-cell a i{
    display:inline!important;
    font-size:.82rem!important
  }
}
@media(max-width:480px){
  .debt-span{
    font-size:.82rem!important;
    padding:.28rem .6rem!important;
    gap:.28rem!important;
    white-space:normal!important;
    line-height:1.35!important;
    border-radius:7px!important
  }
  .debt-span i{
    display:inline!important;
    font-size:.72rem!important
  }
  .debt-cell>div{
    gap:.3rem!important
  }
  .status-span{
    font-size:.82rem!important;
    padding:.32rem .65rem!important;
    gap:.3rem!important
  }
  .status-span i{
    font-size:.62rem!important
  }
  .btn-action-text span{
    display:none!important
  }
  .btn-action-text{
    font-size:.95rem!important;
    padding:.5rem .65rem!important;
    min-height:42px!important;
    min-width:42px!important;
    justify-content:center!important;
    border-radius:9px!important
  }
  .btn-action-text i{
    font-size:1rem!important;
    margin:0!important
  }
  .athlete-mobile-chip{
    font-size:.78rem!important;
    padding:.22rem .55rem!important
  }
}

@media(max-width:480px){
  .btn-action-text{
    font-size:.85rem!important;
    padding:.5rem .75rem!important;
    min-height:42px!important;
    justify-content:center!important;
    border-radius:9px!important;
    white-space:nowrap;
  }
  .btn-action-text i{
    font-size:.9rem!important;
    margin-right:.25rem!important;
  }
  .btn-action-text span{
    display:inline!important;
    margin-left:.2rem;
  }
}

.weight-form{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;align-items:flex-end}.weight-form .form-control{flex:1;min-width:90px;min-height:40px!important}
.info-table{width:100%;border-collapse:collapse;table-layout:fixed}
.info-table td{font-size:clamp(.9rem,3.5vw,1rem)!important;padding:.45rem .5rem .45rem 0;vertical-align:top;line-height:1.4;overflow:hidden}
.info-table td:first-child{color:var(--muted,#8892b0);font-weight:600;width:42%;white-space:nowrap;padding-right:.75rem}
.info-table td:last-child{font-weight:500;word-break:break-all;overflow-wrap:anywhere;width:58%}
.history-table{width:100%;border-collapse:collapse}
.history-table th{font-size:clamp(.74rem,2.5vw,.8rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;padding:.45rem .6rem;color:var(--muted,#8892b0)}
.history-table td{font-size:clamp(.88rem,3vw,.95rem)!important;padding:.5rem .6rem;vertical-align:middle}
html,body{height:auto!important}

/* ── Inline validation patch ── */
.field-wrap{position:relative}
.form-control.is-invalid{
  border-color:#e63946!important;
  box-shadow:0 0 0 3px rgba(230,57,70,.14)!important;
}
.form-control.is-valid{
  border-color:#2dc653!important;
  box-shadow:0 0 0 3px rgba(45,198,83,.12)!important;
}
.inline-error{
  display:none;
  margin-top:.38rem;
  font-size:.8rem;
  color:#ff8f8f;
  font-weight:700;
  line-height:1.35;
}
.inline-error.show{display:block}
.inline-ok{
  display:none;
  margin-top:.38rem;
  font-size:.78rem;
  color:#2dc653;
  font-weight:800;
  line-height:1.35;
}
.inline-ok.show{display:block}
.field-wrap.has-icon .form-control{padding-right:2.4rem}
.field-status-icon{
  position:absolute;
  right:.8rem;
  top:50%;
  transform:translateY(-50%);
  font-size:.9rem;
  pointer-events:none;
  opacity:0;
  transition:opacity .16s ease;
}
.field-wrap.is-invalid .field-status-icon.error{
  opacity:1;
  color:#e63946;
}
.field-wrap.is-valid .field-status-icon.ok{
  opacity:1;
  color:#2dc653;
}
@keyframes fieldShake{
  0%,100%{transform:translateX(0)}
  20%{transform:translateX(-4px)}
  40%{transform:translateX(4px)}
  60%{transform:translateX(-3px)}
  80%{transform:translateX(3px)}
}
.field-shake{animation:fieldShake .22s ease}

.profile-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
@media(max-width:700px){.profile-grid-2{grid-template-columns:1fr!important;gap:.7rem!important}}

.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}
@media(max-width:900px){.grid-3{grid-template-columns:1fr 1fr!important;gap:.85rem!important}.page-body{padding:1rem!important}}
@media(max-width:700px){.grid-3{grid-template-columns:1fr!important;gap:.7rem!important}.page-body{padding:.85rem!important}}
@media(max-width:480px){.page-body{padding:.75rem!important}.card{border-radius:14px;overflow:hidden;box-sizing:border-box;width:100%}.grid-3{width:100%!important;box-sizing:border-box!important}}
.nav-item{min-height:46px!important;font-size:clamp(.92rem,3vw,1rem)!important;font-weight:600!important;padding:.65rem .9rem!important;border-radius:10px!important;display:flex!important;align-items:center!important;gap:.7rem!important;transition:background .15s,color .15s!important;text-decoration:none}.nav-item .icon{width:22px;text-align:center;font-size:1rem;flex-shrink:0}
.sidebar-school{margin:.25rem 1rem!important;padding:0!important;display:flex!important;align-items:center!important;font-weight:700!important;font-size:clamp(.82rem,3vw,.92rem)!important;color:var(--text,#f0f2ff)!important;white-space:normal!important;overflow:visible!important;overflow-wrap:anywhere!important;background:none!important;border:none!important;box-shadow:none!important}

.table-wrap table,.table-wrap th,.table-wrap td{border:1px solid rgba(255,255,255,.1);border-collapse:collapse}
.table-wrap th{background:rgba(0,0,0,.2);font-weight:800}
.table-wrap td{background:transparent}

@media(max-width:700px){
  .info-table td:first-child{width:40%!important;white-space:normal!important;padding-right:.5rem}
  .info-table td:last-child{width:60%!important;word-break:break-word;white-space:normal}
  .info-table td{font-size:.85rem!important;padding:.4rem .3rem}
}
@media(max-width:700px){.profile-grid-2 .card{overflow-x:auto}}
	
	
	
	
	@media(min-width:701px){
  .debt-ok-action{
    width:auto!important;
    margin-top:0!important;
  }
  .debt-ok-action .btn{
    width:auto!important;
  }
}
@media(max-width:700px){
  .debt-ok-wrap{
    align-items:flex-start!important;
  }
  .debt-ok-text{
    min-width:0!important;
    width:calc(100% - 54px);
  }
  .debt-ok-action{
    width:100%!important;
    margin-top:.65rem!important;
  }
  .debt-ok-action .btn{
    width:100%!important;
    justify-content:center!important;
    min-height:44px!important;
  }
}
	
	
	


	

.history-action-buttons{
  display:flex;
  gap:.35rem;
  flex-wrap:wrap;
}

.history-action-buttons button{
  white-space:nowrap;
}

@media(max-width:700px){
  .history-action-buttons{
    flex-direction:row;
    flex-wrap:wrap;
  }

  .history-action-buttons button{
    font-size:.8rem !important;
    padding:.42rem .7rem !important;
    min-height:36px !important;
  }

  .history-table td{
    font-size:.78rem !important;
    padding:.35rem .4rem !important;
  }

  .history-table th{
    font-size:.68rem !important;
    padding:.35rem .4rem !important;
  }

  /* Exam description specifically */
  .history-table td:first-child{
    max-width:110px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:.75rem !important;
  }
}
</style>
<body>
<div class="app-layout">
<?php renderSidebar('athletes'); ?>
<div id="dm-overlay"></div>
<div class="main-content">
<?php renderTopbar('Αθλητές'); ?>
<div class="page-body">

<?php if(isAthleteLimit()): ?>
<!-- ══ ATHLETE LIMIT UPGRADE POPUP ══════════════════════════════ -->
<style>
#lpOverlay{display:<?=$limitReached?'flex':'none'?>;position:fixed;inset:0;z-index:99999;align-items:flex-start;justify-content:center;background:rgba(0,0,0,.7);padding:1rem;overflow-y:auto}
#lpBox{background:#111520;border:1.5px solid #f0a500;border-radius:16px;max-width:420px;width:100%;margin:auto;position:relative}
.lpR{display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.05);border:1px solid #1e2536;border-radius:7px;padding:.35rem .6rem;gap:.4rem}
.lpR span{font-size:.85rem;font-weight:700;color:#f0f2ff;font-family:monospace;word-break:break-all;flex:1}
.lpCp{background:none;border:none;color:#8892b0;cursor:pointer;font-size:.8rem;padding:.1rem .2rem;flex-shrink:0}
.lpLbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#8892b0;margin-bottom:.18rem}
@media(max-width:480px){#lpBox{border-radius:12px}}
</style>
<div id="lpOverlay">
<div id="lpBox">
  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.85rem 1rem .75rem;border-bottom:1px solid rgba(255,255,255,.07)">
    <div style="display:flex;align-items:center;gap:.6rem">
      <i class="fas fa-trophy" style="color:#f0a500;font-size:1rem"></i>
      <div>
        <div style="font-size:.95rem;font-weight:800;color:#f0f2ff">Όριο Αθλητών — Αναβάθμιση σε Pro</div>
        <div style="font-size:.78rem;color:#8892b0">Basic: <strong style="color:#f0a500"><?=$athleteCount?>/<?=$athleteLimit?></strong> αθλητές · Pro: <strong style="color:#a78bfa">απεριόριστοι</strong> + SMS + όλα</div>
      </div>
    </div>
    <button onclick="document.getElementById('lpOverlay').style.display='none'"
            style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);color:#8892b0;border-radius:8px;width:32px;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.95rem">
      <i class="fas fa-xmark"></i>
    </button>
  </div>

  <!-- Body -->
  <div style="padding:.9rem 1rem">

    <!-- Price line -->
    <div style="display:flex;gap:.5rem;margin-bottom:.85rem">
      <div style="flex:1;background:rgba(167,139,250,.07);border:1px solid rgba(167,139,250,.25);border-radius:10px;padding:.55rem .7rem;text-align:center">
        <div style="font-size:.68rem;color:#8892b0;margin-bottom:.1rem">Μηνιαία</div>
        <div style="font-size:1.3rem;font-weight:800;color:#a78bfa">€<?=$_proMonthly?><span style="font-size:.65rem;font-weight:500">/μήνα</span></div>
      </div>
      <div style="flex:1;background:rgba(45,198,83,.06);border:1px solid rgba(45,198,83,.22);border-radius:10px;padding:.55rem .7rem;text-align:center;position:relative">
        <div style="position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:#2dc653;color:#0a0e16;font-size:.6rem;font-weight:800;padding:.1rem .4rem;border-radius:20px;white-space:nowrap">ΟΙΚΟΝΟΜΙΚΟΤΕΡΟ</div>
        <div style="font-size:.68rem;color:#8892b0;margin-bottom:.1rem">Ετήσια</div>
        <div style="font-size:1.3rem;font-weight:800;color:#2dc653">€<?=$_proAnnual?><span style="font-size:.65rem;font-weight:500">/έτος</span></div>
      </div>
    </div>

    <!-- Payment details -->
    <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#8892b0;margin-bottom:.5rem">
      <i class="fas fa-building-columns" style="color:#3b82f6"></i> Πληρωμή — IRIS / Τραπεζική Μεταφορά
    </div>
    <div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:.75rem">
      <?php if($_bankBenef): ?>
      <div><div class="lpLbl">Δικαιούχος</div>
        <div class="lpR"><span><?=h($_bankBenef)?></span><button class="lpCp" onclick="lpCopy('<?=h($_bankBenef)?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
      <?php endif; ?>
      <?php if($_bankName): ?>
      <div><div class="lpLbl">Τράπεζα</div>
        <div class="lpR"><span><?=h($_bankName)?></span></div>
      </div>
      <?php endif; ?>
      <?php if($_bankIban): ?>
      <div><div class="lpLbl">IBAN</div>
        <div class="lpR"><span><?=h($_bankIban)?></span><button class="lpCp" onclick="lpCopy('<?=h(str_replace(' ','',$_bankIban))?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
      <?php endif; ?>
      <?php if($_irisPhone): ?>
      <div><div class="lpLbl"><i class="fas fa-bolt" style="color:#f0a500"></i> IRIS — Τηλέφωνο</div>
        <div class="lpR"><span><?=h($_irisPhone)?></span><button class="lpCp" onclick="lpCopy('<?=h($_irisPhone)?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
      <?php endif; ?>
      <?php if($_irisAfm): ?>
      <div><div class="lpLbl"><i class="fas fa-bolt" style="color:#f0a500"></i> IRIS — ΑΦΜ</div>
        <div class="lpR"><span><?=h($_irisAfm)?></span><button class="lpCp" onclick="lpCopy('<?=h($_irisAfm)?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
      <?php endif; ?>
      <div><div class="lpLbl">Αιτιολογία</div>
        <div class="lpR"><span><?=h($_refCode)?></span><button class="lpCp" onclick="lpCopy('<?=h($_refCode)?>',this)"><i class="fas fa-copy"></i></button></div>
      </div>
    </div>

    <!-- Email note -->
    <div style="font-size:.78rem;color:#fcd34d;background:rgba(240,165,0,.07);border:1px solid rgba(240,165,0,.18);border-radius:8px;padding:.55rem .75rem;margin-bottom:.75rem;line-height:1.5">
      <i class="fas fa-envelope"></i> Στείλτε αποδεικτικό στο <strong style="color:#fff"><?=h($_bankEmail)?></strong><br>
      <strong>Θέμα:</strong> <strong style="color:#fff"><?=h($_emailSubject)?></strong><br>
      <span style="color:#a78bfa">Αιτιολογία πληρωμής: <?=h($_refCode)?></span> · Ενεργοποίηση εντός 24ω.
    </div>

    <a href="mailto:<?=h($_bankEmail)?>?subject=<?=urlencode($_emailSubject)?>&body=<?=urlencode("Επισυνάπτω αποδεικτικό πληρωμής για αναβάθμιση Pro.\n\nΑιτιολογία: {$_refCode}\nΣύλλογος: {$_schoolName}")?>"
       style="display:block;width:100%;text-align:center;font-weight:800;font-size:.9rem;padding:.7rem 1rem;border-radius:10px;text-decoration:none;background:rgba(59,130,246,.15);color:#93c5fd;border:1.5px solid rgba(59,130,246,.35);margin-bottom:.5rem">
      <i class="fas fa-paper-plane"></i> Στείλε Αποδεικτικό μέσω Email
    </a>
    <button onclick="document.getElementById('lpOverlay').style.display='none'"
            style="display:block;width:100%;text-align:center;font-weight:600;font-size:.85rem;padding:.6rem 1rem;border-radius:10px;background:transparent;color:#8892b0;border:1px solid rgba(255,255,255,.1);cursor:pointer">
      Θα το κάνω αργότερα
    </button>
  </div>
</div>
</div>

<script>
function lpCopy(text,btn){
  navigator.clipboard.writeText(text).then(function(){
    var o=btn.innerHTML;
    btn.innerHTML='<i class="fas fa-check" style="color:#2dc653"></i>';
    setTimeout(function(){btn.innerHTML=o;},1500);
  });
}
</script>
<?php endif; ?>

<?php
if ($viewId && $athlete):
    $monthlyFee = floatval($athlete['monthly_fee']??0);
    $totalDebt  = $debtBalance;
    $debtStart  = getDebtStartDate($athlete);

    $isAdult = true;
    if (!empty($athlete['birthdate'])) {
        try {
            $isAdult = ((new DateTime())->diff(new DateTime($athlete['birthdate']))->y >= 18);
        } catch (Exception $e) {}
    }
?>
<div style="margin-bottom:.65rem">
  <a href="<?=APP_URL?>/pages/athletes.php" class="btn btn-ghost btn-sm" style="font-size:.88rem;color:var(--muted,#8892b0)"><i class="fa-solid fa-arrow-left"></i> Πίσω στη λίστα</a>
</div>

<div class="anim-1" style="margin-bottom:1rem">
  <div style="display:flex;align-items:center;gap:.85rem;margin-bottom:.75rem">
    <div class="profile-avatar" style="flex-shrink:0"><?=mb_strtoupper(mb_substr($athlete['full_name']??'?',0,1))?></div>
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.25rem">
        <span class="profile-name" style="margin:0;line-height:1.2"><?=h($athlete['full_name']??'')?></span>
        <?php if(!$athlete['active']): ?>
        <span style="background:rgba(107,116,148,.15);color:#6b7494;border:1px solid rgba(107,116,148,.3);border-radius:6px;font-size:.68rem;font-weight:800;padding:.1rem .45rem;white-space:nowrap"><i class="fa-solid fa-ban" style="font-size:.6rem;margin-right:.2rem"></i>Ανενεργός</span>
        <?php elseif($isCurrentlyPaused): ?>
        <span style="background:rgba(148,103,189,.15);color:#9467bd;border:1px solid rgba(148,103,189,.35);border-radius:6px;font-size:.68rem;font-weight:800;padding:.1rem .45rem;white-space:nowrap"><i class="fa-solid fa-pause" style="font-size:.6rem;margin-right:.2rem"></i>Παύση</span>
        <?php endif; ?>
      </div>
<div class="profile-meta" style="margin:0">
        <?php if($athlete['dept_name']): ?><span><i class="fa-solid fa-folder-open" style="opacity:.6"></i> <?=h($athlete['dept_name'])?></span><?php endif; ?>
        <?php if($athlete['registration_date']): ?><span><i class="fa-regular fa-calendar" style="opacity:.6"></i> Εγγραφή: <?=fmtD($athlete['registration_date'])?></span><?php endif; ?>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php if(!$athlete['active']): ?>
    <form method="POST" style="flex:1;min-width:140px">
      <input type="hidden" name="_action" value="reactivate_athlete">
      <input type="hidden" name="csrf_token" value="<?=csrf()?>">
      <input type="hidden" name="id" value="<?=$viewId?>">
      <button type="submit" class="btn btn-sm" style="width:100%;background:rgba(45,198,83,.12);color:#2dc653;border:1.5px solid rgba(45,198,83,.35);justify-content:center"><i class="fa-solid fa-user-check"></i> Επανενεργοποίηση</button>
    </form>
    <?php else: ?>
    <a href="?edit=<?=$viewId?>" class="btn btn-primary btn-sm" style="flex:1;min-width:120px;justify-content:center"><i class="fa-solid fa-pen-to-square"></i> Επεξεργασία</a>
    <button type="button" onclick="confirmDeactivateAthlete(<?=$viewId?>, '<?=addslashes(h($athlete['full_name']??''))?>')" class="btn btn-sm" style="flex:1;min-width:140px;justify-content:center;background:rgba(240,165,0,.1);color:#f0a500;border:1.5px solid rgba(240,165,0,.3)"><i class="fa-solid fa-user-slash"></i> Απενεργοποίηση</button>
    <?php endif; ?>
  </div>
</div>

<?php if($isCurrentlyPaused): ?>
<div class="debt-info-box is-paused anim-2">
  <div style="display:flex;align-items:center;gap:.65rem;flex-wrap:wrap">
    <div style="background:rgba(148,103,189,.15);border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-pause" style="color:#9467bd;font-size:1.2rem"></i></div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:800;color:#9467bd;font-size:1.05rem">⏸ Παύση — Δεν υπολογίζεται χρέος αυτή τη στιγμή</div>
      <div style="font-size:.82rem;color:#8892b0;margin-top:.15rem">Ο τρέχων μήνας εμπίπτει σε ενεργή περίοδο παύσης.</div>
    </div>
    <?php if($debtMonths > 0): ?>
    <div style="text-align:right;flex-shrink:0">
      <div style="font-size:.8rem;color:#8892b0">Υπάρχον χρέος εκτός παύσης</div>
      <div style="font-weight:800;color:#e63946"><?=$debtMonths?> μήνες<?=$monthlyFee>0?' · '.number_format($totalDebt,2,',','.').'€':''?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif($debtBalance<=0.009 && $examFeeDebtTotal<=0.009): ?>
<div class="debt-info-box ok anim-2">
  <div class="debt-ok-wrap" style="display:flex;align-items:center;gap:.65rem;flex-wrap:wrap">
    <div style="background:rgba(45,198,83,.15);border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="fa-solid fa-circle-check" style="color:#2dc653;font-size:1.2rem"></i>
    </div>

    <div class="debt-ok-text" style="flex:1;min-width:0">
      <div style="font-weight:800;color:#2dc653;font-size:1.05rem;line-height:1.4;word-break:break-word">
        ✓ Ενήμερος/η — Όλες οι πληρωμές εντάξει
      </div>
      <?php if($debtStart): ?>
        <div style="font-size:.82rem;color:#8892b0;margin-top:.15rem;line-height:1.45">
          Παρακολούθηση από: <?=fmtD($debtStart)?>
        </div>
      <?php endif; ?>
    </div>


  </div>
</div>
<?php else: ?>
<div class="debt-info-box has-debt anim-2">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.75rem">
    <div>
      <div style="display:flex;align-items:baseline;gap:.4rem;flex-wrap:wrap;margin-bottom:.3rem">
        <span class="debt-months-num"><?=$debtMonths?></span>
        <span class="debt-label-sm"><?=$debtMonths===1?'μήνας':'μήνες'?> χωρίς πληρωμή</span>
        <?php if($monthlyFee>0): ?><span style="color:#e63946;font-weight:700"> = <strong style="font-size:1.05rem"><?=number_format($totalDebt,2,',','.')?>€</strong> οφειλή</span><?php else: ?><span style="font-size:.8rem;color:#8892b0">(δεν έχει οριστεί μηνιαία χρέωση)</span><?php endif; ?>
      </div>
      <?php if($debtStart): ?><div style="font-size:.82rem;color:#8892b0;margin-bottom:.45rem"><i class="fa-regular fa-calendar" style="margin-right:.3rem"></i>Παρακολούθηση από: <?=fmtD($debtStart)?></div><?php endif; ?>
      <?php if($unpaidMonths): ?>
      <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.4rem">
      <?php foreach(array_slice($unpaidMonths,0,8) as $um): ?>
      <span class="month-chip unpaid"><?=h($um['label'])?> · <?=number_format($um['remaining'],2,',','.')?>€</span>
      <?php endforeach; ?>
      <?php if(count($unpaidMonths)>8): ?><span style="font-size:.78rem;color:#8892b0;align-self:center">+<?=count($unpaidMonths)-8?> ακόμα</span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <a href="<?=APP_URL?>/pages/subscriptions.php?action=add&athlete_id=<?=$viewId?>" class="btn btn-sm" style="background:#e63946;color:#fff;font-weight:700;flex-shrink:0"><i class="fa-solid fa-euro-sign"></i> Καταχώρηση Πληρωμής</a>
  </div>
</div>
<?php endif; ?>

<div class="profile-grid-2 anim-2">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-clipboard-list" style="color:#e63946"></i> Στοιχεία</div></div>
    <div style="padding:.85rem 1rem"><table class="info-table">
    <?php
    $dfmLabel='—';
    if($athlete['debt_from_month']??null) $dfmLabel=fmtD($athlete['debt_from_month'].'-01');
    elseif($athlete['registration_date']) $dfmLabel=fmtD($athlete['registration_date']).' (εγγραφή)';
    foreach([
      ['Πατρώνυμο',$athlete['father_name']??null],
      ['Ημ. Γέννησης',fmtD($athlete['birthdate']??null)],
      ['Εγγραφή',fmtD($athlete['registration_date']??null)],
      ['Χρέωση / μήνα',$monthlyFee>0?number_format($monthlyFee,2,',','.').'€':'—'],
      ['Οφειλή από',$dfmLabel],
    ] as [$l,$v]):
    ?>
      <tr><td style="width:38%"><?=h($l)?></td><td style="width:62%"><?=h($v??'—')?></td></tr>
    <?php endforeach; ?>
    </table></div>
  </div>
<div class="card">
  <div class="card-header"><div class="card-title"><i class="fa-solid fa-phone" style="color:#f0a500"></i> Επικοινωνία</div></div>
  <div style="padding:.85rem 1rem"><table class="info-table">
    <?php if ($isAdult): ?>
        <tr><td>Τηλ. Αθλητή</td><td><?=h($athlete['phone']??'—')?></td></tr>
        <tr><td>Email</td><td><?=h($athlete['email']??'—')?></td></tr>
    <?php else: ?>
        <tr><td>Τηλ. Γονέα</td><td><?=h($athlete['parent_phone']??'—')?></td></tr>
        <tr><td>Email Γονέα</td><td><?=h($athlete['parent_email']??'—')?></td></tr>
    <?php endif; ?>
  </table></div>
  <?php if (!$isAdult && !empty($athlete['parent_email'])): ?>
  <div style="padding:.5rem 1rem .85rem">
    <form method="post">
      <?=csrfField()?>
      <input type="hidden" name="_action" value="resend_parent_credentials">
      <input type="hidden" name="id" value="<?=$viewId?>">
      <button type="submit" class="btn btn-sm" style="background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid rgba(88,166,255,.3);width:100%;justify-content:center" onclick="return confirm('Θα σταλεί νέος κωδικός στο <?=h($athlete['parent_email'])?>. Συνέχεια;')">
        <i class="fa-solid fa-paper-plane"></i> Αποστολή Κωδικών Portal Γονέων
      </button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($isAdult && !empty($athlete['email'])):
    $hasPortal = (int)($athlete['athlete_portal_access'] ?? 0) === 1;
  ?>
  <div style="padding:.5rem 1rem .85rem;display:flex;flex-direction:column;gap:.5rem">
    <form method="post">
      <?=csrfField()?>
      <input type="hidden" name="_action" value="grant_athlete_portal">
      <input type="hidden" name="id" value="<?=$viewId?>">
      <button type="submit" class="btn btn-sm"
              style="background:rgba(230,57,70,.12);color:#ff8891;border:1px solid rgba(230,57,70,.35);width:100%;justify-content:center"
              onclick="return confirm('<?= $hasPortal ? 'Θα σταλεί νέος κωδικός Portal Αθλητή στο ' : 'Θα δοθεί πρόσβαση Portal Αθλητή και θα σταλεί κωδικός στο ' ?><?=h($athlete['email'])?>. Συνέχεια;')">
        <i class="fa-solid fa-user-shield"></i>
        <?= $hasPortal ? 'Επαναποστολή Κωδικών Portal Αθλητή' : 'Δώσε Πρόσβαση Portal Αθλητή' ?>
      </button>
    </form>
    <?php if ($hasPortal): ?>
    <form method="post" onsubmit="return confirm('Απενεργοποίηση πρόσβασης Portal Αθλητή;');">
      <?=csrfField()?>
      <input type="hidden" name="_action" value="revoke_athlete_portal">
      <input type="hidden" name="id" value="<?=$viewId?>">
      <button type="submit" class="btn btn-sm" style="background:rgba(255,255,255,.05);color:var(--muted,#8892b0);border:1px solid rgba(255,255,255,.1);width:100%;justify-content:center">
        <i class="fa-solid fa-ban"></i> Απενεργοποίηση Πρόσβασης
      </button>
    </form>
    <?php endif; ?>
    <div style="font-size:.72rem;color:#6b7494;line-height:1.5;text-align:center;padding:.3rem 0 0">
      <?php if ($hasPortal): ?>
        <i class="fa-solid fa-circle-check" style="color:#2dc653"></i>
        Έχει ενεργή πρόσβαση στο <a href="<?=APP_URL?>/parent/login.php" target="_blank" style="color:#8892b0;text-decoration:underline">Portal Αθλητή</a>.
      <?php else: ?>
        <i class="fa-solid fa-circle-info" style="color:#3b82f6"></i>
        Ο αθλητής θα λάβει email με κωδικό για το δικό του portal.
      <?php endif; ?>
    </div>
  </div>
  <?php elseif ($isAdult): ?>
  <div style="padding:.5rem 1rem .85rem;font-size:.72rem;color:#6b7494;line-height:1.5;text-align:center">
    <i class="fa-solid fa-envelope-open-text"></i>
    Καταχώρησε email αθλητή για να δώσεις πρόσβαση Portal Αθλητή.
  </div>
  <?php endif; ?>
</div>
</div>


<div class="card anim-4">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-euro-sign" style="color:#e63946"></i> Ιστορικό Πληρωμών Συνδρομών</div>
    <a href="<?=APP_URL?>/pages/subscriptions.php?action=add&athlete_id=<?=$viewId?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Νέα Πληρωμή Συνδρομής</a>
  </div>
  <?php if($athleteSubscriptions): ?>
  <div class="table-wrap"><table class="history-table">
<thead><tr><th>Περίοδος</th><th>Ποσό</th><th>Κατάσταση</th><th>Τρόπος</th><th>Ημ. Πληρωμής</th><th>Σημειώσεις</th><th style="width:180px">Ενέργεια</th></tr></thead>    <tbody>
    <?php
    $mLabels=['cash'=>'Μετρητά','card'=>'Κάρτα','deposit'=>'Κατάθεση'];
    $sLabels=['paid'=>'Πληρώθηκε','pending'=>'Εκκρεμεί','overdue'=>'Ληξιπρόθεσμη'];
    $sColors=['paid'=>'#2dc653','pending'=>'#f0a500','overdue'=>'#e63946'];
    foreach($athleteSubscriptions as $sub):
      $sc=$sColors[$sub['status']]??'#8892b0';
      $subJson=htmlspecialchars(json_encode($sub),ENT_QUOTES);
    ?>
     <tr style="cursor:pointer" onclick="openSubPreview(<?=$subJson?>)">
        <td><strong><?=fmtD($sub['valid_from'])?></strong><span style="color:var(--muted,#8892b0);font-size:.85em"> → </span><strong><?=fmtD($sub['valid_until'])?></strong></td>
        <td style="font-weight:700"><?=$sub['amount']>0?number_format($sub['amount'],2,',','.').'€':'—'?></td>
        <td><span style="background:<?=$sc?>22;color:<?=$sc?>;border:1px solid <?=$sc?>44;border-radius:20px;padding:.15rem .5rem;font-size:.78rem;font-weight:700"><?=$sLabels[$sub['status']]??h($sub['status'])?></span></td>
        <td><?=h($mLabels[$sub['payment_method']]??$sub['payment_method']??'—')?></td>
        <td><?=$sub['paid_at']?fmtD($sub['paid_at']):'—'?></td>
        <td style="color:var(--muted,#8892b0);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($sub['notes']??'')?></td>
<td onclick="event.stopPropagation()">
  <div class="history-action-buttons">
    <button type="button" onclick="openEditPayModal(<?=$subJson?>)"
      style="background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.25);border-radius:7px;padding:.38rem .65rem;cursor:pointer;font-size:.78rem;font-weight:700;display:inline-flex;align-items:center;gap:.35rem;min-height:34px"
      title="Επεξεργασία">
      <i class="fa-solid fa-pen-to-square"></i><span>Επεξεργασία</span>
    </button>
    <button type="button" onclick="openDelPayModal(<?=(int)$sub['id']?>, '<?=addslashes(fmtD($sub['valid_from']))?> → <?=addslashes(fmtD($sub['valid_until']))?>')"
      style="background:rgba(230,57,70,.1);color:#e63946;border:1px solid rgba(230,57,70,.25);border-radius:7px;padding:.38rem .65rem;cursor:pointer;font-size:.78rem;font-weight:700;display:inline-flex;align-items:center;gap:.35rem;min-height:34px"
      title="Διαγραφή">
      <i class="fa-solid fa-trash"></i><span>Διαγραφή</span>
    </button>
  </div>
</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
   </table></div>
  <?php else: ?>
  <div style="text-align:center;padding:1.5rem 1rem">
    <div style="font-size:1.8rem;margin-bottom:.5rem;opacity:.35"><i class="fa-solid fa-euro-sign"></i></div>
    <p style="color:var(--muted,#8892b0);margin:0 0 .75rem">Δεν υπάρχουν καταχωρημένες πληρωμές συνδρομών ακόμα</p>
    <a href="<?=APP_URL?>/pages/subscriptions.php?action=add&athlete_id=<?=$viewId?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Πρώτη Καταχώρηση</a>
  </div>
  <?php endif; ?>
</div>


<?php elseif($action==='add'||$editId): ?>
<?php if($action==='add'&&!$editId&&isAthleteLimit()): ?>
<div style="text-align:center;padding:3rem 1rem">
  <div style="font-size:3rem;margin-bottom:1rem">🏆</div>
  <h2 style="color:#f0a500;font-weight:800;margin-bottom:.75rem">Φτάσατε το Όριο Αθλητών</h2>
  <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-top:1.5rem">
    <a href="<?=APP_URL?>/pages/upgrade.php" class="btn btn-primary"><i class="fa-solid fa-star"></i> Αναβάθμιση σε Pro</a>
    <a href="<?=APP_URL?>/pages/athletes.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Πίσω</a>
  </div>
</div>
<?php else: ?>
<?php
$ea          = $editAthlete;
$formIsAdult = true;
if(!empty($ea['birthdate'])){ $fAge=(new DateTime($ea['birthdate']))->diff(new DateTime())->y; $formIsAdult=($fAge>=18); }
$storedDfm     = $ea['debt_from_month']??null;
$debtDateValue = $storedDfm ? $storedDfm.'-01' : ($ea['registration_date']??date('Y-m-d'));
?>
<div class="page-header anim-1">
  <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
    <a href="<?=APP_URL?>/pages/athletes.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Πίσω</a>
    <h2><?=$editId?'<i class="fa-solid fa-pen-to-square"></i> Επεξεργασία Αθλητή':'<i class="fa-solid fa-user-plus"></i> Νέος Αθλητής'?></h2>
  </div>
  <?php if(!$editId&&$nearLimit): ?>
  <div style="background:rgba(240,165,0,.12);border:1px solid rgba(240,165,0,.4);border-radius:10px;padding:.5rem 1rem;font-size:.88rem;color:#f0a500;font-weight:700">
    <i class="fa-solid fa-triangle-exclamation"></i> <?=$athleteCount?>/<?=$athleteLimit?> — πλησιάζετε το όριο!
  </div>
  <?php endif; ?>
</div>

<div class="card anim-2">
<form method="POST" id="athleteForm" novalidate>
  <div style="padding:1.25rem 1.1rem">
  <input type="hidden" name="_action" value="save_athlete">
  <input type="hidden" name="csrf_token" value="<?=csrf()?>">
  <input type="hidden" name="id" value="<?=$ea['id']??''?>">
  <?php if($ea): ?>
  <?php endif; ?>

  <div class="form-section-title"><i class="fa-solid fa-clipboard-list"></i> Βασικά Στοιχεία</div>
  <div class="form-row col-3" style="margin-bottom:1.25rem">
    <div><label class="form-label">Ονοματεπώνυμο <span style="color:#e63946">*</span></label><input name="full_name" class="form-control" value="<?=h($ea['full_name']??'')?>" required autofocus placeholder="π.χ. Νίκος Παπαδόπουλος" oninput="this.value=this.value.replace(/[0-9]/g,'')"></div>
    <div><label class="form-label">Πατρώνυμο</label><input name="father_name" class="form-control" value="<?=h($ea['father_name']??'')?>" placeholder="π.χ. Γιώργης" oninput="this.value=this.value.replace(/[0-9]/g,'')"></div>
    <div>
      <label class="form-label">Ημ. Γέννησης <span style="color:#e63946">*</span></label>
      <?php $bdDisplay=''; if(!empty($ea['birthdate'])&&$ea['birthdate']!=='0000-00-00'){try{$bdDisplay=(new DateTime($ea['birthdate']))->format('d/m/Y');}catch(Exception $e){}} ?>
      <input type="text" name="birthdate" class="form-control" value="<?=h($bdDisplay)?>" id="birthdateInput" required placeholder="π.χ. 15/06/1990" maxlength="10" inputmode="numeric" autocomplete="bday">
      <div class="form-hint"><i class="fa-solid fa-circle-info"></i> Γράψε ημέρα/μήνα/χρόνο — π.χ. <strong style="color:var(--text,#e2e8f0)">15/06/1990</strong></div>
    </div>
  </div>

  <?php
  $storedAthleteeFee = floatval($ea['monthly_fee'] ?? 0);
  $storedDeptId      = (int)($ea['department_id'] ?? 0);
  $storedDeptFee     = 0.0;
  if ($storedDeptId) {
      foreach ($departments as $dd) {
          if ((int)$dd['id'] === $storedDeptId) { $storedDeptFee = floatval($dd['monthly_fee']); break; }
      }
  }
  $hasCustomFee = ($storedDeptId > 0 && $storedAthleteeFee > 0 && abs($storedAthleteeFee - $storedDeptFee) > 0.001);
  ?>

  <div class="form-section-title" style="margin-top:.25rem"><i class="fa-solid fa-clock-rotate-left" style="color:#f0a500"></i> Παρακολούθηση Οφειλών</div>

  <?php
  $todayMonth      = date('Y-m-01');
  $nextMonthDate   = date('Y-m-01', strtotime('first day of next month'));
  $initialDebtDate = $debtDateValue ?: $todayMonth;
  $owesNowChecked  = (strtotime($initialDebtDate) <= strtotime($todayMonth));
  ?>

  <div style="margin-bottom:1rem">
    <label class="form-label" style="margin-bottom:.55rem">Κατάσταση Οφειλής</label>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <label style="display:flex;align-items:center;gap:.5rem;padding:.7rem .9rem;border-radius:12px;border:1px solid rgba(230,57,70,.25);background:rgba(230,57,70,.06);cursor:pointer;min-width:220px">
        <input type="radio" name="debt_mode" value="owes_now" id="debtModeOwesNow" <?=$owesNowChecked?'checked':''?> onchange="toggleDebtMode()">
        <span style="font-weight:800;color:#e63946"><i class="fa-solid fa-triangle-exclamation"></i> Χρωστάει ήδη</span>
      </label>
      <label style="display:flex;align-items:center;gap:.5rem;padding:.7rem .9rem;border-radius:12px;border:1px solid rgba(45,198,83,.25);background:rgba(45,198,83,.06);cursor:pointer;min-width:220px">
        <input type="radio" name="debt_mode" value="future_start" id="debtModeFuture" <?=!$owesNowChecked?'checked':''?> onchange="toggleDebtMode()">
        <span style="font-weight:800;color:#2dc653"><i class="fa-solid fa-circle-check"></i> Δεν χρωστάει ακόμη</span>
      </label>
    </div>
  </div>

  <div class="form-row col-2" style="margin-bottom:1.25rem">
    <div>
      <label class="form-label" id="debtStartLabel">
        <i class="fa-regular fa-calendar" style="color:#f0a500;margin-right:.35rem"></i>
        <?= $owesNowChecked ? 'Χρωστάει από' : 'Θα αρχίσει να χρωστάει από' ?>
      </label>
<?php
$debtMonthValue = '';
if (!empty($initialDebtDate)) {
  try { $debtMonthValue = (new DateTime($initialDebtDate))->format('Y-m'); } catch (Exception $e) {}
}
?>
<div style="position:relative;cursor:pointer" onclick="document.getElementById('debtStartInput').focus();try{document.getElementById('debtStartInput').showPicker();}catch(e){}">
  <input type="month" name="debt_from_month" class="form-control" id="debtStartInput"
    value="<?=h($debtMonthValue)?>"
    autocomplete="off"
    style="cursor:pointer;pointer-events:none"
    onchange="recalcDebtPreview();syncDebtHint();">
</div>
      <div class="form-hint" id="debtStartHint">
        <i class="fa-solid fa-circle-info"></i>
        <?= $owesNowChecked
          ? 'Βάλε τον πρώτο μήνα που έμεινε απλήρωτος.'
          : 'Βάλε τον μήνα από τον οποίο θα αρχίσει να υπολογίζεται πιθανή οφειλή.'
        ?>
      </div>
    </div>
    <div id="debtPreviewBox" style="background:rgba(255,255,255,.03);border:1px solid var(--border,#1e2536);border-radius:12px;padding:.85rem 1rem;display:flex;flex-direction:column;justify-content:center">
      <div style="font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--muted,#8892b0);margin-bottom:.45rem">
        <i class="fa-solid fa-calculator"></i> Εκτίμηση
      </div>
      <div id="debtPreviewContent" style="font-size:clamp(1rem,3vw,1.15rem);font-weight:800;color:var(--text,#e2e8f0)">— —</div>
      <div id="debtPreviewSub" style="font-size:.82rem;color:var(--muted,#8892b0);margin-top:.25rem">Χωρίς αφαίρεση ήδη καταχωρημένων πληρωμών.</div>
    </div>
  </div>

  <?php if(!empty($exemptMonths)): ?>
  <div class="exempt-info-box" style="margin-bottom:1.25rem">
    <span class="ei"><i class="fa-solid fa-calendar-xmark"></i></span>
    <div style="flex:1">
      <div style="font-size:.88rem;font-weight:800;color:#9467bd;margin-bottom:.3rem">Εξαιρεμένοι μήνες συλλόγου (δεν μετράνε για κανέναν)</div>
      <div style="display:flex;flex-wrap:wrap;gap:.3rem">
        <?php foreach($exemptMonths as $em=>$el):
          $ep=explode('-',$em);
        ?><span class="exempt-chip"><?=$greekMonths[(int)$ep[1]]?> <?=$ep[0]?><?=$el?" · $el":''?></span><?php endforeach; ?>
      </div>
      <div style="font-size:.78rem;color:var(--muted,#8892b0);margin-top:.35rem">Μπορείς να τους αλλάξεις από τις <a href="<?=APP_URL?>/pages/settings.php" style="color:#9467bd;text-decoration:none;font-weight:700">Ρυθμίσεις</a>.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="form-section-title"><i class="fa-solid fa-medal"></i> Αθλητικά Στοιχεία</div>

  <div class="form-row col-2" style="margin-bottom:.6rem">
    <div>
      <label class="form-label"><i class="fa-solid fa-folder-open" style="color:#f0a500;margin-right:.3rem"></i>Τμήμα</label>
      <?php $preselDept = (int)($ea['department_id'] ?? $_GET['dept_id'] ?? 0); ?>
      <select name="department_id" class="form-control" id="deptSelect">
        <option value="">— Χωρίς τμήμα —</option>
        <?php foreach($departments as $d): ?>
        <option value="<?=$d['id']?>"
                data-fee="<?= floatval($d['monthly_fee'] ?? 0) ?>"
                <?=$preselDept==(int)$d['id']?'selected':''?>>
          <?=h($d['name']??'')?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label" style="display:flex;align-items:center;gap:.45rem;flex-wrap:wrap">
        <i class="fa-solid fa-euro-sign" style="color:#2dc653;margin-right:.2rem"></i>Μηνιαία Συνδρομή Αθλητή <span style="font-size:.78rem;font-weight:500;color:var(--muted,#8892b0)">(ποσό πληρώνει κάθε μήνα)</span>
        <span id="feeSourceBadge" style="display:<?= ($storedDeptId>0 && !$hasCustomFee && $storedDeptFee>0)?'inline-flex':'none' ?>;font-size:.7rem;font-weight:800;padding:.12rem .45rem;border-radius:20px;background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.3);align-items:center;gap:.25rem">
          <i class="fa-solid fa-folder-open" style="font-size:.6rem"></i> από τμήμα
        </span>
        <span id="feeOverrideBadge" style="display:<?= $hasCustomFee?'inline-flex':'none' ?>;font-size:.7rem;font-weight:800;padding:.12rem .45rem;border-radius:20px;background:rgba(240,165,0,.12);color:#f0a500;border:1px solid rgba(240,165,0,.3);align-items:center;gap:.25rem">
          <i class="fa-solid fa-user-pen" style="font-size:.6rem"></i> ειδική τιμή
        </span>
      </label>
<input type="text" name="monthly_fee" class="form-control" id="monthlyFeeInput"
       value="<?=h($ea['monthly_fee']??'')?>"
       placeholder="π.χ. 40"
       maxlength="7"
       inputmode="decimal"
       autocomplete="off"
       oninput="this.value=this.value.replace(/[^0-9.,]/g,'');onFeeManualInput();recalcDebtPreview()">
      <input type="hidden" id="feeFromDept" value="<?= $storedDeptFee ?>">
    </div>
  </div>

  <div id="feeInfoBox" style="display:none;margin-bottom:1rem;border-radius:12px;padding:.75rem 1rem;font-size:.88rem;line-height:1.55"></div>

  <div id="customFeeToggleWrap" style="display:<?= ($storedDeptId>0 && $storedDeptFee>0)?'block':'none' ?>;margin-bottom:1.1rem">
    <label style="display:inline-flex;align-items:center;gap:.55rem;cursor:pointer;user-select:none;padding:.55rem .85rem;border-radius:10px;border:1px solid <?= $hasCustomFee?'rgba(240,165,0,.4)':'rgba(255,255,255,.08)' ?>;background:<?= $hasCustomFee?'rgba(240,165,0,.06)':'rgba(255,255,255,.02)' ?>;transition:all .18s" id="customFeeLabel">
      <input type="checkbox" id="customFeeCheck" <?= $hasCustomFee?'checked':'' ?> style="width:16px;height:16px;cursor:pointer;accent-color:#f0a500">
      <span style="font-size:.88rem;font-weight:700;color:<?= $hasCustomFee?'#f0a500':'var(--muted,#8892b0)' ?>" id="customFeeCheckLabel">
        <i class="fa-solid fa-user-pen"></i> Ορισμός ειδικής τιμής για αυτόν τον αθλητή
      </span>
    </label>
  </div>

  <div class="form-section-title"><i class="fa-solid fa-phone"></i> Επικοινωνία <span id="ageHintLabel" style="font-size:.8rem;font-weight:500;color:#8892b0;margin-left:.5rem"><?=$formIsAdult?'(ενήλικος)':'(ανήλικος — στοιχεία γονέα)'?></span></div>
  <div class="form-row col-3" style="margin-bottom:1.25rem">
    <div class="contact-adult" style="<?=!$formIsAdult?'display:none':''?>"><label class="form-label">Τηλέφωνο Αθλητή <span style="color:#e63946">*</span></label><input name="phone" class="form-control" value="<?=h($ea['phone']??'')?>" placeholder="69XXXXXXXX" <?=$formIsAdult?'required':''?> oninput="this.value=this.value.replace(/[^0-9+\-\s]/g,'')" inputmode="tel"></div>
    <div class="contact-minor" style="<?=$formIsAdult?'display:none':''?>"><label class="form-label">Τηλ. Γονέα/Κηδεμόνα <span style="color:#e63946">*</span></label><input name="parent_phone" class="form-control" value="<?=h($ea['parent_phone']??'')?>" placeholder="69XXXXXXXX" <?=!$formIsAdult?'required':''?> oninput="this.value=this.value.replace(/[^0-9+\-\s]/g,'')" inputmode="tel"></div>
    <div class="contact-adult" style="<?=!$formIsAdult?'display:none':''?>"><label class="form-label">Email <span style="color:#e63946">*</span></label><input type="email" name="email" class="form-control" value="<?=h($ea['email']??'')?>" <?=$formIsAdult?'required':''?>></div>
    <div class="contact-minor" style="<?=$formIsAdult?'display:none':''?>"><label class="form-label">Email Γονέα <span style="color:#e63946">*</span></label><input type="email" name="parent_email" class="form-control" value="<?=h($ea['parent_email']??'')?>" <?=!$formIsAdult?'required':''?>></div>
  </div>


  </div><!-- end padding div -->

  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση Αθλητή</button>
    <a href="<?=APP_URL?>/pages/athletes.php" class="btn btn-secondary"><i class="fa-solid fa-ban"></i> Ακύρωση</a>
  </div>
</form>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════
     LIST VIEW
     ════════════════════════════════════════════════════════ -->
<div class="stat-cards-row anim-1">
  <div class="stat-card card <?=$totalOwedAthletes>0?'clickable':''?>" <?=$totalOwedAthletes>0?'onclick="document.getElementById(\'debtSelect\').value=\'owed\';document.getElementById(\'filterForm\').submit()"':''?>>
    <div class="stat-icon <?=$totalOwedAthletes>0?'icon-red':'icon-green'?>"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="stat-text"><div class="stat-lbl">Με Οφειλές</div><div class="stat-val" style="color:<?=$totalOwedAthletes>0?'#e63946':'inherit'?>"><?=$totalOwedAthletes?></div></div>
  </div>
  <div class="stat-card card"><div class="stat-icon icon-gold"><i class="fa-solid fa-euro-sign"></i></div><div class="stat-text"><div class="stat-lbl">Συνολική Οφειλή</div><div class="stat-val" style="color:<?=$totalOwed>0?'#f0a500':'inherit'?>;font-size:clamp(1.1rem,3vw,1.6rem)!important"><?=$totalOwed>0?number_format($totalOwed,0,',','.').'€':'—'?></div></div></div>
</div>

<?php if(!empty($exemptMonths)): ?>
<div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.85rem;padding:.6rem 1rem;background:rgba(148,103,189,.06);border:1px solid rgba(148,103,189,.2);border-radius:12px">
  <i class="fa-solid fa-calendar-xmark" style="color:#9467bd;font-size:.9rem"></i>
  <span style="font-size:.83rem;color:#9467bd;font-weight:700">Εξαιρεμένοι μήνες (δεν μετράνε για κανέναν):</span>
  <?php foreach($exemptMonths as $em=>$el): $ep=explode('-',$em); ?>
  <span class="exempt-chip"><?=$greekMonths[(int)$ep[1]]?> <?=$ep[0]?><?=$el?" · $el":''?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="page-header anim-2">
  <h2><i class="fa-solid fa-person-running" style="color:#e63946"></i> Αθλητές</h2>
  <a href="?action=add" class="btn btn-primary btn-add-athlete"><i class="fa-solid fa-plus"></i> Νέος Αθλητής</a>
</div>

<form method="GET" id="filterForm" class="anim-2">
<div class="filters-bar">
<div class="search-bar"><span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span><input type="text" name="q" value="<?=h($search)?>" placeholder="Αναζήτηση ονόματος αθλητή..." id="searchInput" autocomplete="off" enterkeyhint="search"></div>

  <div class="filter-select-wrap" style="min-width:160px;flex:1;max-width:200px">
    <select name="debt" id="debtSelect" onchange="this.form.submit()">
      <option value="" <?=!$debtFilter?'selected':''?>>Όλοι</option>
      <option value="owed" <?=$debtFilter==='owed'?'selected':''?>>Με Οφειλή (<?=$totalOwedAthletes?>)</option>
      <option value="ok" <?=$debtFilter==='ok'?'selected':''?>>Ενήμεροι</option>
    </select>
  </div>


  <div class="filter-select-wrap" style="min-width:120px;flex:1;max-width:165px">
    <select name="dept" onchange="this.form.submit()"><option value="">Όλα τα τμήματα</option><?php foreach($departments as $d): ?><option value="<?=$d['id']?>" <?=$dept==$d['id']?'selected':''?>><?=h($d['name']??'')?></option><?php endforeach; ?></select>
  </div>

  <div class="filter-select-wrap" style="min-width:120px;flex:1;max-width:150px">
    <select name="status" onchange="this.form.submit()">
      <option value="active" <?=$status==='active'?'selected':''?>>Ενεργοί</option>
      <option value="" <?=$status===''?'selected':''?>>Όλοι</option>
      <option value="inactive" <?=$status==='inactive'?'selected':''?>>Ανενεργοί</option>
    </select>
  </div>

  <?php if($search||$dept||$debtFilter||$status): ?>
  <a href="<?=APP_URL?>/pages/athletes.php" class="btn btn-primary btn-sm" style="background:#e63946;color:#ffffff;border:none"><i class="fa-solid fa-xmark"></i> Καθαρισμός Φίλτρων</a>
  <?php endif; ?>
</div>
</form>

<div class="card p-0 anim-3">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;border-bottom:1px solid var(--border,#1e2536);flex-wrap:wrap;gap:.4rem">
    <span style="font-size:1rem;font-weight:800;color:#ffffff">
      <?php $cnt=count($athletes); echo $cnt.' '.($cnt===1?'αθλητής':'αθλητές').' συνολικά'; ?>
      <?php if($search||$dept||$debtFilter||$status): ?>
      <span style="font-size:.75rem;font-weight:500;color:var(--muted,#8892b0);margin-left:.3rem">(μετά από φιλτράρισμα)</span>
      <?php endif; ?>
    </span>
    <?php if($totalPages>1): ?>
      <span style="font-size:.88rem;color:var(--muted,#8892b0)">
        Σελίδα <?=$page?> / <?=$totalPages?>
      </span>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table>
    <thead>
      <tr><th>Ονοματεπώνυμο</th><th class="col-hide-mobile">Τμήμα</th><th class="phone-th">Τηλέφωνο</th><th>Κατάσταση</th><th>Οφειλές</th><th>Ενέργειες</th></tr>
    </thead>
    <tbody>
    <?php foreach($athletes as $a):
      $aDebt        = $a['_debt'];
      $aDebtBalance = floatval($a['_debt_balance'] ?? 0);
      $aFee         = floatval($a['monthly_fee']??0);
      $aIsPaused    = $a['_is_paused'];
      $phone        = $a['parent_phone'] ?? $a['phone'] ?? null;
    ?>
<tr class="athlete-row" onclick="window.location='?view=<?=$a['id']?>'">
  <td>
        <span class="athlete-name">
          <?=h($a['full_name']??'')?>
          <?php if($aIsPaused): ?><span style="background:rgba(148,103,189,.12);color:#9467bd;border:1px solid rgba(148,103,189,.25);border-radius:10px;font-size:.64rem;font-weight:800;padding:.1rem .38rem;margin-left:.3rem;vertical-align:middle">⏸</span><?php endif; ?>
        </span>
        <?php if($a['dept_name']): ?><span class="athlete-sub"><i class="fa-solid fa-folder-open" style="opacity:.5;font-size:.7em;margin-right:.2rem"></i><?=h($a['dept_name'])?></span><?php endif; ?>
        <div class="athlete-mobile-meta">

        </div>
      </td>
      <td class="col-hide-mobile" style="color:var(--muted,#8892b0)"><?=h($a['dept_name']??'—')?></td>

      <td class="phone-cell">
        <?php if($phone): ?>
        <a href="tel:<?=h(preg_replace('/\s+/','',$phone))?>" onclick="event.stopPropagation()" style="display:inline-flex;align-items:center;gap:.3rem;color:var(--text,#e2e8f0);text-decoration:none;font-size:clamp(.82rem,2.8vw,.9rem);font-weight:600;background:rgba(255,255,255,.04);border:1px solid var(--border,#1e2536);border-radius:8px;padding:.2rem .5rem;white-space:nowrap;">
          <i class="fa-solid fa-phone" style="color:#2dc653;font-size:.75rem"></i><?=h($phone)?>
        </a>
        <?php else: ?><span style="color:var(--muted,#8892b0)">—</span><?php endif; ?>
       </td>
      <td class="status-cell">
        <?php if(!$a['active']): ?>
          <span class="status-span" style="display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .6rem;border-radius:6px;font-size:.78rem;font-weight:800;background:rgba(107,116,148,.12);color:#6b7494;border:1.5px solid rgba(107,116,148,.25)">
            <i class="fa-solid fa-ban" style="font-size:.7rem"></i> Ανενεργός
          </span>
        <?php elseif($aIsPaused): ?>
          <span class="status-span" style="display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .6rem;border-radius:6px;font-size:.78rem;font-weight:800;background:rgba(148,103,189,.12);color:#9467bd;border:1.5px solid rgba(148,103,189,.3)">
            <i class="fa-solid fa-pause" style="font-size:.7rem"></i> Παύση
          </span>
        <?php else: ?>
          <span class="status-span" style="display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .6rem;border-radius:6px;font-size:.78rem;font-weight:800;background:rgba(45,198,83,.12);color:#2dc653;border:1.5px solid rgba(45,198,83,.3)">
            <i class="fa-solid fa-circle" style="font-size:.45rem"></i> Ενεργός
          </span>
        <?php endif; ?>
       </td>
      <td class="debt-cell">
        <?php
          $aExamDebt  = floatval($a['_exam_fee_debt'] ?? 0);
          $aTotalDebt = $aDebtBalance + $aExamDebt;
        ?>
        <?php if($aTotalDebt>0.009): ?>
          <div style="display:flex;flex-direction:column;gap:.2rem">
            <?php if($aDebtBalance>0.009): ?>
            <span class="debt-span" style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:6px;font-size:.78rem;font-weight:800;background:<?=$aDebt>=3?'rgba(230,57,70,.12)':'rgba(240,165,0,.1)'?>;color:<?=$aDebt>=3?'#e63946':'#f0a500'?>;border:1.5px solid <?=$aDebt>=3?'rgba(230,57,70,.3)':'rgba(240,165,0,.25)'?>">
              <i class="fa-solid fa-<?=$aDebt>=3?'triangle-exclamation':'clock'?>" style="font-size:.7rem"></i>
<?=$aDebt?> <?=$aDebt===1?'μήνας':'μήνες'?> <?=number_format($aDebtBalance,2,',','.')?>€            </span>
            <?php endif; ?>
            <?php if($aExamDebt>0.009): ?>
            <span class="debt-span" style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:6px;font-size:.78rem;font-weight:800;background:rgba(240,165,0,.1);color:#f0a500;border:1.5px solid rgba(240,165,0,.25)">
              <i class="fa-solid fa-ribbon" style="font-size:.7rem"></i> Εξέταση <?=number_format($aExamDebt,2,',','.')?>€
            </span>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <span class="debt-span" style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:700;color:#2dc653;opacity:.7"><i class="fa-solid fa-check" style="font-size:.7rem"></i> Καμία οφειλή</span>
        <?php endif; ?>
       </td>
      <td onclick="event.stopPropagation()">
        <div style="display:flex;gap:.3rem;align-items:center;flex-wrap:nowrap">
          <a href="?edit=<?=$a['id']?>" class="btn-action-text btn-edit-ath"><i class="fa-solid fa-pen-to-square"></i> <span>Επεξεργασία</span></a>
          <?php if($a['active']): ?>
          <button type="button" class="btn-action-text" style="background:rgba(240,165,0,.1);color:#f0a500;border:1px solid rgba(240,165,0,.25)" onclick="confirmDeactivateAthlete(<?=$a['id']?>, '<?=addslashes(h($a['full_name']??''))?>')"><i class="fa-solid fa-user-slash"></i> <span>Απενεργοποίηση</span></button>
          <?php else: ?>
          <button type="button" class="btn-action-text" style="background:rgba(45,198,83,.1);color:#2dc653;border:1px solid rgba(45,198,83,.25)" onclick="confirmReactivateAthlete(<?=$a['id']?>, '<?=addslashes(h($a['full_name']??''))?>')"><i class="fa-solid fa-user-check"></i> <span>Επανεν/ποίηση</span></button>
          <?php endif; ?>
          <button type="button" class="btn-action-text btn-del-ath" onclick="confirmDeleteAthlete(<?=$a['id']?>, '<?=addslashes(h($a['full_name']??''))?>')"><i class="fa-solid fa-trash"></i> <span>Διαγραφή</span></button>
        </div>
       </td>
     </tr>
    <?php endforeach; ?>
    <?php if(!$athletes): ?>
      <tr><td colspan="7"><div style="text-align:center;padding:2.5rem 1rem"><div style="font-size:2.5rem;margin-bottom:.65rem;opacity:.35"><i class="fa-solid fa-person-running"></i></div><p style="color:var(--muted,#8892b0);margin:0 0 .75rem"><?=$debtFilter==='owed'?'🎉 Κανένας αθλητής με οφειλές!':'Δεν βρέθηκαν αθλητές'?></p><?php if(!$search&&!$dept&&!$debtFilter): ?><a href="?action=add" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Προσθήκη Πρώτου Αθλητή</a><?php endif; ?></div></td></tr>
    <?php endif; ?>
    </tbody>
    </table>
  </div>

  <?php if($totalPages>1): ?>
  <div style="display:flex;align-items:center;justify-content:center;gap:.35rem;padding:1rem 1.25rem;border-top:1px solid var(--border,#1e2536);flex-wrap:wrap">
    <?php if($page>1): ?><a href="<?=pageUrl(1)?>" style="display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:44px;border-radius:10px;border:1px solid #1e2536;color:#8892b0;text-decoration:none" title="Πρώτη"><i class="fa-solid fa-angles-left"></i></a><a href="<?=pageUrl($page-1)?>" style="display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:44px;border-radius:10px;border:1px solid #1e2536;color:#8892b0;text-decoration:none"><i class="fa-solid fa-angle-left"></i></a><?php endif; ?>
    <?php for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?><a href="<?=pageUrl($i)?>" style="display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:44px;border-radius:10px;border:1px solid <?=$i===$page?'#e63946':'#1e2536'?>;background:<?=$i===$page?'rgba(230,57,70,.15)':'transparent'?>;color:<?=$i===$page?'#e63946':'#8892b0'?>;text-decoration:none;font-weight:<?=$i===$page?'800':'400'?>;font-size:1rem"><?=$i?></a><?php endfor; ?>
    <?php if($page<$totalPages): ?><a href="<?=pageUrl($page+1)?>" style="display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:44px;border-radius:10px;border:1px solid #1e2536;color:#8892b0;text-decoration:none"><i class="fa-solid fa-angle-right"></i></a><a href="<?=pageUrl($totalPages)?>" style="display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:44px;border-radius:10px;border:1px solid #1e2536;color:#8892b0;text-decoration:none" title="Τελευταία"><i class="fa-solid fa-angles-right"></i></a><?php endif; ?>
    <span style="font-size:.9rem;color:#6b7494;margin-left:.5rem">Σελίδα <strong style="color:#e2e8f0"><?=$page?></strong> / <?=$totalPages?> · <?=$totalAthletes?> αθλητές</span>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

</div></div></div>

<!-- DEACTIVATE MODAL -->
<div id="deactivateAthleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10500;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeDeactivateModal()">
<div style="background:var(--card-bg,#131929);border:1.5px solid rgba(240,165,0,.3);border-radius:22px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.6);animation:fadeUp .28s ease both">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border,#1e2536)">
    <div style="font-size:1.05rem;font-weight:800;color:#f0a500;display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-user-slash"></i> Απενεργοποίηση Αθλητή</div>
    <button onclick="closeDeactivateModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:34px;height:34px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div style="padding:1.25rem">
    <p style="margin:0 0 .75rem;font-size:.98rem">Να απενεργοποιηθεί ο αθλητής <strong id="deactivateAthleteName" style="color:var(--text,#e2e8f0)"></strong>;</p>
    <div style="background:rgba(240,165,0,.08);border:1px solid rgba(240,165,0,.25);border-radius:12px;padding:.75rem 1rem;font-size:.88rem;color:#d0d8f0;line-height:1.6">
      <i class="fa-solid fa-circle-info" style="color:#f0a500;margin-right:.4rem"></i>
      Ο αθλητής δεν θα εμφανίζεται στη λίστα αλλά <strong>τα δεδομένα και οι πληρωμές διατηρούνται</strong>.<br>
      Μπορείτε να τον επαναφέρετε οποτεδήποτε από το φίλτρο "Ανενεργοί".
    </div>
  </div>
  <div style="padding:.9rem 1.25rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.6rem;justify-content:flex-end">
    <button onclick="closeDeactivateModal()" style="min-height:38px;font-size:.9rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
    <form method="POST" style="display:inline">
      <input type="hidden" name="_action" value="deactivate_athlete">
      <input type="hidden" name="csrf_token" value="<?=csrf()?>">
      <input type="hidden" name="id" id="deactivateAthleteId" value="">
      <button type="submit" style="min-height:38px;font-size:.9rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:none;background:#f0a500;color:#0a0d16"><i class="fa-solid fa-user-slash"></i> Απενεργοποίηση</button>
    </form>
  </div>
</div></div>

<!-- REACTIVATE MODAL -->
<div id="reactivateAthleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10500;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeReactivateModal()">
<div style="background:var(--card-bg,#131929);border:1.5px solid rgba(45,198,83,.35);border-radius:22px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.6);animation:fadeUp .28s ease both">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border,#1e2536)">
    <div style="font-size:1.05rem;font-weight:800;color:#2dc653;display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-user-check"></i> Επανενεργοποίηση Αθλητή</div>
    <button onclick="closeReactivateModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:34px;height:34px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div style="padding:1.25rem">
    <p style="margin:0 0 .75rem;font-size:.98rem">Να επανενεργοποιηθεί ο αθλητής <strong id="reactivateAthleteName" style="color:var(--text,#e2e8f0)"></strong>;</p>
    <div style="background:rgba(45,198,83,.08);border:1px solid rgba(45,198,83,.25);border-radius:12px;padding:.75rem 1rem;font-size:.88rem;color:#d0f0d8;line-height:1.6">
      <i class="fa-solid fa-circle-info" style="color:#2dc653;margin-right:.4rem"></i>
      Ο αθλητής θα εμφανίζεται ξανά στη λίστα και θα υπολογίζεται το χρέος του.
    </div>
  </div>
  <div style="padding:.9rem 1.25rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.6rem;justify-content:flex-end">
    <button onclick="closeReactivateModal()" style="min-height:38px;font-size:.9rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
    <form method="POST" style="display:inline">
      <input type="hidden" name="_action" value="reactivate_athlete">
      <input type="hidden" name="csrf_token" value="<?=csrf()?>">
      <input type="hidden" name="id" id="reactivateAthleteId" value="">
      <button type="submit" style="min-height:38px;font-size:.9rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:none;background:#2dc653;color:#0a0d16"><i class="fa-solid fa-user-check"></i> Επανενεργοποίηση</button>
    </form>
  </div>
</div></div>

<!-- DELETE MODAL -->
<div id="deleteAthleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10500;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeDeleteAthleteModal()">
<div style="background:var(--card-bg,#131929);border:1px solid var(--border,#1e2536);border-radius:22px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.6);animation:fadeUp .28s ease both">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border,#1e2536)">
    <div style="font-size:1.1rem;font-weight:800;color:#e63946;display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-triangle-exclamation"></i> Οριστική Διαγραφή Αθλητή</div>
    <button onclick="closeDeleteAthleteModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:34px;height:34px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div style="padding:1.25rem">
    <p style="margin:0 0 .5rem;font-size:1rem">Να διαγραφεί <strong>οριστικά</strong> ο αθλητής <strong id="deleteAthleteName" style="color:var(--text,#e2e8f0)"></strong>;</p>
    <p style="margin:0;font-size:.88rem;color:#e63946;font-weight:700"><i class="fa-solid fa-exclamation-circle"></i> Θα διαγραφούν <u>όλα τα δεδομένα</u> (πληρωμές, ιστορικό, ζώνες). Δεν αναιρείται.</p>
  </div>
  <div style="padding:.9rem 1.25rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.6rem;justify-content:flex-end">
    <button onclick="closeDeleteAthleteModal()" style="min-height:38px;font-size:.95rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
    <form method="POST" style="display:inline">
      <input type="hidden" name="_action" value="delete_athlete">
      <input type="hidden" name="csrf_token" value="<?=csrf()?>">
      <input type="hidden" name="id" id="deleteAthleteId" value="">
      <button type="submit" style="min-height:38px;font-size:.95rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:none;background:#e63946;color:#fff"><i class="fa-solid fa-trash"></i> Οριστική Διαγραφή</button>
    </form>
  </div>
</div></div>

<!-- EDIT PAYMENT MODAL (subscriptions) -->
<div id="editPayModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(8px);z-index:10600;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeEditPayModal()">
<div style="background:var(--card-bg,#131929);border:1.5px solid rgba(59,130,246,.3);border-radius:22px;width:100%;max-width:480px;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.7);animation:fadeUp .28s ease both">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border,#1e2536);position:sticky;top:0;background:var(--card-bg,#131929);z-index:1;border-radius:22px 22px 0 0">
    <div style="font-size:1.05rem;font-weight:800;color:#3b82f6;display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-pen-to-square"></i> Επεξεργασία Πληρωμής</div>
    <button onclick="closeEditPayModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:34px;height:34px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
  </div>
 <form method="POST" id="editPayForm" style="padding:1.1rem 1.25rem">
  <input type="hidden" name="_action" value="edit_payment">
  <input type="hidden" name="csrf_token" value="<?=csrf()?>">
  <input type="hidden" name="id" id="epId">
    <div style="background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.18);border-radius:10px;padding:.6rem .9rem;margin-bottom:1rem;font-size:.88rem;color:#7baef5;display:flex;align-items:center;gap:.5rem">
      <i class="fa-solid fa-calendar-days"></i>
      <span>Περίοδος: <strong id="epPeriodLabel" style="color:#e2e8f0">—</strong></span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.85rem">
      <div>
        <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Ποσό (€)</label>
        <input type="number" step=".01" name="amount" id="epAmount" class="form-control" placeholder="0.00" min="0">
      </div>
      <div>
        <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Κατάσταση</label>
        <select name="status" id="epStatus" class="form-control">
          <option value="paid">✓ Πληρώθηκε</option>
          <option value="pending">⏳ Εκκρεμεί</option>
          <option value="overdue">⚠ Ληξιπρόθεσμη</option>
        </select>
      </div>
      <div>
        <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Τρόπος Πληρωμής</label>
        <select name="payment_method" id="epMethod" class="form-control">
          <option value="cash">💵 Μετρητά</option>
          <option value="card">💳 Κάρτα</option>
          <option value="deposit">🏦 Κατάθεση</option>
        </select>
      </div>
      <div>
        <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Ημερομηνία</label>
<input type="text" name="paid_at" id="epPaidAt" class="form-control js-date-input"
  placeholder="π.χ. 15/09/2025" maxlength="10" inputmode="numeric" autocomplete="off">      </div>
    </div>
    <div style="margin-bottom:1rem">
      <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Σημειώσεις</label>
      <textarea name="notes" id="epNotes" class="form-control" rows="2" style="min-height:70px;resize:vertical" placeholder="Προαιρετικά..."></textarea>
    </div>
    <div style="display:flex;gap:.6rem;justify-content:flex-end;padding-top:.75rem;border-top:1px solid var(--border,#1e2536)">
      <button type="button" onclick="closeEditPayModal()" style="min-height:40px;font-size:.95rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
      <button type="submit" style="min-height:40px;font-size:.95rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem 1.1rem;cursor:pointer;border:none;background:#3b82f6;color:#fff"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
    </div>
  </form>
</div>
</div>

<!-- DELETE PAYMENT MODAL (subscriptions) -->
<div id="delPayModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10600;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeDelPayModal()">
<div style="background:var(--card-bg,#131929);border:1px solid var(--border,#1e2536);border-radius:18px;width:100%;max-width:380px;box-shadow:0 24px 80px rgba(0,0,0,.6);animation:fadeUp .28s ease both">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.2rem;border-bottom:1px solid var(--border,#1e2536)">
    <div style="font-weight:800;color:#e63946;display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-trash"></i> Διαγραφή Πληρωμής</div>
    <button onclick="closeDelPayModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:32px;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div style="padding:1rem 1.2rem">
    <p style="margin:0 0 .35rem;font-size:.95rem;color:var(--text,#e2e8f0)">Να διαγραφεί η πληρωμή;</p>
    <p style="margin:0 0 .5rem;font-size:.87rem;color:#8892b0;font-weight:600" id="delPayLabel"></p>
    <p style="margin:0;font-size:.83rem;color:#e63946;font-weight:700"><i class="fa-solid fa-triangle-exclamation"></i> Η ενέργεια δεν αναιρείται — ο μήνας θα εμφανιστεί ξανά ως ανεξόφλητος.</p>
  </div>
  <div style="padding:.85rem 1.2rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.5rem;justify-content:flex-end">
    <button onclick="closeDelPayModal()" style="min-height:36px;font-size:.9rem;font-weight:700;padding:.4rem .85rem;border-radius:9px;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)">Ακύρωση</button>
  <form method="POST" id="delPayForm" style="display:inline">
  <input type="hidden" name="_action" value="delete_payment">
  <input type="hidden" name="csrf_token" value="<?=csrf()?>">
  <input type="hidden" name="id" id="delPayId">
      <button type="submit" style="min-height:36px;font-size:.9rem;font-weight:700;padding:.4rem .85rem;border-radius:9px;cursor:pointer;border:none;background:#e63946;color:#fff"><i class="fa-solid fa-trash"></i> Διαγραφή</button>
    </form>
  </div>
</div>
</div>

<!-- EDIT EXAM PAYMENT MODAL -->
<div id="editExamModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(8px);z-index:10600;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeEditExamModal()">
<div style="background:var(--card-bg,#131929);border:1.5px solid rgba(240,165,0,.3);border-radius:22px;width:100%;max-width:480px;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.7);animation:fadeUp .28s ease both">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border,#1e2536);position:sticky;top:0;background:var(--card-bg,#131929);z-index:1;border-radius:22px 22px 0 0">
    <div style="font-size:1.05rem;font-weight:800;color:#f0a500;display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-pen-to-square"></i> Επεξεργασία Τέλους Εξέτασης</div>
    <button onclick="closeEditExamModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:34px;height:34px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <form method="POST" style="padding:1.1rem 1.25rem">
    <input type="hidden" name="_action" value="edit_exam_payment">
    <input type="hidden" name="id" id="examId">
    <input type="hidden" name="athlete_id" id="examAthleteId">
    <div style="background:rgba(240,165,0,.07);border:1px solid rgba(240,165,0,.18);border-radius:10px;padding:.6rem .9rem;margin-bottom:1rem;font-size:.88rem;color:#f0a500;display:flex;align-items:center;gap:.5rem">
      <i class="fa-solid fa-ribbon"></i>
      <span>Περιγραφή: <strong id="examDescLabel" style="color:#e2e8f0">—</strong></span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.85rem">
      <div>
        <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Ποσό (€)</label>
        <input type="number" step=".01" name="amount" id="examAmount" class="form-control" placeholder="0.00" min="0">
      </div>
      <div>
        <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Τρόπος Πληρωμής</label>
        <select name="payment_method" id="examMethod" class="form-control">
          <option value="cash">💵 Μετρητά</option>
          <option value="card">💳 Κάρτα</option>
          <option value="deposit">🏦 Κατάθεση</option>
          <option value="other">Άλλο</option>
        </select>
      </div>
      <div>
        <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Ημερομηνία</label>
<input type="text" name="transaction_date" id="examDate" class="form-control js-date-input"
  placeholder="π.χ. 15/09/2025" maxlength="10" inputmode="numeric" autocomplete="off">      </div>
      <div>
        <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Κατάσταση</label>
        <select name="status" id="examStatus" class="form-control">
          <option value="paid">✓ Πληρώθηκε</option>
          <option value="pending">⏳ Εκκρεμεί</option>
        </select>
      </div>
    </div>
    <div style="margin-bottom:1rem">
      <label style="font-size:.9rem;font-weight:700;display:block;margin-bottom:.35rem">Σημειώσεις</label>
      <textarea name="notes" id="examNotes" class="form-control" rows="2" style="min-height:70px;resize:vertical" placeholder="Προαιρετικά..."></textarea>
    </div>
    <div style="display:flex;gap:.6rem;justify-content:flex-end;padding-top:.75rem;border-top:1px solid var(--border,#1e2536)">
      <button type="button" onclick="closeEditExamModal()" style="min-height:40px;font-size:.95rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem .9rem;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
      <button type="submit" style="min-height:40px;font-size:.95rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;padding:.45rem 1.1rem;cursor:pointer;border:none;background:#f0a500;color:#0a0d16"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
    </div>
  </form>
</div>
</div>

<!-- DELETE EXAM PAYMENT MODAL -->
<div id="delExamModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10600;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeDelExamModal()">
<div style="background:var(--card-bg,#131929);border:1px solid var(--border,#1e2536);border-radius:18px;width:100%;max-width:380px;box-shadow:0 24px 80px rgba(0,0,0,.6);animation:fadeUp .28s ease both">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.2rem;border-bottom:1px solid var(--border,#1e2536)">
    <div style="font-weight:800;color:#e63946;display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-trash"></i> Διαγραφή Τέλους Εξέτασης</div>
    <button onclick="closeDelExamModal()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:32px;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div style="padding:1rem 1.2rem">
    <p style="margin:0 0 .35rem;font-size:.95rem;color:var(--text,#e2e8f0)">Να διαγραφεί το τέλος εξέτασης <strong id="delExamLabel"></strong>;</p>
    <p style="margin:0;font-size:.83rem;color:#e63946;font-weight:700"><i class="fa-solid fa-triangle-exclamation"></i> Η ενέργεια δεν αναιρείται.</p>
  </div>
  <div style="padding:.85rem 1.2rem;border-top:1px solid var(--border,#1e2536);display:flex;gap:.5rem;justify-content:flex-end">
    <button onclick="closeDelExamModal()" style="min-height:36px;font-size:.9rem;font-weight:700;padding:.4rem .85rem;border-radius:9px;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)">Ακύρωση</button>
    <form method="POST" id="delExamForm" style="display:inline">
      <input type="hidden" name="_action" value="delete_exam_payment">
      <input type="hidden" name="id" id="delExamId">
      <input type="hidden" name="athlete_id" id="delExamAthleteId">
      <button type="submit" style="min-height:36px;font-size:.9rem;font-weight:700;padding:.4rem .85rem;border-radius:9px;cursor:pointer;border:none;background:#e63946;color:#fff"><i class="fa-solid fa-trash"></i> Διαγραφή</button>
    </form>
  </div>
</div>
</div>

<script>
// ── Sidebar ──
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

// ── Department fee UX ──
(function(){
    var deptSel   = document.getElementById('deptSelect');
    var feeInput  = document.getElementById('monthlyFeeInput');
    var fromDept  = document.getElementById('feeFromDept');
    var srcBadge  = document.getElementById('feeSourceBadge');
    var ovBadge   = document.getElementById('feeOverrideBadge');
    var infoBox   = document.getElementById('feeInfoBox');
    var toggleWrap= document.getElementById('customFeeToggleWrap');
    var checkBox  = document.getElementById('customFeeCheck');
    var checkLbl  = document.getElementById('customFeeCheckLabel');
    var checkLabel= document.getElementById('customFeeLabel');
    if (!deptSel || !feeInput) return;

    function getDeptFee(deptId) {
        if (!deptId) return 0;
        var opt = deptSel.querySelector('option[value="' + deptId + '"]');
        return opt ? (parseFloat(opt.dataset.fee) || 0) : 0;
    }

    function fmt(n){ return n.toFixed(2).replace('.',',') + '€'; }

    function applyState() {
        var deptId  = deptSel.value;
        var dFee    = getDeptFee(deptId);
        var custom  = checkBox && checkBox.checked;

        if (fromDept) fromDept.value = dFee;

        if (!deptId) {
            if (infoBox)    { infoBox.style.display = 'none'; }
            if (toggleWrap) { toggleWrap.style.display = 'none'; }
            if (srcBadge)   { srcBadge.style.display = 'none'; }
            if (ovBadge)    { ovBadge.style.display = 'none'; }
            feeInput.readOnly    = false;
            feeInput.style.opacity = '1';
            feeInput.placeholder = 'π.χ. 40';
            return;
        }

        if (dFee === 0) {
            if (infoBox) {
                infoBox.style.display = '';
                infoBox.style.background = 'rgba(59,130,246,.07)';
                infoBox.style.border = '1px solid rgba(59,130,246,.2)';
                infoBox.innerHTML = '<i class="fa-solid fa-circle-info" style="color:#3b82f6;margin-right:.45rem"></i>'
                    + '<span style="color:#8892b0">Το τμήμα δεν έχει ορισμένη τιμή. Συμπλήρωσε την τιμή για αυτόν τον αθλητή παρακάτω.</span>';
            }
            if (toggleWrap) { toggleWrap.style.display = 'none'; }
            if (srcBadge)   { srcBadge.style.display = 'none'; }
            if (ovBadge)    { ovBadge.style.display = 'none'; }
            feeInput.readOnly    = false;
            feeInput.style.opacity = '1';
            feeInput.placeholder = 'π.χ. 40';
            return;
        }

        if (toggleWrap) { toggleWrap.style.display = ''; }

        if (!custom) {
            feeInput.value       = dFee.toFixed(2);
            feeInput.readOnly    = true;
            feeInput.style.opacity = '.55';
            if (srcBadge) { srcBadge.style.display = 'inline-flex'; }
            if (ovBadge)  { ovBadge.style.display  = 'none'; }
            if (checkLbl) { checkLbl.style.color = 'var(--muted,#8892b0)'; }
            if (checkLabel) {
                checkLabel.style.borderColor = 'rgba(255,255,255,.08)';
                checkLabel.style.background  = 'rgba(255,255,255,.02)';
            }
            if (infoBox) {
                infoBox.style.display = '';
                infoBox.style.background = 'rgba(45,198,83,.07)';
                infoBox.style.border = '1px solid rgba(45,198,83,.2)';
                infoBox.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#2dc653;margin-right:.45rem"></i>'
                    + '<span style="color:#8892b0">Η συνδρομή είναι <strong style="color:#2dc653">' + fmt(dFee) + '</strong> βάσει τμήματος. '
                    + 'Τσεκάρετε παρακάτω αν θέλετε να ορίσετε διαφορετική τιμή για αυτόν τον αθλητή.</span>';
            }
        } else {
            feeInput.readOnly    = false;
            feeInput.style.opacity = '1';
            if (srcBadge) { srcBadge.style.display = 'none'; }
            if (ovBadge)  { ovBadge.style.display  = 'inline-flex'; }
            if (checkLbl) { checkLbl.style.color = '#f0a500'; }
            if (checkLabel) {
                checkLabel.style.borderColor = 'rgba(240,165,0,.4)';
                checkLabel.style.background  = 'rgba(240,165,0,.06)';
            }
            if (infoBox) {
                infoBox.style.display = '';
                infoBox.style.background = 'rgba(240,165,0,.07)';
                infoBox.style.border = '1px solid rgba(240,165,0,.2)';
                infoBox.innerHTML = '<i class="fa-solid fa-user-pen" style="color:#f0a500;margin-right:.45rem"></i>'
                    + '<span style="color:#8892b0">Ορίζετε ειδική τιμή για αυτόν τον αθλητή. Η τυπική τιμή τμήματος είναι <strong style="color:#e2e8f0">' + fmt(dFee) + '</strong>.</span>';
            }
            if (parseFloat(feeInput.value) === dFee || feeInput.value === '' || feeInput.value === dFee.toFixed(2)) {
                feeInput.value = '';
                feeInput.placeholder = 'Πληκτρολόγησε ειδική τιμή...';
            }
        }
        recalcDebtPreview();
    }

    if (checkBox) {
        checkBox.addEventListener('change', function() {
            applyState();
            if (checkBox.checked) { setTimeout(function(){ feeInput.focus(); feeInput.select && feeInput.select(); }, 50); }
        });
    }

    deptSel.addEventListener('change', function() {
        if (checkBox) checkBox.checked = false;
        applyState();
    });

    window.onFeeManualInput = function() { recalcDebtPreview(); };

    applyState();
})();
	
	(function(){
  var wrap = document.getElementById('debtStartInput') && document.getElementById('debtStartInput').parentNode;
  var input = document.getElementById('debtStartInput');
  if(!input || !wrap) return;

  // Re-enable pointer events for the input itself so keyboard still works
  input.style.pointerEvents = 'auto';

  // On any click anywhere in the wrapper, open the picker
  wrap.addEventListener('click', function(){
    input.focus();
    try { input.showPicker(); } catch(e) {}
  });

  // Forward change/input events properly
  input.addEventListener('change', function(){
    recalcDebtPreview();
    syncDebtHint();
  });
})();

function showValidationModal(msgs) {
  var existing = document.getElementById('validationModal');
  if (existing) existing.remove();

  var modal = document.createElement('div');
  modal.id = 'validationModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);z-index:99999;display:flex;align-items:center;justify-content:center;padding:1rem';
  modal.innerHTML = `
    <div style="background:#131929;border:2px solid rgba(230,57,70,.5);border-radius:22px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(230,57,70,.2);animation:fadeUp .25s ease both;overflow:hidden">
      <div style="background:rgba(230,57,70,.12);padding:1rem 1.25rem;display:flex;align-items:center;gap:.65rem;border-bottom:1px solid rgba(230,57,70,.2)">
        <div style="width:38px;height:38px;min-width:38px;border-radius:50%;background:rgba(230,57,70,.2);display:flex;align-items:center;justify-content:center">
          <i class="fa-solid fa-triangle-exclamation" style="color:#e63946;font-size:1.1rem"></i>
        </div>
        <div>
          <div style="font-size:1rem;font-weight:800;color:#e63946">Συμπληρώστε τα υποχρεωτικά πεδία</div>
          <div style="font-size:.8rem;color:#8892b0;margin-top:.1rem">Βρέθηκαν ${msgs.length} ${msgs.length===1?'σφάλμα':'σφάλματα'}</div>
        </div>
      </div>
      <div style="padding:1rem 1.25rem">
        <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:.5rem">
          ${msgs.map(function(m){ return `
            <li style="display:flex;align-items:flex-start;gap:.6rem;padding:.55rem .75rem;background:rgba(230,57,70,.06);border:1px solid rgba(230,57,70,.15);border-radius:10px">
              <i class="fa-solid fa-circle-xmark" style="color:#e63946;font-size:.85rem;margin-top:.15rem;flex-shrink:0"></i>
              <span style="font-size:.92rem;color:#e2e8f0;line-height:1.4">${m}</span>
            </li>`; }).join('')}
        </ul>
      </div>
      <div style="padding:.85rem 1.25rem;border-top:1px solid rgba(230,57,70,.15);display:flex;justify-content:flex-end">
        <button onclick="document.getElementById('validationModal').remove();document.body.style.overflow='';"
          style="background:#e63946;color:#fff;border:none;border-radius:12px;padding:.65rem 1.75rem;font-size:.97rem;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:.45rem;min-height:44px">
          <i class="fa-solid fa-check"></i> Εντάξει
        </button>
      </div>
    </div>
  `;
  modal.addEventListener('click', function(e){
    if (e.target === modal) { modal.remove(); document.body.style.overflow=''; }
  });
  document.body.appendChild(modal);
  document.body.style.overflow = 'hidden';
}

(function(){
  var form = document.getElementById('athleteForm');
  if (!form) return;

  function isVisible(el) {
    if (!el) return false;
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  }

  function todayAtMidnight(){
    var d = new Date();
    d.setHours(0,0,0,0);
    return d;
  }

  function parseBirthdate(v) {
    var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec((v || '').trim());
    if (!m) return null;
    var d = parseInt(m[1], 10);
    var mo = parseInt(m[2], 10);
    var y = parseInt(m[3], 10);
    var dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return null;
    return dt;
  }

  function validEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((v || '').trim());
  }

  function phoneDigits(v) {
    return (v || '').replace(/[^\d]/g, '');
  }

  function validGreekPhone(v) {
    var digits = phoneDigits(v);
    return digits.length >= 10 && digits.length <= 15;
  }

  function validFee(v) {
    if (v === '' || v === null || typeof v === 'undefined') return true;
    var n = parseFloat(String(v).replace(',', '.'));
    return !isNaN(n) && n >= 0 && n <= 9999;
  }

  function getDebtMode() {
    var owesNow = document.getElementById('debtModeOwesNow');
    return owesNow && owesNow.checked ? 'owes_now' : 'future_start';
  }

  function ensureFieldWrap(input) {
    if (!input) return null;
    var parent = input.parentNode;
    if (!parent) return null;

    if (parent.classList && parent.classList.contains('field-wrap')) return parent;

    var wrap = document.createElement('div');
    wrap.className = 'field-wrap has-icon';
    parent.insertBefore(wrap, input);
    wrap.appendChild(input);

    var errIcon = document.createElement('span');
    errIcon.className = 'field-status-icon error';
    errIcon.innerHTML = '<i class=\"fa-solid fa-circle-exclamation\"></i>';

    var okIcon = document.createElement('span');
    okIcon.className = 'field-status-icon ok';
    okIcon.innerHTML = '<i class=\"fa-solid fa-circle-check\"></i>';

    var err = document.createElement('div');
    err.className = 'inline-error';
    err.setAttribute('data-role', 'error');

    var ok = document.createElement('div');
    ok.className = 'inline-ok';
    ok.setAttribute('data-role', 'ok');
    ok.textContent = '✓ Έγκυρο';

    wrap.appendChild(errIcon);
    wrap.appendChild(okIcon);
    wrap.appendChild(err);
    wrap.appendChild(ok);

    return wrap;
  }

  function markNeutral(input) {
    if (!input) return;
    var wrap = ensureFieldWrap(input);
    input.classList.remove('is-invalid', 'is-valid');
    if (wrap) {
      wrap.classList.remove('is-invalid', 'is-valid');
      var err = wrap.querySelector('[data-role=\"error\"]');
      var ok  = wrap.querySelector('[data-role=\"ok\"]');
      if (err) {
        err.textContent = '';
        err.classList.remove('show');
      }
      if (ok) ok.classList.remove('show');
    }
  }

  function markInvalid(input, msg, shake) {
    if (!input) return;
    var wrap = ensureFieldWrap(input);
    input.classList.remove('is-valid');
    input.classList.add('is-invalid');
    if (wrap) {
      wrap.classList.remove('is-valid');
      wrap.classList.add('is-invalid');
      var err = wrap.querySelector('[data-role=\"error\"]');
      var ok  = wrap.querySelector('[data-role=\"ok\"]');
      if (err) {
        err.textContent = msg || '';
        err.classList.add('show');
      }
      if (ok) ok.classList.remove('show');
      if (shake) {
        input.classList.remove('field-shake');
        void input.offsetWidth;
        input.classList.add('field-shake');
        setTimeout(function(){ input.classList.remove('field-shake'); }, 250);
      }
    }
  }

  function markValid(input, msg) {
    if (!input) return;
    var wrap = ensureFieldWrap(input);
    input.classList.remove('is-invalid');
    input.classList.add('is-valid');
    if (wrap) {
      wrap.classList.remove('is-invalid');
      wrap.classList.add('is-valid');
      var err = wrap.querySelector('[data-role=\"error\"]');
      var ok  = wrap.querySelector('[data-role=\"ok\"]');
      if (err) {
        err.textContent = '';
        err.classList.remove('show');
      }
      if (ok) {
        ok.textContent = msg || '✓ Έγκυρο';
        ok.classList.add('show');
      }
    }
  }

  function validateNameField(input, required, label) {
    if (!input || !isVisible(input)) return true;
    var v = (input.value || '').trim();

    if (!v) {
      if (required) {
        markInvalid(input, 'Το πεδίο \"' + label + '\" είναι υποχρεωτικό.');
        return false;
      }
      markNeutral(input);
      return true;
    }

    if (/[0-9]/.test(v)) {
      markInvalid(input, 'Το πεδίο \"' + label + '\" δεν επιτρέπεται να περιέχει αριθμούς.');
      return false;
    }

    markValid(input);
    return true;
  }

  function validateBirthdateField(input) {
    if (!input || !isVisible(input)) return true;
    var v = (input.value || '').trim();

    if (!v) {
      markInvalid(input, 'Το πεδίο \"Ημ. Γέννησης\" είναι υποχρεωτικό.');
      return false;
    }

    if (!/^(\d{2})\/(\d{2})\/(\d{4})$/.test(v)) {
      markInvalid(input, 'Η ημερομηνία πρέπει να είναι σε μορφή ΗΗ/ΜΜ/ΕΕΕΕ.');
      return false;
    }

    var dt = parseBirthdate(v);
    if (!dt) {
      markInvalid(input, 'Μη έγκυρη ημερομηνία γέννησης.');
      return false;
    }

    var min = new Date(1900, 0, 1);
    var now = todayAtMidnight();
    if (dt < min) {
      markInvalid(input, 'Η χρονολογία πρέπει να είναι από το 1900 και μετά.');
      return false;
    }
    if (dt > now) {
      markInvalid(input, 'Η ημερομηνία γέννησης δεν μπορεί να είναι στο μέλλον.');
      return false;
    }

    markValid(input);
    return true;
  }

  function validatePhoneField(input, required, label) {
    if (!input || !isVisible(input)) return true;
    var v = (input.value || '').trim();

    if (!v) {
      if (required) {
        markInvalid(input, 'Το πεδίο \"' + label + '\" είναι υποχρεωτικό.');
        return false;
      }
      markNeutral(input);
      return true;
    }

    if (/[A-Za-zΑ-Ωα-ω]/.test(v)) {
      markInvalid(input, 'Το πεδίο \"' + label + '\" δεν επιτρέπεται να περιέχει γράμματα.');
      return false;
    }

    if (!validGreekPhone(v)) {
      markInvalid(input, 'Το πεδίο \"' + label + '\" πρέπει να έχει 10 έως 15 ψηφία.');
      return false;
    }

    markValid(input);
    return true;
  }

  function validateEmailField(input, required, label, liveMode) {
    if (!input || !isVisible(input)) return true;
    var v = (input.value || '').trim();

    if (!v) {
      if (required) {
        markInvalid(input, 'Το πεδίο \"' + label + '\" είναι υποχρεωτικό.');
        return false;
      }
      markNeutral(input);
      return true;
    }

    if (liveMode && v.length < 5) {
      markNeutral(input);
      return false;
    }

    if (!validEmail(v)) {
      markInvalid(input, 'Το πεδίο \"' + label + '\" δεν είναι έγκυρο email.');
      return false;
    }

    markValid(input);
    return true;
  }

  function validateFeeField(input) {
    if (!input || !isVisible(input)) return true;
    var v = (input.value || '').trim();

    if (!v) {
      markNeutral(input);
      return true;
    }

    if (!validFee(v)) {
      markInvalid(input, 'Η μηνιαία συνδρομή πρέπει να είναι από 0 έως 9999€.');
      return false;
    }

    markValid(input);
    return true;
  }

function validateDebtDateField(input, birthInput) {
  if (!input || !isVisible(input)) return true;
  var v = (input.value || '').trim();
  if (!v) {
    markNeutral(input);
    return true;
  }

  var debtDate = parseMonthInput(v);
  if (!debtDate) {
    markInvalid(input, 'Επέλεξε μήνα έναρξης οφειλής.');
    return false;
  }

  var mode = getDebtMode();
  var today = todayAtMidnight();
  var currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);
  var selectedMonth = new Date(debtDate.getFullYear(), debtDate.getMonth(), 1);

  if (mode === 'owes_now' && selectedMonth > currentMonth) {
    markInvalid(input, 'Για "Χρωστάει ήδη" δεν επιτρέπεται μελλοντικός μήνας.');
    return false;
  }

  if (mode === 'future_start' && selectedMonth < currentMonth) {
    markInvalid(input, 'Για "Δεν χρωστάει ακόμη" βάλε τρέχοντα ή μελλοντικό μήνα.');
    return false;
  }

  var bd = parseBirthdate((birthInput && birthInput.value) || '');
  if (bd) {
    var birthMonth = new Date(bd.getFullYear(), bd.getMonth(), 1);
    if (selectedMonth < birthMonth) {
      markInvalid(input, 'Η ημερομηνία οφειλής δεν μπορεί να είναι πριν από τη γέννηση.');
      return false;
    }
  }

  markValid(input);
  return true;
}

  var fullName     = form.querySelector('[name=\"full_name\"]');
  var fatherName   = form.querySelector('[name=\"father_name\"]');
  var birthdate    = document.getElementById('birthdateInput');
  var monthlyFee   = document.getElementById('monthlyFeeInput');
  var debtInput    = document.getElementById('debtStartInput');

  var adultPhone = form.querySelector('[name=\"phone\"]');
  var minorPhone = form.querySelector('[name=\"parent_phone\"]');
  var adultEmail = form.querySelector('[name=\"email\"]');
  var minorEmail = form.querySelector('[name=\"parent_email\"]');

  [fullName, fatherName, birthdate, monthlyFee, debtInput, adultPhone, minorPhone, adultEmail, minorEmail]
    .filter(Boolean)
    .forEach(function(el){ ensureFieldWrap(el); });

  [fullName, fatherName].forEach(function(input){
    if (!input) return;

    input.addEventListener('beforeinput', function(e){
      if (e.data && /[0-9]/.test(e.data)) {
        e.preventDefault();
        markInvalid(input, 'Δεν επιτρέπονται αριθμοί εδώ.', true);
      }
    });

    input.addEventListener('input', function(){
      var cleaned = (input.value || '').replace(/[0-9]/g, '');
      if (input.value !== cleaned) input.value = cleaned;
      validateNameField(input, input === fullName, input === fullName ? 'Ονοματεπώνυμο' : 'Πατρώνυμο');
    });

    input.addEventListener('blur', function(){
      validateNameField(input, input === fullName, input === fullName ? 'Ονοματεπώνυμο' : 'Πατρώνυμο');
    });
  });

  if (birthdate) {
    birthdate.addEventListener('input', function(){
      var v = (birthdate.value || '').replace(/[^0-9]/g, '').slice(0,8);
      var out = '';
      if (v.length > 0) out += v.slice(0,2);
      if (v.length >= 3) out += '/' + v.slice(2,4);
      if (v.length >= 5) out += '/' + v.slice(4,8);
      birthdate.value = out;
      if (v.length === 8) validateBirthdateField(birthdate);
      else markNeutral(birthdate);
      validateDebtDateField(debtInput, birthdate);
    });

    birthdate.addEventListener('blur', function(){
      validateBirthdateField(birthdate);
      validateDebtDateField(debtInput, birthdate);
    });
  }

  [adultPhone, minorPhone].forEach(function(input){
    if (!input) return;

    input.addEventListener('beforeinput', function(e){
      if (e.data && /[A-Za-zΑ-Ωα-ω]/.test(e.data)) {
        e.preventDefault();
        markInvalid(input, 'Δεν επιτρέπονται γράμματα στο τηλέφωνο.', true);
      }
    });

    input.addEventListener('input', function(){
      var cleaned = (input.value || '').replace(/[^0-9+\\-\\s]/g, '');
      if (input.value !== cleaned) input.value = cleaned;
      var label = input === adultPhone ? 'Τηλέφωνο Αθλητή' : 'Τηλ. Γονέα/Κηδεμόνα';
      validatePhoneField(input, input.required, label);
    });

    input.addEventListener('blur', function(){
      var label = input === adultPhone ? 'Τηλέφωνο Αθλητή' : 'Τηλ. Γονέα/Κηδεμόνα';
      validatePhoneField(input, input.required, label);
    });
  });

  [adultEmail, minorEmail].forEach(function(input){
    if (!input) return;

    input.addEventListener('input', function(){
      var label = input === adultEmail ? 'Email' : 'Email Γονέα';
      validateEmailField(input, input.required, label, true);
    });

    input.addEventListener('blur', function(){
      var label = input === adultEmail ? 'Email' : 'Email Γονέα';
      validateEmailField(input, input.required, label, false);
    });
  });

  if (monthlyFee) {
    monthlyFee.addEventListener('input', function(){
      validateFeeField(monthlyFee);
    });
    monthlyFee.addEventListener('blur', function(){
      validateFeeField(monthlyFee);
    });
  }

  if (debtInput) {
    debtInput.addEventListener('change', function(){
      validateDebtDateField(debtInput, birthdate);
    });
    debtInput.addEventListener('blur', function(){
      validateDebtDateField(debtInput, birthdate);
    });
  }

  var debtModeNow = document.getElementById('debtModeOwesNow');
  var debtModeFuture = document.getElementById('debtModeFuture');
  [debtModeNow, debtModeFuture].forEach(function(radio){
    if (!radio) return;
    radio.addEventListener('change', function(){
      validateDebtDateField(debtInput, birthdate);
    });
  });

  form.addEventListener('submit', function(e){
    var errors = [];

    if (!validateNameField(fullName, true, 'Ονοματεπώνυμο')) {
      errors.push({ field: fullName, msg: 'Το πεδίο \"Ονοματεπώνυμο\" είναι υποχρεωτικό και χωρίς αριθμούς.' });
    }

    if (!validateNameField(fatherName, false, 'Πατρώνυμο')) {
      errors.push({ field: fatherName, msg: 'Το \"Πατρώνυμο\" δεν επιτρέπεται να περιέχει αριθμούς.' });
    }

    if (!validateBirthdateField(birthdate)) {
      errors.push({ field: birthdate, msg: 'Η \"Ημ. Γέννησης\" πρέπει να είναι έγκυρη και από 1900 έως σήμερα.' });
    }

    var adultVisible = isVisible(adultPhone) || isVisible(adultEmail);
    var phoneField = adultVisible ? adultPhone : minorPhone;
    var emailField = adultVisible ? adultEmail : minorEmail;

    if (!validatePhoneField(phoneField, true, adultVisible ? 'Τηλέφωνο Αθλητή' : 'Τηλ. Γονέα/Κηδεμόνα')) {
      errors.push({ field: phoneField, msg: 'Το τηλέφωνο πρέπει να έχει 10 έως 15 ψηφία και χωρίς γράμματα.' });
    }

    if (!validateEmailField(emailField, true, adultVisible ? 'Email' : 'Email Γονέα', false)) {
      errors.push({ field: emailField, msg: 'Το email δεν είναι έγκυρο.' });
    }

    if (!validateFeeField(monthlyFee)) {
      errors.push({ field: monthlyFee, msg: 'Η μηνιαία συνδρομή πρέπει να είναι θετική ή μηδενική, έως 9999€.' });
    }

    if (!validateDebtDateField(debtInput, birthdate)) {
      errors.push({ field: debtInput, msg: 'Η ημερομηνία οφειλής δεν συμφωνεί με την κατάσταση οφειλής ή τη γέννηση.' });
    }

    if (errors.length > 0) {
      e.preventDefault();
      if (errors[0].field) {
        markInvalid(errors[0].field, (errors[0].msg || '').replace(/^[^—-]*?/, errors[0].field === birthdate ? 'Έλεγξε την ημερομηνία.' : errors[0].msg), true);
        setTimeout(function(){ errors[0].field.focus(); }, 80);
      }
      showValidationModal(errors.map(function(x){ return x.msg; }));
      return false;
    }
  });
})();

(function(){
  var bd=document.getElementById('birthdateInput');
  if(!bd)return;
  function getAge(v){
    if(!v)return null;
    var b;
    var dm=/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(v);
    if(dm){ b=new Date(parseInt(dm[3]),parseInt(dm[2])-1,parseInt(dm[1])); }
    else { b=new Date(v); }
    if(isNaN(b.getTime()))return null;
    var t=new Date(),a=t.getFullYear()-b.getFullYear(),m=t.getMonth()-b.getMonth();
    if(m<0||(m===0&&t.getDate()<b.getDate()))a--;
    return a;
  }
  bd.addEventListener('input',function(){
    var v=this.value.replace(/[^0-9]/g,'');
    var out='';
    if(v.length>0){
      var day=v.substring(0,2);
      var d=parseInt(day,10);
      if(v.length>=2 && (d<1||d>31)) day=d<1?'01':'31';
      out+=day;
    }
    if(v.length>=3){
      var mon=v.substring(2,4);
      var m=parseInt(mon,10);
      if(v.length>=4 && (m<1||m>12)) mon=m<1?'01':'12';
      out+='/'+mon;
    }
    if(v.length>=5)out+='/'+v.substring(4,8);
    this.value=out;
  });
  function apply(age){
    if(age===null)return;
    var adult=age>=18;
    var hint=document.getElementById('ageHintLabel');
    if(hint)hint.textContent=adult?'(ενήλικος)':'(ανήλικος — στοιχεία γονέα)';
    document.querySelectorAll('.contact-adult').forEach(function(el){el.style.display=adult?'':'none';});
    document.querySelectorAll('.contact-minor').forEach(function(el){el.style.display=adult?'none':'';});
    document.querySelectorAll('.contact-adult input').forEach(function(el){el.required=adult;});
    document.querySelectorAll('.contact-minor input').forEach(function(el){el.required=!adult;});
  }
  bd.addEventListener('change',function(){apply(getAge(this.value));});
  apply(getAge(bd.value));
})();

function syncDebtHint(){
  var owesNow = document.getElementById('debtModeOwesNow') && document.getElementById('debtModeOwesNow').checked;
  var lbl  = document.getElementById('debtStartLabel');
  var hint = document.getElementById('debtStartHint');
  if(lbl)  lbl.innerHTML  = '<i class="fa-regular fa-calendar" style="color:#f0a500;margin-right:.35rem"></i>' + (owesNow ? 'Χρωστάει από' : 'Θα αρχίσει να χρωστάει από');
  if(hint) hint.innerHTML = '<i class="fa-solid fa-circle-info"></i> ' + (owesNow ? 'Βάλε τον πρώτο μήνα που έμεινε απλήρωτος.' : 'Βάλε τον μήνα από τον οποίο θα αρχίσει να υπολογίζεται πιθανή οφειλή.');
}

function parseMonthInput(v){
  var m = /^(\d{4})-(\d{2})$/.exec((v || '').trim());
  if(!m) return null;
  var y = parseInt(m[1],10), mo = parseInt(m[2],10);
  if(mo < 1 || mo > 12) return null;
  return new Date(y, mo-1, 1);
}
function toMonthValue(dt){
  if(!dt || isNaN(dt.getTime())) return '';
  return dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0');
}
function parseDisplayDate(v){
  var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec((v || '').trim());
  if(!m) return null;
  var d = parseInt(m[1],10), mo = parseInt(m[2],10), y = parseInt(m[3],10);
  var dt = new Date(y, mo-1, d);
  if(dt.getFullYear() !== y || dt.getMonth() !== mo-1 || dt.getDate() !== d) return null;
  return dt;
}
function toDisplayDate(dt){
  if(!dt || isNaN(dt.getTime())) return '';
  return String(dt.getDate()).padStart(2,'0') + '/' + String(dt.getMonth()+1).padStart(2,'0') + '/' + dt.getFullYear();
}
function toIsoDate(dt){
  if(!dt || isNaN(dt.getTime())) return '';
  return dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0');
}
function maskDateInput(el){
  if(!el) return;
  var v = (el.value || '').replace(/[^0-9]/g,'').slice(0,8);
  var out = '';
  if(v.length > 0) out += v.slice(0,2);
  if(v.length >= 3) out += '/' + v.slice(2,4);
  if(v.length >= 5) out += '/' + v.slice(4,8);
  el.value = out;
}
function toggleDebtMode(){
  var owesNowRadio = document.getElementById('debtModeOwesNow');
  var futureRadio  = document.getElementById('debtModeFuture');
  var input        = document.getElementById('debtStartInput');
  var hidden       = document.getElementById('debtModeHidden');
  if(!input || !owesNowRadio || !futureRadio) return;

  var today = new Date(); today.setDate(1); today.setHours(0,0,0,0);
  var nextMonth = new Date(today.getFullYear(), today.getMonth()+1, 1);
  var parsed = parseMonthInput(input.value);

  if(futureRadio.checked){
    if(!parsed || parsed <= today) input.value = toMonthValue(nextMonth);
    if(hidden) hidden.value = 'future_start';
  }
  if(owesNowRadio.checked){
    if(!parsed || parsed > today) input.value = toMonthValue(today);
    if(hidden) hidden.value = 'owes_now';
  }
  syncDebtHint();
  recalcDebtPreview();
}

function recalcDebtPreview(){
  var startVal = document.getElementById('debtStartInput') && document.getElementById('debtStartInput').value;
  var feeRaw   = document.getElementById('monthlyFeeInput') && document.getElementById('monthlyFeeInput').value;
  var fee      = parseFloat(String(feeRaw || '').replace(',', '.')) || 0;
  var content  = document.getElementById('debtPreviewContent');
  var sub      = document.getElementById('debtPreviewSub');
  var owesNow  = document.getElementById('debtModeOwesNow') && document.getElementById('debtModeOwesNow').checked;
  if(!content) return;

  if(!startVal){
    content.innerHTML='<span style="color:var(--muted,#8892b0)">— Εισάγετε ημερομηνία —</span>';
    if(sub) sub.textContent='';
    return;
  }

  var parsed = parseMonthInput(startVal);
  if(!parsed){
    content.innerHTML='<span style="color:#f0a500"><i class="fa-solid fa-triangle-exclamation"></i> Μη έγκυρη ημερομηνία</span>';
    if(sub) sub.textContent='Επέλεξε μήνα από το πεδίο.';
    return;
  }

  var start = new Date(parsed.getFullYear(), parsed.getMonth(), 1);
  var now  = new Date(); now.setDate(1); now.setHours(0,0,0,0);

  var gm = ['','Ιαν','Φεβ','Μαρ','Απρ','Μαι','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];
  var fromLabel = gm[start.getMonth()+1] + ' ' + start.getFullYear();

  if(!owesNow){
    content.innerHTML = '<span style="color:#2dc653"><i class="fa-solid fa-circle-check"></i> Δεν χρωστάει ακόμη</span>';
    if(sub) sub.innerHTML = 'Η πιθανή οφειλή θα αρχίσει από <strong style="color:#e2e8f0">' + fromLabel + '</strong>.';
    return;
  }

  if(start > now){
    content.innerHTML='<span style="color:#f0a500"><i class="fa-solid fa-triangle-exclamation"></i> Η ημερομηνία είναι στο μέλλον</span>';
    if(sub) sub.textContent='Για "χρωστάει ήδη", βάλε τρέχοντα ή παλαιότερο μήνα.';
    return;
  }

  var months = (now.getFullYear()-start.getFullYear())*12 + (now.getMonth()-start.getMonth()) + 1;
  var euro = fee > 0 ? ' = <strong style="color:#e63946">' + (months*fee).toLocaleString('el-GR',{minimumFractionDigits:2,maximumFractionDigits:2}) + '€</strong>' : '';
  var mLabel = months === 1 ? '1 μήνας' : months + ' μήνες';

  content.innerHTML = '<span style="color:#e63946"><i class="fa-solid fa-triangle-exclamation"></i> ' + mLabel + '</span>' + euro;
  if(sub) sub.innerHTML = 'Χρέος από <strong style="color:#e2e8f0">' + fromLabel + '</strong> (εκτίμηση χωρίς πληρωμές)';
}

document.getElementById('debtStartInput') && document.getElementById('debtStartInput').addEventListener('change', function(){ syncDebtHint(); recalcDebtPreview(); });
toggleDebtMode();
syncDebtHint();
recalcDebtPreview();

function togglePauseForm(){var f=document.getElementById('pauseAddForm');if(f)f.style.display=f.style.display==='none'?'':'none';}

(function(){
  var input = document.getElementById('searchInput');
  if(!input) return;
  var table = document.querySelector('.table-wrap table');
  if(!table) return;
  var tbody = table.querySelector('tbody');
  if(!tbody) return;
  var rows = Array.from(tbody.querySelectorAll('tr.athlete-row'));
  if(!rows.length) return;
  var summaryText = document.querySelector('.card.p-0.anim-3 > div span');
  var emptyRow = null;
  function createEmptyRow(){
    var tr = document.createElement('tr');
    tr.id = 'liveSearchEmptyRow';
    tr.style.display = 'none';
    tr.innerHTML = '<td colspan="7"><div style="text-align:center;padding:2.5rem 1rem"><div style="font-size:2.5rem;margin-bottom:.65rem;opacity:.35"><i class="fa-solid fa-magnifying-glass"></i></div><p style="color:var(--muted,#8892b0);margin:0">Δεν βρέθηκαν αθλητές</p></div></td>';
    tbody.appendChild(tr);
    return tr;
  }
  function filterRows(){
    var currentRows = Array.from(tbody.querySelectorAll('tr.athlete-row'));
    var rawQ = input.value;
    var q = rawQ.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    var visible = 0;
    currentRows.forEach(function(row){
      var nameEl = row.querySelector('.athlete-name');
      var rawName = (nameEl ? nameEl.textContent : row.textContent);
      var name = rawName.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      var match = !q || name.indexOf(q) !== -1;
      row.style.display = match ? '' : 'none';
      if(match) visible++;
    });
    if(!emptyRow) emptyRow = document.getElementById('liveSearchEmptyRow') || createEmptyRow();
    emptyRow.style.display = visible === 0 ? '' : 'none';
    if(summaryText){ summaryText.textContent = visible + ' αθλητές συνολικά'; }
  }
  input.addEventListener('input', filterRows);
  input.addEventListener('compositionend', filterRows);
  input.addEventListener('keydown', function(e){
    if(e.key === 'Enter'){
      e.preventDefault();
      input.blur();
      filterRows();
    }
  });
  input.addEventListener('search', function(){
    input.blur();
    filterRows();
  });
  input.addEventListener('change', filterRows);
  filterRows();
})();

function confirmDeleteAthlete(id,name){
  document.getElementById('deleteAthleteId').value=id;
  document.getElementById('deleteAthleteName').textContent=name;
  document.getElementById('deleteAthleteModal').style.display='flex';
  document.body.style.overflow='hidden';
}
function closeDeleteAthleteModal(){document.getElementById('deleteAthleteModal').style.display='none';document.body.style.overflow='';}
function confirmDeactivateAthlete(id,name){
  document.getElementById('deactivateAthleteId').value=id;
  document.getElementById('deactivateAthleteName').textContent=name;
  document.getElementById('deactivateAthleteModal').style.display='flex';
  document.body.style.overflow='hidden';
}
function closeDeactivateModal(){document.getElementById('deactivateAthleteModal').style.display='none';document.body.style.overflow='';}
function confirmReactivateAthlete(id,name){
  document.getElementById('reactivateAthleteId').value=id;
  document.getElementById('reactivateAthleteName').textContent=name;
  document.getElementById('reactivateAthleteModal').style.display='flex';
  document.body.style.overflow='hidden';
}
function closeReactivateModal(){document.getElementById('reactivateAthleteModal').style.display='none';document.body.style.overflow='';}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeDeleteAthleteModal();closeDeactivateModal();closeEditPayModal();closeDelPayModal();closeEditExamModal();closeDelExamModal();closeSubPreview();closeExamPreview();}});

<?php if(isAthleteLimit()): ?>
document.querySelectorAll('a[href*="action=add"]').forEach(function(btn){
  btn.addEventListener('click',function(e){
    e.preventDefault();
    var ov=document.getElementById('lpOverlay');
    if(ov){ov.style.display='flex';document.body.style.overflow='hidden';}
  });
});
<?php endif; ?>

function openEditPayModal(sub){
  document.getElementById('epId').value      = sub.id;
  document.getElementById('epAmount').value  = sub.amount > 0 ? parseFloat(sub.amount).toFixed(2) : '';
  document.getElementById('epStatus').value  = sub.status  || 'paid';
  document.getElementById('epMethod').value  = sub.payment_method || 'cash';
  document.getElementById('epPaidAt').value = (function(v){
    if(!v) return '';
    var p = v.split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : v;
  })(sub.paid_at || '');
  document.getElementById('epNotes').value   = sub.notes   || '';
  document.getElementById('epPeriodLabel').textContent =
    (sub.valid_from||'').substring(0,7).split('-').reverse().join('/') + ' → ' +
    (sub.valid_until||'').substring(0,7).split('-').reverse().join('/');
var athId = new URLSearchParams(window.location.search).get('view') || '';
  var epRedir = document.getElementById('epRedirect');
  if(epRedir) epRedir.value = athId
    ? '<?=APP_URL?>/pages/athletes.php?view=' + athId
    : '<?=APP_URL?>/pages/subscriptions.php';
  document.getElementById('editPayModal').style.display='flex';
  document.body.style.overflow='hidden';
}
function closeEditPayModal(){document.getElementById('editPayModal').style.display='none';document.body.style.overflow='';}

function openDelPayModal(id,label){
  document.getElementById('delPayId').value  = id;
  document.getElementById('delPayLabel').textContent = label||'';
var athId = new URLSearchParams(window.location.search).get('view') || '';
  var dpRedir = document.getElementById('delPayRedirect');
  if(dpRedir) dpRedir.value = athId
    ? '<?=APP_URL?>/pages/athletes.php?view=' + athId
    : '<?=APP_URL?>/pages/subscriptions.php';
  document.getElementById('delPayModal').style.display='flex';
  document.body.style.overflow='hidden';
}
function closeDelPayModal(){document.getElementById('delPayModal').style.display='none';document.body.style.overflow='';}

function openEditExamModal(exam, athleteId){
  document.getElementById('examId').value = exam.id;
  document.getElementById('examAthleteId').value = athleteId;
  document.getElementById('examAmount').value = exam.amount ? parseFloat(exam.amount).toFixed(2) : '';
  document.getElementById('examMethod').value = exam.payment_method || 'cash';
  document.getElementById('examDate').value = (function(v){
    if(!v) return '';
    var p = v.split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : v;
  })(exam.transaction_date || '');
// Strip internal markers and translate to human-readable
  var rawNotes = exam.notes || '';
  rawNotes = rawNotes.replace(/exam_fee:\d+/g, '');
  rawNotes = rawNotes.replace(/:pending/g, '');
  rawNotes = rawNotes.replace(/:partial/g, '');
  rawNotes = rawNotes.replace(/:paid/g, '');
  rawNotes = rawNotes.replace(/:overpaid/g, '');
  rawNotes = rawNotes.trim().replace(/\s+/g, ' ');
  document.getElementById('examNotes').value = rawNotes;
	document.getElementById('examDescLabel').textContent = exam.description || 'Τέλος εξέτασης';
  var isPending = (exam.notes || '').indexOf(':pending') !== -1;
  document.getElementById('examStatus').value = isPending ? 'pending' : 'paid';
  document.getElementById('editExamModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeEditExamModal(){
  document.getElementById('editExamModal').style.display = 'none';
  document.body.style.overflow = '';
}
function openDelExamModal(id, athleteId, description){
  document.getElementById('delExamId').value = id;
  document.getElementById('delExamAthleteId').value = athleteId;
  document.getElementById('delExamLabel').textContent = description || 'τέλος εξέτασης';
  document.getElementById('delExamModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeDelExamModal(){
  document.getElementById('delExamModal').style.display = 'none';
  document.body.style.overflow = '';
}

document.getElementById('examStatus') && document.getElementById('examStatus').addEventListener('change', function(){
  var notesField = document.getElementById('examNotes');
  var currentNotes = notesField.value || '';
  if(this.value === 'pending') {
    if(currentNotes.indexOf(':pending') === -1) {
      notesField.value = currentNotes + ' :pending';
    }
  } else {
    notesField.value = currentNotes.replace(/\s*:pending\s*/g, ' ').trim();
  }
});

(function(){
  document.querySelectorAll('.js-date-input, #birthdateInput').forEach(function(el){
    if(!el) return;
    el.addEventListener('input', function(){ maskDateInput(el); });
    el.addEventListener('blur', function(){
      var parsed = parseDisplayDate(el.value);
      if(el.value && !parsed){
        el.style.borderColor = '#e63946';
      } else {
        el.style.borderColor = '';
      }
    });
  });

  var feeInput = document.getElementById('monthlyFeeInput');
  if(feeInput){
    feeInput.addEventListener('beforeinput', function(e){
      if(e.data && /[^0-9.,]/.test(e.data)) e.preventDefault();
    });
    feeInput.addEventListener('blur', function(){
      var raw = String(feeInput.value || '').replace(',', '.').trim();
      if(!raw) return;
      var n = parseFloat(raw);
      if(isNaN(n) || n < 0){
        feeInput.value = '';
        return;
      }
      if(n > 9999){
        feeInput.value = '9999';
        return;
      }
      feeInput.value = Number(n).toFixed(n % 1 === 0 ? 0 : 2);
    });
  }
})();
	
	
		  
		  
		  
	
		  
		  
		  
	// ── Subscription preview ──
var _subMethodLabels = {cash:'💵 Μετρητά', card:'💳 Κάρτα', deposit:'🏦 Κατάθεση', other:'Άλλο'};
var _subStatusLabels = {paid:'✓ Πληρώθηκε', pending:'⏳ Εκκρεμεί', overdue:'⚠ Ληξιπρόθεσμη'};
var _subStatusColors = {paid:'#2dc653', pending:'#f0a500', overdue:'#e63946'};

function fmtDatePreview(v){
  if(!v) return '—';
  var p = v.split('-');
  if(p.length === 3) return p[2]+'/'+p[1]+'/'+p[0];
  return v;
}

function openSubPreview(sub){
  document.getElementById('spPeriod').textContent = fmtDatePreview(sub.valid_from) + ' → ' + fmtDatePreview(sub.valid_until);
  document.getElementById('spAmount').textContent = sub.amount > 0 ? parseFloat(sub.amount).toFixed(2).replace('.',',') + '€' : '—';
  var sc = _subStatusColors[sub.status] || '#8892b0';
  var sl = _subStatusLabels[sub.status] || sub.status || '—';
  document.getElementById('spStatus').innerHTML = '<span style="color:'+sc+';font-weight:700">'+sl+'</span>';
  document.getElementById('spMethod').textContent = _subMethodLabels[sub.payment_method] || sub.payment_method || '—';
  document.getElementById('spPaidAt').textContent = sub.paid_at ? fmtDatePreview(sub.paid_at) : '—';
  var notes = (sub.notes || '').trim();
  var notesRow = document.getElementById('spNotesRow');
  if(notes){
    document.getElementById('spNotes').textContent = notes;
    notesRow.style.display = '';
  } else {
    notesRow.style.display = 'none';
  }
  document.getElementById('subPreviewModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeSubPreview(){
  document.getElementById('subPreviewModal').style.display = 'none';
  document.body.style.overflow = '';
}

// ── Exam preview ──
var _examMethodLabels = {cash:'💵 Μετρητά', card:'💳 Κάρτα', deposit:'🏦 Κατάθεση', other:'Άλλο'};

function openExamPreview(exam){
  var desc = (exam.description || '—').replace(/#\d+/g, '').trim();
  document.getElementById('epDesc').textContent = desc || '—';
  document.getElementById('epAmt').textContent = exam.amount ? parseFloat(exam.amount).toFixed(2).replace('.',',') + '€' : '—';
  var isPending = (exam.notes || '').indexOf(':pending') !== -1;
  var statColor = isPending ? '#f0a500' : '#2dc653';
  var statLabel = isPending ? '⏳ Εκκρεμεί' : '✓ Πληρώθηκε';
  document.getElementById('epStat').innerHTML = '<span style="color:'+statColor+';font-weight:700">'+statLabel+'</span>';
  document.getElementById('epMeth').textContent = _examMethodLabels[exam.payment_method] || exam.payment_method || '—';
  document.getElementById('epDt').textContent = fmtDatePreview(exam.transaction_date || '');
  document.getElementById('examPreviewModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeExamPreview(){
  document.getElementById('examPreviewModal').style.display = 'none';
  document.body.style.overflow = '';
}
</script>
	
	
<!-- SUBSCRIPTION PREVIEW MODAL -->
<div id="subPreviewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(6px);z-index:10700;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeSubPreview()">
<div style="background:var(--card-bg,#131929);border:1.5px solid rgba(59,130,246,.3);border-radius:20px;width:100%;max-width:380px;box-shadow:0 20px 60px rgba(0,0,0,.7);animation:fadeUp .22s ease both">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.1rem;border-bottom:1px solid var(--border,#1e2536)">
    <div style="font-size:1rem;font-weight:800;color:#3b82f6;display:flex;align-items:center;gap:.45rem"><i class="fa-solid fa-euro-sign"></i> Στοιχεία Πληρωμής</div>
    <button onclick="closeSubPreview()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:30px;height:30px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.85rem"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div style="padding:1rem 1.1rem">
    <table style="width:100%;border-collapse:collapse">
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0;width:42%">Περίοδος</td><td style="font-size:.9rem;font-weight:700;padding:.3rem 0" id="spPeriod">—</td></tr>
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0">Ποσό</td><td style="font-size:.9rem;font-weight:700;padding:.3rem 0;color:#2dc653" id="spAmount">—</td></tr>
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0">Κατάσταση</td><td style="font-size:.9rem;padding:.3rem 0" id="spStatus">—</td></tr>
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0">Τρόπος</td><td style="font-size:.9rem;padding:.3rem 0" id="spMethod">—</td></tr>
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0">Ημ. Πληρωμής</td><td style="font-size:.9rem;padding:.3rem 0" id="spPaidAt">—</td></tr>
      <tr id="spNotesRow"><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0;vertical-align:top">Σημειώσεις</td><td style="font-size:.85rem;padding:.3rem 0;color:#8892b0" id="spNotes">—</td></tr>
    </table>
  </div>
  <div style="padding:.75rem 1.1rem;border-top:1px solid var(--border,#1e2536);display:flex;justify-content:flex-end">
    <button onclick="closeSubPreview()" style="min-height:36px;font-size:.88rem;font-weight:700;padding:.4rem .9rem;border-radius:9px;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)">Κλείσιμο</button>
  </div>
</div>
</div>

<!-- EXAM PREVIEW MODAL -->
<div id="examPreviewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(6px);z-index:10700;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeExamPreview()">
<div style="background:var(--card-bg,#131929);border:1.5px solid rgba(240,165,0,.3);border-radius:20px;width:100%;max-width:380px;box-shadow:0 20px 60px rgba(0,0,0,.7);animation:fadeUp .22s ease both">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.1rem;border-bottom:1px solid var(--border,#1e2536)">
    <div style="font-size:1rem;font-weight:800;color:#f0a500;display:flex;align-items:center;gap:.45rem"><i class="fa-solid fa-ribbon"></i> Στοιχεία Εξέτασης</div>
    <button onclick="closeExamPreview()" style="background:none;border:1px solid var(--border,#1e2536);border-radius:8px;color:var(--muted,#8892b0);width:30px;height:30px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.85rem"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div style="padding:1rem 1.1rem">
    <table style="width:100%;border-collapse:collapse">
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0;width:42%">Περιγραφή</td><td style="font-size:.9rem;font-weight:700;padding:.3rem 0" id="epDesc">—</td></tr>
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0">Ποσό</td><td style="font-size:.9rem;font-weight:700;padding:.3rem 0;color:#f0a500" id="epAmt">—</td></tr>
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0">Κατάσταση</td><td style="font-size:.9rem;padding:.3rem 0" id="epStat">—</td></tr>
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0">Τρόπος</td><td style="font-size:.9rem;padding:.3rem 0" id="epMeth">—</td></tr>
      <tr><td style="color:#8892b0;font-size:.82rem;font-weight:600;padding:.3rem 0">Ημερομηνία</td><td style="font-size:.9rem;padding:.3rem 0" id="epDt">—</td></tr>
    </table>
  </div>
  <div style="padding:.75rem 1.1rem;border-top:1px solid var(--border,#1e2536);display:flex;justify-content:flex-end">
    <button onclick="closeExamPreview()" style="min-height:36px;font-size:.88rem;font-weight:700;padding:.4rem .9rem;border-radius:9px;cursor:pointer;border:1px solid var(--border,#1e2536);background:none;color:var(--muted,#8892b0)">Κλείσιμο</button>
  </div>
</div>
</div>
</body></html>