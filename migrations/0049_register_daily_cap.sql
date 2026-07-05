-- Globales Tages-Kontingent für Neuregistrierungen (Wachstums-Drossel).
-- Server-justierbar im Admin-Dashboard (/admin/game/config) über die
-- bestehende game_config-Tabelle.
--   10 = max. 10 neue Accounts pro UTC-Kalendertag
--    0 = unbegrenzt (Drossel aus)
-- Idempotent: ein bereits im Admin geänderter Wert wird NICHT überschrieben.
INSERT INTO game_config (config_key, config_value) VALUES
  ('register_daily_max', '10')
ON DUPLICATE KEY UPDATE config_key = config_key;
