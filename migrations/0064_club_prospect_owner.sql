-- CRM-Rückverknüpfung: welcher User-Account hat den Verein angelegt/aktiviert
-- (Web-Aktivierungs-Journey, CrewInvite_Onboarding_Spec §10). Ergänzt club_prospect.
SET NAMES utf8mb4;

ALTER TABLE club_prospect
  ADD COLUMN owner_user_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER crew_id;
