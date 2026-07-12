<?php
/**
 * „Über Karte"-Tab der Ranglisten (UserGrowth_Concept.md §4): interaktive
 * Gebiets-Karte (gleiche Gebiete/Zoom wie die Startseite). Klick auf ein Gebiet
 * öffnet ein Panel mit der windowed Nordstern-Aktivität (Gesamt/Solo/Crews,
 * 7/30 Tage) samt Solo- und Crew-Rangliste. Daten client-seitig same-origin aus
 * /api/v1/game/regions[/{id}][/activity] (map-regions.js, data-activity="1").
 *
 * @var array<string,mixed>|null $_authedUser
 */
$labels = [
    'level2'        => t('Land'),
    'level4'        => t('Bundesland'),
    'level6'        => t('Landkreis'),
    'level8'        => t('Gemeinde'),
    'level_default' => t('Gebiet'),
    'free'          => t('frei'),
    'contested'     => t('Umkämpft'),
    'owned'         => t('Erobert'),
    'leading'       => t('aktuell führend'),
    'ownedBy'       => t('Beherrscht von'),
    'territory'     => t('Reviere gesamt'),
    'edges'         => t('Kanten'),
    'threshold'     => t('Schwelle'),
    'leaderboard'   => t('Bestenliste im Gebiet'),
    'subareas'      => t('Unter-Gebiete'),
    'close'         => t('Schließen'),
    // Aktivitäts-Panel (Nordstern).
    'activity'      => t('Aktivität'),
    'actWindow'     => t('Zeitraum'),
    'act7'          => t('7 Tage'),
    'act30'         => t('30 Tage'),
    'actTotal'      => t('Aktive Fahrer'),
    'actSolo'       => t('Solo'),
    'actCrews'      => t('Crews'),
    'actSoloBoard'  => t('Solo-Rangliste'),
    'actCrewBoard'  => t('Crew-Rangliste'),
    'actNone'       => t('Keine Aktivität im Zeitraum.'),
];
$i18nJson = htmlspecialchars(json_encode($labels, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$locale = \App\Support\I18n::locale();
?>

<header class="page-header">
    <p class="cr-kicker"><?= t('Revierkampf · Gesamtwertung') ?></p>
    <h1><?= t('Ranglisten') ?></h1>
    <p class="muted">
        <?= t('Tippe ein Gebiet an — die Karte zeigt, wer in den letzten 7 oder 30 Tagen dort aktiv war (gesamt, solo und als Crew).') ?>
    </p>
</header>

<nav class="rank-tabs" aria-label="<?= te('Ranglisten-Kategorien') ?>">
    <a href="/rangliste/solo"       class="rank-tab"><?= t('Solo') ?></a>
    <a href="/rangliste/crews"      class="rank-tab"><?= t('Crews') ?></a>
    <a href="/rangliste/fraktionen" class="rank-tab"><?= t('Fraktionen') ?></a>
    <a href="/rangliste/karte"      class="rank-tab is-active"><?= t('Über Karte') ?></a>
</nav>

<div class="region-map-wrap">
    <div id="region-map"
         class="region-map"
         data-activity="1"
         data-locale="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>"
         data-i18n="<?= $i18nJson ?>"
         aria-label="<?= te('Gebiets-Karte') ?>"></div>
    <aside id="region-panel" class="region-panel" aria-live="polite"></aside>
</div>

<p class="muted map-hint">
    <span id="region-map-mode"><?= t('Karte wird geladen …') ?></span>
    · <?= t('Zoom rein für Landkreise und Gemeinden; wähle ein Gebiet für die Aktivitäts-Rangliste.') ?>
</p>
