<?php
declare(strict_types=1);

namespace App\Social;

/**
 * Aggregierte Tagesaktivität für den automatischen Tagesbericht (Konzept §4/E1).
 * Reines Wertobjekt — wird vom {@see DailyReportCollector} befüllt und von
 * {@see PostCopy} zu einem Tweet gerendert.
 */
final class DailyReport
{
    public function __construct(
        public readonly string $date,          // 'YYYY-MM-DD' (UTC)
        public readonly int $rides,            // heutige App-Fahrten
        public readonly float $distanceKm,     // Summe der Fahrt-Distanz
        public readonly int $edgesTakenOver,   // Kanten, die heute den Besitzer wechselten
        public readonly int $countiesChanged,  // Landkreise (level 6) mit Besitzwechsel heute
        public readonly ?string $rushCrewName, // Crew des stärksten abgeschlossenen Rush (oder null)
        public readonly int $rushEdges,        // dabei eroberte Kanten
    ) {}

    /** Kein einziger Aktivitäts-Datenpunkt → nicht posten (Konzept §5: „kein Event, kein Post"). */
    public function isEmpty(): bool
    {
        return $this->rides === 0
            && $this->edgesTakenOver === 0
            && $this->countiesChanged === 0
            && $this->rushCrewName === null;
    }
}
