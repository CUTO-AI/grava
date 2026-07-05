<?php
/** @var ?string $display_name */
/** @var string $verify_url */
/** @var int $hours_valid */
/** @var string $app_name */
$greeting = ($display_name !== null && $display_name !== '')
    ? 'Hallo ' . $display_name
    : 'Hallo';
$email_kicker    = '// KONTO';
$email_heading   = 'Bestätige deine E-Mail-Adresse';
$email_preheader = 'Bestätige deine E-Mail-Adresse und leg bei CYBERRIDE los.';
include __DIR__ . '/_head.php';
?>
        <p style="margin:0 0 14px;color:#EAF6FF;"><?= $e($greeting) ?>,</p>
        <p style="margin:0 0 14px;">Willkommen bei CYBERRIDE! Bestätige deine E-Mail-Adresse, um dein Konto zu aktivieren und Reviere zu erobern.</p>
<?php $button_url = $verify_url; $button_label = 'E-Mail-Adresse bestätigen'; include __DIR__ . '/_button.php'; ?>
        <p style="margin:14px 0 6px;font-size:13px;color:#7C8CA3;">Dieser Link ist <?= (int)$hours_valid ?> Stunden gültig.</p>
        <p style="margin:0 0 6px;font-size:13px;color:#7C8CA3;">Falls der Button nicht funktioniert, kopiere diese URL in deinen Browser:</p>
        <p style="margin:0 0 6px;font-size:13px;word-break:break-all;"><a href="<?= $e($verify_url) ?>" style="color:#00E5FF;text-decoration:none;"><?= $e($verify_url) ?></a></p>
        <p style="margin:14px 0 0;font-size:13px;color:#7C8CA3;">Wenn du dich nicht bei CYBERRIDE registriert hast, kannst du diese E-Mail ignorieren.</p>
<?php include __DIR__ . '/_foot.php'; ?>
