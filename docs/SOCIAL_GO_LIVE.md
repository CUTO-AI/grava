# Social-Automatik — Go-Live-Runbook

Schrittfolge, um die (fertig gebaute, aktuell im Dry-Run laufende) Social-
Automatik scharf zu schalten. **Multi-Channel:** jede Meldung geht auf alle in
`SOCIAL_CHANNELS` gelisteten Kanäle (`twitter`, `instagram`). Feature-Details:
`docs/SOCIAL_TWITTER.md`; Instagram-Design: `Instagram_Automation_Concept.md`.
Sicher-Default: Es wird NICHTS gepostet, solange `SOCIAL_ENABLED=0` **oder**
`SOCIAL_DRY_RUN=1`. Kanäle sind unabhängig — einer kann live sein, der andere aus.

> Jeder `/internal/...`-Aufruf braucht `?token=<INTERNAL_TOKEN>`.

**Kanäle wählen** (`.env`): `SOCIAL_CHANNELS=twitter` · `…=instagram` ·
`…=twitter,instagram`. Der Teil unten „X/Twitter" gilt für den Twitter-Kanal,
der Abschnitt **„Instagram"** für den Instagram-Kanal. `social:doctor` prüft
beide und zeigt je Kanal `configured/ok/account/error`.

## 1. X/Twitter-Account + Developer-App (einmalig, manuell)

1. Account **`@cyberride`** anlegen (offizieller Marken-Kanal).
2. X-Developer-Portal → Projekt + App anlegen. **Free-Tier** genügt für den Start
   (~1 Post/Tag); für den vollen Slot-Plan später **Basic** (§5 im Feature-Doc).
3. App-Berechtigung auf **Read and Write** stellen (sonst schlägt das Posten fehl).
4. **OAuth 1.0a** User-Context-Credentials erzeugen: Consumer Key/Secret
   (API Key/Secret) + Access Token/Secret **des `@cyberride`-Accounts**.
5. Impressum/Verantwortlichkeit für den Kanal klären (wie Web).

## 2. Secrets in die Prod-`.env`

```
SOCIAL_CHANNELS=twitter        # oder twitter,instagram
TWITTER_CONSUMER_KEY=…
TWITTER_CONSUMER_SECRET=…
TWITTER_ACCESS_TOKEN=…
TWITTER_ACCESS_TOKEN_SECRET=…
# vorerst NICHT scharf schalten:
SOCIAL_ENABLED=false
SOCIAL_DRY_RUN=true
```

## 2b. Instagram-Account + Meta-App (nur wenn `instagram` in SOCIAL_CHANNELS)

Instagram ist aufwändiger als X — v. a. der **Meta-App-Review** ist ein externer
Zeitfaktor (früh beantragen). Ablauf (einmalig, manuell):

1. Instagram **Business-/Creator-Account** `@cyberride` anlegen und mit einer
   **Facebook-Seite** verknüpfen.
2. Meta-Developer-App anlegen; Produkt „Instagram Graph API".
3. Permission **`instagram_content_publish`** (+ `instagram_basic`,
   `pages_read_engagement`) beantragen → **App Review** durch Meta.
4. **Long-lived** Access-Token holen; die **IG-User-ID** des Accounts ermitteln.
5. **`PUBLIC_WEB_URL` muss öffentlich erreichbar** sein — Instagram lädt die
   Media-Card von `GET /social/card/{id}.png` (kein Byte-Upload wie bei X).

Secrets in die Prod-`.env`:
```
SOCIAL_CHANNELS=twitter,instagram   # instagram dazunehmen
IG_USER_ID=…
IG_ACCESS_TOKEN=…                   # long-lived
IG_GRAPH_VERSION=v21.0
```
> Token-Refresh: IG-Long-lived-Tokens laufen ~60 Tage — rechtzeitig erneuern
> (manuell oder kleiner Cron; als Ausbau vorgesehen).

## 3. Deploy + Migrationen

1. Deploy des Backends (Branch `feature/social-twitter-phase-a` gemergt).
2. `GET /internal/migrate?token=…` → wendet **0052** (Queue/Log/social_optin)
   und **0053** (entity_key) an. (Neuer Endpunkt erst nach vollständigem Upload
   verfügbar — Stolperfalle wie bei game-snapshot, siehe OPS-Doc.)

## 4. Startklar-Check (postet nichts)

`GET /internal/social/doctor?token=…` → JSON. Erwartet für Go-Live:
- `migrations_ok: true`, `media_ready: true`
- je aktivem Kanal unter `channels`: `configured: true`, `ok: true`,
  `account: "cyberride"`.
- `verdict`: „Bereit — aber im Dry-Run" (weil Flags noch aus).

Bei `channels.<kanal>.ok:false` → `channels.<kanal>.error` lesen. Häufig:
- **twitter**: Berechtigung ≠ Read+Write oder falsche Tokens.
- **instagram**: Token abgelaufen, App-Review offen, oder IG_USER_ID falsch.

## 5. Trocken-Vorschau

`GET /internal/social/preview?token=…&date=<Tag mit Aktivität>&lang=en`
→ prüft Texte/Zahlen aller Kandidaten des Tages, ohne zu senden.

## 6. Cron einrichten (externer Scheduler, HTTPS)

| Endpoint | Intervall |
|---|---|
| `/internal/cron/social-collect?token=…` | täglich **~19:55 UTC** |
| `/internal/cron/social-publish?token=…` | täglich **~20:00 UTC** |

(Ein `social-collect`/`social-publish`-Paar bedient ALLE Kanäle — kein separater
Cron pro Plattform. Tages-Cap gilt pro Kanal. X-Free: 1 Post/Tag.)

## 7. Scharf schalten

`.env`: `SOCIAL_ENABLED=true`, `SOCIAL_DRY_RUN=false`. Beim nächsten
`social-publish` geht je aktivem Kanal der News-wertigste Kandidat des Tages
unter dem Tages-Limit raus. Einzelnen Kanal weglassen = aus `SOCIAL_CHANNELS`
streichen.

## 8. Verifizieren

- `GET /internal/social/status?token=…` → `published_today` (je Kanal), letzte Sendungen.
- Post samt Media-Card prüfen: `x.com/cyberride` bzw. `instagram.com/cyberride`.
- Instagram-Card direkt testbar: `GET /social/card/<queueId>.png` im Browser öffnen.

## Rollback (sofort still)

`.env`: `SOCIAL_ENABLED=false` (oder `SOCIAL_DRY_RUN=true`) → alle Kanäle still.
Nur einen Kanal stilllegen: aus `SOCIAL_CHANNELS` entfernen. Kein Redeploy nötig,
greift beim nächsten Lauf.

## Personenbezogene Highlights (opt-in)

KOM/Abzeichen (`record_beaten`, `badge_earned`) posten NUR für Nutzer mit
`social_optin=1`. Das Opt-in setzen Nutzer in der App (Profil → Privatsphäre →
„Highlights auf X teilen"). Ohne Opt-in bleiben diese Meldungen aus.
