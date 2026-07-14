-- Supporter-Ökonomie / km-Wertung (Supporter_Economy_Spec.md, A8). NUR Fundament:
-- Config-Flags (Default AUS) + Monats-Snapshot-Tabelle. KEINE Auszahlung — die
-- bleibt hinter supporter_payout_enabled (B3 Recht + B4 Webshop) gegated.
SET NAMES utf8mb4;

INSERT INTO game_config (config_key, config_value) VALUES
  ('supporter_program_enabled',  '0'),   -- Master: 0 = keine Wertung/Rechnung
  ('supporter_payout_enabled',   '0'),   -- Auszahlung scharf — bleibt 0 bis B3+B4
  ('supporter_km_rate_ct',       '1'),   -- ct je validiertem km (Basis)
  ('supporter_week_km_cap',      '100'), -- Pro-Fahrer-Wochen-km-Cap
  ('supporter_bonus_pot_eur',    '150'), -- Champion-Bonustopf je Landkreis/Monat
  ('supporter_min_clubs',        '3'),   -- Mindest-Vereine je Landkreis für Bonus
  ('supporter_total_budget_eur', '6000'),-- harter Gesamt-Pilotdeckel
  ('supporter_landkreise',       '')     -- teilnehmende Landkreis-Region-IDs (CSV), leer = keine
ON DUPLICATE KEY UPDATE config_key = config_key;

-- Monats-Snapshot je (Landkreis, Verein): Grundlage für Messung + spätere Auszahlung.
CREATE TABLE IF NOT EXISTS supporter_month (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  period               CHAR(7)      NOT NULL,           -- 'YYYY-MM'
  landkreis_region_id  BIGINT UNSIGNED NOT NULL,        -- game_region level 6
  crew_id              BIGINT UNSIGNED NOT NULL,
  capped_km            DECIMAL(10,2) NOT NULL DEFAULT 0,-- anrechenbare (gedeckelte) km
  basis_ct             INT UNSIGNED NOT NULL DEFAULT 0, -- Basis-Betrag in Cent
  is_champion          TINYINT(1)   NOT NULL DEFAULT 0,
  bonus_ct             INT UNSIGNED NOT NULL DEFAULT 0, -- Champion-Bonus-Anteil in Cent
  computed_at          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_supporter_month (period, landkreis_region_id, crew_id),
  KEY idx_supporter_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
