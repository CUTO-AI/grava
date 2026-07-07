-- Karten-Performance: gerätespezifische Obergrenze der auf der Reviere-Karte
-- tatsächlich gezeichneten Kanten (Metal-Buffer-Schutz) + Faktor für das vom
-- Server angeforderte Kanten-Limit. Server-justierbar im Admin-Dashboard
-- (/admin/game/config) über die bestehende game_config-Tabelle — KEIN App-Build
-- nötig, um die Grenzen pro iPhone-Generation nachzujustieren.
--
--   map_edge_render_caps      JSON: Marketing-Generation → max. gezeichnete Kanten.
--                             Die App bildet ihre Hardware auf die Generation ab und
--                             schickt `device_class` mit; `default` greift für Unbekanntes.
--   map_edge_fetch_multiplier Anfrage-Limit = round(render_cap × Faktor); etwas Reserve
--                             zum Priorisieren der wertvollsten Kanten.
--
-- Idempotent: bereits im Admin geänderte Werte werden NICHT überschrieben.
INSERT INTO game_config (config_key, config_value) VALUES
  ('map_edge_render_caps', '{"default":2000,"iPhone 11":1400,"iPhone 12":1600,"iPhone 13":2000,"iPhone 14":2400,"iPhone 15":3000,"iPhone 16":3500,"iPhone 17":4000}'),
  ('map_edge_fetch_multiplier', '1.25')
ON DUPLICATE KEY UPDATE config_key = config_key;
