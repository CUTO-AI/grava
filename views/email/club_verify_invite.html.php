<?php
/** @var string $org_name */
/** @var string $activate_url */
/** @var string $app_name */
$email_kicker    = '// VEREIN';
$email_heading   = 'Euer Verein bei CYBERRIDE';
$email_preheader = 'Neue Mitglieder, Sichtbarkeit und Geld für die Vereinskasse — kostenlos für eingetragene, gemeinnützige Vereine.';
include __DIR__ . '/_head.php';
?>
        <p style="margin:0 0 14px;color:#EAF6FF;">Hallo <?= $e($org_name) ?>,</p>
        <p style="margin:0 0 14px;">viele Vereine tun sich schwer, neue Mitglieder zu gewinnen. Genau da setzen wir an: <strong>CYBERRIDE</strong> ist ein Radsport-Spiel, bei dem Vereine um die Gebiete ihrer Region fahren — und dabei etwas für den Verein herausholen:</p>
        <ul style="margin:0 0 14px;padding-left:18px;">
            <li style="margin:0 0 6px;">Neue Mitglieder &amp; Nachwuchs — mit direktem Link zu eurem Aufnahmeantrag.</li>
            <li style="margin:0 0 6px;">Geld für die Vereinskasse — für die Kilometer eurer Mitglieder (Details nach dem Klick).</li>
            <li style="margin:0 0 6px;">Sichtbarkeit — der aktivste Verein führt eure Region an.</li>
            <li style="margin:0 0 6px;">Offizieller, geschützter Vereins-Account — nur ihr führt euren Verein.</li>
        </ul>
        <p style="margin:0 0 14px;">Für den Verein <strong>kostenlos</strong> und <strong>fair</strong> (kein Bezahl-Vorteil, reines Radfahren aus eigener Kraft) — für eingetragene, gemeinnützige Vereine.</p>
<?php $button_url = $activate_url; $button_label = 'Vereins-Account aktivieren'; include __DIR__ . '/_button.php'; ?>
        <p style="margin:14px 0 6px;font-size:13px;color:#7C8CA3;">Falls der Button nicht funktioniert, kopiere diese URL in deinen Browser:</p>
        <p style="margin:0 0 6px;font-size:13px;word-break:break-all;"><a href="<?= $e($activate_url) ?>" style="color:#00E5FF;text-decoration:none;"><?= $e($activate_url) ?></a></p>
        <p style="margin:14px 0 0;font-size:13px;color:#7C8CA3;">Kein Interesse? Eine kurze Antwort auf diese Mail genügt, dann melden wir uns nicht wieder.</p>
<?php include __DIR__ . '/_foot.php'; ?>
