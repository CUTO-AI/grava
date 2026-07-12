<?php
/** @var list<array<string,mixed>> $rows */
/** @var \App\Controllers\Web\Admin\AdminListQuery $lq */
/** @var string $source */
/** @var bool $hasMore */
/** @var ?string $flash */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$km = static fn($m): string => $m === null ? '–' : number_format((int)$m / 1000, 1) . ' km';
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin">Übersicht</a>
    <a href="/admin/users">User</a>
    <a href="/admin/rides"><strong>Fahrten</strong></a>
    <a href="/admin/review">Review</a>
    <a href="/admin/game">Health</a>
    <a href="/admin/cron">Cron</a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= t('Fahrten') ?></h1>
    <form method="get" action="/admin/rides" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap">
        <label><?= t('Suche (Titel, User, ID)') ?><br><input type="text" name="q" value="<?= $e($lq->q) ?>" style="min-width:18rem"></label>
        <label><?= t('Quelle') ?><br>
            <select name="source">
                <option value=""><?= t('alle') ?></option>
                <?php foreach (['app', 'strava', 'import', 'manual'] as $s): ?>
                    <option value="<?= $s ?>"<?= $source === $s ? ' selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= t('Suchen') ?></button>
        <?php if ($lq->q !== '' || $source !== ''): ?><a href="/admin/rides"><?= t('Zurücksetzen') ?></a><?php endif; ?>
    </form>
</section>

<section class="card">
    <table class="data" style="width:100%">
        <thead>
            <tr><th><?= t('Titel') ?></th><th>User</th><th><?= t('Quelle') ?></th><th><?= t('Distanz') ?></th><th><?= t('Im Spiel') ?></th><th><?= t('Erstellt') ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="/admin/rides/<?= (int)$r['id'] ?>"><?= $e($r['title']) ?></a></td>
                <td><a href="/admin/users/<?= (int)$r['user_id'] ?>"><?= $e($r['user_email']) ?></a></td>
                <td><span class="badge"><?= $e($r['source']) ?></span></td>
                <td><?= $e($km($r['distance_m'])) ?></td>
                <td><?= (int)$r['in_game'] === 1 ? '<span class="badge badge-ok">✓</span>' : '<span class="muted">–</span>' ?></td>
                <td class="muted"><?= $e($r['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="6" class="muted"><?= t('Keine Treffer.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    <p>
        <?php if ($lq->page > 1): ?><a href="/admin/rides?<?= $e($lq->withParams(['page' => $lq->page - 1, 'source' => $source])) ?>">&larr; <?= t('zurück') ?></a><?php endif; ?>
        <?php if ($hasMore): ?><a href="/admin/rides?<?= $e($lq->withParams(['page' => $lq->page + 1, 'source' => $source])) ?>"><?= t('weiter') ?> &rarr;</a><?php endif; ?>
    </p>
</section>
