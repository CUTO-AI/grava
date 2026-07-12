<?php
/** @var list<array<string,mixed>> $rows */
/** @var array{admin:string,action:string,since:string} $filter */
/** @var int $page */
/** @var bool $hasMore */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$qs = static function (array $ov) use ($filter, $page): string {
    return http_build_query(array_filter(array_merge(
        ['admin' => $filter['admin'], 'action' => $filter['action'], 'since' => $filter['since'], 'page' => $page],
        $ov,
    ), static fn($v) => $v !== '' && $v !== null));
};
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/cron">Cron</a>
    <a href="/admin/audit"><strong>Audit</strong></a>
    <a href="/admin/roles">Rollen</a>
</nav>

<section class="card">
    <h1><?= t('Audit-Log') ?></h1>
    <form method="get" action="/admin/audit" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">
        <label><?= t('Admin (E-Mail)') ?><br><input type="text" name="admin" value="<?= $e($filter['admin']) ?>"></label>
        <label><?= t('Aktion') ?><br><input type="text" name="action" value="<?= $e($filter['action']) ?>"></label>
        <label><?= t('Seit') ?><br><input type="date" name="since" value="<?= $e($filter['since']) ?>"></label>
        <button type="submit"><?= t('Filtern') ?></button>
        <a href="/admin/audit"><?= t('Zurücksetzen') ?></a>
    </form>
</section>

<section class="card">
    <table class="data" style="width:100%">
        <thead>
            <tr>
                <th><?= t('Zeit (UTC)') ?></th>
                <th>Admin</th>
                <th><?= t('Aktion') ?></th>
                <th><?= t('Ziel') ?></th>
                <th><?= t('Details') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= $e($r['created_at']) ?></td>
                <td><?= $e($r['admin_email'] ?? ('#' . $r['admin_user_id'])) ?></td>
                <td><span class="badge"><?= $e($r['action']) ?></span></td>
                <td><?= $e((string)($r['target'] ?? '–')) ?></td>
                <td>
                    <?php if (!empty($r['detail'])): ?>
                        <details><summary><?= t('anzeigen') ?></summary>
                            <pre style="white-space:pre-wrap"><?= $e(json_encode($r['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                        </details>
                    <?php else: ?>–<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="5" class="muted"><?= t('Keine Einträge.') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p>
        <?php if ($page > 1): ?><a href="/admin/audit?<?= $e($qs(['page' => $page - 1])) ?>">&larr; <?= t('neuer') ?></a><?php endif; ?>
        <?php if ($hasMore): ?><a href="/admin/audit?<?= $e($qs(['page' => $page + 1])) ?>"><?= t('älter') ?> &rarr;</a><?php endif; ?>
    </p>
</section>
