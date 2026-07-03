<?php
/**
 * @var list<array{level:int,with_edges:int,owned:int,contested:int}> $summary
 * @var list<array{level:int,name:string,kind:string,country_code:?string,held_fraction:float,total_edges:int,owner_type:string,owner_name:string}> $owned
 * @var list<array{owner_type:string,owner_name:string,regions:int,municipalities:int,districts:int}> $topOwners
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$kindLabel = static function (int $level): string {
    return match ($level) {
        2 => 'Land', 4 => 'Bundesland', 6 => 'Landkreis', 8 => 'Gemeinde', default => 'Ebene ' . $level,
    };
};
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players"><?= t('Spieler') ?></a>
    <a href="/admin/game/crews">Crews</a>
    <a href="/admin/game/regions">Gebiete</a>
    <a href="/admin/game/edge">Inspector</a>
    <a href="/admin/game/map"><?= t('Karte') ?></a>
</nav>

<section class="card">
    <h1>Game · Gebiete</h1>
    <p class="muted">Städte-Eroberung: welche Crew/welcher Rider hält welche Verwaltungsgebiete. Nur Gebiete mit Spielkanten erscheinen.</p>
    <?php if ($summary === []): ?>
        <p class="muted">Noch kein Gebiets-Besitz berechnet. (Ingest fahren oder <code>/internal/cron/region-ownership</code> aufrufen.)</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Ebene</th><th>Mit Kanten</th><th>Erobert</th><th>Umkämpft</th></tr>
        </thead>
        <tbody>
        <?php foreach ($summary as $s): ?>
            <tr>
                <td><?= $e($kindLabel($s['level'])) ?> <span class="muted">(L<?= (int)$s['level'] ?>)</span></td>
                <td><?= (int)$s['with_edges'] ?></td>
                <td><?= (int)$s['owned'] ?></td>
                <td><?= (int)$s['contested'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php if ($topOwners !== []): ?>
<section class="card">
    <h2>Top-Besitzer</h2>
    <table class="data-table">
        <thead>
            <tr><th>#</th><th>Besitzer</th><th>Typ</th><th>Gebiete</th><th>Gemeinden</th><th>Landkreise</th></tr>
        </thead>
        <tbody>
        <?php foreach ($topOwners as $i => $o): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $e($o['owner_name']) ?></td>
                <td><?= $o['owner_type'] === 'group' ? 'Crew' : 'Solo' ?></td>
                <td><?= (int)$o['regions'] ?></td>
                <td><?= (int)$o['municipalities'] ?></td>
                <td><?= (int)$o['districts'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php if ($owned !== []): ?>
<section class="card">
    <h2>Eroberte Gebiete</h2>
    <table class="data-table">
        <thead>
            <tr><th>Ebene</th><th>Gebiet</th><th>Land</th><th>Besitzer</th><th>Typ</th><th>Anteil</th><th>Kanten</th></tr>
        </thead>
        <tbody>
        <?php foreach ($owned as $r): ?>
            <tr>
                <td><?= $e($kindLabel($r['level'])) ?></td>
                <td><?= $e($r['name']) ?></td>
                <td><?= $e($r['country_code'] ?? '—') ?></td>
                <td><?= $e($r['owner_name']) ?></td>
                <td><?= $r['owner_type'] === 'group' ? 'Crew' : 'Solo' ?></td>
                <td><?= number_format($r['held_fraction'] * 100, 0) ?>%</td>
                <td><?= (int)$r['total_edges'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
