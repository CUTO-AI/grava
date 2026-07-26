-- Halbiert die gerätespezifischen Karten-Kanten-Caps (map_edge_render_caps).
-- Anlass: wiederholte Watchdog-Kills (0x8BADF00D) auf iPhone 15 Pro Max — VectorKit
-- überschritt die 50.000er-Metal-Buffer-Schwelle (51.533 Ressourcen) und brauchte
-- >5 s für den Teardown der Kanten-Polylines; iOS beendet die App dann hart.
-- Crash-Reports 19.–26.07.2026, Konsolen-Signatur "Exceeded Metal Buffer threshold".
-- Explizites VALUES() wie 0040, damit die Senkung deterministisch greift, egal ob
-- schon eine (Admin-)Zeile existiert. Feinjustierung danach im Admin:
-- /admin/game/config → map_edge_render_caps (JSON Generation→Zeichen-Grenze).

SET NAMES utf8mb4;

INSERT INTO game_config (config_key, config_value) VALUES ('map_edge_render_caps',
  '{"default":1000,"iPhone 11":700,"iPhone 12":800,"iPhone 13":1000,"iPhone 14":1200,"iPhone 15":1500,"iPhone 16":1800,"iPhone 17":2000}')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
