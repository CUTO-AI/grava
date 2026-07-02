<?php
/** @var array<string,string[]> $errors */
/** @var string $email */
/** @var string $display_name */
/** @var string $_csrf */
$referral_code = $referral_code ?? '';
$f = static function (string $field) use ($errors): string {
    if (empty($errors[$field])) return '';
    $msgs = array_map(fn($m) => htmlspecialchars((string)$m, ENT_QUOTES, 'UTF-8'), $errors[$field]);
    return '<small class="field-error">' . implode(' ', $msgs) . '</small>';
};
?>
<section class="card">
    <h1><?= t('Konto erstellen') ?></h1>
    <form method="post" action="/register" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($referral_code !== ''): ?>
        <input type="hidden" name="referral_code" value="<?= htmlspecialchars($referral_code, ENT_QUOTES, 'UTF-8') ?>">
        <p class="muted"><?= t('Eingeladen mit Code') ?> <strong><?= htmlspecialchars($referral_code, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
        <?php endif; ?>
        <label>
            <?= t('E-Mail') ?>
            <input type="email" name="email" autocomplete="email" required value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
            <?= $f('email') ?>
        </label>
        <label>
            <?= t('Anzeigename (optional)') ?>
            <input type="text" name="display_name" autocomplete="nickname" maxlength="60" value="<?= htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8') ?>">
            <?= $f('display_name') ?>
        </label>
        <label>
            <?= t('Passwort') ?>
            <input type="password" name="password" autocomplete="new-password" required minlength="10" maxlength="200">
            <?= $f('password') ?>
            <small class="muted"><?= t('Mindestens 10 Zeichen.') ?></small>
        </label>
        <button type="submit"><?= t('Konto erstellen') ?></button>
    </form>
    <p class="muted"><?= t('Bereits registriert?') ?> <a href="/login"><?= t('Anmelden') ?></a></p>
</section>
