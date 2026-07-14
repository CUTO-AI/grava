<?php
/** @var string $_csrf */
/** @var string $token */
/** @var array<string,mixed>|null $info */
/** @var bool $logged_in */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$action = '/verein-aktivieren/' . rawurlencode($token);
?>
<?php if ($info === null): ?>
    <section class="card">
        <h1>Verein aktivieren</h1>
        <p class="muted">Dieser Aktivierungslink ist ungültig oder abgelaufen. Bitte prüfe den Link aus deiner E-Mail.</p>
    </section>
<?php elseif (!empty($info['used'])): ?>
    <section class="card">
        <h1><?= $e($info['org_name'] ?? 'Euer Verein') ?></h1>
        <p class="muted">Dieser Verein wurde bereits aktiviert. Melde dich an, um euer Vereins-Cockpit zu öffnen.</p>
        <p><a href="/verein" class="button">Zum Vereins-Cockpit</a></p>
    </section>
<?php else: ?>
    <section class="card">
        <h1>CYBERRIDE für <?= $e($info['org_name'] ?? 'euren Verein') ?></h1>
        <p>CYBERRIDE ist das Spiel für Gravel &amp; Rennrad: Eure Mitglieder fahren, erobern gemeinsam eure Region — und ihr holt etwas für den Verein heraus.</p>
        <ul>
            <li><strong>Neue Mitglieder &amp; Nachwuchs</strong> — mit Link zu eurem Aufnahmeantrag.</li>
            <li><strong>Geld für die Vereinskasse</strong> — für die Kilometer eurer Mitglieder.</li>
            <li><strong>Sichtbarkeit</strong> — der aktivste Verein führt eure Region an.</li>
            <li><strong>Offizieller, geschützter Vereins-Account</strong> — nur ihr führt euren Verein.</li>
        </ul>
        <p class="muted">Kostenlos &amp; fair (kein Bezahl-Vorteil), für eingetragene, gemeinnützige Vereine.</p>
    </section>

    <section class="card">
        <h2>Vereins-Account aktivieren</h2>
        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
            <p class="muted">Verein: <strong><?= $e($info['org_name'] ?? $info['display_name'] ?? '') ?></strong>
                <?php if (!empty($info['register_court']) || !empty($info['register_no'])): ?>
                    · <?= $e(trim(($info['register_court'] ?? '') . ' ' . ($info['register_no'] ?? ''))) ?>
                <?php endif; ?>
            </p>
            <?php if ($logged_in): ?>
                <p>Du bist angemeldet — ein Klick genügt.</p>
                <button type="submit">Verein jetzt aktivieren</button>
            <?php else: ?>
                <?php if (!empty($info['contact_email'])): ?>
                    <label>E-Mail (aus eurem Vereinskontakt)
                        <input type="email" name="email" value="<?= $e($info['contact_email']) ?>" readonly>
                    </label>
                <?php else: ?>
                    <label>E-Mail für euren Vereins-Account
                        <input type="email" name="email" required autocomplete="email">
                    </label>
                <?php endif; ?>
                <label>Handle (öffentlicher Vereins-Name im Spiel)
                    <input type="text" name="handle" id="crewHandle"
                           value="<?= $e($suggested_handle ?? '') ?>"
                           pattern="[a-z0-9_]{3,30}" minlength="3" maxlength="30" required
                           autocapitalize="off" autocomplete="off" spellcheck="false"
                           data-check-url="/verein/handle-verfuegbar">
                </label>
                <p id="crewHandleStatus" class="muted" style="margin:-6px 0 12px;">3–30 Zeichen: a–z, 0–9, _</p>
                <label>Passwort für euren Vereins-Account (min. 10 Zeichen)
                    <input type="password" name="password" minlength="10" required autocomplete="new-password">
                </label>
                <button type="submit" id="crewActivateBtn">Verein aktivieren &amp; Konto anlegen</button>
                <p class="muted" style="margin-top:8px;">Keine separate Bestätigungsmail nötig — dieser Link bestätigt eure Adresse bereits.</p>
            <?php endif; ?>
        </form>
    </section>
<?php endif; ?>
