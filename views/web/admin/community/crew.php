<?php
/** @var array{crew:array<string,mixed>,members:list<array<string,mixed>>,memberCount:int} $d */
/** @var string $role */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$can = static fn(string $p): bool => \App\Game\Admin\AdminPermissions::can($role, $p);
$c = $d['crew'];
$id = (int)$c['id'];
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/community">&larr; <?= t('Community') ?></a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= $e($c['name']) ?> <span class="muted">/<?= $e($c['slug']) ?></span></h1>
    <p class="muted">ID <?= $id ?> · <?= t('Mitglieder') ?>: <?= (int)$d['memberCount'] ?> ·
        Logo: <?= ($c['logo_path'] ?? null) !== null ? '✓' : '–' ?></p>
</section>

<section class="card">
    <h2><?= t('Mitglieder') ?></h2>
    <table class="data" style="width:100%">
        <thead><tr><th>User</th><th><?= t('Rolle') ?></th><th><?= t('Beigetreten') ?></th></tr></thead>
        <tbody>
        <?php foreach ($d['members'] as $m): ?>
            <tr>
                <td><a href="/admin/users/<?= (int)$m['user_id'] ?>"><?= $m['handle'] !== null ? '@' . $e($m['handle']) : ($m['name'] !== null ? $e($m['name']) : 'user#' . (int)$m['user_id']) ?></a></td>
                <td><span class="badge<?= $m['role'] === 'captain' ? ' badge-ok' : '' ?>"><?= $e($m['role']) ?></span></td>
                <td class="muted"><?= $e($m['joined_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($d['members'] === []): ?><tr><td colspan="3" class="muted"><?= t('Keine Mitglieder.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<?php if ($can('crew.manage')): ?>
<section class="card">
    <h2><?= t('Moderation') ?></h2>
    <form method="post" action="/admin/community/crew/<?= $id ?>/rename" style="display:flex;gap:.5rem;align-items:end;margin-bottom:.75rem">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <label><?= t('Name') ?><br><input type="text" name="name" maxlength="40" value="<?= $e($c['name']) ?>"></label>
        <button type="submit"><?= t('Umbenennen') ?></button>
    </form>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <form method="post" action="/admin/community/crew/<?= $id ?>/clear-logo">
            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
            <button type="submit"><?= t('Logo entfernen') ?></button>
        </form>
        <form method="post" action="/admin/community/crew/<?= $id ?>/dissolve"
              onsubmit="return confirm('<?= $e(t('Crew wirklich auflösen? Territorium wird freigegeben.')) ?>')">
            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
            <button type="submit" style="color:var(--err,#c0392b)"><?= t('Crew auflösen') ?></button>
        </form>
    </div>
</section>
<?php endif; ?>
