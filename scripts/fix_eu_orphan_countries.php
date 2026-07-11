<?php
// Einmal-Fix: fügt die 5 fehlenden EU-Landes-Polygone (ES/FR/NL/NO/RU) als
// game_region Level-2 ein und hängt die verwaisten Level-4-Regionen per
// Punkt-in-Polygon darunter (inkl. Pfad-Kaskade auf Nachfahren). KEIN globaler
// linkHierarchy (der grabbt via bbox-Fallback fremde Waisen). Geometrien wurden
// vorab aus polygons.openstreetmap.fr nach /tmp/country_XX.json geladen.
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use App\Config\Config;
use App\Database\Db;
use App\Game\RegionRepository;
use App\Support\GeoPolygon;

Config::boot(dirname(__DIR__));
$pdo  = Db::pdo();
$repo = new RegionRepository($pdo);

// stored-bbox-Overrides: weite/antimeridiane bboxes auf die relevante Hauptmasse
// eindampfen, damit ein etwaiger künftiger Relink keine fremden Waisen fängt.
$countries = [
    ['cc' => 'ES', 'rid' => 1311341, 'name' => 'España',     'file' => '/tmp/country_ES.json', 'bbox' => null],
    ['cc' => 'FR', 'rid' => 1403916, 'name' => 'France',     'file' => '/tmp/country_FR.json', 'bbox' => null],
    ['cc' => 'NL', 'rid' => 47796,   'name' => 'Nederland',  'file' => '/tmp/country_NL.json', 'bbox' => ['minLon' => 3.0,   'minLat' => 50.6, 'maxLon' => 7.3,   'maxLat' => 53.8]],
    ['cc' => 'NO', 'rid' => 2978650, 'name' => 'Norge',      'file' => '/tmp/country_NO.json', 'bbox' => ['minLon' => -10.0, 'minLat' => 57.0, 'maxLon' => 35.0,  'maxLat' => 81.5]],
    ['cc' => 'RU', 'rid' => 60189,   'name' => 'Россия',     'file' => '/tmp/country_RU.json', 'bbox' => ['minLon' => 19.0,  'minLat' => 41.0, 'maxLon' => 180.0, 'maxLat' => 82.1]],
];

$pdo->beginTransaction();
try {
    $inserted = [];
    foreach ($countries as $c) {
        $raw = file_get_contents($c['file']);
        if ($raw === false) { throw new RuntimeException("fehlt: {$c['file']}"); }
        $geom = json_decode($raw, true);
        if (!is_array($geom)) { throw new RuntimeException("kaputt: {$c['file']}"); }
        $realBbox = GeoPolygon::bbox($geom);
        $sb = $c['bbox'] ?? $realBbox;
        $center = ['lat' => ($sb['minLat'] + $sb['maxLat']) / 2, 'lon' => ($sb['minLon'] + $sb['maxLon']) / 2];
        $simpl = GeoPolygon::simplify($geom, 0.01);
        $id = $repo->insertRegion([
            'osm_relation_id'  => $c['rid'],
            'level'            => 2,
            'kind'             => 'country',
            'name'             => $c['name'],
            'country_code'     => $c['cc'],
            'center_lat'       => $center['lat'],
            'center_lon'       => $center['lon'],
            'min_lat'          => $sb['minLat'],
            'min_lon'          => $sb['minLon'],
            'max_lat'          => $sb['maxLat'],
            'max_lon'          => $sb['maxLon'],
            'area_km2'         => 0,
            'boundary_geojson' => json_encode($simpl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $pdo->prepare('UPDATE game_region SET path = CONCAT("/", id, "/") WHERE id = ?')->execute([$id]);
        $inserted[$c['cc']] = ['id' => $id, 'geom' => $geom];
        echo "eingefügt {$c['cc']} '{$c['name']}' id={$id}\n";
    }

    // Waisen anhängen (Punkt-in-Polygon gegen die vollen Geometrien).
    $orphans = $pdo->query(
        'SELECT id, name, center_lat, center_lon FROM game_region
          WHERE level = 4 AND parent_id IS NULL AND center_lon >= -50'
    )->fetchAll(PDO::FETCH_ASSOC);

    $attachState = $pdo->prepare('UPDATE game_region SET parent_id = ?, path = CONCAT("/", ?, "/", id, "/"), country_code = ? WHERE id = ?');
    $cascade     = $pdo->prepare('UPDATE game_region SET path = CONCAT("/", ?, path), country_code = ? WHERE path LIKE CONCAT("/", ?, "/%")');

    $attached = 0; $descendants = 0; $unmatched = [];
    foreach ($orphans as $o) {
        $hit = null;
        foreach ($inserted as $cc => $info) {
            if (GeoPolygon::contains((float)$o['center_lat'], (float)$o['center_lon'], $info['geom'])) { $hit = $cc; break; }
        }
        if ($hit === null) { $unmatched[] = $o['name']; continue; }
        $cid = $inserted[$hit]['id'];
        $attachState->execute([$cid, $cid, $hit, $o['id']]);
        $cascade->execute([$cid, $hit, $o['id']]);
        $descendants += $cascade->rowCount();
        $attached++;
    }

    $pdo->commit();
    echo "\nStaaten angehängt: {$attached} / " . count($orphans) . "  | Nachfahren umgepfadet: {$descendants}\n";
    if ($unmatched) { echo "NICHT zugeordnet (bleiben Waise): " . implode(', ', $unmatched) . "\n"; }
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ROLLBACK: " . $e->getMessage() . "\n");
    exit(1);
}
