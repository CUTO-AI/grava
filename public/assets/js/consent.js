// Consent Mode v2 + Cookie-Banner. Ausgelagert als same-origin-Script, damit
// die strikte CSP (script-src 'self') ohne 'unsafe-inline' greift.
//
// MUSS im <head> VOR dem gtag.js-Loader und vor ga.js eingebunden werden:
// wir setzen hier den Consent-Default (analytics_storage = 'denied', sofern
// keine Zustimmung vorliegt), bevor irgendein GA-Tag feuert. ga.js ruft
// danach gtag('config', …) auf. Der Nutzer entscheidet per Banner; die Wahl
// wird 1 Jahr im Cookie `cr_consent` (granted|denied) gespeichert.
(function () {
  window.dataLayer = window.dataLayer || [];
  function gtag() { dataLayer.push(arguments); }
  window.gtag = window.gtag || gtag;

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }
  function setCookie(name, value, days) {
    var d = new Date();
    d.setTime(d.getTime() + days * 864e5);
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value)
      + '; Expires=' + d.toUTCString() + '; Path=/; SameSite=Lax' + secure;
  }

  var choice = getCookie('cr_consent'); // 'granted' | 'denied' | null

  // Default: alles verweigert; nur bei früherer Zustimmung Analytics erlauben.
  gtag('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: choice === 'granted' ? 'granted' : 'denied',
    wait_for_update: 500
  });

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    var banner = document.getElementById('cr-consent');
    if (!banner) { return; }
    // Bereits entschieden → Banner bleibt verborgen.
    if (choice === 'granted' || choice === 'denied') { return; }
    banner.hidden = false;

    function decide(granted) {
      setCookie('cr_consent', granted ? 'granted' : 'denied', 365);
      gtag('consent', 'update', {
        analytics_storage: granted ? 'granted' : 'denied'
      });
      banner.hidden = true;
    }

    var accept = document.getElementById('cr-consent-accept');
    var decline = document.getElementById('cr-consent-decline');
    if (accept) { accept.addEventListener('click', function () { decide(true); }); }
    if (decline) { decline.addEventListener('click', function () { decide(false); }); }
  });
})();
