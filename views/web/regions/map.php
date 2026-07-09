<?php
/**
 * Interaktive Reviere-Karte der Web-Auswertungen (/gebiete/karte).
 * Zoom-adaptive Choroplethen-Karte (Welt→Land→Bundesland→Landkreis→Gemeinde),
 * Gebiete nach Besitzer eingefärbt; Klick öffnet ein Detail-Panel (Besitzer,
 * km, Kanten, Schwelle, absteigende Bestenliste, Unter-Gebiete) wie in der App.
 * Daten client-seitig same-origin aus /api/v1/game/regions[/{id}] (map-regions.js).
 *
 * @var array<string,mixed>|null $_authedUser
 */
$labels = [
    'level2'       => t('Land'),
    'level4'       => t('Bundesland'),
    'level6'       => t('Landkreis'),
    'level8'       => t('Gemeinde'),
    'level_default'=> t('Gebiet'),
    'free'         => t('frei'),
    'contested'    => t('Umkämpft'),
    'owned'        => t('Erobert'),
    'leading'      => t('aktuell führend'),
    'ownedBy'      => t('Beherrscht von'),
    'territory'    => t('Reviere gesamt'),
    'edges'        => t('Kanten'),
    'threshold'    => t('Schwelle'),
    'leaderboard'  => t('Bestenliste im Gebiet'),
    'subareas'     => t('Unter-Gebiete'),
    'close'        => t('Schließen'),
];
$i18nJson = htmlspecialchars(json_encode($labels, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$locale = \App\Support\I18n::locale();
?>

<header class="page-header">
    <p class="cr-kicker"><?= t('Revierkampf · Karte') ?></p>
    <h1><?= t('Gebiets-Karte') ?></h1>
    <p class="muted">
        <?= t('Wer hält welches Gebiet? Zoom rein — die Karte zeigt Länder, Bundesländer, Landkreise und Gemeinden nach Besitz eingefärbt. Tippe ein Gebiet an für Details.') ?>
        <a href="/gebiete"><?= t('Zur Listenansicht') ?></a>
    </p>
</header>

<div class="region-map-wrap">
    <div id="region-map"
         class="region-map"
         data-locale="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>"
         data-i18n="<?= $i18nJson ?>"
         aria-label="<?= te('Gebiets-Karte') ?>"></div>
    <aside id="region-panel" class="region-panel" aria-live="polite"></aside>
</div>

<p class="muted map-hint">
    <span id="region-map-mode"><?= t('Karte wird geladen …') ?></span>
    · <?= t('Deckung eines Gebiets = gehaltener Anteil; freie Gebiete nur als feiner Umriss.') ?>
</p>
