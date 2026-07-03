<?php
/**
 * Öffentliche Reviere-Karte (Territorien) — zoom-adaptiv wie in der App:
 * Übersichts-Regionen → Landkreise → Gemeinden → einzelne Wege. Die Daten
 * kommen client-seitig same-origin aus den Spiel-Endpunkten (map-territory.js).
 *
 * @var array<string,mixed>|null $_authedUser
 */
$_pageStyles  = ['/assets/vendor/leaflet/leaflet.css'];
$_pageScripts = [
    '/assets/vendor/leaflet/leaflet.js',
    '/assets/js/map-core.js',
    '/assets/js/map-territory.js',
];
?>

<header class="page-header">
    <h1><?= t('Reviere-Karte') ?></h1>
    <p class="muted"><?= t('Wer hält welches Gebiet? Zoom rein — die Karte zeigt je nach Nähe grobe Regionen, dann Landkreise und Gemeinden, ganz nah einzelne Wege nach Besitz.') ?></p>
</header>

<div id="map" class="map map--full" aria-label="<?= te('Reviere-Karte') ?>"></div>

<p class="muted map-hint">
    <span id="map-mode"><?= t('Karte wird geladen …') ?></span>
</p>

<div class="map-legend" aria-hidden="true">
    <span><span class="swatch" style="background:#00E5FF"></span><?= t('Fraktion Blau') ?></span>
    <span><span class="swatch" style="background:#B6FF2E"></span><?= t('Fraktion Grün') ?></span>
    <span><span class="swatch" style="background:#FF1E6F"></span><?= t('Anderer Besitzer') ?></span>
    <span><span class="swatch" style="background:rgba(150,180,210,0.30)"></span><?= t('frei / kein Besitzer') ?></span>
</div>

<p class="muted">
    <?= t('Deckung eines Gebiets = wie viel davon gehalten wird. Freie Gebiete erscheinen nur als feiner Umriss und färben sich, sobald sie erobert werden.') ?>
</p>
