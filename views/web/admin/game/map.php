<?php
/** @var array<string,mixed>|null $_authedUser */
/** @var string|null $flash */

$_pageStyles  = ['/assets/vendor/leaflet/leaflet.css'];
$_pageScripts = [
    '/assets/vendor/leaflet/leaflet.js',
    '/assets/js/map-core.js',
    '/assets/js/map-game-admin.js',
];
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players"><?= t('Spieler') ?></a>
    <a href="/admin/game/player"><?= t('Spieler-Detail') ?></a>
    <a href="/admin/game/crews">Crews</a>
    <a href="/admin/game/edge">Inspector</a>
    <a href="/admin/game/map"><strong><?= t('Karte') ?></strong></a>
</nav>
<section class="card">
    <h1>Game · <?= t('Übersichtskarte') ?></h1>
    <p class="muted">
        <?= t('Kanten des sichtbaren Ausschnitts, eingefärbt nach gewähltem Kriterium.
        Klick auf eine Kante öffnet den Inspector. Beim Verschieben/Zoomen
        werden die Daten des Viewports nachgeladen.') ?>
    </p>
    <label class="inline-form">
        <?= t('Einfärben nach') ?>
        <select id="game-map-color">
            <option value="value"><?= t('Wert') ?></option>
            <option value="freshness"><?= t('Frische') ?></option>
            <option value="vulnerability"><?= t('Übernehmbarkeit') ?></option>
            <option value="owner">Owner</option>
            <option value="crew">Crew</option>
            <option value="faction"><?= t('Fraktion') ?></option>
        </select>
    </label>
</section>

<div id="map" class="map map--full"
     data-edges-url="/admin/game/edges.geojson"
     data-edge-base="/admin/game/edge/"></div>
<div id="map-legend" class="map-legend" hidden></div>

<p class="muted map-hint">
    <strong><?= t('Wert') ?></strong>: <?= t('hell → niedrig, kräftig → hoch (relativ zum Ausschnitt).') ?>
    <strong><?= t('Frische') ?></strong>: <?= t('rot = alt, grün = frisch (0–1).') ?>
    <strong><?= t('Übernehmbarkeit') ?></strong>: <?= t('grün = sicher (Owner klar vorn), rot =
    übernahmereif (Verfolger nah an der Übernahme-Schwelle).') ?>
    <strong>Owner</strong>: <?= t('feste Farbe je Fahrer (der die Kante erradelt hat), grau = niemand.') ?>
    <strong>Crew</strong>: <?= t('feste Farbe je Crew, grau = solo / keine Crew.') ?>
    <strong><?= t('Fraktion') ?></strong>: <?= t('echte Fraktionsfarbe, grau = keiner Fraktion zugeordnet.') ?>
</p>
