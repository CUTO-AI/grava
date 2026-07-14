<?php
/**
 * Öffentliche Vereins-Seite (/vereine) — Cyber-Design, zweisprachig EN/DE.
 * Wird von ClubPublicController::page() gerendert. Erwartet im Scope:
 *   $CR_ASSETS, $CR_CSRF (string), $CR_FLASH (['ok'=>bool,'key'=>string]|null), $CR_OLD (array)
 */
require __DIR__ . '/inc/lang.php';                 // $CR_LANG + $T
$CR_ASSETS = $CR_ASSETS ?? '/cyber/assets';
$CR_PATH   = '/vereine';                           // korrektes Canonical/hreflang
$T['meta'] = $T['clubs']['meta'];                  // Seiten-eigener Title/Description/OG
require __DIR__ . '/inc/header.php';               // liefert $e + cr_* (components.php)

$CL    = $T['clubs'];
$csrf  = isset($CR_CSRF) ? (string)$CR_CSRF : '';
$flash = $CR_FLASH ?? null;                        // ['ok'=>bool,'key'=>'ok'|'err']
$old   = isset($CR_OLD) && is_array($CR_OLD) ? $CR_OLD : [];
$ov    = static fn(string $k): string => isset($old[$k]) ? $e((string)$old[$k]) : '';
$H = $CL['hero']; $B = $CL['benefits']; $FM = $CL['form'];
$icons = ['magenta', 'cyan', 'lime'];
?>
<section class="cr-section cr-wrap" style="padding-top:64px">
  <div style="max-width:720px">
    <div style="margin-bottom:20px"><?= cr_badge($H['badge'], 'lime', true) ?></div>
    <h1 class="cr-display" style="margin:0 0 18px"><?= $e($H['title1']) ?> <span class="cr-neon"><?= $e($H['title2']) ?></span></h1>
    <p class="cr-lead" style="margin:0 0 28px;color:var(--text-muted)"><?= $e($H['lead']) ?></p>
    <div style="display:flex;flex-wrap:wrap;gap:14px">
      <?= cr_button($H['formCta'], ['size' => 'lg', 'variant' => 'primary', 'iconRight' => 'arrow-down', 'href' => '#anmelden']) ?>
      <?= cr_button($H['appCta'], ['size' => 'lg', 'variant' => 'ghost', 'icon' => 'apple', 'href' => '#', 'attrs' => 'data-ga-event="appstore_click" data-ga-source="vereine"']) ?>
    </div>
  </div>
</section>

<section class="cr-section cr-wrap" id="benefits">
  <div class="cr-sechead">
    <div class="cr-kicker" style="margin-bottom:14px"><?= $e($B['kicker']) ?></div>
    <h2 class="cr-h2" style="margin:0"><?= $e($B['h2']) ?></h2>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px">
    <?php foreach ($B['items'] as $i => $it): $accent = $icons[$i % 3]; ?>
      <?= cr_card_open($accent, false, 'benefit-card') ?>
        <div class="body" style="padding:24px">
          <span class="ico" style="display:inline-flex;margin-bottom:14px"><?= cr_icon($it['icon'] ?? 'check', 24, 'var(--' . $accent . '-500)') ?></span>
          <h3 style="margin:0 0 8px;font-size:18px"><?= $e($it['title']) ?></h3>
          <p style="margin:0;color:var(--text-body);font-size:15px;line-height:1.6"><?= $e($it['body']) ?></p>
        </div>
      <?= cr_card_close() ?>
    <?php endforeach; ?>
  </div>
</section>

<section class="cr-section cr-wrap" id="anmelden">
  <?= cr_card_open('cyan', false, 'club-form') ?>
    <div class="body" style="padding:34px 30px;max-width:560px">
      <div class="cr-kicker" style="color:var(--cyan-500);margin-bottom:14px"><?= $e($FM['kicker']) ?></div>
      <h2 class="cr-h2" style="margin:0 0 12px"><?= $e($FM['h2']) ?></h2>
      <p class="cr-lead" style="margin:0 0 22px;color:var(--text-muted)"><?= $e($FM['lead']) ?></p>

      <?php if ($flash !== null && !empty($flash['key'])): ?>
        <div role="status" style="margin:0 0 20px;padding:12px 14px;border-radius:8px;font-size:15px;<?= !empty($flash['ok'])
              ? 'background:rgba(182,255,46,.10);border:1px solid var(--lime-500);color:var(--lime-500)'
              : 'background:rgba(255,30,111,.10);border:1px solid var(--magenta-500);color:var(--magenta-500)' ?>">
          <?= $e($FM[$flash['key']] ?? '') ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/vereine/interesse" class="club-lead-form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <!-- Honeypot: von Menschen leer gelassen, von Bots befüllt -->
        <div style="position:absolute;left:-9999px" aria-hidden="true">
          <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <label style="display:block;margin:0 0 14px">
          <span style="display:block;margin-bottom:6px;font-size:14px;color:var(--text-muted)"><?= $e($FM['name']) ?></span>
          <input type="text" name="club_name" required maxlength="120" value="<?= $ov('club_name') ?>"
                 style="width:100%;box-sizing:border-box">
        </label>
        <label style="display:block;margin:0 0 14px">
          <span style="display:block;margin-bottom:6px;font-size:14px;color:var(--text-muted)"><?= $e($FM['region']) ?></span>
          <input type="text" name="region" maxlength="120" value="<?= $ov('region') ?>"
                 style="width:100%;box-sizing:border-box">
        </label>
        <label style="display:block;margin:0 0 14px">
          <span style="display:block;margin-bottom:6px;font-size:14px;color:var(--text-muted)"><?= $e($FM['email']) ?></span>
          <input type="email" name="contact_email" required maxlength="180" value="<?= $ov('contact_email') ?>"
                 autocapitalize="off" autocomplete="email" style="width:100%;box-sizing:border-box">
        </label>
        <label style="display:block;margin:0 0 22px">
          <span style="display:block;margin-bottom:6px;font-size:14px;color:var(--text-muted)"><?= $e($FM['discipline']) ?></span>
          <input type="text" name="discipline" maxlength="80" value="<?= $ov('discipline') ?>"
                 style="width:100%;box-sizing:border-box">
        </label>
        <button type="submit" class="cr-btn cr-btn--primary cr-btn--lg"><span><?= $e($FM['submit']) ?></span></button>
        <p style="margin:16px 0 0;font-size:13px;color:var(--text-muted)"><?= $e($FM['fine']) ?></p>
      </form>
    </div>
  <?= cr_card_close() ?>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
