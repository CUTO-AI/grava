<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;

/**
 * Zeitlicher Verlauf der eigenen Revier-Kennzahlen (GameHistory_Backend_Spec.md).
 *
 * **Voll-Rebuild aus Fahrdaten** statt Vorwärts-Append: sowohl der Cron
 * (`game:snapshot-daily`) als auch der Lese-Pfad rekonstruieren die komplette Serie
 * eines Claimants aus den Erwerbs-/Erschließungs-Daten der HEUTE gehaltenen bzw.
 * erschlossenen Kanten (`GameRepository::edgeAcquisitionDates`) und ersetzen die
 * Tabelle atomar. „Gehalten" wird nach dem **Fahrdatum** der eigenen Vorbeifahrt
 * datiert (nicht nach `owner_since`) — sonst legt ein Batch-Import historischer
 * Fahrten (z. B. Strava) alle Kanten auf den Import-Tag und der Chart springt als
 * Stufe (z. B. 10.000 → 35.000 an einem Tag). Der Rebuild ist idempotent und heilt
 * bestehende Stufen beim nächsten Lauf rückwirkend.
 *
 * Näherung: seither verlorene Kanten fehlen (die Kurve des heute gehaltenen Reviers
 * ist monoton steigend); Pionier ist exakt (Erstbefahrer bleibt es dauerhaft).
 *
 * Der Lese-Pfad (`history`) aggregiert nur die Tabelle, ohne Recompute.
 */
final class GameHistoryService
{
    public function __construct(private readonly GameRepository $repo) {}

    /**
     * Verlaufspunkte eines Claimants im Fenster der letzten `$days` Tage.
     * @return array{points:list<array{date:string,held_edges:int,pioneered_edges:int,held_length_m:float}>}
     */
    public function history(int $claimantId, int $days): array
    {
        $since = Clock::nowUtc()->modify("-{$days} days")->format('Y-m-d');
        $points = [];
        foreach ($this->repo->dailySnapshots($claimantId, $since) as $r) {
            $points[] = [
                'date'            => $r['snapshot_date'],
                'held_edges'      => $r['held_edges'],
                'pioneered_edges' => $r['pioneered_edges'],
                'held_length_m'   => $r['held_length_m'],
            ];
        }
        return ['points' => $points];
    }

    /**
     * Self-Heal auf dem Lese-Pfad: baut die Historie des Claimants aus den Fahrdaten
     * neu auf — wichtig auf Hostern OHNE täglichen Cron (der Chart korrigiert sich
     * dann allein durchs App-Öffnen) und sorgt dafür, dass eine durch einen Import
     * entstandene Stufe sofort geglättet ist. Idempotent.
     */
    public function ensureTodaySnapshot(int $claimantId): void
    {
        $this->rebuild($claimantId);
    }

    /**
     * Tages-Snapshot über alle aktiven Claimants (Cron): baut jede Historie aus den
     * Fahrdaten neu auf (Voll-Rebuild, idempotent). `$today` überschreibbar für
     * Tests; sonst UTC-heute — Ankerpunkt, bis zu dem die Kurve verlängert wird.
     * @return array{claimants:int,rebuilt:int,date:string}
     */
    public function snapshotAll(?string $today = null): array
    {
        $date = $today ?? Clock::nowUtc()->format('Y-m-d');
        $holdings = $this->repo->allClaimantHoldings();
        foreach (array_keys($holdings) as $claimantId) {
            $this->rebuild((int)$claimantId, $date);
        }
        return ['claimants' => count($holdings), 'rebuilt' => count($holdings), 'date' => $date];
    }

    /**
     * Rekonstruiert die komplette Serie eines Claimants aus den Erwerbs-/Erschließungs-
     * Daten der aktuell gehaltenen/erschlossenen Kanten und ersetzt die Tabelle atomar.
     * Ein Punkt je Kalendertag mit Änderung (der Client interpoliert linear dazwischen);
     * ein Ankerpunkt am `$today` verlängert die Kurve bis heute, falls der letzte
     * Erwerbstag früher liegt. Idempotent.
     * @return int Anzahl geschriebener Punkte
     */
    public function rebuild(int $claimantId, ?string $today = null): int
    {
        $date = $today ?? Clock::nowUtc()->format('Y-m-d');
        $data = $this->repo->edgeAcquisitionDates($claimantId);

        // Deltas je Tag zusammenführen (gehaltene Kanten + Länge, Pionierkanten).
        $heldDelta = [];   // date => count
        $lenDelta  = [];   // date => meters
        $pioDelta  = [];   // date => count
        foreach ($data['held'] as $row) {
            $heldDelta[$row['d']] = ($heldDelta[$row['d']] ?? 0) + 1;
            $lenDelta[$row['d']]  = ($lenDelta[$row['d']] ?? 0.0) + $row['len'];
        }
        foreach ($data['pioneered'] as $d) {
            $pioDelta[$d] = ($pioDelta[$d] ?? 0) + 1;
        }

        $dates = array_keys($heldDelta + $pioDelta);
        sort($dates);

        $points = [];
        $held = 0; $pio = 0; $len = 0.0;
        foreach ($dates as $d) {
            $held += $heldDelta[$d] ?? 0;
            $len  += $lenDelta[$d] ?? 0.0;
            $pio  += $pioDelta[$d] ?? 0;
            $points[] = [
                'date' => (string)$d, 'held' => $held, 'pioneered' => $pio, 'held_length_m' => $len,
            ];
        }

        // Kurve bis heute verlängern: Ankerpunkt mit dem finalen Bestand (= meStats),
        // damit der Chart nicht am letzten Erwerbstag endet. Nur wenn nötig — und
        // nur, wenn der letzte Erwerbstag nicht in der Zukunft liegt (Clock-Skew).
        $lastDate = $points === [] ? null : $points[count($points) - 1]['date'];
        if ($lastDate === null || ($lastDate < $date)) {
            $points[] = [
                'date' => $date, 'held' => $held, 'pioneered' => $pio, 'held_length_m' => $len,
            ];
        }

        $this->repo->replaceDailySnapshots($claimantId, $points);
        return count($points);
    }
}
