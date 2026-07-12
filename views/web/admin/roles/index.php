<?php
/** @var list<string> $assignable */
/** @var list<array{user_id:int,email:string,handle:?string,role:string}> $rows */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/cron">Cron</a>
    <a href="/admin/audit">Audit</a>
    <a href="/admin/roles"><strong>Rollen</strong></a>
</nav>

<?php if ($flash !== null): ?>
    <section class="card"><p><?= $e($flash) ?></p></section>
<?php endif; ?>

<section class="card">
    <h1><?= t('Admin-Rollen') ?></h1>
    <p class="muted"><?= t('super wird über ADMIN_EMAILS gesetzt. Hier operator/support/analyst an einzelne User vergeben.') ?></p>
    <form method="post" action="/admin/roles/assign" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <label><?= t('User (E-Mail oder Handle)') ?><br><input type="text" name="user" required></label>
        <label><?= t('Rolle') ?><br>
            <select name="role">
                <?php foreach ($assignable as $r): ?>
                    <option value="<?= $e($r) ?>"><?= $e($r) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= t('Vergeben') ?></button>
    </form>
</section>

<section class="card">
    <table class="data" style="width:100%">
        <thead>
            <tr><th>User</th><th><?= t('Rolle') ?></th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= $e($row['email']) ?><?= $row['handle'] !== null ? ' <span class="muted">@' . $e($row['handle']) . '</span>' : '' ?></td>
                <td><span class="badge"><?= $e($row['role']) ?></span></td>
                <td>
                    <form method="post" action="/admin/roles/<?= (int)$row['user_id'] ?>/revoke" style="margin:0"
                          onsubmit="return confirm('<?= $e(t('Rolle wirklich entfernen?')) ?>')">
                        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                        <button type="submit"><?= t('Entfernen') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="3" class="muted"><?= t('Noch keine Rollen vergeben.') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
