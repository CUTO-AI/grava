-- Ereignisse für die Gebiets-Eroberung: region_taken / region_lost
-- (CityConquest_Backend_Spec.md, Phase D). Eine Stadt/ein Landkreis wechselt den
-- Besitzer → Ereignis in den gemeinsamen game_event-Strom, Zustellung als Inbox +
-- Push über den GameNotificationDispatcher (wie edge_taken).
--
-- game_event: neue ENUM-Werte + region_id-Spalte (Deep-Link). Der Dedupe-Key
-- (type,user_id,edge_id,ridden_on) bleibt UNVERÄNDERT: region_id in den Key
-- aufzunehmen würde die Kanten-Idempotenz brechen (region_id=NULL bei Kanten →
-- MySQL wertet NULL im UNIQUE-Key als distinkt). Region-Ereignisse brauchen keine
-- DB-Dedup — recomputeAll liefert je Flip genau EINEN change, und der Besitz-Cache
-- verhindert Wiederholung bei Re-Ingest. notifications: ENUM-Werte, subject_type
-- 'region', region_id-Spalte für den Deep-Link.

SET NAMES utf8mb4;

ALTER TABLE game_event
  MODIFY COLUMN type ENUM(
    'edge_new','edge_taken','edge_lost','edge_reclaimed','record_beaten','pioneer_joined',
    'region_taken','region_lost'
  ) NOT NULL,
  ADD COLUMN region_id BIGINT UNSIGNED NULL AFTER edge_id;

ALTER TABLE notifications
  MODIFY COLUMN type ENUM(
    'follow','like','comment','territory_taken','crew_invite',
    'edge_taken','edge_lost','edge_reclaimed','record_beaten','pioneer_joined',
    'rush_invite','rush_reminder','rush_result',
    'region_taken','region_lost'
  ) NOT NULL,
  MODIFY COLUMN subject_type ENUM('route','user','rush','edge','region') NULL,
  ADD COLUMN region_id BIGINT UNSIGNED NULL AFTER edge_id;
