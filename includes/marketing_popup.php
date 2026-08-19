<?php
/**
 * includes/marketing_popup.php
 * ----------------------------------------------------------------
 * Renders the current admin-configured one-time marketing popup
 * on the dashboard (and any other page that calls the function).
 *
 * Fires ONCE per user per popup — a row in marketing_popup_actions
 * marks it seen, whether the user clicked the CTA or dismissed.
 *
 * The CTA emails the configured admin address via Brevo through
 * the existing sendEmail() infrastructure.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/marketing_popup.php';
 *   renderMarketingPopup();
 * ----------------------------------------------------------------
 */

function _mpActivePopupForUser(int $userId): ?array {
    if (!function_exists('getDB')) return null;
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT p.*
              FROM marketing_popups p
             WHERE p.enabled = 1
               AND (p.starts_at IS NULL OR p.starts_at <= NOW())
               AND (p.ends_at   IS NULL OR p.ends_at   >= NOW())
               AND NOT EXISTS (
                     SELECT 1 FROM marketing_popup_actions a
                      WHERE a.popup_id = p.id AND a.user_id = ?
                   )
             ORDER BY p.updated_at DESC, p.id DESC
             LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[MAster mkt_popup] active fetch: ' . $e->getMessage());
        return null;
    }
}

function _mpUserMatchesAudience(string $audience): bool {
    $role = $_SESSION['user']['role'] ?? '';
    $isParent = !empty($_SESSION['is_parent']);
    switch ($audience) {
        case 'parents':      return $isParent;
        case 'employees':    return in_array($role, ['employee','maintainer'], true);
        case 'club_admins':  return !$isParent && in_array($role, ['admin','owner','superadmin'], true);
        case 'all':
        default:             return true;
    }
}

function renderMarketingPopup(): void {
    // Accept both club-user session ($_SESSION['user']['id']) and
    // parent-portal session ($_SESSION['user_id'] / ['parent_id']).
    $userId = (int)($_SESSION['user']['id']
                 ?? $_SESSION['user_id']
                 ?? $_SESSION['parent_id']
                 ?? 0);
    if ($userId <= 0) return;

    $popup = _mpActivePopupForUser($userId);
    if (!$popup) return;

    if (!_mpUserMatchesAudience($popup['audience'] ?? 'all')) return;

    $csrf = $_SESSION['csrf_token'] ?? '';
    if (!$csrf && function_exists('csrfToken')) $csrf = csrfToken();

    $actionUrl = (defined('APP_URL') ? rtrim(APP_URL,'/') : '') . '/marketing-popup-action.php';
    $icon      = $popup['icon']          ?? 'fa-solid fa-globe';
    $title     = $popup['title']         ?? '';
    $body      = $popup['body_html']     ?? '';
    $ctaLabel  = $popup['cta_label']     ?? 'Ενδιαφέρομαι';
    $noLabel   = $popup['dismiss_label'] ?? 'Αργότερα';
    $pid       = (int)$popup['id'];
    ?>
<div id="mktPopup" role="dialog" aria-modal="true" aria-labelledby="mktPopupTitle"
     style="position:fixed;inset:0;z-index:10050;background:rgba(10,22,40,.62);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:1rem;animation:mktPopupFade .18s ease">
  <div style="background:#ffffff;color:#0f172a;border:1px solid #e2e8f0;border-radius:18px;max-width:520px;width:100%;padding:2rem 1.75rem 1.5rem;box-shadow:0 20px 60px -20px rgba(15,23,42,.4);position:relative;font-family:'Inter','DM Sans',system-ui,sans-serif;animation:mktPopupIn .22s cubic-bezier(.2,.9,.3,1.2)">
    <button type="button" aria-label="Κλείσιμο"
            onclick="mktPopupSubmit('dismissed')"
            style="position:absolute;top:.7rem;right:.7rem;width:34px;height:34px;border-radius:8px;background:transparent;border:none;color:#64748b;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s"
            onmouseover="this.style.background='rgba(15,23,42,.06)'"
            onmouseout="this.style.background='transparent'">✕</button>
    <div style="text-align:center;margin-bottom:1rem">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;background:#fef2f2;border:1px solid #fecaca;border-radius:14px;color:#e63946;font-size:1.5rem;margin-bottom:.85rem">
        <i class="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>
      </div>
      <h3 id="mktPopupTitle" style="font-size:1.2rem;font-weight:800;color:#0f172a;margin:0 0 .25rem;letter-spacing:-.01em">
        <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
      </h3>
    </div>
    <div style="font-size:.94rem;line-height:1.6;color:#475569;margin-bottom:1.3rem">
      <?= $body /* trusted admin HTML */ ?>
    </div>
    <div style="display:flex;gap:.6rem;justify-content:flex-end;flex-wrap:wrap">
      <button type="button" onclick="mktPopupSubmit('dismissed')"
              style="background:#ffffff;color:#0f172a;border:1px solid #cbd5e1;padding:.6rem 1.15rem;border-radius:10px;font-weight:600;font-size:.9rem;cursor:pointer;transition:background .15s,border-color .15s"
              onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'"
              onmouseout="this.style.background='#ffffff';this.style.borderColor='#cbd5e1'">
        <?= htmlspecialchars($noLabel, ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" onclick="mktPopupSubmit('interested')"
              style="background:#e63946;color:#ffffff;border:none;padding:.6rem 1.3rem;border-radius:10px;font-weight:700;font-size:.9rem;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.08);transition:background .15s,transform .12s"
              onmouseover="this.style.background='#dc2836';this.style.transform='translateY(-1px)'"
              onmouseout="this.style.background='#e63946';this.style.transform='translateY(0)'">
        <i class="fa-solid fa-heart" style="margin-right:.35rem"></i><?= htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
    <div id="mktPopupThanks" style="display:none;text-align:center;padding:1.2rem 0 .3rem;color:#16a34a;font-weight:700;font-size:.95rem">
      <i class="fa-solid fa-circle-check" style="margin-right:.4rem"></i>Σας ευχαριστούμε! Θα επικοινωνήσουμε σύντομα.
    </div>
  </div>
</div>
<style>
@keyframes mktPopupFade { from { opacity:0 } to { opacity:1 } }
@keyframes mktPopupIn { from { opacity:0; transform:translateY(12px) scale(.98) } to { opacity:1; transform:none } }
</style>
<script>
(function(){
  window.mktPopupSubmit = function(action){
    var el = document.getElementById('mktPopup');
    fetch(<?= json_encode($actionUrl) ?>, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
      body: 'popup_id=<?= $pid ?>&action=' + encodeURIComponent(action) +
            '&csrf=' + encodeURIComponent(<?= json_encode($csrf) ?>)
    }).catch(function(){});
    if (action === 'interested') {
      var box = el.querySelector('div[style*="background:#ffffff"]');
      if (box) {
        Array.prototype.slice.call(box.children).forEach(function(c){
          if (c.id !== 'mktPopupThanks') c.style.display = 'none';
        });
        var thanks = document.getElementById('mktPopupThanks');
        if (thanks) thanks.style.display = 'block';
      }
      setTimeout(function(){ if (el) el.remove(); }, 2400);
    } else {
      if (el) el.remove();
    }
  };
})();
</script>
    <?php
}
