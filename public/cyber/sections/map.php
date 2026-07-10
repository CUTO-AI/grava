<?php
/**
 * Live-Karten-Section — direkt unter dem Pulse-Teaser. Zeigt die eroberten
 * Gebiete (Game-Edges) auf einer Leaflet-Karte. Der Start-Zoom richtet sich
 * nach der Weltregion des Aufrufs (Europa vs. Nordamerika) — Auflösung in
 * \App\Support\RequestRegion (CF-IPCountry, sonst Browsersprache).
 * Daten holt landing-map.js same-origin aus GET /api/v1/game/edges (CSP-konform).
 * Erwartet components.php + $T (inc/lang.php) im Scope.
 */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
$M = $T['map'];
$view = \App\Support\RequestRegion::fromGlobals();
?>
<section class="cr-wrap cr-section landing-map-section" id="map" style="position:relative;z-index:4">
  <?= cr_card_open('lime', true, 'landing-map-section__card', 'padding:28px 26px') ?>
    <div style="margin-bottom:22px">
      <div class="cr-kicker"><span class="badge-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--lime-500);box-shadow:var(--glow-lime);margin-right:8px;vertical-align:middle"></span><?= $e($M['kicker']) ?></div>
      <h2 class="cr-h2" style="margin:.25em 0 0"><?= $e($M['h2']) ?></h2>
      <p class="cr-lead" style="margin:.5em 0 0;max-width:60ch"><?= $e($M['lead']) ?></p>
    </div>
    <div id="landing-map" class="landing-map"
         role="application"
         aria-label="<?= $e($M['aria']) ?>"
         data-center-lat="<?= $e((string) $view['lat']) ?>"
         data-center-lon="<?= $e((string) $view['lon']) ?>"
         data-zoom="<?= $e((string) $view['zoom']) ?>"
         data-region="<?= $e($view['region']) ?>"
         style="height:clamp(340px,52vh,560px);border-radius:14px;overflow:hidden;border:1px solid rgba(0,229,255,.18)">
      <noscript><p style="padding:20px;margin:0;color:var(--text-muted)"><?= $e($M['noscript']) ?></p></noscript>
    </div>
  <?= cr_card_close() ?>
</section>
