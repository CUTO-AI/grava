<?php
/**
 * Pulse-Teaser — Live-Streifen „Heute im Spiel" direkt unter dem Hero, verlinkt
 * auf /pulse. Zahlen füllt pulse-teaser.js same-origin aus GET /api/v1/pulse
 * (CSP-konform); ohne JS bleibt ein neutraler „—"-Platzhalter stehen.
 * Erwartet components.php + $T (inc/lang.php) im Scope.
 */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
$P = $T['pulse'];
$cell = static function (string $key, string $label, string $accent) use ($e): string {
    return '<div class="cell"><div class="stat">'
        . '<span class="lab">' . $e($label) . '</span>'
        . '<span class="val is-' . $accent . '" data-pulse="' . $e($key) . '">—</span>'
        . '</div></div>';
};
?>
<section class="cr-wrap cr-section pulse-teaser" id="pulse-teaser" style="position:relative;z-index:5">
  <?= cr_card_open('cyan', true, 'pulse-teaser__card', 'padding:28px 26px') ?>
    <div class="pulse-teaser__head" style="display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap;margin-bottom:22px">
      <div style="min-width:min(100%,420px)">
        <div class="cr-kicker"><span class="badge-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--cyan-500);box-shadow:var(--glow-cyan-sm);margin-right:8px;vertical-align:middle"></span><?= $e($P['kicker']) ?></div>
        <h2 class="cr-h2" style="margin:.25em 0 0"><?= $e($P['h2']) ?></h2>
        <p class="cr-lead" style="margin:.5em 0 0;max-width:56ch"><?= $e($P['lead']) ?></p>
      </div>
      <?= cr_button($P['cta'], ['variant' => 'primary', 'iconRight' => 'arrow-right', 'href' => '/pulse']) ?>
    </div>
    <div class="stat-strip pulse-teaser__stats">
      <?= $cell('active_now', $P['live'], 'cyan') ?>
      <?= $cell('rides', $P['rides'], 'lime') ?>
      <?= $cell('regions_taken', $P['regions'], 'magenta') ?>
      <?= $cell('records_beaten', $P['records'], 'cyan') ?>
    </div>
  <?= cr_card_close() ?>
</section>
