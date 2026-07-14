-- Verifizierter Vereins-Account (UserGrowth §15.2 / CrewInvite_Onboarding_Spec §8).
-- Ein Verein wird per operator-vergebenem Aktivierungslink verifiziert: nur der
-- Vorstand (Empfänger des Tokens) kann den offiziellen, verifizierten Vereins-
-- Account beanspruchen. Status-Tier — normale Crews bleiben unberührt (Dichte).
SET NAMES utf8mb4;

-- Verifizierungs-/Vereinsfelder an der Crew (alle NULL = normale, unverifizierte Crew).
ALTER TABLE game_crew
  ADD COLUMN verified_at         DATETIME(3)  NULL DEFAULT NULL,
  ADD COLUMN verified_org_name   VARCHAR(120) NULL DEFAULT NULL,
  ADD COLUMN register_court      VARCHAR(120) NULL DEFAULT NULL,
  ADD COLUMN register_no         VARCHAR(40)  NULL DEFAULT NULL,
  ADD COLUMN is_charitable       TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN official_source_url VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN contact_email       VARCHAR(190) NULL DEFAULT NULL,
  ADD COLUMN membership_url      VARCHAR(255) NULL DEFAULT NULL;

-- Einmalige, vereins-gebundene Aktivierungs-Tokens (outbound-Einladung an den
-- Vorstand). Beim Einlösen wird die verifizierte Crew erzeugt; used_at +
-- created_crew_id verhindern Mehrfach-Einlösung.
CREATE TABLE IF NOT EXISTS game_crew_verify_token (
  token               CHAR(32)     NOT NULL PRIMARY KEY,
  display_name        VARCHAR(40)  NOT NULL,
  org_name            VARCHAR(120) NOT NULL,
  register_court      VARCHAR(120) NULL DEFAULT NULL,
  register_no         VARCHAR(40)  NULL DEFAULT NULL,
  is_charitable       TINYINT(1)   NOT NULL DEFAULT 1,
  official_source_url VARCHAR(255) NULL DEFAULT NULL,
  contact_email       VARCHAR(190) NULL DEFAULT NULL,
  membership_url      VARCHAR(255) NULL DEFAULT NULL,
  created_at          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  used_at             DATETIME(3)  NULL DEFAULT NULL,
  created_crew_id     BIGINT UNSIGNED NULL DEFAULT NULL,
  CONSTRAINT fk_verifytoken_crew FOREIGN KEY (created_crew_id) REFERENCES game_crew(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
