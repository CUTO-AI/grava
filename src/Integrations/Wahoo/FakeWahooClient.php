<?php
declare(strict_types=1);

namespace App\Integrations\Wahoo;

/**
 * Dev-Seam: deterministischer Fake-Client für Smoke-Tests und lokale Entwicklung
 * ohne Wahoo-Credentials/Approval/Netz — analog {@see \App\Integrations\Strava\FakeStravaClient}.
 *
 * Aktiviert über WAHOO_FAKE=1 (oder automatisch, wenn keine WAHOO_CLIENT_ID
 * gesetzt ist). Liefert zwei feste Workouts: eines mit FIT-Datei (GPS-Fahrt) und
 * eines ohne (für den „skip ohne FIT"-Pfad).
 */
final class FakeWahooClient implements WahooClient
{
    /** Fixtures: Workout-ID → [starts, fit_file_url]. */
    private const WORKOUTS = [
        '9100000001' => ['starts' => '2026-05-01T07:30:00Z', 'fit' => 'https://fake.wahoo/fit/9100000001.fit'],
        '9100000002' => ['starts' => '2026-05-02T18:00:00Z', 'fit' => null],
    ];

    public function exchangeCode(string $code): array
    {
        return [
            'access_token'  => 'fake-wahoo-access-' . substr(sha1($code), 0, 12),
            'refresh_token' => 'fake-wahoo-refresh-token',
            'expires_at'    => time() + 7200,
            'wahoo_user_id' => '91000001',
            'scope'         => 'user_read workouts_read offline_data',
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        return [
            'access_token'  => 'fake-wahoo-access-refreshed',
            'refresh_token' => $refreshToken,
            'expires_at'    => time() + 7200,
        ];
    }

    public function listWorkouts(string $accessToken, int $perPage = 30, int $page = 1): array
    {
        // Fixtures liegen komplett auf Seite 1; Folgeseiten sind leer, damit der
        // paginierende Import terminiert (wie beim echten Ende der Workout-Liste).
        if ($page > 1) {
            return [];
        }
        return [
            [
                'id'           => '9100000001',
                'name'         => 'Morgendliche Gravel-Runde (Wahoo)',
                'starts'       => self::WORKOUTS['9100000001']['starts'],
                'workout_type' => 'BIKING',
            ],
            [
                'id'           => '9100000002',
                'name'         => 'Indoor Trainer (ohne GPS)',
                'starts'       => self::WORKOUTS['9100000002']['starts'],
                'workout_type' => 'BIKING_INDOOR',
            ],
        ];
    }

    public function getWorkoutSummary(string $accessToken, string $workoutId): array
    {
        $w = self::WORKOUTS[$workoutId] ?? ['starts' => null, 'fit' => null];
        return ['fit_file_url' => $w['fit'], 'starts' => $w['starts']];
    }

    public function downloadFit(string $accessToken, string $fitFileUrl): string
    {
        // Platzhalter-Bytes — das echte FIT-Dekodieren (und dessen Fake) kommt in
        // Phase C. Deterministisch, damit Tests stabil bleiben.
        return 'FAKE-FIT:' . sha1($fitFileUrl);
    }

    public function deauthorize(string $accessToken): void
    {
        // No-op im Fake.
    }
}
