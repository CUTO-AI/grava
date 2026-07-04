<?php
/**
 * Landing-Texte — Englisch (Standard-Sprache). Marke: CYBERRIDE.
 * Struktur spiegelt die Sektionen; Zahlen/Daten/Versionen sind sprachneutral,
 * werden aber hier gehalten, damit die Sektionen ausschließlich aus $T lesen.
 */
return [
    'meta' => [
        'title' => 'CYBERRIDE — Ride Real Roads. Claim the Grid.',
        'description' => 'CYBERRIDE turns your city into contested territory. Ride real roads to claim sectors and map surface, traffic and hazards for every rider.',
    ],
    'nav' => [
        'features' => 'Features',
        'news'     => 'News',
        'updates'  => 'Updates',
        'heatmap'  => 'Heatmap',
        'live'     => 'Live',
        'login'    => 'Login',
        'getApp'   => 'Get the App',
    ],
    'pulse' => [
        'kicker'  => '// LIVE — TODAY IN THE GAME',
        'h2'      => 'The grid is live right now',
        'lead'    => 'Riders out on the roads, territories changing hands, records falling — watch today’s battle unfold in real time.',
        'cta'     => 'Open the live board',
        'live'    => 'riding now',
        'rides'   => 'rides today',
        'regions' => 'regions taken',
        'records' => 'new records',
    ],
    'lang' => ['en' => 'EN', 'de' => 'DE'],
    'hero' => [
        'badge'     => 'Launch Phase // Season 03',
        'title1'    => 'Ride real roads.',
        'title2'    => 'Claim the grid.',
        'lead'      => 'CYBERRIDE turns your city into contested territory. Every kilometer you ride captures sectors for your crew — and maps surface, traffic and hazards for every rider behind you. Ingress, powered by your bike.',
        'ctaApp'    => 'Get the iOS App',
        'trailer'   => 'Watch Trailer',
        'trailerMeta' => '02:14 // SEASON 03',
        'badges'    => ['✓ FREE — NO SUBSCRIPTION', '✓ WORKS OFFLINE', '✓ GDPR-SAFE'],
        'stats'     => [
            ['4,127', 'Riders Online', 'cyan', '+18% wk'],
            ['892,304', 'KM Conquered', 'lime', null],
            ['1,204', 'Territories', 'magenta', '+37 today'],
            ['216', 'Active Crews', 'neutral', null],
        ],
    ],
    'features' => [
        'kicker' => '// TWO SYSTEMS, ONE RIDE',
        'h2'     => 'A game layered over the real world',
        'lead'   => 'CYBERRIDE runs while you ride. Play for territory; leave better maps behind you.',
        'items'  => [
            [
                'kicker' => '// 01 — TERRITORY',
                'title'  => 'Conquer Your Region',
                'body'   => 'Ride real streets to claim their sectors. Join a crew, defend your turf, climb the leaderboard. Every ride redraws the map — solo or in a duel with the crew across town.',
                'points' => ['Claim sectors by riding', 'Crews & live leaderboards', 'Seasonal territory resets'],
            ],
            [
                'kicker' => '// 02 — INTEL',
                'title'  => 'Auto-Map the Roads',
                'body'   => 'Start recording and just ride. CYBERRIDE reads surface quality and traffic automatically via your radar taillight — no input needed. The data everyone else is missing, built by the community.',
                'points' => ['Surface & traffic, hands-free', 'Community hazard pins', 'Check any route before you go'],
            ],
        ],
        'hud' => [
            'region'  => 'Region Control — Waldkraiburg',
            'rough'   => 'ROUGH SURFACE',
            'smooth'  => 'SMOOTH',
            'radar'   => 'RADAR TAILLIGHT · AUTO',
        ],
    ],
    'news' => [
        'kicker'  => '// LIVE FROM THE GRID',
        'h2'      => 'News from the field',
        'allNews' => 'All News',
        'read'    => 'Read',
        'items'   => [
            ['tag' => 'Community', 'tone' => 'cyan',    'date' => '30 JUN 2026', 'icon' => 'users',  'title' => 'Waldkraiburg Falls to Crew NEONWOLVES', 'excerpt' => 'After a 116km overnight push, NEONWOLVES flipped the entire eastern sector. The map hasn’t looked this contested since launch.'],
            ['tag' => 'Feature',   'tone' => 'magenta', 'date' => '24 JUN 2026', 'icon' => 'route',  'title' => 'Import Your Komoot Routes', 'excerpt' => 'Pull any GPX into CYBERRIDE and see community surface data, traffic and hazards before you roll out.'],
            ['tag' => 'Milestone', 'tone' => 'lime',    'date' => '18 JUN 2026', 'icon' => 'trophy', 'title' => '4,000 Riders Now on the Grid', 'excerpt' => 'The launch region crossed 4k active riders this week — nearly 900,000 km of roads mapped and counting.'],
        ],
    ],
    'updates' => [
        'kicker' => '// PATCH NOTES',
        'h2'     => 'Updates from the game',
        'lead'   => 'What shipped, what changed. Rider-facing changelog for every release.',
        'releases' => [
            ['v' => 'v2.4', 'date' => '01 JUL 2026', 'title' => 'Season 03 — The Reclamation', 'notes' => [
                ['NEW', 'lime', 'Seasonal territory reset — the map is wide open again.'],
                ['NEW', 'lime', 'Crew war rooms with live sector heat.'],
                ['BALANCE', 'cyan', 'Night rides now grant +25% capture bonus.'],
            ]],
            ['v' => 'v2.3', 'date' => '12 JUN 2026', 'title' => 'Radar Intel Overhaul', 'notes' => [
                ['NEW', 'lime', 'Auto surface scoring from radar taillight.'],
                ['FIX', 'magenta', 'Offline uploads no longer drop hazard pins.'],
            ]],
        ],
    ],
    'cta' => [
        'badge'  => 'Free during launch',
        'title1' => 'Ready to claim',
        'title2' => 'your first sector?',
        'lead'   => 'Download CYBERRIDE, start recording, and turn tonight’s ride into territory. No subscription — the grid is waiting.',
        'ctaApp' => 'Download for iOS',
        'ctaHeatmap' => 'Explore the Heatmap',
        'fine'   => 'IOS 16+ · ANDROID SOON · DE · AT · CH',
    ],
    'trailerCaption' => 'Trailer placeholder — drop the Season 03 film here',
    'footer' => [
        'tagline' => 'Ride, conquer, build the map. Surface · Traffic · Hazards — the data the others are missing.',
        'cols' => [
            'Product'   => [['Features', '/features'], ['Heatmap', '/heatmap'], ['Discover', '/discover'], ['Get the App', '#']],
            'Community' => [['Crews', '#'], ['Leaderboards', '#'], ['News', '#news'], ['Strava Club', 'https://www.strava.com/clubs/gravaworld']],
            'Legal'     => [['Privacy', '/privacy'], ['Terms', '/terms'], ['Imprint', '/imprint'], ['GDPR', '/privacy']],
        ],
        'legalL' => '© 2026 CYBERRIDE · ALL SECTORS RESERVED',
        'legalR' => 'MADE FOR RIDERS · DE / AT / CH',
    ],
];
