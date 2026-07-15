<?php
/**
 * Landing-Texte — Englisch (Standard-Sprache). Marke: CYBERRIDE.
 * Struktur spiegelt die Sektionen; Zahlen/Daten/Versionen sind sprachneutral,
 * werden aber hier gehalten, damit die Sektionen ausschließlich aus $T lesen.
 */
return [
    'meta' => [
        'title' => 'CYBERRIDE — Ride Real Roads. Claim the Grid.',
        'description' => 'CYBERRIDE turns your region into contested territory. Ride real roads to claim sectors and map surface, traffic and hazards for every rider.',
    ],
    'nav' => [
        'features' => 'Features',
        'news'     => 'News',
        'updates'  => 'Updates',
        'heatmap'  => 'Heatmap',
        'live'     => 'Live',
        'clubs'    => 'Clubs',
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
    'map' => [
        'kicker'   => '// THE LIVING MAP',
        'h2'       => 'Every conquered road, live on the grid',
        'lead'     => 'Territories change hands every day. Explore the map — zoomed to your part of the world — and see who owns the roads near you.',
        'aria'     => 'Interactive map of conquered territories',
        'noscript' => 'Enable JavaScript to explore the live territory map.',
    ],
    'lang' => ['en' => 'EN', 'de' => 'DE'],
    'hero' => [
        'badge'     => 'Launch Phase // Season 03',
        'title1'    => 'Ride real roads.',
        'title2'    => 'Claim the grid.',
        'lead'      => 'CYBERRIDE turns your region into contested territory. Every kilometer you ride captures sectors for your crew — and maps surface, traffic and hazards for every rider behind you. Ingress, powered by your bike.',
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
    'clubs' => [
        // Homepage-Teaser
        'kicker' => '// FOR CLUBS',
        'h2'     => 'Bring your club onto the map',
        'lead'   => 'CYBERRIDE turns your region into contested territory — and your cycling club into the crew that holds it. Free, fair, ride-powered.',
        'points' => [
            'Win new members — every invite links straight to your club',
            'Visibility — the most active club leads your region',
            'Official, protected club account — only you run your club',
        ],
        'cta'    => 'Clubs & benefits',
        // Dedicated page /vereine
        'meta'   => [
            'title'       => 'CYBERRIDE for Clubs — Members, Visibility, Your Region',
            'description' => 'Bring your cycling club to CYBERRIDE: win members, gain visibility and hold your region — free and fair, powered by real riding.',
        ],
        'hero'   => [
            'badge'   => 'FOR CYCLING CLUBS',
            'title1'  => 'Your club.',
            'title2'  => 'Your region.',
            'lead'    => 'CYBERRIDE is the cycling game where clubs ride to hold the sectors of their region. Free for the club, fair for everyone — no pay-to-win, pure riding.',
            'formCta' => 'Register your club',
            'appCta'  => 'Get the App',
        ],
        'benefits' => [
            'kicker' => '// WHY JOIN AS A CLUB',
            'h2'     => 'What your club gets',
            'items'  => [
                ['icon' => 'users',        'title' => 'New members & youth',        'body' => 'Every invite link points straight to your club — turn riders into members.'],
                ['icon' => 'trophy',       'title' => 'Visibility in your region',  'body' => 'The most active club leads the regional standings. Be the name everyone sees.'],
                ['icon' => 'shield-check', 'title' => 'Official, protected account', 'body' => 'A verified club account only you control — nobody else can run your club.'],
                ['icon' => 'layout-dashboard', 'title' => 'Together, in one cockpit', 'body' => 'Invite members by email, see your roster and your club’s progress in one place.'],
                ['icon' => 'scale',        'title' => 'Fair by design',             'body' => 'Standings and rewards are earned by riding — never bought. No pay-to-win, ever.'],
                ['icon' => 'piggy-bank',   'title' => 'Club treasury (pilot)',      'body' => 'In pilot regions, club kilometres can feed a club treasury. Rolling out step by step — talk to us.'],
            ],
        ],
        'form'   => [
            'kicker'     => '// REGISTER YOUR CLUB',
            'h2'         => 'Get on the invite list',
            'lead'       => 'Tell us about your club and we’ll send you an activation link. No obligation.',
            'name'       => 'Club name',
            'region'     => 'County / region',
            'email'      => 'Contact email',
            'discipline' => 'Discipline (road, gravel, MTB…)',
            'submit'     => 'Register club',
            'fine'       => 'We use your details only to contact you about CYBERRIDE for clubs.',
            'ok'         => 'Thanks! We’ve noted your club and will be in touch.',
            'err'        => 'Please enter at least a club name and a valid contact email.',
        ],
    ],
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
