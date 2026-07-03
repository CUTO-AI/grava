<?php
declare(strict_types=1);

namespace App\Game;

use PDO;

/**
 * PDO-Zugriff für die Gebiets-Eroberung (CityConquest_Backend_Spec.md):
 * Grenzen-Hierarchie (game_region), Kante→Gebiet-Zuordnung (game_edge.region_id)
 * und der Besitz-Cache (game_region_ownership). Reine Queries — die
 * Geometrie-/Orchestrierungslogik liegt in RegionImportService / RegionService.
 */
final class RegionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** WKT-Polygon der bbox (für die SRID-0-Spatial-Spalte bbox_geom). */
    private static function bboxWkt(float $minLon, float $minLat, float $maxLon, float $maxLat): string
    {
        return sprintf(
            'POLYGON((%1$.8f %2$.8f,%3$.8f %2$.8f,%3$.8f %4$.8f,%1$.8f %4$.8f,%1$.8f %2$.8f))',
            $minLon, $minLat, $maxLon, $maxLat
        );
    }

    // ---- Import / Hierarchie -------------------------------------------------

    /**
     * Löscht alle Gebiete + Besitz-Cache (für einen sauberen Re-Import).
     * game_edge.region_id wird durch die FK (ON DELETE SET NULL) automatisch
     * geleert.
     */
    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM game_region_ownership');
        $this->pdo->exec('DELETE FROM game_region');
    }

    /**
     * Fügt ein Gebiet ohne Elternbezug ein (parent_id/path werden im zweiten
     * Pass gesetzt) und liefert die neue id.
     *
     * @param array{osm_relation_id:?int,level:int,kind:string,name:string,country_code:?string,center_lat:float,center_lon:float,min_lat:float,min_lon:float,max_lat:float,max_lon:float,area_km2:?float,boundary_geojson:string} $r
     */
    public function insertRegion(array $r): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_region
               (osm_relation_id, level, kind, name, country_code, parent_id, path,
                center_lat, center_lon, min_lat, min_lon, max_lat, max_lon, area_km2, boundary_geojson, bbox_geom)
             VALUES
               (:osm, :level, :kind, :name, :cc, NULL, :path,
                :clat, :clon, :minlat, :minlon, :maxlat, :maxlon, :area, :geo,
                ST_SRID(ST_GeomFromText(:wkt), 0))'
        );
        // Vorläufiger Self-Path; wird im Link-Pass überschrieben, sobald die id feststeht.
        $stmt->execute([
            ':osm'    => $r['osm_relation_id'],
            ':level'  => $r['level'],
            ':kind'   => $r['kind'],
            ':name'   => $r['name'],
            ':cc'     => $r['country_code'],
            ':path'   => '/',
            ':clat'   => $r['center_lat'],
            ':clon'   => $r['center_lon'],
            ':minlat' => $r['min_lat'],
            ':minlon' => $r['min_lon'],
            ':maxlat' => $r['max_lat'],
            ':maxlon' => $r['max_lon'],
            ':area'   => $r['area_km2'],
            ':geo'    => $r['boundary_geojson'],
            ':wkt'    => self::bboxWkt((float)$r['min_lon'], (float)$r['min_lat'], (float)$r['max_lon'], (float)$r['max_lat']),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function setParent(int $id, ?int $parentId, string $path, ?string $countryCode): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE game_region SET parent_id = :pid, path = :path, country_code = COALESCE(:cc, country_code)
              WHERE id = :id'
        );
        $stmt->execute([':pid' => $parentId, ':path' => $path, ':cc' => $countryCode, ':id' => $id]);
    }

    /** Distinct vorhandene Ebenen, aufsteigend (z. B. [2,4,6,8]). @return list<int> */
    public function levelsPresent(): array
    {
        $rows = $this->pdo->query('SELECT DISTINCT level FROM game_region ORDER BY level ASC')
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    }

    /** @return list<int> ids aller Gebiete einer Ebene */
    public function idsByLevel(int $level): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM game_region WHERE level = ? ORDER BY id');
        $stmt->execute([$level]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array{id:int,level:int,center_lat:float,center_lon:float,country_code:?string,path:string}|null */
    public function coreById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, level, center_lat, center_lon, country_code, path FROM game_region WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'level' => (int)$row['level'],
            'center_lat' => (float)$row['center_lat'],
            'center_lon' => (float)$row['center_lon'],
            'country_code' => $row['country_code'] !== null ? (string)$row['country_code'] : null,
            'path' => (string)$row['path'],
        ];
    }

    /** Obergrenze der bbox-Kandidaten je Punkt (real ≤ wenige je Ebene). */
    private const CANDIDATE_LIMIT = 200;

    /**
     * Kandidaten-Gebiete einer Ebene, deren bbox den Punkt enthält (Spatial-Index
     * über bbox_geom). Liefert NUR id + Fläche (klein), kleinste Fläche zuerst —
     * die große boundary_geojson wird erst beim PiP über {@see boundaryGeojson()}
     * lazy geladen. So kann selbst ein pathologischer Massen-Match keinen Speicher
     * sprengen, und der wahrscheinlichste (kleinste) Treffer kommt zuerst.
     *
     * @return list<array{id:int,area_km2:?float}>
     */
    public function bboxCandidates(int $level, float $lat, float $lon, ?int $excludeId = null, ?float $maxSpan = null): array
    {
        // Spatial-Index (R-Tree) über bbox_geom (SRID 0): MBRContains, POINT(lon lat).
        $sql = 'SELECT id, area_km2
                  FROM game_region
                 WHERE level = :level
                   AND MBRContains(bbox_geom, ST_SRID(ST_GeomFromText(:pt), 0))';
        $params = [':level' => $level, ':pt' => sprintf('POINT(%.8f %.8f)', $lon, $lat)];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :ex';
            $params[':ex'] = $excludeId;
        }
        $sql .= ' ORDER BY area_km2 ASC LIMIT ' . self::CANDIDATE_LIMIT;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'area_km2' => $row['area_km2'] !== null ? (float)$row['area_km2'] : null,
            ];
        }
        return $out;
    }

    /** Lädt die (potenziell große) Grenzgeometrie eines Gebiets lazy (für PiP). */
    public function boundaryGeojson(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT boundary_geojson FROM game_region WHERE id = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    // ---- Backfill Kante → Gebiet --------------------------------------------

    /**
     * Liefert Kanten (Mittelpunkt) in id-Fenstern für den Backfill.
     *
     * @return list<array{id:int,mid_lat:float,mid_lon:float}>
     */
    public function edgeMidpointsAfter(int $afterId, int $limit, bool $onlyUnassigned): array
    {
        $sql = 'SELECT id,
                       (min_lat + max_lat) / 2 AS mid_lat,
                       (min_lon + max_lon) / 2 AS mid_lon
                  FROM game_edge
                 WHERE id > :after';
        if ($onlyUnassigned) {
            $sql .= ' AND region_id IS NULL';
        }
        $sql .= ' ORDER BY id ASC LIMIT :lim';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'mid_lat' => (float)$row['mid_lat'],
                'mid_lon' => (float)$row['mid_lon'],
            ];
        }
        return $out;
    }

    public function setEdgeRegion(int $edgeId, ?int $regionId): void
    {
        $stmt = $this->pdo->prepare('UPDATE game_edge SET region_id = ? WHERE id = ?');
        $stmt->execute([$regionId, $edgeId]);
    }

    public function edgeCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM game_edge')->fetchColumn();
    }

    // ---- Besitz-Rollup (game_region_ownership) ------------------------------

    /**
     * Direkte (Blatt-)Besitzsummen je Gebiet und Claimant — nur eroberte Kanten.
     *
     * @return list<array{region_id:int,claimant_id:int,len:float,edges:int}>
     */
    public function directOwnershipSums(): array
    {
        $sql = 'SELECT region_id, owner_claimant_id, SUM(length_m) AS len, COUNT(*) AS edges
                  FROM game_edge
                 WHERE region_id IS NOT NULL AND owner_claimant_id IS NOT NULL
              GROUP BY region_id, owner_claimant_id';
        $out = [];
        foreach ($this->pdo->query($sql) as $r) {
            $out[] = [
                'region_id' => (int)$r['region_id'],
                'claimant_id' => (int)$r['owner_claimant_id'],
                'len' => (float)$r['len'],
                'edges' => (int)$r['edges'],
            ];
        }
        return $out;
    }

    /**
     * Direkte (Blatt-)Gesamtsummen je Gebiet — alle Spielkanten (auch herrenlose).
     *
     * @return list<array{region_id:int,len:float,edges:int}>
     */
    public function directTotals(): array
    {
        $sql = 'SELECT region_id, SUM(length_m) AS len, COUNT(*) AS edges
                  FROM game_edge
                 WHERE region_id IS NOT NULL
              GROUP BY region_id';
        $out = [];
        foreach ($this->pdo->query($sql) as $r) {
            $out[] = [
                'region_id' => (int)$r['region_id'],
                'len' => (float)$r['len'],
                'edges' => (int)$r['edges'],
            ];
        }
        return $out;
    }

    /**
     * path + level für die gegebenen Gebiets-ids.
     *
     * @param list<int> $ids
     * @return array<int,array{path:string,level:int}>
     */
    public function metaForRegions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, path, level FROM game_region WHERE id IN ($in)");
        $stmt->execute(array_values($ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = ['path' => (string)$r['path'], 'level' => (int)$r['level']];
        }
        return $out;
    }

    /**
     * Aktueller Besitzer je Gebiet aus dem Cache (für Event-Diff und owner_since).
     *
     * @param list<int> $ids
     * @return array<int,array{owner:?int,since:?string}>
     */
    public function currentOwnersFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT region_id, owner_claimant_id, owner_since FROM game_region_ownership WHERE region_id IN ($in)"
        );
        $stmt->execute(array_values($ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['region_id']] = [
                'owner' => $r['owner_claimant_id'] !== null ? (int)$r['owner_claimant_id'] : null,
                'since' => $r['owner_since'] !== null ? (string)$r['owner_since'] : null,
            ];
        }
        return $out;
    }

    /**
     * Upsert einer Besitz-Cache-Zeile.
     *
     * @param array{region_id:int,owner_claimant_id:?int,leader_claimant_id:?int,owner_held_length_m:float,owner_held_edges:int,total_game_length_m:float,total_edges:int,held_fraction:float,contested:int,owner_since:?string} $r
     */
    public function upsertOwnership(array $r): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_region_ownership
               (region_id, owner_claimant_id, leader_claimant_id, owner_held_length_m, owner_held_edges,
                total_game_length_m, total_edges, held_fraction, contested, owner_since)
             VALUES
               (:rid, :owner, :leader, :olen, :oedges, :tlen, :tedges, :frac, :contested, :since)
             ON DUPLICATE KEY UPDATE
               owner_claimant_id = VALUES(owner_claimant_id),
               leader_claimant_id = VALUES(leader_claimant_id),
               owner_held_length_m = VALUES(owner_held_length_m),
               owner_held_edges = VALUES(owner_held_edges),
               total_game_length_m = VALUES(total_game_length_m),
               total_edges = VALUES(total_edges),
               held_fraction = VALUES(held_fraction),
               contested = VALUES(contested),
               owner_since = VALUES(owner_since)'
        );
        $stmt->execute([
            ':rid' => $r['region_id'],
            ':owner' => $r['owner_claimant_id'],
            ':leader' => $r['leader_claimant_id'],
            ':olen' => $r['owner_held_length_m'],
            ':oedges' => $r['owner_held_edges'],
            ':tlen' => $r['total_game_length_m'],
            ':tedges' => $r['total_edges'],
            ':frac' => $r['held_fraction'],
            ':contested' => $r['contested'],
            ':since' => $r['owner_since'],
        ]);
    }

    /**
     * Entfernt Cache-Zeilen für Gebiete, die nicht mehr im aktuellen Set sind
     * (z. B. wenn alle Kanten dort verschwanden).
     *
     * @param list<int> $keepIds
     */
    public function deleteOwnershipExcept(array $keepIds): void
    {
        if ($keepIds === []) {
            $this->pdo->exec('DELETE FROM game_region_ownership');
            return;
        }
        $in = implode(',', array_fill(0, count($keepIds), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM game_region_ownership WHERE region_id NOT IN ($in)");
        $stmt->execute(array_values($keepIds));
    }

    public function ownershipRowCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM game_region_ownership')->fetchColumn();
    }

    public function regionRowCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM game_region')->fetchColumn();
    }

    // ---- Prod-Sync (lokal berechnet → PROD via /internal/regions/import) -----

    /**
     * Seite der game_region-Tabelle für den Export nach PROD (id-Cursor).
     *
     * @return list<array<string,mixed>>
     */
    public function exportPage(int $afterId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, osm_relation_id, level, kind, name, country_code, parent_id, path,
                    center_lat, center_lon, min_lat, min_lon, max_lat, max_lon, area_km2, boundary_geojson
               FROM game_region
              WHERE id > :after
           ORDER BY id ASC
              LIMIT :lim'
        );
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Importiert Gebiets-Zeilen VERBATIM (inkl. id/parent_id/path — sonst brächen
     * die Hierarchie-Referenzen). Nur parametrisierte INSERTs (kein SQL aus dem
     * Body). FK-Prüfung während des Ladens aus, weil Kinder chunk-übergreifend vor
     * ihren Eltern kommen können; re-enable validiert bestehende Zeilen nicht neu.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function importRowsVerbatim(array $rows, bool $replace): int
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        if ($replace) {
            $this->pdo->exec('DELETE FROM game_region_ownership');
            $this->pdo->exec('DELETE FROM game_region');
        }
        $sql = 'INSERT INTO game_region
                  (id, osm_relation_id, level, kind, name, country_code, parent_id, path,
                   center_lat, center_lon, min_lat, min_lon, max_lat, max_lon, area_km2, boundary_geojson, bbox_geom)
                VALUES
                  (:id, :osm, :level, :kind, :name, :cc, :pid, :path,
                   :clat, :clon, :minlat, :minlon, :maxlat, :maxlon, :area, :geo,
                   ST_SRID(ST_GeomFromText(:wkt), 0))
                ON DUPLICATE KEY UPDATE
                   osm_relation_id = VALUES(osm_relation_id), level = VALUES(level), kind = VALUES(kind),
                   name = VALUES(name), country_code = VALUES(country_code), parent_id = VALUES(parent_id),
                   path = VALUES(path), center_lat = VALUES(center_lat), center_lon = VALUES(center_lon),
                   min_lat = VALUES(min_lat), min_lon = VALUES(min_lon), max_lat = VALUES(max_lat),
                   max_lon = VALUES(max_lon), area_km2 = VALUES(area_km2), boundary_geojson = VALUES(boundary_geojson),
                   bbox_geom = VALUES(bbox_geom)';
        $stmt = $this->pdo->prepare($sql);
        $n = 0;
        foreach ($rows as $r) {
            $stmt->execute([
                ':id'     => (int)$r['id'],
                ':osm'    => $r['osm_relation_id'] !== null ? (int)$r['osm_relation_id'] : null,
                ':level'  => (int)$r['level'],
                ':kind'   => (string)$r['kind'],
                ':name'   => (string)$r['name'],
                ':cc'     => $r['country_code'] !== null && $r['country_code'] !== '' ? (string)$r['country_code'] : null,
                ':pid'    => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
                ':path'   => (string)$r['path'],
                ':clat'   => (float)$r['center_lat'],
                ':clon'   => (float)$r['center_lon'],
                ':minlat' => (float)$r['min_lat'],
                ':minlon' => (float)$r['min_lon'],
                ':maxlat' => (float)$r['max_lat'],
                ':maxlon' => (float)$r['max_lon'],
                ':area'   => $r['area_km2'] !== null ? (float)$r['area_km2'] : null,
                ':geo'    => is_string($r['boundary_geojson']) ? $r['boundary_geojson'] : json_encode($r['boundary_geojson']),
                ':wkt'    => self::bboxWkt((float)$r['min_lon'], (float)$r['min_lat'], (float)$r['max_lon'], (float)$r['max_lat']),
            ]);
            $n++;
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $n;
    }

    // ---- Lesepfad (Endpunkte) -----------------------------------------------

    /**
     * Gebiete einer Ebene im bbox-Ausschnitt inkl. Besitz-Cache. `withGeometry`
     * hängt boundary_geojson an (nur bei Bedarf — kann groß sein).
     *
     * @return list<array<string,mixed>>
     */
    public function regionsInBbox(
        int $level,
        float $minLon,
        float $minLat,
        float $maxLon,
        float $maxLat,
        int $limit,
        bool $withGeometry
    ): array {
        $geoCol = $withGeometry ? ', r.boundary_geojson' : '';
        $sql = "SELECT r.id, r.level, r.kind, r.name, r.parent_id, r.center_lat, r.center_lon,
                       r.min_lat, r.min_lon, r.max_lat, r.max_lon,
                       o.owner_claimant_id, o.leader_claimant_id, o.held_fraction, o.contested, o.total_edges
                       {$geoCol}
                  FROM game_region r
             LEFT JOIN game_region_ownership o ON o.region_id = r.id
                 WHERE r.level = :level
                   AND r.min_lat <= :maxLat AND r.max_lat >= :minLat
                   AND r.min_lon <= :maxLon AND r.max_lon >= :minLon
              ORDER BY r.area_km2 DESC
                 LIMIT :lim";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':level', $level, PDO::PARAM_INT);
        $stmt->bindValue(':maxLat', $maxLat);
        $stmt->bindValue(':minLat', $minLat);
        $stmt->bindValue(':maxLon', $maxLon);
        $stmt->bindValue(':minLon', $minLon);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Volles Gebiet inkl. Besitz-Cache. @return array<string,mixed>|null */
    public function regionFull(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.level, r.kind, r.name, r.parent_id, r.path, r.center_lat, r.center_lon,
                    r.min_lat, r.min_lon, r.max_lat, r.max_lon,
                    o.owner_claimant_id, o.leader_claimant_id, o.held_fraction, o.contested,
                    o.total_game_length_m, o.total_edges, o.owner_since
               FROM game_region r
          LEFT JOIN game_region_ownership o ON o.region_id = r.id
              WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Direkte Kinder eines Gebiets inkl. Besitz. @return list<array<string,mixed>> */
    public function childrenOf(int $parentId, int $limit = 400): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.level, r.kind, r.name, r.center_lat, r.center_lon,
                    o.owner_claimant_id, o.leader_claimant_id, o.held_fraction, o.contested
               FROM game_region r
          LEFT JOIN game_region_ownership o ON o.region_id = r.id
              WHERE r.parent_id = :pid
           ORDER BY r.name ASC
              LIMIT :lim'
        );
        $stmt->bindValue(':pid', $parentId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ahnenkette (Breadcrumb) aus dem materialisierten path, ohne das Gebiet
     * selbst, aufsteigend nach Ebene.
     *
     * @return list<array{id:int,level:int,kind:string,name:string}>
     */
    public function ancestors(string $path, int $selfId): array
    {
        $ids = array_values(array_filter(array_map('intval', explode('/', trim($path, '/'))),
            static fn(int $i): bool => $i > 0 && $i !== $selfId));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, level, kind, name FROM game_region WHERE id IN ($in) ORDER BY level ASC"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['id' => (int)$r['id'], 'level' => (int)$r['level'], 'kind' => (string)$r['kind'], 'name' => (string)$r['name']];
        }
        return $out;
    }

    /**
     * Bestenliste der Claimants in einem Gebiet (Selbst + alle Nachfahren über
     * das path-Präfix, indexierbar). Nur eroberte Kanten.
     *
     * @return list<array{claimant_id:int,len:float,edges:int}>
     */
    public function leaderboardByPathPrefix(string $pathPrefix, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.owner_claimant_id AS cid, SUM(e.length_m) AS len, COUNT(*) AS edges
               FROM game_edge e
               JOIN game_region r ON r.id = e.region_id
              WHERE r.path LIKE :prefix
                AND e.owner_claimant_id IS NOT NULL
           GROUP BY e.owner_claimant_id
           ORDER BY len DESC
              LIMIT :lim'
        );
        $stmt->bindValue(':prefix', $pathPrefix . '%');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['claimant_id' => (int)$r['cid'], 'len' => (float)$r['len'], 'edges' => (int)$r['edges']];
        }
        return $out;
    }

    /**
     * Gebiete, die ein Claimant hält oder anführt (owned/contesting).
     *
     * @return list<array<string,mixed>>
     */
    public function regionsForClaimant(int $claimantId, ?int $level, int $limit = 200): array
    {
        $sql = 'SELECT r.id, r.level, r.kind, r.name, r.center_lat, r.center_lon,
                       o.owner_claimant_id, o.leader_claimant_id, o.held_fraction, o.contested
                  FROM game_region_ownership o
                  JOIN game_region r ON r.id = o.region_id
                 WHERE (o.owner_claimant_id = :cid1 OR o.leader_claimant_id = :cid2)';
        $params = [':cid1' => $claimantId, ':cid2' => $claimantId];
        if ($level !== null) {
            $sql .= ' AND r.level = :level';
            $params[':level'] = $level;
        }
        $sql .= ' ORDER BY (o.owner_claimant_id = :cid3) DESC, o.held_fraction DESC LIMIT :lim';
        $params[':cid3'] = $claimantId;
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** path eines Gebiets (für Präfix-Abfragen). */
    public function pathOf(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT path FROM game_region WHERE id = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string)$v;
    }
}
