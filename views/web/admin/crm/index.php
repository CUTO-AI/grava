<?php
/** @var string $_csrf */
/** @var list<array<string,mixed>> $prospects */
/** @var array<string,int> $counts */
/** @var list<string> $statuses */
/** @var string $filterStatus */
/** @var string $filterLandkreis */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Game</a>
    <a href="/admin/crm"><strong>Vereins-CRM</strong></a>
</nav>

<section class="card">
    <h1>Vereins-CRM · Outreach</h1>
    <p class="muted">Vereins-Zielliste fürs Beachhead-Seeding. Funnel:
        <?php foreach ($statuses as $s): ?>
            <strong><?= $e($s) ?></strong> <?= (int)($counts[$s] ?? 0) ?><?= $s === end($statuses) ? '' : ' · ' ?>
        <?php endforeach; ?>
    </p>
    <form method="get" action="/admin/crm" class="inline-form" style="margin-top:.5rem">
        <select name="status">
            <option value="">— Status —</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= $e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= $e($s) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="landkreis" placeholder="Landkreis" value="<?= $e($filterLandkreis) ?>">
        <button type="submit">Filtern</button>
        <a href="/admin/crm">Zurücksetzen</a>
    </form>
</section>

<section class="card">
    <h2>Verein hinzufügen</h2>
    <form method="post" action="/admin/crm">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <p><input type="text" name="name" placeholder="Name (Pflicht)" required style="width:100%"></p>
        <p style="display:flex;gap:.5rem;flex-wrap:wrap">
            <input type="text" name="landkreis" placeholder="Landkreis">
            <input type="text" name="discipline" placeholder="Disziplin (gravel/rennrad/mtb/tri)">
            <input type="text" name="assigned_to" placeholder="Zuständig">
        </p>
        <p style="display:flex;gap:.5rem;flex-wrap:wrap">
            <input type="email" name="contact_email" placeholder="Kontakt-E-Mail">
            <input type="url" name="official_source_url" placeholder="Impressum-URL">
        </p>
        <p style="display:flex;gap:.5rem;flex-wrap:wrap">
            <input type="text" name="register_court" placeholder="Registergericht">
            <input type="text" name="register_no" placeholder="VR-Nr.">
            <label><input type="checkbox" name="is_charitable" value="1"> gemeinnützig</label>
        </p>
        <p><textarea name="notes" placeholder="Notizen" rows="2" style="width:100%"></textarea></p>
        <button type="submit">Speichern</button>
    </form>
</section>

<section class="card">
    <h2><?= count($prospects) ?> Vereine</h2>
    <?php if ($prospects === []): ?>
        <p class="muted">Keine Einträge. Über das Formular oben anlegen.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Name</th><th>Landkreis</th><th>Disziplin</th><th>Kontakt</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($prospects as $p): ?>
            <tr>
                <td>
                    <?= $e($p['name']) ?>
                    <?php if (!empty($p['is_charitable'])): ?><span class="muted" style="font-size:.8rem">· e.V./gemeinn.</span><?php endif; ?>
                    <?php if (!empty($p['notes'])): ?><br><span class="muted" style="font-size:.8rem"><?= $e(mb_substr((string)$p['notes'], 0, 120)) ?></span><?php endif; ?>
                </td>
                <td><?= $e($p['landkreis'] ?? '') ?></td>
                <td><?= $e($p['discipline'] ?? '') ?></td>
                <td>
                    <?php if (!empty($p['contact_email'])): ?><?= $e($p['contact_email']) ?><?php endif; ?>
                    <?php if (!empty($p['official_source_url'])): ?><br><a href="<?= $e($p['official_source_url']) ?>" target="_blank" rel="noopener">Impressum</a><?php endif; ?>
                </td>
                <td>
                    <form method="post" action="/admin/crm/<?= (int)$p['id'] ?>" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                        <input type="hidden" name="name" value="<?= $e($p['name']) ?>">
                        <select name="status" onchange="this.form.submit()">
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?= $e($s) ?>" <?= (string)$p['status'] === $s ? 'selected' : '' ?>><?= $e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit">OK</button></noscript>
                    </form>
                </td>
                <td class="muted" style="font-size:.8rem">
                    <?php if (!empty($p['link_opened_at'])): ?>🔗 Link geöffnet<?php endif; ?>
                    <?php if (!empty($p['activated_at'])): ?>✅ aktiviert<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
