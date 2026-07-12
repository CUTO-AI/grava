<?php
/** @var array<string,mixed> $d */
/** @var string $role */
/** @var list<array<string,mixed>> $auditRows */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$can = static fn(string $p): bool => \App\Game\Admin\AdminPermissions::can($role, $p);
$u = $d['user'];
$banned = (int)$u['banned'] === 1;
$uid = (int)$u['id'];
$g = $d['game'] ?? null;
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/users">&larr; <?= t('User') ?></a>
    <a href="/admin">Übersicht</a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= $e($u['email']) ?> <?php if ($banned): ?><span class="badge badge-err">banned</span><?php endif; ?></h1>
    <p class="muted">
        ID <?= $uid ?> · <?= $e($u['public_id']) ?> ·
        Handle: <?= $u['handle'] !== null ? '@' . $e($u['handle']) : '–' ?> ·
        Name: <?= $e($u['display_name'] ?? '–') ?><br>
        Status: <strong><?= $e($u['status']) ?></strong> ·
        Verify: <?= $u['email_verified_at'] !== null ? $e($u['email_verified_at']) : '<em>' . t('nicht verifiziert') . '</em>' ?> ·
        <?= t('Erstellt') ?>: <?= $e($u['created_at']) ?>
        <?php if ($banned && $u['ban_reason'] !== null): ?><br>Ban-Grund: <?= $e($u['ban_reason']) ?><?php endif; ?>
    </p>
    <p>
        <?= t('Fahrten') ?>: <strong><?= (int)$d['rides_total'] ?></strong>
        (<?= t('im Spiel') ?>: <?= (int)$d['rides_game'] ?>) ·
        Strava: <?= $d['strava'] ? '✓' : '–' ?>
        <?php if (is_array($g)): ?>
            · <?= t('Revierlänge') ?>: <?= isset($g['held_length_m']) ? number_format((float)$g['held_length_m'] / 1000, 1) . ' km' : '–' ?>
        <?php endif; ?>
        · <a href="/admin/rides?user_id=<?= (int)$uid ?>"><?= t('Fahrten dieses Users') ?> &rarr;</a>
    </p>
</section>

<section class="card">
    <h2><?= t('Aktionen') ?></h2>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <?php if ($can('user.ban')): ?>
            <?php if ($banned): ?>
                <form method="post" action="/admin/users/<?= $uid ?>/unban"><input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                    <button type="submit"><?= t('Ban aufheben') ?></button></form>
            <?php else: ?>
                <form method="post" action="/admin/users/<?= $uid ?>/ban" style="display:flex;gap:.3rem;align-items:end">
                    <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                    <label><?= t('Grund') ?><br><input type="text" name="reason"></label>
                    <button type="submit"><?= t('Bannen') ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($can('user.support') && $u['email_verified_at'] === null): ?>
            <form method="post" action="/admin/users/<?= $uid ?>/verify"><input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                <button type="submit"><?= t('E-Mail verifizieren') ?></button></form>
        <?php endif; ?>
    </div>

    <?php if ($can('user.edit')): ?>
        <h3><?= t('Profil ändern') ?></h3>
        <form method="post" action="/admin/users/<?= $uid ?>/profile" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap">
            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
            <label><?= t('Anzeigename') ?><br><input type="text" name="display_name" value="<?= $e($u['display_name'] ?? '') ?>"></label>
            <label>Handle<br><input type="text" name="handle" value="<?= $e($u['handle'] ?? '') ?>"></label>
            <button type="submit"><?= t('Speichern') ?></button>
        </form>
    <?php endif; ?>

    <?php if ($can('user.delete')): ?>
        <h3><?= t('DSGVO') ?></h3>
        <form method="post" action="/admin/users/<?= $uid ?>/anonymize"
              onsubmit="return confirm('<?= $e(t('User unwiderruflich anonymisieren?')) ?>')">
            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
            <button type="submit" style="color:var(--err,#c0392b)"><?= t('Anonymisieren (löschen)') ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= t('Audit (diesen User betreffend)') ?></h2>
    <table class="data" style="width:100%">
        <thead><tr><th><?= t('Zeit') ?></th><th>Admin</th><th><?= t('Aktion') ?></th><th><?= t('Ziel') ?></th></tr></thead>
        <tbody>
        <?php foreach ($auditRows as $a): ?>
            <tr><td class="muted"><?= $e($a['created_at']) ?></td><td><?= $e($a['admin_email'] ?? '–') ?></td>
                <td><span class="badge"><?= $e($a['action']) ?></span></td><td><?= $e((string)($a['target'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($auditRows === []): ?><tr><td colspan="4" class="muted"><?= t('Keine Einträge.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
