-- Vereins-CRM / Outreach (CrewInvite_Onboarding_Spec §8.3): lebende Datenhaltung
-- der Vereins-Zielliste fürs Beachhead-Seeding (UserGrowth §15). Eingabemaske +
-- Batch-Import + Funnel-Tracking. Reine Backoffice-Tabelle (kein Spielbezug).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS club_prospect (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(120) NOT NULL,
  landkreis           VARCHAR(80)  NULL DEFAULT NULL,
  discipline          VARCHAR(40)  NULL DEFAULT NULL,   -- gravel/rennrad/mtb/triathlon/…
  contact_email       VARCHAR(190) NULL DEFAULT NULL,
  official_source_url VARCHAR(255) NULL DEFAULT NULL,   -- Impressum-Quelle
  register_court      VARCHAR(120) NULL DEFAULT NULL,
  register_no         VARCHAR(40)  NULL DEFAULT NULL,
  is_charitable       TINYINT(1)   NOT NULL DEFAULT 0,
  -- Funnel: prospect → invited → delivered → email_opened → link_opened → activated → playing
  status              ENUM('prospect','invited','delivered','email_opened','link_opened','activated','playing','declined')
                      NOT NULL DEFAULT 'prospect',
  assigned_to         VARCHAR(80)  NULL DEFAULT NULL,   -- Team-Zuständige:r (frei)
  notes               TEXT         NULL DEFAULT NULL,
  invite_token        CHAR(32)     NULL DEFAULT NULL,   -- gesetztes Vereins-Aktivierungs-Token
  crew_id             BIGINT UNSIGNED NULL DEFAULT NULL,-- verknüpfte (aktivierte) Crew
  -- Dedup-Schlüssel für den Batch-Import (normalisierter Name + Landkreis)
  dedup_key           VARCHAR(160) NOT NULL,
  invited_at          DATETIME(3) NULL DEFAULT NULL,
  delivered_at        DATETIME(3) NULL DEFAULT NULL,
  email_opened_at     DATETIME(3) NULL DEFAULT NULL,
  link_opened_at      DATETIME(3) NULL DEFAULT NULL,
  activated_at        DATETIME(3) NULL DEFAULT NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_prospect_dedup (dedup_key),
  KEY idx_prospect_status (status),
  KEY idx_prospect_landkreis (landkreis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
