<?php
declare(strict_types=1);

namespace App\Social;

/**
 * Eine Meldungs-Quelle („Post-Skill", Konzept §4): baut für einen Tag null bis
 * mehrere Post-Kandidaten aus der Aktivität. Jeder Meldungstyp ist eine eigene
 * Implementierung; der {@see SocialService} sammelt sie alle ein.
 */
interface PostSource
{
    /**
     * @param string $date 'YYYY-MM-DD' (UTC)
     * @return list<PostCandidate>
     */
    public function collect(string $date): array;
}
