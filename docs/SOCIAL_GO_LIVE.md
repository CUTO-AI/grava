# Social-Automatik — Go-Live-Runbook

Schrittfolge, um die (fertig gebaute, aktuell im Dry-Run laufende) X/Twitter-
Automatik scharf zu schalten. Feature-Details: `docs/SOCIAL_TWITTER.md`.
Sicher-Default: Es wird NICHTS gepostet, solange `SOCIAL_ENABLED=0` **oder**
`SOCIAL_DRY_RUN=1`.

> Jeder `/internal/...`-Aufruf braucht `?token=<INTERNAL_TOKEN>`.

## 1. X-Account + Developer-App (einmalig, manuell)

1. Account **`@cyberride`** anlegen (offizieller Marken-Kanal).
2. X-Developer-Portal → Projekt + App anlegen. **Free-Tier** genügt für den Start
   (~1 Post/Tag); für den vollen Slot-Plan später **Basic** (§5 im Feature-Doc).
3. App-Berechtigung auf **Read and Write** stellen (sonst schlägt das Posten fehl).
4. **OAuth 1.0a** User-Context-Credentials erzeugen: Consumer Key/Secret
   (API Key/Secret) + Access Token/Secret **des `@cyberride`-Accounts**.
5. Impressum/Verantwortlichkeit für den Kanal klären (wie Web).

## 2. Secrets in die Prod-`.env`

```
TWITTER_CONSUMER_KEY=…
TWITTER_CONSUMER_SECRET=…
TWITTER_ACCESS_TOKEN=…
TWITTER_ACCESS_TOKEN_SECRET=…
# vorerst NICHT scharf schalten:
SOCIAL_ENABLED=false
SOCIAL_DRY_RUN=true
```

## 3. Deploy + Migrationen

1. Deploy des Backends (Branch `feature/social-twitter-phase-a` gemergt).
2. `GET /internal/migrate?token=…` → wendet **0052** (Queue/Log/social_optin)
   und **0053** (entity_key) an. (Neuer Endpunkt erst nach vollständigem Upload
   verfügbar — Stolperfalle wie bei game-snapshot, siehe OPS-Doc.)

## 4. Startklar-Check (postet nichts)

`GET /internal/social/doctor?token=…` → JSON. Erwartet für Go-Live:
- `migrations_ok: true`
- `twitter_configured: true`, `twitter_ok: true`, `twitter_account: "cyberride"`
- `media_ready: true`
- `verdict`: „Bereit — aber im Dry-Run" (weil Flags noch aus).

Bei `twitter_ok:false` → `twitter_error` lesen (meist Berechtigung ≠ Write oder
falsche Tokens).

## 5. Trocken-Vorschau

`GET /internal/social/preview?token=…&date=<Tag mit Aktivität>&lang=en`
→ prüft Texte/Zahlen aller Kandidaten des Tages, ohne zu senden.

## 6. Cron einrichten (externer Scheduler, HTTPS)

| Endpoint | Intervall |
|---|---|
| `/internal/cron/social-collect?token=…` | täglich **~19:55 UTC** |
| `/internal/cron/social-publish?token=…` | täglich **~20:00 UTC** |

(Free-Tier: 1 Post/Tag. Nach X-Basic voller Slot-Plan aus §5.)

## 7. Scharf schalten

`.env`: `SOCIAL_ENABLED=true`, `SOCIAL_DRY_RUN=false`. Beim nächsten
`social-publish` geht der erste echte Post raus (der News-wertigste Kandidat des
Tages unter dem Tages-Limit).

## 8. Verifizieren

- `GET /internal/social/status?token=…` → `published_today`, letzte Sendungen.
- Auf `x.com/cyberride` den Post samt Media-Card prüfen.

## Rollback (sofort still)

`.env`: `SOCIAL_ENABLED=false` (oder `SOCIAL_DRY_RUN=true`). Kein Redeploy nötig,
greift beim nächsten Lauf.

## Personenbezogene Highlights (opt-in)

KOM/Abzeichen (`record_beaten`, `badge_earned`) posten NUR für Nutzer mit
`social_optin=1`. Das Opt-in setzen Nutzer in der App (Profil → Privatsphäre →
„Highlights auf X teilen"). Ohne Opt-in bleiben diese Meldungen aus.
