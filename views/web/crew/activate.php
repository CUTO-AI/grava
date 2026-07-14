<?php
/** @var string $open_url */
/** @var string $app_store_url */
$open = htmlspecialchars($open_url, ENT_QUOTES, 'UTF-8');
?>
<section class="card">
    <h1><?= t('Vereins-Account aktivieren') ?></h1>
    <p><?= t('Dieser Link aktiviert euren offiziellen, verifizierten Vereins-Account in CYBERRIDE. Öffne ihn auf dem iPhone mit installierter App und angemeldetem Konto.') ?></p>

    <p>
        <a href="<?= $open ?>" class="button" data-ga-event="crew_activate_open_in_app"><?= t('In der App öffnen') ?></a>
    </p>

    <?php if ($app_store_url !== ''): ?>
        <p>
            <a href="<?= htmlspecialchars($app_store_url, ENT_QUOTES, 'UTF-8') ?>" class="button"
               data-ga-event="appstore_click" data-ga-source="crew_activate"><?= t('App laden') ?></a>
        </p>
    <?php endif; ?>

    <p class="muted"><?= t('Aus Sicherheitsgründen lässt sich der Vereins-Account nur in der App aktivieren.') ?></p>
</section>
