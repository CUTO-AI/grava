-- Broadcast-Push (GameAdmin_Concept.md Phase 2): admin-erstellte Push-Mitteilungen
-- an ein Nutzersegment. Entwurf → queued → (Cron-Worker game:broadcast-run) sendet
-- in Batches via APNs → sent. Kein synchroner Massenversand im Web-Request.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_broadcasts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by  BIGINT UNSIGNED NULL,
    title       VARCHAR(120) NOT NULL,
    body        VARCHAR(300) NOT NULL,
    deeplink    VARCHAR(200) NULL,
    segment     VARCHAR(32) NOT NULL DEFAULT 'all',   -- all | active_7d | active_30d
    status      ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    recipients  INT UNSIGNED NULL,
    sent        INT UNSIGNED NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    sent_at     DATETIME(3) NULL,
    PRIMARY KEY (id),
    KEY idx_bc_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
