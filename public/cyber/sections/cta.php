<?php /** Full-bleed final CTA before footer. */ ?>
<section class="final-cta">
  <div class="bg cr-grid-bg cr-floor-glow"></div>
  <div class="cr-scanlines" style="position:absolute;inset:0"></div>
  <div class="glow"></div>
  <div class="inner">
    <div style="display:flex;justify-content:center;margin-bottom:22px"><?= cr_badge('Free during launch', 'lime', true) ?></div>
    <h2 class="cr-display">Ready to claim<br /><span class="cr-neon">your first sector?</span></h2>
    <p class="cr-lead">Download CyberRide, start recording, and turn tonight’s ride into territory. No subscription — the grid is waiting.</p>
    <div class="ctas">
      <?= cr_button('Download for iOS', ['size' => 'lg', 'variant' => 'primary', 'icon' => 'apple', 'href' => '#']) ?>
      <?= cr_button('Explore the Heatmap', ['size' => 'lg', 'variant' => 'ghost', 'iconRight' => 'arrow-right', 'href' => '#']) ?>
    </div>
    <div class="fine">IOS 16+ · ANDROID SOON · DE · AT · CH</div>
  </div>
</section>
