-- Pro-Entitlement-Fundament (RouteSuggestion_Concept.md §7, Phase D). Bereitet die
-- Pro-Freischaltung vor, LÄSST ABER ALLES OFFEN, bis das Flag umgelegt wird.
--
--   users.pro_until          = Ablauf der Pro-Berechtigung (NULL = kein Pro). Wird
--                              später vom Kauf-/Abo-Flow gesetzt.
--   pro_gating_enabled = '0' = Gate AUS → jede:r darf Pro-Features nutzen (Beta).
--                              '1' später = nur Pro-Nutzer (sonst HTTP 402).
--   route_suggestion_daily_limit = '0' = unbegrenzt (Drossel aus). >0 = Cap/Tag.
-- Beide server-justierbar im Admin (/admin/game/config). Idempotent.
SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN pro_until DATETIME(3) NULL DEFAULT NULL AFTER status;

INSERT INTO game_config (config_key, config_value) VALUES
  ('pro_gating_enabled',           '0'),
  ('route_suggestion_daily_limit', '0')
ON DUPLICATE KEY UPDATE config_key = config_key;
