<?php
/**
 * Gebiets-Detail (WebAnalytics_Concept.md): Kennzahlen, In-Gebiet-Bestenliste,
 * Breadcrumb (hoch) und Unter-Gebiete (runter). Reine Listendarstellung.
 *
 * @var array<string,mixed> $region  Ausgabe von RegionService::regionDetail()
 */
$e   = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$km  = static fn(float $m): string => number_format($m / 1000, 1);
$pct = static fn($f): string => number_format(((float)$f) * 100, 1) . ' %';
$levelLabel = static fn(int $lvl): string => match ($lvl) {
    2 => t('Land'), 4 => t('Bundesland'), 6 => t('Landkreis'), 8 => t('Gemeinde'),
    default => t('Gebiet'),
};
// Anzeigename eines Gebiets: Länder (Ebene 2) in der Web-Sprache (de/en) statt
// nativem Eigennamen (bessere Lesbarkeit); Untereinheiten behalten ihren Namen.
$displayName = static fn(array $r): string =>
    (int)($r['level'] ?? 0) === 2
        ? \App\Support\CountryName::localized($r['country_code'] ?? null, (string)($r['name'] ?? ''))
        : (string)($r['name'] ?? '');
// Anzeigename eines Claimants (Fahrer @handle bzw. Crew-Name); '—' wenn frei.
$claimantName = static function (?array $c) use ($e): string {
    if ($c === null || !isset($c['type'])) {
        return '—';
    }
    if ($c['type'] === 'group') {
        return $e($c['name'] ?? $c['handle'] ?? '—');
    }
    return !empty($c['handle']) ? '@' . $e($c['handle']) : $e($c['name'] ?? '—');
};

$owner    = $region['owner']    ?? null;
$contested = !empty($region['contested']);
$totalLen = (float)($region['total_game_length_m'] ?? 0.0);

// Unter-Gebiete absteigend nach Kanten (aktivste zuerst); Name als Tie-Break.
if (!empty($region['children'])) {
    usort($region['children'], static fn(array $a, array $b): int =>
        ((int)($b['total_edges'] ?? 0) <=> (int)($a['total_edges'] ?? 0))
        ?: strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
}
?>

<nav class="region-breadcrumb" aria-label="<?= te('Gebiets-Pfad') ?>">
    <a href="/gebiete"><?= t('Gebiete') ?></a>
    <?php foreach (($region['breadcrumb'] ?? []) as $b): ?>
        <span class="sep">›</span>
        <a href="/gebiete/<?= (int)$b['id'] ?>"><?= $e($displayName($b)) ?></a>
    <?php endforeach; ?>
    <span class="sep">›</span>
    <span><?= $e($displayName($region)) ?></span>
</nav>

<header class="page-header">
    <p class="cr-kicker"><?= $e($levelLabel((int)$region['level'])) ?></p>
    <h1><?= $e($displayName($region)) ?></h1>
    <p class="muted">
        <?php if ($contested || $owner === null): ?>
            <?= t('Umkämpft') ?> — <?= t('aktuell führend:') ?> <?= $claimantName($region['leader'] ?? null) ?>
        <?php else: ?>
            <?= t('Beherrscht von') ?> <?= $claimantName($owner) ?>
        <?php endif; ?>
    </p>
</header>

<section class="card">
    <div class="region-stats">
        <div class="region-stat">
            <div class="val"><?= (int)($region['total_edges'] ?? 0) ?></div>
            <div class="lbl"><?= t('Kanten gesamt') ?></div>
        </div>
        <div class="region-stat">
            <div class="val"><?= $km($totalLen) ?> km</div>
            <div class="lbl"><?= t('Streckenlänge') ?></div>
        </div>
        <div class="region-stat">
            <div class="val"><?= $claimantName($owner) ?></div>
            <div class="lbl"><?= $contested || $owner === null ? t('Status: umkämpft') : t('Besitzer') ?></div>
        </div>
        <?php if (isset($region['control_min_fraction']) && $region['control_min_fraction'] !== null): ?>
        <div class="region-stat">
            <div class="val"><?= $pct($region['control_min_fraction']) ?></div>
            <div class="lbl"><?= t('Kontroll-Schwelle') ?></div>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <h2 class="cr-h3"><?= t('Bestenliste im Gebiet') ?></h2>
    <p class="muted small"><?= t('Nach gehaltener Streckenlänge (inkl. Unter-Gebiete).') ?></p>
    <?php if (empty($region['leaderboard'])): ?>
        <p class="muted"><?= t('Noch keine gehaltenen Reviere.') ?></p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th><?= t('Fahrer / Crew') ?></th>
                <th class="num"><?= t('Revier (km)') ?></th>
                <th class="num"><?= t('Kanten') ?></th>
                <th class="num"><?= t('Anteil') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($region['leaderboard'] as $row): ?>
            <tr>
                <td><?= (int)$row['rank'] ?></td>
                <td><?= $claimantName($row) ?></td>
                <td class="num"><?= $km((float)($row['held_length_m'] ?? 0)) ?></td>
                <td class="num"><?= (int)($row['held_edges'] ?? 0) ?></td>
                <td class="num"><?= $pct($row['held_fraction'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php if (!empty($region['children'])): ?>
<section class="card">
    <h2 class="cr-h3"><?= t('Unter-Gebiete') ?></h2>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= t('Gebiet') ?></th>
                <th><?= t('Ebene') ?></th>
                <th class="num"><?= t('Kanten') ?></th>
                <th class="num"><?= t('Revier (km)') ?></th>
                <th class="num"><?= t('Erobert') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($region['children'] as $c): ?>
            <tr>
                <td><a href="/gebiete/<?= (int)$c['id'] ?>"><?= $e($displayName($c)) ?></a></td>
                <td><?= $e($levelLabel((int)$c['level'])) ?></td>
                <td class="num"><?= (int)($c['total_edges'] ?? 0) ?></td>
                <td class="num"><?= $km((float)($c['total_game_length_m'] ?? 0)) ?></td>
                <td class="num"><?= $pct($c['held_fraction'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
