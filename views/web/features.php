<?php
/**
 * Öffentliche „Funktionen & Neuigkeiten"-Seite.
 *
 * WICHTIG: Diese Seite ist öffentlich-nutzerseitig. Hier stehen NUR Features
 * und Neuigkeiten in Nutzersprache — KEINE sicherheits-/infrastrukturrelevanten
 * Details (keine Endpunkte, Tokens, Server-/Build-/Signing-Infos). Bei
 * Erweiterungen bitte beibehalten.
 */
$badge = static function (string $label, string $kind = 'ok'): string {
    $styles = [
        'ok'      => 'background:var(--success-bg);color:var(--success-text)',
        'soon'    => 'background:var(--accent-weak);color:var(--accent-hover)',
        'planned' => 'background:var(--primary-weak);color:var(--primary)',
    ];
    $s = $styles[$kind] ?? $styles['ok'];
    return '<span class="badge" style="' . $s . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};
?>

<!-- Hero Section -->
<section class="hero hero--single-column" style="padding: 80px 24px;">
    <div class="hero-content">
        <h1 class="hero-title" style="font-size: 42px;"><?= t('Funktionen &amp; Neuigkeiten') ?></h1>
        <p class="hero-subtitle">
            <?= t('Was GRAVA heute alles kann — und was als Nächstes kommt.') ?>
            <br><strong><?= t('Version 0.1.0') ?></strong> · 2026-06-30
        </p>
    </div>
</section>

<!-- Features Grid -->
<section class="features-section">
    <div class="features-container">
        <div class="feature-card">
            <div class="feature-icon">📍</div>
            <h3 class="feature-title"><?= t('Aufzeichnung &amp; Wegqualität') ?></h3>
            <ul class="feature-list">
                <li><?= t('Fahrt aufzeichnen mit Live-Anzeigen für Tempo, Höhenmeter, Untergrund und Verkehr') ?></li>
                <li><?= t('Wegqualität-Score (1–5) aus Vibration und Geschwindigkeit') ?></li>
                <li><?= t('Halterungsprofile mit eigener Kalibrierung') ?></li>
                <li><?= t('Höhenprofil über Barometer und GPS') ?></li>
                <li><?= t('Verkehrszählung per Radar (Garmin Varia, Bluetooth)') ?></li>
                <li><?= t('Herzfrequenz per Bluetooth-Sensor – live sowie Puls-Chart und Ø/Max im Fahrt-Detail') ?></li>
                <li><?= t('Leistung, Trittfrequenz &amp; Pedal-Balance per Bluetooth-Powermeter (Shimano, SRAM, Quarq, Stages &hellip;)') ?></li>
                <li><?= t('Sensor-Gerät auswählen &amp; merken – verbindet gezielt dein Gerät, auch mit mehreren Rädern oder in der Gruppenausfahrt') ?></li>
                <li><?= t('Hinweise unterwegs setzen') ?></li>
                <li><?= t('Live Activity auf Sperrbildschirm') ?></li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🗺️</div>
            <h3 class="feature-title"><?= t('Routen, Import &amp; Export') ?></h3>
            <ul class="feature-list">
                <li><?= t('GPX-Import und GPX-Export inklusive Wegqualität') ?></li>
                <li><?= t('CSV-Rohdaten-Export') ?></li>
                <li><?= t('Lokale Fahrten- und Routenliste mit Suche') ?></li>
                <li><?= t('Fahrt-Detail: Karte mit Score-Strecke, Höhenprofil') ?></li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">☁️</div>
            <h3 class="feature-title"><?= t('Konto &amp; Cloud') ?> <span class="muted"><?= t('(optional)') ?></span></h3>
            <ul class="feature-list">
                <li><?= t('Konto optional – Aufzeichnen funktioniert auch ohne Anmeldung') ?></li>
                <li><?= t('Registrierung mit E-Mail-Bestätigung') ?></li>
                <li><?= t('Profil: Anzeigename, Handle, Profilbild') ?></li>
                <li><?= t('Cloud-Routen: Upload mit Sichtbarkeit (privat/Link/öffentlich)') ?></li>
                <li><?= t('Teilen-Links erstellen und widerrufen') ?></li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">👥</div>
            <h3 class="feature-title"><?= t('Community') ?></h3>
            <ul class="feature-list">
                <li><?= t('Entdecken, Feed und Heatmap') ?></li>
                <li><?= t('Folgen/Entfolgen und öffentliche Profile') ?></li>
                <li><?= t('Kommentare und Likes auf Routen') ?></li>
                <li><?= t('Mitteilungen mit Push (pro Typ schaltbar)') ?></li>
                <li><?= t('Freunde einladen über Einladungslinks') ?></li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🎮</div>
            <h3 class="feature-title"><?= t('Territorialspiel „Reviere"') ?></h3>
            <ul class="feature-list">
                <li><?= t('Solo: Karte mit eingefärbten Kanten (Besitz, Frische, Wert)') ?></li>
                <li><?= t('Route „ins Spiel aufnehmen" – automatisches Wegenetz-Matching') ?></li>
                <li><?= t('Crews: gründen, beitreten, Mitgliederliste, Crew-Rangliste') ?></li>
                <li><?= t('Fraktionen: Grün vs. Blau, Meta-Karte mit Zellen-Gewinnern') ?></li>
                <li><?= t('Spieler-Rangliste: Welt/Freunde × Woche/Saison/Gesamt') ?></li>
                <li><?= t('Segment-Bestzeiten: schnellste Zeiten je Abschnitt (nach Rad-Typ)') ?></li>
                <li><?= t('Rush: Gruppenfahrten mit gemeinsamer Übernahme') ?></li>
                <li><?= t('Spielregeln &amp; Hilfe direkt in der App') ?></li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🏅</div>
            <h3 class="feature-title"><?= t('Ränge, Level &amp; Abzeichen') ?></h3>
            <ul class="feature-list">
                <li><?= t('Aufstieg über mehrere Ränge mit eigener In-App-Aufstiegs-Feier') ?></li>
                <li><?= t('Rang-Leiter und Abzeichen-Galerie zum Durchblättern') ?></li>
                <li><?= t('Abzeichen in mehreren Familien und Stufen zum Freischalten') ?></li>
                <li><?= t('Wochen-Serie (Streak): Flammen-Chip für Wochen in Folge mit Fahrt') ?></li>
                <li><?= t('Aufgaben: wechselnde Wochen- und Saison-Ziele mit Belohnung') ?></li>
                <li><?= t('Pionier &amp; Erstbefahrer: Namensrecht für die erste Befahrung') ?></li>
                <li><?= t('Revier-Recap nach der Fahrt mit Punkte-Übersicht') ?></li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🏃</div>
            <h3 class="feature-title">Strava</h3>
            <ul class="feature-list">
                <li><?= t('Strava verbinden') ?></li>
                <li><?= t('Aktivitäten importieren als private Cloud-Routen') ?></li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🔒</div>
            <h3 class="feature-title"><?= t('Privatsphäre') ?></h3>
            <ul class="feature-list">
                <li><?= t('Heimat-/Privatzone: Verschleierung rund um dein Zuhause') ?></li>
                <li><?= t('Radius frei einstellbar') ?></li>
                <li><?= t('Wirkt auch rückwirkend') ?></li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">⚙️</div>
            <h3 class="feature-title"><?= t('Bedienung &amp; Qualität') ?></h3>
            <ul class="feature-list">
                <li><?= t('Sprachen: Deutsch und Englisch (vollständig)') ?></li>
                <li><?= t('Barrierefreiheit: VoiceOver, Dynamic Type') ?></li>
                <li><?= t('Robuste Zustände: Leer-, Fehler- und Offline-Ansichten') ?></li>
                <li><?= t('Onboarding beim Erststart') ?></li>
                <li><?= t('Deep Links für Routen, E-Mail, Passwort-Reset') ?></li>
            </ul>
        </div>
    </div>
</section>

<!-- Roadmap Section -->
<section class="how-section">
    <div class="how-container">
        <h2 class="section-heading"><?= t('Was kommt als Nächstes') ?></h2>
        <p style="text-align: center; color: var(--muted); margin-bottom: 48px;">
            <?= t('Roadmap-Ausblick – Reihenfolge und Umfang können sich ändern') ?>
        </p>
        <div style="max-width: 900px; margin: 0 auto;">
            <ul style="list-style:none; padding:0; display:flex; flex-direction:column; gap:1rem;">
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge(t('Verfügbar')) ?>
                    <strong><?= t('Ränge &amp; Abzeichen, Wochen-Serie, Aufgaben, Pionier-Namensrecht, Segment-Bestzeiten, Crew- &amp; Spieler-Rangliste, Onboarding, Heimat-/Privatzone, Spielregeln und Live Activity') ?></strong> <?= t('sind bereits an Bord.') ?>
                </li>
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge(t('In Kürze'), 'soon') ?>
                    <strong><?= t('Push-Benachrichtigungen') ?></strong> <?= t('für neue Follower, Likes und Kommentare.') ?>
                </li>
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge(t('Geplant'), 'planned') ?>
                    <strong><?= t('Personensuche') ?></strong> <?= t('– Fahrer per Name oder Handle finden.') ?>
                </li>
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge(t('Geplant'), 'planned') ?>
                    <strong><?= t('Weitere Sprachen') ?></strong> <?= t('über Deutsch und Englisch hinaus.') ?>
                </li>
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge(t('Idee'), 'planned') ?>
                    <strong><?= t('Apple-Watch-App') ?></strong> <?= t('zur Aufzeichnung am Handgelenk.') ?>
                </li>
            </ul>
        </div>
    </div>
</section>

<style>
.feature-list {
    margin: 0;
    padding-left: 1.5rem;
    list-style: disc;
    color: var(--muted);
    font-size: 15px;
    line-height: 1.6;
}

.feature-list li {
    margin-bottom: 8px;
}

.feature-card .muted {
    font-weight: 400;
    font-size: 0.9em;
    color: var(--muted);
}

.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 600;
    margin-right: 8px;
}
</style>
