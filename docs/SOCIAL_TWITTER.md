# Social-Automatik (X/Twitter) — Backend

Automatisierte X/Twitter-Meldungen aus der Tagesaktivität von CYBERRIDE.
Fachliches Konzept: `Twitter_Automation_Concept.md` (iOS-Repo). Dieses Dokument
beschreibt die **Backend-Umsetzung** und den Betrieb.

**Stand:** Phase A–F gebaut (Branch `feature/social-twitter-phase-a`; iOS-Teil im
App-Repo), lokal getestet, **noch nicht deployt**, sendet per Default nichts (Dry-Run).
Meldungstypen: `daily_report` (E1), `region_taken` (A1, Solo-Rider opt-in-gated),
`rush_result` (B2), `faction_standing` (C2, sonntags),
`badge_earned` (D3, opt-in), `record_beaten` (D1 KOM, opt-in),
`weekly_recap` (E2, sonntags), `community_milestone` (E4, km-Schwelle).
Redaktions-Layer (Phase C): Expiry + Per-Entity-Cooldown + `social:status`.
Media-Cards (Phase D): gebrandete 1200×675-PNGs je Meldungstyp (Region mit
gezeichneter Grenze) + X-Media-Upload; best-effort, sonst text-only.
Opt-in (Phase E): `PUT /users/me/social-optin` + iOS-Toggle (ProfileView →
Privatsphäre); personenbezogene Highlights (KOM, Abzeichen) NUR mit `social_optin=1`.

---

## 1. Prinzip

Datengetrieben, kanal-agnostisch (Konzept §5–§7): ein Cron sammelt die
Tagesaktivität ein und legt einen Post-Kandidaten in eine Redaktions-**Queue**;
ein zweiter Cron **sendet** fällige Kandidaten getaktet und unter einem
Tages-Limit. „Kein Event → kein Post."

```
Aktivität (routes / game_edge / game_region_ownership / game_rush)
   │  social:collect  (~19:55 UTC)
   ▼
social_post_queue  ──►  social:publish (~20:00 UTC)  ──►  Publisher (X)  ──►  social_post_log
                                                        └► NullPublisher (Dry-Run)
```

## 2. Code

Alles unter `src/Social/` (Namespace `App\Social`):

| Datei | Rolle |
|---|---|
| `PostSource` (Interface) / `PostCandidate` | eine Meldungs-Quelle liefert fertige Kandidaten (kind, dedupeKey, score, body, payload) |
| `DailyReport` / `DailyReportCollector` | aggregiert Tagesaktivität (rides, km, Kanten übernommen, Landkreise gewechselt, Rush des Tages). Jede Kennzahl fehlertolerant (fehlt eine Tabelle → 0 + `error_log`). |
| `RegionTakenCollector` | „Landkreis erobert" (A1). Crew = öffentlich; Solo-Rider nur mit `social_optin=1` (E3), sonst übersprungen. |
| `RushResultCollector` | „Rush-Ergebnis" (B2): heute abgeschlossene Crew-Rushes + eroberte Kanten. Öffentlich. |
| `FactionStandingCollector` | „Fraktions-Wochenstand" (C2): Anteil je Fraktion, nur SONNTAGS (UTC). |
| `BadgeEarnedCollector` | „Seltenes Abzeichen" (D3): heute erreichte Platin/Onyx (tier ≥ 3). **Opt-in-gated** (E3). |
| `RecordBeatenCollector` | „Neuer KOM" (D1): `game_event` record_beaten heute, Region-Name der Kante. **Opt-in-gated** (E3). |
| `WeeklyRecapCollector` | „Wochenrückblick" (E2): 7-Tage-Aggregat, nur SONNTAGS (UTC). Öffentlich. |
| `CommunityMilestoneCollector` | „Community-Meilenstein" (E4): kumulierte Gesamt-km überschreitet eine Schwelle; queue-dedupliziert (kein neues Schema). Öffentlich. |
| `PostCopy` | sprach-keyed Textbausteine (EN Go-Live, DE hinterlegt), begrenzt auf ≤280 Zeichen; je Meldungstyp eine Methode |
| `EditorialPolicy` | Redaktions-Regeln (Phase C): `pruneStale()` verfallen lassen + `entityOnCooldown()` prüfen |
| `SocialCardRenderer` | Media-Cards (Phase D): GD, 1200×675, Marken-Palette/Fonts (`resources/fonts`), je Meldungstyp ein Layout; `region_taken` zeichnet die Landkreis-Grenze (aus `game_region.boundary_geojson`). Best-effort → null = text-only. |
| `Publisher` (Interface) | kanal-agnostische Sende-Schnittstelle; `publish(text, ?imagePng)` |
| `TwitterPublisher` | X API v2 `POST /2/tweets` (OAuth 1.0a); Media via v1.1 `media/upload` (multipart) → `media_ids` |
| `NullPublisher` | Dry-Run — sendet nichts |
| `SocialService` | Orchestrierung: `gatherCandidates` (Tagesbericht + alle Quellen) → `preview` / `collectDaily` / `publishPending`, Publisher-Wahl, Tages-Cap |

CLI (`src/Cli/Commands.php`), auch als Internal-HTTP-Route:

| Befehl | Route | Zweck |
|---|---|---|
| `social:preview [--date=] [--lang=]` | `GET /internal/social/preview` | Trocken-Vorschau (kein Speichern/Senden) |
| `social:collect [--date=]` | `GET /internal/cron/social-collect` | Tagesbericht → Queue (idempotent) |
| `social:publish` | `GET /internal/cron/social-publish` | Redaktions-Layer + fällige Posts senden |
| `social:status` | `GET /internal/social/status` | Betriebs-Überblick (Queue-Zustand, letzte Sendungen) |
| `social:doctor` | `GET /internal/social/doctor` | Startklar-Check (Config, Migrationen, X-Verbindung); postet nichts |
| `social:card --kind= [--date= --lang= --out=]` | — (CLI) | Media-Card in eine PNG-Datei rendern (Vorschau; storage/social-cards/) |

## 3. Datenbank (Migration `0052_social_posts.sql`)

- **`social_post_queue`** — ein Kandidat je Meldung. `dedupe_key` (unique) macht
  das Einsammeln idempotent. `entity_key` ist der DATUMS-UNABHÄNGIGE Objekt-
  Schlüssel für den Cooldown (Migration `0053`), z. B. `region:4711`, `rush:12`,
  `day:<date>`, `faction:<isoWeek>`. `status`: `pending|published|skipped|failed`.
  `kind`: `daily_report` | `region_taken` | `rush_result` | `faction_standing`.
  Dedupe-Schemata: `daily_report:<date>:<lang>:<channel>`,
  `region_taken:<regionId>:<date>:<lang>:<channel>`,
  `rush_result:<rushId>:<lang>:<channel>`,
  `faction_standing:<isoWeek>:<lang>:<channel>`.
- **`social_post_log`** — ein Eintrag je Sendeversuch (`ok|error|dry_run`,
  `external_id` = Tweet-ID). Dient dem Tages-Cap und dem Audit.
- **`users.social_optin`** — Default 0. In Phase A ungenutzt; Andockpunkt für
  personenbezogene Highlights (Konzept §8/E3) + iOS-Opt-in-UI.

## 4. Konfiguration (`.env`)

```bash
SOCIAL_ENABLED=false        # Gesamtschalter. false => immer Dry-Run.
SOCIAL_DRY_RUN=true         # true => sendet nie, nur Queue+Log.
SOCIAL_CHANNEL=twitter      # kanal-agnostisch; nur 'twitter' implementiert.
SOCIAL_LANG=en              # Go-Live-Sprache (E4). 'de' ist im Code hinterlegt.
SOCIAL_MAX_POSTS_PER_DAY=1  # Free-Tier-Schutz (E8).
SOCIAL_MAX_AGE_HOURS=36        # Kandidat älter als N h → verfällt (kein verspätetes Posten).
SOCIAL_ENTITY_COOLDOWN_DAYS=3  # dieselbe Region/Objekt nicht binnen N Tagen erneut posten.
SOCIAL_MEDIA_ENABLED=true      # gebrandete Media-Card an Posts anhängen (best-effort).
# SOCIAL_FONT_DIR=             # optionaler TTF-Pfad; Default: <repo>/resources/fonts

# X API v2 — OAuth 1.0a User-Context für EINEN eigenen Account (@cyberride).
TWITTER_CONSUMER_KEY=
TWITTER_CONSUMER_SECRET=
TWITTER_ACCESS_TOKEN=
TWITTER_ACCESS_TOKEN_SECRET=
```

**Sicherer Default:** Es wird erst gesendet, wenn `SOCIAL_ENABLED=1` **und**
`SOCIAL_DRY_RUN=0` **und** alle vier `TWITTER_*` gesetzt sind. Fehlt etwas,
fällt der Publisher automatisch auf Dry-Run zurück.

Links zeigen auf `PUBLIC_WEB_URL` (Fallback `APP_URL`).

## 5. Go-Live-Checkliste

> Ausführliches Schritt-für-Schritt-Runbook: **`docs/SOCIAL_GO_LIVE.md`**.
> Vor dem Scharfschalten immer `social:doctor` grün bekommen.

1. **X-Account** `@cyberride` anlegen; im X-Developer-Portal eine App mit
   **Read+Write** erstellen; Consumer-Keys + Access-Token/-Secret erzeugen.
2. `TWITTER_*` in die Prod-`.env` eintragen.
3. Deploy; Migration `/internal/migrate?token=…` (legt `0052` an).
4. **Verifizieren im Dry-Run:** `GET /internal/social/preview?token=…&date=<Tag mit Aktivität>`
   → prüft Text/Zahlen, ohne zu senden.
5. Cron einrichten: `social-collect` ~19:55 UTC, `social-publish` ~20:00 UTC
   (externer Scheduler, siehe `docs/OPS_DEPLOY.local.md`).
6. **Scharf schalten:** `SOCIAL_ENABLED=1`, `SOCIAL_DRY_RUN=0`. Erster echter
   Post beim nächsten `social-publish`.
7. **Free-Tier prüfen:** aktuelles X-Post-Limit verifizieren; `SOCIAL_MAX_POSTS_PER_DAY`
   passt konservativ auf 1. Bei Upgrade auf Basic Slot-Plan erweitern.

**Rollback:** `SOCIAL_ENABLED=0` (oder `SOCIAL_DRY_RUN=1`) → sofort wieder still.

## 6. Verhalten / Invarianten

- **Dry-Run lässt Kandidaten `pending`** (er soll später echt raus), Log bekommt
  eine `dry_run`-Zeile. Echter Erfolg → `published`. Fehler → `failed` + `error`.
- **Redaktions-Layer (Phase C, §5)** in `social:publish`: (1) `pruneStale` markiert
  pending-Kandidaten älter als `SOCIAL_MAX_AGE_HOURS` als `skipped` (error `expired`);
  (2) vor dem Senden prüft `entityOnCooldown` gegen bereits `published` Posts derselben
  `entity_key` binnen `SOCIAL_ENTITY_COOLDOWN_DAYS` → `skipped` (error `cooldown`);
  (3) Auswahl bleibt `score DESC` unter dem Tages-Cap. Cooldown wirkt praktisch nur
  auf `region_taken` (day/faction/rush-Entities sind ohnehin datums-/id-eindeutig).
- **Tages-Cap** zählt `social_post_log.status='ok'` des aktuellen UTC-Tags.
- **Idempotenz** über `dedupe_key`; erneutes `collect` desselben Tags = `already_queued`.
- **Leerer Tag** (keine Fahrten/Kanten/Landkreise/Rush) → kein Kandidat (`no_activity`).

## 7. Restliche Ausbaustufen (Konzept §9)

Phase A–F sind gebaut (alle Text-Meldungstypen + Redaktions-Layer + Media-Cards
+ Opt-in-Endpoint/iOS-UI + Wochenrückblick + Community-Meilenstein). Bewusst
noch offen — jeweils mit Grund:
- **D2 Rang-Aufstieg** — Rang ist rein abgeleitet (`ap_total`); „heute Rang N
  erreicht" braucht eine neue Rang-History-Tabelle + Ingest-Hook.
- **E3 Live-Peak** („X fahren gerade") — braucht Persistenz des Tages-Peaks der
  Presence (aktuell nur Live-Zähler, keine Historie).
- **B1 Rush-Ankündigung** — Vorab-Post passt nicht zur 1×/Tag-Collect-Kadenz;
  sinnvoll erst mit X-Basic + häufigerem Collect-Cron.
- **Upgrade auf X-Basic** + voller Slot-Plan (§5) + optional per-kind-Tageslimit.
- **DE zuschalten** (reine Config: SOCIAL_LANG) · **Claude-Copy-Veredelung** ·
  **weitere Publisher-Adapter** (Mastodon/Threads/…).

Neue Meldungstypen = neuer `PostSource` + `PostCopy`-Methode (+ `entity_key`,
optional ein `SocialCardRenderer`-Layout).
