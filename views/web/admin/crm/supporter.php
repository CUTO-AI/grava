<?php
/** @var string $period */
/** @var list<array<string,mixed>> $rows */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$eur = static fn($ct): string => number_format(((int)$ct) / 100, 2, ',', '.') . ' €';
$basisTotal = 0; $bonusTotal = 0;
foreach ($rows as $r) { $basisTotal += (int)$r['basis_ct']; $bonusTotal += (int)$r['bonus_ct']; }
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/crm">Vereins-CRM</a>
    <a href="/admin/crm/supporter"><strong>Supporter-Ökonomie</strong></a>
</nav>

<section class="card">
    <h1>Supporter-Ökonomie · <?= $e($period) ?></h1>
    <p class="muted">Read-only Messung (A8). Basis = gedeckelte km × ct-Satz; Champion = größter Revieranteil je Landkreis; Bonus = Top-3 50/30/20. <strong>Keine Auszahlung</strong> — reine Kalkulation (Kill-or-Keep-Test).</p>
    <form method="get" action="/admin/crm/supporter" class="inline-form">
        <input type="month" name="period" value="<?= $e($period) ?>">
        <button type="submit">Anzeigen</button>
    </form>
    <p class="muted" style="margin-top:.5rem">Snapshot aktualisieren: Cron <code>supporter:snapshot-monthly</code> bzw. <code>/internal/supporter/snapshot?token=…&amp;period=<?= $e($period) ?></code> (nur bei <code>supporter_program_enabled=1</code>).</p>
</section>

<section class="card">
    <h2>Ergebnis (<?= $eur($basisTotal + $bonusTotal) ?> gesamt — Basis <?= $eur($basisTotal) ?>, Bonus <?= $eur($bonusTotal) ?>)</h2>
    <?php if ($rows === []): ?>
        <p class="muted">Kein Snapshot für diese Periode. Programm evtl. deaktiviert oder noch nicht gerechnet.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Landkreis</th><th>Verein</th><th>km (gedeckelt)</th><th>Basis</th><th>Champion</th><th>Bonus</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= $e($r['landkreis_name'] ?? ('#' . $r['landkreis_region_id'])) ?></td>
                <td><?= $e($r['crew_name']) ?></td>
                <td><?= $e(number_format((float)$r['capped_km'], 1, ',', '.')) ?></td>
                <td><?= $eur($r['basis_ct']) ?></td>
                <td><?= !empty($r['is_champion']) ? '👑' : '' ?></td>
                <td><?= (int)$r['bonus_ct'] > 0 ? $eur($r['bonus_ct']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
