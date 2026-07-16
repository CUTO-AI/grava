-- Wahoo-Integration Phase C: importierte Wahoo-Fahrten als Routen-Quelle.
-- Erweitert die routes.source-ENUM um 'wahoo' (analog 'strava').
ALTER TABLE routes
    MODIFY COLUMN source ENUM('app','import','strava','manual','wahoo') NOT NULL DEFAULT 'app';
