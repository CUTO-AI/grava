<?php
declare(strict_types=1);

namespace App\Social;

/**
 * Trocken-Adapter (Konzept §9/A: `social:preview`). Sendet nichts, sondern
 * meldet Erfolg als Dry-Run — für Vorschau und für den Betrieb, solange
 * SOCIAL_DRY_RUN=1 gesetzt ist oder noch keine X-Credentials vorliegen.
 */
final class NullPublisher implements Publisher
{
    public function __construct(private readonly string $channel = 'twitter') {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function publish(string $text): PublishResult
    {
        return PublishResult::dryRun('dry-run: not sent (' . mb_strlen($text) . ' chars)');
    }
}
