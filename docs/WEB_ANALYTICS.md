# Web-Auswertungen: Ranglisten, Gebiete & Karte

Öffentliche, SEO-indexierbare Auswertungsseiten der Website (neben „Heute im
Spiel" `/pulse`). Zeigen den **aktuellen Gesamtstand (all-time)** des
Revierkampfs. Konzept/Design: iOS-Repo `GravelExplorer/WebAnalytics_Concept.md`.

## Seiten (SSR, ohne Login)

| URL | Controller | Inhalt |
|-----|-----------|--------|
| `/rangliste`, `/rangliste/{solo\|crews\|fraktionen}` | `RankingsPagesController` | Ranglisten |
| `/gebiete` | `RegionsPagesController::index` | Länderliste |
| `/gebiete/{id}` | `RegionsPagesController::detail` | Gebiets-Detail |
| `/gebiete/karte` | `RegionsPagesController::map` | Interaktive Karte |

- Solo-Rangliste = all-time **gehaltenes Revier** (`GameRepository::topRidersByHeldLength`),
  NICHT die präsenzbasierte App-Bestenliste (`/game/leaderboard`).
- Crew-Rangliste: `CrewLeaderboardService` (Metrik: gehaltene Strecke, deckungsgleich
  mit `crewWorldRank`). Fraktionen: `FactionService::standings`.
- Ländernamen werden in Seitensprache (de/en) via `App\Support\CountryName`
  (intl `Locale::getDisplayRegion`) angezeigt; `/gebiete` listet nur Wurzeln mit
  `country_code` (maritime Pseudo-Länder/Grenzstreifen ausgeblendet).

## Öffentliche API-Endpunkte

| Endpoint | Zweck |
|----------|-------|
| `GET /game/regions?bbox=&level=&geometry=&owned=` | Gebiete im Ausschnitt (Server wählt Ebene bei fehlendem `level`); `owned=1` = nur eroberte |
| `GET /game/regions/{id}` | Detail inkl. `bbox`, `boundary_geojson`, `leaderboard[]`, `children[]`, `breadcrumb[]` |
| `GET /game/crews/leaderboard` | globale Crew-Rangliste (OptionalBearer) |
| `GET /game/leaderboard`, `GET /game/factions` | Solo (präsenzbasiert) bzw. Fraktionen |

## Karte `/gebiete/karte`

Leaflet, zoom-adaptiv Welt→Land→Bundesland→Landkreis→Gemeinde. Frontend:
`public/assets/js/map-regions.js`, `public/assets/css/regions-map.css`,
`views/web/regions/map.php`. `/heatmap` (`map-territory.js`) bleibt separat.

- **Einfärbung nur bei erobert** (`owner` gesetzt = Schwelle erreicht). „Umkämpft"
  (Führender unter Schwelle) nur dezent gestrichelt. So färbt eine einzelne Kante
  nicht das ganze Land.
- **Overlay:** bei grober Ebene werden eroberte Landkreise als gefüllte Polygone
  über der Karte gezeigt (`?owned=1`), damit gehaltenes Revier beim Rauszoomen
  sichtbar bleibt.
- Klick → App-artiges Detail-Panel + Highlight + `fitBounds`.

## Erober-Schwellen (Config `game_config`)

Ein Gebiet ist **erobert**, wenn der Führende Anteil **und** absolute Kantenzahl
erreicht (`RegionOwnershipService`):

| Ebene | `region_control_min_fraction` | `region_control_min_edges` |
|-------|-------------------------------|----------------------------|
| Gemeinde (8) | 0.25 | 3 |
| Landkreis (6) | 0.30 | 15 |
| Bundesland (4) | 0.35 | 60 |
| Land (2) | 0.40 | 250 |

Zoom→Ebene: `region_level_span_breaks` `{"2":6.0,"4":1.5,"6":0.4}` (Grad-Spanne).
Alle drei sind admin-tunebar; nach Änderung wirkt der nächste Ownership-Recompute
(`regions:ownership-refresh`).

## Region-Datenpflege (CLI)

```bash
# Vorschau (nichts ändern):
php public/index.php regions:recorrect
# Anwenden: falsch verknüpfte L4 re-parenten, Dubletten + heimatlose Fremd-Fragmente entfernen
php public/index.php regions:recorrect --apply --purge-homeless

# Fehlende OSM-Grenze nachladen (Beispiel Alaska), danach recorrect für Untergebiete:
php public/index.php regions:add-osm --relation=1116270 --level=4 --name=Alaska --cc=US --center=64.44,-149.68
php public/index.php regions:recorrect --apply
```

- `regions:recorrect`: strikter Punkt-in-Polygon; politisch umstrittene Fälle
  (echtes Land = RU) werden beim L4-Re-Parent übersprungen. Idempotent.
- `regions:add-osm`: holt eine Relation von `polygons.openstreetmap.fr`, klippt den
  Antimeridian-Zipfel (Osthalbkugel-Ringe), verknüpft NUR diese Region (kein voller
  Re-Link).
- Ursache der ursprünglichen Fehlzuordnungen (grenzüberschreitend, US-Riesen-bbox):
  behoben im Import (`RegionImportService::resolveCountryParent`, cc autoritativ).

## Deploy

Push auf `main` → GitHub Actions `deploy.yml` (netcup, zieht selbst + `cli:migrate`
+ FPM-Reload). Bei Runner-Ausfall manueller SSH-Fallback siehe `docs/OPS_DEPLOY.local.md`.
Verifikation: `curl -s https://cyberride.world/healthz` → `version.short`.
