<?php
/**
 * Landing-Texte — Deutsch. Marke: CYBERRIDE. Nur abweichende Keys nötig
 * (fehlende fallen via inc/lang.php auf en.php zurück).
 */
return [
    'meta' => [
        'title' => 'CYBERRIDE — Fahr echte Wege. Erobere das Grid.',
        'description' => 'CYBERRIDE macht deine Stadt zum umkämpften Revier. Fahr echte Straßen, erobere Sektoren und kartiere Untergrund, Verkehr und Gefahren für alle.',
    ],
    'nav' => [
        'features' => 'Funktionen',
        'news'     => 'News',
        'updates'  => 'Updates',
        'heatmap'  => 'Heatmap',
        'live'     => 'Live',
        'clubs'    => 'Vereine',
        'login'    => 'Login',
        'getApp'   => 'App laden',
    ],
    'pulse' => [
        'kicker'  => '// LIVE — HEUTE IM SPIEL',
        'h2'      => 'Das Revier lebt — gerade jetzt',
        'lead'    => 'Fahrer auf den Straßen, Gebiete wechseln den Besitzer, Rekorde fallen — sieh dem heutigen Kampf in Echtzeit zu.',
        'cta'     => 'Zur Live-Übersicht',
        'live'    => 'jetzt unterwegs',
        'rides'   => 'Fahrten heute',
        'regions' => 'Eroberte Gebiete',
        'records' => 'Neue Rekorde',
    ],
    'map' => [
        'kicker'   => '// DIE LEBENDE KARTE',
        'h2'       => 'Jede eroberte Straße, live im Revier',
        'lead'     => 'Gebiete wechseln täglich den Besitzer. Erkunde die Karte — passend zu deiner Weltregion gezoomt — und sieh, wem die Straßen in deiner Nähe gehören.',
        'aria'     => 'Interaktive Karte der eroberten Gebiete',
        'noscript' => 'Aktiviere JavaScript, um die Live-Revierkarte zu erkunden.',
    ],
    'hero' => [
        'badge'     => 'Launch-Phase // Season 03',
        'title1'    => 'Fahr echte Wege.',
        'title2'    => 'Erobere das Grid.',
        'lead'      => 'CYBERRIDE macht deine Stadt zum umkämpften Revier. Jeder gefahrene Kilometer erobert Sektoren für deine Crew — und kartiert Untergrund, Verkehr und Gefahren für alle, die nach dir kommen. Ingress, angetrieben von deinem Rad.',
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
        'lead'   => 'CYBERRIDE läuft mit, während du fährst. Spiel um Reviere — und hinterlasse bessere Karten.',
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
                'body'   => 'Aufnahme starten und einfach fahren. CYBERRIDE erfasst Untergrund und Verkehr automatisch über dein Radar-Rücklicht — ganz ohne Eingabe. Die Daten, die überall sonst fehlen, gebaut von der Community.',
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
            ['tag' => 'Feature',   'tone' => 'magenta', 'date' => '24. Juni 2026', 'icon' => 'route',  'title' => 'Importiere deine Komoot-Routen', 'excerpt' => 'Zieh eine beliebige GPX in CYBERRIDE und sieh Community-Untergrunddaten, Verkehr und Gefahren, bevor du losrollst.'],
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
        'lead'   => 'Lade CYBERRIDE, starte die Aufnahme und mach die heutige Fahrt zum Revier. Kein Abo — das Grid wartet.',
        'ctaApp' => 'Für iOS laden',
        'ctaHeatmap' => 'Heatmap erkunden',
        'fine'   => 'IOS 16+ · ANDROID BALD · DE · AT · CH',
    ],
    'trailerCaption' => 'Trailer-Platzhalter — hier kommt der Season-03-Film rein',
    'clubs' => [
        // Homepage-Teaser
        'kicker' => '// FÜR VEREINE',
        'h2'     => 'Bring deinen Verein auf die Karte',
        'lead'   => 'CYBERRIDE macht eure Region zum umkämpften Revier — und euren Radverein zur Crew, die es hält. Kostenlos, fair, aus eigener Kraft.',
        'points' => [
            'Neue Mitglieder gewinnen — jede Einladung führt direkt zu eurem Verein',
            'Sichtbarkeit — der aktivste Verein führt eure Region an',
            'Offizieller, geschützter Vereins-Account — nur ihr führt euren Verein',
        ],
        'cta'    => 'Vereine & Vorteile',
        // Eigene Seite /vereine
        'meta'   => [
            'title'       => 'CYBERRIDE für Vereine — Mitglieder, Sichtbarkeit, eure Region',
            'description' => 'Bring deinen Radverein zu CYBERRIDE: neue Mitglieder, Sichtbarkeit und eure Region halten — kostenlos und fair, aus reiner Fahrleistung.',
        ],
        'hero'   => [
            'badge'   => 'FÜR RADSPORT-VEREINE',
            'title1'  => 'Euer Verein.',
            'title2'  => 'Eure Region.',
            'lead'    => 'CYBERRIDE ist das Radsport-Spiel, bei dem Vereine um die Sektoren ihrer Region fahren. Für den Verein kostenlos, für alle fair — kein Bezahl-Vorteil, reines Radfahren.',
            'formCta' => 'Verein anmelden',
            'appCta'  => 'App laden',
        ],
        'benefits' => [
            'kicker' => '// WARUM ALS VEREIN MITMACHEN',
            'h2'     => 'Das bekommt euer Verein',
            'items'  => [
                ['icon' => 'users',        'title' => 'Neue Mitglieder & Nachwuchs',   'body' => 'Jeder Einladungslink führt direkt zu eurem Verein — macht aus Fahrern Mitglieder.'],
                ['icon' => 'trophy',       'title' => 'Sichtbarkeit in eurer Region',  'body' => 'Der aktivste Verein führt die regionale Rangliste an. Seid der Name, den alle sehen.'],
                ['icon' => 'shield-check', 'title' => 'Offizieller, geschützter Account', 'body' => 'Ein verifizierter Vereins-Account, den nur ihr führt — niemand sonst.'],
                ['icon' => 'layout-dashboard', 'title' => 'Gemeinsam, in einem Cockpit', 'body' => 'Ladet Mitglieder per E-Mail ein, seht euren Kader und euren Vereins-Fortschritt an einem Ort.'],
                ['icon' => 'scale',        'title' => 'Fair by Design',                'body' => 'Ranglisten und Belohnungen erfährt man — man kauft sie nicht. Kein Pay-to-Win.'],
                ['icon' => 'piggy-bank',   'title' => 'Vereinskasse (Pilot)',          'body' => 'In Pilotregionen können die Kilometer eures Vereins in die Vereinskasse fließen. Schrittweiser Rollout — sprecht uns an.'],
            ],
        ],
        'form'   => [
            'kicker'     => '// VEREIN ANMELDEN',
            'h2'         => 'Auf die Einladungsliste',
            'lead'       => 'Erzählt uns kurz von eurem Verein — wir schicken euch einen Aktivierungslink. Unverbindlich.',
            'name'       => 'Vereinsname',
            'region'     => 'Landkreis / Region',
            'email'      => 'Kontakt-E-Mail',
            'discipline' => 'Disziplin (Rennrad, Gravel, MTB…)',
            'submit'     => 'Verein anmelden',
            'fine'       => 'Wir nutzen eure Angaben nur, um euch zu CYBERRIDE für Vereine zu kontaktieren.',
            'ok'         => 'Danke! Wir haben euren Verein notiert und melden uns.',
            'err'        => 'Bitte mindestens Vereinsname und eine gültige Kontakt-E-Mail angeben.',
        ],
    ],
    'footer' => [
        'tagline' => 'Fahre, erobere, baue die Karte. Untergrund · Verkehr · Gefahren — die Daten, die den anderen fehlen.',
        'cols' => [
            'Produkt'     => [['Funktionen', '/features'], ['Heatmap', '/heatmap'], ['Entdecken', '/discover'], ['App laden', '#']],
            'Community'   => [['Crews', '#'], ['Ranglisten', '#'], ['News', '#news'], ['Strava-Club', 'https://www.strava.com/clubs/gravaworld']],
            'Rechtliches' => [['Datenschutz', '/privacy'], ['Nutzungsbedingungen', '/terms'], ['Impressum', '/imprint'], ['DSGVO', '/privacy']],
        ],
        'legalL' => '© 2026 CYBERRIDE · ALLE SEKTOREN VORBEHALTEN',
        'legalR' => 'GEBAUT FÜR FAHRER · DE / AT / CH',
    ],
];
