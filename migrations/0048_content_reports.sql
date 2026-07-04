-- Nutzer-Meldungen zu anstößigen Inhalten (App-Store-Richtlinie 1.2, UGC):
-- Kommentare, Routen und Nutzer lassen sich melden. Eine Meldung landet als
-- „open" und wird manuell gesichtet/abgearbeitet (Admin). Ein Reporter kann
-- denselben Inhalt nur einmal offen führen (UNIQUE dedupe) — erneutes Melden
-- aktualisiert Grund/Text idempotent, statt Dubletten zu erzeugen.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS content_report (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id  BIGINT UNSIGNED NOT NULL,
  content_type ENUM('comment','route','user') NOT NULL,
  content_id   BIGINT UNSIGNED NOT NULL,   -- interne id: route_comments.id / routes.id / users.id
  reason       ENUM('spam','abuse','harassment','explicit','other') NOT NULL,
  description  VARCHAR(500) NULL,
  status       ENUM('open','reviewed','resolved') NOT NULL DEFAULT 'open',
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  reviewed_at  DATETIME(3) NULL,
  reviewed_by  BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_once (reporter_id, content_type, content_id),
  KEY idx_report_status (status, created_at),
  KEY idx_report_content (content_type, content_id),
  CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
