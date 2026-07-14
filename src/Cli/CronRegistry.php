<?php
declare(strict_types=1);

namespace App\Cli;

/**
 * Source of Truth der überwachten Cron-Befehle (Cron-Monitoring, Admin).
 *
 * Nur hier gelistete Befehle werden in `cron_runs` protokolliert — Dev-/One-off-
 * Befehle (z. B. social:preview, heatmap:rebuild-local) bleiben ungeloggt. Die
 * Alias-Map muss zu den Aliassen im `switch` von {@see Commands::run()} passen,
 * damit je realem Job genau EINE kanonische Zeile entsteht.
 */
final class CronRegistry
{
    /**
     * kanonisch => [label, interval_s (erwarteter Abstand), max_runtime_s (Stuck-Schwelle)]
     *
     * @var array<string,array{0:string,1:int,2:int}>
     */
    private const JOBS = [
        'game:ingest-run'           => ['Ingest-Worker',            60,    300],
        'game:broadcast-run'        => ['Broadcast-Worker',         60,    600],
        'game:notify-dispatch'      => ['Push-Dispatch',            600,   120],
        'regions:backfill'          => ['Gebiete-Backfill',        900,   600],
        'game:snapshot-daily'       => ['Tages-Snapshot',          86400, 900],
        'regions:ownership-refresh' => ['Gebiets-Besitz',          86400, 900],
        'game:region-activity-refresh' => ['Gebiets-Aktivität',    86400, 900],
        'cron:cleanup'              => ['Cleanup / Retention',      86400, 600],
        'supporter:snapshot-monthly'=> ['Supporter-Snapshot',       86400, 900],
    ];

    /**
     * Alias (wie auf der CLI getippt) => kanonisch. Synchron zu Commands::run().
     *
     * @var array<string,string>
     */
    private const ALIASES = [
        'cron:game-ingest'       => 'game:ingest-run',
        'cron:region-ownership'  => 'regions:ownership-refresh',
        'cron:game-snapshot'     => 'game:snapshot-daily',
        'cron:region-activity'   => 'game:region-activity-refresh',
        'cleanup'                => 'cron:cleanup',
    ];

    public static function canonical(string $command): string
    {
        return self::ALIASES[$command] ?? $command;
    }

    public static function isKnown(string $canonical): bool
    {
        return isset(self::JOBS[$canonical]);
    }

    /** @return array{label:string,interval_s:int,max_runtime_s:int}|null */
    public static function meta(string $canonical): ?array
    {
        $m = self::JOBS[$canonical] ?? null;
        return $m === null
            ? null
            : ['label' => $m[0], 'interval_s' => $m[1], 'max_runtime_s' => $m[2]];
    }

    /** @return list<string> alle kanonischen Befehle (Reihenfolge = Anzeige) */
    public static function commands(): array
    {
        return array_keys(self::JOBS);
    }
}
