<?php
/** In-game updates / patch notes. Erwartet components.php + $T im Scope. */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
$U = $T['updates'];
?>
<section class="cr-section cr-wrap cr-wrap--slim" id="updates">
  <div class="cr-sechead">
    <div class="cr-kicker" style="margin-bottom:14px"><?= $e($U['kicker']) ?></div>
    <h2 class="cr-h2" style="margin:0 0 14px"><?= $e($U['h2']) ?></h2>
    <p class="cr-lead" style="margin:0;color:var(--text-muted)"><?= $e($U['lead']) ?></p>
  </div>
  <div style="display:flex;flex-direction:column;gap:20px">
    <?php foreach ($U['releases'] as $r): ?>
      <div class="cr-card update-row" style="padding:0">
        <div class="grid">
          <div class="ver">
            <div class="v"><?= $e($r['v']) ?></div>
            <div class="d"><?= $e($r['date']) ?></div>
          </div>
          <div class="notes">
            <h3><?= $e($r['title']) ?></h3>
            <ul>
              <?php foreach ($r['notes'] as $n): ?>
                <li><span><?= cr_badge($n[0], $n[1]) ?></span><span class="txt"><?= $e($n[2]) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
