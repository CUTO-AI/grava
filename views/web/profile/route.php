<?php
/** @var array<string,mixed> $route */
/** @var array<string,mixed> $profile */
/** @var array<string,mixed>|null $_authedUser */
/** @var array{count:int,liked_by_viewer:bool,recent:list<string>} $likes */
/** @var list<array<string,mixed>> $comments */
/** @var array<string,mixed> $commentsPagination */
/** @var string $_csrf */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$kmFromMeters = static fn(?int $m): string => $m === null ? '—' : number_format($m / 1000, 1, ',', '.') . ' km';
$stats = $route['stats'] ?? [];
$bbox  = $stats['bbox'] ?? null;
$cent  = $stats['centroid'] ?? null;
$handle = (string)$profile['handle'];
$likes = $likes ?? ['count' => 0, 'liked_by_viewer' => false, 'recent' => []];
$comments = $comments ?? [];
$commentsPagination = $commentsPagination ?? ['total' => 0];
$pid = (string)$route['id'];

$_pageStyles  = ['/assets/vendor/leaflet/leaflet.css'];
$_pageScripts = [
    '/assets/vendor/leaflet/leaflet.js',
    '/assets/js/map-core.js',
    '/assets/js/map-route.js',
];
?>

<header class="page-header">
    <h1><?= $h($route['title']) ?></h1>
    <p class="muted">
        <?= t('Von') ?> <a href="/u/<?= $h($handle) ?>">@<?= $h($handle) ?></a>
        · <?= t('erstellt am') ?> <?= $h(substr($route['created_at'] ?? '', 0, 10)) ?>
        <span class="badge badge-public"><?= t('public') ?></span>
    </p>

    <div class="like-bar">
        <?php if ($_authedUser !== null): ?>
            <?php if (!empty($likes['liked_by_viewer'])): ?>
                <form method="post" action="/u/<?= $h($handle) ?>/r/<?= $h($route['id']) ?>/unlike" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                    <button type="submit" class="btn-secondary">♥ <?= t('Geliked') ?></button>
                </form>
            <?php else: ?>
                <form method="post" action="/u/<?= $h($handle) ?>/r/<?= $h($route['id']) ?>/like" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                    <button type="submit" class="btn-primary">♡ <?= t('Liken') ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <span class="like-count"><strong><?= $h((int)$likes['count']) ?></strong> Like<?= (int)$likes['count'] === 1 ? '' : 's' ?></span>
        <?php if (!empty($likes['recent'])): ?>
            <span class="muted">— <?= t('zuletzt:') ?>
                <?php foreach ($likes['recent'] as $i => $rh): ?><?= $i > 0 ? ', ' : '' ?><a href="/u/<?= $h($rh) ?>">@<?= $h($rh) ?></a><?php endforeach; ?>
            </span>
        <?php endif; ?>
    </div>
</header>

<div id="map" class="map map--detail"
     data-geojson-url="/u/<?= $h(rawurlencode($handle)) ?>/r/<?= $h(rawurlencode($pid)) ?>/geojson"></div>
<div id="map-legend" class="map-legend" hidden></div>

<?php if (!empty($insights)) { include __DIR__ . '/../partials/route-insights.php'; } ?>

<?php if (!empty($route['description'])): ?>
    <section class="route-description">
        <h2><?= t('Beschreibung') ?></h2>
        <p><?= nl2br($h($route['description'])) ?></p>
    </section>
<?php endif; ?>

<section>
    <h2><?= t('Statistik') ?></h2>
    <dl class="profile">
        <dt><?= t('Distanz') ?></dt>      <dd><?= $h($kmFromMeters($stats['distance_m'] ?? null)) ?></dd>
        <dt><?= t('Höhenmeter') ?></dt>   <dd><?= $h($stats['elevation_gain_m'] ?? '—') ?> m</dd>
        <dt><?= t('Punkte') ?></dt>       <dd><?= $h($stats['point_count'] ?? '—') ?></dd>
        <dt><?= t('Format') ?></dt>       <dd><?= $h($route['format'] ?? '—') ?></dd>
        <?php if ($bbox): ?>
            <dt><?= t('Bounding Box') ?></dt>
            <dd><code><?= $h(sprintf('%.4f, %.4f → %.4f, %.4f', $bbox['min_lat'], $bbox['min_lon'], $bbox['max_lat'], $bbox['max_lon'])) ?></code></dd>
        <?php endif; ?>
        <?php if ($cent): ?>
            <dt>Centroid</dt>
            <dd><code><?= $h(sprintf('%.4f, %.4f', $cent['lat'], $cent['lon'])) ?></code></dd>
        <?php endif; ?>
    </dl>
</section>

<?php if (!empty($route['tags'])): ?>
    <section>
        <h2><?= t('Tags') ?></h2>
        <p>
            <?php foreach ($route['tags'] as $t): ?>
                <a href="/discover?tag=<?= $h($t) ?>" class="tag">#<?= $h($t) ?></a>
            <?php endforeach; ?>
        </p>
    </section>
<?php endif; ?>

<section class="comments">
    <h2><?= t('Kommentare') ?> (<?= $h((int)($commentsPagination['total'] ?? 0)) ?>)</h2>

    <?php if ($_authedUser !== null): ?>
        <form method="post" action="/u/<?= $h($handle) ?>/r/<?= $h($pid) ?>/comment" class="comment-form">
            <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
            <label>
                <?= t('Kommentar schreiben') ?>
                <textarea name="body" rows="3" maxlength="2000" required placeholder="<?= te('Schöne Tour!') ?>"></textarea>
            </label>
            <div class="form-actions">
                <button type="submit"><?= t('Kommentieren') ?></button>
            </div>
        </form>
    <?php else: ?>
        <p class="muted"><a href="/login"><?= t('Melde dich an') ?></a><?= t(', um zu kommentieren.') ?></p>
    <?php endif; ?>

    <?php if (empty($comments)): ?>
        <div class="empty-state"><p><?= t('Noch keine Kommentare.') ?></p></div>
    <?php else: ?>
        <ul class="comment-list">
            <?php foreach ($comments as $c):
                $ah = $c['author']['handle'] ?? null; ?>
                <li class="comment">
                    <div class="comment-head">
                        <?php if ($ah): ?>
                            <a href="/u/<?= $h($ah) ?>">@<?= $h($ah) ?></a>
                        <?php else: ?>
                            <span class="muted"><?= $h($c['author']['display_name'] ?? t('Unbekannt')) ?></span>
                        <?php endif; ?>
                        <span class="muted comment-date"><?= $h(substr((string)$c['created_at'], 0, 10)) ?></span>
                    </div>
                    <div class="comment-body"><?= nl2br($h($c['body'])) ?></div>
                    <?php if (!empty($c['can_delete'])): ?>
                        <form method="post" action="/u/<?= $h($handle) ?>/r/<?= $h($pid) ?>/comments/<?= $h($c['id']) ?>/delete" class="inline-form" onsubmit="return confirm('<?= t('Kommentar löschen?') ?>');">
                            <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                            <button type="submit" class="btn-link comment-delete"><?= t('löschen') ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<p>
    <a href="/u/<?= $h($handle) ?>" class="btn-link">← <?= t('Zurück zu') ?> @<?= $h($handle) ?><?= t('\'s Profil') ?></a>
</p>
