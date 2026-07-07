<?php
/**
 * Cookie-/Analytics-Einwilligungs-Banner (Consent Mode v2).
 *
 * Standardmäßig verborgen (`hidden`); consent.js blendet es ein, wenn noch
 * keine Entscheidung im Cookie `cr_consent` steht, und verdrahtet die Buttons.
 * Styles inline (CSP erlaubt style-src 'unsafe-inline'), damit das Partial in
 * allen drei Layouts (cyber, classic, Cyber-Landing) ohne Extra-Asset greift.
 */
?>
<div id="cr-consent" class="cr-consent" role="dialog" aria-modal="false"
     aria-label="<?= te('Cookie-Einwilligung') ?>" aria-live="polite" hidden>
  <div class="cr-consent__inner">
    <p class="cr-consent__text">
      <?= te('Wir nutzen Cookies für anonyme Reichweiten-Messung (Google Analytics), um CYBERRIDE zu verbessern – nur mit deiner Zustimmung.') ?>
      <a href="/privacy" class="cr-consent__link"><?= te('Mehr erfahren') ?></a>
    </p>
    <div class="cr-consent__actions">
      <button type="button" id="cr-consent-decline" class="cr-consent__btn cr-consent__btn--ghost"><?= te('Ablehnen') ?></button>
      <button type="button" id="cr-consent-accept" class="cr-consent__btn cr-consent__btn--primary"><?= te('Akzeptieren') ?></button>
    </div>
  </div>
</div>
<style>
.cr-consent{position:fixed;left:0;right:0;bottom:0;z-index:9999;
  background:#04060B;border-top:1px solid rgba(0,229,255,.35);
  box-shadow:0 -8px 30px rgba(0,0,0,.5);color:#e6f1f5;font-size:14px;line-height:1.5}
.cr-consent[hidden]{display:none}
.cr-consent__inner{max-width:1100px;margin:0 auto;padding:14px 20px;
  display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.cr-consent__text{margin:0;flex:1 1 320px}
.cr-consent__link{color:#00e5ff;text-decoration:underline}
.cr-consent__actions{display:flex;gap:10px;flex-wrap:wrap}
.cr-consent__btn{cursor:pointer;border-radius:8px;padding:10px 18px;font:inherit;
  font-weight:600;border:1px solid transparent;white-space:nowrap}
.cr-consent__btn--ghost{background:transparent;border-color:rgba(230,241,245,.35);color:#e6f1f5}
.cr-consent__btn--ghost:hover{border-color:#e6f1f5}
.cr-consent__btn--primary{background:#00e5ff;color:#04060B}
.cr-consent__btn--primary:hover{filter:brightness(1.08)}
.cr-consent__btn:focus-visible{outline:2px solid #00e5ff;outline-offset:2px}
</style>
