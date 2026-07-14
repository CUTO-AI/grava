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

    <p><?= t('CYBERRIDE ist das Spiel für Gravel & Rennrad: Fahre, erobere deine Region und tritt deinem Verein bei.') ?></p>

    <p>
        <a href="<?= $open ?>" class="button" data-ga-event="crew_open_in_app"><?= t('In der App öffnen') ?></a>
    </p>

    <?php if ($app_store_url !== ''): ?>
        <p>
            <a href="<?= htmlspecialchars($app_store_url, ENT_QUOTES, 'UTF-8') ?>" class="button"
               data-ga-event="appstore_click" data-ga-source="crew"><?= t('App laden') ?></a>
        </p>
    <?php endif; ?>

    <p class="muted">
        <?= t('App schon installiert? Öffne diesen Link auf deinem iPhone – die App übernimmt die Einladung automatisch. Falls nicht, gib den Code') ?>
        <strong><?= $code ?></strong> <?= t('in der App unter „Crew beitreten" ein.') ?>
    </p>
</section>
