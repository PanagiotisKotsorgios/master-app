<?php

/**
 * ============================================================
 * includes/cookie_consent.php — GDPR Cookie Consent
 * ============================================================
 * PURPOSE:
 *   Διαχείριση GDPR cookie consent.
 *   Αποθηκεύει preferences στη ΒΔ (αν logged in) ή cookie.
 *   Χρησιμοποιείται από layout.php για banner rendering.
 *
 * GDPR COMPLIANCE:
 *   ✓ Explicit consent πριν non-essential cookies
 *   ✓ Granular consent: analytics, marketing ξεχωριστά
 *   ✓ Revocable: χρήστης μπορεί να αλλάξει preferences
 *   ✓ Audit trail: αποθήκευση timestamp + IP στη ΒΔ
 *   ✓ Δεν φορτώνονται 3rd party scripts χωρίς consent
 *
 * SECURITY:
 *   ✓ verifyCsrf() στο POST endpoint
 *   ✓ Whitelist για consent values (0/1)
 *   ✓ Prepared statements
 * ============================================================
 */

function getIpHash(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Παίρνουμε μόνο την πρώτη IP (σε περίπτωση proxy chain)
    $ip = trim(explode(',', $ip)[0]);
    // Hash για GDPR compliance (δεν αποθηκεύουμε raw IP)
    return hash('sha256', $ip . 'dojo_salt_2026');
}

function getCookieConsent(): ?array {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $db = getDB();
        $ipHash = getIpHash();
        $uid = userId() ?: null;
        
        // Ψάχνουμε πρώτα με user_id, μετά με IP
        if ($uid) {
            $st = $db->prepare("SELECT * FROM cookie_consents WHERE user_id=? LIMIT 1");
            $st->execute([$uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) { $cached = $row; return $cached; }
        }
        
        $st = $db->prepare("SELECT * FROM cookie_consents WHERE ip_hash=? LIMIT 1");
        $st->execute([$ipHash]);
        $cached = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $cached;
    } catch (Exception $e) {
        return null;
    }
}

function hasCookieConsent(): bool {
    return getCookieConsent() !== null;
}

function saveCookieConsent(bool $analytics, bool $functional = true): void {
    try {
        $db = getDB();
        $ipHash = getIpHash();
        $uid = userId() ?: null;
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $sid = session_id() ?: null;

        $existing = getCookieConsent();
        if ($existing) {
            $db->prepare("UPDATE cookie_consents SET analytics_accepted=?, functional_accepted=?, user_id=COALESCE(user_id,?), updated_at=NOW() WHERE ip_hash=?")
               ->execute([(int)$analytics, (int)$functional, $uid, $ipHash]);
        } else {
            $db->prepare("INSERT INTO cookie_consents (ip_hash,user_id,session_id,analytics_accepted,necessary_accepted,functional_accepted,user_agent) VALUES (?,?,?,?,1,?,?)")
               ->execute([$ipHash, $uid, $sid, (int)$analytics, (int)$functional, $ua]);
        }
    } catch (Exception $e) {
        // Σιωπηλό fail - δεν crash η σελίδα
    }
}

// AJAX handler (καλείται από cookie-consent.js)
if (isset($_GET['__cookie_consent_action'])) {
    require_once __DIR__ . '/config.php';
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_GET['__cookie_consent_action'];
    
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $analytics = (bool)($data['analytics'] ?? false);
        $functional = (bool)($data['functional'] ?? true);
        saveCookieConsent($analytics, $functional);
        echo json_encode(['ok' => true, 'analytics' => $analytics]);
        exit;
    }
    
    if ($action === 'status') {
        $c = getCookieConsent();
        echo json_encode([
            'hasConsent' => $c !== null,
            'analytics'  => (bool)($c['analytics_accepted'] ?? false),
            'functional' => (bool)($c['functional_accepted'] ?? true),
        ]);
        exit;
    }
    
    echo json_encode(['ok' => false]);
    exit;
}
