-- Config-Versionierung (GameAdmin_Concept.md Phase 2): bei jeder Änderung an der
-- Spiel-Config wird der resultierende Gesamtzustand als Voll-Snapshot abgelegt.
-- Erlaubt Historie, Diff (vs. Vorversion) und Rollback (Snapshot erneut anwenden).
-- Ergänzt das bestehende per-Key-Audit (game_audit action=config_update).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_config_versions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    note          VARCHAR(160) NULL,
    snapshot_json MEDIUMTEXT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cfgver_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
