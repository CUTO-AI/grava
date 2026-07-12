# Game-Backoffice — Konzept

> Verwaltungssystem für den Betrieb des Reviere-Spiels. Ziel: aus der heutigen
> Werkzeugsammlung ein zusammenhängendes Backoffice machen, mit dem sich User,
> Fahrten, Integrität und Spielzustand effizient verwalten lassen.
>
> **Status:** Phase 0 (Fundamente) in Umsetzung. Architektur-Entscheidung: die
> bestehende **server-gerenderte** Admin (PHP/`WebView`) konsequent ausbauen —
> gleiche Session/CSRF/Cyber-Optik, niedrigste Kosten (kein separates SPA).

## Ist-Zustand
Admin unter `admin.cyberride.world` (Host-Split, `AdminHost`). Einzelseiten:
Health, Config, Ingest, Moderation, Players+Ban, Crews, Regionen, Kanten-Inspektor,
Uploads, Referrals, Cron-Monitoring. Zugang = `ADMIN_EMAILS`-Boolean. Schreibende
Aktionen werden per `GameAuditService::record()` protokolliert (aber nicht durchsuchbar).
Es fehlt: echte Nutzer-/Fahrten-Verwaltung, Übersichts-Dashboard, Rollen/Rechte,
durchgängige Muster (Suche/Filter/Bulk/Export).

## Rollen (RBAC)
Statt eines Booleans vier Rollen mit Rechte-Matrix:
- **super** — alles (inkl. Rollenvergabe, DSGVO-Löschung, Config). Quelle: `ADMIN_EMAILS`.
- **operator** (GM) — Tagesgeschäft: User/Fahrten/Review/Ban, Ingest, Recompute.
- **support** — User-Hilfe: lesen + eng begrenzte Aktionen (Verify erzwingen, Reset senden);
  keine destruktiven Aktionen.
- **analyst** — read-only (Dashboards, Listen, Export).

Jede mutierende Aktion: Rechteprüfung + Bestätigung + **Grundfeld** + Audit-Eintrag.
Rollen in `admin_roles(user_id, role)`; `super` wird aus `ADMIN_EMAILS` abgeleitet
(erster Admin existiert immer). Rechte-Matrix ist rein/testbar (`AdminPermissions`).

## Fundamente (Phase 0)
1. **RBAC** — `admin_roles`-Tabelle, `AdminPermissions::can(role, permission)` (rein),
   `AdminRoleService` (Rollenauflösung + Vergabe), minimaler Rollen-Verwaltungsseite
   (`/admin/roles`, nur super).
2. **Resource-Scaffolding** — `AdminListQuery` (parst `q`/`page`/`perPage`/`sort`/`dir`
   einheitlich) + View-Partials (`_nav`, `_pagination`, `_flash`). Neue Listen/Detailseiten
   werden dadurch billig und konsistent.
3. **Audit-Sicht** — `GameAuditService::search()` (Filter Admin/Aktion/Zeitraum) +
   Seite `/admin/audit`.
4. **Gemeinsame Nav** — rollenabhängige Navigations-Partial statt kopierter Links.

## Module (Phase 1)
- **A · Dashboard** `/admin` — Live-KPIs + Trends (reuse Health-Metriken, Presence,
  Cron-/Ingest-Queue): aktive Rider, Signups, Fahrten heute/7T, Queue-Tiefe, fehlgeschlagene
  Jobs, Moderations-Backlog, DAU/WAU/MAU.
- **B · User-360** `/admin/users` — Suche + 360°-Detail (Profil, Status, Geräte, Crew/Fraktion,
  Heimrevier, Rang/AP/Revierlänge, Strava, Flags, Audit-Trail) + Support-Aktionen
  ((un)ban, Shadow-Ban, Verify erzwingen, Reset senden, Handle/Name, DSGVO-Löschung, „view as").
- **C · Fahrten/Routen** `/admin/rides` — Liste (User/Zeit/Distanz/Quelle/Sync/geflaggt) +
  Detail (Karte, Scoring, Ingest-Status, Kanten/Pässe, Anti-Cheat-Signale) + Aktionen
  (re-ingest, invalidieren, re-score, verbergen/löschen, Rohdaten) inkl. Bulk.
- **D · Review-Queue** `/admin/review` — verdächtige Fahrten + UGC-Reports (`content_reports`) +
  Flags/Bans in einem Workflow (offen → in Prüfung → erledigt/abgelehnt).

## Spätere Stufen
Config-Versionierung/Rollback, Crews/Fraktionen/Regionen-Konsolidierung, Broadcast-/
segmentierter Push, Analytics/Exports (Retention/Funnels/CSV), 2FA für Admins.

## Prinzipien
- Bestehende Services wiederverwenden statt duplizieren (`GameAdminService`,
  `GamePassAdminService`, `GameUserFlagService`, `GameModerationService`, `PresenceService`,
  `CronRunRepository`, `IngestJobRepository`, `RouteAdminService`).
- Read/Write getrennt; destruktive Aktionen nur super/operator.
- Konsistentes Listen-/Detail-/Aktions-Muster überall; jede Mutation → Audit.
- Server-gerendert, `admin.cyberride.world`, gleiche Auth/CSRF wie heute.
