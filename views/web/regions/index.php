<?php
/**
 * Gebiets-Einstieg (WebAnalytics_Concept.md): Liste der Länder. Klick führt in
 * den Drilldown (/gebiete/{id}).
 *
 * @var list<array<string,mixed>> $regions
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$pct = static fn($f): string => number_format(((float)$f) * 100, 1) . ' %';
$km  = static fn(float $m): string => number_format($m / 1000, 1);
// Ländername in der Web-Sprache (de/en) statt nativem Eigennamen (besser lesbar).
$countryName = static fn(array $r): string =>
    \App\Support\CountryName::localized($r['country_code'] ?? null, (string)($r['name'] ?? ''));
// Absteigend nach Kanten (aktivste Gebiete zuerst); Name als stabiler Tie-Break.
usort($regions, static fn(array $a, array $b): int =>
    ((int)($b['total_edges'] ?? 0) <=> (int)($a['total_edges'] ?? 0))
    ?: strcasecmp($countryName($a), $countryName($b)));
?>

<header class="page-header">
    <p class="cr-kicker"><?= t('Revierkampf · Gebiete') ?></p>
    <h1><?= t('Gebiete') ?></h1>
    <p class="muted">
        <?= t('Vom Land bis zur Gemeinde: wähle ein Gebiet, um Reviere, Kanten und die Bestenliste vor Ort zu sehen.') ?>
        <a href="/gebiete/karte"><?= t('Zur Kartenansicht') ?></a>
    </p>
</header>

<section class="card">
    <h2 class="cr-h3"><?= t('Länder') ?></h2>
    <?php if (empty($regions)): ?>
        <p class="muted"><?= t('Noch keine Gebiete verfügbar.') ?></p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= t('Land') ?></th>
                <th class="num"><?= t('Kanten') ?></th>
                <th class="num"><?= t('Revier (km)') ?></th>
                <th class="num"><?= t('Erobert') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($regions as $r): ?>
            <tr>
                <td><a href="/gebiete/<?= (int)$r['id'] ?>"><?= $e($countryName($r)) ?></a></td>
                <td class="num"><?= (int)$r['total_edges'] ?></td>
                <td class="num"><?= $km((float)($r['total_game_length_m'] ?? 0)) ?></td>
                <td class="num"><?= $pct($r['held_fraction'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
