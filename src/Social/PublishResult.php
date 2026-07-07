<?php
declare(strict_types=1);

namespace App\Social;

/** Ergebnis eines Sendeversuchs über einen {@see Publisher}. */
final class PublishResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $dryRun,
        public readonly ?string $externalId,   // Tweet-ID o. Ä.
        public readonly ?string $error,
        public readonly ?string $response,      // gekürzte Rohantwort (Audit)
    ) {}

    public static function ok(?string $externalId, ?string $response = null): self
    {
        return new self(true, false, $externalId, null, $response);
    }

    public static function dryRun(string $note): self
    {
        return new self(true, true, null, null, $note);
    }

    public static function failure(string $error, ?string $response = null): self
    {
        return new self(false, false, null, $error, $response);
    }
}
