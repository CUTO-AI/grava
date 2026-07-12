<?php
/** @var list<array{d:string,n:int}> $signups */
/** @var list<array{d:string,n:int}> $rides */
/** @var array<string,int> $sources */
/** @var array{cohorts:list<array{week:string,size:int,retained:array<int,int>}>,maxOffset:int} $retention */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$maxN = static fn(array $rows): int => (int)max(1, ...array_map(static fn($r) => (int)$r['n'], $rows ?: [['n' => 1]]));
$bars = static function (array $rows) use ($e, $maxN): string {
    if ($rows === []) { return '<p class="muted">—</p>'; }
    $m = $maxN($rows);
    $out = '<div style="display:flex;gap:2px;align-items:flex-end;height:80px">';
    foreach ($rows as $r) {
        $h = max(2, (int)round((int)$r['n'] / $m * 78));
        $out .= '<div title="' . $e($r['d'] . ': ' . $r['n']) . '" style="flex:1;height:' . $h . 'px;background:var(--accent,#4a5643)"></div>';
    }
    return $out . '</div>';
};
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin">Übersicht</a>
    <a href="/admin/analytics"><strong>Analytics</strong></a>
    <a href="/admin/users">User</a>
    <a href="/admin/rides">Fahrten</a>
</nav>

<section class="card">
    <h1><?= t('Analytics') ?></h1>
    <p>
        <a href="/admin/analytics/users.csv"><?= t('User-Export (CSV)') ?></a> ·
        <a href="/admin/analytics/rides.csv"><?= t('Fahrten-Export (CSV)') ?></a>
    </p>
</section>

<section class="card">
    <h2><?= t('Signups / Tag (30T)') ?></h2>
    <?= $bars($signups) ?>
    <h2 style="margin-top:1rem"><?= t('Fahrten / Tag (30T)') ?></h2>
    <?= $bars($rides) ?>
</section>

<section class="card">
    <h2><?= t('Fahrten nach Quelle (30T)') ?></h2>
    <table class="data">
        <thead><tr><th><?= t('Quelle') ?></th><th><?= t('Anzahl') ?></th></tr></thead>
        <tbody>
        <?php foreach ($sources as $src => $n): ?>
            <tr><td><span class="badge"><?= $e($src) ?></span></td><td><?= (int)$n ?></td></tr>
        <?php endforeach; ?>
        <?php if ($sources === []): ?><tr><td colspan="2" class="muted"><?= t('Keine Daten.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2><?= t('Wöchentliche Retention (Signup-Kohorten)') ?></h2>
    <p class="muted"><?= t('Anteil der Kohorte mit ≥1 Fahrt in Woche N nach Signup.') ?></p>
    <table class="data" style="width:100%">
        <thead>
            <tr><th><?= t('Kohorte (Woche)') ?></th><th><?= t('Größe') ?></th>
                <?php for ($k = 0; $k <= $retention['maxOffset']; $k++): ?><th>W<?= $k ?></th><?php endfor; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($retention['cohorts'] as $c): ?>
            <tr>
                <td><?= $e($c['week']) ?></td>
                <td><?= (int)$c['size'] ?></td>
                <?php for ($k = 0; $k <= $retention['maxOffset']; $k++):
                    $ret = (int)($c['retained'][$k] ?? 0);
                    $pct = $c['size'] > 0 ? round($ret / $c['size'] * 100) : 0;
                    $shade = (int)round($pct * 0.6); ?>
                    <td style="background:rgba(74,86,67,<?= $pct / 100 ?>);color:<?= $pct > 55 ? '#fff' : 'inherit' ?>">
                        <?= $ret > 0 ? $pct . '%' : '<span class="muted">–</span>' ?>
                    </td>
                <?php endfor; ?>
            </tr>
        <?php endforeach; ?>
        <?php if ($retention['cohorts'] === []): ?><tr><td colspan="<?= $retention['maxOffset'] + 3 ?>" class="muted"><?= t('Keine Kohorten.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
