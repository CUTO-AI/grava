// Google Analytics (gtag.js) – Initialisierung. Ausgelagert aus dem Inline-
// Snippet, damit die strikte CSP (script-src 'self') ohne 'unsafe-inline'
// greift. Der gtag.js-Loader wird separat im <head> von layout.php geladen.
window.dataLayer = window.dataLayer || [];
function gtag() { dataLayer.push(arguments); }
gtag('js', new Date());

// Per-Seite-Konfiguration (Seitentyp/Titel/URL) kommt aus einem
// nicht-ausführbaren JSON-Block <script type="application/json" id="ga-data">.
// Das ist CSP-konform (script-src 'self', kein Inline-JS) und erlaubt saubere
// content_group-Auswertungen sowie explizite page_title/page_location.
var _gaConfig = {};
try {
  var _gaEl = document.getElementById('ga-data');
  if (_gaEl) {
    var _gaData = JSON.parse(_gaEl.textContent || '{}');
    if (_gaData.content_group) { _gaConfig.content_group = _gaData.content_group; }
    if (_gaData.page_title)    { _gaConfig.page_title    = _gaData.page_title; }
    if (_gaData.page_location) { _gaConfig.page_location = _gaData.page_location; }
  }
} catch (e) { /* Fallback: Auto-Erfassung */ }

gtag('config', 'G-HVRGQSKQNV', _gaConfig);
