<?php
/**
 * ============================================================
 * parent/auth.php — Parent Portal Authentication Helpers
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';

// ── One-time schema migration for parent_users extra columns ──
function ensureParentUsersSchema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    // Create consent_log if missing
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS consent_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_id INT NULL,
            parent_user_id INT NULL,
            event_type VARCHAR(40) NOT NULL,
            ip_hash VARCHAR(128) NULL,
            user_agent VARCHAR(512) NULL,
            terms_version VARCHAR(10) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pid (parent_user_id),
            INDEX idx_type (event_type)
        )");
    } catch (\PDOException $e) {}

    foreach ([
        "ALTER TABLE parent_users ADD COLUMN terms_accepted_at DATETIME DEFAULT NULL",
        "ALTER TABLE parent_users ADD COLUMN sms_opt_out TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE parent_users ADD COLUMN email_opt_out TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE parent_users ADD COLUMN sms_consent_at DATETIME DEFAULT NULL",
        "ALTER TABLE parent_users ADD COLUMN unsubscribe_token VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE parent_users ADD COLUMN terms_version VARCHAR(10) DEFAULT NULL",
    ] as $sql) {
        try { $db->exec($sql); } catch (\PDOException $e) { /* column already exists */ }
    }
}
ensureParentUsersSchema();

function requireParentLogin(): void {
    if (!isParent()) {
        redirect(APP_URL . '/parent/login.php');
    }

    // If session flag is missing but DB says terms are accepted, fix session
    if (!($_SESSION['parent_terms_accepted'] ?? false)) {
        $db = getDB();
        $pid = parentUserId();
        $stmt = $db->prepare("SELECT terms_accepted_at FROM parent_users WHERE id = ?");
        $stmt->execute([$pid]);
        $dbAccepted = $stmt->fetchColumn();
        if ($dbAccepted) {
            $_SESSION['parent_terms_accepted'] = true;
            // Allow access, no redirect
            return;
        }
    }

    // Block access until terms are accepted, except on the terms page itself
    if (!($_SESSION['parent_terms_accepted'] ?? false)) {
        $current = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        if ($current !== 'terms.php') {
            redirect(APP_URL . '/parent/terms.php');
        }
    }
}

function isParent(): bool {
    return isset($_SESSION['user_id'])
        && isset($_SESSION['is_parent'])
        && $_SESSION['is_parent'] === true;
}

function parentSchoolId(): int {
    return (int)($_SESSION['school_id'] ?? 0);
}

function parentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

// ── Fetch all children linked to this parent with CORRECT overall status ──
function getParentChildren(): array {
    $db  = getDB();
    $sid = parentSchoolId();
    $pid = parentUserId();

    $stmt = $db->prepare("
        SELECT a.*,
               (SELECT MAX(paid_at) FROM subscriptions
                WHERE athlete_id = a.id AND school_id = a.school_id AND status = 'paid') AS last_payment_date
        FROM parent_children pc
        JOIN athletes a ON pc.athlete_id = a.id
        WHERE pc.parent_id = ?
          AND a.school_id  = ?
          AND a.active     = 1
        ORDER BY a.full_name
    ");
    $stmt->execute([$pid, $sid]);
    $children = $stmt->fetchAll();

    // Calculate overall status for each child based on monthly data
    foreach ($children as &$child) {
        $months = getAthleteMonthlyPayments((int)$child['id']);
        
        if (empty($months)) {
            $child['status'] = 'unknown';
        } else {
            $now = new DateTime();
            $currentMonthStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
            
            $hasCompletelyUnpaid = false;  // Month with €0 paid (current or future)
            $hasPartiallyPaid = false;      // Month with some payment but not full (current or future)
            $allCurrentPaid = true;         // All months from current onwards are paid
            
            foreach ($months as $m) {
                $monthDate = new DateTime($m['key'] . '-01');
                
                // Only check CURRENT month onwards (not past months)
                if ($monthDate >= $currentMonthStart) {
                    if (!$m['paid']) {
                        $allCurrentPaid = false;
                        
                        // Is it unpaid (€0) or partially paid?
                        if (empty($m['partial'])) {
                            $hasCompletelyUnpaid = true;
                        } else {
                            $hasPartiallyPaid = true;
                        }
                    }
                }
            }
            
            // Determine status based on current month onwards
            if ($allCurrentPaid) {
                // All current and future months are fully paid
                $child['status'] = 'paid';
            } elseif ($hasCompletelyUnpaid) {
                // Has at least one current/future month with NO payment at all
                $child['status'] = 'overdue';
            } elseif ($hasPartiallyPaid) {
                // Has partial payments but no completely unpaid months
                $child['status'] = 'pending';
            } else {
                $child['status'] = 'paid';
            }
        }
    }

    return $children;
}

// ── Get monthly payment status for an athlete (for calendar view) ──
function getAthleteMonthlyPayments(int $athleteId): array {
    $db  = getDB();
    $sid = parentSchoolId();

    $athStmt = $db->prepare("SELECT registration_date, debt_from_month, monthly_fee FROM athletes WHERE id=? AND school_id=? LIMIT 1");
    $athStmt->execute([$athleteId, $sid]);
    $ath = $athStmt->fetch();
    if (!$ath) return [];

    $monthlyFee = (float)($ath['monthly_fee'] ?? 0);

    $subStmt = $db->prepare("SELECT valid_from, valid_until, amount FROM subscriptions WHERE athlete_id=? AND school_id=? AND status='paid' ORDER BY valid_from ASC");
    $subStmt->execute([$athleteId, $sid]);
    $subs = $subStmt->fetchAll();

    if ($monthlyFee <= 0 && !empty($subs)) {
        $amounts = array_column($subs, 'amount');
        rsort($amounts);
        $monthlyFee = (float)$amounts[0];
    }

    $now        = new DateTime();
    $nowStart   = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
    $reg        = $ath['registration_date'] ?? null;
    $dfm        = $ath['debt_from_month'] ?? null;

    $startDate = null;

    if ($reg && $reg !== '0000-00-00') {
        $regDt = new DateTime($reg);
        if ($regDt <= $nowStart) {
            $startDate = $reg;
        }
    }

    if (!$startDate && $dfm && preg_match('/^\d{4}-\d{2}$/', $dfm)) {
        $dfmDt = new DateTime($dfm . '-01');
        if ($dfmDt <= $nowStart) {
            $startDate = $dfm . '-01';
        }
    }

    if (!$startDate && !empty($subs)) {
        $startDate = $subs[0]['valid_from'];
    }

    if (!$startDate) {
        $startDate = date('Y-m-01');
    }

    $feeKnown = ($monthlyFee > 0);

    $gm = ['','Ιαν','Φεβ','Μαρ','Απρ','Μαι','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];
    $gm_full = ['','Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος',
                'Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];

    $months  = [];
    $start   = (new DateTime($startDate))->modify('first day of this month');
    $cur     = clone $start;

    while ($cur <= $nowStart) {
        $mKey = $cur->format('Y-m');
        $mEnd = (clone $cur)->modify('last day of this month');
        $paidForMonth = 0.0;

        foreach ($subs as $s) {
            if (new DateTime($s['valid_from']) <= $mEnd && new DateTime($s['valid_until']) >= $cur) {
                $paidForMonth += (float)$s['amount'];
            }
        }

        if ($feeKnown) {
            $remaining  = max(0.0, $monthlyFee - $paidForMonth);
            $isPaid     = ($remaining < 0.01);
            $isPartial  = (!$isPaid && $paidForMonth > 0.005);
        } else {
            $isPaid    = ($paidForMonth > 0);
            $isPartial = false;
            $remaining = 0.0;
        }

        $paymentStatus = $isPaid ? 'paid' : ($isPartial ? 'partial' : 'unpaid');

        $months[] = [
            'key'            => $mKey,
            'label'          => $gm[(int)$cur->format('m')] . ' ' . $cur->format('Y'),
            'label_full'     => $gm_full[(int)$cur->format('m')] . ' ' . $cur->format('Y'),
            'year'           => $cur->format('Y'),
            'month_num'      => (int)$cur->format('m'),
            'paid'           => $isPaid,
            'partial'        => $isPartial,
            'payment_status' => $paymentStatus,
            'paid_amount'    => $paidForMonth,
            'fee'            => $monthlyFee,
            'remaining'      => $remaining,
        ];

        $cur->modify('+1 month');
    }

    return array_reverse($months);
}

// ── Stat helpers ──
function getPaidChildrenCount(): int {
    $db  = getDB();
    $sid = parentSchoolId();
    $pid = parentUserId();
    
    $stmt = $db->prepare("
        SELECT a.id FROM parent_children pc
        JOIN athletes a ON pc.athlete_id = a.id
        WHERE pc.parent_id = ? AND a.school_id = ? AND a.active = 1
    ");
    $stmt->execute([$pid, $sid]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $paidCount = 0;
    $now = new DateTime();
    $currentMonthStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
    
    foreach ($children as $childId) {
        $months = getAthleteMonthlyPayments($childId);
        
        $allCurrentPaid = true;
        foreach ($months as $m) {
            $monthDate = new DateTime($m['key'] . '-01');
            // Only check current month onwards
            if ($monthDate >= $currentMonthStart && !$m['paid']) {
                $allCurrentPaid = false;
                break;
            }
        }
        
        if ($allCurrentPaid) {
            $paidCount++;
        }
    }
    
    return $paidCount;
}

function getPendingChildrenCount(): int {
    $db  = getDB();
    $sid = parentSchoolId();
    $pid = parentUserId();
    
    $stmt = $db->prepare("
        SELECT a.id FROM parent_children pc
        JOIN athletes a ON pc.athlete_id = a.id
        WHERE pc.parent_id = ? AND a.school_id = ? AND a.active = 1
    ");
    $stmt->execute([$pid, $sid]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $pendingCount = 0;
    $now = new DateTime();
    $currentMonthStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
    
    foreach ($children as $childId) {
        $months = getAthleteMonthlyPayments($childId);
        
        $hasPartial = false;
        $hasUnpaid = false;
        
        foreach ($months as $m) {
            $monthDate = new DateTime($m['key'] . '-01');
            // Only check current month onwards
            if ($monthDate >= $currentMonthStart) {
                if (!$m['paid']) {
                    if (!empty($m['partial'])) {
                        $hasPartial = true;
                    } else {
                        $hasUnpaid = true;
                    }
                }
            }
        }
        
        // Pending = Has partial payments but NO completely unpaid months (from current month onwards)
        if ($hasPartial && !$hasUnpaid) {
            $pendingCount++;
        }
    }
    
    return $pendingCount;
}

function getOverdueChildrenCount(): int {
    $db  = getDB();
    $sid = parentSchoolId();
    $pid = parentUserId();
    
    $stmt = $db->prepare("
        SELECT a.id FROM parent_children pc
        JOIN athletes a ON pc.athlete_id = a.id
        WHERE pc.parent_id = ? AND a.school_id = ? AND a.active = 1
    ");
    $stmt->execute([$pid, $sid]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $overdueCount = 0;
    $now = new DateTime();
    $currentMonthStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
    
    foreach ($children as $childId) {
        $months = getAthleteMonthlyPayments($childId);
        
        $hasCompletelyUnpaid = false;
        foreach ($months as $m) {
            $monthDate = new DateTime($m['key'] . '-01');
            // Only check current month onwards
            if ($monthDate >= $currentMonthStart && !$m['paid'] && empty($m['partial'])) {
                $hasCompletelyUnpaid = true;
                break;
            }
        }
        
        if ($hasCompletelyUnpaid) {
            $overdueCount++;
        }
    }
    
    return $overdueCount;
}

// ── Change password ──
function changeParentPassword(string $newPassword): bool {
    $db  = getDB();
    $pid = parentUserId();
    if (!$pid || strlen($newPassword) < 8) return false;

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->prepare("UPDATE parent_users SET password_hash = ?, first_login = 0 WHERE id = ?")
       ->execute([$hash, $pid]);

    $_SESSION['parent_first_login'] = false;
    return true;
}