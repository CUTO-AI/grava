<?php
/** @var array<string,mixed> $d */
/** @var string $role */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$can = static fn(string $p): bool => \App\Game\Admin\AdminPermissions::can($role, $p);
$r = $d['route'];
$id = (int)$r['id'];
$job = $d['job'] ?? null;
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/rides">&larr; <?= t('Fahrten') ?></a>
    <a href="/admin/users/<?= (int)$r['user_id'] ?>"><?= t('Fahrer') ?></a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= $e($r['title']) ?></h1>
    <p class="muted">
        ID <?= $id ?> · <?= $e($r['public_id']) ?> · <?= t('Quelle') ?>: <span class="badge"><?= $e($r['source']) ?></span><br>
        <?= t('Fahrer') ?>: <a href="/admin/users/<?= (int)$r['user_id'] ?>"><?= $e($r['user_email']) ?></a>
        <?= $r['handle'] !== null ? '(@' . $e($r['handle']) . ')' : '' ?> ·
        <?= t('Distanz') ?>: <?= $r['distance_m'] !== null ? number_format((int)$r['distance_m'] / 1000, 1) . ' km' : '–' ?> ·
        <?= t('Punkte') ?>: <?= (int)($r['point_count'] ?? 0) ?> ·
        <?= t('Erstellt') ?>: <?= $e($r['created_at']) ?>
    </p>
    <p>
        <?= t('Im Spiel') ?>: <?= $d['in_game'] ? '<span class="badge badge-ok">✓</span>' : '<span class="muted">–</span>' ?> ·
        <?= t('Kanten') ?>: <strong><?= (int)$d['game_edges'] ?></strong> ·
        <?= t('Pässe aktiv/invalid') ?>: <?= (int)$d['passes_active'] ?> / <?= (int)$d['passes_invalid'] ?>
    </p>
    <?php if ($job !== null): ?>
        <p class="muted"><?= t('Letzter Ingest-Job') ?>:
            <span class="badge <?= $job['status'] === 'done' ? 'badge-ok' : ($job['status'] === 'failed' ? 'badge-err' : 'badge-warn') ?>"><?= $e($job['status']) ?></span>
            <?= $job['finished_at'] !== null ? $e($job['finished_at']) . ' UTC' : '' ?>
            <?= ($job['error_message'] ?? '') !== '' ? '· ' . $e($job['error_message']) : '' ?>
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= t('Aktionen') ?></h2>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end">
        <?php if ($can('ride.reingest')): ?>
            <form method="post" action="/admin/rides/<?= $id ?>/reingest"><input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                <button type="submit"><?= t('Erneut ins Spiel aufnehmen') ?></button></form>
        <?php endif; ?>
        <?php if ($can('ride.invalidate')): ?>
            <form method="post" action="/admin/rides/<?= $id ?>/invalidate" style="display:flex;gap:.3rem;align-items:end"
                  onsubmit="return confirm('<?= $e(t('Alle Pässe dieser Fahrt invalidieren?')) ?>')">
                <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                <label><?= t('Grund') ?><br><input type="text" name="reason"></label>
                <button type="submit"><?= t('Aus Spiel entfernen') ?></button>
            </form>
        <?php endif; ?>
        <?php if ($can('ride.delete')): ?>
            <form method="post" action="/admin/rides/<?= $id ?>/delete"
                  onsubmit="return confirm('<?= $e(t('Fahrt verbergen (soft-delete)?')) ?>')">
                <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                <button type="submit" style="color:var(--err,#c0392b)"><?= t('Verbergen') ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>
