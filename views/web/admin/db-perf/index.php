<?php
/** @var list<array<string,mixed>> $digests */
/** @var ?string $digestErr */
/** @var list<array<string,mixed>> $proc */
/** @var ?string $procErr */
/** @var list<string> $grants */
/** @var ?string $flash */
/** @var string $_csrf */
$e   = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn($v): string => $v === null ? '–' : number_format((float)$v, 0, '.', ' ');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/cron">Cron</a>
    <a href="/admin/db-perf"><strong>DB</strong></a>
    <a href="/admin/audit">Audit</a>
    <a href="/admin/roles">Rollen</a>
    <a href="/admin/game/config">Config</a>
</nav>

<?php if ($flash !== null): ?>
    <section class="card"><p><?= $e($flash) ?></p></section>
<?php endif; ?>

<section class="card">
    <h1><?= t('DB-Performance') ?></h1>
    <p class="muted"><?= t('Teuerste normalisierte Queries aus performance_schema (seit letztem Reset bzw. Server-Start). „Zurücksetzen" startet ein frisches Messfenster – danach kurz die App bedienen und diese Seite neu laden.') ?></p>
    <form method="post" action="/admin/db-perf/reset" style="margin:0 0 1rem">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <button type="submit"><?= t('Statistik zurücksetzen') ?></button>
    </form>
    <?php if ($digestErr !== null): ?>
        <p><span class="badge badge-err"><?= t('Kein Zugriff auf performance_schema') ?></span></p>
        <pre class="muted" style="white-space:pre-wrap"><?= $e($digestErr) ?></pre>
    <?php elseif (empty($digests)): ?>
        <p class="muted"><?= t('Noch keine Daten – Statistik zurücksetzen, App bedienen, neu laden.') ?></p>
    <?php else: ?>
    <table class="data" style="width:100%">
        <thead><tr>
            <th><?= t('Query (normalisiert)') ?></th>
            <th><?= t('Aufrufe') ?></th>
            <th><?= t('Summe') ?></th>
            <th>Ø ms</th>
            <th>Max ms</th>
            <th><?= t('Zeilen geprüft') ?></th>
            <th><?= t('Full-Scans') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($digests as $d): ?>
            <tr>
                <td style="max-width:520px">
                    <code style="white-space:pre-wrap;font-size:.8em"><?= $e($d['DIGEST_TEXT']) ?></code>
                    <br><span class="muted"><?= $e($d['SCHEMA_NAME'] ?? '') ?></span>
                </td>
                <td><?= $num($d['calls']) ?></td>
                <td><strong><?= $e($d['total_s']) ?> s</strong></td>
                <td><?= $e($d['avg_ms']) ?></td>
                <td><?= $e($d['max_ms']) ?></td>
                <td><?= $num($d['rows_examined']) ?></td>
                <td>
                    <?php $fs = (int)($d['full_scans'] ?? 0); ?>
                    <?php if ($fs > 0): ?>
                        <strong style="color:var(--err,#c0392b)"><?= $num($fs) ?></strong>
                    <?php else: ?>0<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= t('Aktuell laufende Abfragen') ?></h2>
    <p class="muted"><?= t('Threads, die gerade arbeiten (nicht „Sleep"), nach Laufzeit sortiert – zeigt, was in einem Hang klemmt.') ?></p>
    <?php if ($procErr !== null): ?>
        <p><span class="badge badge-err"><?= t('Kein PROCESS-Recht') ?></span></p>
        <pre class="muted" style="white-space:pre-wrap"><?= $e($procErr) ?></pre>
    <?php elseif (empty($proc)): ?>
        <p class="muted"><?= t('Gerade keine aktiven Abfragen.') ?></p>
    <?php else: ?>
    <table class="data" style="width:100%">
        <thead><tr><th>ID</th><th>DB</th><th><?= t('Dauer') ?></th><th>State</th><th>Query</th></tr></thead>
        <tbody>
        <?php foreach ($proc as $p): ?>
            <tr>
                <td><?= $e($p['ID']) ?></td>
                <td><?= $e($p['DB'] ?? '') ?></td>
                <td><strong><?= (int)$p['TIME'] ?> s</strong></td>
                <td><?= $e($p['STATE'] ?? '') ?></td>
                <td style="max-width:520px"><code style="white-space:pre-wrap;font-size:.8em"><?= $e($p['INFO'] ?? '') ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= t('DB-Benutzer-Rechte') ?></h2>
    <p class="muted"><?= t('Steht oben „kein Zugriff", fehlen diese Rechte. Einmalig als DB-Root setzen:') ?></p>
    <pre class="muted" style="white-space:pre-wrap">GRANT SELECT ON performance_schema.* TO CURRENT_USER;
GRANT PROCESS ON *.* TO CURRENT_USER;</pre>
    <?php if (!empty($grants)): ?>
        <p class="muted"><?= t('Aktuelle Grants:') ?></p>
        <pre class="muted" style="white-space:pre-wrap"><?php foreach ($grants as $g) { echo $e($g) . "\n"; } ?></pre>
    <?php endif; ?>
</section>
