<?php
/** @var list<array<string,mixed>> $crews */
/** @var list<array<string,mixed>> $factions */
/** @var array<string,mixed> $regionSummary */
/** @var list<array<string,mixed>> $regionOwned */
/** @var string $role */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$can = static fn(string $p): bool => \App\Game\Admin\AdminPermissions::can($role, $p);
$km = static fn($m): string => number_format((float)$m / 1000, 1) . ' km';
$ownerLabel = static function ($o) use ($e): string {
    if (is_array($o)) { return $e($o['name'] ?? $o['handle'] ?? $o['key'] ?? '—'); }
    return $o === null ? '—' : $e($o);
};
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin">Übersicht</a>
    <a href="/admin/community"><strong>Community</strong></a>
    <a href="/admin/users">User</a>
    <a href="/admin/game/map">Karte</a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= t('Community') ?></h1>
    <h2><?= t('Crews') ?></h2>
    <table class="data" style="width:100%">
        <thead><tr><th><?= t('Name') ?></th><th>Captain</th><th><?= t('Mitglieder') ?></th><th><?= t('Kanten') ?></th><th><?= t('Revierlänge') ?></th><th>Pionier</th></tr></thead>
        <tbody>
        <?php foreach ($crews as $c): ?>
            <tr>
                <td><a href="/admin/community/crew/<?= (int)$c['crew_id'] ?>"><?= $e($c['name']) ?></a> <span class="muted">/<?= $e($c['slug']) ?></span></td>
                <td><?= $c['captain_handle'] !== null ? '@' . $e($c['captain_handle']) : '<span class="muted">–</span>' ?></td>
                <td><?= (int)$c['members'] ?></td>
                <td><?= (int)$c['held_edges'] ?></td>
                <td><?= $e($km($c['held_length_m'])) ?></td>
                <td><?= (int)$c['pioneered'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($crews === []): ?><tr><td colspan="6" class="muted"><?= t('Keine Crews.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2><?= t('Fraktionen') ?></h2>
    <table class="data" style="width:100%">
        <thead><tr><th><?= t('Fraktion') ?></th><th>Crews</th><th><?= t('Mitglieder') ?></th><th><?= t('Revierlänge') ?></th><th><?= t('Zellen') ?></th></tr></thead>
        <tbody>
        <?php foreach ($factions as $f): ?>
            <tr>
                <td><span class="badge" style="background:<?= $e($f['color'] ?? '#666') ?>;color:#fff"><?= $e($f['name']) ?></span></td>
                <td><?= (int)($f['crews'] ?? 0) ?></td>
                <td><?= (int)($f['members'] ?? 0) ?></td>
                <td><?= $e($km($f['held_length_m'] ?? 0)) ?></td>
                <td><?= (int)($f['cells'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($factions === []): ?><tr><td colspan="5" class="muted"><?= t('Keine Fraktionsdaten.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2><?= t('Gebiete') ?></h2>
    <?php if ($can('region.manage')): ?>
        <form method="post" action="/admin/community/regions/recompute" style="margin-bottom:.75rem">
            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
            <button type="submit"><?= t('Besitz neu berechnen') ?></button>
        </form>
    <?php endif; ?>
    <p class="muted">
        <?php foreach ($regionSummary as $k => $v): ?><?= $e((string)$k) ?>: <strong><?= $e(is_scalar($v) ? (string)$v : json_encode($v)) ?></strong> · <?php endforeach; ?>
    </p>
    <table class="data" style="width:100%">
        <thead><tr><th><?= t('Gebiet') ?></th><th><?= t('Ebene') ?></th><th><?= t('Besitzer') ?></th></tr></thead>
        <tbody>
        <?php foreach ($regionOwned as $r): ?>
            <tr><td><?= $e($r['name'] ?? '–') ?></td><td><?= (int)($r['level'] ?? 0) ?></td><td><?= $ownerLabel($r['owner'] ?? null) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($regionOwned === []): ?><tr><td colspan="3" class="muted"><?= t('Keine eroberten Gebiete.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
