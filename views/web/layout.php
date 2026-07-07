<?php
/** @var string $content */
/** @var string $_title */
/** @var string $_csrf */
$_authedUser  = $_authedUser  ?? null;
$_layoutWide  = $_layoutWide  ?? false;
$mainClass    = 'container' . ($_layoutWide ? ' container--wide' : '');
// Optionale, seiten-spezifische Assets (z. B. Leaflet-Karten). Listen aus
// reinen same-origin-Pfaden ('self'), damit die strikte CSP greift — keine
// Inline-Scripts. Controller/Views setzen $_pageStyles / $_pageScripts.
$_pageStyles  = $_pageStyles  ?? [];
$_pageScripts = $_pageScripts ?? [];
// SEO & Social Meta-Tags
$_metaDescription = $_metaDescription ?? 'CYBERRIDE — Finde, fahre und erobere deine Gravel- und Bikepacking-Touren. Objektive Wegqualität, Community-Power und Territorialspiel in einer App.';
$_metaKeywords    = $_metaKeywords    ?? 'Gravel, Bikepacking, Radtouren, Wegqualität, Schotter, Rennrad, Community, GPS';
$_ogTitle         = $_ogTitle         ?? ($_title ?? 'CYBERRIDE');
$_ogDescription   = $_ogDescription   ?? $_metaDescription;
$_ogImage         = $_ogImage         ?? '/assets/brand/icon-512.png';
$_canonical       = $_canonical       ?? ($_ogUrl ?? ($_SERVER['REQUEST_URI'] ?? '/'));
$_ogUrl           = $_ogUrl           ?? $_canonical;
$_robots          = $_robots          ?? 'index, follow';
$_analyticsGroup  = $_analyticsGroup  ?? 'other';
$_hreflangPath    = $_hreflangPath    ?? (strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/');
$_pageLanguage    = $_pageLanguage    ?? \App\Support\I18n::locale();
$_ogLocale        = $_pageLanguage === 'de' ? 'de_DE' : 'en_US';
$_ogLocaleAlt     = $_pageLanguage === 'de' ? 'en_US' : 'de_DE';
?><!doctype html>
<html lang="<?= htmlspecialchars($_pageLanguage, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($_title ?? 'CYBERRIDE', ENT_QUOTES, 'UTF-8') ?></title>

    <!-- SEO Meta-Tags -->
    <meta name="description" content="<?= htmlspecialchars($_metaDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($_metaKeywords, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="author" content="CYBERRIDE">
    <meta name="robots" content="<?= htmlspecialchars($_robots, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="canonical" href="<?= htmlspecialchars($_canonical, ENT_QUOTES, 'UTF-8') ?>">
    <?= \App\Support\SiteUrl::hreflangLinks($_hreflangPath) ?>

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($_canonical, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($_ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($_ogDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($_ogImage, ENT_QUOTES, 'UTF-8') ?>">
    <?php if (!empty($_ogImageWidth) && !empty($_ogImageHeight)): ?>
    <meta property="og:image:width" content="<?= (int)$_ogImageWidth ?>">
    <meta property="og:image:height" content="<?= (int)$_ogImageHeight ?>">
    <?php endif; ?>
    <meta property="og:locale" content="<?= $_ogLocale ?>">
    <meta property="og:locale:alternate" content="<?= $_ogLocaleAlt ?>">
    <meta property="og:site_name" content="CYBERRIDE">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?= htmlspecialchars($_canonical, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:title" content="<?= htmlspecialchars($_ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($_ogDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($_ogImage, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Theme Color -->
    <meta name="theme-color" content="#2f5233">
    <!-- Consent Mode v2: Default (denied) VOR dem GA-Loader setzen -->
    <script src="/assets/js/consent.js"></script>
    <!-- Google tag (gtag.js) — Loader extern, Init aus same-origin /assets/js/ga.js (CSP) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-HVRGQSKQNV"></script>
    <script type="application/json" id="ga-data"><?= json_encode(['content_group' => $_analyticsGroup ?? 'other', 'page_title' => $_title ?? 'CYBERRIDE', 'page_location' => $_canonical, 'page_language' => $_pageLanguage], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script src="/assets/js/ga.js"></script>
    <script src="/assets/js/events.js"></script>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
    <?= \App\Support\StructuredData::render($_jsonLd ?? []) ?>
    <?php foreach ($_pageStyles as $_href): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars((string)$_href, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
</head>
<body>
    <header class="site-header">
        <a href="/" class="brand"><img src="/assets/brand/icon-512.png" alt="CYBERRIDE" class="brand-icon"><span class="brand-word">CYBERRIDE</span></a>
        <nav>
        <?php $_surfaceCheck = \App\Config\Config::instance()->bool('SURFACE_CHECK_ENABLED', true); ?>
        <?php if ($_authedUser !== null): ?>
            <a href="/dashboard">Dashboard</a>
            <a href="/features">Funktionen</a>
            <a href="/routes">Routen</a>
            <a href="/discover">Entdecken</a>
            <a href="/heatmap">Heatmap</a>
            <a href="/pulse">Heute im Spiel</a>
            <?php if ($_surfaceCheck): ?><a href="/surface-check">Belag prüfen</a><?php endif; ?>
            <a href="/feed">Feed</a>
            <?php $_notifUnread = $_notifUnread ?? 0; ?>
            <a href="/notifications">Mitteilungen<?php if ((int)$_notifUnread > 0): ?> <span class="notif-badge"><?= (int)$_notifUnread ?></span><?php endif; ?></a>
            <?php if (!empty($_authedUser['public_handle'])): ?>
                <a href="/u/<?= htmlspecialchars((string)$_authedUser['public_handle'], ENT_QUOTES, 'UTF-8') ?>">@<?= htmlspecialchars((string)$_authedUser['public_handle'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
            <form method="post" action="/logout" class="nav-form">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="nav-button">Abmelden</button>
            </form>
        <?php else: ?>
            <a href="/features">Funktionen</a>
            <a href="/discover">Entdecken</a>
            <a href="/heatmap">Heatmap</a>
            <a href="/pulse">Heute im Spiel</a>
            <a href="/login">Login</a>
            <a href="/register">Registrieren</a>
        <?php endif; ?>
        </nav>
    </header>
    <main class="<?= $mainClass ?>">
        <?php if (!empty($flash)): ?>
            <div class="flash"><?= htmlspecialchars((string)$flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?= $content ?? '' ?>
    </main>
    <footer class="site-footer">
        <div class="footer-content">
            <div class="footer-section">
                <h4 class="footer-heading">Produkt</h4>
                <a href="/features">Funktionen</a>
                <a href="/discover">Entdecken</a>
                <a href="/heatmap">Heatmap</a>
                <?php if ($_authedUser === null): ?>
                <a href="/register">App laden</a>
                <?php endif; ?>
            </div>

            <div class="footer-section">
                <h4 class="footer-heading">Rechtliches</h4>
                <a href="/privacy">Datenschutz</a>
                <a href="/terms">Nutzungsbedingungen</a>
                <a href="/imprint">Impressum</a>
            </div>

            <div class="footer-section">
                <h4 class="footer-heading">Folgen</h4>
                <a href="https://instagram.com/gravaapp" target="_blank" rel="noopener">Instagram</a>
                <a href="https://twitter.com/gravaapp" target="_blank" rel="noopener">Twitter</a>
                <a href="https://www.strava.com/clubs/gravaworld" target="_blank" rel="noopener">Strava Club</a>
            </div>

            <div class="footer-section">
                <h4 class="footer-heading">CYBERRIDE</h4>
                <p class="footer-tagline">
                    Fahre, erobere, baue die Map.<br>
                    Oberfläche · Verkehr · Hinweise
                </p>
            </div>
        </div>

        <div class="footer-bottom">
            <small>&copy; <?= date('Y') ?> CYBERRIDE. Alle Rechte vorbehalten.</small>
        </div>
    </footer>
    <?php require dirname(__DIR__) . '/web/partials/consent-banner.php'; ?>
    <?php foreach ($_pageScripts as $_src): ?>
    <script src="<?= htmlspecialchars((string)$_src, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endforeach; ?>
</body>
</html>
