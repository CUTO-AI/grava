<?php
/** News section. Erwartet components.php + $T im Scope. */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
$N = $T['news'];
?>
<section class="news-band" id="news">
  <div class="cr-wrap cr-section">
    <div class="news-head">
      <div class="cr-sechead" style="margin-bottom:0">
        <div class="cr-kicker" style="margin-bottom:14px"><?= $e($N['kicker']) ?></div>
        <h2 class="cr-h2" style="margin:0"><?= $e($N['h2']) ?></h2>
      </div>
      <?= cr_button($N['allNews'], ['variant' => 'ghost', 'size' => 'sm', 'iconRight' => 'arrow-right', 'href' => '#news']) ?>
    </div>
    <div class="news-grid">
      <?php foreach ($N['items'] as $n): ?>
        <?= cr_card_open($n['tone'], true, 'news-card') ?>
          <div class="thumb">
            <div class="cr-grid-bg" style="position:absolute;inset:0;background-size:28px 28px;opacity:.5"></div>
            <div style="position:absolute;inset:0;background:radial-gradient(80% 80% at 70% 30%,var(--fill-<?= $n['tone'] ?>),transparent 70%)"></div>
            <span class="ico" style="color:var(--<?= $n['tone'] ?>-500)"><?= cr_icon($n['icon'], 54) ?></span>
            <span class="badge-pos"><?= cr_badge($n['tag'], $n['tone']) ?></span>
          </div>
          <div class="inner">
            <div class="date"><?= $e($n['date']) ?></div>
            <h3><?= $e($n['title']) ?></h3>
            <p><?= $e($n['excerpt']) ?></p>
            <a class="more" href="#" style="color:var(--<?= $n['tone'] ?>-500)"><?= $e($N['read']) ?> <?= cr_icon('arrow-right', 14) ?></a>
          </div>
        <?= cr_card_close() ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
