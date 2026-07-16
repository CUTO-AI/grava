-- Lokalisierte Gebietsnamen für die Gebiets-Eroberung.
--
-- Bisher hielt game_region nur EINEN `name` (den lokalen OSM-Namen, also z. B.
-- kyrillisch für Russland, griechisch für Griechenland). Im Statistik-Modal der
-- App möchten wir den Namen in der Gerätesprache zeigen, sonst international.
--
-- Additiv: zwei NULL-bare Spalten für die deutsche und die englische/
-- internationale Variante. `name` bleibt als letzter Fallback (lokaler Name).
-- Werden erst beim erneuten regions:import (RegionImportService) befüllt; bis
-- dahin sind sie NULL und die API fällt per COALESCE auf `name` zurück.

SET NAMES utf8mb4;

-- Am Tabellenende anfügen (kein AFTER) → INSTANT-DDL in MySQL 8, kein Rebuild.
ALTER TABLE game_region
    ADD COLUMN name_de VARCHAR(120) NULL,
    ADD COLUMN name_en VARCHAR(120) NULL;
