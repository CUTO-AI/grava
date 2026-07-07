<?php
require __DIR__ . '/components.php';
$CR_ASSETS = $CR_ASSETS ?? 'assets';
$CR_LANG = $CR_LANG ?? 'en';
$T = $T ?? (require __DIR__ . '/../lang/en.php');
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// SEO/Canonical: absolute Marken-URL der Startseite (unabhängig vom Request-Host).
$CR_BASE      = class_exists('\\App\\Support\\SiteUrl') ? \App\Support\SiteUrl::base() : '';
$CR_CANONICAL = ($CR_BASE !== '' ? $CR_BASE : '') . '/';
$CR_OG_IMAGE  = '/assets/brand/icon-512.png';
$CR_OG_LOCALE = $CR_LANG === 'de' ? 'de_DE' : 'en_US';
?>
<!DOCTYPE html>
<html lang="<?= $e($CR_LANG) ?>" data-theme="cyber">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= $e($T['meta']['title']) ?></title>
<meta name="description" content="<?= $e($T['meta']['description']) ?>" />
<meta name="author" content="CYBERRIDE" />
<meta name="robots" content="index, follow" />
<link rel="canonical" href="<?= $e($CR_CANONICAL) ?>" />

<meta property="og:type" content="website" />
<meta property="og:url" content="<?= $e($CR_CANONICAL) ?>" />
<meta property="og:title" content="<?= $e($T['meta']['title']) ?>" />
<meta property="og:description" content="<?= $e($T['meta']['description']) ?>" />
<meta property="og:image" content="<?= $e($CR_OG_IMAGE) ?>" />
<meta property="og:locale" content="<?= $e($CR_OG_LOCALE) ?>" />
<meta property="og:site_name" content="CYBERRIDE" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:url" content="<?= $e($CR_CANONICAL) ?>" />
<meta name="twitter:title" content="<?= $e($T['meta']['title']) ?>" />
<meta name="twitter:description" content="<?= $e($T['meta']['description']) ?>" />
<meta name="twitter:image" content="<?= $e($CR_OG_IMAGE) ?>" />

<meta name="theme-color" content="#04060B" />
<!-- Consent Mode v2: Default (denied) VOR dem GA-Loader setzen -->
<script src="/assets/js/consent.js"></script>
<!-- Google tag (gtag.js) — Loader extern, Init aus same-origin /assets/js/ga.js (CSP) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-HVRGQSKQNV"></script>
<script type="application/json" id="ga-data"><?= json_encode(['content_group' => 'landing', 'page_title' => $T['meta']['title'], 'page_location' => $CR_CANONICAL], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/ga.js"></script>
<script src="/assets/js/events.js"></script>
<link rel="stylesheet" href="<?= $e($CR_ASSETS) ?>/cyberride.css" />
<link rel="stylesheet" href="<?= $e($CR_ASSETS) ?>/site.css" />
<link rel="stylesheet" href="<?= $e($CR_ASSETS) ?>/app.css" />
<link rel="icon" href="/favicon.svg" type="image/svg+xml" />
<link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png" />
<?= \App\Support\StructuredData::render() ?>
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
    <?= cr_button($T['nav']['getApp'], ['size' => 'sm', 'variant' => 'primary', 'icon' => 'apple', 'href' => '#', 'attrs' => 'data-ga-event="appstore_click" data-ga-source="landing_nav"']) ?>
  </nav>
</header>
<main>
