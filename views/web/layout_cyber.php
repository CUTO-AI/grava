<?php
/**
 * Standard-Schale im „Cyber"-Design (Nachfolger von layout.php).
 *
 * WebView rendert JEDE View hierin (Cyber ist Standard). Das vorhandene
 * View-Markup wird per /cyber/assets/app.css global umgeskinnt; handpolierte
 * Varianten liegen unter views/web/cyber/<view>.php. Lädt ausschließlich
 * same-origin-Assets (CSP-strikt) und NICHT das klassische /assets/style.css.
 *
 * Escape-Hatch: ?theme=classic rendert wieder layout.php.
 *
 * @var string  $content
 * @var ?string $_title
 * @var ?string $_csrf
 */
require_once dirname(__DIR__, 2) . '/public/cyber/inc/components.php';
require_once dirname(__DIR__, 2) . '/src/Support/i18n_functions.php';

$CR_LANG          = \App\Support\I18n::locale();
$CR_ASSETS        = '/cyber/assets';
$_authedUser      = $_authedUser  ?? null;
$_layoutWide      = $_layoutWide  ?? false;
$_pageStyles      = $_pageStyles  ?? [];
$_pageScripts     = $_pageScripts ?? [];
$_notifUnread     = $_notifUnread ?? 0;
$_surfaceCheck    = \App\Config\Config::instance()->bool('SURFACE_CHECK_ENABLED', true);

// SEO & Social Meta
$_metaDescription = $_metaDescription ?? 'CYBERRIDE — Finde, fahre und erobere deine Gravel- und Bikepacking-Touren. Objektive Wegqualität, Community-Power und Territorialspiel in einer App.';
$_metaKeywords    = $_metaKeywords    ?? 'Gravel, Bikepacking, Radtouren, Wegqualität, Schotter, Rennrad, Community, GPS';
$_ogTitle         = $_ogTitle         ?? ($_title ?? 'CYBERRIDE');
$_ogDescription   = $_ogDescription   ?? $_metaDescription;
$_ogImage         = $_ogImage         ?? '/assets/brand/icon-512.png';
$_canonical       = $_canonical       ?? ($_ogUrl ?? ($_SERVER['REQUEST_URI'] ?? '/'));
$_ogUrl           = $_ogUrl           ?? $_canonical;
$_robots          = $_robots          ?? 'index, follow';

$_wrapClass = 'cr-wrap' . ($_layoutWide ? '' : ' cr-wrap--narrow');
?><!doctype html>
<html lang="<?= htmlspecialchars($CR_LANG, ENT_QUOTES, 'UTF-8') ?>" data-theme="cyber">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($_title ?? 'CYBERRIDE', ENT_QUOTES, 'UTF-8') ?></title>

<meta name="description" content="<?= htmlspecialchars($_metaDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="keywords" content="<?= htmlspecialchars($_metaKeywords, ENT_QUOTES, 'UTF-8') ?>">
<meta name="author" content="CYBERRIDE">
<meta name="robots" content="<?= htmlspecialchars($_robots, ENT_QUOTES, 'UTF-8') ?>">
<link rel="canonical" href="<?= htmlspecialchars($_canonical, ENT_QUOTES, 'UTF-8') ?>">

<meta property="og:type" content="website">
<meta property="og:url" content="<?= htmlspecialchars($_canonical, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:title" content="<?= htmlspecialchars($_ogTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($_ogDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($_ogImage, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:locale" content="de_DE">
<meta property="og:site_name" content="CYBERRIDE">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:url" content="<?= htmlspecialchars($_canonical, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($_ogTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($_ogDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($_ogImage, ENT_QUOTES, 'UTF-8') ?>">

<meta name="theme-color" content="#04060B">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-HVRGQSKQNV"></script>
<script type="application/json" id="ga-data"><?= json_encode(['content_group' => $_analyticsGroup ?? 'other', 'page_title' => $_title ?? 'CYBERRIDE', 'page_location' => $_canonical], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/ga.js"></script>
<link rel="stylesheet" href="<?= $CR_ASSETS ?>/cyberride.css">
<link rel="stylesheet" href="<?= $CR_ASSETS ?>/site.css">
<link rel="stylesheet" href="<?= $CR_ASSETS ?>/app.css">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
<?php foreach ($_pageStyles as $_href): ?>
<link rel="stylesheet" href="<?= htmlspecialchars((string)$_href, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>
</head>
<body>
<header class="site-header" id="siteHeader">
  <a class="brand" href="/">
    <span class="glyph"><b>C</b></span>
    <span class="word">CYBER<span class="r">RIDE</span></span>
  </a>
  <nav class="site-nav">
    <div class="navlinks">
    <?php if ($_authedUser !== null): ?>
      <a href="/dashboard"><?= te('Dashboard') ?></a>
      <a href="/features"><?= te('Funktionen') ?></a>
      <a href="/routes"><?= te('Routen') ?></a>
      <a href="/discover"><?= te('Entdecken') ?></a>
      <a href="/heatmap"><?= te('Heatmap') ?></a>
      <a href="/pulse"><?= te('Heute im Spiel') ?></a>
      <?php if ($_surfaceCheck): ?><a href="/surface-check"><?= te('Belag prüfen') ?></a><?php endif; ?>
      <a href="/feed"><?= te('Feed') ?></a>
      <a href="/notifications"><?= te('Mitteilungen') ?><?php if ((int)$_notifUnread > 0): ?> <span class="notif-badge"><?= (int)$_notifUnread ?></span><?php endif; ?></a>
      <?php if (!empty($_authedUser['public_handle'])): ?>
        <a href="/u/<?= htmlspecialchars((string)$_authedUser['public_handle'], ENT_QUOTES, 'UTF-8') ?>">@<?= htmlspecialchars((string)$_authedUser['public_handle'], ENT_QUOTES, 'UTF-8') ?></a>
      <?php endif; ?>
    <?php else: ?>
      <a href="/features"><?= te('Funktionen') ?></a>
      <a href="/discover"><?= te('Entdecken') ?></a>
      <a href="/heatmap"><?= te('Heatmap') ?></a>
      <a href="/pulse"><?= te('Heute im Spiel') ?></a>
    <?php endif; ?>
    </div>
    <span class="lang-switch">
      <a href="?lang=en" class="<?= $CR_LANG === 'en' ? 'is-active' : '' ?>">EN</a>
      <span class="sep">/</span>
      <a href="?lang=de" class="<?= $CR_LANG === 'de' ? 'is-active' : '' ?>">DE</a>
    </span>
    <?php if ($_authedUser !== null): ?>
      <form method="post" action="/logout" class="nav-form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)($_csrf ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="nav-button"><?= te('Abmelden') ?></button>
      </form>
    <?php else: ?>
      <a class="login" href="/login"><?= te('Login') ?></a>
      <?= cr_button(t('Registrieren'), ['size' => 'sm', 'variant' => 'primary', 'href' => '/register']) ?>
    <?php endif; ?>
  </nav>
</header>

<main class="cyber-main">
  <div class="<?= $_wrapClass ?>">
    <?php if (!empty($flash)): ?>
      <div class="flash"><?= htmlspecialchars((string)$flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?= $content ?? '' ?>
  </div>
</main>

<footer class="site-footer">
  <div class="cr-wrap">
    <div class="cols">
      <div class="about">
        <a class="brand" href="/" style="margin-bottom:16px">
          <span class="glyph" style="width:30px;height:30px"><b style="font-size:16px">C</b></span>
          <span class="word" style="font-size:20px">CYBER<span class="r">RIDE</span></span>
        </a>
        <p><?= te('Fahre, erobere, baue die Map. Oberfläche · Verkehr · Hinweise.') ?></p>
      </div>
      <?php
      $cols = [
        'Produkt'     => [['Funktionen', '/features'], ['Entdecken', '/discover'], ['Heatmap', '/heatmap']],
        'Community'   => [['Feed', '/feed'], ['Registrieren', '/register'], ['Login', '/login']],
        'Rechtliches' => [['Datenschutz', '/privacy'], ['Nutzungsbedingungen', '/terms'], ['Impressum', '/imprint']],
      ];
      foreach ($cols as $h => $links): ?>
        <div class="col">
          <h4 class="cr-kicker" style="color:var(--text-muted)"><?= te($h) ?></h4>
          <ul>
            <?php foreach ($links as $l): ?><li><a href="<?= htmlspecialchars($l[1]) ?>"><?= te($l[0]) ?></a></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="legal">
      <span>© <?= date('Y') ?> CYBERRIDE · <?= te('ALLE RECHTE VORBEHALTEN') ?></span>
      <span><?= te('MADE FOR RIDERS · DE / AT / CH') ?></span>
    </div>
  </div>
</footer>

<script src="<?= $CR_ASSETS ?>/lucide.min.js"></script>
<script src="<?= $CR_ASSETS ?>/site.js"></script>
<?php foreach ($_pageScripts as $_src): ?>
<script src="<?= htmlspecialchars((string)$_src, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endforeach; ?>
</body>
</html>
