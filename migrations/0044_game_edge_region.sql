-- Kante -> feinstes Verwaltungsgebiet (Blatt, i. d. R. Gemeinde/level=8) für die
-- Gebiets-Eroberung (CityConquest_Backend_Spec.md). Nur EINE Spalte auf game_edge:
-- höhere Ebenen (Landkreis/Bundesland/Land) ergeben sich per Bottom-up-Rollup entlang
-- game_region.parent_id. Gesetzt beim Ingest (neue Kanten) sowie einmalig per Backfill.
-- NULL = Kante liegt in keinem geladenen Gebiet (Umland) -> zählt für kein Gebiet, bleibt
-- aber normal bespielbar. Der zusammengesetzte Index trägt das Besitz-GROUP-BY je Gebiet.

SET NAMES utf8mb4;

ALTER TABLE game_edge
  ADD COLUMN region_id BIGINT UNSIGNED NULL AFTER owner_claimant_id,
  ADD KEY idx_edge_region_owner (region_id, owner_claimant_id),
  ADD CONSTRAINT fk_edge_region FOREIGN KEY (region_id)
    REFERENCES game_region(id) ON DELETE SET NULL;
