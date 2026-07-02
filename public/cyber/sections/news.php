<?php
/** News section. */
$news = [
  ['tag' => 'Community', 'tone' => 'cyan',    'date' => '30 JUN 2026', 'icon' => 'users',  'title' => 'Waldkraiburg Falls to Crew NEONWOLVES', 'excerpt' => 'After a 116km overnight push, NEONWOLVES flipped the entire eastern sector. The map hasn’t looked this contested since launch.'],
  ['tag' => 'Feature',   'tone' => 'magenta', 'date' => '24 JUN 2026', 'icon' => 'route',  'title' => 'Import Your Komoot Routes', 'excerpt' => 'Pull any GPX into CyberRide and see community surface data, traffic and hazards before you roll out.'],
  ['tag' => 'Milestone', 'tone' => 'lime',    'date' => '18 JUN 2026', 'icon' => 'trophy', 'title' => '4,000 Riders Now on the Grid', 'excerpt' => 'The launch region crossed 4k active riders this week — nearly 900,000 km of roads mapped and counting.'],
];
?>
<section class="news-band" id="news">
  <div class="cr-wrap cr-section">
    <div class="news-head">
      <div class="cr-sechead" style="margin-bottom:0">
        <div class="cr-kicker" style="margin-bottom:14px">// LIVE FROM THE GRID</div>
        <h2 class="cr-h2" style="margin:0">News from the field</h2>
      </div>
      <?= cr_button('All News', ['variant' => 'ghost', 'size' => 'sm', 'iconRight' => 'arrow-right', 'href' => '#']) ?>
    </div>
    <div class="news-grid">
      <?php foreach ($news as $n): ?>
        <?= cr_card_open($n['tone'], true, 'news-card') ?>
          <div class="thumb">
            <div class="cr-grid-bg" style="position:absolute;inset:0;background-size:28px 28px;opacity:.5"></div>
            <div style="position:absolute;inset:0;background:radial-gradient(80% 80% at 70% 30%,var(--fill-<?= $n['tone'] ?>),transparent 70%)"></div>
            <span class="ico" style="color:var(--<?= $n['tone'] ?>-500)"><?= cr_icon($n['icon'], 54) ?></span>
            <span class="badge-pos"><?= cr_badge($n['tag'], $n['tone']) ?></span>
          </div>
          <div class="inner">
            <div class="date"><?= htmlspecialchars($n['date']) ?></div>
            <h3><?= htmlspecialchars($n['title']) ?></h3>
            <p><?= htmlspecialchars($n['excerpt']) ?></p>
            <a class="more" href="#" style="color:var(--<?= $n['tone'] ?>-500)">Read <?= cr_icon('arrow-right', 14) ?></a>
          </div>
        <?= cr_card_close() ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
