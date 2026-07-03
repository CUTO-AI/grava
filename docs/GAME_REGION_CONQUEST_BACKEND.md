# Gebiets-Eroberung (Region/City Conquest) — Backend & Deploy-Runbook

Server-Seite zu `CityConquest_Backend_Spec.md` (iOS-Repo). Mehrstufige Gebiets-
Hierarchie (OSM `admin_level` 2/4/6/8) über der kantenbasierten Revier-Mechanik:
„Welches Gebiet gehört welcher Crew oder welchem Solo-Rider?"

## Bausteine

- **Migrationen** `0043_game_region.sql` (Hierarchie: `parent_id` + materialisierter
  `path`), `0044_game_edge_region.sql` (`game_edge.region_id` = feinstes Gebiet),
  `0045_game_region_ownership.sql` (Besitz-Cache).
- **Geometrie** `src/Support/GeoPolygon.php` (Point-in-Polygon, bbox, Douglas-Peucker).
- **Import** `src/Game/RegionImportService.php` + `RegionRepository.php`: streamt
  OSM-Grenzen (GeoJSONSeq), verknüpft die Hierarchie per Center-PiP, Edge→Gebiet-Backfill.
- **Besitz** `src/Game/RegionOwnershipService.php`: Bottom-up-Rollup + Kontrollschwelle
  je Ebene → `game_region_ownership`.
- **Lesepfad** `src/Game/RegionService.php` + `Controllers/Api/RegionController.php`.
- **Config** in `GameConfig::DEFAULTS`: `region_ownable_levels`,
  `region_control_min_fraction`, `region_control_min_edges`, `region_level_span_breaks`,
  `region_list_max`.

## Endpunkte

| Methode | Pfad | Auth |
|---|---|---|
| GET | `/api/v1/game/regions?bbox=&level=&geometry=` | OptionalBearer |
| GET | `/api/v1/game/regions/{id}` | OptionalBearer |
| GET | `/api/v1/game/me/regions?level=` | Bearer |
| POST | `/internal/regions/import` | INTERNAL_TOKEN |
| GET/POST | `/internal/regions/backfill?all=1` | INTERNAL_TOKEN |
| GET/POST | `/internal/cron/region-ownership` | INTERNAL_TOKEN |

## CLI

```
php public/index.php regions:import  --file=storage/regions/boundaries.geojsonseq
php public/index.php regions:backfill --all
php public/index.php regions:ownership-refresh
php public/index.php regions:push     --base-url=https://grava.world --token=… [--chunk=2000]
scripts/import_admin_boundaries.sh [PBF] [--backfill]     # osmium-Pipeline + Import
```

## Grenzen lokal bauen (einmalig / bei OSM-Update)

Server hat kein osmium/mysql-Client — Grenzen werden **lokal** aus dem Europa-PBF
gebaut und die fertige `game_region`-Tabelle nach PROD gepusht (wie der Heatmap-Cutover).

```
scripts/import_admin_boundaries.sh docker/valhalla/custom_files/europe-latest.osm.pbf
# → osmium tags-filter (boundary=administrative) → osmium export (geojsonseq)
#   → regions:import (memory_limit 3G). Ergebnis: 156 764 Gebiete (94/592/4992/151086).
```

Zwischendateien unter `storage/regions/` (gitignored, ~4,5 GB).

## Prod-Deploy (Reihenfolge)

Backend ist **additiv** (neue Tabellen/Routen, kein Eingriff in Bestehendes) → risikoarm.

1. **Code + Migrationen deployen:** Push auf `main` → GitHub Actions (SFTP).
2. **Migrationen anwenden:** `GET https://grava.world/internal/migrate?token=$INTERNAL_TOKEN`
   (wendet 0043–0045 an).
3. **Gebiete nach PROD pushen** (lokal, gegen die Prod-URL):
   `php public/index.php regions:push --base-url=https://grava.world --token=$INTERNAL_TOKEN`
   (chunk-weise, verbatim inkl. id/parent_id/path — sonst brächen die Hierarchie-Refs).
4. **Kante→Gebiet-Backfill auf PROD:**
   `POST https://grava.world/internal/regions/backfill?all=1&token=$INTERNAL_TOKEN`
5. **Besitz rechnen:** `POST https://grava.world/internal/cron/region-ownership?token=$INTERNAL_TOKEN`
6. **Verifizieren:** `GET /api/v1/game/regions?bbox=…&level=8`, `GET /api/v1/game/regions/{id}`.

**Cron** (`region-ownership`) regelmäßig planen (externer Scheduler, da United-Domains
keinen System-Cron hat — wie beim game-snapshot). Solange kein Cron: nach jedem
größeren Ingest Schritt 5 manuell/extern anstoßen.

## Bewusst offen (spätere Phasen)

- **Ingest-Hook + Self-Heal:** aktuell hält der Cron `region-ownership` den Cache
  frisch; inkrementelles Refreshen nur der berührten Blätter+Ahnen nach jedem Ingest
  ist noch nicht verdrahtet.
- **`region_taken`/`region_lost`-Events:** `game_event.type` ist ein fixes ENUM —
  braucht eigene Migration + Notification-Pipeline. `recomputeAll()` liefert die
  Besitzwechsel (`changes`) bereits zurück.

## Tests

`tests/Unit/GeoPolygonTest.php`, `tests/Integration/Game/Region{Import,Ownership,Service,ProdSync}Test.php`.
