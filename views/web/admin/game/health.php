<?php
/** @var array{nodes:int,edges:int,passes_total:int,passes_24h:int,active_riders_90d:int} $metrics */
/** @var array{ok:int,pending:int,failed:int,match_rate:float} $ingestHealth */
/** @var array{reachable:bool,base_url:string,version:?string,tileset_last_modified:?string,latency_ms:?int,error:?string} $valhalla */
/** @var list<array{name:string,reachable:bool,base_url:string,version:?string,tileset_last_modified:?string,latency_ms:?int,error:?string}>|null $valhallaRegions */
/** @var list<array<string,mixed>> $audits */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/uploads">Uploads</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players"><?= t('Spieler') ?></a>
    <a href="/admin/game/player"><?= t('Spieler-Detail') ?></a>
    <a href="/admin/game/crews">Crews</a>
    <a href="/admin/game/edge">Inspector</a>
    <a href="/admin/game/map"><?= t('Karte') ?></a>
</nav>
<section class="card">
    <h1>Game · Health</h1>
    <p class="muted">
        <?= t('Knoten') ?>: <strong><?= (int)$metrics['nodes'] ?></strong> ·
        <?= t('Kanten') ?>: <strong><?= (int)$metrics['edges'] ?></strong> ·
        <?= t('Pässe gesamt') ?>: <strong><?= (int)$metrics['passes_total'] ?></strong> ·
        <?= t('Pässe 24h') ?>: <strong><?= (int)$metrics['passes_24h'] ?></strong> ·
        <?= t('Aktive Fahrer 90T') ?>: <strong><?= (int)$metrics['active_riders_90d'] ?></strong>
    </p>
    <p class="muted">
        <?= t('Ingest — ok') ?>: <strong><?= (int)$ingestHealth['ok'] ?></strong> ·
        pending: <strong><?= (int)$ingestHealth['pending'] ?></strong> ·
        failed: <strong><?= (int)$ingestHealth['failed'] ?></strong> ·
        <?= t('Match-Rate') ?>: <strong><?= number_format((float)$ingestHealth['match_rate'] * 100, 1) ?>&nbsp;%</strong>
    </p>
</section>
<section class="card">
    <h2>Valhalla · Map-Matching</h2>
    <?php if ($valhalla['reachable']): ?>
        <p>
            <span class="badge badge-ok"><?= t('erreichbar') ?></span>
        </p>
        <p class="muted">
            URL: <strong><?= $e($valhalla['base_url']) ?></strong> ·
            Version: <strong><?= $e($valhalla['version'] ?? '—') ?></strong> ·
            Tileset: <strong><?= $e($valhalla['tileset_last_modified'] ?? '—') ?></strong> ·
            <?= t('Antwortzeit') ?>: <strong><?= (int)($valhalla['latency_ms'] ?? 0) ?>&nbsp;ms</strong>
        </p>
    <?php else: ?>
        <p>
            <span class="badge" style="background:var(--error-bg);color:var(--error-text)"><?= t('nicht erreichbar') ?></span>
        </p>
        <p class="muted">
            URL: <strong><?= $e($valhalla['base_url']) ?></strong>
            <?php if (($valhalla['error'] ?? null) !== null): ?> · <?= t('Fehler') ?>: <strong><?= $e($valhalla['error']) ?></strong><?php endif; ?>
            <?php if (($valhalla['latency_ms'] ?? null) !== null): ?> · <?= t('nach') ?> <strong><?= (int)$valhalla['latency_ms'] ?>&nbsp;ms</strong><?php endif; ?>
        </p>
        <p class="muted">
            <?= t('Solange Valhalla unten ist, schlägt „Strecke ins Spiel übernehmen" mit') ?>
            <code>routing_unavailable</code> <?= t('fehl (Upload bleibt gespeichert, Re-Ingest möglich). Dienst/Tunnel prüfen — siehe') ?> <code>docs/LOCAL_DEV_STARTUP.md</code>.
        </p>
    <?php endif; ?>
</section>
<?php if (!empty($valhallaRegions)): ?>
<section class="card">
    <h2>Valhalla-Instanzen · <?= t('pro Kontinent (Cloudflare)') ?></h2>
    <p class="muted"><?= t('Jede Region hat eine eigene lokale Valhalla-Instanz über einen eigenen Cloudflare-Tunnel. Das Backend wählt pro Fahrt anhand der Koordinaten.') ?></p>
    <table class="data">
        <thead>
            <tr>
                <th><?= t('Region') ?></th>
                <th><?= t('Status') ?></th>
                <th>URL</th>
                <th>Version</th>
                <th>Tileset</th>
                <th><?= t('Antwortzeit') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($valhallaRegions as $vr): ?>
            <tr>
                <td><strong><?= $e(strtoupper($vr['name'])) ?></strong></td>
                <td>
                    <?php if ($vr['reachable']): ?>
                        <span class="badge badge-ok"><?= t('erreichbar') ?></span>
                    <?php else: ?>
                        <span class="badge" style="background:var(--error-bg);color:var(--error-text)"><?= t('nicht erreichbar') ?></span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= $e($vr['base_url']) ?></td>
                <td class="muted"><?= $e($vr['version'] ?? '—') ?></td>
                <td class="muted"><?= $e($vr['tileset_last_modified'] ?? '—') ?></td>
                <td class="muted">
                    <?php if ($vr['reachable']): ?>
                        <?= (int)($vr['latency_ms'] ?? 0) ?>&nbsp;ms
                    <?php elseif (($vr['error'] ?? null) !== null): ?>
                        <?= t('Fehler') ?>: <?= $e($vr['error']) ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
<section class="card">
    <h2><?= t('Letzte Admin-Aktionen') ?></h2>
    <?php if ($audits === []): ?>
        <p class="muted"><?= t('Keine Aktionen protokolliert.') ?></p>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th><?= t('Zeit') ?></th><th>Admin</th><th><?= t('Aktion') ?></th><th><?= t('Ziel') ?></th></tr></thead>
        <tbody>
        <?php foreach ($audits as $a): ?>
            <tr>
                <td><?= $e($a['created_at'] ?? '') ?></td>
                <td><?= (int)($a['admin_user_id'] ?? 0) ?></td>
                <td><?= $e($a['action'] ?? '') ?></td>
                <td><?= $e($a['target'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
