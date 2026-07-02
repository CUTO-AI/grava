<?php
/** Hero + stat strip. Erwartet components.php + $T (inc/lang.php) im Scope. */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
$H = $T['hero'];
?>
<section class="hero" id="top">
  <div class="hero__bg">
    <div class="hero__grid cr-grid-bg"></div>
    <div class="hero__floor"><div class="cr-grid-bg"></div></div>
    <span class="hero__sector" style="left:58%;top:44%;width:120px;height:120px;background:var(--fill-lime);border:1.5px solid var(--lime-500);box-shadow:var(--glow-lime)"></span>
    <span class="hero__sector" style="left:72%;top:30%;width:84px;height:84px;background:var(--fill-cyan);border:1.5px solid var(--cyan-500);box-shadow:var(--glow-cyan-sm);animation-delay:.8s"></span>
    <span class="hero__sector" style="left:64%;top:60%;width:70px;height:70px;background:var(--fill-magenta);border:1.5px solid var(--magenta-500);animation-delay:1.6s"></span>
    <div class="hero__sweep"></div>
    <div class="cr-floor-glow" style="position:absolute;inset:0"></div>
    <div class="hero__scrim"></div>
    <div class="cr-scanlines" style="position:absolute;inset:0;opacity:.6"></div>
  </div>
  <div class="hero__inner">
    <div class="hero__col">
      <div style="margin-bottom:22px"><?= cr_badge($H['badge'], 'cyan', true) ?></div>
      <h1 class="cr-display"><?= $e($H['title1']) ?><br /><span class="cr-neon"><?= $e($H['title2']) ?></span></h1>
      <p class="cr-lead"><?= $e($H['lead']) ?></p>
      <div class="hero__ctas">
        <?= cr_button($H['ctaApp'], ['size' => 'lg', 'variant' => 'primary', 'icon' => 'apple', 'href' => '#']) ?>
        <button class="trailer-box" id="trailerOpen">
          <span class="play"><?= cr_icon('play', 18) ?></span>
          <span style="text-align:left">
            <span class="t1"><?= $e($H['trailer']) ?></span>
            <span class="t2"><?= $e($H['trailerMeta']) ?></span>
          </span>
        </button>
      </div>
      <div class="hero__badges">
        <?php foreach ($H['badges'] as $b): ?><span><?= $e($b) ?></span><?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="hero__fade"></div>
</section>

<section class="cr-wrap" style="position:relative;z-index:5;margin-top:-40px">
  <div class="stat-strip">
    <?php foreach ($H['stats'] as $s): ?>
      <div class="cell"><?= cr_stat($s[0], $s[1], $s[2], $s[3]) ?></div>
    <?php endforeach; ?>
  </div>
</section>
