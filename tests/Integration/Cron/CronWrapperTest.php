<?php
declare(strict_types=1);

namespace Tests\Integration\Cron;

use App\Auth\TokenService;
use App\Cli\Commands;
use App\Cli\CronRunRepository;
use App\Config\Config;
use App\Routes\GeometryParser;
use App\Routes\GeometryStats;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\RouteStorage;
use Tests\IntegrationTestCase;

/**
 * Der Recording-Wrapper in {@see Commands::run()}: nur bekannte Cron-Befehle
 * werden protokolliert, Fehler landen als `failed` (mit Output-Tail), Aliase
 * werden auf den kanonischen Namen normalisiert, und der Output wird weiterhin
 * ausgegeben.
 */
final class CronWrapperTest extends IntegrationTestCase
{
    private function makeCommands(CronRunRepository $repo): Commands
    {
        $config = Config::instance();
        return new Commands(
            basePath: dirname(__DIR__, 3),
            tokens: new TokenService($config),
            routes: new RouteService(
                new RouteRepository(),
                new RouteStorage($config),
                new GeometryParser(),
                new GeometryStats(),
            ),
            config: $config,
            cronRuns: $repo,
            triggerKind: 'cron',
        );
        // gameDispatcher/gameHistory bleiben null → die Handler geben exit 1 zurück.
    }

    /** Führt run() aus und schluckt den (absichtlich weiter ausgegebenen) stdout. */
    private function runCommand(Commands $cli, string $command): array
    {
        ob_start();
        $code = $cli->run(['cli', $command]);
        $out = (string)ob_get_clean();
        return [$code, $out];
    }

    public function testUnknownCommandIsNotRecorded(): void
    {
        $repo = new CronRunRepository($this->pdo);
        $this->runCommand($this->makeCommands($repo), 'help');
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM cron_runs')->fetchColumn());
    }

    public function testFailedRunIsRecordedWithOutput(): void
    {
        $repo = new CronRunRepository($this->pdo);
        // gameDispatcher ist null → notifyDispatch() echo + return 1.
        [$code, $out] = $this->runCommand($this->makeCommands($repo), 'game:notify-dispatch');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('nicht verfügbar', $out);   // stdout weiterhin ausgegeben

        $row = $repo->history('game:notify-dispatch', 10, 0)[0];
        $this->assertSame('failed', $row['status']);
        $this->assertSame(1, (int)$row['exit_code']);
        $this->assertStringContainsString('nicht verfügbar', (string)$row['output_tail']);
    }

    public function testAliasIsNormalizedToCanonical(): void
    {
        $repo = new CronRunRepository($this->pdo);
        // Alias 'cron:game-snapshot' → kanonisch 'game:snapshot-daily'.
        $this->runCommand($this->makeCommands($repo), 'cron:game-snapshot');

        $this->assertSame(0, $repo->historyCount('cron:game-snapshot'));
        $this->assertSame(1, $repo->historyCount('game:snapshot-daily'));
    }
}
