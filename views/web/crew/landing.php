<?php
/** @var string $join_code */
/** @var ?string $crew_name */
/** @var ?string $crew_slug */
/** @var ?int $member_count */
/** @var bool $has_logo */
/** @var string $open_url */
/** @var string $app_store_url */
$code = htmlspecialchars($join_code, ENT_QUOTES, 'UTF-8');
$open = htmlspecialchars($open_url, ENT_QUOTES, 'UTF-8');
?>
<section class="card">
    <?php if ($crew_name !== null): ?>
        <?php if ($has_logo && $crew_slug !== null): ?>
            <p>
                <img src="/game/crews/<?= htmlspecialchars($crew_slug, ENT_QUOTES, 'UTF-8') ?>/logo"
                     alt="" width="96" height="96"
                     style="border-radius:16px;object-fit:cover;">
            </p>
        <?php endif; ?>
        <h1><?= t('Tritt bei:') ?> <?= htmlspecialchars($crew_name, ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($member_count !== null): ?>
            <p class="muted"><?= htmlspecialchars((string)$member_count, ENT_QUOTES, 'UTF-8') ?> <?= t('Mitglieder') ?></p>
        <?php endif; ?>
    <?php else: ?>
        <h1><?= t('Vereins-Einladung') ?></h1>
        <p class="muted"><?= t('Diese Einladung ist ungültig oder abgelaufen.') ?></p>
    <?php endif; ?>

    <p><?= t('CYBERRIDE ist das Spiel für Gravel & Rennrad: Fahre, erobere deine Region und tritt deinem Verein bei. Dazu brauchst du die kostenlose CYBERRIDE-App.') ?></p>

    <h2><?= t('So trittst du bei') ?></h2>
    <ol>
        <li><?= t('CYBERRIDE-App installieren.') ?></li>
        <li><?= t('Diesen Link danach erneut auf dem iPhone öffnen — die App übernimmt die Einladung automatisch.') ?></li>
    </ol>

    <?php if ($app_store_url !== ''): ?>
        <p>
            <a href="<?= htmlspecialchars($app_store_url, ENT_QUOTES, 'UTF-8') ?>" class="button"
               data-ga-event="appstore_click" data-ga-source="crew"><?= t('CYBERRIDE-App laden') ?></a>
        </p>
    <?php else: ?>
        <p class="muted"><?= t('Die CYBERRIDE-App ist bald im App Store verfügbar.') ?></p>
    <?php endif; ?>

    <p class="muted" style="margin-top:14px;">
        <?= t('App schon installiert?') ?>
        <a href="<?= $open ?>" data-ga-event="crew_open_in_app"><?= t('In der App öffnen') ?></a>
        · <?= t('Oder in der App unter „Crew beitreten" den Code eingeben:') ?> <strong><?= $code ?></strong>
    </p>
</section>
