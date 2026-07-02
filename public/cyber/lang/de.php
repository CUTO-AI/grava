<?php
/**
 * Landing-Texte — Deutsch. Marke: GRAVA. Nur abweichende Keys nötig
 * (fehlende fallen via inc/lang.php auf en.php zurück).
 */
return [
    'meta' => [
        'title' => 'GRAVA — Fahr echte Wege. Erobere das Grid.',
        'description' => 'GRAVA macht deine Stadt zum umkämpften Revier. Fahr echte Straßen, erobere Sektoren und kartiere Untergrund, Verkehr und Gefahren für alle.',
    ],
    'nav' => [
        'features' => 'Funktionen',
        'news'     => 'News',
        'updates'  => 'Updates',
        'heatmap'  => 'Heatmap',
        'login'    => 'Login',
        'getApp'   => 'App laden',
    ],
    'hero' => [
        'badge'     => 'Launch-Phase // Season 03',
        'title1'    => 'Fahr echte Wege.',
        'title2'    => 'Erobere das Grid.',
        'lead'      => 'GRAVA macht deine Stadt zum umkämpften Revier. Jeder gefahrene Kilometer erobert Sektoren für deine Crew — und kartiert Untergrund, Verkehr und Gefahren für alle, die nach dir kommen. Ingress, angetrieben von deinem Rad.',
        'ctaApp'    => 'iOS-App laden',
        'trailer'   => 'Trailer ansehen',
        'trailerMeta' => '02:14 // SEASON 03',
        'badges'    => ['✓ GRATIS — KEIN ABO', '✓ FUNKTIONIERT OFFLINE', '✓ DSGVO-KONFORM'],
        'stats'     => [
            ['4.127', 'Fahrer online', 'cyan', '+18% Wo.'],
            ['892.304', 'km erobert', 'lime', null],
            ['1.204', 'Reviere', 'magenta', '+37 heute'],
            ['216', 'Aktive Crews', 'neutral', null],
        ],
    ],
    'features' => [
        'kicker' => '// ZWEI SYSTEME, EINE FAHRT',
        'h2'     => 'Ein Spiel über der echten Welt',
        'lead'   => 'GRAVA läuft mit, während du fährst. Spiel um Reviere — und hinterlasse bessere Karten.',
        'items'  => [
            [
                'kicker' => '// 01 — REVIER',
                'title'  => 'Erobere deine Region',
                'body'   => 'Fahr echte Straßen, um ihre Sektoren zu erobern. Tritt einer Crew bei, verteidige dein Revier, klettere die Rangliste hoch. Jede Fahrt zeichnet die Karte neu — solo oder im Duell mit der Crew von nebenan.',
                'points' => ['Sektoren durch Fahren erobern', 'Crews & Live-Ranglisten', 'Saisonale Revier-Resets'],
            ],
            [
                'kicker' => '// 02 — INTEL',
                'title'  => 'Straßen automatisch kartieren',
                'body'   => 'Aufnahme starten und einfach fahren. GRAVA erfasst Untergrund und Verkehr automatisch über dein Radar-Rücklicht — ganz ohne Eingabe. Die Daten, die überall sonst fehlen, gebaut von der Community.',
                'points' => ['Untergrund & Verkehr, freihändig', 'Community-Gefahren-Pins', 'Jede Route vorab prüfen'],
            ],
        ],
        'hud' => [
            'region'  => 'Revier-Kontrolle — Waldkraiburg',
            'rough'   => 'RAUER UNTERGRUND',
            'smooth'  => 'GLATT',
            'radar'   => 'RADAR-RÜCKLICHT · AUTO',
        ],
    ],
    'news' => [
        'kicker'  => '// LIVE AUS DEM GRID',
        'h2'      => 'News vom Feld',
        'allNews' => 'Alle News',
        'read'    => 'Lesen',
        'items'   => [
            ['tag' => 'Community', 'tone' => 'cyan',    'date' => '30. Juni 2026', 'icon' => 'users',  'title' => 'Waldkraiburg fällt an Crew NEONWOLVES', 'excerpt' => 'Nach einer 116-km-Nachtfahrt kippten die NEONWOLVES den kompletten Ost-Sektor. So umkämpft war die Karte seit dem Launch nicht mehr.'],
            ['tag' => 'Feature',   'tone' => 'magenta', 'date' => '24. Juni 2026', 'icon' => 'route',  'title' => 'Importiere deine Komoot-Routen', 'excerpt' => 'Zieh eine beliebige GPX in GRAVA und sieh Community-Untergrunddaten, Verkehr und Gefahren, bevor du losrollst.'],
            ['tag' => 'Meilenstein','tone'=> 'lime',    'date' => '18. Juni 2026', 'icon' => 'trophy', 'title' => '4.000 Fahrer jetzt im Grid', 'excerpt' => 'Die Launch-Region überschritt diese Woche 4.000 aktive Fahrer — fast 900.000 km Straße kartiert, Tendenz steigend.'],
        ],
    ],
    'updates' => [
        'kicker' => '// PATCH NOTES',
        'h2'     => 'Updates aus dem Spiel',
        'lead'   => 'Was neu ist, was sich geändert hat. Der Changelog für Fahrer zu jedem Release.',
        'releases' => [
            ['v' => 'v2.4', 'date' => '01. Juli 2026', 'title' => 'Season 03 — Die Rückeroberung', 'notes' => [
                ['NEU', 'lime', 'Saisonaler Revier-Reset — die Karte ist wieder weit offen.'],
                ['NEU', 'lime', 'Crew-Lageräume mit Live-Sektor-Heat.'],
                ['BALANCE', 'cyan', 'Nachtfahrten geben jetzt +25% Eroberungs-Bonus.'],
            ]],
            ['v' => 'v2.3', 'date' => '12. Juni 2026', 'title' => 'Radar-Intel-Überholung', 'notes' => [
                ['NEU', 'lime', 'Automatisches Untergrund-Rating vom Radar-Rücklicht.'],
                ['FIX', 'magenta', 'Offline-Uploads verlieren keine Gefahren-Pins mehr.'],
            ]],
        ],
    ],
    'cta' => [
        'badge'  => 'Gratis im Launch',
        'title1' => 'Bereit, deinen',
        'title2' => 'ersten Sektor zu erobern?',
        'lead'   => 'Lade GRAVA, starte die Aufnahme und mach die heutige Fahrt zum Revier. Kein Abo — das Grid wartet.',
        'ctaApp' => 'Für iOS laden',
        'ctaHeatmap' => 'Heatmap erkunden',
        'fine'   => 'IOS 16+ · ANDROID BALD · DE · AT · CH',
    ],
    'trailerCaption' => 'Trailer-Platzhalter — hier kommt der Season-03-Film rein',
    'footer' => [
        'tagline' => 'Fahre, erobere, baue die Karte. Untergrund · Verkehr · Gefahren — die Daten, die den anderen fehlen.',
        'cols' => [
            'Produkt'     => [['Funktionen', '/features'], ['Heatmap', '/heatmap'], ['Entdecken', '/discover'], ['App laden', '#']],
            'Community'   => [['Crews', '#'], ['Ranglisten', '#'], ['News', '#news'], ['Strava-Club', 'https://www.strava.com/clubs/gravaworld']],
            'Rechtliches' => [['Datenschutz', '/privacy'], ['Nutzungsbedingungen', '/terms'], ['Impressum', '/imprint'], ['DSGVO', '/privacy']],
        ],
        'legalL' => '© 2026 GRAVA · ALLE SEKTOREN VORBEHALTEN',
        'legalR' => 'GEBAUT FÜR FAHRER · DE / AT / CH',
    ],
];
