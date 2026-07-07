-- Social-Automatik Phase C (Twitter_Automation_Concept.md §5): Redaktions-Layer.
--
-- `entity_key` ist ein DATUMS-UNABHÄNGIGER Schlüssel des betroffenen Objekts
-- (z. B. 'region:4711', 'rush:12', 'crew:…'), im Gegensatz zum `dedupe_key`,
-- der die konkrete Meldung eindeutig macht (inkl. Datum). Damit lässt sich ein
-- Cooldown „dieselbe Region nicht binnen N Tagen erneut" prüfen, ohne den
-- dedupe_key zu parsen.

SET NAMES utf8mb4;

ALTER TABLE social_post_queue
  ADD COLUMN entity_key VARCHAR(120) NULL AFTER dedupe_key,
  ADD KEY idx_social_entity (kind, entity_key, status, published_at);
