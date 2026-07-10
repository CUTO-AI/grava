<?php
/** @var ?string $display_name */
/** @var string $app_name */
/** @var string $support_email */
$greeting = ($display_name !== null && $display_name !== '')
    ? 'Hallo ' . $display_name
    : 'Hallo';
?><?= $greeting ?>,

dein <?= $app_name ?>-Konto wurde auf deinen Wunsch hin gelöscht. Du kannst dich nicht mehr anmelden.

Die zugehörigen personenbezogenen Daten (Profil, Routen, Community-Inhalte) werden innerhalb von 30 Tagen endgültig aus unseren Systemen entfernt.

Du hast diese Löschung nicht veranlasst? Dann melde dich bitte umgehend bei uns: <?= $support_email ?>


Schade, dass du gehst — die Reviere warten, falls du zurückkommen willst.

— Dein <?= $app_name ?>-Team
