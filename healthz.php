<?php
/**
 * healthz.php — Container liveness probe (Coolify / Docker healthcheck)
 * =====================================================================
 * Returns 200 if PHP + Apache are alive. Does NOT touch the DB by default
 * so the container stays "healthy" during a brief DB blip (which would
 * otherwise cause Coolify to keep restarting it and make recovery worse).
 *
 * Optional deep check:
 *   /healthz.php?db=1   → also opens a PDO connection
 *
 * No sessions, no config load, no output-buffering — fastest possible.
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$deep = isset($_GET['db']) && $_GET['db'] === '1';

if (!$deep) {
    echo "OK";
    exit;
}

// Deep mode: verify DB is reachable
try {
    $host    = getenv('DB_HOST') ?: 'localhost';
    $port    = (int)(getenv('DB_PORT') ?: 3306);
    $name    = getenv('DB_NAME') ?: 'master_db';
    $user    = getenv('DB_USER') ?: 'master';
    $pass    = getenv('DB_PASS') ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset={$charset}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]
    );
    $pdo->query("SELECT 1")->fetchColumn();
    echo "OK db";
} catch (Throwable $e) {
    http_response_code(503);
    echo "DEGRADED db: " . $e->getMessage();
}
