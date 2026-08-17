<?php
/**
 * tools/run_events_migration.php — apply migrations/001_events.sql
 * Idempotent. Run:  php tools/run_events_migration.php
 * Or via web (superadmin only): /tools/run_events_migration.php?web=1
 */

require_once __DIR__ . '/../includes/config.php';

$isWeb = PHP_SAPI !== 'cli';
if ($isWeb) {
    requireSuperAdmin();
    header('Content-Type: text/plain; charset=utf-8');
}

$dir = __DIR__ . '/../migrations';
$files = glob($dir . '/*.sql');
sort($files);
if (!$files) { echo "No migrations found.\n"; exit(1); }

$db = getDB();
$totalOk = 0; $totalFail = 0;

foreach ($files as $sqlFile) {
    echo "\n── " . basename($sqlFile) . " ──\n";
    $sql = file_get_contents($sqlFile);
    // Preserve multi-line statements (ALTER TABLE ... ADD COLUMN ..., ADD COLUMN ...);
    // split on ; at line end but NOT inside parentheses
    $statements = [];
    $buf = ''; $depth = 0;
    foreach (preg_split("/(\r\n|\n)/", $sql) as $line) {
        $stripped = trim($line);
        if ($stripped === '' || str_starts_with($stripped, '--')) continue;
        $depth += substr_count($line, '(') - substr_count($line, ')');
        $buf .= $line . "\n";
        if ($depth <= 0 && str_ends_with($stripped, ';')) {
            $statements[] = trim($buf);
            $buf = ''; $depth = 0;
        }
    }
    if (trim($buf) !== '') $statements[] = trim($buf);

    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        try {
            $db->exec($stmt);
            echo "OK   " . substr($stmt, 0, 60) . "...\n";
            $totalOk++;
        } catch (PDOException $e) {
            // Ignore idempotent-safe errors on re-run
            $msg = $e->getMessage();
            $ignore = str_contains($msg, 'Duplicate')
                   || str_contains($msg, 'already exists')
                   || str_contains($msg, 'Multiple primary key')
                   || str_contains($msg, "Can't DROP")
                   || str_contains($msg, "check that column/key exists")
                   || str_contains($msg, 'Duplicate key name')
                   || str_contains($msg, 'Duplicate column name');
            echo ($ignore ? 'SKIP ' : 'FAIL ') . substr($stmt, 0, 60) . "...\n   -> " . $msg . "\n";
            if (!$ignore) $totalFail++;
        }
    }
}

echo "\nDone. $totalOk ok, $totalFail failed.\n";
if ($isWeb && $totalFail === 0) auditLog('events_migration_run', 'events', 0, "$totalOk statements");
