<?php
require __DIR__ . '/components.php';
$CR_ASSETS = $CR_ASSETS ?? 'assets';
$CR_LANG = $CR_LANG ?? 'en';
$T = $T ?? (require __DIR__ . '/../lang/en.php');
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $e($CR_LANG) ?>" data-theme="cyber">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= $e($T['meta']['title']) ?></title>
<meta name="description" content="<?= $e($T['meta']['description']) ?>" />
<link rel="stylesheet" href="<?= $e($CR_ASSETS) ?>/cyberride.css" />
<link rel="stylesheet" href="<?= $e($CR_ASSETS) ?>/site.css" />
<link rel="stylesheet" href="<?= $e($CR_ASSETS) ?>/app.css" />
<link rel="icon" href="/favicon.svg" type="image/svg+xml" />
</head>
<body>
<header class="site-header" id="siteHeader">
  <a class="brand" href="/">
    <span class="glyph"><b>C</b></span>
    <span class="word">CYBER<span class="r">RIDE</span></span>
  </a>
  <nav class="site-nav">
    <div class="navlinks">
      <a href="#features"><?= $e($T['nav']['features']) ?></a>
      <a href="#news"><?= $e($T['nav']['news']) ?></a>
      <a href="#updates"><?= $e($T['nav']['updates']) ?></a>
      <a href="/heatmap"><?= $e($T['nav']['heatmap']) ?></a>
      <a href="/pulse"><?= $e($T['nav']['live']) ?></a>
    </div>
    <span class="lang-switch">
      <a href="?lang=en" class="<?= $CR_LANG === 'en' ? 'is-active' : '' ?>"><?= $e($T['lang']['en']) ?></a>
      <span class="sep">/</span>
      <a href="?lang=de" class="<?= $CR_LANG === 'de' ? 'is-active' : '' ?>"><?= $e($T['lang']['de']) ?></a>
    </span>
    <a class="login" href="/login"><?= $e($T['nav']['login']) ?></a>
    <?= cr_button($T['nav']['getApp'], ['size' => 'sm', 'variant' => 'primary', 'icon' => 'apple', 'href' => '#']) ?>
  </nav>
</header>
<main>
