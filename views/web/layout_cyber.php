<?php
/**
 * Wiederverwendbare Cyber-Schale für migrierte App-Views.
 *
 * Gegenstück zu layout.php, aber im neuen „Cyber"-Design. WebView wählt dieses
 * Layout, wenn ?theme=cyber aktiv ist UND eine views/web/cyber/<view>.php
 * existiert. Lädt ausschließlich same-origin-Assets aus /cyber/assets (CSP-strikt)
 * und NICHT das klassische /assets/style.css — daher keine Klassen-Kollision.
 *
 * @var string  $content     Gerendertes View-Partial
 * @var ?string $_title
 * @var ?string $_csrf
 */
require_once dirname(__DIR__, 2) . '/public/cyber/inc/components.php';

$CR_ASSETS        = '/cyber/assets';
$_authedUser      = $_authedUser  ?? null;
$_pageStyles      = $_pageStyles  ?? [];
$_pageScripts     = $_pageScripts ?? [];
$_metaDescription = $_metaDescription ?? 'GRAVA — Finde, fahre und erobere deine Gravel- und Bikepacking-Touren.';
$_notifUnread     = $_notifUnread ?? 0;
?><!doctype html>
<html lang="de" data-theme="cyber">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($_title ?? 'GRAVA', ENT_QUOTES, 'UTF-8') ?></title>
<meta name="description" content="<?= htmlspecialchars($_metaDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="theme-color" content="#04060B">
<link rel="stylesheet" href="<?= $CR_ASSETS ?>/cyberride.css">
<link rel="stylesheet" href="<?= $CR_ASSETS ?>/site.css">
<link rel="stylesheet" href="<?= $CR_ASSETS ?>/app.css">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<?php foreach ($_pageStyles as $_href): ?>
<link rel="stylesheet" href="<?= htmlspecialchars((string)$_href, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>
</head>
<body>
<header class="site-header" id="siteHeader">
  <a class="brand" href="/">
    <span class="glyph"><b>G</b></span>
    <span class="word">GRA<span class="r">VA</span></span>
  </a>
  <nav class="site-nav">
    <div class="navlinks">
    <?php if ($_authedUser !== null): ?>
      <a href="/dashboard">Dashboard</a>
      <a href="/features">Funktionen</a>
      <a href="/routes">Routen</a>
      <a href="/discover">Entdecken</a>
      <a href="/heatmap">Heatmap</a>
      <a href="/feed">Feed</a>
      <a href="/notifications">Mitteilungen<?php if ((int)$_notifUnread > 0): ?> (<?= (int)$_notifUnread ?>)<?php endif; ?></a>
      <?php if (!empty($_authedUser['public_handle'])): ?>
        <a href="/u/<?= htmlspecialchars((string)$_authedUser['public_handle'], ENT_QUOTES, 'UTF-8') ?>">@<?= htmlspecialchars((string)$_authedUser['public_handle'], ENT_QUOTES, 'UTF-8') ?></a>
      <?php endif; ?>
    <?php else: ?>
      <a href="/features">Funktionen</a>
      <a href="/discover">Entdecken</a>
      <a href="/heatmap">Heatmap</a>
    <?php endif; ?>
    </div>
    <?php if ($_authedUser !== null): ?>
      <form method="post" action="/logout" class="nav-form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)($_csrf ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="nav-button">Abmelden</button>
      </form>
    <?php else: ?>
      <a class="login" href="/login">Login</a>
      <?= cr_button('Registrieren', ['size' => 'sm', 'variant' => 'primary', 'href' => '/register']) ?>
    <?php endif; ?>
  </nav>
</header>

<main class="cyber-main">
  <div class="cr-wrap">
    <?php if (!empty($flash)): ?>
      <div class="cyber-flash"><?= htmlspecialchars((string)$flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?= $content ?? '' ?>
  </div>
</main>

<footer class="site-footer">
  <div class="cr-wrap">
    <div class="cols">
      <div class="about">
        <a class="brand" href="/" style="margin-bottom:16px">
          <span class="glyph" style="width:30px;height:30px"><b style="font-size:16px">G</b></span>
          <span class="word" style="font-size:20px">GRA<span class="r">VA</span></span>
        </a>
        <p>Fahre, erobere, baue die Map. Oberfläche &middot; Verkehr &middot; Hinweise.</p>
      </div>
      <?php
      $cols = [
        'Produkt'    => [['Funktionen', '/features'], ['Entdecken', '/discover'], ['Heatmap', '/heatmap']],
        'Community'  => [['Feed', '/feed'], ['Registrieren', '/register'], ['Login', '/login']],
        'Rechtliches'=> [['Datenschutz', '/privacy'], ['Nutzungsbedingungen', '/terms'], ['Impressum', '/imprint']],
      ];
      foreach ($cols as $h => $links): ?>
        <div class="col">
          <h4 class="cr-kicker" style="color:var(--text-muted)"><?= htmlspecialchars($h) ?></h4>
          <ul>
            <?php foreach ($links as $l): ?><li><a href="<?= htmlspecialchars($l[1]) ?>"><?= htmlspecialchars($l[0]) ?></a></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="legal">
      <span>© <?= date('Y') ?> GRAVA · ALLE RECHTE VORBEHALTEN</span>
      <span>MADE FOR RIDERS · DE / AT / CH</span>
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
