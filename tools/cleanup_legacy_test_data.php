<?php
/**
 * Remove only the legacy test tenants that existed before production launch.
 *
 * This intentionally uses an immutable allow-list of exact school signatures.
 * It must never be changed to a broad rule such as "all non-admin users" or
 * "all schools except demo", because future tenants are production data.
 *
 * Run automatically by docker-entrypoint.sh on every deployment. It is also
 * safe to run repeatedly from the CLI:
 *   php tools/cleanup_legacy_test_data.php
 *   php tools/cleanup_legacy_test_data.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$db = getDB();

/** @return list<int> */
function cleanupIds(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

/** @return list<string> */
function cleanupTablesWithColumn(PDO $db, string $column): array
{
    $stmt = $db->prepare(
        "SELECT c.TABLE_NAME
           FROM information_schema.COLUMNS c
           JOIN information_schema.TABLES t
             ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
          WHERE c.TABLE_SCHEMA = DATABASE()
            AND c.COLUMN_NAME = ?
            AND t.TABLE_TYPE = 'BASE TABLE'"
    );
    $stmt->execute([$column]);

    return array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_COLUMN),
        static fn($name): bool => is_string($name) && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1
    ));
}

/**
 * Delete rows from every base table that references one of the exact IDs via
 * the supplied conventional column name. The IDs have already been resolved
 * exclusively from the hard-coded test-school allow-list below.
 */
function cleanupDeleteByColumn(PDO $db, string $column, array $ids, array $skipTables = []): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $deleted = 0;

    foreach (cleanupTablesWithColumn($db, $column) as $table) {
        if (in_array($table, $skipTables, true)) {
            continue;
        }
        $stmt = $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
        $stmt->execute($ids);
        $deleted += $stmt->rowCount();
    }

    return $deleted;
}

// These are the only school records this cleanup is ever allowed to target.
// Both name and email must match. This prevents a future production tenant
// that happens to contain the word "Test" from ever being touched.
$legacySchoolSignatures = [
    ['Α.Σ. Test Athens', 'opengplms@gmail.com'],
    ['ΓΣ Test Larisas', 'nolifeprogrammer1@gmail.com'],
    ['ΑΟ Test Thessalonikis', 'info@mykalypsis.gr'],
    ['Σ.Α. Test Patras', 'support@timologion.gr'],
    ['ΣΧΟΛΗ ΠΟΛΕΜΙΚΩΝ ΤΕΧΝΩΝ ΑΘΗΝΩΝ', 'hattorihanzo1916@gmail.com'],
    ['ΑΘΛΗΤΙΚΟΣ ΟΜΙΛΟΣ ΚΑΡΑΤΕ ΘΕΣΣΑΛΟΝΙΚΗΣ', 'nolifeprogrammer1@gmail.com'],
];

$signatureSql = [];
$signatureParams = [];
foreach ($legacySchoolSignatures as [$name, $email]) {
    $signatureSql[] = '(s.name = ? AND LOWER(COALESCE(s.email, \'\')) = ?)';
    $signatureParams[] = $name;
    $signatureParams[] = strtolower($email);
}

// School #52 was a one-off duplicate created during the same test session.
// Its ID and exact name must both match, and the official demo email is an
// absolute exclusion. Once deleted, this clause cannot match a future row
// because MySQL does not recycle the consumed AUTO_INCREMENT ID.
$signatureSql[] = "(s.id = 52 AND s.name = 'Demo Σύλλογος MAster' AND LOWER(COALESCE(s.email, '')) <> 'demo@master-app.gr')";

$targetSql =
    'SELECT s.id
       FROM schools s
      WHERE (' . implode(' OR ', $signatureSql) . ")
        AND LOWER(COALESCE(s.email, '')) <> 'demo@master-app.gr'
        AND s.name <> 'MAster Admin School'
        AND NOT EXISTS (
              SELECT 1 FROM users protected_admin
               WHERE protected_admin.school_id = s.id
                 AND protected_admin.role = 'superadmin'
        )
      ORDER BY s.id";

$schoolIds = cleanupIds($db, $targetSql, $signatureParams);

if (!$schoolIds) {
    fwrite(STDOUT, "[legacy-cleanup] No allow-listed test schools found; production data unchanged.\n");
    exit(0);
}

$schoolPlaceholders = implode(',', array_fill(0, count($schoolIds), '?'));
$schoolStmt = $db->prepare("SELECT id, name, COALESCE(email, '') AS email FROM schools WHERE id IN ({$schoolPlaceholders}) ORDER BY id");
$schoolStmt->execute($schoolIds);
$targetSchools = $schoolStmt->fetchAll();

foreach ($targetSchools as $school) {
    fwrite(STDOUT, sprintf(
        "[legacy-cleanup] %sremove school #%d: %s <%s>\n",
        $dryRun ? 'would ' : '',
        (int)$school['id'],
        $school['name'],
        $school['email']
    ));
}

if ($dryRun) {
    fwrite(STDOUT, "[legacy-cleanup] Dry run complete; no rows changed.\n");
    exit(0);
}

// Resolve every related entity before deleting anything. These IDs are used
// to clean cross-tenant event rows that do not have a school foreign key.
$athleteIds = cleanupIds($db, "SELECT id FROM athletes WHERE school_id IN ({$schoolPlaceholders})", $schoolIds);
$userIds = cleanupIds($db, "SELECT id FROM users WHERE school_id IN ({$schoolPlaceholders})", $schoolIds);
$parentIds = cleanupIds($db, "SELECT id FROM parent_users WHERE school_id IN ({$schoolPlaceholders})", $schoolIds);
$departmentIds = cleanupIds($db, "SELECT id FROM departments WHERE school_id IN ({$schoolPlaceholders})", $schoolIds);
$competitionIds = cleanupIds($db, "SELECT id FROM competitions WHERE school_id IN ({$schoolPlaceholders})", $schoolIds);
$eventIds = cleanupIds($db, "SELECT id FROM events WHERE organiser_school_id IN ({$schoolPlaceholders})", $schoolIds);

$registrationWhere = ["registering_school_id IN ({$schoolPlaceholders})"];
$registrationParams = $schoolIds;
if ($athleteIds) {
    $registrationWhere[] = 'athlete_id IN (' . implode(',', array_fill(0, count($athleteIds), '?')) . ')';
    array_push($registrationParams, ...$athleteIds);
}
if ($eventIds) {
    $registrationWhere[] = 'event_id IN (' . implode(',', array_fill(0, count($eventIds), '?')) . ')';
    array_push($registrationParams, ...$eventIds);
}
$registrationIds = cleanupIds(
    $db,
    'SELECT id FROM event_registrations WHERE ' . implode(' OR ', $registrationWhere),
    $registrationParams
);

$paymentWhere = ["paying_school_id IN ({$schoolPlaceholders})"];
$paymentParams = $schoolIds;
if ($eventIds) {
    $paymentWhere[] = 'event_id IN (' . implode(',', array_fill(0, count($eventIds), '?')) . ')';
    array_push($paymentParams, ...$eventIds);
}
$eventPaymentIds = cleanupIds(
    $db,
    'SELECT id FROM event_payments WHERE ' . implode(' OR ', $paymentWhere),
    $paymentParams
);

$deleted = 0;
try {
    $db->beginTransaction();

    // Deep/cross-tenant relations first. Removing a test registration also
    // removes any match/result/payment link that points to that registration.
    foreach (['red_registration_id', 'blue_registration_id', 'winner_registration_id', 'registration_id'] as $column) {
        $deleted += cleanupDeleteByColumn($db, $column, $registrationIds);
    }
    $deleted += cleanupDeleteByColumn($db, 'payment_id', $eventPaymentIds);
    $deleted += cleanupDeleteByColumn($db, 'event_id', $eventIds, ['events']);

    // Entity-owned data without a direct school_id column.
    $deleted += cleanupDeleteByColumn($db, 'athlete_id', $athleteIds, ['athletes']);
    $deleted += cleanupDeleteByColumn($db, 'user_id', $userIds, ['users']);
    $deleted += cleanupDeleteByColumn($db, 'coach_user_id', $userIds);
    $deleted += cleanupDeleteByColumn($db, 'parent_id', $parentIds, ['parent_users']);
    $deleted += cleanupDeleteByColumn($db, 'parent_user_id', $parentIds);
    $deleted += cleanupDeleteByColumn($db, 'department_id', $departmentIds, ['departments']);
    $deleted += cleanupDeleteByColumn($db, 'competition_id', $competitionIds, ['competitions']);

    // Every known spelling of a school reference. Using information_schema
    // also covers newer tables without broadening which schools can match.
    foreach ([
        'target_school_id',
        'organiser_school_id',
        'organizer_school_id',
        'registering_school_id',
        'paying_school_id',
        'invited_school_id',
        'school_id',
    ] as $column) {
        $deleted += cleanupDeleteByColumn($db, $column, $schoolIds, ['schools']);
    }

    $deleteSchools = $db->prepare("DELETE FROM schools WHERE id IN ({$schoolPlaceholders})");
    $deleteSchools->execute($schoolIds);
    $deleted += $deleteSchools->rowCount();

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[legacy-cleanup] Failed; transaction rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "[legacy-cleanup] Removed %d allow-listed test school(s) and %d related row(s). Admin, official demo, and all other tenants were preserved.\n",
    count($schoolIds),
    $deleted
));
