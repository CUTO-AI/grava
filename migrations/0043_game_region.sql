-- Verwaltungsgebiete-Hierarchie für die Gebiets-Eroberung (City/Territory Conquest,
-- CityConquest_Backend_Spec.md). Mehrstufig über OSM admin_level (2 Land / 4 Bundesland /
-- 6 Landkreis / 8 Gemeinde), europaweit aus europe-latest.osm.pbf geladen. Additiv, eigene
-- Tabelle — verändert keine bestehende. parent_id + materialisierter path bilden die
-- Hierarchie ab (Navigation nach oben, Subtree-Abfragen für Backfill/Rollup). Grenzen als
-- vereinfachtes (Multi)Polygon in boundary_geojson, dient Client-Rendering UND dem
-- serverseitigen Point-in-Polygon der Kanten-Zuordnung.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_region (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  osm_relation_id  BIGINT          NULL,            -- OSM boundary-Relation (Herkunft/Update)
  level            TINYINT         NOT NULL,        -- OSM admin_level (2/4/6/8)
  kind             VARCHAR(16)     NOT NULL,        -- 'country'|'state'|'county'|'municipality'
  name             VARCHAR(120)    NOT NULL,
  country_code     CHAR(2)         NULL,            -- ISO-3166-1 (DE/AT/CH/FR/IT …)
  parent_id        BIGINT UNSIGNED NULL,            -- nächsthöheres Gebiet
  path             VARCHAR(255)    NOT NULL,        -- materialisiert: '/1/17/230/4711/' (Ahnen-IDs)
  center_lat       DOUBLE          NOT NULL,        -- Label-/Kamera-Anker (repräsentativer Punkt)
  center_lon       DOUBLE          NOT NULL,
  min_lat          DOUBLE          NOT NULL,        -- bbox für schnelle Queries + PiP-Vorfilter
  min_lon          DOUBLE          NOT NULL,
  max_lat          DOUBLE          NOT NULL,
  max_lon          DOUBLE          NOT NULL,
  area_km2         DOUBLE          NULL,
  boundary_geojson JSON            NOT NULL,        -- (Multi)Polygon, je Ebene passend vereinfacht
  created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_region_osm (osm_relation_id),
  KEY idx_region_level_bbox (level, min_lat, max_lat, min_lon, max_lon),
  KEY idx_region_parent (parent_id),
  KEY idx_region_path (path),
  KEY idx_region_name (name),
  CONSTRAINT fk_region_parent FOREIGN KEY (parent_id)
    REFERENCES game_region(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
