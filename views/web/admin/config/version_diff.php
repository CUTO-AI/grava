<?php
/** @var array{id:int,created_at:string,note:?string,values:array<string,string>} $version */
/** @var array<string,array{before:?string,after:?string}> $diff */
/** @var string $role */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$can = static fn(string $p): bool => \App\Game\Admin\AdminPermissions::can($role, $p);
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/config/versions">&larr; <?= t('Config-Versionen') ?></a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= t('Version') ?> #<?= (int)$version['id'] ?></h1>
    <p class="muted"><?= $e($version['created_at']) ?> UTC<?= $version['note'] !== null ? ' · ' . $e($version['note']) : '' ?></p>
    <?php if ($can('config.write')): ?>
        <form method="post" action="/admin/config/versions/<?= (int)$version['id'] ?>/restore"
              onsubmit="return confirm('<?= $e(t('Config auf diese Version zurücksetzen?')) ?>')">
            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
            <button type="submit"><?= t('Auf diese Version zurücksetzen') ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= t('Änderungen zur Vorversion') ?> (<?= count($diff) ?>)</h2>
    <table class="data" style="width:100%">
        <thead><tr><th>Key</th><th><?= t('vorher') ?></th><th><?= t('nachher') ?></th></tr></thead>
        <tbody>
        <?php foreach ($diff as $key => $d): ?>
            <tr>
                <td style="font-family:monospace"><?= $e($key) ?></td>
                <td class="muted"><?= $d['before'] === null ? '<em>—</em>' : $e($d['before']) ?></td>
                <td><strong><?= $d['after'] === null ? '<em>—</em>' : $e($d['after']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($diff === []): ?><tr><td colspan="3" class="muted"><?= t('Keine Unterschiede.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
