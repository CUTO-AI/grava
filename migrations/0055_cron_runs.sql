-- Cron-/Job-Monitoring (Admin): bisher wurde kein Cron-Lauf protokolliert — die
-- Befehle gaben nur echo aus, die crontab schickte alles nach /dev/null. Diese
-- Tabelle hält jeden (bekannten) Cron-Lauf fest: Start/Ende, Status, Dauer,
-- Output-Tail, Fehler. Aufzeichnung zentral im Wrapper Commands::run().
--
-- did_work: für die Idle-Zusammenfassung des minütlichen game:ingest-run — leere
-- Ticks werden zu EINER Heartbeat-Zeile/Tag zusammengefasst (did_work=0), echte
-- Arbeit/Fehler bekommen immer eine eigene Zeile.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cron_runs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    command       VARCHAR(64) NOT NULL,               -- kanonisch (Aliase aufgelöst)
    status        ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
    exit_code     INT NULL,
    trigger_kind  ENUM('cron','manual','internal') NOT NULL DEFAULT 'cron',
    host          VARCHAR(64) NULL,
    started_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at   DATETIME(3) NULL,
    duration_ms   INT UNSIGNED NULL,
    did_work      TINYINT(1) NOT NULL DEFAULT 1,
    output_tail   MEDIUMTEXT NULL,                    -- gekürzter Tail von stdout/stderr
    error_message TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_cmd_started (command, started_at),        -- letzter Lauf, 24h-Fenster, p95-recent-N
    KEY idx_status_started (status, started_at),       -- Stuck-Sweep
    KEY idx_started (started_at)                        -- Retention-Prune
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
