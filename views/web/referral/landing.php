<?php
/** @var string $referral_code */
/** @var string $app_store_url */
/** @var string $register_url */
$code = htmlspecialchars($referral_code, ENT_QUOTES, 'UTF-8');
?>
<section class="card">
    <h1><?= t('Du wurdest zu CYBERRIDE eingeladen') ?></h1>
    <p><?= t('CYBERRIDE ist deine App für Gravel-Touren: Routen entdecken, aufzeichnen und teilen.') ?></p>

    <p class="muted"><?= t('Dein Einlade-Code:') ?></p>
    <p style="font-size:1.6rem;font-weight:700;letter-spacing:.04em;">
        <?= $code ?>
    </p>

    <?php if ($app_store_url !== ''): ?>
        <p>
            <a href="<?= htmlspecialchars($app_store_url, ENT_QUOTES, 'UTF-8') ?>" class="button"
               data-ga-event="appstore_click" data-ga-source="referral">
                <?= t('App laden') ?>
            </a>
        </p>
    <?php endif; ?>

    <p class="muted">
        <?= t('App schon installiert? Öffne diesen Link auf deinem iPhone – die App übernimmt den Code automatisch. Falls nicht, gib den Code') ?>
        <strong><?= $code ?></strong> <?= t('bei der Registrierung ein.') ?>
    </p>

    <p>
        <?= t('Lieber im Browser?') ?> <a href="<?= htmlspecialchars($register_url, ENT_QUOTES, 'UTF-8') ?>" data-ga-event="register_click" data-ga-source="referral"><?= t('Hier registrieren') ?></a>
        <?= t('– der Code ist bereits hinterlegt.') ?>
    </p>
</section>
