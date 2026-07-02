<?php
/** Hero + stat strip. Expects components.php already included. */

$stats = [
  ['4,127', 'Riders Online', 'cyan', '+18% wk'],
  ['892,304', 'KM Conquered', 'lime', null],
  ['1,204', 'Territories', 'magenta', '+37 today'],
  ['216', 'Active Crews', 'neutral', null],
];
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
      <div style="margin-bottom:22px"><?= cr_badge('Launch Phase // Season 03', 'cyan', true) ?></div>
      <h1 class="cr-display">Ride real roads.<br /><span class="cr-neon">Claim the grid.</span></h1>
      <p class="cr-lead">CyberRide turns your city into contested territory. Every kilometer you ride
        captures sectors for your crew — and maps surface, traffic and hazards for every rider
        behind you. Ingress, powered by your bike.</p>
      <div class="hero__ctas">
        <?= cr_button('Get the iOS App', ['size' => 'lg', 'variant' => 'primary', 'icon' => 'apple', 'href' => '#']) ?>
        <button class="trailer-box" id="trailerOpen">
          <span class="play"><?= cr_icon('play', 18) ?></span>
          <span style="text-align:left">
            <span class="t1">Watch Trailer</span>
            <span class="t2">02:14 // SEASON 03</span>
          </span>
        </button>
      </div>
      <div class="hero__badges">
        <span>✓ FREE — NO SUBSCRIPTION</span>
        <span>✓ WORKS OFFLINE</span>
        <span>✓ GDPR-SAFE</span>
      </div>
    </div>
  </div>
  <div class="hero__fade"></div>
</section>

<section class="cr-wrap" style="position:relative;z-index:5;margin-top:-40px">
  <div class="stat-strip">
    <?php foreach ($stats as $s): ?>
      <div class="cell"><?= cr_stat($s[0], $s[1], $s[2], $s[3]) ?></div>
    <?php endforeach; ?>
  </div>
</section>
