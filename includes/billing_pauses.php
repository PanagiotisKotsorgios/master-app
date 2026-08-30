<?php

/** Central no-charge month rules used by debt calculations and reminders. */

function ensureBillingPauseSchema(PDO $db): void
{
    static $ready = false;
    if ($ready) return;

    try {
        $db->query('SELECT 1 FROM school_billing_pause_months LIMIT 1');
        $db->query('SELECT 1 FROM department_billing_pause_months LIMIT 1');
        $ready = true;
        return;
    } catch (Throwable $e) {
    }

    $db->exec("CREATE TABLE IF NOT EXISTS school_billing_pause_months (
        id INT NOT NULL AUTO_INCREMENT,
        school_id INT NOT NULL,
        month_num TINYINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_school_pause_month (school_id, month_num),
        KEY idx_school_pause (school_id),
        CONSTRAINT fk_school_billing_pause_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS department_billing_pause_months (
        id INT NOT NULL AUTO_INCREMENT,
        school_id INT NOT NULL,
        department_id INT NOT NULL,
        month_num TINYINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_department_pause_month (department_id, month_num),
        KEY idx_department_pause_school (school_id),
        KEY idx_department_pause_department (department_id),
        CONSTRAINT fk_department_billing_pause_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
        CONSTRAINT fk_department_billing_pause_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ready = true;
}

function billingMonthLabels(): array
{
    return [
        1 => 'Ιανουάριος', 2 => 'Φεβρουάριος', 3 => 'Μάρτιος',
        4 => 'Απρίλιος', 5 => 'Μάιος', 6 => 'Ιούνιος',
        7 => 'Ιούλιος', 8 => 'Αύγουστος', 9 => 'Σεπτέμβριος',
        10 => 'Οκτώβριος', 11 => 'Νοέμβριος', 12 => 'Δεκέμβριος',
    ];
}

function normaliseBillingPauseMonths(array $months): array
{
    $normalised = [];
    foreach ($months as $month) {
        $month = (int)$month;
        if ($month >= 1 && $month <= 12) $normalised[$month] = $month;
    }
    ksort($normalised);
    return array_values($normalised);
}

function replaceSchoolBillingPauseMonths(PDO $db, int $schoolId, array $months): void
{
    ensureBillingPauseSchema($db);
    $months = normaliseBillingPauseMonths($months);
    $db->prepare('DELETE FROM school_billing_pause_months WHERE school_id=?')->execute([$schoolId]);
    if (!$months) return;
    $stmt = $db->prepare('INSERT INTO school_billing_pause_months (school_id, month_num) VALUES (?, ?)');
    foreach ($months as $month) $stmt->execute([$schoolId, $month]);
}

function replaceDepartmentBillingPauseMonths(PDO $db, int $schoolId, int $departmentId, array $months): void
{
    ensureBillingPauseSchema($db);
    $months = normaliseBillingPauseMonths($months);
    $owner = $db->prepare('SELECT id FROM departments WHERE id=? AND school_id=? LIMIT 1');
    $owner->execute([$departmentId, $schoolId]);
    if (!$owner->fetchColumn()) throw new RuntimeException('Το τμήμα δεν βρέθηκε.');

    $db->prepare('DELETE FROM department_billing_pause_months WHERE school_id=? AND department_id=?')
       ->execute([$schoolId, $departmentId]);
    if (!$months) return;
    $stmt = $db->prepare('INSERT INTO department_billing_pause_months (school_id, department_id, month_num) VALUES (?, ?, ?)');
    foreach ($months as $month) $stmt->execute([$schoolId, $departmentId, $month]);
}

/**
 * Load all pause rules for a school once, so list pages and cron avoid N+1 queries.
 * `exact` contains the legacy one-off YYYY-MM school_exempt_months rows.
 */
function loadBillingPauseContext(PDO $db, int $schoolId): array
{
    $context = ['school' => [], 'departments' => [], 'exact' => []];
    if ($schoolId <= 0) return $context;

    try {
        $stmt = $db->prepare('SELECT month_num FROM school_billing_pause_months WHERE school_id=? ORDER BY month_num');
        $stmt->execute([$schoolId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $month) {
            $month = (int)$month;
            if ($month >= 1 && $month <= 12) $context['school'][$month] = true;
        }
    } catch (Throwable $e) {
        // Migration may still be running during the first seconds of a deploy.
    }

    try {
        $stmt = $db->prepare('SELECT department_id, month_num FROM department_billing_pause_months WHERE school_id=? ORDER BY department_id, month_num');
        $stmt->execute([$schoolId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $departmentId = (int)$row['department_id'];
            $month = (int)$row['month_num'];
            if ($departmentId > 0 && $month >= 1 && $month <= 12) {
                $context['departments'][$departmentId][$month] = true;
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $db->prepare('SELECT month, label FROM school_exempt_months WHERE school_id=?');
        $stmt->execute([$schoolId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (preg_match('/^\d{4}-\d{2}$/', (string)$row['month'])) {
                $context['exact'][(string)$row['month']] = (string)($row['label'] ?? '');
            }
        }
    } catch (Throwable $e) {
    }

    return $context;
}

function billingPauseMonthParts(DateTimeInterface|string $month): array
{
    if ($month instanceof DateTimeInterface) {
        return [$month->format('Y-m'), (int)$month->format('n')];
    }
    $value = trim($month);
    if (!preg_match('/^(\d{4})-(\d{2})/', $value, $matches)) return ['', 0];
    $monthNum = (int)$matches[2];
    return [$matches[1] . '-' . $matches[2], $monthNum >= 1 && $monthNum <= 12 ? $monthNum : 0];
}

function isSchoolBillingPaused(array $context, DateTimeInterface|string $month): bool
{
    [$monthKey, $monthNum] = billingPauseMonthParts($month);
    if ($monthNum === 0) return false;
    return isset($context['exact'][$monthKey]) || !empty($context['school'][$monthNum]);
}

function isBillingMonthPaused(array $context, ?int $departmentId, DateTimeInterface|string $month): bool
{
    if (isSchoolBillingPaused($context, $month)) return true;
    [, $monthNum] = billingPauseMonthParts($month);
    return $monthNum > 0
        && $departmentId
        && !empty($context['departments'][$departmentId][$monthNum]);
}

function billingPauseReason(array $context, ?int $departmentId, DateTimeInterface|string $month): ?string
{
    [$monthKey, $monthNum] = billingPauseMonthParts($month);
    if (isset($context['exact'][$monthKey])) return $context['exact'][$monthKey] ?: 'Εξαίρεση σχολής';
    if (!empty($context['school'][$monthNum])) return 'Η σχολή είναι κλειστή';
    if ($departmentId && !empty($context['departments'][$departmentId][$monthNum])) return 'Διακοπή τμήματος';
    return null;
}

function loadAthletePausePeriodsForBilling(PDO $db, int $schoolId, int $athleteId): array
{
    try {
        $stmt = $db->prepare('SELECT pause_from, pause_until FROM athlete_pause_periods WHERE school_id=? AND athlete_id=? ORDER BY pause_from');
        $stmt->execute([$schoolId, $athleteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function isAthleteIndividuallyPaused(DateTimeInterface $month, array $pausePeriods): bool
{
    $monthStart = new DateTimeImmutable($month->format('Y-m-01'));
    $monthEnd = $monthStart->modify('last day of this month');
    foreach ($pausePeriods as $period) {
        try {
            $from = new DateTimeImmutable((string)$period['pause_from']);
            $until = new DateTimeImmutable((string)$period['pause_until']);
            if ($from <= $monthEnd && $until >= $monthStart) return true;
        } catch (Throwable $e) {
        }
    }
    return false;
}

/** Calculate real debt while excluding school, department and athlete pause months. */
function calculateAthleteDebtSummary(
    PDO $db,
    int $schoolId,
    int $athleteId,
    ?int $departmentId,
    ?string $startDate,
    float $monthlyFee,
    ?array $context = null,
    ?string $endMonth = null,
    ?array $athletePausePeriods = null,
    ?array $paidSubscriptions = null
): array {
    $empty = ['months' => 0, 'balance' => 0.0, 'unpaid' => [], 'paused' => []];
    if (!$startDate || $startDate === '0000-00-00' || $monthlyFee <= 0) return $empty;

    try {
        $start = (new DateTimeImmutable($startDate))->modify('first day of this month');
        $end = $endMonth
            ? (new DateTimeImmutable($endMonth . (strlen($endMonth) === 7 ? '-01' : '')))->modify('first day of this month')
            : (new DateTimeImmutable())->modify('first day of this month');
    } catch (Throwable $e) {
        return $empty;
    }
    if ($start > $end) return $empty;

    $context ??= loadBillingPauseContext($db, $schoolId);
    $athletePausePeriods ??= loadAthletePausePeriodsForBilling($db, $schoolId, $athleteId);

    if ($paidSubscriptions === null) {
        $stmt = $db->prepare("SELECT valid_from, valid_until, amount FROM subscriptions WHERE athlete_id=? AND school_id=? AND status='paid'");
        $stmt->execute([$athleteId, $schoolId]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $subscriptions = $paidSubscriptions;
    }

    $labels = ['','Ιαν','Φεβ','Μαρ','Απρ','Μαι','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];
    $result = $empty;

    for ($month = $start; $month <= $end; $month = $month->modify('+1 month')) {
        $monthKey = $month->format('Y-m');
        $ruleReason = billingPauseReason($context, $departmentId, $month);
        $individualPause = isAthleteIndividuallyPaused($month, $athletePausePeriods);
        if ($ruleReason !== null || $individualPause) {
            $result['paused'][] = [
                'month' => $monthKey,
                'label' => $labels[(int)$month->format('n')] . ' ' . $month->format('Y'),
                'reason' => $ruleReason ?? 'Ατομική παύση αθλητή',
            ];
            continue;
        }

        $monthEnd = $month->modify('last day of this month');
        $paidForMonth = 0.0;
        foreach ($subscriptions as $subscription) {
            try {
                $validFrom = new DateTimeImmutable((string)$subscription['valid_from']);
                $validUntil = new DateTimeImmutable((string)$subscription['valid_until']);
                if ($validFrom <= $monthEnd && $validUntil >= $month) {
                    $paidForMonth += (float)($subscription['amount'] ?? 0);
                }
            } catch (Throwable $e) {
            }
        }

        $remaining = max(0.0, $monthlyFee - $paidForMonth);
        if ($remaining > 0.009) {
            $result['months']++;
            $result['balance'] += $remaining;
            $result['unpaid'][] = [
                'month' => $monthKey,
                'label' => $labels[(int)$month->format('n')] . ' ' . $month->format('Y'),
                'paid' => $paidForMonth,
                'remaining' => $remaining,
            ];
        }
    }

    return $result;
}
