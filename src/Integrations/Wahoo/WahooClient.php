<?php
declare(strict_types=1);

namespace App\Integrations\Wahoo;

/**
 * Dev-Seam: kapselt alle HTTP-Calls zur Wahoo Cloud API hinter einem Interface —
 * analog {@see \App\Integrations\Strava\StravaClient}. {@see RealWahooClient}
 * spricht das echte API (Phase B), {@see FakeWahooClient} liefert Fixtures, sodass
 * der komplette Import-Pfad ohne Netz/Credentials/Approval smoke-testbar ist.
 *
 * Alle Token-Werte sind Klartext; Verschlüsselung passiert eine Ebene höher im
 * WahooService. Da die Integration **Import-only** ist, gibt es keine Upload-/
 * Share-Methoden (kein `workouts_write`).
 *
 * Datenformat: Wahoo liefert Fahrten als **FIT-Datei** (nicht lat/lng-JSON wie
 * Strava). Der Client lädt die rohe FIT herunter; das Dekodieren übernimmt der
 * WahooService via FIT-Decoder (Phase C).
 */
interface WahooClient
{
    /**
     * Tauscht einen Authorization-Code gegen Tokens + Wahoo-User-Info.
     *
     * @return array{
     *   access_token:string, refresh_token:string, expires_at:int,
     *   wahoo_user_id:string, scope:?string
     * }
     */
    public function exchangeCode(string $code): array;

    /**
     * Erneuert ein abgelaufenes Access-Token (Wahoo-Token laufen nach ~2 h ab).
     *
     * @return array{access_token:string, refresh_token:string, expires_at:int}
     */
    public function refreshToken(string $refreshToken): array;

    /**
     * Listet eine Seite der Workouts des verbundenen Users (neueste zuerst).
     * Liefert ein leeres Array, sobald keine weiteren Workouts existieren (Ende
     * der Paginierung) — für den manuellen Pull-Import.
     *
     * @return list<array{id:string, name:?string, starts:?string, workout_type:?string}>
     */
    public function listWorkouts(string $accessToken, int $perPage = 30, int $page = 1): array;

    /**
     * Liefert die Workout-Zusammenfassung inkl. Verweis auf die FIT-Datei.
     * `fit_file_url` ist null, wenn das Workout keine herunterladbare FIT hat
     * (z. B. Indoor ohne GPS) → der Aufrufer überspringt es.
     *
     * @return array{fit_file_url:?string, starts:?string}
     */
    public function getWorkoutSummary(string $accessToken, string $workoutId): array;

    /**
     * Lädt die rohe FIT-Datei herunter (Bytes). Die URL stammt aus der
     * Workout-Zusammenfassung bzw. dem Webhook-Payload (`file.url`).
     */
    public function downloadFit(string $accessToken, string $fitFileUrl): string;

    /**
     * Widerruft die Autorisierung serverseitig bei Wahoo (Deauthorize), damit beim
     * Trennen keine verwaisten Tokens/Webhooks zurückbleiben.
     */
    public function deauthorize(string $accessToken): void;
}
