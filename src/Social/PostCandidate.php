<?php
declare(strict_types=1);

namespace App\Social;

/**
 * Ein fertiger Post-Kandidat, wie ihn ein {@see PostSource} liefert und der
 * {@see SocialService} in die Redaktions-Queue schreibt. Der Text ist bereits
 * gerendert (sprach-keyed, ≤280); die Rohdaten stecken zum Audit in $payload.
 */
final class PostCandidate
{
    public function __construct(
        public readonly string $kind,        // 'daily_report' | 'region_taken' | 'rush_result' | 'faction_standing'
        public readonly string $dedupeKey,   // idempotenter Schlüssel (unique in social_post_queue)
        public readonly int $score,          // Newsworthiness (Konzept §5)
        public readonly string $body,        // fertiger Post-Text
        public readonly ?string $payloadJson, // strukturierte Rohdaten (JSON) oder null
    ) {}
}
