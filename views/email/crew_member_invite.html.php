<?php
/** @var string $crew_name */
/** @var string $join_url */
/** @var string $join_code */
/** @var string $app_name */
$email_kicker    = '// VEREINS-EINLADUNG';
$email_heading   = 'Tritt ' . $crew_name . ' bei';
$email_preheader = $crew_name . ' fährt bei CYBERRIDE — komm dazu, hol die App und tritt mit einem Tippen bei.';
include __DIR__ . '/_head.php';
?>
        <p style="margin:0 0 14px;color:#EAF6FF;">Hallo,</p>
        <p style="margin:0 0 14px;"><strong><?= $e($crew_name) ?></strong> ist bei <strong>CYBERRIDE</strong> dabei — dem Radsport-Spiel, bei dem ihr als Verein gemeinsam die Gebiete eurer Region erfahrt und erobert. Du bist eingeladen, dem Verein beizutreten.</p>
        <p style="margin:0 0 14px;">So geht's:</p>
        <ul style="margin:0 0 14px;padding-left:18px;">
            <li style="margin:0 0 6px;">Kostenlose CYBERRIDE-App installieren.</li>
            <li style="margin:0 0 6px;">Diesen Link danach auf dem iPhone öffnen — der Beitritt läuft automatisch.</li>
            <li style="margin:0 0 6px;">Fahren, gemeinsam eure Region halten.</li>
        </ul>
<?php $button_url = $join_url; $button_label = 'Verein beitreten'; include __DIR__ . '/_button.php'; ?>
        <p style="margin:14px 0 6px;font-size:13px;color:#7C8CA3;">Falls der Button nicht funktioniert, kopiere diese URL in deinen Browser:</p>
        <p style="margin:0 0 6px;font-size:13px;word-break:break-all;"><a href="<?= $e($join_url) ?>" style="color:#00E5FF;text-decoration:none;"><?= $e($join_url) ?></a></p>
        <p style="margin:14px 0 0;font-size:13px;color:#7C8CA3;">Oder in der App unter „Crew beitreten" den Code eingeben: <strong style="color:#B6C6DA;"><?= $e($join_code) ?></strong></p>
<?php include __DIR__ . '/_foot.php'; ?>
