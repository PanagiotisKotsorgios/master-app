/* ═══════════════════════════════════════════════════════
   DojoManager — Cookie Consent Banner  v3.1
   assets/js/cookie-consent.js
   ═══════════════════════════════════════════════════════ */

/* ── Inject styles ── */
(function () {
  const css = `
/* =========================
   Banner (bottom bar)
   ========================= */
#dm-cookie-bar {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  z-index: 99999;
  background: rgba(10,13,22,0.97);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-top: 1px solid rgba(230,57,70,0.25);
  padding:
    calc(1rem + env(safe-area-inset-top, 0px))
    calc(1rem + env(safe-area-inset-right, 0px))
    calc(1rem + env(safe-area-inset-bottom, 0px))
    calc(1rem + env(safe-area-inset-left, 0px));
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: .85rem;
  font-family: 'DM Sans', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  font-size: clamp(.95rem, 2.7vw, 1rem);
  color: #d6dbf2;
  opacity: 0;
  transform: translateY(12px);
  transition: opacity .4s cubic-bezier(.22,1,.36,1), transform .4s cubic-bezier(.22,1,.36,1);
  pointer-events: none;
}
#dm-cookie-bar.dm-bar-in {
  opacity: 1;
  transform: translateY(0);
  pointer-events: all;
}
#dm-cookie-bar.dm-bar-out {
  opacity: 0;
  transform: translateY(12px);
  pointer-events: none;
}
#dm-cookie-bar .dm-cb-text {
  flex: 1 1 280px;
  line-height: 1.6;
}
#dm-cookie-bar .dm-cb-text a {
  color: #ff5a66;
  text-decoration: underline;
}
#dm-cookie-bar .dm-cb-btns {
  display: flex;
  flex-wrap: wrap;
  gap: .55rem;
  align-items: center;
}

/* =========================
   Buttons (shared)
   ========================= */
.dm-cb-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: .5rem;
  font-size: clamp(.84rem, 3.2vw, 1rem);
  padding: clamp(.62rem, 2.7vw, .8rem) clamp(.9rem, 3.5vw, 1.15rem);
  border-radius: 12px;
  font-weight: 900;
  cursor: pointer;
  border: none;
  transition: transform .2s, box-shadow .2s, background .2s, color .2s, border-color .2s;
  white-space: normal;
  line-height: 1.15;
  text-align: center;
  min-height: 44px;
  font-family: 'DM Sans', system-ui, sans-serif;
}
.dm-cb-btn i { font-size: 1.05em; line-height: 1; }
.dm-cb-btn:focus {
  outline: 3px solid rgba(230,57,70,.55);
  outline-offset: 2px;
}
.dm-cb-btn-accept {
  background: linear-gradient(135deg,#e63946,#d62f3d);
  color: #fff;
  box-shadow: 0 0 14px rgba(230,57,70,.35);
}
.dm-cb-btn-accept:hover {
  box-shadow: 0 0 24px rgba(230,57,70,.55);
  transform: translateY(-1px);
}
.dm-cb-btn-reject {
  background: rgba(255,255,255,.08);
  color: #e6eaff;
  border: 1px solid rgba(255,255,255,.12);
}
.dm-cb-btn-reject:hover { background: rgba(255,255,255,.12); color: #fff; }
.dm-cb-btn-custom {
  background: transparent;
  color: #d6dbf2;
  border: 1px solid rgba(255,255,255,.18);
}
.dm-cb-btn-custom:hover { color: #fff; border-color: rgba(255,255,255,.3); }

@media (max-width:520px) {
  #dm-cookie-bar .dm-cb-btns { width: 100%; }
  #dm-cookie-bar .dm-cb-btns .dm-cb-btn { width: 100%; }
}

/* =========================
   Overlay (behind modal)
   ========================= */
#dm-cookie-overlay {
  position: fixed;
  inset: 0;
  z-index: 99999;
  background: rgba(0,0,0,.65);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  opacity: 0;
  transition: opacity .35s ease;
  pointer-events: none;
}
#dm-cookie-overlay.dm-ov-in {
  opacity: 1;
  pointer-events: all;
}

/* =========================
   Modal
   ========================= */
#dm-cookie-modal {
  position: fixed;
  inset: 0;
  z-index: 100000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  opacity: 0;
  pointer-events: none;
  transition: opacity .35s ease;
}
#dm-cookie-modal.dm-modal-in {
  opacity: 1;
  pointer-events: all;
}
.dm-cm-box {
  background: #0d1017;
  border: 1px solid rgba(230,57,70,.25);
  border-radius: 18px;
  padding: clamp(1.25rem, 4vw, 2.1rem) clamp(1.1rem, 4vw, 2rem);
  width: min(620px, 100%);
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 30px 90px rgba(0,0,0,.75);
  font-family: 'DM Sans', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  color: #d6dbf2;
  transform: scale(.95) translateY(14px);
  transition: transform .38s cubic-bezier(.22,1,.36,1);
}
#dm-cookie-modal.dm-modal-in .dm-cm-box {
  transform: scale(1) translateY(0);
}
.dm-cm-box h3 {
  font-size: clamp(1.15rem, 4vw, 1.35rem);
  font-weight: 900;
  color: #f4f6ff;
  margin: 0 0 1rem 0;
  display: flex;
  align-items: center;
  gap: .6rem;
}
.dm-cm-box h3 i { color: #ff5a66; }
.dm-cm-desc {
  font-size: clamp(.95rem, 3.2vw, 1.02rem);
  color: #aab2d6;
  margin: 0 0 1.25rem 0;
  line-height: 1.7;
}
.dm-cm-desc a { color: #ff5a66; text-decoration: underline; }
.dm-cm-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.05rem 0;
  border-bottom: 1px solid rgba(255,255,255,.06);
}
.dm-cm-row:last-of-type { border-bottom: none; }
.dm-cm-label { flex: 1; }
.dm-cm-label strong {
  display: flex;
  align-items: center;
  gap: .55rem;
  font-size: clamp(1rem, 3.6vw, 1.05rem);
  color: #f4f6ff;
  margin-bottom: .35rem;
  font-weight: 900;
}
.dm-cm-label strong i { color: #ff5a66; }
.dm-cm-label span {
  font-size: clamp(.9rem, 3.2vw, .98rem);
  color: #9aa3c9;
  line-height: 1.6;
}
.dm-toggle { position: relative; width: 52px; height: 30px; flex-shrink: 0; margin-top: .15rem; }
.dm-toggle input { opacity: 0; width: 0; height: 0; }
.dm-toggle-track {
  position: absolute; inset: 0;
  background: #1e2536;
  border-radius: 60px;
  transition: .25s;
  cursor: pointer;
  border: 1px solid rgba(255,255,255,.08);
}
.dm-toggle input:checked + .dm-toggle-track { background: #e63946; border-color: rgba(230,57,70,.35); }
.dm-toggle-track::after {
  content: ''; position: absolute; top: 4px; left: 4px;
  width: 22px; height: 22px;
  background: #fff; border-radius: 50%; transition: .25s;
}
.dm-toggle input:checked + .dm-toggle-track::after { left: 26px; }
.dm-toggle input:disabled + .dm-toggle-track { opacity: .55; cursor: not-allowed; }
.dm-cm-btns {
  display: grid;
  grid-template-columns: 1fr 1fr auto;
  gap: .6rem;
  margin-top: 1.4rem;
  align-items: stretch;
}
.dm-cm-btns .dm-cb-btn { width: 100%; min-width: 0; }
#dmCloseModal { min-width: 52px; padding-left: .95rem; padding-right: .95rem; }
@media (max-width:520px) {
  .dm-cm-btns { grid-template-columns: 1fr 1fr; }
  #dmCloseModal { grid-column: 1 / -1; width: 100%; min-width: 0; }
}
@media (max-width:420px) {
  .dm-cm-btns { grid-template-columns: 1fr; }
  #dmSaveCustom { order: 1; }
  #dmAcceptAllModal { order: 2; }
  #dmCloseModal { order: 3; }
}
@media (max-width:360px) {
  .dm-cm-row { gap: .75rem; }
  .dm-toggle { width: 48px; height: 28px; }
  .dm-toggle-track::after { width: 20px; height: 20px; }
  .dm-toggle input:checked + .dm-toggle-track::after { left: 24px; }
}
@media (prefers-reduced-motion: reduce) {
  #dm-cookie-bar, #dm-cookie-overlay, #dm-cookie-modal, .dm-cm-box {
    transition: none !important;
  }
}

/* =========================
   Floating icon portal
   — sits bottom-RIGHT so it
     never blocks page CTAs
   ========================= */
#dm-icon-portal {
  /* No styles — intentional isolation */
}
`;
  const style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);
})();

/* ═══════════════════════════════════════════════════════
   State
   ═══════════════════════════════════════════════════════ */
const DM_CONSENT_KEY = 'dm_cookie_consent_v1';

function dmGetConsent() {
  try { return JSON.parse(localStorage.getItem(DM_CONSENT_KEY)); }
  catch (e) { return null; }
}

function dmSetConsent(analytics, functional) {
  const obj = { analytics: !!analytics, functional: !!functional, date: new Date().toISOString() };
  try { localStorage.setItem(DM_CONSENT_KEY, JSON.stringify(obj)); } catch (e) {}
  fetch('?__cookie_consent_action=save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(obj),
  }).catch(() => {});
  dmApplyConsent(obj);
}

function dmApplyConsent(consent) {
  if (!consent) return;
  dmApplyFunctional(consent.functional);
  dmApplyAnalytics(consent.analytics);
}

function dmApplyFunctional(allowed) {
  if (allowed) {
    try { localStorage.removeItem('djm_functional_disabled'); } catch(e) {}
    document.dispatchEvent(new CustomEvent('djm:functional:enabled'));
  } else {
    try {
      const keysToRemove = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && key.startsWith('djm_pref_')) keysToRemove.push(key);
      }
      keysToRemove.forEach(k => localStorage.removeItem(k));
      localStorage.setItem('djm_functional_disabled', '1');
    } catch(e) {}
    document.dispatchEvent(new CustomEvent('djm:functional:disabled'));
  }
}

function dmApplyAnalytics(allowed) {
  if (allowed) {
    document.cookie = 'djm_analytics_optout=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax';
    if (window.DJM_GA_ID && !window._djm_ga_loaded) {
      window._djm_ga_loaded = true;
      const s = document.createElement('script');
      s.src = 'https://www.googletagmanager.com/gtag/js?id=' + window.DJM_GA_ID;
      s.async = true;
      document.head.appendChild(s);
      window.dataLayer = window.dataLayer || [];
      function gtag(){ dataLayer.push(arguments); }
      window.gtag = gtag;
      gtag('js', new Date());
      gtag('config', window.DJM_GA_ID, { anonymize_ip: true });
    }
    document.dispatchEvent(new CustomEvent('djm:analytics:enabled'));
  } else {
    document.cookie = 'djm_analytics_optout=1; max-age=31536000; path=/; SameSite=Lax';
    if (window.gtag) window['ga-disable-' + (window.DJM_GA_ID || '')] = true;
    document.dispatchEvent(new CustomEvent('djm:analytics:disabled'));
  }
}

window.DJM = window.DJM || {};
window.DJM.setPref = function(key, value) {
  try {
    if (localStorage.getItem('djm_functional_disabled') === '1') return false;
    localStorage.setItem('djm_pref_' + key, JSON.stringify(value));
    return true;
  } catch(e) { return false; }
};
window.DJM.getPref = function(key, defaultValue) {
  try {
    if (localStorage.getItem('djm_functional_disabled') === '1') return defaultValue;
    const val = localStorage.getItem('djm_pref_' + key);
    return val !== null ? JSON.parse(val) : defaultValue;
  } catch(e) { return defaultValue; }
};
window.DJM.hasConsent = function(type) {
  const c = dmGetConsent();
  if (!c) return false;
  if (type === 'necessary') return true;
  return !!c[type];
};

/* ═══════════════════════════════════════════════════════
   Overlay helpers
   ═══════════════════════════════════════════════════════ */
function dmGetOverlay() {
  let ov = document.getElementById('dm-cookie-overlay');
  if (!ov) {
    ov = document.createElement('div');
    ov.id = 'dm-cookie-overlay';
    document.body.appendChild(ov);
  }
  return ov;
}
function dmShowOverlay(onClickCallback) {
  const ov = dmGetOverlay();
  ov.onclick = onClickCallback || null;
  requestAnimationFrame(() => requestAnimationFrame(() => ov.classList.add('dm-ov-in')));
}
function dmHideOverlay() {
  const ov = document.getElementById('dm-cookie-overlay');
  if (!ov) return;
  ov.classList.remove('dm-ov-in');
  ov.onclick = null;
}

/* ═══════════════════════════════════════════════════════
   Banner
   ═══════════════════════════════════════════════════════ */
function dmRenderBanner() {
  if (dmGetConsent()) return;
  if (document.getElementById('dm-cookie-bar')) return;

  const bar = document.createElement('div');
  bar.id = 'dm-cookie-bar';
  bar.innerHTML = `
    <div class="dm-cb-text">
      <i class="fas fa-cookie-bite" aria-hidden="true" style="color:#ff5a66;margin-right:.35rem"></i>
      Χρησιμοποιούμε <strong>cookies</strong> για τη λειτουργία της πλατφόρμας.
      Cookies ανάλυσης ενεργοποιούνται μόνο με τη συγκατάθεσή σας. Δεν υπάρχουν διαφημιστικά cookies.
      <a href="../legal/cookies.php" target="_blank" rel="noopener">Πολιτική Cookies</a>
    </div>
    <div class="dm-cb-btns">
      <button class="dm-cb-btn dm-cb-btn-accept"  id="dmAcceptAll"      type="button">
        <i class="fas fa-check" aria-hidden="true"></i> Αποδοχή Όλων
      </button>
      <button class="dm-cb-btn dm-cb-btn-reject"  id="dmRejectOptional" type="button">
        <i class="fas fa-shield-alt" aria-hidden="true"></i> Μόνο Απαραίτητα
      </button>
      <button class="dm-cb-btn dm-cb-btn-custom"  id="dmCustomize"      type="button">
        <i class="fas fa-sliders-h" aria-hidden="true"></i> Επιλογές
      </button>
    </div>
  `;
  document.body.appendChild(bar);
  requestAnimationFrame(() => requestAnimationFrame(() => bar.classList.add('dm-bar-in')));

  function closeBanner(callback) {
    bar.classList.remove('dm-bar-in');
    bar.classList.add('dm-bar-out');
    setTimeout(() => { bar.remove(); if (callback) callback(); }, 420);
  }

  document.getElementById('dmAcceptAll').onclick = () => {
    dmSetConsent(true, true);
    closeBanner(dmShowIcon);
  };
  document.getElementById('dmRejectOptional').onclick = () => {
    dmSetConsent(false, true);
    closeBanner(dmShowIcon);
  };
  document.getElementById('dmCustomize').onclick = () => {
    closeBanner(() => dmOpenModal());
  };
}

/* ═══════════════════════════════════════════════════════
   Floating icon
   
   FIX v3.1:
   - Moved to BOTTOM-RIGHT so it never overlaps page CTAs
     (register buttons, hero buttons, etc.)
   - z-index lowered to 9999 — still above page content
     but BELOW the banner (99999) and modal (100000)
   - Portal approach kept for stacking context isolation
   ═══════════════════════════════════════════════════════ */
function dmShowIcon() {
  // Remove any old icon first
  const oldPortal = document.getElementById('dm-icon-portal');
  if (oldPortal) oldPortal.remove();

  // Create an isolated portal — NO styles on the portal div itself
  const portal = document.createElement('div');
  portal.id = 'dm-icon-portal';

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.title = 'Ρυθμίσεις Cookies';
  btn.setAttribute('aria-label', 'Ρυθμίσεις Cookies');
  btn.innerHTML = '<i class="fas fa-cookie-bite" style="font-size:1.3rem;color:#ff5a66;pointer-events:none"></i>';

  const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
  const size     = isMobile ? '52px' : '44px';

  // ── KEY FIXES ──
  // 1. position: bottom-RIGHT (was bottom-left — was blocking CTAs)
  // 2. z-index: 9999 (was 2147483647 — was covering everything)
  btn.setAttribute('style', [
    'position:fixed',
    'bottom:' + (isMobile ? '16px' : '20px'),
    'right:'  + (isMobile ? '16px' : '20px'),   // RIGHT not left
    'width:'  + size,
    'height:' + size,
    'border-radius:50%',
    'background:#0d1017',
    'border:2px solid rgba(230,57,70,.7)',
    'display:flex',
    'align-items:center',
    'justify-content:center',
    'cursor:pointer',
    'box-shadow:0 4px 20px rgba(0,0,0,.7),0 0 0 1px rgba(230,57,70,.25)',
    'z-index:9999',                               // LOWERED from 2147483647
    'opacity:0',
    'transform:scale(0.5)',
    'transition:opacity .35s ease,transform .35s cubic-bezier(.22,1,.36,1)',
    '-webkit-tap-highlight-color:transparent',
    'outline:none',
    'padding:0',
  ].join(';'));

  btn.onclick = () => dmOpenModal();

  // Touch feedback
  btn.addEventListener('touchstart', () => {
    btn.style.transform = 'scale(0.92)';
    btn.style.background = 'rgba(230,57,70,.15)';
  }, { passive: true });
  btn.addEventListener('touchend', () => {
    btn.style.transform = 'scale(1)';
    btn.style.background = '#0d1017';
  }, { passive: true });

  // Hover feedback (desktop)
  btn.addEventListener('mouseenter', () => {
    btn.style.background = 'rgba(230,57,70,.12)';
    btn.style.borderColor = 'rgba(230,57,70,1)';
    btn.style.boxShadow = '0 4px 24px rgba(230,57,70,.45),0 0 0 1px rgba(230,57,70,.35)';
  });
  btn.addEventListener('mouseleave', () => {
    btn.style.background = '#0d1017';
    btn.style.borderColor = 'rgba(230,57,70,.7)';
    btn.style.boxShadow = '0 4px 20px rgba(0,0,0,.7),0 0 0 1px rgba(230,57,70,.25)';
  });

  portal.appendChild(btn);
  document.body.appendChild(portal);

  // Animate in
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      btn.style.opacity = '1';
      btn.style.transform = 'scale(1)';
    });
  });
}

/* ═══════════════════════════════════════════════════════
   Modal
   ═══════════════════════════════════════════════════════ */
function dmOpenModal() {
  const bar = document.getElementById('dm-cookie-bar');
  if (bar) { bar.classList.remove('dm-bar-in'); bar.classList.add('dm-bar-out'); setTimeout(() => bar.remove(), 420); }

  const c = dmGetConsent() || {};

  let modal = document.getElementById('dm-cookie-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'dm-cookie-modal';
    document.body.appendChild(modal);
  }

  modal.innerHTML = `
    <div class="dm-cm-box" role="dialog" aria-modal="true" aria-labelledby="dmCookieTitle" tabindex="-1">
      <h3 id="dmCookieTitle"><i class="fas fa-cookie-bite" aria-hidden="true"></i> Ρυθμίσεις Cookies</h3>
      <p class="dm-cm-desc">
        Επιλέξτε ποιες κατηγορίες cookies αποδέχεστε. Τα απαραίτητα cookies δεν μπορούν να απενεργοποιηθούν.
        <a href="/legal/cookies.php" target="_blank" rel="noopener">Πολιτική Cookies</a>
      </p>
      <div class="dm-cm-row">
        <div class="dm-cm-label">
          <strong><i class="fas fa-lock" aria-hidden="true"></i> Απολύτως Απαραίτητα</strong>
          <span>Session, CSRF token, σύνδεση χρήστη. Απαραίτητα για τη λειτουργία.</span>
        </div>
        <label class="dm-toggle" aria-label="Απολύτως απαραίτητα cookies (πάντα ενεργά)">
          <input type="checkbox" checked disabled>
          <div class="dm-toggle-track"></div>
        </label>
      </div>
      <div class="dm-cm-row">
        <div class="dm-cm-label">
          <strong><i class="fas fa-cog" aria-hidden="true"></i> Λειτουργικά</strong>
          <span>Αποθήκευση προτιμήσεων διεπαφής, γλώσσα, τελευταίο σωματείο.</span>
        </div>
        <label class="dm-toggle" aria-label="Λειτουργικά cookies">
          <input type="checkbox" id="dmFunctional" ${c.functional !== false ? 'checked' : ''}>
          <div class="dm-toggle-track"></div>
        </label>
      </div>
      <div class="dm-cm-row">
        <div class="dm-cm-label">
          <strong><i class="fas fa-chart-line" aria-hidden="true"></i> Ανάλυση</strong>
          <span>Εσωτερικά analytics για τη βελτίωση της εφαρμογής. Δεν κοινοποιούνται σε τρίτους.</span>
        </div>
        <label class="dm-toggle" aria-label="Cookies ανάλυσης">
          <input type="checkbox" id="dmAnalytics" ${c.analytics ? 'checked' : ''}>
          <div class="dm-toggle-track"></div>
        </label>
      </div>
      <div class="dm-cm-btns">
        <button class="dm-cb-btn dm-cb-btn-accept" id="dmSaveCustom"     type="button">
          <i class="fas fa-save" aria-hidden="true"></i> Αποθήκευση Επιλογών
        </button>
        <button class="dm-cb-btn dm-cb-btn-reject" id="dmAcceptAllModal" type="button">
          <i class="fas fa-check-double" aria-hidden="true"></i> Αποδοχή Όλων
        </button>
        <button class="dm-cb-btn dm-cb-btn-custom" id="dmCloseModal"     type="button" aria-label="Κλείσιμο">
          <i class="fas fa-times" aria-hidden="true"></i>
        </button>
      </div>
    </div>
  `;

  dmShowOverlay(() => dmCloseModal());
  requestAnimationFrame(() => requestAnimationFrame(() => modal.classList.add('dm-modal-in')));
  setTimeout(() => { const box = modal.querySelector('.dm-cm-box'); if (box) box.focus(); }, 50);

  document.getElementById('dmSaveCustom').onclick = () => {
    dmSetConsent(
      document.getElementById('dmAnalytics').checked,
      document.getElementById('dmFunctional').checked
    );
    dmCloseModal();
    dmShowIcon();
  };
  document.getElementById('dmAcceptAllModal').onclick = () => {
    dmSetConsent(true, true);
    dmCloseModal();
    dmShowIcon();
  };
  document.getElementById('dmCloseModal').onclick = () => dmCloseModal();
  modal.onkeydown = (e) => { if (e.key === 'Escape') dmCloseModal(); };
}

function dmCloseModal() {
  const modal = document.getElementById('dm-cookie-modal');
  if (!modal) return;
  modal.classList.remove('dm-modal-in');
  dmHideOverlay();
  setTimeout(() => { if (!dmGetConsent()) dmRenderBanner(); }, 380);
}

/* ═══════════════════════════════════════════════════════
   Init
   ═══════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
  const consent = dmGetConsent();
  if (!consent) {
    setTimeout(dmRenderBanner, 700);
  } else {
    dmApplyConsent(consent);
    dmShowIcon();
  }
});