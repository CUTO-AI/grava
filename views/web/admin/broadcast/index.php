<?php
/** @var list<string> $segments */
/** @var array<string,int> $estimates */
/** @var list<array<string,mixed>> $recent */
/** @var ?string $flash */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin">Übersicht</a>
    <a href="/admin/broadcast"><strong>Broadcast</strong></a>
    <a href="/admin/analytics">Analytics</a>
    <a href="/admin/cron">Cron</a>
</nav>

<?php if ($flash !== null): ?><section class="card"><p><?= $e($flash) ?></p></section><?php endif; ?>

<section class="card">
    <h1><?= t('Broadcast-Push') ?></h1>
    <p class="muted"><?= t('Sendet eine Mitteilung an ein Nutzersegment (gebannte/inaktive ausgeschlossen). Versand läuft entkoppelt über den Worker.') ?></p>
    <form method="post" action="/admin/broadcast" onsubmit="return confirm('<?= $e(t('Broadcast wirklich an das gewählte Segment senden?')) ?>')">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <p><label><?= t('Titel') ?><br><input type="text" name="title" maxlength="120" required style="min-width:28rem"></label></p>
        <p><label><?= t('Text') ?><br><textarea name="body" maxlength="300" required rows="3" style="min-width:28rem"></textarea></label></p>
        <p><label><?= t('Deep-Link (optional)') ?><br><input type="text" name="deeplink" maxlength="200" placeholder="cyberride://…" style="min-width:28rem"></label></p>
        <p><label><?= t('Segment') ?><br>
            <select name="segment">
                <?php foreach ($segments as $s): ?>
                    <option value="<?= $e($s) ?>"><?= $e($s) ?> (~<?= (int)$estimates[$s] ?> <?= t('Empfänger') ?>)</option>
                <?php endforeach; ?>
            </select>
        </label></p>
        <button type="submit"><?= t('Einreihen & senden') ?></button>
    </form>
</section>

<section class="card">
    <h2><?= t('Verlauf') ?></h2>
    <table class="data" style="width:100%">
        <thead><tr><th>#</th><th><?= t('Zeit') ?></th><th><?= t('Titel') ?></th><th>Segment</th><th><?= t('Status') ?></th><th><?= t('Empf./Gesendet') ?></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $b): ?>
            <tr>
                <td><?= (int)$b['id'] ?></td>
                <td class="muted"><?= $e($b['created_at']) ?></td>
                <td><?= $e($b['title']) ?></td>
                <td><span class="badge"><?= $e($b['segment']) ?></span></td>
                <td><span class="badge <?= $b['status'] === 'sent' ? 'badge-ok' : ($b['status'] === 'failed' ? 'badge-err' : 'badge-warn') ?>"><?= $e($b['status']) ?></span></td>
                <td><?= (int)($b['recipients'] ?? 0) ?> / <?= (int)($b['sent'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($recent === []): ?><tr><td colspan="6" class="muted"><?= t('Noch keine Broadcasts.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
