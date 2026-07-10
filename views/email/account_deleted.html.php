<?php
/** @var ?string $display_name */
/** @var string $app_name */
/** @var string $support_email */
$greeting = ($display_name !== null && $display_name !== '')
    ? 'Hallo ' . $display_name
    : 'Hallo';
$email_kicker    = '// KONTO';
$email_heading   = 'Dein Konto wurde gelöscht';
$email_preheader = 'Wir haben dein CYBERRIDE-Konto und die zugehörigen Daten gelöscht.';
include __DIR__ . '/_head.php';
?>
        <p style="margin:0 0 14px;color:#EAF6FF;"><?= $e($greeting) ?>,</p>
        <p style="margin:0 0 14px;">dein CYBERRIDE-Konto wurde auf deinen Wunsch hin gelöscht. Du kannst dich nicht mehr anmelden.</p>
        <p style="margin:0 0 14px;">Die zugehörigen personenbezogenen Daten (Profil, Routen, Community-Inhalte) werden innerhalb von 30 Tagen endgültig aus unseren Systemen entfernt.</p>
        <p style="margin:14px 0 0;font-size:13px;color:#7C8CA3;">Du hast diese Löschung nicht veranlasst? Dann melde dich bitte umgehend bei uns: <a href="mailto:<?= $e($support_email) ?>" style="color:#00E5FF;text-decoration:none;"><?= $e($support_email) ?></a></p>
        <p style="margin:14px 0 0;font-size:13px;color:#7C8CA3;">Schade, dass du gehst — die Reviere warten, falls du zurückkommen willst.</p>
<?php include __DIR__ . '/_foot.php'; ?>
