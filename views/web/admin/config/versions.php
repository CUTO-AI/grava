<?php
/** @var list<array<string,mixed>> $versions */
/** @var string $role */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$can = static fn(string $p): bool => \App\Game\Admin\AdminPermissions::can($role, $p);
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin">Übersicht</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/config/versions"><strong>Config-Versionen</strong></a>
    <a href="/admin/audit">Audit</a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= t('Config-Versionen') ?></h1>
    <p class="muted"><?= t('Jede Config-Speicherung erzeugt einen Voll-Snapshot. Diff zeigt die Änderung zur Vorversion; Rollback wendet den Snapshot erneut an.') ?></p>
    <table class="data" style="width:100%">
        <thead><tr><th>#</th><th><?= t('Zeit (UTC)') ?></th><th>Admin</th><th><?= t('Notiz') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($versions as $v): ?>
            <tr>
                <td><a href="/admin/config/versions/<?= (int)$v['id'] ?>">#<?= (int)$v['id'] ?></a></td>
                <td class="muted"><?= $e($v['created_at']) ?></td>
                <td><?= $e($v['admin_email'] ?? '–') ?></td>
                <td><?= $e($v['note'] ?? '') ?></td>
                <td>
                    <a href="/admin/config/versions/<?= (int)$v['id'] ?>"><?= t('Diff') ?></a>
                    <?php if ($can('config.write')): ?>
                        · <form method="post" action="/admin/config/versions/<?= (int)$v['id'] ?>/restore" style="display:inline"
                                onsubmit="return confirm('<?= $e(t('Config auf diese Version zurücksetzen?')) ?>')">
                            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                            <button type="submit"><?= t('Rollback') ?></button>
                          </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($versions === []): ?><tr><td colspan="5" class="muted"><?= t('Noch keine Versionen (erste Config-Speicherung erzeugt eine).') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
