<?php
/**
 * includes/pro_website_banner.php
 * ----------------------------------------------------------------
 * Renders a dismissible "Free website for Pro subscribers" banner
 * at the top of the dashboard. Fully admin-controlled:
 *
 *   • pro_website_banner_enabled = "1"  →  banner shows
 *   • disabled                          →  nothing renders
 *
 * Only shown to users on the Pro plan, and once the user clicks
 * ✕ it hides for the rest of the session (dm_pro_banner_dismissed).
 *
 * Superadmin can preview by appending ?preview_pro_banner=1 to any
 * dashboard URL.
 * ----------------------------------------------------------------
 */

function renderProWebsiteBanner(): void {
    if (!function_exists('getDB') || !function_exists('getSetting')) return;

    $preview = function_exists('isSuperAdmin')
        && isSuperAdmin()
        && !empty($_GET['preview_pro_banner']);

    // Master kill switch
    if (!$preview && getSetting('pro_website_banner_enabled', '0') !== '1') return;

    // Pro-only unless previewing
    if (!$preview) {
        if (!function_exists('schoolPlan')) return;
        $plan = schoolPlan();
        if (($plan['slug'] ?? 'basic') !== 'pro') return;
    }

    // Session-level dismiss + optional cookie for persistence
    if (isset($_GET['dismiss_pro_banner'])) {
        $_SESSION['dm_pro_banner_dismissed'] = 1;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    if (!$preview && !empty($_SESSION['dm_pro_banner_dismissed'])) return;

    $title    = getSetting('pro_website_banner_title',
                    'Δωρεάν επαγγελματική ιστοσελίδα για τη σχολή σας');
    $message  = getSetting('pro_website_banner_message',
                    'Ως Pro συνδρομητής δικαιούστε δωρεάν σχεδίαση + φιλοξενία μιας mobile-first ιστοσελίδας για τη σχολή σας — συνδεδεμένη με το MAster.');
    $ctaLbl   = getSetting('pro_website_banner_cta_label', 'Ενημερώστε με τώρα');
    $ctaRaw   = trim((string)getSetting('pro_website_banner_cta_url', '/contact.php'));
    if ($ctaRaw === '') $ctaRaw = '/contact.php';

    // Normalise CTA URL: relative → prefix APP_URL; leave tel:/mailto:/https: alone
    if (preg_match('#^(https?:|tel:|mailto:|viber:|whatsapp:)#i', $ctaRaw)) {
        $ctaUrl = $ctaRaw;
    } else {
        $ctaUrl = rtrim(APP_URL, '/') . '/' . ltrim($ctaRaw, '/');
    }

    $dismissUrl = strtok($_SERVER['REQUEST_URI'], '?')
        . (empty($_SERVER['QUERY_STRING']) ? '?' : '?' . $_SERVER['QUERY_STRING'] . '&')
        . 'dismiss_pro_banner=1';
    ?>
<div id="proWebsiteBanner"
     style="position:relative;margin:1rem 1.25rem 0;padding:1.15rem 1.4rem;
            background:linear-gradient(135deg,rgba(230,57,70,.15) 0%,rgba(230,57,70,.05) 60%,rgba(14,30,53,.4) 100%);
            border:1px solid rgba(230,57,70,.35);border-radius:16px;
            display:flex;align-items:center;justify-content:space-between;gap:1.25rem;flex-wrap:wrap;
            box-shadow:0 8px 30px -12px rgba(230,57,70,.35);overflow:hidden">
  <div style="position:absolute;top:-40px;right:-40px;width:200px;height:200px;
              background:radial-gradient(circle,rgba(230,57,70,.18),transparent 70%);
              border-radius:50%;pointer-events:none"></div>
  <div style="display:flex;align-items:center;gap:1rem;flex:1;min-width:240px;position:relative;z-index:1">
    <div style="width:52px;height:52px;background:#e63946;border-radius:14px;
                display:flex;align-items:center;justify-content:center;flex-shrink:0;
                box-shadow:0 6px 18px -4px rgba(230,57,70,.6)">
      <i class="fa-solid fa-globe" style="color:#fff;font-size:1.35rem"></i>
    </div>
    <div>
      <div style="color:#ffffff;font-weight:800;font-size:1.02rem;line-height:1.3;
                  display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
        <span style="font-size:.65rem;background:#e63946;color:#fff;padding:.15rem .55rem;
                     border-radius:6px;letter-spacing:.06em;text-transform:uppercase">Pro Bonus</span>
        <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div style="color:#c1c8d4;font-size:.87rem;line-height:1.5;margin-top:.35rem;max-width:640px">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
      </div>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:.6rem;position:relative;z-index:1;flex-shrink:0">
    <a href="<?= htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8') ?>"
       style="background:#e63946;color:#ffffff;padding:.65rem 1.2rem;border-radius:10px;
              font-weight:700;font-size:.9rem;text-decoration:none;white-space:nowrap;
              box-shadow:0 4px 14px -4px rgba(230,57,70,.55);
              display:inline-flex;align-items:center;gap:.4rem;transition:transform .12s ease"
       onmouseover="this.style.transform='translateY(-1px)'"
       onmouseout="this.style.transform='translateY(0)'">
      <i class="fa-solid fa-arrow-right"></i>
      <?= htmlspecialchars($ctaLbl, ENT_QUOTES, 'UTF-8') ?>
    </a>
    <a href="<?= htmlspecialchars($dismissUrl, ENT_QUOTES, 'UTF-8') ?>"
       aria-label="Κλείσιμο"
       title="Κλείσιμο"
       style="background:rgba(255,255,255,.08);color:#c1c8d4;width:34px;height:34px;
              border-radius:8px;text-decoration:none;
              display:inline-flex;align-items:center;justify-content:center;
              transition:background .15s ease,color .15s ease"
       onmouseover="this.style.background='rgba(255,255,255,.14)';this.style.color='#fff'"
       onmouseout="this.style.background='rgba(255,255,255,.08)';this.style.color='#c1c8d4'">
      <i class="fa-solid fa-xmark"></i>
    </a>
  </div>
</div>
    <?php
}
