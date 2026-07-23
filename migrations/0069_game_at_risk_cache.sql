-- Antwort-Cache für GET /game/me/at-risk („Kanten in Gefahr"). Die Live-Ableitung
-- skaliert linear mit den gehaltenen Kanten (je Kante mehrere Queries im
-- EdgeRecalculator) und lief bei Power-Usern in den 20-s-Client-Timeout. Der
-- Endpoint berechnet deshalb EINMAL, speichert die fertige JSON-Antwort hier und
-- liefert danach aus dem Cache; der Cron `game:at-risk-refresh` erneuert abgelaufene
-- Zeilen. Pro USER (nicht Claimant), weil die Antwort durch die Privacy-Zone des
-- Anfragenden gefiltert ist — Crew-Mitglieder teilen den Claimant, nicht die Zone.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_at_risk_cache (
  user_id     BIGINT UNSIGNED NOT NULL,
  payload     MEDIUMTEXT      NOT NULL,           -- fertige JSON-Antwort des Endpoints
  computed_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  KEY idx_garc_computed (computed_at),
  CONSTRAINT fk_garc_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
