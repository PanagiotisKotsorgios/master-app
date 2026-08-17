<!-- ═══ COOKIE CONSENT BANNER — GDPR/ePrivacy Compliant (v2, 30/03/2026) ═══ -->
<!-- Usage: <?php include 'legal/cookie-banner.php'; ?> just before </body> -->
<!-- Compliance: ν.3471/2006 art.5§3, ΓΚΠΔ, EDPB Cookie Banner Taskforce -->
<!-- Changes v2: equal-weight accept/reject buttons, default OFF toggles,    -->
<!--             localStorage consent proof log, no pre-consent storage.      -->
<style>
/* ── Banner ── */
#ck-banner{
  position:fixed;bottom:0;left:0;right:0;z-index:99999;
  background:rgba(13,16,23,.97);
  border-top:1px solid rgba(255,255,255,.08);
  backdrop-filter:blur(20px);
  padding:1.25rem 2rem;
  transform:translateY(100%);
  transition:transform .4s cubic-bezier(.22,1,.36,1);
}
#ck-banner.show{transform:translateY(0)}
.ck-inner{
  max-width:1100px;margin:0 auto;
  display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;justify-content:space-between;
}
.ck-text{font-size:.82rem;color:#6b7494;line-height:1.6;flex:1;min-width:280px}
.ck-text strong{color:#f0f2ff}
.ck-text a{color:#e63946;text-decoration:underline}
.ck-btns{display:flex;gap:.625rem;flex-shrink:0;flex-wrap:wrap;align-items:center}

/* ── Buttons — EQUAL visual weight for accept and reject (EDPB requirement) ── */
.ck-btn{
  font-size:.8rem;font-weight:700;padding:.5rem 1.1rem;
  border-radius:8px;cursor:pointer;border:none;font-family:'DM Sans',sans-serif;
  transition:all .2s;white-space:nowrap;
}
/* Accept: primary style */
.ck-accept{
  background:linear-gradient(135deg,#e63946,#d62f3d);
  color:#fff;
  box-shadow:0 0 16px rgba(230,57,70,.35);
  border:1px solid transparent;
}
.ck-accept:hover{box-shadow:0 0 24px rgba(230,57,70,.55)}

/* Reject: SAME visual weight as accept — solid border, not ghost */
.ck-reject{
  background:rgba(255,255,255,.08);
  color:#f0f2ff;
  border:1px solid rgba(255,255,255,.18);
}
.ck-reject:hover{background:rgba(255,255,255,.14);border-color:rgba(255,255,255,.3)}

/* Settings: tertiary/link style */
.ck-settings{background:none;color:#6b7494;border:none;text-decoration:underline;padding:.5rem .25rem;font-size:.78rem}
.ck-settings:hover{color:#f0f2ff}

/* ── Settings Modal ── */
#ck-modal{
  position:fixed;inset:0;z-index:100000;
  background:rgba(0,0,0,.7);backdrop-filter:blur(8px);
  display:none;align-items:flex-end;justify-content:center;
  padding:1rem;
}
#ck-modal.open{display:flex}
.ck-modal-box{
  background:#0d1017;border:1px solid rgba(255,255,255,.1);
  border-radius:20px 20px 0 0;width:100%;max-width:600px;
  padding:2rem;max-height:85vh;overflow-y:auto;
}
.ck-modal-title{font-family:'Syne',sans-serif;font-weight:700;font-size:1.1rem;margin-bottom:.375rem}
.ck-modal-subtitle{font-size:.78rem;color:#6b7494;margin-bottom:1rem;line-height:1.5}
.ck-toggle-row{
  display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;
  padding:.875rem 0;border-bottom:1px solid rgba(255,255,255,.06);
}
.ck-toggle-row:last-of-type{border:none}
.ck-toggle-info h4{font-size:.875rem;font-weight:600;color:#f0f2ff;margin-bottom:.2rem}
.ck-toggle-info p{font-size:.78rem;color:#6b7494;line-height:1.55}

/* Toggle switch */
.ck-switch{position:relative;flex-shrink:0;width:44px;height:24px;margin-top:.15rem}
.ck-switch input{opacity:0;width:0;height:0}
.ck-slider{
  position:absolute;inset:0;background:#3d4362;border-radius:50px;
  cursor:pointer;transition:background .3s;
}
.ck-slider::before{
  content:'';position:absolute;left:3px;top:3px;
  width:18px;height:18px;background:#fff;border-radius:50%;
  transition:transform .3s;
}
.ck-switch input:checked+.ck-slider{background:#e63946}
.ck-switch input:checked+.ck-slider::before{transform:translateX(20px)}
.ck-switch input:disabled+.ck-slider{opacity:.5;cursor:not-allowed}
.ck-switch input:disabled+.ck-slider::after{
  content:'';position:absolute;inset:0;border-radius:50px;
}

.ck-modal-btns{display:flex;gap:.625rem;margin-top:1.25rem;flex-wrap:wrap}
.ck-modal-revoke{font-size:.72rem;color:#4a5068;text-align:center;margin-top:.875rem;line-height:1.5}

/* ── Floating cookie icon (for re-opening preferences) ── */
#ck-float{
  position:fixed;bottom:1.25rem;left:1.25rem;z-index:9990;
  width:38px;height:38px;border-radius:50%;
  background:rgba(13,16,23,.92);border:1px solid rgba(255,255,255,.12);
  display:none;align-items:center;justify-content:center;
  cursor:pointer;font-size:1rem;
  transition:all .2s;box-shadow:0 2px 12px rgba(0,0,0,.4);
}
#ck-float:hover{background:rgba(230,57,70,.15);border-color:rgba(230,57,70,.4)}
#ck-float.visible{display:flex}
</style>

<!-- ── BANNER: Level 1 ── -->
<div id="ck-banner" role="dialog" aria-modal="true" aria-live="polite" aria-label="Επιλογές Cookies & Απορρήτου">
  <div class="ck-inner">
    <div class="ck-text">
      <strong>🍪 Ρυθμίσεις Cookies & Παρόμοιων Τεχνολογιών</strong><br>
      Χρησιμοποιούμε <strong>απολύτως απαραίτητες</strong> τεχνολογίες για τη λειτουργία της πλατφόρμας. Με τη συγκατάθεσή σας, χρησιμοποιούμε επίσης <strong>analytics</strong> για βελτίωση εμπειρίας και μέτρηση χρήσης. Μπορείτε να αλλάξετε επιλογές ανά πάσα στιγμή.
      <br><a href="<?= defined('APP_URL') ? APP_URL : '' ?>/legal/cookies.php">Πολιτική Cookies</a> &nbsp;|&nbsp;
      <a href="<?= defined('APP_URL') ? APP_URL : '' ?>/legal/privacy.php">Απόρρητο</a><br>
      <span style="font-size:.72rem;color:#4a5068;">Υπεύθυνος: ΚΟΤΣΟΡΓΙΟΣ ΠΑΝΑΓΙΩΤΗΣ · <a href="mailto:pkotsorgios654@gmail.com" style="color:#4a5068;">pkotsorgios654@gmail.com</a></span>
    </div>
    <!-- ✅ EDPB: equal-weight buttons — reject is NOT visually subordinate to accept -->
    <div class="ck-btns">
      <button class="ck-btn ck-settings" onclick="ckOpenModal()" aria-label="Ρυθμίσεις cookies">Ρυθμίσεις</button>
      <button class="ck-btn ck-reject" onclick="ckSave(['necessary'])" aria-label="Απόρριψη μη απαραίτητων cookies">Απόρριψη Μη Απαραίτητων</button>
      <button class="ck-btn ck-accept" onclick="ckSave(['necessary','functional','analytics'])" aria-label="Αποδοχή όλων των cookies">Αποδοχή Όλων</button>
    </div>
  </div>
</div>

<!-- ── MODAL: Level 2 (granular preferences) ── -->
<div id="ck-modal" role="dialog" aria-modal="true" aria-label="Ρυθμίσεις Cookies">
  <div class="ck-modal-box">
    <div class="ck-modal-title"><i class="fas fa-sliders" style="color:#e63946;margin-right:.5rem"></i>Προτιμήσεις Cookies</div>
    <p class="ck-modal-subtitle">Επιλέξτε ποιες κατηγορίες cookies αποδέχεστε. Τα «Απολύτως Απαραίτητα» είναι πάντα ενεργά.</p>

    <!-- 1. Strictly Necessary — always ON, disabled -->
    <div class="ck-toggle-row">
      <div class="ck-toggle-info">
        <h4>Απολύτως Απαραίτητα</h4>
        <p>Αναγκαία για σύνδεση, ασφάλεια CSRF, session management και αποθήκευση επιλογής συγκατάθεσης. Δεν μπορούν να απενεργοποιηθούν γιατί χωρίς αυτά η πλατφόρμα δεν λειτουργεί.</p>
      </div>
      <label class="ck-switch" title="Πάντα ενεργό">
        <input type="checkbox" checked disabled aria-label="Απολύτως απαραίτητα — πάντα ενεργό">
        <span class="ck-slider"></span>
      </label>
    </div>

    <!-- 2. Functional — default OFF ✅ EDPB requirement -->
    <div class="ck-toggle-row">
      <div class="ck-toggle-info">
        <h4>Λειτουργικά</h4>
        <p>Αποθηκεύουν προτιμήσεις διεπαφής (γλώσσα, θέμα, τελευταίο σωματείο). Δεν χρησιμοποιούνται για tracking. Ενεργοποιούνται μόνο αν επιλέξετε «Αποδοχή».</p>
      </div>
      <label class="ck-switch">
        <!-- ✅ Default: UNCHECKED (OFF) — user must actively enable -->
        <input type="checkbox" id="ck-functional" aria-label="Λειτουργικά cookies">
        <span class="ck-slider"></span>
      </label>
    </div>

    <!-- 3. Analytics — default OFF ✅ EDPB requirement -->
    <div class="ck-toggle-row">
      <div class="ck-toggle-info">
        <h4>Ανάλυσης (Analytics)</h4>
        <p>Ανώνυμα στατιστικά χρήσης (Google Analytics με IP anonymization ενεργό) για βελτίωση της πλατφόρμας. Δεν πωλούνται σε τρίτους. Δεδομένα: EU-US Data Privacy Framework.</p>
      </div>
      <label class="ck-switch">
        <!-- ✅ Default: UNCHECKED (OFF) — user must actively enable -->
        <input type="checkbox" id="ck-analytics" aria-label="Cookies ανάλυσης">
        <span class="ck-slider"></span>
      </label>
    </div>

    <div class="ck-modal-btns">
      <button class="ck-btn ck-accept" onclick="ckSaveModal()" style="flex:1">Αποθήκευση Επιλογών</button>
      <button class="ck-btn ck-reject" onclick="ckRejectFromModal()">Μόνο Απαραίτητα</button>
    </div>
    <p class="ck-modal-revoke">Μπορείτε να ανακαλέσετε ή να αλλάξετε τις επιλογές σας ανά πάσα στιγμή μέσω του εικονιδίου 🍪 στο κάτω μέρος της σελίδας.</p>
  </div>
</div>

<!-- Floating cookie button — appears after consent given, for re-managing preferences -->
<button id="ck-float" onclick="ckReopenBanner()" aria-label="Ρυθμίσεις cookies" title="Ρυθμίσεις Cookies">🍪</button>

<script>
(function(){
  'use strict';

  // ── Keys ──
  var COOKIE_KEY  = 'cookie_consent';   // HTTP cookie (server-readable)
  var LOCAL_KEY   = 'ck_consent';        // localStorage (browser-side fallback)
  var CONSENT_VER = '2026-03-30';        // bump when legal text changes → re-ask

  // ── Helpers ──
  function getCookie(name){
    var c = document.cookie.split(';').map(function(c){return c.trim();}).find(function(c){return c.startsWith(name+'=');});
    return c ? decodeURIComponent(c.split('=').slice(1).join('=')) : null;
  }
  function setCookie(name, val, days){
    var d = new Date();
    d.setTime(d.getTime() + days * 864e5);
    document.cookie = name+'='+encodeURIComponent(val)+';expires='+d.toUTCString()+';path=/;SameSite=Lax';
  }
  function getLocalConsent(){
    try { return JSON.parse(localStorage.getItem(LOCAL_KEY)||'null'); } catch(e){ return null; }
  }
  function setLocalConsent(obj){
    try { localStorage.setItem(LOCAL_KEY, JSON.stringify(obj)); } catch(e){}
  }

  // ── Read existing consent ──
  var existing = null;
  var cookieVal = getCookie(COOKIE_KEY);
  if(cookieVal){
    try { existing = JSON.parse(cookieVal); } catch(e){}
  }
  // fallback to localStorage
  if(!existing){ existing = getLocalConsent(); }

  // ── Show banner or float icon ──
  var banner = document.getElementById('ck-banner');
  var floatBtn = document.getElementById('ck-float');

  if(!existing || existing.ver !== CONSENT_VER){
    // No valid consent yet — show banner after brief delay
    setTimeout(function(){ banner.classList.add('show'); }, 600);
  } else {
    // Already consented — show float icon for preference management
    floatBtn.classList.add('visible');
    // Load already-consented categories
    if(existing.cats && existing.cats.includes('analytics')) ckLoadAnalytics();
  }

  // ── Save consent ──
  window.ckSave = function(categories){
    var payload = {
      ver:  CONSENT_VER,
      ts:   new Date().toISOString(),
      cats: categories
    };
    // 1. HTTP cookie (12 months, server-readable for PHP)
    setCookie(COOKIE_KEY, JSON.stringify(payload), 365);
    // 2. localStorage (browser-side, consent proof backup)
    setLocalConsent(payload);
    // 3. Server-side consent proof log (fire-and-forget)
    ckLogConsent(payload);

    // Hide banner / modal
    banner.classList.remove('show');
    document.getElementById('ck-modal').classList.remove('open');
    floatBtn.classList.add('visible');

    // Load consented services
    if(categories.includes('analytics')) ckLoadAnalytics();
  };

  window.ckOpenModal = function(){
    var saved = existing && existing.cats ? existing.cats : [];
    document.getElementById('ck-functional').checked = saved.includes('functional');
    document.getElementById('ck-analytics').checked  = saved.includes('analytics');
    document.getElementById('ck-modal').classList.add('open');
  };

  window.ckSaveModal = function(){
    var cats = ['necessary'];
    if(document.getElementById('ck-functional').checked) cats.push('functional');
    if(document.getElementById('ck-analytics').checked)  cats.push('analytics');
    ckSave(cats);
  };

  window.ckRejectFromModal = function(){
    ckSave(['necessary']);
  };

  window.ckReopenBanner = function(){
    // Re-open modal to let user change preferences
    ckOpenModal();
  };

  // Close modal on backdrop click
  document.getElementById('ck-modal').addEventListener('click', function(e){
    if(e.target === this) this.classList.remove('open');
  });

  // ── Consent proof server log ──
  // Logs: timestamp, categories, consent version to server for GDPR accountability
  // Does NOT store PII — only metadata for compliance demonstration
  function ckLogConsent(payload){
    try {
      var url = (typeof APP_URL !== 'undefined' ? APP_URL : '') + '/api/consent-log.php';
      var body = JSON.stringify({
        ver:  payload.ver,
        ts:   payload.ts,
        cats: payload.cats,
        ua:   navigator.userAgent.substring(0, 120)
        // Note: IP is captured server-side only, never sent from client
      });
      if(navigator.sendBeacon){
        navigator.sendBeacon(url, new Blob([body], {type:'application/json'}));
      } else {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type','application/json');
        xhr.send(body);
      }
    } catch(e){ /* non-critical: log failure does not break consent flow */ }
  }

  // ── Load analytics (only after consent) ──
  function ckLoadAnalytics(){
    // ✅ No scripts loaded before consent — only called from ckSave or on page load
    //    if consent was already given in a previous session.
    if(typeof window._ckAnalyticsLoaded !== 'undefined') return;
    window._ckAnalyticsLoaded = true;

    if(window.GA_ID){
      var s = document.createElement('script');
      s.src  = 'https://www.googletagmanager.com/gtag/js?id=' + window.GA_ID;
      s.async = true;
      document.head.appendChild(s);
      window.dataLayer = window.dataLayer || [];
      function gtag(){ window.dataLayer.push(arguments); }
      gtag('js', new Date());
      gtag('config', window.GA_ID, {
        anonymize_ip: true,
        allow_google_signals: false,
        allow_ad_personalization_signals: false
      });
    }
  }

  // ── ESC closes modal ──
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') document.getElementById('ck-modal').classList.remove('open');
  });

})();
</script>