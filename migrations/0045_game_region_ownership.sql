-- Denormalisierter Besitz-Cache je Verwaltungsgebiet (alle Ebenen) für die
-- Gebiets-Eroberung (CityConquest_Backend_Spec.md). Trägt Karten-Overlay und
-- Bestenlisten ohne Live-Aggregation über alle Kanten. Wird per Bottom-up-Rollup
-- befüllt (Blatt aus game_edge, höhere Ebenen aus den Kindern) — am Ingest nur für
-- berührte Gebiete + deren Ahnenkette, per Cron (game:region-ownership-refresh) als
-- Voll-Refresh und per Self-Heal beim Lesen. owner_claimant_id = NULL bedeutet umkämpft/
-- neutral (Kontrollschwelle nicht erreicht); leader_claimant_id hält dennoch den Führenden
-- (Dominanz-Anzeige höherer, nicht ownable Ebenen).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_region_ownership (
  region_id            BIGINT UNSIGNED NOT NULL,
  owner_claimant_id    BIGINT UNSIGNED NULL,        -- NULL = umkämpft/neutral
  leader_claimant_id   BIGINT UNSIGNED NULL,        -- Führender auch unterhalb der Schwelle
  owner_held_length_m  DOUBLE          NOT NULL DEFAULT 0,
  owner_held_edges     INT             NOT NULL DEFAULT 0,
  total_game_length_m  DOUBLE          NOT NULL DEFAULT 0,  -- alle Spielkanten des Gebiets
  total_edges          INT             NOT NULL DEFAULT 0,
  held_fraction        DOUBLE          NOT NULL DEFAULT 0,  -- owner_held_length_m / total_game_length_m
  contested            TINYINT(1)      NOT NULL DEFAULT 1,
  owner_since          DATETIME(3)     NULL,               -- seit wann dieser Claimant führt
  updated_at           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (region_id),
  KEY idx_region_owner (owner_claimant_id),
  KEY idx_region_leader (leader_claimant_id),
  CONSTRAINT fk_regown_region FOREIGN KEY (region_id)
    REFERENCES game_region(id) ON DELETE CASCADE,
  CONSTRAINT fk_regown_owner FOREIGN KEY (owner_claimant_id)
    REFERENCES game_claimant(id) ON DELETE SET NULL,
  CONSTRAINT fk_regown_leader FOREIGN KEY (leader_claimant_id)
    REFERENCES game_claimant(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
