<?php
/** @var list<array<string,mixed>> $users */
/** @var array<string,mixed> $pagination */
/** @var array{q:string} $filters */
/** @var array<string,mixed>|null $_authedUser */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
?>

<header class="page-header">
    <h1><?= t('User entdecken') ?></h1>
    <p class="muted"><?= t('User mit gesetztem Profil-Handle.') ?> <a href="/discover"><?= t('Routen entdecken') ?> &rarr;</a></p>
</header>

<form method="get" action="/discover/users" class="filter-form">
    <label>
        <?= t('Suche') ?>
        <input type="text" name="q" value="<?= $h($filters['q'] ?? '') ?>" placeholder="<?= te('Handle oder Name…') ?>" maxlength="100">
    </label>
    <div class="form-actions">
        <button type="submit"><?= t('Suchen') ?></button>
        <a href="/discover/users" class="btn-link"><?= t('Zurücksetzen') ?></a>
    </div>
</form>

<?php if (empty($users)): ?>
    <div class="empty-state">
        <p><?= t('Keine User gefunden.') ?></p>
    </div>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= t('Handle') ?></th>
                <th><?= t('Name') ?></th>
                <th class="num"><?= t('Public Routen') ?></th>
                <th><?= t('Beigetreten') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><a href="/u/<?= $h($u['handle']) ?>">@<?= $h($u['handle']) ?></a></td>
                <td><?= $h($u['display_name'] ?? '—') ?></td>
                <td class="num"><?= $h($u['route_count_public']) ?></td>
                <td><?= $h(substr($u['joined_at'] ?? '', 0, 10)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    $offset = (int)($pagination['offset'] ?? 0);
    $limit  = (int)($pagination['limit']  ?? 20);
    $total  = (int)($pagination['total']  ?? 0);
    $hasMore = !empty($pagination['has_more']);
    $qs = $_GET; unset($qs['offset']);
    ?>
    <p class="muted">
        <?= t('Zeige') ?> <?= $h($offset + 1) ?>–<?= $h(min($offset + $limit, $total)) ?> <?= t('von') ?> <?= $h($total) ?>
    </p>
    <p class="pagination">
        <?php if ($offset > 0):
            $qs['offset'] = max(0, $offset - $limit); ?>
            <a href="?<?= $h(http_build_query($qs)) ?>" class="btn-link">← <?= t('Vorherige') ?></a>
        <?php endif; ?>
        <?php if ($hasMore):
            $qs['offset'] = $offset + $limit; ?>
            <a href="?<?= $h(http_build_query($qs)) ?>" class="btn-link"><?= t('Nächste') ?> →</a>
        <?php endif; ?>
    </p>
<?php endif; ?>
