/*
 * pulse.js — „Heute im Spiel"-Seite. Holt GET /api/v1/pulse same-origin und
 * rendert alle Kacheln, mit Auto-Refresh (~60 s). CSP-konform: externes
 * 'self'-Script, nur same-origin-Fetch, keine Inline-Handler, kein eval.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/v1/pulse';
  var REFRESH_MS = 60000;

  // Sprache aus <html lang> (Fallback de) — DE/EN werden unterstützt.
  var LANG = (document.documentElement.lang || 'de').slice(0, 2).toLowerCase();
  var DE = LANG !== 'en';

  var T = DE ? {
    live: 'jetzt unterwegs', rides: 'Fahrten heute', dist: 'Kilometer heute',
    elev: 'Höhenmeter heute', signups: 'Neuanmeldungen', regions: 'Eroberte Gebiete',
    edges: 'Eroberte Kanten', records: 'Neue Rekorde',
    empty: 'Heute noch nichts los — sei die/der Erste!',
    heldPct: '% gehalten', flips: 'Wechsel', newEdges: 'Wege', edgesW: 'Kanten',
    solo: 'Einzel', crew: 'Crew', updated: 'Aktualisiert', error: 'Aktualisierung fehlgeschlagen',
    now: 'gerade eben'
  } : {
    live: 'riding now', rides: 'rides today', dist: 'kilometres today',
    elev: 'elevation today', signups: 'new sign-ups', regions: 'regions taken',
    edges: 'edges taken', records: 'new records',
    empty: 'Nothing yet today — be the first!',
    heldPct: '% held', flips: 'flips', newEdges: 'ways', edgesW: 'edges',
    solo: 'solo', crew: 'crew', updated: 'Updated', error: 'Refresh failed',
    now: 'just now'
  };

  // Feed-Textbausteine je Ereignistyp. {a}=Akteur, {r}=Region.
  var FEED = DE ? {
    edge_new:       ['◆', '{a} entdeckte Neuland{r}'],
    edge_taken:     ['▲', '{a} eroberte eine Kante{r}'],
    edge_lost:      ['▽', 'Eine Kante wurde verloren{r}'],
    edge_reclaimed: ['↻', '{a} holte sich eine Kante zurück{r}'],
    record_beaten:  ['★', '{a} stellte eine neue Bestzeit auf{r}'],
    pioneer_joined: ['⚑', '{a} wurde Pionier{r}'],
    region_taken:   ['⬢', '{a} eroberte {R}'],
    region_lost:    ['⬡', '{R} ging verloren']
  } : {
    edge_new:       ['◆', '{a} discovered new ground{r}'],
    edge_taken:     ['▲', '{a} captured an edge{r}'],
    edge_lost:      ['▽', 'An edge was lost{r}'],
    edge_reclaimed: ['↻', '{a} reclaimed an edge{r}'],
    record_beaten:  ['★', '{a} set a new record{r}'],
    pioneer_joined: ['⚑', '{a} became a pioneer{r}'],
    region_taken:   ['⬢', '{a} captured {R}'],
    region_lost:    ['⬡', '{R} was lost']
  };

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function $(id) { return document.getElementById(id); }
  function fmtInt(n) { return (Math.round(n) || 0).toLocaleString(DE ? 'de-DE' : 'en-GB'); }
  function fmt1(n) { return (Number(n) || 0).toLocaleString(DE ? 'de-DE' : 'en-GB', { maximumFractionDigits: 1 }); }

  function fmtTime(iso) {
    if (!iso) { return ''; }
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return ''; }
    return d.toLocaleTimeString(DE ? 'de-DE' : 'en-GB', { hour: '2-digit', minute: '2-digit' });
  }

  // Kleiner Sicherheitshelfer: gültige CSS-Farbe (#hex) oder Fallback.
  function safeColor(c, fallback) {
    return (typeof c === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(c)) ? c : fallback;
  }

  function tile(val, label, live) {
    return '<div class="pulse-tile' + (live ? ' is-live' : '') + '">'
      + '<div class="val">' + (live ? '<span class="live-dot"></span>' : '') + esc(val) + '</div>'
      + '<span class="lbl">' + esc(label) + '</span></div>';
  }

  function empty() { return '<p class="pulse-empty">' + esc(T.empty) + '</p>'; }

  function typeLabel(t) { return t === 'crew' ? T.crew : T.solo; }

  function renderTiles(d) {
    var today = d.today || {};
    var live = (d.live && d.live.active_now) || 0;
    var html = ''
      + tile(fmtInt(live), T.live, true)
      + tile(fmtInt(today.rides || 0), T.rides)
      + tile(fmt1(today.distance_km || 0), T.dist)
      + tile(fmtInt(today.elevation_m || 0), T.elev)
      + tile(fmtInt(today.signups || 0), T.signups)
      + tile(fmtInt(today.regions_taken || 0), T.regions)
      + tile(fmtInt(today.edges_taken || 0), T.edges)
      + tile(fmtInt(today.records_beaten || 0), T.records);
    $('pulse-tiles').innerHTML = html;
  }

  function row(rank, color, name, sub, metric) {
    var dot = color ? '<span class="pulse-dot" style="background:' + safeColor(color, '#7C8CA3') + '"></span>' : '';
    var sb = sub ? ' <span class="pulse-sub">· ' + esc(sub) + '</span>' : '';
    return '<div class="pulse-row">'
      + (rank != null ? '<span class="pulse-rank">' + rank + '</span>' : '')
      + dot
      + '<span class="pulse-name">' + esc(name) + sb + '</span>'
      + (metric != null ? '<span class="pulse-metric">' + metric + '</span>' : '')
      + '</div>';
  }

  function renderTeams(list) {
    var el = $('pulse-teams');
    if (!list || !list.length) { el.innerHTML = empty(); return; }
    var h = '';
    list.forEach(function (t, i) {
      h += row(i + 1, t.faction_color, t.name, typeLabel(t.type),
        fmtInt(t.edges) + ' ' + T.edgesW);
    });
    el.innerHTML = h;
  }

  function renderRegions(list) {
    var el = $('pulse-regions');
    if (!list || !list.length) { el.innerHTML = empty(); return; }
    var h = '';
    list.forEach(function (r) {
      var pct = Math.round((r.held_fraction || 0) * 100) + T.heldPct;
      h += row(fmtTime(r.at) || null, r.faction_color, r.region,
        r.owner, pct);
    });
    el.innerHTML = h;
  }

  function renderPioneers(list) {
    var el = $('pulse-pioneers');
    if (!list || !list.length) { el.innerHTML = empty(); return; }
    var h = '';
    list.forEach(function (p, i) {
      h += row(i + 1, p.faction_color, p.name, typeLabel(p.type),
        fmtInt(p.edges) + ' ' + T.newEdges);
    });
    el.innerHTML = h;
  }

  function renderRecords(list) {
    var el = $('pulse-records');
    if (!list || !list.length) { el.innerHTML = empty(); return; }
    var h = '';
    list.forEach(function (r) {
      h += row(fmtTime(r.at) || null, null, r.actor, r.region || null, '★');
    });
    el.innerHTML = h;
  }

  function renderHot(list) {
    var el = $('pulse-hot');
    if (!list || !list.length) { el.innerHTML = empty(); return; }
    var h = '';
    list.forEach(function (r, i) {
      h += row(i + 1, null, r.region, null, fmtInt(r.flips) + ' ' + T.flips);
    });
    el.innerHTML = h;
  }

  function renderFactions(list) {
    var el = $('pulse-factions');
    if (!list || !list.length) { el.innerHTML = empty(); return; }
    var h = '';
    list.forEach(function (f) {
      var pct = Math.round((f.share || 0) * 100);
      var col = safeColor(f.color, '#00E5FF');
      h += '<div class="pulse-fac">'
        + '<div class="pulse-fac-head">'
        + '<span class="pulse-fac-name"><span class="pulse-dot" style="background:' + col + '"></span> ' + esc(f.name) + '</span>'
        + '<span class="pulse-fac-val">' + pct + '% · ' + fmt1(f.held_length_km) + ' km</span>'
        + '</div>'
        + '<div class="pulse-bar"><span style="width:' + pct + '%;background:' + col + '"></span></div>'
        + '</div>';
    });
    el.innerHTML = h;
  }

  function feedText(ev) {
    var def = FEED[ev.type];
    if (!def) { return null; }
    var actor = ev.actor ? '<b>' + esc(ev.actor) + '</b>' : (DE ? 'Jemand' : 'Someone');
    var region = ev.region ? esc(ev.region) : '';
    var regionSuffix = ev.region ? (DE ? ' in ' : ' in ') + '<b>' + region + '</b>' : '';
    var txt = def[1]
      .replace('{a}', actor)
      .replace('{R}', region ? '<b>' + region + '</b>' : (DE ? 'ein Gebiet' : 'a region'))
      .replace('{r}', regionSuffix);
    return { icon: def[0], text: txt };
  }

  function renderFeed(list) {
    var el = $('pulse-feed');
    if (!list || !list.length) { el.innerHTML = empty(); return; }
    var h = '';
    list.forEach(function (ev) {
      var ft = feedText(ev);
      if (!ft) { return; }
      h += '<div class="pulse-feed-row">'
        + '<span class="pulse-feed-time">' + (fmtTime(ev.at) || T.now) + '</span>'
        + '<span class="pulse-feed-icon">' + ft.icon + '</span>'
        + '<span class="pulse-feed-text">' + ft.text + '</span>'
        + '</div>';
    });
    el.innerHTML = h || empty();
  }

  function render(d) {
    renderTiles(d);
    renderRegions(d.region_report);
    renderTeams(d.team_ranking);
    renderFactions(d.factions);
    renderPioneers(d.pioneers);
    renderRecords(d.records);
    renderHot(d.hot_regions);
    renderFeed(d.feed);
    var st = $('pulse-status');
    if (st) { st.textContent = T.updated + ' · ' + fmtTime(d.generated_at || new Date().toISOString()); }
  }

  function load() {
    fetch(ENDPOINT, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(render)
      .catch(function () {
        var st = $('pulse-status');
        if (st) { st.textContent = T.error; }
      });
  }

  load();
  window.setInterval(load, REFRESH_MS);
})();
