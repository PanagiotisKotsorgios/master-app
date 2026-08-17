<?php
// api/public_stats.php — Public stats for landing page (no auth required)
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

try {
    $pdo = getDB();

    $stats = [
        'athletes' => (int) $pdo->query(
            "SELECT COUNT(*) FROM athletes WHERE active = 1"
        )->fetchColumn(),

        'schools' => (int) $pdo->query(
            "SELECT COUNT(*) FROM schools WHERE active = 1"
        )->fetchColumn(),

        'reminders' => (int) $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM reminder_logs WHERE status = 'sent')
                +
                (SELECT COALESCE(SUM(total_sent), 0) FROM broadcast_messages WHERE status = 'done')
        ")->fetchColumn(),
    ];

    echo json_encode(['success' => true, 'data' => $stats]);

} catch (Exception $e) {
    error_log('[MAster] public_stats failed: ' . $e->getMessage());
    echo json_encode(['success' => true, 'data' => ['athletes' => 0, 'schools' => 0, 'reminders' => 0]]);
}