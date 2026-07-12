<?php
declare(strict_types=1);

namespace App\Game;

use RuntimeException;

/**
 * Wird geworfen, wenn ein Ingest-Job laufen soll, die Route aber inzwischen
 * gelöscht wurde. Eigener Typ, damit der Worker den Job als failed mit
 * sprechendem error_code (route_gone) markieren kann, statt einen 500-Pfad
 * zu triggern.
 */
final class IngestRouteGoneException extends RuntimeException
{
}
