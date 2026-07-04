<?php
/**
 * „Heute im Spiel" (Pulse) — öffentliche Live-Übersicht des Spiels.
 * Gerüst mit leeren Containern; die Daten holt pulse.js same-origin von
 * GET /api/v1/pulse und rendert alle Kacheln, mit Auto-Refresh (~60 s).
 *
 * @var array<string,mixed>|null $_authedUser
 */
?>

<header class="page-header">
    <p class="cr-kicker"><?= t('Live aus dem Revierkampf') ?></p>
    <h1><?= t('Heute im Spiel') ?></h1>
    <p class="muted">
        <?= t('Wer erobert gerade welches Gebiet, welche Teams sind vorn und was passiert in Echtzeit? Diese Übersicht aktualisiert sich automatisch.') ?>
    </p>
    <p class="muted pulse-updated">
        <span id="pulse-status"><?= t('Wird geladen …') ?></span>
    </p>
</header>

<!-- #7 Tages-Kennzahlen + #3 Live jetzt unterwegs -->
<section class="pulse-tiles" id="pulse-tiles" aria-label="<?= te('Tages-Kennzahlen') ?>"></section>

<div class="pulse-grid">

    <!-- #1 Tagesbericht: neu eroberte Gebiete -->
    <section class="card pulse-card">
        <h2 class="cr-h3"><?= t('Tagesbericht · Eroberungen') ?></h2>
        <p class="muted small"><?= t('Heute neu übernommene Gebiete') ?></p>
        <div id="pulse-regions" class="pulse-list"></div>
    </section>

    <!-- #2 / #9 Team-Rangliste des Tages -->
    <section class="card pulse-card">
        <h2 class="cr-h3"><?= t('Erfolgreichste Teams heute') ?></h2>
        <p class="muted small"><?= t('Nach heute eroberten Kanten') ?></p>
        <div id="pulse-teams" class="pulse-list"></div>
    </section>

    <!-- #4 Fraktions-Kräftemessen -->
    <section class="card pulse-card">
        <h2 class="cr-h3"><?= t('Fraktions-Kräftemessen') ?></h2>
        <p class="muted small"><?= t('Anteil gehaltener Strecke gesamt') ?></p>
        <div id="pulse-factions" class="pulse-factions"></div>
    </section>

    <!-- #6 Pioniere: neu entdecktes Neuland -->
    <section class="card pulse-card">
        <h2 class="cr-h3"><?= t('Neuland-Entdecker') ?></h2>
        <p class="muted small"><?= t('Heute erstbefahrene Wege') ?></p>
        <div id="pulse-pioneers" class="pulse-list"></div>
    </section>

    <!-- #5 Neue Rekorde -->
    <section class="card pulse-card">
        <h2 class="cr-h3"><?= t('Neue Bestzeiten') ?></h2>
        <p class="muted small"><?= t('Heute gebrochene Segment-Rekorde') ?></p>
        <div id="pulse-records" class="pulse-list"></div>
    </section>

    <!-- #8 Umkämpfteste Region -->
    <section class="card pulse-card">
        <h2 class="cr-h3"><?= t('Heiß umkämpft') ?></h2>
        <p class="muted small"><?= t('Meiste Besitzwechsel heute') ?></p>
        <div id="pulse-hot" class="pulse-list"></div>
    </section>

    <!-- #10 Live-Ereignis-Feed -->
    <section class="card pulse-card pulse-card--wide">
        <h2 class="cr-h3"><?= t('Live-Ticker') ?></h2>
        <p class="muted small"><?= t('Die jüngsten Ereignisse aus dem Spiel') ?></p>
        <div id="pulse-feed" class="pulse-feed"></div>
    </section>

</div>

<p class="muted small pulse-foot">
    <?= t('Alle Angaben sind aggregiert und anonym. „Heute" bezieht sich auf den laufenden Kalendertag (UTC).') ?>
</p>
