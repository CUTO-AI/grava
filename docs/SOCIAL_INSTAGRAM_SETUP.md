# Instagram-Setup-Runbook (Meta Graph API)

So bekommst du die zwei Werte, die der Code braucht — **`IG_USER_ID`** und einen
**long-lived `IG_ACCESS_TOKEN`** — und schaltest den Instagram-Kanal scharf.
Der Code ist fertig (`InstagramPublisher`, Multi-Channel, Card-Hosting); dies ist
der Ops-Teil. Feature-/Design-Kontext: `Instagram_Automation_Concept.md`.

> **Zwei Rahmenbedingungen:**
> 1. **Wahrscheinlich kein voller App-Review nötig** — wir posten nur auf den
>    **eigenen** Account (@cyberride). Im Dev-/Standard-Modus gewährst du dir die
>    Rechte selbst. Voller App-Review ist erst nötig, um für *fremde* Accounts zu
>    posten. (Falls Meta dennoch Advanced Access verlangt → Text in §6 nutzen.)
> 2. **Ein echter IG-Post geht nur von einer ÖFFENTLICH erreichbaren Instanz**
>    (Prod), weil Instagram die Media-Card per URL `GET /social/card/{id}.png`
>    lädt. Lokal (`gravelexplorer.test`) lässt sich nur der **Token** prüfen
>    (`social:doctor`), kein echter Post.

## 1. Account + Facebook-Seite

1. Instagram **@cyberride** in ein **Business-** (oder Creator-) Konto umwandeln
   (IG-App → Einstellungen → Konto → „Zu professionellem Konto wechseln").
2. Eine **Facebook-Seite** erstellen (falls keine da) und das IG-Konto damit
   **verknüpfen** (FB-Seite → Einstellungen → Verknüpfte Konten → Instagram).
   Du musst **Admin** der Seite sein.

## 2. Meta-App anlegen

1. [developers.facebook.com](https://developers.facebook.com) → **My Apps** →
   **Create App** → Use case **„Other"** → Typ **„Business"**.
2. In der App: Produkt **„Instagram Graph API"** (bzw. „Instagram") hinzufügen.
3. App bleibt im **Development-Modus** (reicht für den eigenen Account).

> **Abkürzung ab Schritt 3:** Der CLI-Helfer **`social:ig-setup`** übernimmt den
> Token-Tausch (§4) und die IG-User-ID-Suche (§3.3) automatisch. Du brauchst nur
> den **kurzlebigen Token** aus dem Graph API Explorer:
> `php public/index.php social:ig-setup --token=<KURZ> --app-id=<APP_ID> --app-secret=<APP_SECRET>`
> → gibt einen fertigen `.env`-Block (IG_USER_ID + long-lived IG_ACCESS_TOKEN) aus.

## 3. Token + IG-User-ID holen (Graph API Explorer)

1. App-Dashboard → Tools → **Graph API Explorer**.
2. Oben die App wählen; **User Token** generieren mit den Permissions:
   `instagram_basic`, `instagram_content_publish`, `pages_show_list`,
   `pages_read_engagement`, `business_management`.
3. **IG-User-ID ermitteln** (im Explorer nacheinander):
   - `GET /me/accounts` → liefert deine **Page-ID**.
   - `GET /{page-id}?fields=instagram_business_account` → liefert die
     **`instagram_business_account.id`** = **`IG_USER_ID`**.

## 4. Long-lived Token (~60 Tage)

Der Explorer-Token lebt nur ~1–2 h. Einmal tauschen:

```
GET https://graph.facebook.com/v21.0/oauth/access_token
    ?grant_type=fb_exchange_token
    &client_id=<APP_ID>
    &client_secret=<APP_SECRET>
    &fb_exchange_token=<SHORT_LIVED_TOKEN>
```

→ Antwort enthält den **long-lived** `access_token` = **`IG_ACCESS_TOKEN`**.
(App-ID/Secret stehen im App-Dashboard unter „Settings → Basic".)

> **Ablauf:** Long-lived Tokens verfallen nach ~60 Tagen. Rechtzeitig erneuern
> (manuell wie oben, oder wir bauen später einen kleinen `social:ig-refresh`-Cron).

## 5. In die `.env` + Scharfschalten

```
SOCIAL_CHANNELS=instagram          # oder twitter,instagram
IG_USER_ID=<…>
IG_ACCESS_TOKEN=<long-lived …>
IG_GRAPH_VERSION=v21.0
```

1. `social:doctor` → erwartet `channels.instagram.ok: true`, `account: "cyberride"`.
   (Lokal möglich — prüft nur den Token, postet nicht.)
2. **Nur auf Prod** (öffentliche `PUBLIC_WEB_URL`): `social:preview` sichten →
   `SOCIAL_ENABLED=1` + `SOCIAL_DRY_RUN=0` → nächster `social-publish` postet.
3. **Card-URL testen:** `GET https://cyberride.world/social/card/<queueId>.png`
   im Browser öffnen — genau dieses Bild holt Instagram.

## 6. App-Review-Text (nur falls Meta Advanced Access verlangt)

Feld „How your app uses this permission" (`instagram_content_publish`), Englisch:

> CYBERRIDE is a gravel-cycling app with a territory game. Our backend uses the
> Instagram Graph API to publish a small number of posts per day to our own
> business account (@cyberride). Each post is an automatically generated summary
> of public in-game community activity — daily recaps, when a region is
> conquered, crew results, weekly recaps and milestones — as a short caption plus
> a branded image that we render and host ourselves. We publish only to the one
> business account that we own and operate; we do not publish on behalf of any
> other user or account, and we do not read, analyze, or store other users'
> content. Posting is rate-limited to a few scheduled posts per day and is used
> solely to share our community's activity.

## Grenzen / Hinweise

- **Media:** Ein Bild pro Post (Single-Feed). Caption ≤ 2200 Zeichen (unsere
  Texte sind kürzer). Karussell/Stories = späterer Ausbau.
- **Rate Limits:** IG erlaubt ~50 API-Posts / 24 h pro Konto — für uns unkritisch.
- **Kein Per-Post-Preis** (anders als X-Basic) — nur der Setup-/Review-Aufwand.
