<?php
/**
 * Öffentliche Ranglisten (WebAnalytics_Concept.md): Solo / Crews / Fraktionen,
 * all-time nach gehaltenem Revier. Reine Listendarstellung.
 *
 * @var string $tab  Aktiver Tab: solo|crews|fraktionen
 * @var list<array{claimant_id:int,handle:?string,name:?string,held_length_m:float,held_edges:int}>|null $solo
 * @var list<array<string,mixed>>|null $crews
 * @var list<array<string,mixed>>|null $factions
 */
$e  = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$km = static fn(float $m): string => number_format($m / 1000, 1);
?>

<header class="page-header">
    <p class="cr-kicker"><?= t('Revierkampf · Gesamtwertung') ?></p>
    <h1><?= t('Ranglisten') ?></h1>
    <p class="muted">
        <?= t('Wer hält aktuell das meiste Revier? Solo-Fahrer, Crews und Fraktionen im Gesamtvergleich nach gehaltener Streckenlänge.') ?>
    </p>
</header>

<nav class="rank-tabs" aria-label="<?= te('Ranglisten-Kategorien') ?>">
    <a href="/rangliste/solo"       class="rank-tab<?= $tab === 'solo' ? ' is-active' : '' ?>"><?= t('Solo') ?></a>
    <a href="/rangliste/crews"      class="rank-tab<?= $tab === 'crews' ? ' is-active' : '' ?>"><?= t('Crews') ?></a>
    <a href="/rangliste/fraktionen" class="rank-tab<?= $tab === 'fraktionen' ? ' is-active' : '' ?>"><?= t('Fraktionen') ?></a>
</nav>

<section class="card">
<?php if ($tab === 'solo'): ?>
    <h2 class="cr-h3"><?= t('Solo-Fahrer') ?></h2>
    <p class="muted small"><?= t('Nach gehaltener Revierlänge (all-time).') ?></p>
    <?php if (empty($solo)): ?>
        <p class="muted"><?= t('Noch keine gehaltenen Reviere.') ?></p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th><?= t('Fahrer') ?></th>
                <th class="num"><?= t('Revier (km)') ?></th>
                <th class="num"><?= t('Kanten') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($solo as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td>
                    <?php if ($r['handle'] !== null): ?>
                        <a href="/u/<?= $e($r['handle']) ?>">@<?= $e($r['handle']) ?></a>
                    <?php else: ?>
                        <?= $e($r['name'] ?? t('Unbekannt')) ?>
                    <?php endif; ?>
                </td>
                <td class="num"><?= $km((float)$r['held_length_m']) ?></td>
                <td class="num"><?= (int)$r['held_edges'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

<?php elseif ($tab === 'crews'): ?>
    <h2 class="cr-h3"><?= t('Crews') ?></h2>
    <p class="muted small"><?= t('Nach gehaltener Revierlänge (all-time).') ?></p>
    <?php if (empty($crews)): ?>
        <p class="muted"><?= t('Noch keine Crew hält ein Revier.') ?></p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th><?= t('Crew') ?></th>
                <th class="num"><?= t('Mitglieder') ?></th>
                <th class="num"><?= t('Revier (km)') ?></th>
                <th class="num"><?= t('Kanten') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($crews as $i => $r): ?>
            <tr>
                <td><?= (int)$r['rank'] ?></td>
                <td>
                    <span class="crew-cell">
                        <?php if (!empty($r['logo_updated_at'])): ?>
                            <img class="crew-logo" src="/game/crews/<?= $e($r['slug']) ?>/logo" alt="" width="24" height="24" loading="lazy">
                        <?php elseif (!empty($r['faction']['color'])): ?>
                            <span class="faction-dot" style="background:<?= $e($r['faction']['color']) ?>"></span>
                        <?php endif; ?>
                        <?= $e($r['name']) ?>
                    </span>
                </td>
                <td class="num"><?= (int)$r['member_count'] ?></td>
                <td class="num"><?= $km((float)$r['held_length_m']) ?></td>
                <td class="num"><?= (int)$r['held_edges'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

<?php else: ?>
    <h2 class="cr-h3"><?= t('Fraktionen') ?></h2>
    <p class="muted small"><?= t('Gehaltene Strecke und gewonnene Zellen je Fraktion.') ?></p>
    <?php if (empty($factions)): ?>
        <p class="muted"><?= t('Keine Fraktionsdaten.') ?></p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= t('Fraktion') ?></th>
                <th class="num"><?= t('Crews') ?></th>
                <th class="num"><?= t('Mitglieder') ?></th>
                <th class="num"><?= t('Revier (km)') ?></th>
                <th class="num"><?= t('Zellen') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($factions as $r): ?>
            <tr>
                <td>
                    <span class="crew-cell">
                        <span class="faction-dot" style="background:<?= $e($r['color']) ?>"></span>
                        <?= $e($r['name']) ?>
                    </span>
                </td>
                <td class="num"><?= (int)$r['crews'] ?></td>
                <td class="num"><?= (int)$r['members'] ?></td>
                <td class="num"><?= $km((float)$r['held_length_m']) ?></td>
                <td class="num"><?= (int)$r['cells'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>
</section>
