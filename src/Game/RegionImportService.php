<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\GeoPolygon;

/**
 * Lädt die OSM-Verwaltungsgrenzen (aus `osmium export … -f geojsonseq`) in die
 * game_region-Hierarchie und trägt den Edge→Gebiet-Backfill (CityConquest_Backend_Spec.md,
 * Phase A). Reihenfolge: (1) alle Gebiete der Zielebenen einfügen, (2) Hierarchie
 * verknüpfen (parent_id/path/country_code per Center-Point-in-Polygon gegen die
 * nächsthöhere Ebene), (3) optional Kanten ihrem feinsten Gebiet zuordnen.
 *
 * Der GeoJSONSeq-Stream wird zeilenweise gelesen (ein Feature je Zeile, ggf. mit
 * RS-Präfix 0x1E) — konstanter Speicher auch bei europaweiten Daten.
 */
final class RegionImportService
{
    /** Ziel-Ebenen und ihr menschenlesbarer Typ. */
    private const KIND = [2 => 'country', 4 => 'state', 6 => 'county', 8 => 'municipality'];

    /** Simplify-Toleranz je Ebene (Grad) — grobe Ebenen stärker vereinfacht. */
    private const SIMPLIFY_TOL = [2 => 0.01, 4 => 0.005, 6 => 0.002, 8 => 0.0005];

    /** Obergrenze dekodierter Eltern-Geometrien im Cache (Speicherschutz). */
    private const MAX_GEOM_CACHE = 8000;

    /** @var array<int,array<string,mixed>|null> Decode-Cache für Grenz-Geometrien (id → decoded|null). */
    private array $geomCache = [];

    public function __construct(private readonly RegionRepository $repo) {}

    /**
     * Import aus einer GeoJSONSeq-Datei. Mit `$replace=true` (Default) wird der
     * Bestand vorab geleert (sauberer Voll-Import); mit `$replace=false` werden die
     * Gebiete ANGEHÄNGT — nötig, um einen weiteren Kontinent (z. B. USA) zu EU
     * hinzuzufügen, ohne EU zu verlieren. `linkHierarchy` läuft danach ortsbasiert
     * über den gesamten Bestand und verknüpft die neuen Gebiete korrekt.
     *
     * @param list<int> $levels  z. B. [2,4,6,8]
     * @param callable(string):void|null $log
     * @return array{inserted:array<int,int>,linked:int}
     */
    public function importFromGeojsonSeq(string $path, array $levels, ?callable $log = null, bool $replace = true): array
    {
        $log ??= static function (string $_): void {};
        if (!is_readable($path)) {
            throw new \RuntimeException("GeoJSONSeq nicht lesbar: {$path}");
        }
        $want = array_fill_keys($levels, true);

        if ($replace) {
            $log("Lösche vorhandene Gebiete …");
            $this->repo->deleteAll();
        } else {
            $log("Append-Modus: bestehende Gebiete bleiben erhalten.");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Kann {$path} nicht öffnen.");
        }

        $inserted = array_fill_keys($levels, 0);
        $seen = 0;
        while (($line = fgets($handle)) !== false) {
            $line = ltrim($line, "\x1e \t\r\n");
            if ($line === '') {
                continue;
            }
            $feature = json_decode($line, true);
            if (!is_array($feature)) {
                continue;
            }
            $props = $feature['properties'] ?? [];
            $geometry = $feature['geometry'] ?? null;
            if (!is_array($props) || !is_array($geometry)) {
                continue;
            }
            if (($props['boundary'] ?? null) !== 'administrative') {
                continue;
            }
            $level = isset($props['admin_level']) && is_numeric($props['admin_level'])
                ? (int)$props['admin_level'] : null;
            if ($level === null || !isset($want[$level])) {
                continue;
            }
            $type = $geometry['type'] ?? null;
            if ($type !== 'Polygon' && $type !== 'MultiPolygon') {
                continue;
            }
            $name = $this->pickName($props);
            if ($name === null) {
                continue;
            }

            $bbox = GeoPolygon::bbox($geometry);
            if ($bbox === null) {
                continue;
            }
            $center = GeoPolygon::representativePoint($geometry) ?? [
                'lat' => ($bbox['minLat'] + $bbox['maxLat']) / 2,
                'lon' => ($bbox['minLon'] + $bbox['maxLon']) / 2,
            ];
            $simplified = GeoPolygon::simplify($geometry, self::SIMPLIFY_TOL[$level] ?? 0.001);

            $this->repo->insertRegion([
                'osm_relation_id'  => $this->osmRelationId($props, $feature),
                'level'            => $level,
                'kind'             => self::KIND[$level] ?? ('level' . $level),
                'name'             => mb_substr($name, 0, 120),
                'country_code'     => $this->pickCountryCode($props),
                'center_lat'       => $center['lat'],
                'center_lon'       => $center['lon'],
                'min_lat'          => $bbox['minLat'],
                'min_lon'          => $bbox['minLon'],
                'max_lat'          => $bbox['maxLat'],
                'max_lon'          => $bbox['maxLon'],
                'area_km2'         => $this->approxAreaKm2($bbox),
                'boundary_geojson' => json_encode($simplified, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $inserted[$level]++;
            if ((++$seen % 5000) === 0) {
                $log("… {$seen} Gebiete eingelesen");
            }
        }
        fclose($handle);
        foreach ($inserted as $lvl => $cnt) {
            $log("Ebene {$lvl}: {$cnt} Gebiete");
        }

        $linked = $this->linkHierarchy($log);
        return ['inserted' => $inserted, 'linked' => $linked];
    }

    /**
     * Zweiter Pass: parent_id/path/country_code je Gebiet setzen. Ebenen
     * aufsteigend, damit der Elternpfad schon steht, wenn Kinder verknüpft werden.
     *
     * @param callable(string):void $log
     */
    public function linkHierarchy(callable $log): int
    {
        $levels = $this->repo->levelsPresent();     // aufsteigend
        $linked = 0;
        foreach ($levels as $level) {
            $higher = array_values(array_filter($levels, static fn(int $l): bool => $l < $level));
            foreach ($this->repo->idsByLevel($level) as $id) {
                $self = $this->repo->coreById($id);
                if ($self === null) {
                    continue;
                }
                $parentId = null;
                $parentCore = null;
                // Nächsthöhere Ebene zuerst (6 vor 4 vor 2), mit Insel-bbox-Fallback.
                foreach (array_reverse($higher) as $plevel) {
                    $parentId = $this->resolveContaining($plevel, $self['center_lat'], $self['center_lon'], $id, true);
                    if ($parentId !== null) {
                        $parentCore = $this->repo->coreById($parentId);
                        break;
                    }
                }
                if ($parentId !== null && $parentCore !== null) {
                    $path = rtrim($parentCore['path'], '/') . '/' . $id . '/';
                    $cc = $self['country_code'] ?? $parentCore['country_code'];
                    $this->repo->setParent($id, $parentId, $path, $cc);
                } else {
                    // Wurzel (höchste Ebene / kein Treffer).
                    $this->repo->setParent($id, null, '/' . $id . '/', $self['country_code']);
                }
                $linked++;
            }
            $log("Hierarchie Ebene {$level} verknüpft");
        }
        return $linked;
    }

    /**
     * Gezielte Neu-Verknüpfung fehl-/zu-hoch verketteter Gebiete (v. a. Inseln),
     * OHNE Neu-Import der Geometrie. Nutzt den Insel-bbox-Fallback. Ebene
     * aufsteigend (erst Provinzen, dann Gemeinden → nutzen ggf. den frisch
     * korrigierten Provinz-Pfad).
     *
     * @param callable(string):void|null $log
     * @return array{checked:int,relinked:int}
     */
    public function relinkOrphans(?callable $log = null): array
    {
        $log ??= static function (string $_): void {};
        $levels = $this->repo->levelsPresent();
        $orphans = $this->repo->regionsWithSkippedParent();
        $checked = 0;
        $relinked = 0;
        foreach ($orphans as $o) {
            $checked++;
            $higher = array_values(array_filter($levels, static fn(int $l): bool => $l < $o['level']));
            $parentId = null;
            $parentCore = null;
            foreach (array_reverse($higher) as $plevel) {
                $cand = $this->resolveContaining($plevel, $o['center_lat'], $o['center_lon'], $o['id'], true);
                if ($cand === null) {
                    continue;
                }
                $core = $this->repo->coreById($cand);
                // Ländercode-Sicherung: der bbox-Fallback könnte bei Küsten-Boxen
                // eine ausländische Provinz treffen — nur akzeptieren, wenn das Land
                // passt (oder eines von beiden unbekannt ist).
                if ($core !== null
                    && !empty($o['country_code']) && !empty($core['country_code'])
                    && $o['country_code'] !== $core['country_code']) {
                    continue;
                }
                $parentId = $cand;
                $parentCore = $core;
                break;
            }
            if ($parentId !== null && $parentCore !== null) {
                $path = rtrim($parentCore['path'], '/') . '/' . $o['id'] . '/';
                $cc = $o['country_code'] ?? $parentCore['country_code'];
                $this->repo->setParent($o['id'], $parentId, $path, $cc);
                $relinked++;
            }
            if ($checked % 200 === 0) {
                $log("… {$checked} geprüft, {$relinked} neu verknüpft");
            }
        }
        return ['checked' => $checked, 'relinked' => $relinked];
    }

    /**
     * Korrigiert L4-Gebiete, deren Center per STRIKTEM Punkt-in-Polygon in einem
     * anderen Land liegt als der zugewiesene Elter — die grenzüberschreitende
     * Fehlverknüpfung, die der bbox-Fallback in {@see linkHierarchy()} erzeugt
     * (Nachbarland-bbox greift den Grenz-/Küstenpunkt, cc wird vom falschen Elter
     * geerbt). Verschiebt den ganzen Teilbaum ans echte Land (path/cc-Rewrite) und
     * dedupliziert Namensdubletten (gleicher Name+Elter). Politisch umstrittene
     * Fälle (echtes Land in $skipTrueCc, Default RU) bleiben unangetastet.
     * Idempotent: ein zweiter Lauf findet nichts mehr.
     *
     * @param list<string> $skipTrueCc
     * @return array{reparented:list<array<string,mixed>>,skipped:list<array<string,mixed>>,dedup:list<array<string,mixed>>,dedupSkipped:list<array<string,mixed>>}
     */
    public function recorrectMisparented(bool $apply, ?callable $log = null, array $skipTrueCc = ['RU']): array
    {
        $log ??= static function (string $_): void {};
        $skip = array_fill_keys($skipTrueCc, true);
        $report = ['reparented' => [], 'skipped' => [], 'dedup' => [], 'dedupSkipped' => []];

        // 1) Re-Parent auf Ebene 4 (die gemeldete Fehlerebene).
        foreach ($this->repo->regionsAtLevel(4) as $r) {
            $trueId = $this->resolveContaining(2, $r['center_lat'], $r['center_lon'], $r['id'], false);
            if ($trueId === null || $trueId === $r['parent_id']) {
                continue;
            }
            $trueCore = $this->repo->coreById($trueId);
            if ($trueCore === null) {
                continue;
            }
            $trueCc = $trueCore['country_code'];
            $entry = [
                'id' => $r['id'], 'name' => $r['name'], 'from' => $r['parent_id'],
                'to' => $trueId, 'true_cc' => $trueCc,
                'descendants' => $this->repo->descendantCount($r['path'], $r['id']),
            ];
            if ($trueCc !== null && isset($skip[$trueCc])) {
                $report['skipped'][] = $entry;
                continue;
            }
            $newPrefix = rtrim($trueCore['path'], '/') . '/' . $r['id'] . '/';
            if ($apply) {
                $this->repo->reparentSubtree($r['id'], $trueId, $r['path'], $newPrefix, $trueCc);
            }
            $report['reparented'][] = $entry;
            $log(sprintf('re-parent %s #%d: %s → %s (%s), %d Nachfahren',
                $r['name'], $r['id'], $r['parent_id'] ?? 'NULL', (string)$trueId, $trueCc ?? '-', $entry['descendants']));
        }

        // 2) Dedup Namensdubletten auf Ebene 4.
        foreach ($this->repo->duplicateSiblingsAtLevel(4) as $g) {
            $disputed = false;
            $stats = [];
            foreach ($g['ids'] as $id) {
                $core = $this->repo->coreById($id);
                if ($core === null) {
                    continue;
                }
                $trueId = $this->resolveContaining(2, $core['center_lat'], $core['center_lon'], $id, false);
                $trueCore = $trueId !== null ? $this->repo->coreById($trueId) : null;
                if ($trueCore !== null && $trueCore['country_code'] !== null && isset($skip[$trueCore['country_code']])) {
                    $disputed = true;
                }
                $stats[$id] = [
                    'edges' => $this->repo->treeEdgeCount($core['path']),
                    'desc'  => $this->repo->descendantCount($core['path'], $id),
                ];
            }
            if ($disputed) {
                $report['dedupSkipped'][] = ['name' => $g['name'], 'ids' => $g['ids'], 'reason' => 'disputed'];
                continue;
            }
            // Keeper = meiste Kanten (Tie → kleinste id). Verlierer nur löschen,
            // wenn leer (0 Kanten & 0 Nachfahren) — sonst manuell prüfen.
            $ids = $g['ids'];
            usort($ids, static fn(int $a, int $b): int =>
                (($stats[$b]['edges'] ?? 0) <=> ($stats[$a]['edges'] ?? 0)) ?: ($a <=> $b));
            $keeper = $ids[0];
            foreach (array_slice($ids, 1) as $loser) {
                if (($stats[$loser]['edges'] ?? 0) === 0 && ($stats[$loser]['desc'] ?? 0) === 0) {
                    if ($apply) {
                        $this->repo->deleteRegion($loser);
                    }
                    $report['dedup'][] = ['name' => $g['name'], 'keeper' => $keeper, 'deleted' => $loser];
                    $log(sprintf('dedup %s: behalte #%d, lösche #%d', $g['name'], $keeper, $loser));
                } else {
                    $report['dedupSkipped'][] = ['name' => $g['name'], 'ids' => $g['ids'], 'reason' => 'loser_has_data'];
                }
            }
        }

        return $report;
    }

    /**
     * feinstes Gebiet, das den Punkt enthält — Ebenen von fein nach grob
     * (8→6→4→2). Kleinste Fläche gewinnt bei Overlaps. Für Ingest & Backfill.
     */
    public function resolveLeafRegionId(float $lat, float $lon, array $levelsDesc): ?int
    {
        foreach ($levelsDesc as $level) {
            $hit = $this->resolveContaining($level, $lat, $lon, null);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * Enthaltendes Gebiet einer bestimmten Ebene (kleinste Fläche gewinnt).
     *
     * `$bboxFallback`: findet KEIN Polygon (PiP) den Punkt, aber es gibt bbox-
     * Kandidaten, wird der flächenkleinste bbox-Kandidat zurückgegeben. Nötig für
     * die Eltern-Verknüpfung von INSELN: die vereinfachten Provinz-/Regions-
     * Polygone verlieren mitunter den Insel-Teil, sodass ein exakter PiP scheitert
     * und die Zuordnung sonst eine Ebene zu hoch springt (Comune → direkt Land).
     * Für die Kanten-Zuordnung bleibt der Fallback AUS (strikter PiP).
     */
    private function resolveContaining(int $level, float $lat, float $lon, ?int $excludeId, bool $bboxFallback = false): ?int
    {
        if (count($this->geomCache) > self::MAX_GEOM_CACHE) {
            $this->geomCache = [];
        }
        // Kandidaten kommen kleinste-Fläche-zuerst (Repo ORDER BY area ASC) und
        // OHNE Geometrie — die wird hier lazy je Kandidat geladen. Der erste
        // PiP-Treffer ist damit automatisch das feinste enthaltende Gebiet.
        $candidates = $this->repo->bboxCandidates($level, $lat, $lon, $excludeId);
        foreach ($candidates as $c) {
            $id = $c['id'];
            if (!array_key_exists($id, $this->geomCache)) {
                $raw = $this->repo->boundaryGeojson($id);
                $decoded = $raw !== null ? json_decode($raw, true) : null;
                $this->geomCache[$id] = is_array($decoded) ? $decoded : null;
            }
            $geom = $this->geomCache[$id];
            if ($geom !== null && GeoPolygon::contains($lat, $lon, $geom)) {
                return $id;
            }
        }
        // Insel-Fallback: kein exakter Treffer → flächenkleinster bbox-Kandidat.
        return $bboxFallback ? ($candidates[0]['id'] ?? null) : null;
    }

    /**
     * Backfill: ordnet Kanten ihr feinstes Gebiet zu. Beschränkbar (maxCount) und
     * fortsetzbar (startAfter → last_id) — für den PROD-Lauf über die
     * Internal-HTTP-Route (Zeit-/Speicherlimit je Request), von außen geschleift.
     *
     * @param callable(string):void|null $log
     * @return array{scanned:int,assigned:int,last_id:int,done:bool}
     */
    public function backfillEdges(
        bool $onlyUnassigned = true,
        int $batch = 1000,
        ?callable $log = null,
        ?int $maxCount = null,
        int $startAfter = 0
    ): array {
        $log ??= static function (string $_): void {};
        $levelsDesc = array_reverse($this->repo->levelsPresent());   // fein → grob
        if ($levelsDesc === []) {
            throw new \RuntimeException('Keine Gebiete geladen — erst importieren.');
        }
        // Bei maxCount (fortsetzbarer Lauf) über den globalen id-Cursor gehen
        // (nicht onlyUnassigned), damit der last_id-Cursor deterministisch
        // vorrückt und der Aufrufer sauber weiterblättern kann.
        $cursorMode = $maxCount !== null;
        $after = $startAfter;
        $scanned = 0;
        $assigned = 0;
        $done = false;
        while (true) {
            $take = $cursorMode ? min($batch, $maxCount - $scanned) : $batch;
            if ($take <= 0) {
                break;
            }
            $rows = $this->repo->edgeMidpointsAfter($after, $take, $cursorMode ? false : $onlyUnassigned);
            if ($rows === []) {
                $done = true;
                break;
            }
            foreach ($rows as $e) {
                $rid = $this->resolveLeafRegionId($e['mid_lat'], $e['mid_lon'], $levelsDesc);
                $this->repo->setEdgeRegion($e['id'], $rid);
                if ($rid !== null) {
                    $assigned++;
                }
                $scanned++;
                $after = $e['id'];
            }
            $log("… {$scanned} Kanten geprüft, {$assigned} zugeordnet");
            if ($cursorMode && $scanned >= $maxCount) {
                break;
            }
        }
        return ['scanned' => $scanned, 'assigned' => $assigned, 'last_id' => $after, 'done' => $done];
    }

    /**
     * Seite der game_region-Tabelle (id-Cursor) für den Prod-Push.
     *
     * @return list<array<string,mixed>>
     */
    public function exportPage(int $afterId, int $limit): array
    {
        return $this->repo->exportPage($afterId, $limit);
    }

    public function regionCount(): int
    {
        return $this->repo->regionRowCount();
    }

    /**
     * Import einer Chunk-Payload {replace:bool, rows:[…]} vom Prod-Sync-Endpunkt
     * (/internal/regions/import). Verbatim inkl. id/parent_id/path.
     *
     * @return array{received:int,imported:int,replace:bool}
     */
    public function importRowsJson(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
            throw new \InvalidArgumentException('Erwartet {replace, rows:[…]}.');
        }
        $replace = (bool)($data['replace'] ?? false);
        $imported = $this->repo->importRowsVerbatim($data['rows'], $replace);
        return ['received' => count($data['rows']), 'imported' => $imported, 'replace' => $replace];
    }

    // ---- Tag-Helfer ----------------------------------------------------------

    /** @param array<string,mixed> $props */
    private function pickName(array $props): ?string
    {
        foreach (['name', 'name:de', 'official_name', 'name:en'] as $k) {
            $v = $props[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $props */
    private function pickCountryCode(array $props): ?string
    {
        foreach (['ISO3166-1:alpha2', 'ISO3166-1', 'country_code_iso3166_1_alpha_2'] as $k) {
            $v = $props[$k] ?? null;
            if (is_string($v) && strlen(trim($v)) === 2) {
                return strtoupper(trim($v));
            }
        }
        return null;
    }

    /**
     * OSM-Relations-id: osmium export legt sie je nach Version unter
     * properties.@id / id / properties.osm_id ab (mit 'r'-Präfix bei type_id).
     *
     * @param array<string,mixed> $props
     * @param array<string,mixed> $feature
     */
    private function osmRelationId(array $props, array $feature): ?int
    {
        foreach ([$props['@id'] ?? null, $feature['id'] ?? null, $props['osm_id'] ?? null, $props['id'] ?? null] as $raw) {
            if ($raw === null) {
                continue;
            }
            $s = (string)$raw;
            if (preg_match('/(\d+)/', $s, $m)) {
                return (int)$m[1];
            }
        }
        return null;
    }

    /** Grobe Flächenschätzung aus der bbox (km²) — reicht fürs „kleinste gewinnt". */
    private function approxAreaKm2(array $bbox): float
    {
        $dLat = $bbox['maxLat'] - $bbox['minLat'];
        $dLon = $bbox['maxLon'] - $bbox['minLon'];
        $midLat = deg2rad(($bbox['minLat'] + $bbox['maxLat']) / 2);
        $kmPerDegLat = 110.574;
        $kmPerDegLon = 111.320 * cos($midLat);
        return abs($dLat * $kmPerDegLat) * abs($dLon * $kmPerDegLon);
    }
}
