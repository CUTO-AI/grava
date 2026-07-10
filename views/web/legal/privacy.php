<!-- Hero Section -->
<section class="hero hero--single-column" style="padding: 80px 24px 48px;">
    <div class="hero-content">
        <h1 class="hero-title" style="font-size: 42px;"><?= t('Datenschutzerklärung') ?></h1>
        <p class="hero-subtitle" style="color: var(--muted);">
            <?= t('Stand:') ?> <strong>4. Juli 2026</strong>
        </p>
    </div>
</section>

<!-- Content Section -->
<section class="legal-content">
    <div class="legal-container">
        <h2>1. <?= t('Verantwortlicher') ?></h2>
        <p>
            benX AG<br>
            Richard-Wagner-Str. 38, 84478 Waldkraiburg, <?= t('Deutschland') ?><br>
            <?= t('E-Mail:') ?> <a href="mailto:grava@benx.de" style="color: var(--primary);">grava@benx.de</a>
        </p>

        <h2>2. <?= t('Welche Daten wir verarbeiten') ?></h2>
        <ul>
            <li><strong><?= t('Kontodaten:') ?></strong> <?= t('E-Mail-Adresse, Name, Handle (Benutzername).') ?>
                <a href="/delete-account"><?= t('Konto und alle Daten hier löschen') ?></a>.</li>
            <li><strong><?= t('Standort- und Routendaten:') ?></strong> <?= t('hochgeladene bzw. aufgezeichnete Strecken und Wegpunkte.') ?></li>
            <li><strong><?= t('Fahrt-Metriken:') ?></strong> <?= t('Distanz, Dauer, Belag- und Aktivitätskennzahlen.') ?></li>
            <li><strong><?= t('Fitnessdaten (optional):') ?></strong> <?= t('Leistung, Trittfrequenz und Pedal-Balance aus einem von dir gekoppelten Bluetooth-Powermeter – nur, wenn ein Sensor verbunden ist und die Fahrt hochgeladen wird.') ?></li>
            <li><strong><?= t('Gesundheitsdaten (optional):') ?></strong> <?= t('Herzfrequenz aus einem von dir gekoppelten Bluetooth-Sensor – nur, wenn ein Sensor verbunden ist und die Fahrt hochgeladen wird. Reine Aufzeichnung; beim Teilen auf Strava wird sie mit der Aktivität übertragen.') ?></li>
            <li><strong><?= t('Profilfotos / Avatare:') ?></strong> <?= t('von dir hochgeladene Bilder.') ?></li>
            <li><strong><?= t('Community-Inhalte:') ?></strong> <?= t('Kommentare, Hinweise, Likes, Follows.') ?></li>
        </ul>

        <h2>3. <?= t('Zwecke der Verarbeitung') ?></h2>
        <p>
            <?= t('Die Daten werden ausschließlich zur Bereitstellung der App-Funktionalität verarbeitet (Konto, Routenverwaltung, Community-Funktionen, Spielmechanik).') ?>
            <?= t('Es findet') ?> <strong><?= t('kein app-übergreifendes Tracking') ?></strong> <?= t('und keine Weitergabe zu Werbezwecken statt.') ?>
        </p>

        <h2>4. <?= t('Empfänger, Hosting & Drittdienste') ?></h2>
        <ul>
            <li><strong><?= t('Hosting:') ?></strong> <?= t('Unser Webhosting-Anbieter innerhalb der EU (Auftragsverarbeiter). Details bitte ergänzen.') ?></li>
            <li><strong><?= t('Push-Benachrichtigungen:') ?></strong> <?= t('Apple Push Notification service (APNs), Apple Inc.') ?></li>
            <li><strong><?= t('Strava-Import/-Export:') ?></strong> <?= t('nur sofern von dir aktiv verbunden – Strava, Inc. Beim Verbinden werden Aktivitäten importiert bzw. beim Teilen als Aktivität exportiert (inkl. Herzfrequenz, sofern aufgezeichnet).') ?></li>
            <li><strong><?= t('Web-Analyse:') ?></strong> <?= t('Google Analytics (Google Ireland Ltd.) auf unseren Webseiten – nur nach deiner Einwilligung (siehe Abschnitt 5).') ?></li>
        </ul>

        <h2>5. <?= t('Web-Analyse & Einwilligung (Cookies)') ?></h2>
        <p>
            <?= t('Auf unseren öffentlichen Webseiten setzen wir Google Analytics (GA4) zur anonymen Reichweiten-Messung ein, um das Angebot zu verbessern. Die Analyse startet erst, nachdem du im Cookie-Banner zugestimmt hast (Consent Mode v2); ohne Zustimmung werden keine Analyse-Cookies gesetzt.') ?>
            <?= t('Du kannst deine Wahl jederzeit ändern, indem du das Consent-Cookie in deinem Browser löschst – dann erscheint das Banner erneut. Rechtsgrundlage ist deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).') ?>
        </p>

        <h2>6. <?= t('Speicherdauer') ?></h2>
        <p><?= t('Kontodaten und deine Routen speichern wir bis zur Löschung deines Kontos. Nach dem Löschen einer Route oder deines Kontos werden die zugehörigen Daten innerhalb von 30 Tagen endgültig entfernt. Aggregierte, anonyme Kennzahlen (z. B. Heatmap) haben keinen Personenbezug.') ?></p>

        <h2>7. <?= t('Deine Rechte') ?></h2>
        <p>
            <?= t('Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit und Widerspruch. Dein Konto kannst du jederzeit in der App löschen („Account löschen") – dabei werden die zugehörigen personenbezogenen Daten entfernt.') ?> <?= t('Zuständige Aufsichtsbehörde ist das Bayerische Landesamt für Datenschutzaufsicht (BayLDA), Ansbach.') ?>
        </p>

        <h2>8. <?= t('Kontakt') ?></h2>
        <p><?= t('Für Datenschutzanfragen:') ?> <a href="mailto:grava@benx.de" style="color: var(--primary);">grava@benx.de</a></p>

        <p style="margin-top: 40px; text-align: center;">
            <a href="/terms" style="color: var(--primary);"><?= t('Nutzungsbedingungen') ?></a> ·
            <a href="/imprint" style="color: var(--primary);"><?= t('Impressum') ?></a>
        </p>
    </div>
</section>

<style>
.legal-content {
    max-width: 800px;
    margin: 0 auto;
    padding: 0 24px 80px;
}

.legal-container {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 48px;
}

.legal-container h2 {
    margin-top: 32px;
    margin-bottom: 16px;
    font-size: 20px;
    color: var(--text);
    font-weight: 600;
}

.legal-container h2:first-child {
    margin-top: 0;
}

.legal-container p, .legal-container li {
    line-height: 1.7;
    color: var(--text);
    font-size: 15px;
}

.legal-container ul {
    padding-left: 24px;
    margin: 16px 0;
}

.legal-container li {
    margin-bottom: 12px;
}

@media (max-width: 768px) {
    .legal-container {
        padding: 32px 24px;
    }
}
</style>
