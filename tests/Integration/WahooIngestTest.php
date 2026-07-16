<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Integrations\Wahoo\FitDecoder;
use App\Integrations\Wahoo\WahooClient;
use App\Integrations\Wahoo\WahooService;
use App\Routes\GeometryParser;
use App\Routes\GeometryStats;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\RouteStorage;
use App\Config\Config;
use App\Support\Crypto;
use Tests\IntegrationTestCase;

/**
 * Wahoo-Integration Phase C: eine echte ELEMNT-ROAM-FIT (ride.fit) läuft durch
 * ingestWorkout → private Route (source=wahoo), korrekt datiert, mit persistierten
 * Sensor-Aggregaten. Idempotenz beim zweiten Aufruf.
 */
final class WahooIngestTest extends IntegrationTestCase
{
    private WahooService $wahoo;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::instance();
        $routes = new RouteService(
            new RouteRepository(),
            new RouteStorage($config),
            new GeometryParser(),
            new GeometryStats(),
        );
        // Fixture NICHT eingecheckt (echter GPS-Track, Datenschutz): lokal läuft der
        // Test, in CI wird er übersprungen.
        $ridePath = \dirname(__DIR__) . '/fixtures/wahoo/ride.fit';
        if (!is_file($ridePath)) {
            $this->markTestSkipped('FIT-Fixture ride.fit nicht vorhanden (nur lokal).');
        }
        $fitBytes = (string)file_get_contents($ridePath);
        $this->wahoo = new WahooService(
            new FixtureWahooClient($fitBytes),
            new Crypto(base64_encode(str_repeat("\x0a", 32))),
            '', 'http://localhost/auth/wahoo/callback', true, 'http://localhost',
            $routes,
            new RouteRepository(),
            new FitDecoder(),
        );
        $this->userId = $this->createUser('wahoo-ingest');
        $this->connect();
    }

    private function connect(): void
    {
        $url = $this->wahoo->authorizeUrl($this->userId, 'mobile', 'grava://wahoo-connected');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->wahoo->handleCallback((string)$q['state'], (string)$q['code'], null);
    }

    public function testIngestCreatesDatedRouteWithSensorAggregates(): void
    {
        $res = $this->wahoo->ingestWorkout($this->userId, '9100000001');
        $this->assertSame('imported', $res['status']);

        $uuid = WahooService::workoutUuid('9100000001');
        $stmt = $this->pdo->prepare(
            'SELECT source, visibility, avg_power_w, max_power_w, avg_heart_rate_bpm, avg_cadence_rpm
               FROM routes WHERE user_id = ? AND client_route_uuid = ?'
        );
        $stmt->execute([$this->userId, $uuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Route wurde nicht angelegt');
        $this->assertSame('wahoo', $row['source']);
        $this->assertSame('private', $row['visibility']);
        // Sensor-Aggregate aus der FIT-Session.
        $this->assertSame(126, (int)$row['avg_power_w']);
        $this->assertSame(728, (int)$row['max_power_w']);
        $this->assertSame(127, (int)$row['avg_heart_rate_bpm']);
        $this->assertSame(72, (int)$row['avg_cadence_rpm']);
    }

    public function testIngestIsIdempotent(): void
    {
        $this->assertSame('imported', $this->wahoo->ingestWorkout($this->userId, '9100000001')['status']);
        $second = $this->wahoo->ingestWorkout($this->userId, '9100000001');
        $this->assertSame('skipped', $second['status']);
        $this->assertSame('already_imported', $second['reason'] ?? null);

        $c = $this->pdo->prepare('SELECT COUNT(*) FROM routes WHERE user_id = ? AND client_route_uuid = ?');
        $c->execute([$this->userId, WahooService::workoutUuid('9100000001')]);
        $this->assertSame(1, (int)$c->fetchColumn());
    }
}

/**
 * Test-Client: liefert für jeden Workout-Download die übergebenen FIT-Bytes
 * (echte ride.fit). Der Fake-Client des Dev-Seams gibt nur Platzhalter zurück.
 */
final class FixtureWahooClient implements WahooClient
{
    public function __construct(private readonly string $fitBytes) {}

    public function exchangeCode(string $code): array
    {
        return [
            'access_token' => 'tok', 'refresh_token' => 'ref',
            'expires_at' => time() + 7200, 'wahoo_user_id' => '91000001',
            'scope' => 'user_read workouts_read offline_data',
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        return ['access_token' => 'tok2', 'refresh_token' => $refreshToken, 'expires_at' => time() + 7200];
    }

    public function listWorkouts(string $accessToken, int $perPage = 30, int $page = 1): array
    {
        return $page > 1 ? [] : [['id' => '9100000001', 'name' => 'Ride', 'starts' => null, 'workout_type' => 'BIKING']];
    }

    public function getWorkoutSummary(string $accessToken, string $workoutId): array
    {
        return ['fit_file_url' => 'https://fixture/' . $workoutId . '.fit', 'starts' => null];
    }

    public function downloadFit(string $accessToken, string $fitFileUrl): string
    {
        return $this->fitBytes;
    }

    public function deauthorize(string $accessToken): void {}
}
