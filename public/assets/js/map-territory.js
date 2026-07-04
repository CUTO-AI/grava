/*
 * map-territory.js — öffentliche Reviere-Karte, zoom-adaptiv wie in der App.
 *
 * Weit raus: grobe Regionen (Bezirk/Bundesland) → Landkreise → Gemeinden →
 * ganz nah: einzelne Wege (Kanten), nach Besitz eingefärbt. Besetzte Gebiete
 * werden in Fraktions-/Besitzerfarbe gefüllt (Deckung ∝ gehaltenem Anteil),
 * freie Gebiete nur als feine Umriss-Linie.
 *
 * CSP-konform: externes 'self'-Script, ausschließlich same-origin-Fetches
 * (connect-src 'self'), keine Inline-Handler, kein eval.
 */
(function () {
  'use strict';

  var GE = window.GE || {};
  if (!GE.map || typeof window.L === 'undefined') { return; }
  var el = document.getElementById('map');
  if (!el) { return; }

  var map = GE.map.createBaseMap(el, { center: [51.0, 10.3], zoom: 6 });

  // Cyber-Palette (Fraktionen: grün→lime, blau→cyan)
  var FREE_STROKE = 'rgba(150,180,210,0.30)';
  var CAT = ['#00E5FF', '#B6FF2E', '#FF1E6F', '#FFB020', '#4DEFFF', '#CBFF66', '#FF5A93', '#00A6B8'];

  function catColor(id) { return CAT[Math.abs(id || 0) % CAT.length]; }

  function ownerColor(owner) {
    if (!owner) { return null; }
    if (owner.faction && owner.faction.color) { return owner.faction.color; }
    return catColor(owner.claimant_id);
  }

  var layer = null;
  function clearLayer() { if (layer) { map.removeLayer(layer); layer = null; } }

  function bboxParam() {
    var b = map.getBounds();
    return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]
      .map(function (n) { return n.toFixed(5); }).join(',');
  }
  function spanDeg() {
    var b = map.getBounds();
    return Math.max(b.getNorth() - b.getSouth(), b.getEast() - b.getWest());
  }
  function fetchJson(url) {
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
  }
  function noop() {}

  function ownerName(owner) {
    if (!owner) { return 'frei'; }
    return owner.name || owner.handle || ('#' + (owner.claimant_id || '?'));
  }

  // ---- Renderer -----------------------------------------------------------

  function drawRegions(regions, asMarkers) {
    var grp = L.layerGroup();
    regions.forEach(function (rg) {
      var owner = rg.owner || null;
      var col = ownerColor(owner);
      var frac = rg.held_fraction || 0;
      var tip = '<b>' + GE.map.escapeHtml(rg.name || 'Region') + '</b><br>'
        + GE.map.escapeHtml(ownerName(owner))
        + (owner ? ' · ' + Math.round(frac * 100) + '% gehalten' : '');

      if (asMarkers && rg.center) {
        // Übersicht: nur besetzte Gebiete als Punkt, freie weglassen
        if (!owner) { return; }
        var m = L.circleMarker([rg.center.lat, rg.center.lon], {
          radius: 6 + 12 * frac, color: col, weight: 1.5,
          fillColor: col, fillOpacity: 0.55
        });
        m.bindTooltip(tip, { sticky: true });
        grp.addLayer(m);
        return;
      }

      var geo = rg.boundary_geojson;
      if (!geo) { return; }
      var poly = L.geoJSON(geo, {
        style: {
          color: col || FREE_STROKE,
          weight: owner ? 1.4 : 1,
          opacity: owner ? 0.95 : 0.35,
          fillColor: col || '#000000',
          fillOpacity: owner ? (0.12 + 0.42 * frac) : 0.0
        }
      });
      poly.bindTooltip(tip, { sticky: true });
      grp.addLayer(poly);
    });
    return grp;
  }

  function drawEdges(edges) {
    var grp = L.layerGroup();
    edges.forEach(function (e) {
      if (!e.geom || e.geom.type !== 'LineString') { return; }
      var latlngs = e.geom.coordinates.map(function (c) { return [c[1], c[0]]; });
      var owner = e.owner || null;
      var col = ownerColor(owner) || FREE_STROKE;
      var pl = L.polyline(latlngs, {
        color: col,
        weight: 2 + Math.min(6, (e.value || 0) / 4),
        opacity: owner ? (0.35 + 0.6 * (e.freshness || 0)) : 0.45
      });
      pl.bindTooltip(GE.map.escapeHtml(ownerName(owner)), { sticky: true });
      grp.addLayer(pl);
    });
    return grp;
  }

  function setMode(text) {
    var h = document.getElementById('map-mode');
    if (h) { h.textContent = text; }
  }

  // ---- Zoom-adaptive Auswahl (nach Grad-Spanne des Ausschnitts) -----------

  // Schwellen exakt wie in der App (GameStore): Marker ab 2.5° Spanne aus
  // Landkreis-Zentren (Level 6), sonst Landkreise (6) bzw. Gemeinden (8) als
  // Polygone, ganz nah einzelne Wege. So verschwinden die Pins beim
  // Rauszoomen nicht (frühere level4-Übersicht blieb ohne Level-4-Gebiete leer).
  var lastKey = '';
  function reload() {
    var s = spanDeg();
    var bb = bboxParam();
    var mode;
    if (s >= 2.5) { mode = 'markers'; }
    else if (s >= 0.4) { mode = 'level6'; }
    else if (s >= 0.15) { mode = 'level8'; }
    else { mode = 'edges'; }

    var key = mode + '|' + bb;
    if (key === lastKey) { return; }
    lastKey = key;

    if (mode === 'edges') {
      fetchJson('/api/v1/game/edges?bbox=' + encodeURIComponent(bb) + '&limit=4000&max_points_per_edge=48')
        .then(function (d) {
          clearLayer();
          layer = drawEdges((d && d.edges) || []);
          layer.addTo(map);
          setMode('Einzelne Wege · nach Besitz eingefärbt');
        }).catch(noop);
      return;
    }

    // Marker-Modus: Level-6-Zentren ohne Geometrie — spart Payload und
    // schweres Polygon-Rendering bei weitem Zoom (wie in der App).
    var asMarkers = (mode === 'markers');
    var level = (mode === 'level8') ? 8 : 6;
    var geometry = asMarkers ? 0 : 1;
    fetchJson('/api/v1/game/regions?bbox=' + encodeURIComponent(bb) + '&level=' + level + '&geometry=' + geometry)
      .then(function (d) {
        clearLayer();
        var regs = (d && d.regions) || [];
        layer = drawRegions(regs, asMarkers);
        layer.addTo(map);
        setMode(
          asMarkers ? 'Übersicht · besetzte Regionen — reinzoomen für Gebiete'
            : (level === 6 ? 'Landkreise' : 'Gemeinden')
        );
      }).catch(noop);
  }

  var timer = null;
  function schedule() {
    if (timer) { window.clearTimeout(timer); }
    timer = window.setTimeout(reload, 350);
  }

  map.on('moveend', schedule);
  map.on('zoomend', schedule);
  reload();
})();
