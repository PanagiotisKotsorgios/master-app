<?php
/**
 * ============================================================
 * includes/security_headers.php
 * ============================================================
 * PURPOSE:
 *   Ορισμός κρίσιμων HTTP security headers για κάθε response.
 *   Αυτό το αρχείο φορτώνεται αυτόματα από config.php και
 *   εκτελείται ΠΡΙΝ από οποιαδήποτε HTML/JSON έξοδο.
 *
 * WHY THIS FILE EXISTS:
 *   Αντί να ορίζουμε headers σε κάθε σελίδα ξεχωριστά, τα
 *   κεντρίζουμε εδώ ώστε να μην ξεχαστεί σε καμία σελίδα.
 *
 * SECURITY THREATS ADDRESSED:
 *   - Clickjacking (X-Frame-Options)
 *   - MIME sniffing (X-Content-Type-Options)
 *   - XSS via injected scripts (Content-Security-Policy)
 *   - Sensitive data in browser cache (Cache-Control)
 *   - Info leakage via Referer (Referrer-Policy)
 *   - Protocol downgrade attacks (HSTS — production only)
 *   - Cross-origin isolation (COOP/COEP)
 *
 * CALLED BY: includes/config.php (last line)
 * ============================================================
 */

function sendSecurityHeaders(): void {

    // ── 1. CLICKJACKING PROTECTION ──────────────────────────
    // Αποτρέπει την ενσωμάτωση της εφαρμογής σε <iframe> από
    // εξωτερικά sites. Χωρίς αυτό, attackers μπορούν να
    // δημιουργήσουν διαφανές overlay και να κλέψουν κλικ
    // (UI Redressing / Clickjacking attack).
    header('X-Frame-Options: DENY');

    // ── 2. MIME TYPE SNIFFING PROTECTION ────────────────────
    // Αποτρέπει τον browser να "μαντεύει" τον Content-Type.
    // Χωρίς αυτό: ένα .txt αρχείο με HTML payload μπορεί να
    // εκτελεστεί ως HTML/JS (Content Sniffing attack).
    header('X-Content-Type-Options: nosniff');

    // ── 3. REFERRER POLICY ──────────────────────────────────
    // Ελέγχει τι URL αποκαλύπτεται στο Referer header.
    // "strict-origin-when-cross-origin": στέλνει μόνο το domain
    // (όχι path/query) σε cross-origin requests.
    // Αποτρέπει διαρροή sensitive URLs (π.χ. /admin/schools?id=5)
    // σε third-party πόρους (fonts, CDNs).
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // ── 4. PERMISSIONS POLICY ───────────────────────────────
    // Απενεργοποιεί browser APIs που δεν χρειαζόμαστε.
    // Αποτρέπει malicious iframes/scripts από το να αποκτήσουν
    // πρόσβαση σε κάμερα, μικρόφωνο, geolocation κλπ.
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');

    // ── 5. HSTS — HTTP STRICT TRANSPORT SECURITY ────────────
    // Μόνο για production με valid SSL certificate.
    // Λέει στον browser: "αυτό το site ΠΑΝΤΑ HTTPS, για 1 χρόνο".
    // Αποτρέπει SSL stripping attacks (π.χ. sslstrip).
    // ΠΡΟΣΟΧΗ: Μην ενεργοποιήσεις σε localhost/dev!
    // Αφού οριστεί, ο browser αρνείται HTTP για max-age seconds.
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // ── 6. CONTENT SECURITY POLICY (CSP) ────────────────────
    // Η πιο ισχυρή προστασία κατά XSS.
    // Ορίζει ρητά ΠΟΙΕΣ πηγές επιτρέπονται για κάθε τύπο πόρου.
    // Ακόμα κι αν εισαχθεί XSS payload, το CSP εμποδίζει
    // την εκτέλεσή του αν δεν είναι από whitelisted origin.
    //
    // ΣΗΜΕΙΩΣΗ: 'unsafe-inline' επιτρέπεται προσωρινά επειδή
    // υπάρχει inline JS/CSS σε πολλές σελίδες. Μακροπρόθεσμα,
    // να αντικατασταθεί με CSP nonces ή hashes.
    $cspDirectives = [
        // Default: μόνο same-origin αν δεν ορίζεται αλλού
        "default-src 'self'",

        // Scripts: same-origin + inline (existing code) + CDN libs
        "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://esm.sh",

        // Styles: same-origin + inline + Google Fonts + CDN
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com",

        // Fonts: Google Fonts + CDN
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",

        // Images: same-origin + data URIs + any HTTPS
        "img-src 'self' data: blob: https:",

        // AJAX/Fetch/WebSocket: same-origin + Viva.com API
        "connect-src 'self' https://www.vivapayments.com https://demo.vivapayments.com https://accounts.vivapayments.com https://demo-accounts.vivapayments.com",

        // Frames: Viva Smart Checkout redirect pages
        "frame-src https://www.vivapayments.com https://demo.vivapayments.com",

        // Απαγορεύει <object>, <embed>, <applet> (legacy plugin vectors)
        "object-src 'none'",

        // Αποτρέπει <base href> injection που θα άλλαζε relative URLs
        "base-uri 'self'",

        // Φόρμες μόνο σε same-origin (αποτρέπει exfiltration μέσω form POST)
        "form-action 'self'",

        // Αποτρέπει mixed content (HTTP πόρους σε HTTPS σελίδα)
        "upgrade-insecure-requests",
    ];
    header('Content-Security-Policy: ' . implode('; ', $cspDirectives));

    // ── 7. CROSS-ORIGIN OPENER POLICY ───────────────────────
    // Αποτρέπει cross-origin windows από το να αποκτήσουν
    // reference στο window object μας (Spectre mitigations).
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

    // ── 8. CACHE CONTROL FOR AUTHENTICATED PAGES ────────────
    // Αποτρέπει browser/proxy caching για authenticated responses.
    // Χωρίς αυτό: πατώντας "Πίσω" μετά logout, ο browser
    // μπορεί να εμφανίσει την cached σελίδα χωρίς auth check.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    // ── 9. SERVER FINGERPRINT REMOVAL ───────────────────────
    // Αφαιρεί "X-Powered-By: PHP/x.x" header που αποκαλύπτει
    // την PHP έκδοση και διευκολύνει targeted attacks.
    // Εναλλακτικά: expose_php = Off στο php.ini
    header_remove('X-Powered-By');
    header_remove('Server');
}

// Εκτέλεση αμέσως κατά τη φόρτωση (καλείται από config.php)
sendSecurityHeaders();