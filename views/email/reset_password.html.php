<?php
/** @var ?string $display_name */
/** @var string $reset_url */
/** @var int $minutes_valid */
/** @var string $app_name */
$greeting = ($display_name !== null && $display_name !== '')
    ? 'Hallo ' . $display_name
    : 'Hallo';
$email_kicker    = '// SICHERHEIT';
$email_heading   = 'Passwort zurücksetzen';
$email_preheader = 'Setze dein CYBERRIDE-Passwort zurück.';
include __DIR__ . '/_head.php';
?>
        <p style="margin:0 0 14px;color:#EAF6FF;"><?= $e($greeting) ?>,</p>
        <p style="margin:0 0 14px;">Du (oder jemand mit Zugriff auf dein Konto) hat ein neues Passwort angefordert. Lege über den Button ein neues Passwort fest:</p>
<?php $button_url = $reset_url; $button_label = 'Passwort zurücksetzen'; include __DIR__ . '/_button.php'; ?>
        <p style="margin:14px 0 6px;font-size:13px;color:#7C8CA3;">Dieser Link ist <?= (int)$minutes_valid ?> Minuten gültig und kann nur einmal verwendet werden.</p>
        <p style="margin:0 0 6px;font-size:13px;color:#7C8CA3;">Falls der Button nicht funktioniert, kopiere diese URL in deinen Browser:</p>
        <p style="margin:0 0 6px;font-size:13px;word-break:break-all;"><a href="<?= $e($reset_url) ?>" style="color:#00E5FF;text-decoration:none;"><?= $e($reset_url) ?></a></p>
        <p style="margin:14px 0 0;font-size:13px;color:#B6C6DA;"><strong style="color:#FF1E6F;">Wenn du das nicht warst,</strong> ignoriere diese E-Mail — dein Passwort bleibt unverändert.</p>
<?php include __DIR__ . '/_foot.php'; ?>
