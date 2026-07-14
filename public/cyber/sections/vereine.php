<?php
/** Vereins-Teaser auf der Startseite. Erwartet components.php + $T im Scope. */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
$CL = $T['clubs'];
?>
<section class="cr-section cr-wrap" id="clubs">
  <?= cr_card_open('lime', false, 'club-teaser') ?>
    <div class="body" style="padding:34px 30px">
      <div class="cr-kicker" style="color:var(--lime-500);margin-bottom:14px"><?= $e($CL['kicker']) ?></div>
      <h2 class="cr-h2" style="margin:0 0 14px"><?= $e($CL['h2']) ?></h2>
      <p class="cr-lead" style="margin:0 0 20px;color:var(--text-muted);max-width:640px"><?= $e($CL['lead']) ?></p>
      <ul style="list-style:none;padding:0;margin:0 0 26px;display:grid;gap:12px">
        <?php foreach ($CL['points'] as $p): ?>
          <li style="display:flex;gap:10px;align-items:flex-start;color:var(--text-body);font-size:16px;line-height:1.5"><?= cr_icon('chevron-right', 18, 'var(--lime-500)') ?><span><?= $e($p) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?= cr_button($CL['cta'], ['size' => 'lg', 'variant' => 'primary', 'iconRight' => 'arrow-right', 'href' => '/vereine']) ?>
    </div>
  <?= cr_card_close() ?>
</section>
