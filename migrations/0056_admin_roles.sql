-- Game-Backoffice RBAC (GameAdmin_Concept.md, Phase 0): Rollen für Admin-Nutzer.
-- Bisher gab es nur den ADMIN_EMAILS-Boolean (= alles dürfen). Diese Tabelle
-- vergibt abgestufte Rollen an konkrete User; `super` wird weiterhin aus
-- ADMIN_EMAILS abgeleitet (erster Admin existiert immer, unabhängig von der DB).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_roles (
    user_id    BIGINT UNSIGNED NOT NULL,
    role       ENUM('super','operator','support','analyst') NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (user_id),
    CONSTRAINT fk_admin_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
