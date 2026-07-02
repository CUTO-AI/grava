<?php require __DIR__ . '/components.php'; $CR_ASSETS = $CR_ASSETS ?? 'assets'; ?>
<!DOCTYPE html>
<html lang="en" data-theme="cyber">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>CyberRide — Ride Real Roads. Claim the Grid.</title>
<meta name="description" content="CyberRide turns your city into contested territory. Ride real roads to claim sectors and map surface, traffic and hazards for every rider." />
<link rel="stylesheet" href="<?= $CR_ASSETS ?>/cyberride.css" />
<link rel="stylesheet" href="<?= $CR_ASSETS ?>/site.css" />
</head>
<body>
<header class="site-header" id="siteHeader">
  <a class="brand" href="#top">
    <span class="glyph"><b>C</b></span>
    <span class="word">CYBER<span class="r">RIDE</span></span>
  </a>
  <nav class="site-nav">
    <div class="navlinks">
      <a href="#features">Features</a>
      <a href="#news">News</a>
      <a href="#updates">Updates</a>
      <a href="#">Heatmap</a>
    </div>
    <a class="login" href="#">Login</a>
    <?= cr_button('Get the App', ['size' => 'sm', 'variant' => 'primary', 'icon' => 'apple', 'href' => '#']) ?>
  </nav>
</header>
<main>
