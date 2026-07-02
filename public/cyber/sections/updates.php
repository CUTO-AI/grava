<?php
/** In-game updates / patch notes. */
$releases = [
  ['v' => 'v2.4', 'date' => '01 JUL 2026', 'title' => 'Season 03 — The Reclamation', 'notes' => [
    ['NEW', 'lime', 'Seasonal territory reset — the map is wide open again.'],
    ['NEW', 'lime', 'Crew war rooms with live sector heat.'],
    ['BALANCE', 'cyan', 'Night rides now grant +25% capture bonus.'],
  ]],
  ['v' => 'v2.3', 'date' => '12 JUN 2026', 'title' => 'Radar Intel Overhaul', 'notes' => [
    ['NEW', 'lime', 'Auto surface scoring from radar taillight.'],
    ['FIX', 'magenta', 'Offline uploads no longer drop hazard pins.'],
  ]],
];
?>
<section class="cr-section cr-wrap cr-wrap--slim" id="updates">
  <div class="cr-sechead">
    <div class="cr-kicker" style="margin-bottom:14px">// PATCH NOTES</div>
    <h2 class="cr-h2" style="margin:0 0 14px">Updates from the game</h2>
    <p class="cr-lead" style="margin:0;color:var(--text-muted)">What shipped, what changed. Rider-facing changelog for every release.</p>
  </div>
  <div style="display:flex;flex-direction:column;gap:20px">
    <?php foreach ($releases as $r): ?>
      <div class="cr-card update-row" style="padding:0">
        <div class="grid">
          <div class="ver">
            <div class="v"><?= htmlspecialchars($r['v']) ?></div>
            <div class="d"><?= htmlspecialchars($r['date']) ?></div>
          </div>
          <div class="notes">
            <h3><?= htmlspecialchars($r['title']) ?></h3>
            <ul>
              <?php foreach ($r['notes'] as $n): ?>
                <li><span><?= cr_badge($n[0], $n[1]) ?></span><span class="txt"><?= htmlspecialchars($n[2]) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
