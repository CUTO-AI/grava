<?php
declare(strict_types=1);

namespace App\Social;

/**
 * Kanal-agnostische Sende-Schnittstelle (Konzept §6/E7). X ist der erste
 * Adapter ({@see TwitterPublisher}); weitere (Mastodon/Threads/…) docken an,
 * ohne dass Collector/Copy/Queue sich ändern.
 */
interface Publisher
{
    /** Stabiler Kanal-Schlüssel, wie er in social_post_queue.channel steht. */
    public function channel(): string;

    /** Sendet den fertigen Text. Wirft nie — Fehler stecken im Ergebnis. */
    public function publish(string $text): PublishResult;
}
