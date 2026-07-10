<?php
/**
 * Öffentliche Konto-Löschseite (DSGVO Art. 17). Bestätigung mit E-Mail +
 * Passwort + explizitem Häkchen; löscht das Konto serverseitig
 * (AuthService::deleteAccountByEmail). noindex.
 *
 * @var ?string $error
 * @var bool $done
 * @var string $email
 * @var string $_csrf
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<section class="card">
    <h1><?= t('Konto löschen') ?></h1>

    <?php if (!empty($done)): ?>
        <div class="alert alert-success"><?= t('Dein Konto wurde gelöscht.') ?></div>
        <p class="muted">
            <?= t('Alle personenbezogenen Daten wurden entfernt bzw. anonymisiert; verbleibende Kopien werden innerhalb von 30 Tagen endgültig gelöscht. Danke, dass du CYBERRIDE genutzt hast.') ?>
        </p>
        <p class="muted"><a href="/"><?= t('Zur Startseite') ?></a></p>
    <?php else: ?>
        <p class="muted">
            <?= t('Hier kannst du dein CYBERRIDE-Konto und alle zugehörigen personenbezogenen Daten dauerhaft löschen. Bitte bestätige mit deinen Anmeldedaten.') ?>
        </p>

        <div class="alert alert-warning">
            <strong><?= t('Achtung: Diese Aktion ist unwiderruflich.') ?></strong>
            <ul>
                <li><?= t('Dein Profil, Handle, deine E-Mail-Adresse und dein Anzeigename werden gelöscht.') ?></li>
                <li><?= t('Deine Routen, Aktivitäten, Kommentare, Likes und Follower-Beziehungen werden entfernt.') ?></li>
                <li><?= t('Deine Crew-Mitgliedschaft und dein Spiel-/Revier-Fortschritt werden aufgelöst.') ?></li>
                <li><?= t('Verbleibende Kopien werden innerhalb von 30 Tagen endgültig gelöscht (aggregierte, anonyme Kennzahlen ohne Personenbezug ausgenommen).') ?></li>
            </ul>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= $e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/delete-account" novalidate>
            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
            <label>
                <?= t('E-Mail') ?>
                <input type="email" name="email" autocomplete="email" required value="<?= $e($email) ?>">
            </label>
            <label>
                <?= t('Passwort') ?>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <label class="checkbox">
                <input type="checkbox" name="confirm" value="1" required>
                <span><?= t('Ich möchte mein Konto und alle zugehörigen Daten unwiderruflich löschen.') ?></span>
            </label>
            <button type="submit" class="btn-danger"><?= t('Konto endgültig löschen') ?></button>
        </form>

        <p class="muted">
            <?= t('Passwort vergessen?') ?> <a href="/forgot-password"><?= t('Erst zurücksetzen') ?></a>,
            dann hier löschen. <?= t('Alternativ kannst du dein Konto auch direkt in der App löschen (Profil → Konto löschen).') ?>
        </p>
        <p class="muted"><a href="/privacy"><?= t('Datenschutzerklärung') ?></a></p>
    <?php endif; ?>
</section>
