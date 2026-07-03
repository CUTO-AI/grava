-- Spatial-Index für die Gebiets-Zuordnung (CityConquest_Backend_Spec.md). Ein
-- b-tree über (level, min_lat, …) kann nur EINE Range-Spalte nutzen — bei 151k
-- Gemeinden mit stark variierender Größe (0,01°–4,5°) scannt „min_lat<=P" die
-- halbe Tabelle je Punkt (~0,3 s/Query → Backfill-Timeouts auf PROD). Ein
-- R-Tree über die bbox-Geometrie macht „welches Gebiet enthält den Punkt"
-- größenunabhängig schnell (MBRContains, ~0,1 ms/Query).
--
-- WICHTIG: Die Spalte braucht das explizite SRID-0-Attribut, sonst nutzt der
-- Optimizer den Spatial-Index NICHT (MySQL 8). Ein GENERATED-Spalten-Attribut
-- verträgt sich nicht mit SRID → daher normale Spalte: nullable anlegen, aus
-- min/max füllen, dann NOT NULL + Index. bbox_geom wird beim Insert gesetzt
-- (RegionRepository::insertRegion / importRowsVerbatim).

SET NAMES utf8mb4;

ALTER TABLE game_region ADD COLUMN bbox_geom GEOMETRY NULL SRID 0;

UPDATE game_region
   SET bbox_geom = ST_SRID(ST_GeomFromText(CONCAT(
     'POLYGON((',
     min_lon, ' ', min_lat, ',',
     max_lon, ' ', min_lat, ',',
     max_lon, ' ', max_lat, ',',
     min_lon, ' ', max_lat, ',',
     min_lon, ' ', min_lat, '))'
   )), 0);

ALTER TABLE game_region
  MODIFY COLUMN bbox_geom GEOMETRY NOT NULL SRID 0,
  ADD SPATIAL INDEX idx_region_geom (bbox_geom);
