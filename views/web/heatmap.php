<?php
/** @var list<array{lat:float,lon:float,weight:int}> $cells */
/** @var array{grid:float,cell_count:int,max_weight:int} $meta */
/** @var array<string,mixed>|null $_authedUser */
/** @var bool $linesEnabled */

$h = static fn(string|int|float|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$num = static fn(float $v): string => rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
$maxW = max(1, (int)($meta['max_weight'] ?? 1));
$linesEnabled = !empty($linesEnabled);

$_pageStyles  = ['/assets/vendor/leaflet/leaflet.css'];
$_pageScripts = [
    '/assets/vendor/leaflet/leaflet.js',
    '/assets/vendor/leaflet/leaflet-heat.js',
    '/assets/js/map-core.js',
    '/assets/js/map-heatmap.js',
];
?>

<header class="page-header">
    <h1><?= t('Crowd-Heatmap') ?></h1>
    <p class="muted">
        <?= t('Aggregierte Dichte öffentlicher Routen in einem') ?>
        <?= $h($num((float)($meta['grid'] ?? 0))) ?>°-Raster
        (~<?= $h(round(((float)($meta['grid'] ?? 0)) * 111)) ?> <?= t('km pro Zelle') ?>).
        <?= t('Anonym &amp; vorberechnet.') ?>
    </p>
</header>

<div id="map" class="map map--full"
     data-heatmap-url="/api/v1/heatmap"
     <?php if ($linesEnabled): ?>data-lines-url="/api/v1/heatmap/lines"<?php endif; ?>></div>
<div id="map-legend" class="map-legend" hidden></div>
<?php if ($linesEnabled): ?>
<p class="muted map-hint">
    <?= t('Oben rechts umschaltbar:') ?> <strong><?= t('Dichte') ?></strong> <?= t('(Raster-Heatmap) und') ?>
    <strong><?= t('Strecken') ?></strong> <?= t('— die tatsächlich gefahrenen Wege, aufs Straßennetz gematcht. Linienfarbe = Ø Untergrund, Breite = Häufigkeit. Die Strecken-Ebene lädt den jeweils sichtbaren Kartenausschnitt nach.') ?>
</p>
<?php else: ?>
<p class="muted map-hint">
    <strong><?= t('Dichte') ?></strong> <?= t('(Raster-Heatmap) der öffentlichen Routen.') ?>
</p>
<?php endif; ?>

<?php if (empty($cells)): ?>
    <div class="empty-state">
        <p><?= t('Noch keine Daten. Sobald öffentliche Routen existieren und die Aggregation lief') ?> (<code>cron:heatmap</code>)<?= t(', erscheinen hier die heißesten Regionen.') ?></p>
    </div>
<?php else: ?>
    <p class="muted"><?= $h((int)($meta['cell_count'] ?? 0)) ?> <?= t('Zellen · stärkste Zelle:') ?> <?= $h($maxW) ?> <?= t('Routen') ?></p>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= t('Intensität') ?></th>
                <th class="num"><?= t('Routen') ?></th>
                <th class="num"><?= t('Breite (lat)') ?></th>
                <th class="num"><?= t('Länge (lon)') ?></th>
                <th><?= t('Karte') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cells as $c):
            $pct = (int)round(($c['weight'] / $maxW) * 100); ?>
            <tr>
                <td>
                    <span class="heat-bar" style="width: <?= $h(max(4, $pct)) ?>%"></span>
                </td>
                <td class="num"><?= $h($c['weight']) ?></td>
                <td class="num"><?= $h($num($c['lat'])) ?></td>
                <td class="num"><?= $h($num($c['lon'])) ?></td>
                <td>
                    <a href="/discover?bbox=<?= $h($num($c['lat'] - 0.05)) ?>,<?= $h($num($c['lon'] - 0.05)) ?>,<?= $h($num($c['lat'] + 0.05)) ?>,<?= $h($num($c['lon'] + 0.05)) ?>"
                       class="btn-link"><?= t('Routen hier →') ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted">
        <?= t('Maschinenlesbar als GeoJSON:') ?> <code>GET /api/v1/heatmap</code>
        (<?= t('optional') ?> <code>?bbox=minLon,minLat,maxLon,maxLat</code>).
    </p>
<?php endif; ?>
