-- Idempotenter Nachtrag zu 0067: name_de/name_en sicher ergänzen.
--
-- 0067 wurde auf PROD zwar als angewandt vermerkt, die Spalten fehlten aber:
-- das führende `SET NAMES utf8mb4;` ließ PDO::exec() nur das erste Statement
-- ausführen und das ALTER verschlucken (siehe Migrator::splitStatements, jetzt
-- Statement-für-Statement). Diese Migration prüft per information_schema und
-- legt fehlende Spalten an — no-op, wo sie bereits existieren (z. B. lokal).

SET @has_de := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_region' AND COLUMN_NAME = 'name_de');
SET @ddl_de := IF(@has_de = 0, 'ALTER TABLE game_region ADD COLUMN name_de VARCHAR(120) NULL', 'DO 0');
PREPARE s_de FROM @ddl_de;
EXECUTE s_de;
DEALLOCATE PREPARE s_de;

SET @has_en := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_region' AND COLUMN_NAME = 'name_en');
SET @ddl_en := IF(@has_en = 0, 'ALTER TABLE game_region ADD COLUMN name_en VARCHAR(120) NULL', 'DO 0');
PREPARE s_en FROM @ddl_en;
EXECUTE s_en;
DEALLOCATE PREPARE s_en;
