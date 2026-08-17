<?php

/**
 * ============================================================
 * pages/payments_ajax.php — AJAX JSON API για Πληρωμές
 * ============================================================
 * PURPOSE:
 *   Επιστρέφει JSON με paid months για συγκεκριμένο αθλητή.
 *   Χρησιμοποιείται από subscriptions.php για dynamic UI.
 *
 * ENDPOINT:
 *   GET /pages/payments_ajax.php?action=paid_months&athlete_id=X
 *
 * SECURITY:
 *   ✓ requireLogin() — authentication required
 *   ✓ Ownership check: WHERE athlete_id=? AND school_id=schoolId()
 *     Αποτρέπει IDOR (Insecure Direct Object Reference):
 *     user δεν μπορεί να δει paid months άλλης σχολής
 *   ✓ (int) cast σε athlete_id
 *   ✓ Prepared statements
 *   ✓ JSON output: δεν υπάρχει HTML, XSS δεν εφαρμόζεται
 *
 * OUTPUT:
 *   JSON array: ["2024-01","2024-02",...] ή []
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
requireLogin();

header('Content-Type: application/json');

$db  = getDB();
$sid = schoolId();
$action = $_GET['action'] ?? '';

if ($action === 'paid_months') {
    $athleteId = (int)($_GET['athlete_id'] ?? 0);
    if (!$athleteId) { echo '[]'; exit; }

    // Verify athlete belongs to this school
    $check = $db->prepare("SELECT id FROM athletes WHERE id=? AND school_id=? AND active=1");
    $check->execute([$athleteId, $sid]);
    if (!$check->fetch()) { echo '[]'; exit; }

    $stmt = $db->prepare("SELECT `month` FROM payments WHERE athlete_id=? ORDER BY `month` ASC");
    $stmt->execute([$athleteId]);
    $months = array_column($stmt->fetchAll(), 'month');
    echo json_encode($months);
    exit;
}

echo '[]';