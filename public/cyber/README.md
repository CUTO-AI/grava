# CyberRide — plain-PHP landing page (parallel rollout)

Server-rendered port of the CyberRide landing page. **No framework, no Composer, no build
step, no database.** Just PHP `require` + CSS + a little vanilla JS. Meant to run *alongside*
the existing GRAVA site as the first migrated route.

## Preview
`preview.html` is a static render of the exact same markup/CSS (open it in a browser to see
the result without a PHP server). `index.php` produces the same output through PHP.

## File map
```
php/
├─ index.php              # entry — includes header + sections + footer
├─ inc/
│  ├─ components.php       # cr_icon() cr_badge() cr_button() cr_card_open/close() cr_stat()
│  ├─ header.php           # <head>, sticky header, opens <main>
│  └─ footer.php           # footer, trailer modal, script tags, closes page
├─ sections/
│  ├─ hero.php  features.php  news.php  updates.php  cta.php
├─ assets/
│  ├─ cyberride.css        # design-system tokens + fonts + base (generated, portable)
│  ├─ site.css             # marketing-page component + layout classes
│  └─ site.js              # header scroll state, trailer modal, smooth nav, icon init
└─ preview.html            # static snapshot for quick viewing
```

Content (stats, features, news, releases) lives in plain PHP arrays at the top of each
section file — swap those for your real data source when wiring it up.

## ⚠️ Dependencies (hand these to your PHP/Xcode agent)
1. **Google Fonts (CDN).** `cyberride.css` starts with an `@import` for **Chakra Petch**,
   **Rajdhani**, **JetBrains Mono**. Needs outbound network at page load.
   → *For production, self-host the woff2 files and replace the `@import` with `@font-face`.*
2. **Lucide icons (CDN).** `footer.php`/`preview.html` load
   `https://unpkg.com/lucide@latest/dist/umd/lucide.min.js`; `site.js` calls
   `lucide.createIcons()`. Icons are `<i data-lucide="…">` placeholders until that runs.
   → *Pin a version (not `@latest`) or self-host. Lucide's latest build dropped brand glyphs,
   so the footer socials use `camera` / `at-sign` / `activity` as stand-ins.*
3. **PHP:** any version with short-echo `<?= … ?>` (5.4+, so effectively all). Nothing else.
4. **No JS framework / bundler.** All JS is one plain file.

## ⚠️ CSS namespacing — read before global include
`site.css` uses some **generic class names** (`.hero`, `.brand`, `.modal`, `.col`, `.stat`,
`.news-band`, …) that **could collide** with the existing GRAVA stylesheet. Because you're
running both in parallel, pick one of:
- **(Recommended) Page-scoped load** — only `<link>` `cyberride.css` + `site.css` on the new
  CyberRide route(s). The old pages never load them, so no collision. This is the natural fit
  for page-by-page migration.
- **Scope under `[data-theme="cyber"]`** — the `<html>` already carries `data-theme="cyber"`.
  Ask me to re-emit `site.css` with every selector prefixed by `[data-theme="cyber"]` so it
  can coexist with the old CSS on the same page.
- **Prefix everything** — ask me to rename all generic classes to a `cr-` prefix.

`cyberride.css` (the tokens) is safe: it only defines `--cr…`-style custom properties and a
handful of `.cr-*` utility classes.

## Parallel rollout in plain PHP (theme switch)
Keep the old site as default; make the new look opt-in via a cookie/query flag. Minimal glue:

```php
<?php
// resolve theme once, e.g. in your front controller / config
$theme = $_GET['theme'] ?? ($_COOKIE['theme'] ?? 'classic');
if (isset($_GET['theme'])) { setcookie('theme', $theme, time()+2592000, '/'); }

if ($theme === 'cyber') {
    require __DIR__ . '/cyber/index.php';   // this folder
} else {
    require __DIR__ . '/legacy-landing.php'; // your current page
}
```

- Visit `?theme=cyber` to flip a user to the new design; `?theme=classic` to go back.
- Migrate one route at a time — each converted route renders the new template; everything
  else stays on the old layout. Both ship from the same codebase.
- Retire the flag and delete the legacy layout once every route is converted.

**Asset paths:** the `<link>`/`<script>` hrefs in `inc/header.php` and `inc/footer.php` are
relative (`assets/…`), assuming `index.php` is served from this folder. If you mount it
elsewhere, set an absolute base (e.g. `/cyber/assets/…`) or define a `$BASE` constant and
prepend it.

## Placeholders to replace
- Hero "trailer" + the trailer modal → the real Season 03 video.
- Feature/news thumbnails are CSS/grid HUD graphics → real app screenshots.
- Wordmark is type-set (no logo supplied) → drop in a real SVG mark.
- All links are `#` → wire to real routes.
