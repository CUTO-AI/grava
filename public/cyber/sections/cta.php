<?php
/** Full-bleed final CTA before footer. Erwartet components.php + $T im Scope. */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
$C = $T['cta'];
?>
<section class="final-cta">
  <div class="bg cr-grid-bg cr-floor-glow"></div>
  <div class="cr-scanlines" style="position:absolute;inset:0"></div>
  <div class="glow"></div>
  <div class="inner">
    <div style="display:flex;justify-content:center;margin-bottom:22px"><?= cr_badge($C['badge'], 'lime', true) ?></div>
    <h2 class="cr-display"><?= $e($C['title1']) ?><br /><span class="cr-neon"><?= $e($C['title2']) ?></span></h2>
    <p class="cr-lead"><?= $e($C['lead']) ?></p>
    <div class="ctas">
      <?= cr_button($C['ctaApp'], ['size' => 'lg', 'variant' => 'primary', 'icon' => 'apple', 'href' => '#', 'attrs' => 'data-ga-event="appstore_click" data-ga-source="landing_cta"']) ?>
      <?= cr_button($C['ctaHeatmap'], ['size' => 'lg', 'variant' => 'ghost', 'iconRight' => 'arrow-right', 'href' => '/heatmap']) ?>
    </div>
    <div class="fine"><?= $e($C['fine']) ?></div>
  </div>
</section>
