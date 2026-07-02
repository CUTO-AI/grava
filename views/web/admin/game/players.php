<?php
/** @var list<array{user_id:int,claimant_id:?int,handle:?string,display_name:?string,status:string,held_edges:int,held_length_m:float,pioneered:int,passes:int,edges_ridden:int}> $rows */
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
    <h1>Game · <?= t('Spieler') ?></h1>
    <p class="muted"><?= t('Alle aktiven Nutzer.') ?> <strong><?= t('Solo-Besitz') ?></strong> <?= t('(Kanten/Länge/Pioniert) zählt nur eigenes Revier — Crew-/Fraktions-Besitz erscheint auf der Crews-Seite.') ?> <strong><?= t('Aktivität') ?></strong> <?= t('zeigt befahrene Kanten unabhängig vom Besitz.') ?></p>
    <?php if ($rows === []): ?>
        <p class="muted"><?= t('Keine aktiven Spieler.') ?></p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th><th><?= t('Fahrer') ?></th>
                <th><?= t('Solo-Kanten') ?></th><th><?= t('Länge (km)') ?></th><th><?= t('Pioniert') ?></th>
                <th><?= t('Pässe') ?></th><th><?= t('Kanten gefahren') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td>
                    <?php if ($r['handle'] !== null): ?>
                        <a href="/admin/game/player?q=<?= urlencode((string)$r['handle']) ?>">@<?= $e($r['handle']) ?></a>
                    <?php else: ?>
                        <a href="/admin/game/player?q=<?= urlencode((string)($r['display_name'] ?? ('#' . $r['user_id']))) ?>"><?= $e($r['display_name'] ?? ('#' . $r['user_id'])) ?></a>
                        <span class="muted" style="font-size:.8rem"><?= t('(kein Handle)') ?></span>
                    <?php endif; ?>
                </td>
                <td><?= (int)$r['held_edges'] ?></td>
                <td><?= number_format((float)$r['held_length_m'] / 1000, 1) ?></td>
                <td><?= (int)$r['pioneered'] ?></td>
                <td><?= (int)$r['passes'] ?></td>
                <td><?= (int)$r['edges_ridden'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
