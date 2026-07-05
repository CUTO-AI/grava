<!-- Hero Section -->
<section class="hero hero--single-column" style="padding: 80px 24px 48px;">
    <div class="hero-content">
        <h1 class="hero-title" style="font-size: 42px;"><?= t('Nutzungsbedingungen') ?></h1>
        <p class="hero-subtitle" style="color: var(--muted);">
            <?= t('Stand:') ?> <strong>4. Juli 2026</strong>
        </p>
    </div>
</section>

<!-- Content Section -->
<section class="legal-content">
    <div class="legal-container">
        <h2><?= t('1. Geltungsbereich') ?></h2>
        <p><?= t('Anbieter der App CYBERRIDE („die App") ist die benX AG, Richard-Wagner-Str. 38, 84478 Waldkraiburg. Diese Nutzungsbedingungen gelten für die Nutzung der App und der zugehörigen Online-Dienste. Die App dient der Aufzeichnung und Bewertung von Fahrrad-Touren sowie einem darauf aufbauenden Community- und Spiel-Angebot.') ?></p>

        <h2><?= t('2. Konten') ?></h2>
        <p>
            <?= t('Kernfunktionen (Aufzeichnung, Bewertung, lokale Historie) sind ohne Konto nutzbar. Für Cloud-Sync, Community und Spiel ist ein kostenloses Konto nötig. Du musst mindestens 16 Jahre alt sein, wahrheitsgemäße Angaben machen und deine Zugangsdaten geheim halten. Du bist für Aktivitäten unter deinem Konto verantwortlich.') ?>
        </p>

        <h2><?= t('3. Inhalte & Community-Regeln') ?></h2>
        <p>
            <?= t('Für von dir hochgeladene Inhalte (Routen, Fotos, Kommentare) bleibst du verantwortlich und räumst uns das einfache Recht ein, sie zur Bereitstellung der Dienste zu speichern und anzuzeigen. Verboten sind rechtswidrige, beleidigende, belästigende, gewaltverherrlichende oder anstößige Inhalte sowie Spam. Nutzer können Inhalte und Profile über die App melden und andere Nutzer blockieren. Wir prüfen Meldungen und können Inhalte entfernen oder Konten sperren.') ?>
        </p>

        <h2><?= t('4. Pflichten der Nutzer') ?></h2>
        <p><?= t('Nutze die App nicht missbräuchlich und verletze keine Rechte Dritter. Beachte im Straßenverkehr stets die Verkehrsregeln und deine eigene Sicherheit; bediene das Gerät nicht während der Fahrt.') ?></p>

        <h2><?= t('5. Haftung') ?></h2>
        <p>
            <?= t('Routen-, Belag- und Verkehrsinformationen werden ohne Gewähr bereitgestellt und ersetzen keine eigene Einschätzung der Lage vor Ort; die Nutzung erfolgt eigenverantwortlich. Wir haften unbeschränkt bei Vorsatz und grober Fahrlässigkeit sowie bei Verletzung von Leben, Körper oder Gesundheit. Bei einfacher Fahrlässigkeit haften wir nur bei Verletzung wesentlicher Vertragspflichten und begrenzt auf den vorhersehbaren, vertragstypischen Schaden.') ?>
        </p>

        <h2><?= t('6. Kündigung & Account-Löschung') ?></h2>
        <p>
            <?= t('Das Nutzungsverhältnis kann jederzeit ohne Frist beendet werden.') ?> <?= t('Du kannst dein Konto jederzeit in der App löschen.') ?>
        </p>

        <h2><?= t('7. Änderungen der Bedingungen') ?></h2>
        <p><?= t('Wir können diese Bedingungen bei berechtigtem Anlass anpassen. Über wesentliche Änderungen informieren wir dich in angemessener Weise (z. B. in der App oder per E-Mail); die weitere Nutzung gilt als Zustimmung.') ?></p>

        <h2><?= t('8. Schlussbestimmungen') ?></h2>
        <p><?= t('Es gilt das Recht der Bundesrepublik Deutschland unter Ausschluss des UN-Kaufrechts; zwingende Verbraucherschutzvorschriften bleiben unberührt. Sollte eine Bestimmung unwirksam sein, bleibt die Wirksamkeit der übrigen Bestimmungen unberührt.') ?></p>

        <p style="margin-top: 40px; text-align: center;">
            <a href="/privacy" style="color: var(--primary);"><?= t('Datenschutzerklärung') ?></a> ·
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
