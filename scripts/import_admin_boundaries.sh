#!/usr/bin/env bash
#
# import_admin_boundaries.sh — lädt OSM-Verwaltungsgrenzen (admin_level 2/4/6/8)
# in die game_region-Hierarchie für die Gebiets-Eroberung (CityConquest_Backend_Spec.md,
# Phase A). Nutzt osmium (kein GDAL nötig): filtert boundary=administrative aus dem
# Europa-PBF und exportiert die assemblierten (Multi)Polygone als GeoJSONSeq; der
# PHP-Befehl regions:import streamt das dann in die DB.
#
#   scripts/import_admin_boundaries.sh [PBF] [--backfill] [--append]
#
# PBF        Pfad zum OSM-Extrakt (Default: das bereits gebaute Europa-Tileset-PBF).
# --backfill Nach dem Import zusätzlich game_edge.region_id füllen (regions:backfill).
# --append   Gebiete ANHÄNGEN statt ersetzen — für einen weiteren Kontinent (z. B.
#            USA: north-america-latest.osm.pbf), ohne den EU-Bestand zu löschen.
#
# Die Zwischendateien liegen unter storage/regions/ (gitignored). Ohne --append ist
# der Import idempotent (löscht die Gebiete vorab) — bei OSM-Updates erneut laufen.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PBF="${1:-docker/valhalla/custom_files/europe-latest.osm.pbf}"
BACKFILL=0
APPEND=0
for arg in "$@"; do
  [[ "$arg" == "--backfill" ]] && BACKFILL=1
  [[ "$arg" == "--append" ]] && APPEND=1
done
IMPORT_FLAGS=""
[[ "$APPEND" == "1" ]] && IMPORT_FLAGS="--append"

OUT_DIR="storage/regions"
BOUNDARIES_PBF="${OUT_DIR}/boundaries.osm.pbf"
GEOJSONSEQ="${OUT_DIR}/boundaries.geojsonseq"

command -v osmium >/dev/null 2>&1 || { echo "FEHLER: osmium nicht installiert (brew install osmium-tool)." >&2; exit 1; }
[[ -f "$PBF" ]] || { echo "FEHLER: PBF nicht gefunden: $PBF" >&2; exit 1; }
mkdir -p "$OUT_DIR"

echo "[1/3] Grenzen filtern (boundary=administrative) → ${BOUNDARIES_PBF}"
osmium tags-filter "$PBF" r/boundary=administrative -o "$BOUNDARIES_PBF" --overwrite

echo "[2/3] Polygone exportieren → ${GEOJSONSEQ}"
osmium export "$BOUNDARIES_PBF" --geometry-types=polygon -f geojsonseq -o "$GEOJSONSEQ" --overwrite

echo "[3/3] Import in game_region${IMPORT_FLAGS:+ (Append)}"
php -d memory_limit=3G public/index.php regions:import --file="$GEOJSONSEQ" --levels=2,4,6,8 $IMPORT_FLAGS

if [[ "$BACKFILL" == "1" ]]; then
  echo "Backfill game_edge.region_id"
  php -d memory_limit=2G public/index.php regions:backfill --all
fi

echo "Fertig."
