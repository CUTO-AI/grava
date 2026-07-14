<?php
/** @var array<string,mixed>|null $crew */
/** @var ?string $flash */
/** @var string $app_store_url */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$members = is_array($crew['members'] ?? null) ? $crew['members'] : [];
$code = (string)($crew['join_code'] ?? '');
$inviteUrl = $code !== '' ? 'https://cyberride.world/c/' . $code : '';
?>
<?php if (!empty($flash)): ?><div class="alert alert-success"><?= $e($flash) ?></div><?php endif; ?>

<?php if ($crew === null): ?>
    <section class="card">
        <h1>Mein Verein</h1>
        <p class="muted">Du verwaltest noch keinen Verein. Falls du eine Einladung erhalten hast, öffne den Aktivierungslink aus der E-Mail.</p>
    </section>
<?php else: ?>
    <section class="card">
        <h1><?= $e($crew['name']) ?>
            <?php if (!empty($crew['verified'])): ?><span title="Verifizierter Verein">✓</span><?php endif; ?>
        </h1>
        <?php if (!empty($crew['verified'])): ?>
            <p class="muted">Verifizierter Verein · <?= (int)($crew['member_count'] ?? count($members)) ?> Mitglied(er)</p>
        <?php endif; ?>
    </section>

    <?php if ($inviteUrl !== ''): ?>
    <section class="card">
        <h2>Mitglieder einladen</h2>
        <p>Teile diesen Link mit euren Mitgliedern. Wer ihn öffnet, installiert die App und tritt eurem Verein bei:</p>
        <p style="font-size:1.05rem;word-break:break-all;"><a href="<?= $e($inviteUrl) ?>"><?= $e($inviteUrl) ?></a></p>
        <p class="muted">Einladungscode: <strong><?= $e($code) ?></strong></p>

        <h3 style="margin-top:22px;">Per E-Mail einladen</h3>
        <p class="muted">Eine oder mehrere Adressen — durch Komma, Semikolon oder Zeilenumbruch getrennt. Jede bekommt den Beitritts-Link.</p>
        <form method="post" action="/verein/einladen">
            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
            <label>E-Mail-Adressen der Mitglieder
                <textarea name="emails" rows="4" required
                          placeholder="anna@example.com, ben@example.com&#10;carla@example.com"
                          autocapitalize="off" autocomplete="off" spellcheck="false"></textarea>
            </label>
            <button type="submit">Einladungen senden</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Mitglieder (<?= count($members) ?>)</h2>
        <?php if ($members === []): ?>
            <p class="muted">Noch keine Mitglieder beigetreten. Teile den Einladungslink oben.</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Fahrer</th><th>Rolle</th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?php if (!empty($m['handle'])): ?>@<?= $e($m['handle']) ?><?php else: ?><?= $e($m['name'] ?? '—') ?><?php endif; ?></td>
                    <td><?= $e($m['role'] ?? 'member') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>So geht's weiter</h2>
        <ol>
            <li>Einladungslink an eure Mitglieder schicken (WhatsApp, Vereins-Chat, Aushang).</li>
            <li>Mitglieder installieren die CYBERRIDE-App und treten über den Link bei.</li>
            <li>Fahren, gemeinsam eure Region erobern — euer Vereins-Fortschritt erscheint hier.</li>
        </ol>
        <?php if ($app_store_url !== ''): ?>
            <p><a href="<?= $e($app_store_url) ?>" class="button">CYBERRIDE-App laden (selbst mitfahren)</a></p>
        <?php endif; ?>
    </section>
<?php endif; ?>
