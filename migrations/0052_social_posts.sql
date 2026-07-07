-- Social-Automatik Phase A (Twitter_Automation_Concept.md §6):
-- Aus der Tagesaktivität automatisierte X/Twitter-Meldungen erzeugen.
--
-- Zwei Tabellen (Konzept §2/E2): eine Redaktions-QUEUE mit Kandidaten
-- (Scoring/Cooldown/Dedupe) und ein LOG der tatsächlichen Sendungen.
-- Plus ein per-User Opt-in-Flag (Konzept §8/E3) für spätere
-- personenbezogene Highlights — in Phase A noch ungenutzt (Default aus),
-- aber hier angelegt, damit die iOS-Opt-in-UI andocken kann.
--
-- `channel` ist kanal-agnostisch (E7): 'twitter' zuerst, weitere Adapter
-- (mastodon/instagram/…) docken ohne Schema-Änderung an.

SET NAMES utf8mb4;

-- Personenbezogene Posts nur mit explizitem Opt-in (Konzept §8). Default 0.
ALTER TABLE users
  ADD COLUMN social_optin TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- Redaktions-Queue: ein Kandidat je Meldung. `dedupe_key` macht das
-- Einsammeln idempotent (ein Tagesbericht je Tag+Sprache+Kanal).
CREATE TABLE IF NOT EXISTS social_post_queue (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind          VARCHAR(32)     NOT NULL,                 -- 'daily_report' | 'region_taken' | 'rush_result' | …
  channel       VARCHAR(16)     NOT NULL DEFAULT 'twitter',
  lang          CHAR(2)         NOT NULL DEFAULT 'en',
  dedupe_key    VARCHAR(191)    NOT NULL,                 -- z. B. 'daily_report:2026-07-07:en:twitter'
  status        ENUM('pending','published','skipped','failed') NOT NULL DEFAULT 'pending',
  score         INT             NOT NULL DEFAULT 0,       -- Newsworthiness (Konzept §5)
  body          TEXT            NOT NULL,                 -- fertiger Post-Text (≤280)
  payload       JSON            NULL,                     -- strukturierte Rohdaten der Meldung
  scheduled_for DATETIME(3)     NULL,                     -- frühester Sendezeitpunkt (Slot-Plan)
  published_at  DATETIME(3)     NULL,
  error         VARCHAR(500)    NULL,
  created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_social_dedupe (dedupe_key),
  KEY idx_social_pending (status, channel, scheduled_for),
  KEY idx_social_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sende-Log: eine Zeile je Sendeversuch (für Audit + Doppel-Post-Schutz).
CREATE TABLE IF NOT EXISTS social_post_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_id     BIGINT UNSIGNED NULL,
  channel      VARCHAR(16)     NOT NULL DEFAULT 'twitter',
  external_id  VARCHAR(64)     NULL,                      -- Tweet-ID des Kanals
  status       ENUM('ok','error','dry_run') NOT NULL,
  response     TEXT            NULL,                      -- gekürzte API-Antwort / Fehlertext
  created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_social_log_queue (queue_id),
  KEY idx_social_log_created (created_at),
  CONSTRAINT fk_social_log_queue FOREIGN KEY (queue_id)
    REFERENCES social_post_queue(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
