<?php
/**
 * includes/summer_popup.php
 * Renders a summer pause notification popup/banner on the dashboard.
 *
 * Call renderSummerPopup() right after <body> in dashboard/index.php.
 */

function renderSummerPopup(): void
{
    if (!function_exists('schoolId') || !function_exists('getDB')) return;

    $sid = schoolId();
    if (!$sid) return;

    $db = getDB();

    // Load superadmin summer pause config
    $keys = ['summer_pause_enabled','summer_pause_month','summer_pause_end_month',
             'summer_pause_message','summer_pause_popup_days','summer_pause_reopening_message'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($keys);
    $cfg = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cfg[$r['setting_key']] = $r['setting_value'];

    // ── Test / preview mode (superadmin only) ───────────────────
    // Visit dashboard/?preview_popup=pause|soon|reopen to force-show
    // a specific popup variant without waiting for the actual dates.
    $previewType = '';
    if (isSuperAdmin() && isset($_GET['preview_popup'])) {
        $previewType = in_array($_GET['preview_popup'], ['pause','soon','reopen'], true)
            ? $_GET['preview_popup'] : '';
    }

    if (!$previewType && ($cfg['summer_pause_enabled'] ?? '0') !== '1') return;

    // Per-school opt-in
    $smStmt = $db->prepare("SELECT meta_val FROM school_meta WHERE school_id=? AND meta_key='summer_pause_opted_in'");
    $smStmt->execute([$sid]);
    $optedIn = ($smStmt->fetchColumn() === '1');

    $pauseMonth    = (int)($cfg['summer_pause_month'] ?? 7);
    $endMonth      = (int)($cfg['summer_pause_end_month'] ?? 8);
    $popupDays     = (int)($cfg['summer_pause_popup_days'] ?? 14);
    $message       = $cfg['summer_pause_message'] ?? 'Η πλατφόρμα βρίσκεται σε θερινή παύση.';
    $reopeningMsg  = $cfg['summer_pause_reopening_message'] ?? 'Καλώς ήρθατε πίσω! Η πλατφόρμα επαναλειτουργεί.';

    $now        = new DateTimeImmutable();
    $curMonth   = (int)$now->format('n');
    $curDay     = (int)$now->format('j');
    $pauseYear  = (int)$now->format('Y');

    // Compute start date of pause
    $pauseStart = new DateTimeImmutable("{$pauseYear}-{$pauseMonth}-01");
    // Compute end: last day of end month
    $pauseEnd   = (new DateTimeImmutable("{$pauseYear}-{$endMonth}-01"))->modify('last day of this month');

    // Override conditions when in preview mode
    if ($previewType === 'pause') {
        $inPause = true; $optedIn = true; $soonAlert = false; $isReopening = false; $daysUntil = 0;
    } elseif ($previewType === 'soon') {
        $inPause = false; $soonAlert = true; $daysUntil = 7; $isReopening = false;
    } elseif ($previewType === 'reopen') {
        $inPause = false; $soonAlert = false; $isReopening = true; $optedIn = true; $daysUntil = 0;
    } else {
        $inPause    = ($now >= $pauseStart && $now <= $pauseEnd);
        // Days until pause start (only relevant if not yet in pause)
        $daysUntil  = $now < $pauseStart ? (int)$now->diff($pauseStart)->days : 0;
        $soonAlert  = !$inPause && $daysUntil <= $popupDays && $daysUntil > 0;

        // Reopening: within 5 days after pause ended
        $daysSinceEnd = $now > $pauseEnd ? (int)$pauseEnd->diff($now)->days : 0;
        $isReopening  = ($daysSinceEnd >= 1 && $daysSinceEnd <= 5 && $optedIn);
    }

    // Dismiss via session (per-type) — skip dismiss check in preview mode
    $dismissKey = 'summer_popup_dismissed_' . ($inPause ? 'pause' : ($soonAlert ? 'soon' : 'reopen'));
    if (!$previewType && isset($_GET['dismiss_summer_popup'])) {
        $_SESSION[$dismissKey] = date('Y-m-d');
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $dismissed = !$previewType && (($_SESSION[$dismissKey] ?? '') === date('Y-m-d'));
    if ($dismissed) return;

    // Determine which popup to show
    if ($inPause && $optedIn) {
        // Active pause banner (shown every day during pause, can dismiss per day)
        ?>
<div id="summerPauseBanner" style="position:fixed;top:0;left:0;right:0;z-index:9999;background:linear-gradient(135deg,rgba(240,165,0,.95),rgba(230,120,0,.95));padding:.7rem 1.2rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;box-shadow:0 2px 12px rgba(0,0,0,.3);flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:.7rem;flex:1;min-width:0">
        <i class="fa-solid fa-sun" style="font-size:1.4rem;color:#fff;flex-shrink:0"></i>
        <div style="font-size:.9rem;color:#fff;font-weight:700;line-height:1.4">
            <strong>Θερινή Παύση Ενεργή</strong><br>
            <span style="font-weight:400;font-size:.83rem"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:.6rem;flex-shrink:0">
        <a href="<?= APP_URL ?>/pages/settings.php?tab=school" style="background:rgba(0,0,0,.2);color:#fff;border:1px solid rgba(255,255,255,.3);padding:.35rem .8rem;border-radius:8px;font-size:.8rem;font-weight:700;text-decoration:none;white-space:nowrap">Ρυθμίσεις</a>
        <a href="?dismiss_summer_popup=1" style="background:none;border:none;color:#fff;font-size:1.1rem;cursor:pointer;padding:.3rem;opacity:.8;text-decoration:none" title="Κλείσιμο">✕</a>
    </div>
</div>
<div style="height:52px"></div>
        <?php
    } elseif ($soonAlert) {
        // Coming soon popup (for all schools, opted-in or not, so they can opt in)
        ?>
<div id="summerSoonModal" style="position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#111520;border:1px solid rgba(240,165,0,.3);border-radius:20px;max-width:440px;width:100%;padding:2rem;box-shadow:0 8px 40px rgba(0,0,0,.5);position:relative">
        <div style="text-align:center;margin-bottom:1.2rem">
            <div style="font-size:3rem;margin-bottom:.5rem">☀️</div>
            <div style="font-size:1.15rem;font-weight:900;color:#f0a500">Θερινή Παύση σε <?= $daysUntil ?> ημέρες</div>
            <div style="font-size:.88rem;color:#8892b0;margin-top:.4rem">
                Ξεκινά <?= (new DateTimeImmutable("{$pauseYear}-{$pauseMonth}-01"))->format('d/m/Y') ?> — Λήγει <?= $pauseEnd->format('d/m/Y') ?>
            </div>
        </div>
        <p style="color:#b0bcd4;font-size:.9rem;line-height:1.6;margin:0 0 1.2rem;text-align:center">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </p>
        <?php if (!$optedIn): ?>
        <div style="background:rgba(240,165,0,.07);border:1px solid rgba(240,165,0,.2);border-radius:12px;padding:.85rem 1rem;margin-bottom:1.2rem;font-size:.87rem;color:#d7c39a;text-align:center">
            <i class="fa-solid fa-circle-info"></i> Για να ενεργοποιήσετε τη θερινή παύση για τη σχολή σας,
            μεταβείτε στις <a href="<?= APP_URL ?>/pages/settings.php?tab=school" style="color:#f0a500;font-weight:700">Ρυθμίσεις → Γενικές</a>.
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:.75rem;justify-content:center">
            <a href="<?= APP_URL ?>/pages/settings.php?tab=school" style="background:rgba(240,165,0,.15);color:#f0a500;border:1.5px solid rgba(240,165,0,.4);padding:.55rem 1.2rem;border-radius:12px;font-size:.9rem;font-weight:700;text-decoration:none">
                <i class="fa-solid fa-sliders"></i> Ρυθμίσεις
            </a>
            <a href="?dismiss_summer_popup=1" style="background:rgba(255,255,255,.05);color:#8892b0;border:1px solid rgba(255,255,255,.1);padding:.55rem 1.2rem;border-radius:12px;font-size:.9rem;text-decoration:none">
                Το είδα
            </a>
        </div>
    </div>
</div>
        <?php
    } elseif ($isReopening && !$dismissed) {
        ?>
<div id="summerReopenBanner" style="position:fixed;top:0;left:0;right:0;z-index:9999;background:linear-gradient(135deg,rgba(45,198,83,.92),rgba(16,160,60,.92));padding:.7rem 1.2rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;box-shadow:0 2px 12px rgba(0,0,0,.3);flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:.7rem;flex:1">
        <i class="fa-solid fa-circle-check" style="font-size:1.4rem;color:#fff;flex-shrink:0"></i>
        <div style="font-size:.9rem;color:#fff;font-weight:700;line-height:1.4">
            <strong>Επαναλειτουργία Πλατφόρμας</strong><br>
            <span style="font-weight:400;font-size:.83rem"><?= htmlspecialchars($reopeningMsg, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
    <a href="?dismiss_summer_popup=1" style="background:none;border:none;color:#fff;font-size:1.1rem;cursor:pointer;padding:.3rem;opacity:.8;text-decoration:none">✕</a>
</div>
<div style="height:52px"></div>
        <?php
    }
}
