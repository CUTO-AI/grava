-- Täglicher Aktivitäts-Cache (Nordstern, UserGrowth_Concept.md §4): WAR (distinct
-- aktive Fahrer) je Gebiet und Zeitfenster (7/30 Tage) inkl. Solo/Crew-Aufschlüsselung
-- und Kantenzahl. Speist die Karten-/Admin-WAR-Übersicht ohne teuren Live-Scan über
-- game_edge_pass. Befüllt vom Cron `game:region-activity-refresh` (DELETE+INSERT je
-- Fenster) — nur Gebiete MIT Aktivität bekommen eine Zeile, der Rest zählt implizit 0.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_region_activity (
  region_id    BIGINT UNSIGNED   NOT NULL,
  window_days  SMALLINT UNSIGNED NOT NULL,        -- 7 | 30
  war          INT UNSIGNED      NOT NULL DEFAULT 0,  -- distinct aktive Fahrer (Nordstern)
  solo_riders  INT UNSIGNED      NOT NULL DEFAULT 0,
  crew_riders  INT UNSIGNED      NOT NULL DEFAULT 0,
  crew_count   INT UNSIGNED      NOT NULL DEFAULT 0,
  edges        INT UNSIGNED      NOT NULL DEFAULT 0,
  computed_at  DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (region_id, window_days),
  KEY idx_gra_window_war (window_days, war),
  CONSTRAINT fk_gra_region FOREIGN KEY (region_id)
    REFERENCES game_region(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
