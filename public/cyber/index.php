<?php
/**
 * GRAVA Landing — plain-PHP entry (zweisprachig EN/DE, Standard EN).
 * Wird von LandingController::home() als „/" gerendert; direkter Aufruf /cyber/
 * funktioniert ebenfalls (lang.php ist self-contained).
 */
$CR_ASSETS = $CR_ASSETS ?? '/cyber/assets';   // Assets unter public/cyber/assets/
require __DIR__ . '/inc/lang.php';             // exponiert $CR_LANG + $T
$SEC = __DIR__ . '/sections/';
require __DIR__ . '/inc/header.php';
require $SEC . 'hero.php';
require $SEC . 'features.php';
require $SEC . 'news.php';
require $SEC . 'updates.php';
require $SEC . 'cta.php';
require __DIR__ . '/inc/footer.php';
