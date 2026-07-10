<?php $e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8')); ?>
</main>
<footer class="site-footer">
  <div class="cr-wrap">
    <div class="cols">
      <div class="about">
        <a class="brand" href="/" style="margin-bottom:16px">
          <span class="glyph" style="width:30px;height:30px"><b style="font-size:16px">C</b></span>
          <span class="word" style="font-size:20px">CYBER<span class="r">RIDE</span></span>
        </a>
        <p><?= $e($T['footer']['tagline']) ?></p>
        <div class="socials">
          <a class="cr-iconbtn" href="https://instagram.com/gravaapp" aria-label="Instagram"><?= cr_icon('camera', 17) ?></a>
          <a class="cr-iconbtn" href="https://twitter.com/gravaapp" aria-label="X / Twitter"><?= cr_icon('at-sign', 17) ?></a>
          <a class="cr-iconbtn" href="https://www.strava.com/clubs/gravaworld" aria-label="Strava"><?= cr_icon('activity', 17) ?></a>
        </div>
      </div>
      <?php foreach ($T['footer']['cols'] as $h => $links): ?>
        <div class="col">
          <h4 class="cr-kicker" style="color:var(--text-muted)"><?= $e($h) ?></h4>
          <ul>
            <?php foreach ($links as $l): ?><li><a href="<?= $e($l[1]) ?>"><?= $e($l[0]) ?></a></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="legal">
      <span><?= $e($T['footer']['legalL']) ?></span>
      <span><?= $e($T['footer']['legalR']) ?></span>
    </div>
  </div>
</footer>

<!-- Trailer lightbox -->
<div class="modal" id="trailerModal" aria-hidden="true">
  <div class="frame">
    <div class="hero__bg">
      <div class="hero__grid cr-grid-bg"></div>
      <div class="cr-floor-glow" style="position:absolute;inset:0"></div>
      <div class="cr-scanlines" style="position:absolute;inset:0;opacity:.6"></div>
    </div>
    <div class="center">
      <div>
        <span class="play-lg"><?= cr_icon('play', 32) ?></span>
        <div class="cap"><?= $e($T['trailerCaption']) ?></div>
      </div>
    </div>
    <div class="badge-pos"><?= cr_badge('CYBERRIDE // 02:14', 'cyan', true) ?></div>
    <button class="cr-iconbtn close" id="trailerClose" aria-label="Close"><?= cr_icon('x', 18) ?></button>
  </div>
</div>

<?php require dirname(__DIR__, 3) . '/views/web/partials/consent-banner.php'; ?>

<script src="<?= $e($CR_ASSETS) ?>/lucide.min.js"></script>
<script src="<?= $e($CR_ASSETS) ?>/site.js"></script>
<script src="<?= $e($CR_ASSETS) ?>/pulse-teaser.js"></script>
<!-- Leaflet + Reviere-Karte (Startseite): identischer Stack wie /gebiete/karte -->
<script src="/assets/vendor/leaflet/leaflet.js"></script>
<script src="/assets/js/map-core.js"></script>
<script src="/assets/js/map-regions.js"></script>
</body>
</html>
