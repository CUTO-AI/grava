-- Wahoo-Integration Phase A — Fundament (Wahoo_Integration_Concept.md).
--
-- Wahoo wird als zweite externe Datenquelle WIE Strava angebunden (Import-only).
-- Die OAuth-Infrastruktur ist provider-generisch: wir erweitern nur die
-- provider-ENUMs um 'wahoo' und legen die Webhook-Event-Tabelle an.
--
-- provider_user_id trägt hier die Wahoo-User-ID (eindeutig je Provider, verhindert
-- dass zwei App-Accounts denselben Wahoo-Account verbinden — analog Strava-Athlete).

ALTER TABLE oauth_connections
    MODIFY COLUMN provider ENUM('strava','wahoo') NOT NULL;

ALTER TABLE oauth_states
    MODIFY COLUMN provider ENUM('strava','wahoo') NOT NULL;

-- wahoo_webhook_events: Wahoo meldet neue Fahrten per Webhook (workout_summary).
-- Der Endpunkt schreibt nur (schnell, HTTP 200 zurück) — ein Cron verarbeitet
-- asynchron. Idempotenz über wahoo_workout_id (UNIQUE): Doppel-Zustellungen und
-- Kollisionen mit dem manuellen Pull-Import werden zu No-Ops.
CREATE TABLE wahoo_webhook_events (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    wahoo_workout_id VARCHAR(64)     NOT NULL,
    user_id          BIGINT UNSIGNED NULL,
    status           ENUM('pending','done','skipped','failed') NOT NULL DEFAULT 'pending',
    attempts         INT             NOT NULL DEFAULT 0,
    error            VARCHAR(255)    NULL,
    payload_json     JSON            NULL,
    received_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    processed_at     DATETIME(3)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wahoo_workout (wahoo_workout_id),
    KEY idx_wahoo_events_status (status, received_at),
    CONSTRAINT fk_wahoo_events_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
