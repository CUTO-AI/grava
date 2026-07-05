# USA-Rollout — Valhalla + Gebiete pro Kontinent (EU/US)

Ziel: USA genauso spielbar wie Europa — eigenes Valhalla-Routing **und** die volle
Gebiets-Hierarchie (Land → Bundesstaat → County → Gemeinde/Stadt). Statt einer
Planet-Kachelmenge läuft **pro Kontinent eine eigene, kleine Valhalla-Instanz**; das
Backend wählt pro Fahrt anhand der Koordinaten automatisch EU oder US.

> Backend-Code ist bereits fertig (`RegionalValhallaClient`/`ValhallaClientFactory`,
> `regions:import --append`). USA aktiv wird durch **Daten aufbauen + `.env` setzen** —
> keine weitere Code-Änderung nötig.

---

## 0. Architektur in einem Satz

`RegionalValhallaClient` hält je Kontinent eine Instanz + Bounding-Box und leitet den
Map-Match-Aufruf an die Instanz weiter, in deren Box der **erste Fahrt-Punkt** liegt
(EU-Box und US-Box sind disjunkt → eindeutig; Ozean-Fälle → EU-Fallback). Aktiv wird
das Multi-Instanz-Setup, sobald `VALHALLA_URL_US` in der `.env` gesetzt ist.

---

## 1. Valhalla USA aufbauen (lokal, Docker)

> Ressourcen: `north-america-latest.osm.pbf` ist ~14 GB; der Tile-Build braucht viel
> Disk (≥ ~150–250 GB frei) und Zeit (Stunden). Läuft **parallel** zur EU-Instanz.

```bash
cd ~/Sites/gravelexplorer/docker/valhalla

# 1a) PBF laden
mkdir -p custom_files_us
curl -L --fail --retry 3 -C - \
  -o custom_files_us/north-america-latest.osm.pbf \
  https://download.geofabrik.de/north-america-latest.osm.pbf
# (Nur USA nötig? Kleiner/schneller: .../north-america/us-latest.osm.pbf)

# 1b) Container starten (baut Tiles beim ersten Start, Port 8003)
docker compose -f docker-compose.us.yml up -d
docker compose -f docker-compose.us.yml logs -f     # Build beobachten

# 1c) Fertig, wenn /status antwortet:
curl http://localhost:8003/status
```

EU bleibt unverändert auf `:8002`, US läuft auf `:8003`.

---

## 2. US-Verwaltungsgrenzen importieren (Gebiete)

Gleiche osmium-Pipeline wie EU, aber **`--append`** (hängt USA an, ohne EU zu löschen).
Läuft gegen die **lokale** DB; danach wird der komplette Bestand nach PROD gepusht.

```bash
cd ~/Sites/gravelexplorer

# 2a) US-Grenzen aus dem US-PBF in die lokale game_region-Hierarchie ANHÄNGEN
scripts/import_admin_boundaries.sh \
  docker/valhalla/custom_files_us/north-america-latest.osm.pbf --append
#   → osmium tags-filter/export → regions:import --append (memory_limit 3G)
#   OSM admin_level in den USA: 2=Land, 4=State, 6=County, 8=City/Township

# 2b) Insel-/Hierarchie-Fix (wie bei EU; korrigiert zu hoch verkettete Gebiete)
php -d memory_limit=2G public/index.php regions:relink

# 2c) Kompletten Bestand (EU + US) nach PROD schieben (ersetzt dort game_region)
php public/index.php regions:push \
  --base-url=https://grava.world --token=$INTERNAL_TOKEN
```

Danach auf **PROD** neu zuordnen + berechnen (Reihenfolge wichtig):

```bash
T=$INTERNAL_TOKEN
curl -sS "https://grava.world/internal/regions/backfill?all=1&token=$T"   # Kanten→Gebiete (alle neu)
curl -sS "https://grava.world/internal/regions/relink?token=$T"           # Inseln/Hierarchie
curl -sS "https://grava.world/internal/cron/region-ownership?token=$T"    # Besitz rollen
```

> `regions:push` ersetzt `game_region` auf PROD komplett durch den lokalen Stand
> (EU **+** US). Deshalb muss lokal beides vorhanden sein (Schritt 2a hängt US an EU
> an). `backfill?all=1` ist danach Pflicht, weil die Kanten-Gebietszuordnung neu
> aufgebaut werden muss.

---

## 3. Connectivity von außen (.env + Tunnel)

Das PROD-Backend (grava.world) erreicht deine **lokalen** Valhalla-Dienste über
Cloudflare-Quick-Tunnel. Für USA kommt ein **zweiter** Tunnel (Port 8003) dazu.

```bash
# EU-Tunnel (bestehend)
scripts/valhalla_tunnel.sh start          # → https://<eu>.trycloudflare.com  (Port 8002)

# US-Tunnel (zweiter cloudflared, Port 8003)
cloudflared tunnel --url http://localhost:8003 \
  > /tmp/ge_valhalla_us_tunnel.log 2>&1 &
grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' /tmp/ge_valhalla_us_tunnel.log | head -1
```

Dann in der **PROD-`.env`** (bei United Domains / Hosting-Panel):

```dotenv
# EU bleibt bestehen — neuer, sprechender Schlüssel (Fallback: VALHALLA_BASE_URL):
VALHALLA_URL_EU=https://<eu>.trycloudflare.com
# USA aktiviert das Multi-Instanz-Routing:
VALHALLA_URL_US=https://<us>.trycloudflare.com
```

- Ist `VALHALLA_URL_US` **leer/nicht gesetzt** → alles läuft wie bisher (nur EU).
- `VALHALLA_URL_EU` ist optional; fehlt er, wird weiter `VALHALLA_BASE_URL` genutzt.
- Quick-Tunnel-URLs sind **ephemer** — nach jedem Neustart neu in die `.env` eintragen.

### MAMP Pro / stabile URLs (optional, empfohlen für Dauerbetrieb)
Quick-Tunnel sind zum Testen ok, aber flüchtig. Für Stabilität:
- **Benannter Cloudflare-Tunnel** (statt `--url`): feste Hostnames
  `valhalla-eu.deinedomain` / `valhalla-us.deinedomain` via `cloudflared tunnel route dns`
  → `.env`-URLs ändern sich nie mehr.
- **MAMP Pro** hilft hier nicht direkt (es hostet PHP/MySQL, nicht die Docker-Valhalla-
  Container). Die Valhalla-Dienste bleiben Docker + Tunnel. MAMP Pro brauchst du nur,
  falls du das **PHP-Backend** lokal betreibst — für den USA-Rollout nicht nötig.

### Prüfen (beide Instanzen)
```bash
curl -s "https://grava.world/healthz?check=valhalla" | jq
# → checks.valhalla (Primär) + checks.valhalla_regions[] mit name eu/us, reachable, latency
```

---

## 4. App (iOS) — was nötig ist

**Nichts am Routing** — die Instanz-Wahl passiert komplett im Backend; die App lädt
weiter nur Fahrten hoch und liest Summaries. Punkte, die dennoch relevant sind:

- **Gebiets-/Share-Karten** funktionieren für US automatisch, sobald US-Gebiete
  importiert sind (das `regions`-Delta ist länderneutral).
- **Karten-Snapshots** nutzen Apple MapKit (weltweit) — kein US-Sonderfall.
- **Texte/Einheiten:** In den USA sind Meilen/Fuß üblich. Falls gewünscht, später ein
  Maßeinheiten-Setting (metrisch/imperial) für Distanz/Höhe in App + Share-Karten
  (`RideFormat`) — **nicht** Teil dieses Rollouts, aber der naheliegende US-Feinschliff.
- **Sprache:** Englisch ist bereits vollständig lokalisiert.

---

## 5. Reihenfolge / Checkliste

1. [ ] `north-america-latest.osm.pbf` nach `custom_files_us/` laden
2. [ ] `docker compose -f docker-compose.us.yml up -d` → `:8003/status` grün
3. [ ] `import_admin_boundaries.sh <us-pbf> --append` (lokal, EU bleibt)
4. [ ] `regions:relink` (lokal)
5. [ ] `regions:push --base-url=… --token=…` (EU+US → PROD)
6. [ ] PROD: `backfill?all=1` → `relink` → `cron/region-ownership`
7. [ ] US-Tunnel starten, `VALHALLA_URL_US` in PROD-`.env` setzen
8. [ ] `healthz?check=valhalla` → beide Regionen `reachable`
9. [ ] Testfahrt in den USA hochladen → Kanten + Gebiete erscheinen
