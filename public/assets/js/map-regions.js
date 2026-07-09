/*
 * map-regions.js — interaktive Reviere-Karte für die Web-Auswertungen
 * (/gebiete/karte). Zoom-adaptiv Welt → Land → Bundesland → Landkreis →
 * Gemeinde; Gebiete nach Besitzer eingefärbt (Deckung ∝ gehaltenem Anteil).
 * Klick auf ein Gebiet öffnet ein Detail-Panel (Besitzer, km, Kanten, Schwelle,
 * absteigende Bestenliste, Unter-Gebiete) — wie in der App. Klick auf ein
 * Unter-Gebiet öffnet/zoomt es.
 *
 * CSP-konform: externes 'self'-Script, nur same-origin-Fetches, keine
 * Inline-Handler. Daten aus /api/v1/game/regions[/{id}].
 */
(function () {
  'use strict';

  var GE = window.GE || {};
  if (!GE.map || typeof window.L === 'undefined') { return; }
  var el = document.getElementById('region-map');
  var panel = document.getElementById('region-panel');
  if (!el) { return; }

  // Konfiguration + Labels aus data-Attributen (serverseitig lokalisiert).
  var I18N = {};
  try { I18N = JSON.parse(el.getAttribute('data-i18n') || '{}'); } catch (e) { I18N = {}; }
  var LOCALE = el.getAttribute('data-locale') || 'en';
  var apiBase = '/api/v1';

  function t(key, fallback) { return (I18N && I18N[key]) || fallback || key; }

  var map = GE.map.createBaseMap(el, { center: [30, 0], zoom: 2 });

  // Ländername in Seitensprache (kyrillisch/asiatisch lesbar machen).
  var regionNames = null;
  try {
    if (typeof Intl !== 'undefined' && Intl.DisplayNames) {
      regionNames = new Intl.DisplayNames([LOCALE], { type: 'region' });
    }
  } catch (e) { regionNames = null; }

  function displayName(r) {
    if (r && r.level === 2 && r.country_code && regionNames) {
      try {
        var n = regionNames.of(String(r.country_code).toUpperCase());
        if (n && n.toUpperCase() !== String(r.country_code).toUpperCase()) { return n; }
      } catch (e) { /* fallthrough */ }
    }
    return (r && r.name) || '';
  }

  function levelLabel(level) {
    return t('level' + level, t('level_default', 'Gebiet'));
  }

  // Cyber-Palette; Fraktionsfarbe hat Vorrang.
  var FREE_STROKE = 'rgba(150,180,210,0.35)';
  var CAT = ['#00E5FF', '#B6FF2E', '#FF1E6F', '#FFB020', '#4DEFFF', '#CBFF66', '#FF5A93', '#00A6B8'];
  function catColor(id) { return CAT[Math.abs(id || 0) % CAT.length]; }
  function ownerColor(owner) {
    if (!owner) { return null; }
    if (owner.faction && owner.faction.color) { return owner.faction.color; }
    return catColor(owner.claimant_id);
  }
  function ownerName(owner) {
    if (!owner) { return t('free', 'frei'); }
    return owner.name || (owner.handle ? '@' + owner.handle : ('#' + (owner.claimant_id || '?')));
  }
  function km(m) { return (Math.round((m || 0) / 100) / 10).toLocaleString(LOCALE); }
  function pct(f) { return Math.round((f || 0) * 100) + ' %'; }

  function fetchJson(url) {
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
  }
  function noop() {}

  // ---- Polygon-Layer (zoom-adaptiv) ---------------------------------------

  var layer = null;
  var lastKey = '';

  function bboxParam() {
    var b = map.getBounds();
    return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]
      .map(function (n) { return n.toFixed(5); }).join(',');
  }

  // „Halter" für Beschriftung/Panel = Besitzer ODER (bei umkämpftem Gebiet) der
  // Führende. Für die FÄRBUNG (styleFor) gilt aber die Erober-Schwelle, s. u.
  function holderOf(rg) { return (rg && (rg.owner || rg.leader)) || null; }

  // Färbung respektiert die Erober-Schwelle des Servers (Anteil UND Kantenzahl je
  // Ebene, region_control_min_*): NUR wenn `owner` gesetzt ist (Schwelle erreicht),
  // wird voll in Besitzerfarbe gefüllt. „Umkämpft" (leader, aber unter Schwelle)
  // nur dezent angedeutet — so färbt eine einzelne Kante NICHT das ganze Land.
  function styleFor(rg) {
    var owner = rg.owner || null;
    var leader = rg.leader || null;
    var frac = rg.held_fraction || 0;
    if (owner) {
      var oc = ownerColor(owner) || FREE_STROKE;
      return {
        color: oc, weight: 1.6, opacity: 0.95,
        fillColor: oc, fillOpacity: 0.30 + 0.45 * Math.min(1, frac)
      };
    }
    if (leader && frac > 0) {
      var lc = ownerColor(leader) || FREE_STROKE;
      return {
        color: lc, weight: 1.0, opacity: 0.6,
        fillColor: lc, fillOpacity: 0.07, dashArray: '3 4'
      };
    }
    return { color: FREE_STROKE, weight: 0.8, opacity: 0.4, fillColor: '#000000', fillOpacity: 0.02 };
  }

  function drawRegions(regions) {
    var grp = L.layerGroup();
    regions.forEach(function (rg) {
      if (!rg.boundary_geojson) { return; }
      var holder = holderOf(rg);
      var tip = '<b>' + GE.map.escapeHtml(displayName(rg)) + '</b><br>'
        + GE.map.escapeHtml(ownerName(holder))
        + (holder ? ' · ' + pct(rg.held_fraction) : '');
      var poly = L.geoJSON(rg.boundary_geojson, { style: styleFor(rg) });
      poly.bindTooltip(tip, { sticky: true });
      poly.on('click', function () { openDetail(rg.id); });
      grp.addLayer(poly);
    });
    return grp;
  }

  function clearLayer() { if (layer) { map.removeLayer(layer); layer = null; } }

  // ---- Overlay: eroberte Fein-Gebiete als Punkte (App-artig) --------------
  // Beim Rauszoomen (grobe Basis-Ebene) zeigen wir zusätzlich die EROBERTEN
  // Landkreise als farbige Marker, damit sofort sichtbar ist, WO Revier gehalten
  // wird — ohne dass große Gebiete flächig „übernommen" wirken.
  var ownedLayer = null;
  function clearOwned() { if (ownedLayer) { map.removeLayer(ownedLayer); ownedLayer = null; } }

  // Marker-Ebene = eine feinere, eroberte Ebene über der groben Basis. Bei
  // Landkreis-/Gemeinde-Zoom (Basis 6/8) genügen die Polygone selbst.
  function markerLevelFor(baseLevel) { return baseLevel > 0 && baseLevel <= 4 ? 6 : 0; }

  function drawOwnedMarkers(regions) {
    var grp = L.layerGroup();
    regions.forEach(function (rg) {
      if (!rg.owner || !rg.center) { return; }   // NUR eroberte (Schwelle erreicht)
      var col = ownerColor(rg.owner) || FREE_STROKE;
      var m = L.circleMarker([rg.center.lat, rg.center.lon], {
        radius: 5 + 6 * Math.min(1, rg.held_fraction || 0),
        color: col, weight: 1.5, fillColor: col, fillOpacity: 0.75
      });
      m.bindTooltip('<b>' + GE.map.escapeHtml(displayName(rg)) + '</b><br>'
        + GE.map.escapeHtml(ownerName(rg.owner)) + ' · ' + pct(rg.held_fraction), { sticky: true });
      m.on('click', function () { openDetail(rg.id, true); });
      grp.addLayer(m);
    });
    return grp;
  }

  function loadOwnedOverlay(bb, baseLevel) {
    clearOwned();
    var mlvl = markerLevelFor(baseLevel);
    if (!mlvl) { return; }
    fetchJson(apiBase + '/game/regions?geometry=0&level=' + mlvl + '&bbox=' + encodeURIComponent(bb))
      .then(function (md) {
        var owned = ((md && md.regions) || []).filter(function (r) { return r.owner; });
        if (!owned.length) { return; }
        ownedLayer = drawOwnedMarkers(owned);
        ownedLayer.addTo(map);
        if (highlight) { highlight.bringToFront(); }
      }).catch(noop);
  }

  function reload() {
    var bb = bboxParam();
    if (bb === lastKey) { return; }
    lastKey = bb;
    // Ebene wählt der Server anhand der bbox-Spanne (wie in der App).
    fetchJson(apiBase + '/game/regions?geometry=1&bbox=' + encodeURIComponent(bb))
      .then(function (d) {
        clearLayer();
        var lvl = (d && d.level) || 0;
        layer = drawRegions((d && d.regions) || []);
        layer.addTo(map);
        if (highlight) { highlight.bringToFront(); }   // Auswahl über Basis-Layer halten
        setMode(levelLabel(lvl));
        loadOwnedOverlay(bb, lvl);   // eroberte Fein-Gebiete als Punkte darüber
      }).catch(noop);
  }

  function setMode(text) {
    var h = document.getElementById('region-map-mode');
    if (h) { h.textContent = text; }
  }

  var timer = null;
  function schedule() {
    if (timer) { window.clearTimeout(timer); }
    timer = window.setTimeout(reload, 300);
  }
  map.on('moveend', schedule);
  map.on('zoomend', schedule);

  // ---- Detail-Panel -------------------------------------------------------

  function el2(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (html !== undefined) { n.innerHTML = html; }
    return n;
  }

  function renderPanel(d) {
    if (!panel) { return; }
    var e = GE.map.escapeHtml;
    var owner = d.owner || null;
    var contested = !!d.contested || !owner;
    var parts = [];

    parts.push('<button type="button" class="region-panel__close" id="region-panel-close" aria-label="'
      + e(t('close', 'Schließen')) + '">×</button>');

    // Breadcrumb (klickbar).
    if (d.breadcrumb && d.breadcrumb.length) {
      var crumbs = d.breadcrumb.map(function (b) {
        return '<a href="#" data-region="' + b.id + '">' + e(displayName(b)) + '</a>';
      }).join(' <span class="sep">›</span> ');
      parts.push('<nav class="region-panel__crumbs">' + crumbs + '</nav>');
    }

    parts.push('<p class="region-panel__kicker">' + e(levelLabel(d.level)) + '</p>');
    parts.push('<h2 class="region-panel__title">' + e(displayName(d)) + '</h2>');

    // Besitzer-Karte.
    var badge = contested
      ? '<span class="region-badge region-badge--contested">' + e(t('contested', 'Umkämpft')) + '</span>'
      : '<span class="region-badge region-badge--owned">' + e(t('owned', 'Erobert')) + '</span>';
    var lead = contested
      ? (t('leading', 'aktuell führend') + ': ' + e(ownerName(d.leader)))
      : (t('ownedBy', 'Beherrscht von') + ' ' + e(ownerName(owner)));
    parts.push('<div class="region-owner">' + badge + '<div class="region-owner__name">' + lead + '</div></div>');

    // Kennzahlen.
    parts.push('<div class="region-stats">'
      + '<div class="region-stat"><div class="val">' + km(d.total_game_length_m) + ' km</div><div class="lbl">' + e(t('territory', 'Reviere gesamt')) + '</div></div>'
      + '<div class="region-stat"><div class="val">' + (d.total_edges || 0) + '</div><div class="lbl">' + e(t('edges', 'Kanten')) + '</div></div>'
      + (d.control_min_fraction != null
        ? '<div class="region-stat"><div class="val">' + pct(d.control_min_fraction) + '</div><div class="lbl">' + e(t('threshold', 'Schwelle')) + '</div></div>'
        : '')
      + '</div>');

    // Bestenliste (absteigend).
    if (d.leaderboard && d.leaderboard.length) {
      var rows = d.leaderboard.map(function (row) {
        return '<li><span class="rk">#' + row.rank + '</span>'
          + '<span class="nm">' + e(ownerName(row)) + '</span>'
          + '<span class="vl">' + km(row.held_length_m) + ' km · ' + pct(row.held_fraction) + '</span></li>';
      }).join('');
      parts.push('<h3 class="region-panel__h3">' + e(t('leaderboard', 'Bestenliste im Gebiet')) + '</h3>');
      parts.push('<ol class="region-board">' + rows + '</ol>');
    }

    // Unter-Gebiete (klickbar).
    if (d.children && d.children.length) {
      var kids = d.children.slice().sort(function (a, b) {
        return (b.total_edges || 0) - (a.total_edges || 0) || String(a.name).localeCompare(b.name);
      }).map(function (c) {
        // Führenden zeigen (owner||leader) — sonst stünde bei umkämpften Groß-
        // Gebieten „free", obwohl sie klar geführt werden (z. B. Bayern).
        var holder = holderOf(c);
        var dot = holder ? ('<span class="dot" style="background:' + (ownerColor(holder) || FREE_STROKE) + '"></span>') : '<span class="dot dot--free"></span>';
        return '<li><a href="#" data-region="' + c.id + '">' + dot + '<span class="nm">' + e(displayName(c)) + '</span>'
          + '<span class="vl">' + e(ownerName(holder)) + ' ›</span></a></li>';
      }).join('');
      parts.push('<h3 class="region-panel__h3">' + e(t('subareas', 'Unter-Gebiete')) + '</h3>');
      parts.push('<ul class="region-children">' + kids + '</ul>');
    }

    panel.innerHTML = parts.join('');
    panel.classList.add('is-open');

    // Handler (keine Inline-Handler — CSP).
    var close = document.getElementById('region-panel-close');
    if (close) { close.addEventListener('click', hidePanel); }
    Array.prototype.forEach.call(panel.querySelectorAll('[data-region]'), function (a) {
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        var id = parseInt(a.getAttribute('data-region'), 10);
        if (id) { openDetail(id, true); }
      });
    });
  }

  function hidePanel() {
    if (panel) { panel.classList.remove('is-open'); panel.innerHTML = ''; }
    clearHighlight();
  }

  // Hervorhebung des ausgewählten Gebiets (heller Umriss über dem Basis-Layer).
  var highlight = null;
  function clearHighlight() { if (highlight) { map.removeLayer(highlight); highlight = null; } }
  function showHighlight(d) {
    clearHighlight();
    if (!d || !d.boundary_geojson) { return null; }
    var col = ownerColor(holderOf(d)) || '#00E5FF';
    highlight = L.geoJSON(d.boundary_geojson, {
      interactive: false,
      style: { color: col, weight: 3, opacity: 1, fillColor: col, fillOpacity: 0.35, dashArray: '5 3' }
    });
    highlight.addTo(map);
    highlight.bringToFront();
    return highlight;
  }

  // maxZoom je Ebene, damit fitBounds nicht zu tief springt.
  function maxZoomForLevel(level) {
    return level >= 8 ? 13 : level >= 6 ? 11 : level >= 4 ? 8 : 5;
  }

  function openDetail(id, recenter) {
    if (!id) { return; }
    fetchJson(apiBase + '/game/regions/' + id)
      .then(function (d) {
        if (!d) { return; }
        renderPanel(d);
        var hl = showHighlight(d);
        if (!recenter) { return; }
        // Karte auf das gewählte Gebiet zoomen (fitBounds bevorzugt).
        if (hl && hl.getBounds && hl.getBounds().isValid()) {
          map.fitBounds(hl.getBounds(), { padding: [30, 30], maxZoom: maxZoomForLevel(d.level) });
        } else if (d.bbox) {
          map.fitBounds([[d.bbox.minLat, d.bbox.minLon], [d.bbox.maxLat, d.bbox.maxLon]],
            { padding: [30, 30], maxZoom: maxZoomForLevel(d.level) });
        } else if (d.center && typeof d.center.lat === 'number') {
          map.setView([d.center.lat, d.center.lon], maxZoomForLevel(d.level));
        }
      }).catch(noop);
  }

  GE.regionMap = { open: openDetail };

  reload();
})();
