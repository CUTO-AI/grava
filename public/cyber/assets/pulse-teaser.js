/*
 * pulse-teaser.js — füllt den Live-Streifen der Landing aus GET /api/v1/pulse.
 * Same-origin, CSP-konform, keine Abhängigkeiten. Fehler degradieren leise
 * (der „—"-Platzhalter bleibt stehen).
 */
(function () {
  'use strict';
  var box = document.getElementById('pulse-teaser');
  if (!box) { return; }

  function set(key, val) {
    var el = box.querySelector('[data-pulse="' + key + '"]');
    if (el) { el.textContent = val; }
  }
  function num(n) { return (Math.round(Number(n) || 0)).toLocaleString(); }

  fetch('/api/v1/pulse', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
    .then(function (d) {
      var t = d.today || {};
      set('active_now', num(d.live && d.live.active_now));
      set('rides', num(t.rides));
      set('regions_taken', num(t.regions_taken));
      set('records_beaten', num(t.records_beaten));
    })
    .catch(function () { /* leise degradieren */ });
})();
