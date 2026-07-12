<?php
/** @var string $command */
/** @var string $label */
/** @var list<array<string,mixed>> $runs */
/** @var int $page */
/** @var int $perPage */
/** @var int $total */
/** @var bool $hasMore */
/** @var ?string $flash */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ms = static function ($v): string {
    if ($v === null) { return '–'; }
    $v = (int)$v;
    return $v >= 1000 ? number_format($v / 1000, 2) . ' s' : $v . ' ms';
};
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/cron">&larr; <?= t('Alle Cron-Jobs') ?></a>
</nav>

<?php if ($flash !== null): ?>
    <section class="card"><p><?= $e($flash) ?></p></section>
<?php endif; ?>

<section class="card">
    <h1><?= $e($label) ?></h1>
    <p class="muted" style="font-family:monospace"><?= $e($command) ?> · <?= (int)$total ?> <?= t('Läufe') ?></p>
    <form method="post" action="/admin/cron/<?= $e($command) ?>/run" style="margin:0 0 1rem">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <button type="submit"><?= t('Jetzt ausführen') ?></button>
    </form>
    <table class="data" style="width:100%">
        <thead>
            <tr>
                <th><?= t('Start (UTC)') ?></th>
                <th><?= t('Status') ?></th>
                <th><?= t('Dauer') ?></th>
                <th><?= t('Auslöser') ?></th>
                <th>exit</th>
                <th><?= t('Details') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($runs as $run):
            $badge = $run['status'] === 'ok' ? 'badge-ok' : ($run['status'] === 'failed' ? 'badge-err' : 'badge-warn');
            $hasDetail = ($run['output_tail'] ?? '') !== '' || ($run['error_message'] ?? '') !== '';
        ?>
            <tr>
                <td><?= $e($run['started_at']) ?><?= (int)($run['did_work'] ?? 1) === 0 ? ' <span class="muted">(idle)</span>' : '' ?></td>
                <td><span class="badge <?= $badge ?>"><?= $e($run['status']) ?></span></td>
                <td><?= $e($ms($run['duration_ms'] ?? null)) ?></td>
                <td><?= $e($run['trigger_kind'] ?? '') ?></td>
                <td><?= $run['exit_code'] === null ? '–' : (int)$run['exit_code'] ?></td>
                <td>
                    <?php if ($hasDetail): ?>
                        <details>
                            <summary><?= t('anzeigen') ?></summary>
                            <?php if (($run['error_message'] ?? '') !== ''): ?>
                                <pre style="color:var(--err,#c0392b);white-space:pre-wrap"><?= $e($run['error_message']) ?></pre>
                            <?php endif; ?>
                            <?php if (($run['output_tail'] ?? '') !== ''): ?>
                                <pre style="white-space:pre-wrap;max-height:20rem;overflow:auto"><?= $e($run['output_tail']) ?></pre>
                            <?php endif; ?>
                        </details>
                    <?php else: ?>–<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($runs === []): ?>
            <tr><td colspan="6" class="muted"><?= t('Noch keine Läufe.') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p>
        <?php if ($page > 1): ?>
            <a href="/admin/cron/<?= $e($command) ?>?page=<?= $page - 1 ?>">&larr; <?= t('neuer') ?></a>
        <?php endif; ?>
        <?php if ($hasMore): ?>
            <a href="/admin/cron/<?= $e($command) ?>?page=<?= $page + 1 ?>"><?= t('älter') ?> &rarr;</a>
        <?php endif; ?>
    </p>
</section>
