-- Asynchroner Ingest: „Route ins Spiel aufnehmen" lief bisher synchron im
-- Request und map-matchte lange Fahrten in Valhalla (50-km-Chunks). Das
-- überschritt bei großen Fahrten das 60-s-Client-Timeout → die App zeigte
-- „Keine Verbindung zum Server". Jetzt legt POST /game/ingest/{route_id} nur
-- einen Job an (202) und ein Cron-Worker (game:ingest-run) verarbeitet ihn;
-- die App pollt GET /game/ingest/jobs/{id}.
--
-- UNIQUE(route_id): pro Route existiert höchstens EIN Job. Erneutes „aufnehmen"
-- setzt denselben Job auf queued zurück (idempotent, keine Doppelverarbeitung).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_ingest_jobs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_id      BIGINT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NOT NULL,
    status        ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    summary_json  MEDIUMTEXT NULL,
    error_code    VARCHAR(64) NULL,
    error_message TEXT NULL,
    attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    started_at    DATETIME(3) NULL,
    finished_at   DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_route (route_id),
    KEY idx_status (status, id),
    CONSTRAINT fk_ingest_job_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
    CONSTRAINT fk_ingest_job_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
