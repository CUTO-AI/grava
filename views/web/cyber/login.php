<?php
/**
 * Login im Cyber-Design. Funktional identisch zu views/web/login.php
 * (gleiche Action, Feldnamen, CSRF, Validierung) — nur neu geskinnt.
 *
 * @var ?string $error
 * @var string  $email
 * @var string  $_csrf
 */
?>
<div class="cyber-narrow">
    <p class="cyber-kicker">// Access Terminal</p>
    <h1 class="cyber-h1"><?= t('Anmelden') ?></h1>

    <?php if (!empty($error)): ?>
        <div class="cr-alert cr-alert--error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form class="cr-form" method="post" action="/login" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label class="cr-field">
            <span>E-Mail</span>
            <input class="cr-input" type="email" name="email" autocomplete="email" required
                   value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label class="cr-field">
            <span><?= t('Passwort') ?></span>
            <input class="cr-input" type="password" name="password" autocomplete="current-password" required minlength="10">
        </label>
        <button type="submit" class="cr-btn cr-btn--primary cr-btn--lg cr-btn--block">
            <span><?= t('Anmelden') ?></span>
        </button>
    </form>

    <p class="cr-formmeta">
        <a href="/forgot-password"><?= t('Passwort vergessen?') ?></a> · <a href="/register"><?= t('Neues Konto erstellen') ?></a>
    </p>
</div>
