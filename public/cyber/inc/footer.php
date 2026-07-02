</main>
<footer class="site-footer">
  <div class="cr-wrap">
    <div class="cols">
      <div class="about">
        <a class="brand" href="#top" style="margin-bottom:16px">
          <span class="glyph" style="width:30px;height:30px"><b style="font-size:16px">C</b></span>
          <span class="word" style="font-size:20px">CYBER<span class="r">RIDE</span></span>
        </a>
        <p>Ride, conquer, build the map. Surface &middot; Traffic &middot; Hazards — the data Komoot is missing.</p>
        <div class="socials">
          <a class="cr-iconbtn" href="#" aria-label="Instagram"><?= cr_icon('camera', 17) ?></a>
          <a class="cr-iconbtn" href="#" aria-label="X / Twitter"><?= cr_icon('at-sign', 17) ?></a>
          <a class="cr-iconbtn" href="#" aria-label="Strava"><?= cr_icon('activity', 17) ?></a>
        </div>
      </div>
      <?php
      $cols = [
        'Product'   => ['Features', 'Heatmap', 'Discover', 'Get the App'],
        'Community' => ['Crews', 'Leaderboards', 'News', 'Strava Club'],
        'Legal'     => ['Privacy', 'Terms', 'Imprint', 'GDPR'],
      ];
      foreach ($cols as $h => $links): ?>
        <div class="col">
          <h4 class="cr-kicker" style="color:var(--text-muted)"><?= htmlspecialchars($h) ?></h4>
          <ul>
            <?php foreach ($links as $l): ?><li><a href="#"><?= htmlspecialchars($l) ?></a></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="legal">
      <span>© 2026 CYBERRIDE · ALL SECTORS RESERVED</span>
      <span>MADE FOR RIDERS · DE / AT / CH</span>
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
        <div class="cap">Trailer placeholder — drop the Season 03 film here</div>
      </div>
    </div>
    <div class="badge-pos"><?= cr_badge('CYBERRIDE // 02:14', 'cyan', true) ?></div>
    <button class="cr-iconbtn close" id="trailerClose" aria-label="Close"><?= cr_icon('x', 18) ?></button>
  </div>
</div>

<script src="<?= $CR_ASSETS ?>/lucide.min.js"></script>
<script src="<?= $CR_ASSETS ?>/site.js"></script>
</body>
</html>
