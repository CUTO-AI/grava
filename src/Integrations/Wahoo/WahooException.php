<?php
declare(strict_types=1);

namespace App\Integrations\Wahoo;

use RuntimeException;

/**
 * Exception für die Wahoo-Integration (errorCode + httpStatus) — analog
 * {@see \App\Integrations\Strava\StravaException}.
 */
final class WahooException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
