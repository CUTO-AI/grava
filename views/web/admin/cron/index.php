<?php
/** @var list<array<string,mixed>> $rows */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ms = static function ($v): string {
    if ($v === null) { return '–'; }
    $v = (int)$v;
    return $v >= 1000 ? number_format($v / 1000, 2) . ' s' : $v . ' ms';
};
$ago = static function ($s): string {
    if ($s === null) { return 'nie'; }
    $s = (int)$s;
    if ($s < 90) { return $s . ' s'; }
    if ($s < 5400) { return round($s / 60) . ' min'; }
    if ($s < 172800) { return round($s / 3600) . ' h'; }
    return round($s / 86400) . ' d';
};
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/cron"><strong>Cron</strong></a>
    <a href="/admin/audit">Audit</a>
    <a href="/admin/roles">Rollen</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/uploads">Uploads</a>
    <a href="/admin/game/moderation">Moderation</a>
</nav>

<?php if ($flash !== null): ?>
    <section class="card"><p><?= $e($flash) ?></p></section>
<?php endif; ?>

<section class="card">
    <h1><?= t('Cron-Jobs') ?></h1>
    <p class="muted"><?= t('Überwachte Cron-Befehle: letzter Lauf, Erfolg, Dauer und Überfälligkeit. „Jetzt ausführen" startet den Befehl serverseitig.') ?></p>
    <table class="data" style="width:100%">
        <thead>
            <tr>
                <th><?= t('Befehl') ?></th>
                <th><?= t('Status') ?></th>
                <th><?= t('Letzter Lauf') ?></th>
                <th><?= t('Letzter Erfolg') ?></th>
                <th><?= t('Dauer (letzte)') ?></th>
                <th>p95</th>
                <th><?= t('24h (Läufe/Fehler)') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $last = $r['last'];
            $lastOk = $r['last_ok'];
            $status = $r['status'];
            $badge = $status === 'ok' ? 'badge-ok' : ($status === 'failed' ? 'badge-err' : 'badge-warn');
        ?>
            <tr>
                <td>
                    <a href="/admin/cron/<?= $e($r['command']) ?>"><strong><?= $e($r['label']) ?></strong></a><br>
                    <span class="muted" style="font-family:monospace"><?= $e($r['command']) ?></span>
                </td>
                <td>
                    <?php if ($r['never']): ?>
                        <span class="badge badge-warn"><?= t('nie gelaufen') ?></span>
                    <?php else: ?>
                        <span class="badge <?= $badge ?>"><?= $e($status) ?></span>
                    <?php endif; ?>
                    <?php if ($r['overdue'] && !$r['never']): ?>
                        <span class="badge badge-err"><?= t('überfällig') ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($last !== null): ?>
                        <?= $e($ago($r['age_s'])) ?> <?= t('her') ?>
                        <br><span class="muted"><?= $e($last['started_at']) ?> UTC</span>
                    <?php else: ?>–<?php endif; ?>
                </td>
                <td>
                    <?php if ($lastOk !== null): ?>
                        <span class="muted"><?= $e($lastOk['finished_at'] ?? $lastOk['started_at']) ?> UTC</span>
                    <?php else: ?>–<?php endif; ?>
                </td>
                <td><?= $e($ms($last['duration_ms'] ?? null)) ?></td>
                <td><?= $e($ms($r['p95_ms'])) ?></td>
                <td>
                    <?= (int)$r['agg']['runs'] ?> /
                    <?php if ((int)$r['agg']['failures'] > 0): ?>
                        <strong style="color:var(--err,#c0392b)"><?= (int)$r['agg']['failures'] ?></strong>
                    <?php else: ?>0<?php endif; ?>
                </td>
                <td>
                    <form method="post" action="/admin/cron/<?= $e($r['command']) ?>/run" style="margin:0">
                        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                        <button type="submit"<?= $r['interval_s'] >= 3600 ? ' title="' . $e(t('Langer Job – kann das Request-Timeout überschreiten.')) . '"' : '' ?>>
                            <?= t('Jetzt ausführen') ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
