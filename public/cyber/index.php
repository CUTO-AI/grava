<?php
/**
 * CyberRide landing page — plain-PHP entry.
 * Drop this folder into your public root (e.g. public/cyber/) and point your
 * theme switch at it. Set $CR_ASSETS to the URL path where the 3 asset files live.
 */
$CR_ASSETS = $CR_ASSETS ?? '/cyber/assets';   // Assets liegen unter public/cyber/assets/
$SEC = __DIR__ . '/sections/';
require __DIR__ . '/inc/header.php';
require $SEC . 'hero.php';
require $SEC . 'features.php';
require $SEC . 'news.php';
require $SEC . 'updates.php';
require $SEC . 'cta.php';
require __DIR__ . '/inc/footer.php';
