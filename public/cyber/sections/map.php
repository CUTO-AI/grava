<?php
/**
 * Live-Karten-Section — direkt unter dem Pulse-Teaser. Zeigt die eroberten
 * Gebiete als dunkle Besitz-Choroplethen-Karte, identisch zu /gebiete/karte
 * (map-regions.js + regions-map.css): OSM-Raster invertiert (dunkel), Gebiete
 * nach Besitzer eingefärbt, Klick öffnet ein Detail-Panel. Der Start-Zoom
 * richtet sich nach der Weltregion des Aufrufs (Europa vs. Nordamerika) —
 * Auflösung in \App\Support\RequestRegion (CF-IPCountry, sonst Browsersprache).
 * Daten holt map-regions.js same-origin aus /api/v1/game/regions (CSP-konform).
 * Erwartet components.php + $T + $CR_LANG (inc/lang.php) im Scope.
 */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
$M = $T['map'];
$view = \App\Support\RequestRegion::fromGlobals();
$lang = (($CR_LANG ?? 'en') === 'de') ? 'de' : 'en';

// Detail-Panel-Labels für map-regions.js (data-i18n) — zweisprachig wie die
// übrige Landing ($T). Nur im Panel sichtbar (Klick auf ein Gebiet).
$L = [
    'de' => [
        'level2' => 'Land', 'level4' => 'Bundesland', 'level6' => 'Landkreis',
        'level8' => 'Gemeinde', 'level_default' => 'Gebiet', 'free' => 'frei',
        'contested' => 'Umkämpft', 'owned' => 'Erobert', 'leading' => 'aktuell führend',
        'ownedBy' => 'Beherrscht von', 'territory' => 'Reviere gesamt', 'edges' => 'Kanten',
        'threshold' => 'Schwelle', 'leaderboard' => 'Bestenliste im Gebiet',
        'subareas' => 'Unter-Gebiete', 'close' => 'Schließen',
    ],
    'en' => [
        'level2' => 'Country', 'level4' => 'State', 'level6' => 'District',
        'level8' => 'Municipality', 'level_default' => 'Area', 'free' => 'free',
        'contested' => 'Contested', 'owned' => 'Conquered', 'leading' => 'currently leading',
        'ownedBy' => 'Held by', 'territory' => 'Territories total', 'edges' => 'Edges',
        'threshold' => 'Threshold', 'leaderboard' => 'Leaderboard in area',
        'subareas' => 'Sub-areas', 'close' => 'Close',
    ],
];
$i18nJson = htmlspecialchars(json_encode($L[$lang], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>
<section class="cr-wrap cr-section landing-map-section" id="map" style="position:relative;z-index:4">
  <?= cr_card_open('lime', true, 'landing-map-section__card', 'padding:28px 26px') ?>
    <div style="margin-bottom:22px">
      <div class="cr-kicker"><span class="badge-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--lime-500);box-shadow:var(--glow-lime);margin-right:8px;vertical-align:middle"></span><?= $e($M['kicker']) ?></div>
      <h2 class="cr-h2" style="margin:.25em 0 0"><?= $e($M['h2']) ?></h2>
      <p class="cr-lead" style="margin:.5em 0 0;max-width:60ch"><?= $e($M['lead']) ?></p>
    </div>
    <div class="region-map-wrap">
      <div id="region-map" class="region-map"
           role="application"
           aria-label="<?= $e($M['aria']) ?>"
           data-locale="<?= $e($lang) ?>"
           data-i18n="<?= $i18nJson ?>"
           data-init-lat="<?= $e((string) $view['lat']) ?>"
           data-init-lon="<?= $e((string) $view['lon']) ?>"
           data-init-zoom="<?= $e((string) $view['zoom']) ?>"
           data-region="<?= $e($view['region']) ?>">
        <noscript><p style="padding:20px;margin:0;color:var(--text-muted)"><?= $e($M['noscript']) ?></p></noscript>
      </div>
      <aside id="region-panel" class="region-panel" aria-live="polite"></aside>
    </div>
  <?= cr_card_close() ?>
</section>
