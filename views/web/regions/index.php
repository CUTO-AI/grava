<?php
/**
 * Gebiets-Einstieg (WebAnalytics_Concept.md): Liste der Länder. Klick führt in
 * den Drilldown (/gebiete/{id}).
 *
 * @var list<array<string,mixed>> $regions
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$pct = static fn($f): string => number_format(((float)$f) * 100, 1) . ' %';
?>

<header class="page-header">
    <p class="cr-kicker"><?= t('Revierkampf · Gebiete') ?></p>
    <h1><?= t('Gebiete') ?></h1>
    <p class="muted">
        <?= t('Vom Land bis zur Gemeinde: wähle ein Gebiet, um Reviere, Kanten und die Bestenliste vor Ort zu sehen.') ?>
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
                <th class="num"><?= t('Erobert') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($regions as $r): ?>
            <tr>
                <td><a href="/gebiete/<?= (int)$r['id'] ?>"><?= $e($r['name']) ?></a></td>
                <td class="num"><?= (int)$r['total_edges'] ?></td>
                <td class="num"><?= $pct($r['held_fraction'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
