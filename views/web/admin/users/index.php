<?php
/** @var list<array<string,mixed>> $rows */
/** @var \App\Controllers\Web\Admin\AdminListQuery $lq */
/** @var bool $hasMore */
/** @var ?string $flash */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin">Übersicht</a>
    <a href="/admin/users"><strong>User</strong></a>
    <a href="/admin/rides">Fahrten</a>
    <a href="/admin/review">Review</a>
    <a href="/admin/game">Health</a>
    <a href="/admin/cron">Cron</a>
    <a href="/admin/audit">Audit</a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= t('User') ?></h1>
    <form method="get" action="/admin/users" style="display:flex;gap:.5rem;align-items:end">
        <label><?= t('Suche (E-Mail, Handle, Name, ID)') ?><br>
            <input type="text" name="q" value="<?= $e($lq->q) ?>" style="min-width:20rem"></label>
        <button type="submit"><?= t('Suchen') ?></button>
        <?php if ($lq->q !== ''): ?><a href="/admin/users"><?= t('Zurücksetzen') ?></a><?php endif; ?>
    </form>
</section>

<section class="card">
    <table class="data" style="width:100%">
        <thead>
            <tr><th>E-Mail</th><th>Handle</th><th><?= t('Status') ?></th><th>Verify</th><th><?= t('Erstellt') ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="/admin/users/<?= (int)$r['id'] ?>"><?= $e($r['email']) ?></a></td>
                <td><?= $r['handle'] !== null ? '@' . $e($r['handle']) : '<span class="muted">–</span>' ?></td>
                <td>
                    <span class="badge <?= (int)$r['banned'] === 1 ? 'badge-err' : ($r['status'] === 'active' ? 'badge-ok' : 'badge-warn') ?>">
                        <?= (int)$r['banned'] === 1 ? 'banned' : $e($r['status']) ?>
                    </span>
                </td>
                <td><?= $r['email_verified_at'] !== null ? '✓' : '<span class="muted">–</span>' ?></td>
                <td class="muted"><?= $e($r['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="5" class="muted"><?= t('Keine Treffer.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    <p>
        <?php if ($lq->page > 1): ?><a href="/admin/users?<?= $e($lq->withParams(['page' => $lq->page - 1])) ?>">&larr; <?= t('zurück') ?></a><?php endif; ?>
        <?php if ($hasMore): ?><a href="/admin/users?<?= $e($lq->withParams(['page' => $lq->page + 1])) ?>"><?= t('weiter') ?> &rarr;</a><?php endif; ?>
    </p>
</section>
