<?php
/**
 * WAR/Region-Auswertung (Nordstern, UserGrowth_Concept.md §4): distinct aktive
 * Fahrer je Gebiet im Fenster, mit Solo-/Crew-Aufschlüsselung. Read-only.
 *
 * @var list<array{region_id:int,name:string,level:int,kind:string,war:int,solo_riders:int,crew_count:int,edges:int}> $rows
 * @var int $days   Zeitfenster in Tagen (7|30)
 * @var int $level  Verwaltungsebene (2|4|6|8)
 * @var bool $cached  true = aus dem täglichen Cache, false = live berechnet
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$kindLabel = static function (int $level): string {
    return match ($level) {
        2 => 'Land', 4 => 'Bundesland', 6 => 'Landkreis', 8 => 'Gemeinde', default => 'Ebene ' . $level,
    };
};
$url = static fn(int $d, int $l): string => '/admin/game/regions/activity?days=' . $d . '&level=' . $l;
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players"><?= t('Spieler') ?></a>
    <a href="/admin/game/crews">Crews</a>
    <a href="/admin/game/regions">Gebiete</a>
    <a href="/admin/game/regions/activity"><strong>WAR/Region</strong></a>
    <a href="/admin/game/edge">Inspector</a>
    <a href="/admin/game/map"><?= t('Karte') ?></a>
</nav>

<section class="card">
    <h1>Game · WAR/Region</h1>
    <p class="muted">
        Nordstern-Metrik (UserGrowth_Concept.md §4): <strong>wöchentlich aktive Fahrer je Gebiet</strong>.
        „Aktiv" = mindestens eine gewertete Kantenbefahrung im Zeitraum. Solo = Fahrer ohne Crew,
        Crews = Anzahl aktiver Crews.
    </p>

    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin:.75rem 0">
        <div>
            <span class="muted">Zeitraum:</span>
            <a href="<?= $e($url(7, $level)) ?>"<?= $days === 7 ? ' style="font-weight:700"' : '' ?>>7 Tage</a> ·
            <a href="<?= $e($url(30, $level)) ?>"<?= $days === 30 ? ' style="font-weight:700"' : '' ?>>30 Tage</a>
        </div>
        <div>
            <span class="muted">Ebene:</span>
            <?php foreach ([2 => 'Land', 4 => 'Bundesland', 6 => 'Landkreis', 8 => 'Gemeinde'] as $lv => $lbl): ?>
                <a href="<?= $e($url($days, $lv)) ?>"<?= $level === $lv ? ' style="font-weight:700"' : '' ?>><?= $e($lbl) ?></a><?= $lv !== 8 ? ' · ' : '' ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <p class="muted">Keine Aktivität im gewählten Zeitraum/auf dieser Ebene.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th><?= $e($kindLabel($level)) ?></th>
                <th class="num">WAR</th>
                <th class="num">Solo</th>
                <th class="num">Crews</th>
                <th class="num">Kanten</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><a href="/gebiete/<?= (int)$r['region_id'] ?>"><?= $e($r['name']) ?></a></td>
                <td class="num"><strong><?= (int)$r['war'] ?></strong></td>
                <td class="num"><?= (int)$r['solo_riders'] ?></td>
                <td class="num"><?= (int)$r['crew_count'] ?></td>
                <td class="num"><?= (int)$r['edges'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted" style="margin-top:.5rem">
        Top <?= count($rows) ?> nach WAR (max. 200).
        <?= ($cached ?? false) ? 'Quelle: täglicher Cache.' : 'Quelle: live berechnet (Cache noch nicht gefüllt).' ?>
    </p>
    <?php endif; ?>
</section>
