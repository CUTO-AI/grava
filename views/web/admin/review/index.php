<?php
/** @var list<array<string,mixed>> $reports */
/** @var list<array<string,mixed>> $highVolume */
/** @var string $role */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$can = static fn(string $p): bool => \App\Game\Admin\AdminPermissions::can($role, $p);
$contentLink = static function (string $type, int $cid) use ($e): string {
    return match ($type) {
        'user'  => '<a href="/admin/users/' . $cid . '">user#' . $cid . '</a>',
        'route' => '<a href="/admin/rides/' . $cid . '">route#' . $cid . '</a>',
        default => $e($type) . '#' . $cid,
    };
};
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin">Übersicht</a>
    <a href="/admin/users">User</a>
    <a href="/admin/rides">Fahrten</a>
    <a href="/admin/review"><strong>Review</strong></a>
    <a href="/admin/game/moderation">Moderation</a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= t('Review-Queue') ?></h1>
    <h2><?= t('Offene Meldungen') ?> (<?= count($reports) ?>)</h2>
    <table class="data" style="width:100%">
        <thead><tr><th><?= t('Zeit') ?></th><th><?= t('Inhalt') ?></th><th><?= t('Grund') ?></th><th><?= t('Melder') ?></th><th><?= t('Beschreibung') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($reports as $r): ?>
            <tr>
                <td class="muted"><?= $e($r['created_at']) ?></td>
                <td><?= $contentLink((string)$r['content_type'], (int)$r['content_id']) ?></td>
                <td><span class="badge"><?= $e($r['reason']) ?></span></td>
                <td><?= $e($r['reporter_email'] ?? '–') ?></td>
                <td><?= $e((string)($r['description'] ?? '')) ?></td>
                <td>
                    <?php if ($can('review.act')): ?>
                        <form method="post" action="/admin/review/report/<?= (int)$r['id'] ?>" style="display:flex;gap:.3rem;margin:0">
                            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                            <button type="submit" name="status" value="reviewed"><?= t('geprüft') ?></button>
                            <button type="submit" name="status" value="resolved"><?= t('erledigt') ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($reports === []): ?><tr><td colspan="6" class="muted"><?= t('Keine offenen Meldungen.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2><?= t('Auffällig: hohe Geschwindigkeit') ?></h2>
    <p class="muted"><?= t('Pässe mit Ø-Kanten-Tempo über mod_max_speed_kmh (unter der harten Ingest-Grenze). Nur markiert – Fahrt im Fahrten-Modul invalidieren.') ?></p>
    <table class="data" style="width:100%">
        <thead><tr><th>User</th><th><?= t('Tempo') ?></th><th>Kante</th><th><?= t('Fahrt') ?></th><th><?= t('Tag') ?></th></tr></thead>
        <tbody>
        <?php foreach ($suspiciousSpeed as $s): ?>
            <tr>
                <td><a href="/admin/users/<?= (int)$s['user_id'] ?>"><?= $s['handle'] !== null ? '@' . $e($s['handle']) : 'user#' . (int)$s['user_id'] ?></a></td>
                <td><strong><?= number_format((float)$s['avg_speed_kmh'], 1) ?> km/h</strong></td>
                <td>edge#<?= (int)$s['edge_id'] ?></td>
                <td><a href="/admin/rides/<?= (int)$s['route_id'] ?>">route#<?= (int)$s['route_id'] ?></a></td>
                <td class="muted"><?= $e($s['ridden_on']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($suspiciousSpeed === []): ?><tr><td colspan="5" class="muted"><?= t('Nichts Auffälliges.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2><?= t('Auffällig: viele Pässe/Tag') ?></h2>
    <p class="muted"><?= t('Heuristik (mod_max_passes_per_day).') ?></p>
    <table class="data" style="width:100%">
        <thead><tr><th>User</th><th><?= t('Pässe/Tag') ?></th><th><?= t('Tag') ?></th></tr></thead>
        <tbody>
        <?php foreach ($highVolume as $h): ?>
            <tr>
                <td><a href="/admin/users/<?= (int)$h['user_id'] ?>"><?= $h['handle'] !== null ? '@' . $e($h['handle']) : 'user#' . (int)$h['user_id'] ?></a></td>
                <td><strong><?= (int)$h['passes_that_day'] ?></strong></td>
                <td class="muted"><?= $e($h['ridden_on']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($highVolume === []): ?><tr><td colspan="3" class="muted"><?= t('Nichts Auffälliges.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
