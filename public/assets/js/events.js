// GA4 Custom-Events via Daten-Attribute (CSP-konform, kein Inline-JS).
//
// Markiere ein klickbares Element mit data-ga-event="name". Optional weitere
// Parameter als data-ga-<key>="value" → werden als Event-Parameter übergeben.
// Beispiel: <a href="…" data-ga-event="appstore_click" data-ga-source="referral">
//
// Ausgelöste Events landen nur bei erteilter Einwilligung in GA (Consent Mode
// gated analytics_storage). gtag ist global aus ga.js verfügbar.
(function () {
  function paramsFrom(el) {
    var out = {};
    if (!el.dataset) { return out; }
    Object.keys(el.dataset).forEach(function (key) {
      if (key === 'gaEvent') { return; }
      if (key.indexOf('ga') === 0 && key.length > 2) {
        // data-ga-source → gaSource → source
        var name = key.charAt(2).toLowerCase() + key.slice(3);
        out[name] = el.dataset[key];
      }
    });
    return out;
  }

  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-ga-event]') : null;
    if (!el) { return; }
    var name = el.getAttribute('data-ga-event');
    if (!name) { return; }
    if (typeof window.gtag === 'function') {
      window.gtag('event', name, paramsFrom(el));
    }
  }, true);
})();
