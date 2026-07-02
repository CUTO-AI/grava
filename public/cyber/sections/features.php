<?php
/** Two-feature section. */
$feats = [
  [
    'accent' => 'magenta', 'icon' => 'swords', 'kicker' => '// 01 — TERRITORY',
    'title' => 'Conquer Your Region',
    'body'  => 'Ride real streets to claim their sectors. Join a crew, defend your turf, climb the leaderboard. Every ride redraws the map — solo or in a duel with the crew across town.',
    'points' => ['Claim sectors by riding', 'Crews & live leaderboards', 'Seasonal territory resets'],
    'visual' => 'territory',
  ],
  [
    'accent' => 'cyan', 'icon' => 'radio', 'kicker' => '// 02 — INTEL',
    'title' => 'Auto-Map the Roads',
    'body'  => 'Start recording and just ride. CyberRide reads surface quality and traffic automatically via your radar taillight — no input needed. The data everyone else is missing, built by the community.',
    'points' => ['Surface & traffic, hands-free', 'Community hazard pins', 'Check any route before you go'],
    'visual' => 'intel',
  ],
];
?>
<section class="cr-section cr-wrap" id="features">
  <div class="cr-sechead">
    <div class="cr-kicker" style="margin-bottom:14px">// TWO SYSTEMS, ONE RIDE</div>
    <h2 class="cr-h2" style="margin:0 0 14px">A game layered over the real world</h2>
    <p class="cr-lead" style="margin:0;color:var(--text-muted)">CyberRide runs while you ride. Play for territory; leave better maps behind you.</p>
  </div>
  <div class="feature-grid">
    <?php foreach ($feats as $f): ?>
      <?= cr_card_open($f['accent'], true, 'feature-card') ?>
        <div class="body">
          <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px">
            <span class="ico"><?= cr_icon($f['icon'], 22) ?></span>
            <div class="cr-kicker" style="color:var(--<?= $f['accent'] ?>-500)"><?= htmlspecialchars($f['kicker']) ?></div>
          </div>
          <h3><?= htmlspecialchars($f['title']) ?></h3>
          <p style="margin:0 0 20px;color:var(--text-body);font-size:16px;line-height:1.6"><?= htmlspecialchars($f['body']) ?></p>
          <ul>
            <?php foreach ($f['points'] as $p): ?>
              <li><?= cr_icon('chevron-right', 16) ?><?= htmlspecialchars($p) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="visual-wrap">
          <?php if ($f['visual'] === 'territory'): ?>
            <div class="hud-visual">
              <div class="cr-grid-bg" style="position:absolute;inset:0;background-size:32px 32px;opacity:.6"></div>
              <span style="position:absolute;left:18%;top:30%;width:90px;height:70px;background:var(--fill-lime);border:1.5px solid var(--lime-500);box-shadow:var(--glow-lime);transform:skewX(-14deg)"></span>
              <span style="position:absolute;left:46%;top:50%;width:74px;height:60px;background:var(--fill-cyan);border:1.5px solid var(--cyan-500);transform:skewX(-14deg)"></span>
              <span style="position:absolute;left:62%;top:22%;width:60px;height:50px;background:var(--fill-magenta);border:1.5px solid var(--magenta-500);transform:skewX(-14deg)"></span>
              <div style="position:absolute;left:16px;right:16px;bottom:16px">
                <div style="display:flex;justify-content:space-between;margin-bottom:8px">
                  <span class="cr-kicker" style="color:var(--text-muted)">Region Control — Waldkraiburg</span>
                  <span style="font-family:var(--font-mono);font-size:12px;font-weight:700;color:var(--lime-500)">68%</span>
                </div>
                <div style="position:relative;height:10px;background:var(--void-600);border:1px solid var(--line-cyan);overflow:hidden;clip-path:polygon(0 0,calc(100% - 6px) 0,100% 6px,100% 100%,6px 100%,0 calc(100% - 6px))">
                  <div style="position:absolute;inset:0;width:68%;background:var(--lime-500);box-shadow:var(--glow-lime);background-image:repeating-linear-gradient(90deg,rgba(4,6,11,.55) 0 2px,transparent 2px 9px)"></div>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div class="hud-visual">
              <div class="cr-scanlines" style="position:absolute;inset:0"></div>
              <svg viewBox="0 0 400 200" preserveAspectRatio="none" style="position:absolute;inset:0;width:100%;height:100%">
                <polyline points="10,150 90,120 150,140 220,70 300,90 390,40" fill="none" stroke="var(--cyan-500)" stroke-width="2.5" style="filter:drop-shadow(0 0 6px var(--cyan-500))"></polyline>
              </svg>
              <span class="hud-tag" style="left:52%;top:30%;border:1px solid var(--amber-500);color:var(--amber-500)"><?= cr_icon('triangle-alert', 13) ?> ROUGH SURFACE</span>
              <span class="hud-tag" style="left:20%;top:62%;border:1px solid var(--lime-500);color:var(--lime-500)"><?= cr_icon('check', 13) ?> SMOOTH</span>
              <div style="position:absolute;right:14px;bottom:14px;display:flex;align-items:center;gap:8px;color:var(--text-muted);font-family:var(--font-mono);font-size:11px"><?= cr_icon('radio', 14, 'var(--cyan-500)') ?> RADAR TAILLIGHT · AUTO</div>
            </div>
          <?php endif; ?>
        </div>
      <?= cr_card_close() ?>
    <?php endforeach; ?>
  </div>
</section>
