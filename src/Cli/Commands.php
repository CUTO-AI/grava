<?php
declare(strict_types=1);

namespace App\Cli;

use App\Auth\TokenService;
use App\Config\Config;
use App\Database\Migrator;
use App\Engagement\NotificationService;
use App\Heatmap\HeatmapService;
use App\Heatmap\HeatmapLinesService;
use App\Routes\RouteService;

final class Commands
{
    public function __construct(
        private readonly string $basePath,
        private readonly TokenService $tokens,
        private readonly RouteService $routes,
        private readonly Config $config,
        private readonly ?NotificationService $notifications = null,
        private readonly ?HeatmapService $heatmap = null,
        private readonly ?HeatmapLinesService $heatmapLines = null,
        private readonly ?\App\Game\GameRecomputeService $gameRecompute = null,
        private readonly ?\App\Game\Rush\RushService $rushService = null,
        private readonly ?\App\Game\Crew\CrewService $crewService = null,
        private readonly ?\App\Game\EdgeRecordBackfillService $edgeBackfill = null,
        private readonly ?\App\Game\GameNotificationDispatcher $gameDispatcher = null,
        private readonly ?\App\Game\GameHistoryService $gameHistory = null,
        private readonly ?\App\Game\RegionImportService $regionImport = null,
        private readonly ?\App\Game\RegionOwnershipService $regionOwnership = null,
        private readonly ?\App\Social\SocialService $social = null,
        private readonly ?\App\Game\IngestJobRepository $ingestJobs = null,
        private readonly ?\App\Game\IngestJobRunner $ingestRunner = null,
        private readonly ?CronRunRepository $cronRuns = null,
        private readonly string $triggerKind = 'cron',
        private readonly ?\App\Game\Admin\BroadcastService $broadcasts = null,
        private readonly ?\App\Game\RegionActivityCacheService $regionActivity = null,
    ) {}

    /**
     * Setzt ein Befehl, wenn er nichts zu tun hatte (Leerlauf-Tick, z. B.
     * game:ingest-run mit leerer Queue). Der Recording-Wrapper fasst solche
     * Läufe zu einer Heartbeat-Zeile/Tag zusammen (Cron-Monitoring).
     */
    private ?bool $didWork = null;
    private function markIdle(): void { $this->didWork = false; }

    /**
     * Recording-Wrapper um {@see dispatch()} — der einzige Choke Point für alle
     * CLI-Befehle. Für in der {@see CronRegistry} bekannte Cron-Befehle wird der
     * Lauf in `cron_runs` protokolliert (Start/Ende/Status/Dauer/Output-Tail).
     * Output wird gepuffert UND wieder ausgegeben (crontab/Terminal unverändert);
     * Aufzeichnungsfehler dürfen den Befehl nie brechen.
     */
    public function run(array $argv): int
    {
        $raw = $argv[1] ?? 'help';
        $canonical = CronRegistry::canonical($raw);

        if ($this->cronRuns === null || !CronRegistry::isKnown($canonical)) {
            return $this->dispatch($argv);   // Dev-/One-off-Befehl: ungeloggt, unverändert
        }

        $host = (string)(gethostname() ?: 'unknown');
        $t0 = microtime(true);
        $this->didWork = null;

        $runId = null;
        try { $runId = $this->cronRuns->begin($canonical, $this->triggerKind, $host); }
        catch (\Throwable $e) { error_log('cron_runs begin failed: ' . $e->getMessage()); }

        ob_start();
        $code = 0; $error = null; $threw = null;
        try {
            $code = $this->dispatch($argv);
        } catch (\Throwable $e) {
            $threw = $e; $code = 1; $error = $e->getMessage();
        }
        $output = (string)ob_get_clean();
        echo $output;   // stdout-Verhalten exakt erhalten

        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        $status = ($threw !== null || $code !== 0) ? 'failed' : 'ok';
        $didWork = $this->didWork ?? true;
        $tail = $output === '' ? null : substr($output, -8192);

        try {
            // Defensiv: eine vom Befehl offen gelassene Transaktion würde den
            // Finish-Write gefährden.
            if (\App\Database\Db::pdo()->inTransaction()) {
                \App\Database\Db::pdo()->rollBack();
            }
            if ($status === 'ok' && !$didWork) {
                if ($runId !== null) { $this->cronRuns->deleteById($runId); }
                $this->cronRuns->recordIdle($canonical, $host, $durationMs);
            } elseif ($runId !== null) {
                $this->cronRuns->finish($runId, $status, $code, $durationMs, $tail, $error, $didWork);
            } else {
                $this->cronRuns->insertCompleted($canonical, $status, $code, $this->triggerKind, $host, $durationMs, $didWork, $tail, $error);
            }
        } catch (\Throwable $e) {
            error_log('cron_runs finish failed: ' . $e->getMessage());
        }

        if ($threw !== null) {
            error_log("command {$canonical} threw: " . $threw->getMessage());
        }
        return $code;
    }

    /** Befehls-Dispatch (bisheriger run()-Rumpf). */
    private function dispatch(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        switch ($command) {
            case 'cli:migrate':
            case 'migrate':
                return $this->migrate();

            case 'cron:cleanup':
            case 'cleanup':
                return $this->cleanup();

            case 'cron:heatmap':
            case 'heatmap':
                return $this->rebuildHeatmap();

            case 'cron:heatmap-lines':
            case 'heatmap-lines':
                return $this->rebuildHeatmapLines();

            case 'heatmap:manifest':
                return $this->heatmapManifest();

            case 'heatmap:rebuild-local':
                return $this->rebuildHeatmapLinesLocal($argv);

            case 'heatmap:export-edges':
                return $this->exportHeatmapEdges($argv);

            case 'game:recompute':
                return $this->recomputeGame($argv);

            case 'game:rush-tick':
                return $this->rushTick();

            case 'game:heal-crews':
                return $this->healCrews();
            case 'crews:verify-invite':
                return $this->crewsVerifyInvite($argv);

            case 'game:backfill-speed':
                return $this->backfillSpeed($argv);

            case 'game:notify-dispatch':
                return $this->notifyDispatch();

            case 'cron:game-ingest':
            case 'game:ingest-run':
                return $this->ingestRun($argv);

            case 'game:broadcast-run':
                return $this->broadcastRun($argv);

            case 'cron:game-snapshot':
            case 'game:snapshot-daily':
                return $this->gameSnapshotDaily();

            case 'supporter:snapshot-monthly':
                return $this->supporterSnapshot($argv);

            case 'regions:import':
                return $this->regionsImport($argv);

            case 'regions:backfill':
                return $this->regionsBackfill($argv);

            case 'regions:relink':
                return $this->regionsRelink();

            case 'regions:recorrect':
                return $this->regionsRecorrect($argv);

            case 'regions:add-osm':
                return $this->regionsAddOsm($argv);

            case 'cron:region-ownership':
            case 'regions:ownership-refresh':
                return $this->regionsOwnershipRefresh();

            case 'cron:region-activity':
            case 'game:region-activity-refresh':
                return $this->regionActivityRefresh();

            case 'regions:push':
                return $this->regionsPush($argv);

            case 'game:test-push':
                return $this->gameTestPush($argv);

            case 'cron:social-collect':
            case 'social:collect':
                return $this->socialCollect($argv);

            case 'cron:social-publish':
            case 'social:publish':
                return $this->socialPublish();

            case 'social:preview':
                return $this->socialPreview($argv);

            case 'social:status':
                return $this->socialStatus();

            case 'social:card':
                return $this->socialCard($argv);

            case 'social:doctor':
                return $this->socialDoctor();

            case 'social:ig-setup':
                return $this->socialIgSetup($argv);

            case 'internal:logtail':
            case 'logtail':
                return $this->logTail($argv);

            case 'internal:apns-check':
            case 'apns-check':
                return $this->apnsCheck();

            case 'user:verify':
                return $this->verifyUser($argv);

            case 'game:edge-inspect':
                return $this->inspectEdge($argv);

            case 'help':
            default:
                $this->help();
                return 0;
        }
    }

    private function migrate(): int
    {
        $migrator = new Migrator($this->basePath . '/migrations');
        $applied = $migrator->migrate();
        if (empty($applied)) {
            echo "Keine ausstehenden Migrationen.\n";
            return 0;
        }
        foreach ($applied as $name) {
            echo "Migriert: {$name}\n";
        }
        return 0;
    }

    private function cleanup(): int
    {
        // 1) Token-/Session-/Rate-Limit-Cleanup (M1).
        $tokenRes = $this->tokens->cleanup();

        // 2) M2 Phase 7: hard-delete soft-deleted routes nach Karenz.
        //    Default 30 Tage — kann via .env überstimmt werden.
        $graceDays  = $this->config->int('ROUTES_SOFT_DELETE_GRACE_DAYS', 30);
        $routesRes  = $this->routes->purgeSoftDeleted($graceDays);

        // 3) M4c: gelesene Notifications nach Karenz entfernen (Default 90 Tage).
        $notifDays  = $this->config->int('NOTIFICATIONS_READ_GRACE_DAYS', 90);
        $notifPurged = $this->notifications?->purgeOldRead($notifDays) ?? 0;

        // 4) M4e: verwaiste OAuth-States (abgebrochene Connects) entfernen.
        //    Werden bei erfolgreichem Callback single-use konsumiert;
        //    der Rest ist nach einer Stunde sicher tot.
        $statesPurged = 0;
        try {
            $stmt = \App\Database\Db::pdo()->prepare(
                'DELETE FROM oauth_states WHERE created_at <= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)'
            );
            $stmt->execute();
            $statesPurged = $stmt->rowCount();
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), '1146')) {
                throw $e;
            }
        }

        $merged = [];
        foreach ($tokenRes as $k => $v) {
            $merged[$k] = $v;
        }
        foreach ($routesRes as $k => $v) {
            $merged['routes_' . $k] = $v;
        }
        // 5) M4f: Heatmap-Grid aus public Routen neu aggregieren.
        $heatmapCells = $this->heatmap?->rebuild() ?? 0;

        $merged['notifications_purged'] = $notifPurged;
        $merged['oauth_states_purged']  = $statesPurged;
        $merged['heatmap_cells']        = $heatmapCells;

        // 6) Cron-Monitoring: hängende Läufe reifen lassen + alte Zeilen prunen.
        if ($this->cronRuns !== null) {
            $maxByCommand = [];
            foreach (CronRegistry::commands() as $cmd) {
                $maxByCommand[$cmd] = CronRegistry::meta($cmd)['max_runtime_s'] ?? 900;
            }
            $merged['cron_stuck_marked'] = $this->cronRuns->sweepStuck(
                $maxByCommand, $this->config->int('CRON_STUCK_DEFAULT_S', 900),
            );
            $merged['cron_runs_pruned'] = $this->cronRuns->pruneOlderThan(
                $this->config->int('CRON_RUNS_RETENTION_DAYS', 14),
            );
        }

        echo "Cleanup abgeschlossen:\n";
        foreach ($merged as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        // L12: Auch ins Logfile schreiben — sonst sieht ein Operator
        // den Cron-Output nur, wenn er stdout in der crontab-Zeile
        // explizit umlenkt (`>> /var/log/...`). So bleibt zumindest
        // ein Eintrag pro Cleanup-Run im PHP-Errorlog.
        $summary = implode(', ', array_map(
            static fn($k, $v) => "{$k}={$v}",
            array_keys($merged),
            array_values($merged),
        ));
        error_log("cron:cleanup [{$summary}]");
        return 0;
    }

    /** @param list<string> $argv */
    private function recomputeGame(array $argv): int
    {
        if ($this->gameRecompute === null) {
            echo "GameRecomputeService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $bbox = trim((string)($opts['bbox'] ?? ''));
        if ($bbox !== '') {
            $parts = array_map('trim', explode(',', $bbox));
            if (count($parts) !== 4 || array_filter($parts, static fn($p) => !is_numeric($p)) !== []) {
                echo "Nutzung: game:recompute --bbox=minLon,minLat,maxLon,maxLat\n";
                return 1;
            }
            [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
            $n = $this->gameRecompute->recomputeBbox($minLon, $minLat, $maxLon, $maxLat);
            echo "Spiel-Region neu berechnet: {$n} Kanten.\n";
            return 0;
        }
        $n = $this->gameRecompute->recomputeAll();
        echo "Spiel neu berechnet: {$n} Kanten.\n";
        return 0;
    }

    /**
     * Rush-Statuszeit (§4): überführt fällige Rushes (planned→active,
     * active→completed/expired), rechnet betroffene Kanten neu und stößt
     * rush_result-Push an. Für Cron (z. B. minütlich) + /internal-Endpoint.
     */
    private function rushTick(): int
    {
        if ($this->rushService === null) {
            echo "RushService nicht verfügbar.\n";
            return 1;
        }
        $res = $this->rushService->tick();
        echo "Rush-Tick: aktiviert={$res['activated']}, abgeschlossen={$res['completed']}, verfallen={$res['expired']}.\n";
        return 0;
    }

    /**
     * Datencheck + Self-Healing (§12.1): findet nicht-leere Crews ohne gültigen
     * Captain (Altbestand) und promotet das älteste Mitglied. Idempotent.
     */
    private function healCrews(): int
    {
        if ($this->crewService === null) {
            echo "CrewService nicht verfügbar.\n";
            return 1;
        }
        $healed = $this->crewService->healCaptainlessCrews();
        if ($healed === []) {
            echo "Keine captain-losen Crews gefunden.\n";
            return 0;
        }
        foreach ($healed as $h) {
            echo "Crew '{$h['slug']}': Captain → User #{$h['promoted_user_id']}.\n";
        }
        echo 'Geheilt: ' . count($healed) . " Crew(s).\n";
        return 0;
    }

    /**
     * Supporter-Ökonomie-Snapshot (A8, Supporter_Economy_Spec.md): rechnet Basis/
     * Champion/Bonus je Landkreis für die Periode und schreibt den Snapshot. No-op,
     * solange `supporter_program_enabled=0`. KEINE Auszahlung.
     *
     * @param list<string> $argv
     */
    private function supporterSnapshot(array $argv): int
    {
        $opts = $this->parseOptions($argv);
        $period = trim((string)($opts['period'] ?? ''));
        if ($period === '' || preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            $period = gmdate('Y-m');
        }
        $pdo = \App\Database\Db::pdo();
        $svc = new \App\Growth\SupporterAccountingService(
            $pdo,
            new \App\Game\GameConfig($pdo),
            new \App\Game\RegionRepository($pdo),
        );
        $res = $svc->computeAndStore($period);
        if (!$res['enabled']) {
            echo "Supporter-Programm deaktiviert (supporter_program_enabled=0) — nichts berechnet.\n";
            return 0;
        }
        printf(
            "Supporter-Snapshot %s: %d Landkreise, %d Vereins-Zeilen, Basis %.2f €, Bonus %.2f €.\n",
            $res['period'], $res['landkreise'], $res['clubs'], $res['basis_ct'] / 100, $res['bonus_ct'] / 100,
        );
        return 0;
    }

    /**
     * Erzeugt ein Vereins-Aktivierungs-Token (verifizierter Vereins-Account,
     * CrewInvite_Onboarding_Spec §8.1). Der Link geht per Mail an den Vorstand.
     *
     * Nutzung: crews:verify-invite --name="RSV Rosenheim" --org="Radsportverein
     *   Rosenheim e.V." [--court="Amtsgericht Traunstein"] [--regno="VR 12345"]
     *   [--charitable=1] [--source=https://rsv-rosenheim.de/impressum]
     *   [--email=vorstand@rsv-rosenheim.de] [--membership=https://.../mitglied-werden]
     *
     * @param list<string> $argv
     */
    private function crewsVerifyInvite(array $argv): int
    {
        if ($this->crewService === null) {
            echo "CrewService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $org  = trim((string)($opts['org'] ?? ''));
        $name = trim((string)($opts['name'] ?? $org));
        if ($org === '') {
            echo "Nutzung: crews:verify-invite --name=\"<Anzeige, <=40>\" --org=\"<voller e.V.-Name>\" [--court= --regno= --charitable=1 --source= --email= --membership=]\n";
            return 1;
        }
        $token = $this->crewService->issueVerifyInvite([
            'display_name'        => $name,
            'org_name'            => $org,
            'register_court'      => isset($opts['court']) ? (string)$opts['court'] : null,
            'register_no'         => isset($opts['regno']) ? (string)$opts['regno'] : null,
            'is_charitable'       => (string)($opts['charitable'] ?? '1') === '1',
            'official_source_url' => isset($opts['source']) ? (string)$opts['source'] : null,
            'contact_email'       => isset($opts['email']) ? (string)$opts['email'] : null,
            'membership_url'      => isset($opts['membership']) ? (string)$opts['membership'] : null,
        ]);
        echo "Aktivierungslink für „{$org}\":\n";
        echo "https://cyberride.world/verein-aktivieren/{$token}\n";
        return 0;
    }

    /**
     * Gibt die letzten Zeilen des PHP-Errorlogs (storage/logs/php.log) auf
     * stdout aus — read-only Diagnose ohne SSH. Wird per /internal/logtail
     * (token-geschützt) ausgelöst, z. B. um einen frischen PDO-Stacktrace
     * (SQLSTATE) nachzuschlagen.
     *
     * @param list<string> $argv
     */
    private function logTail(array $argv): int
    {
        $lines = (int)($argv[2] ?? 200);
        $lines = max(1, min(2000, $lines));
        $file  = $this->basePath . '/storage/logs/php.log';
        if (!is_file($file)) {
            echo "Kein Logfile vorhanden: {$file}\n";
            return 0;
        }
        // Effizient: nur das Dateiende lesen (bis ~512 KB), dann die
        // letzten N Zeilen ausschneiden. So bleibt der Endpoint auch bei
        // großen Logs bezahlbar.
        $maxBytes = 512 * 1024;
        $size     = (int)filesize($file);
        $fh       = fopen($file, 'rb');
        if ($fh === false) {
            echo "Logfile nicht lesbar: {$file}\n";
            return 1;
        }
        if ($size > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
            fgets($fh); // angeschnittene erste Zeile verwerfen
        }
        $content = (string)stream_get_contents($fh);
        fclose($fh);

        $all  = preg_split("/\r\n|\n|\r/", rtrim($content, "\r\n"));
        $all  = $all === false ? [] : $all;
        $tail = array_slice($all, -$lines);
        echo "--- letzte " . count($tail) . " Zeilen aus storage/logs/php.log ---\n";
        echo implode("\n", $tail) . "\n";
        return 0;
    }

    private function rebuildHeatmap(): int
    {
        if ($this->heatmap === null) {
            echo "HeatmapService nicht verfügbar.\n";
            return 1;
        }
        $cells = $this->heatmap->rebuild();
        echo "Heatmap neu aggregiert: {$cells} Zellen.\n";
        return 0;
    }

    private function rebuildHeatmapLines(): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $res = $this->heatmapLines->rebuild();
        echo "Heatmap-Linien neu gematcht:\n";
        foreach ($res as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        return 0;
    }

    /**
     * Cutover-Hinweg (Modell A), PROD-seitig: gibt das Manifest der public
     * Routen als JSON auf stdout aus. Wird per /internal/heatmap/manifest
     * ausgelöst; der lokale `pull_prod_routes.sh` holt es per curl.
     */
    private function heatmapManifest(): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $routes = $this->heatmapLines->publicManifest();
        echo json_encode([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'count'        => count($routes),
            'routes'       => $routes,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return 0;
    }

    /**
     * Cutover-Hinweg (Modell A), LOKAL: matcht die per SFTP geholten
     * Prod-Payloads gegen die lokale Valhalla und füllt heatmap_edges — ohne
     * dass die Prod-DB lokal vorliegen muss.
     *
     *   php public/index.php heatmap:rebuild-local \
     *       --manifest=build/heatmap_manifest.json --routes-dir=build/prod_routes
     */
    private function rebuildHeatmapLinesLocal(array $argv): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $manifestPath = (string)($opts['manifest'] ?? '');
        $routesDir    = (string)($opts['routes-dir'] ?? '');
        if ($manifestPath === '' || $routesDir === '') {
            echo "Nutzung: heatmap:rebuild-local --manifest=<datei.json> --routes-dir=<verzeichnis>\n";
            return 1;
        }
        if (!is_file($manifestPath)) {
            echo "Manifest nicht gefunden: {$manifestPath}\n";
            return 1;
        }
        $raw = (string)@file_get_contents($manifestPath);
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            echo "Manifest ist kein gültiges JSON: {$e->getMessage()}\n";
            return 1;
        }
        $entries = is_array($data['routes'] ?? null) ? $data['routes'] : [];
        if ($entries === []) {
            echo "Manifest enthält keine Routen.\n";
            return 1;
        }

        $res = $this->heatmapLines->rebuildFromManifest($entries, $routesDir);
        echo "Heatmap-Linien lokal aus Manifest gematcht:\n";
        foreach ($res as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        return 0;
    }

    /**
     * Cutover-Rückweg, LOKAL: schreibt die lokal berechneten heatmap_edges als
     * JSON nach --out. Diese Datei wird per scripts/push_heatmap_edges.sh an
     * /internal/heatmap/import auf PROD gepostet.
     *
     *   php public/index.php heatmap:export-edges --out=build/heatmap_edges.json
     */
    private function exportHeatmapEdges(array $argv): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $out  = (string)($opts['out'] ?? '');
        if ($out === '') {
            echo "Nutzung: heatmap:export-edges --out=<datei.json>\n";
            return 1;
        }
        $rows = $this->heatmapLines->exportRows();
        $json = json_encode([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'count'        => count($rows),
            'rows'         => $rows,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $dir = dirname($out);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@file_put_contents($out, $json) === false) {
            echo "Konnte nicht schreiben: {$out}\n";
            return 1;
        }
        echo "Export: " . count($rows) . " Kanten -> {$out}\n";
        return 0;
    }

    /**
     * APNs-Diagnose ohne SSH: prüft, ob der Server den .p8-Key tatsächlich
     * lesen und daraus ein Provider-JWT erzeugen kann. Gibt NIEMALS den Key
     * oder das JWT aus — nur Status-Flags. Pfad-Auflösung identisch zu
     * public/index.php (absolut oder relativ zum Projekt).
     */
    private function apnsCheck(): int
    {
        $enabled  = $this->config->bool('APNS_ENABLED', false);
        $keyId    = (string)($this->config->get('APNS_KEY_ID', '') ?? '');
        $teamId   = (string)($this->config->get('APNS_TEAM_ID', '98JR57G9M7') ?? '');
        $bundleId = (string)($this->config->get('APNS_BUNDLE_ID', 'world.grava.app') ?? '');
        $keyPath  = (string)($this->config->get('APNS_KEY_PATH', '') ?? '');

        $resolved = $keyPath === ''
            ? ''
            : (str_starts_with($keyPath, '/') ? $keyPath : $this->basePath . '/' . $keyPath);

        $exists   = $resolved !== '' && @is_file($resolved);
        $readable = $exists && @is_readable($resolved);
        $size     = $readable ? (int)@filesize($resolved) : 0;
        $pem      = $readable ? (string)(@file_get_contents($resolved) ?: '') : '';
        $looksPem = $pem !== '' && str_contains($pem, 'BEGIN PRIVATE KEY');

        $jwtOk = false;
        $jwtErr = null;
        if ($looksPem && $keyId !== '' && $teamId !== '') {
            try {
                \App\Push\ApnsJwt::provider($pem, $keyId, $teamId, time());
                $jwtOk = true;
            } catch (\Throwable $e) {
                $jwtErr = $e->getMessage();
            }
        }

        $usable = $enabled && $keyId !== '' && $teamId !== '' && $bundleId !== '' && $pem !== '';

        echo json_encode([
            'apns_enabled'   => $enabled,
            'key_id_set'     => $keyId !== '',
            'team_id'        => $teamId,
            'bundle_id'      => $bundleId,
            'key_path'       => $keyPath,
            'resolved_path'  => $resolved,
            'file_exists'    => $exists,
            'is_readable'    => $readable,
            'file_size'      => $size,
            'looks_like_p8'  => $looksPem,
            'jwt_mint_ok'    => $jwtOk,
            'jwt_error'      => $jwtErr,
            'config_usable'  => $usable,
            'verdict'        => $usable && $jwtOk
                ? 'OK — Push ist versandbereit.'
                : 'NICHT versandbereit — siehe Flags.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "\n";
        return $usable && $jwtOk ? 0 : 1;
    }

    /**
     * Parst `--key=value`-Optionen aus argv (ab Index 2, hinter dem Befehl).
     *
     * @param list<string> $argv
     * @return array<string,string>
     */
    private function parseOptions(array $argv): array
    {
        $opts = [];
        foreach (array_slice($argv, 2) as $arg) {
            if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', (string)$arg, $m)) {
                $opts[$m[1]] = $m[2];
            }
        }
        return $opts;
    }

    /**
     * Spiel-Push-Zustellung (GAME_PUSH_BACKEND.md): verarbeitet den
     * Ereignis-Strom (game_event) zu Inbox-Mitteilungen + APNs, gebündelt über
     * das Digest-Zeitfenster. Für Cron (z. B. minütlich) + /internal-Endpoint.
     */
    private function notifyDispatch(): int
    {
        if ($this->gameDispatcher === null) {
            echo "GameNotificationDispatcher nicht verfügbar.\n";
            return 1;
        }
        $sent = $this->gameDispatcher->dispatch(\App\Support\Clock::nowUtc());
        echo "Spiel-Push-Dispatch: {$sent} Mitteilung(en) erzeugt.\n";
        return 0;
    }

    /**
     * Async-Ingest-Worker (Cron game:ingest-run, minütlich). Arbeitet die
     * game_ingest_jobs-Queue ab, bis sie leer ist oder das Job-Budget erreicht
     * ist (Backstop gegen Endlosläufe). Jeder Job wird atomar geklaut
     * (FOR UPDATE SKIP LOCKED) — parallele Läufe stören sich nicht. Das schwere
     * Map-Matching läuft hier, entkoppelt vom Client-Timeout (Migration 0054).
     */
    private function ingestRun(array $argv): int
    {
        if ($this->ingestJobs === null || $this->ingestRunner === null) {
            echo "Async-Ingest-Worker nicht verfügbar (DI).\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $max = max(1, (int)($opts['max'] ?? 20)); // Jobs pro Lauf
        $done = 0; $failed = 0; $processed = 0;
        while ($processed < $max) {
            $job = $this->ingestJobs->claimNext();
            if ($job === null) {
                if ($processed === 0) { $this->markIdle(); }   // Leerlauf-Tick
                break; // Queue leer
            }
            $processed++;
            $jobId = (int)$job['id'];
            try {
                $summary = $this->ingestRunner->run((int)$job['route_id'], (int)$job['user_id']);
                $this->ingestJobs->markDone(
                    $jobId,
                    (string)json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );
                $done++;
            } catch (\App\Game\MatchUnavailableException $e) {
                // Routing-Engine down → failed; die App darf später erneut aufnehmen.
                $this->ingestJobs->markFailed($jobId, 'routing_unavailable', $e->getMessage());
                $failed++;
            } catch (\App\Routes\GeometryParseException $e) {
                $this->ingestJobs->markFailed($jobId, 'unprocessable_route', $e->getMessage());
                $failed++;
            } catch (\App\Game\IngestRouteGoneException $e) {
                $this->ingestJobs->markFailed($jobId, 'route_gone', $e->getMessage());
                $failed++;
            } catch (\Throwable $e) {
                $this->ingestJobs->markFailed($jobId, 'error', $e->getMessage());
                error_log("game:ingest-run job {$jobId} fehlgeschlagen: " . $e->getMessage());
                $failed++;
            }
        }
        echo "Async-Ingest: {$done} fertig, {$failed} fehlgeschlagen ({$processed} verarbeitet).\n";
        return 0;
    }

    /**
     * Broadcast-Push-Worker (Cron game:broadcast-run): sendet wartende
     * Broadcasts (Status queued) via APNs, entkoppelt vom Web-Request. Leerlauf
     * wird als Heartbeat zusammengefasst (Cron-Monitoring).
     */
    private function broadcastRun(array $argv): int
    {
        if ($this->broadcasts === null) {
            echo "Broadcast-Worker nicht verfügbar (DI).\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $max = max(1, (int)($opts['max'] ?? 5));
        $done = 0; $sentTotal = 0; $processed = 0;
        while ($processed < $max) {
            $res = $this->broadcasts->runNext();
            if ($res === null) {
                if ($processed === 0) { $this->markIdle(); }
                break;
            }
            $processed++;
            $done++;
            $sentTotal += $res['sent'];
        }
        echo "Broadcast: {$done} gesendet ({$sentTotal} Pushes).\n";
        return 0;
    }

    /**
     * Einmaliger Feldtest (GAME_PUSH_BACKEND.md): erzeugt für einen Empfänger
     * eine echte edge_taken-Mitteilung (Inbox-Eintrag + APNs mit Deep-Link
     * edge_id) — derselbe Zustell-Pfad wie der Dispatcher (notifyGame), ohne
     * auf das Digest-Fenster zu warten. Push hängt am game_takeover-Schalter
     * und an einem registrierten Gerät.
     *
     *   game:test-push --handle=<empfänger> [--actor=<auslöser-handle>] [--edge=<id>]
     *   (alternativ --user=<id> / --actor-id=<id>)
     *
     * @param list<string> $argv
     */
    private function gameTestPush(array $argv): int
    {
        if ($this->notifications === null) {
            echo "NotificationService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $pdo  = \App\Database\Db::pdo();

        $recipientId = $this->resolveUserId($pdo, (string)($opts['handle'] ?? ''), (int)($opts['user'] ?? 0));
        if ($recipientId === 0) {
            echo "Empfänger nicht gefunden. Nutzung: game:test-push --handle=<@handle> | --user=<id> [--actor=<handle>] [--edge=<id>]\n";
            return 1;
        }
        $actorId = $this->resolveUserId($pdo, (string)($opts['actor'] ?? ''), (int)($opts['actor-id'] ?? 0));
        if ($actorId === $recipientId) {
            $actorId = 0; // Self-Notification vermeiden → Digest-/aktorlose Form
        }

        $edgeId = (int)($opts['edge'] ?? 0);
        if ($edgeId <= 0) {
            // Bevorzugt eine vom Empfänger gehaltene Kante → Deep-Link landet im eigenen Revier.
            $stmt = $pdo->prepare(
                'SELECT e.id FROM game_edge e
                   JOIN game_claimant c ON c.id = e.owner_claimant_id
                  WHERE c.user_id = ? ORDER BY e.id LIMIT 1'
            );
            $stmt->execute([$recipientId]);
            $edgeId = (int)($stmt->fetchColumn() ?: 0);
            if ($edgeId <= 0) {
                $edgeId = (int)($pdo->query('SELECT id FROM game_edge ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            }
        }
        if ($edgeId <= 0) {
            echo "Keine Kante gefunden — bitte --edge=<id> angeben.\n";
            return 1;
        }

        $this->notifications->notifyGame($recipientId, $actorId > 0 ? $actorId : null, 'edge_taken', $edgeId, 1);

        echo json_encode([
            'ok'                => true,
            'type'              => 'edge_taken',
            'recipient_user_id' => $recipientId,
            'actor_user_id'     => $actorId > 0 ? $actorId : null,
            'edge_id'           => $edgeId,
            'note'              => 'Inbox-Eintrag erstellt; APNs versandt, falls ein Gerät registriert und die game_takeover-Pref aktiv ist.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    }

    /** Löst eine User-ID aus expliziter ID oder public_handle (mit/ohne @) auf; 0 = nicht gefunden. */
    /**
     * user:verify --email=<email>
     *
     * Markiert ein Konto als E-Mail-verifiziert (und aktiv) — für Betriebs-/
     * Test-Konten (z. B. Apple-Review-Demo), die keinen Zugriff auf das Postfach
     * haben. Nur über den token-geschützten /internal-Endpoint erreichbar.
     */
    private function verifyUser(array $argv): int
    {
        $opts  = $this->parseOptions($argv);
        $email = trim((string)($opts['email'] ?? ''));
        if ($email === '') {
            echo "Nutzung: user:verify --email=<email>\n";
            return 1;
        }
        $pdo = \App\Database\Db::pdo();
        $upd = $pdo->prepare(
            "UPDATE users
                SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()),
                    status = 'active',
                    updated_at = UTC_TIMESTAMP()
              WHERE email = ?"
        );
        $upd->execute([$email]);

        $sel = $pdo->prepare('SELECT id, email, email_verified_at, status FROM users WHERE email = ? LIMIT 1');
        $sel->execute([$email]);
        $user = $sel->fetch(\PDO::FETCH_ASSOC) ?: null;

        echo json_encode([
            'ok'    => $user !== null,
            'email' => $email,
            'user'  => $user,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return $user !== null ? 0 : 1;
    }

    /**
     * Read-only Diagnose einer Spielkante: aktueller Besitzer (Claimant + Handle),
     * Regionszuordnung (`region_id`) und der aggregierte Gebiets-Besitz. Klärt den
     * typischen App-vs-Web-Widerspruch: App zeigt Einzelkanten-Besitz, die Web-Karte
     * zeigt Gebiete erst ab Schwelle — und eine Kante mit `region_id = NULL` zählt
     * gar nicht zum Gebiet. Ändert NICHTS. Nutzung: game:edge-inspect --edge=<id>
     */
    private function inspectEdge(array $argv): int
    {
        $opts   = $this->parseOptions($argv);
        $edgeId = (int)($opts['edge'] ?? 0);
        if ($edgeId <= 0) {
            echo "Nutzung: game:edge-inspect --edge=<id>\n";
            return 1;
        }
        $pdo = \App\Database\Db::pdo();

        $sel = $pdo->prepare(
            'SELECT id, owner_claimant_id, owner_since, region_id, length_m FROM game_edge WHERE id = ? LIMIT 1'
        );
        $sel->execute([$edgeId]);
        $edge = $sel->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($edge === null) {
            echo json_encode(['ok' => false, 'edge_id' => $edgeId, 'error' => 'edge_not_found'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return 1;
        }

        // Besitzer (Claimant → ggf. User-Handle) auflösen.
        $owner = null;
        if ($edge['owner_claimant_id'] !== null) {
            $oc = $pdo->prepare(
                'SELECT c.id AS claimant_id, c.type, c.user_id, u.public_handle, u.display_name
                   FROM game_claimant c
              LEFT JOIN users u ON u.id = c.user_id
                  WHERE c.id = ? LIMIT 1'
            );
            $oc->execute([(int)$edge['owner_claimant_id']]);
            $owner = $oc->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        // Region + aggregierter Gebiets-Besitz (nur wenn die Kante zugeordnet ist).
        $region = null;
        $regionOwnership = null;
        $ownerProgress = null;
        if ($edge['region_id'] !== null) {
            $rid = (int)$edge['region_id'];
            $rg = $pdo->prepare('SELECT id, level, name FROM game_region WHERE id = ? LIMIT 1');
            $rg->execute([$rid]);
            $region = $rg->fetch(\PDO::FETCH_ASSOC) ?: null;

            $ro = $pdo->prepare(
                'SELECT owner_claimant_id, leader_claimant_id, owner_held_length_m, owner_held_edges,
                        total_game_length_m, total_edges, held_fraction, contested, owner_since
                   FROM game_region_ownership WHERE region_id = ? LIMIT 1'
            );
            $ro->execute([$rid]);
            $regionOwnership = $ro->fetch(\PDO::FETCH_ASSOC) ?: null;

            // Wie viel hält der Kanten-Besitzer LIVE in diesem Gebiet (unabhängig vom Cache)?
            if ($edge['owner_claimant_id'] !== null) {
                $op = $pdo->prepare(
                    'SELECT COUNT(*) AS owned_edges, COALESCE(SUM(length_m),0) AS owned_length_m
                       FROM game_edge WHERE region_id = ? AND owner_claimant_id = ?'
                );
                $op->execute([$rid, (int)$edge['owner_claimant_id']]);
                $ownerProgress = $op->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
            $tot = $pdo->prepare(
                'SELECT COUNT(*) AS total_edges, COALESCE(SUM(length_m),0) AS total_length_m
                   FROM game_edge WHERE region_id = ?'
            );
            $tot->execute([$rid]);
            $regionTotals = $tot->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($ownerProgress !== null && $regionTotals !== null) {
                $ownerProgress['total_edges'] = (int)$regionTotals['total_edges'];
                $ownerProgress['total_length_m'] = (float)$regionTotals['total_length_m'];
            }
        }

        $note = $edge['region_id'] === null
            ? 'edge has NO region_id → it counts ZERO toward any region ownership (backfill gap or outside imported region coverage).'
            : 'edge is assigned to a region → region only flips to owned above the level threshold (L8: >=25% length AND >=3 edges; L6: >=30% AND >=15).';

        echo json_encode([
            'ok'               => true,
            'edge_id'          => $edgeId,
            'edge'             => $edge,
            'owner'            => $owner,
            'region'           => $region,
            'region_ownership' => $regionOwnership,
            'owner_progress_in_region' => $ownerProgress,
            'note'             => $note,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        return 0;
    }

    private function resolveUserId(\PDO $pdo, string $handle, int $id): int
    {
        if ($id > 0) {
            return $id;
        }
        $handle = ltrim(trim($handle), '@');
        if ($handle === '') {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE public_handle = ? LIMIT 1');
        $stmt->execute([$handle]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /** @param list<string> $argv */
    /**
     * Täglicher Revier-Verlauf-Snapshot (GameHistory_Backend_Spec.md): schreibt je
     * aktivem Claimant den heutigen Stand (idempotent) und backfillt beim ersten Lauf
     * die Vergangenheit aus game_edge.owner_since/discovered_at. Für Cron gedacht
     * (z. B. täglich ~00:05 UTC).
     */
    private function gameSnapshotDaily(): int
    {
        if ($this->gameHistory === null) {
            fwrite(STDERR, "game:snapshot-daily nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $res = $this->gameHistory->snapshotAll();
        echo sprintf(
            "Revier-Verlauf: %d Claimant(s) für %s, %d neu backfillt.\n",
            $res['claimants'], $res['date'], $res['backfilled'],
        );
        return 0;
    }

    /**
     * regions:import --file=storage/regions/boundaries.geojsonseq [--levels=2,4,6,8]
     * Lädt die OSM-Verwaltungsgrenzen in die game_region-Hierarchie (Phase A der
     * Gebiets-Eroberung, CityConquest_Backend_Spec.md).
     */
    private function regionsImport(array $argv): int
    {
        if ($this->regionImport === null) {
            fwrite(STDERR, "regions:import nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        // Einmaliger Ops-Import europaweiter Grenzen: großzügiges Limit (der
        // Geometrie-Cache der Zwischen-Ebenen wächst über den ganzen Kontinent).
        ini_set('memory_limit', '3G');
        $opts = $this->parseOptions($argv);
        $file = (string)($opts['file'] ?? ($this->basePath . '/storage/regions/boundaries.geojsonseq'));
        $levels = array_values(array_filter(array_map(
            static fn($s): int => (int)trim($s),
            explode(',', (string)($opts['levels'] ?? '2,4,6,8'))
        ), static fn(int $l): bool => $l > 0));
        if ($levels === []) {
            $levels = [2, 4, 6, 8];
        }
        // --append: weiteren Kontinent (z. B. USA) hinzufügen, ohne EU zu löschen.
        // Bloßes Flag (--append) ODER --append=1; parseOptions erfasst nur letzteres,
        // daher zusätzlich argv direkt prüfen.
        $replace = !isset($opts['append']) && !in_array('--append', $argv, true);
        $log = static fn(string $m): int => fwrite(STDERR, $m . "\n");
        try {
            $res = $this->regionImport->importFromGeojsonSeq($file, $levels, $log, $replace);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Fehler: {$e->getMessage()}\n");
            return 1;
        }
        $total = array_sum($res['inserted']);
        echo sprintf("Gebiete importiert: %d (verknüpft: %d)\n", $total, $res['linked']);
        foreach ($res['inserted'] as $lvl => $cnt) {
            echo sprintf("  Ebene %d: %d\n", $lvl, $cnt);
        }
        return 0;
    }

    /**
     * regions:backfill [--all] [--batch=1000]
     * Ordnet Kanten ihr feinstes Gebiet zu (game_edge.region_id). Standard: nur
     * bisher nicht zugeordnete Kanten; --all rechnet alle neu.
     */
    private function regionsBackfill(array $argv): int
    {
        if ($this->regionImport === null) {
            echo "regions:backfill nicht verfügbar (Service nicht verdrahtet).\n";
            return 1;
        }
        ini_set('memory_limit', '2G');
        $opts = $this->parseOptions($argv);
        $onlyUnassigned = !isset($opts['all']);
        $batch = max(1, (int)($opts['batch'] ?? 500));
        // --limit begrenzt die je Aufruf verarbeiteten Kanten (fortsetzbar über
        // --after-id) — nötig auf PROD, wo ein Request Zeit-/Speicherlimits hat.
        $maxCount = isset($opts['limit']) ? max(1, (int)$opts['limit']) : null;
        $afterId = max(0, (int)($opts['after-id'] ?? 0));
        // echo (kein STDERR): läuft auch über die Internal-HTTP-Route (Web-SAPI,
        // wo die STDERR-Konstante fehlt); der Runner erfasst die Ausgabe per ob_start.
        $log = static function (string $m): void { echo $m . "\n"; };
        try {
            $res = $this->regionImport->backfillEdges($onlyUnassigned, $batch, $log, $maxCount, $afterId);
        } catch (\Throwable $e) {
            echo "Fehler: {$e->getMessage()}\n";
            return 1;
        }
        echo sprintf(
            "Backfill: %d geprüft, %d zugeordnet, last_id=%d, done=%s\n",
            $res['scanned'], $res['assigned'], $res['last_id'], $res['done'] ? '1' : '0'
        );
        return 0;
    }

    /**
     * regions:relink — verknüpft zu hoch/fehlerhaft verkettete Gebiete (v. a.
     * Inseln, deren Comune direkt am Land statt an der Provinz hing) neu, ohne
     * Neu-Import der Geometrie. Danach regions:ownership-refresh aufrufen.
     */
    private function regionsRelink(): int
    {
        if ($this->regionImport === null) {
            echo "regions:relink nicht verfügbar (Service nicht verdrahtet).\n";
            return 1;
        }
        ini_set('memory_limit', '2G');
        $log = static function (string $m): void { echo $m . "\n"; };
        $res = $this->regionImport->relinkOrphans($log);
        echo sprintf("Relink: %d geprüft, %d neu verknüpft.\n", $res['checked'], $res['relinked']);
        return 0;
    }

    /**
     * regions:recorrect [--apply] — korrigiert grenzüberschreitend falsch
     * verknüpfte L4-Gebiete (Center-PiP != Elternland) und dedupliziert
     * Namensdubletten. Ohne --apply nur Vorschau (Dry-Run). Politisch umstrittene
     * Fälle (echtes Land = RU) werden übersprungen. Nach --apply wird der
     * Besitz-Rollup neu gerechnet.
     */
    private function regionsRecorrect(array $argv): int
    {
        if ($this->regionImport === null) {
            echo "regions:recorrect nicht verfügbar (Service nicht verdrahtet).\n";
            return 1;
        }
        $apply = in_array('--apply', $argv, true);
        $purgeHomeless = in_array('--purge-homeless', $argv, true);
        ini_set('memory_limit', '2G');
        $log = static function (string $m): void { echo '  ' . $m . "\n"; };

        echo ($apply ? "== ANWENDEN ==\n" : "== DRY-RUN (Vorschau; --apply zum Anwenden) ==\n");
        if ($purgeHomeless) {
            echo "   (--purge-homeless: heimatlose Fremd-Fragmente werden entfernt)\n";
        }
        $res = $this->regionImport->recorrectMisparented($apply, $log, ['RU'], $purgeHomeless);

        echo sprintf(
            "\nRe-Parent (L4): %d | übersprungen (umstritten/RU): %d | Dedup gelöscht: %d | Dedup übersprungen: %d\n",
            count($res['reparented']), count($res['skipped']), count($res['dedup']), count($res['dedupSkipped'])
        );
        echo sprintf(
            "Übersprungene Elter (L6/L8) umgehängt: %d | am Land belassen: %d | heimatlos gelöscht: %d\n",
            count($res['relinkedOrphans'] ?? []), (int)($res['orphansLeft'] ?? 0), count($res['deletedHomeless'] ?? [])
        );
        foreach ($res['deletedHomeless'] as $d) {
            echo sprintf("  · heimatlos gelöscht: %s #%d (L%d)\n", $d['name'], $d['id'], $d['level']);
        }
        foreach ($res['skipped'] as $s) {
            echo sprintf("  · umstritten belassen: %s #%d (echtes Land %s)\n", $s['name'], $s['id'], $s['true_cc'] ?? '-');
        }
        foreach ($res['dedupSkipped'] as $s) {
            echo sprintf("  · Dedup übersprungen: %s [%s] (%s)\n", $s['name'], implode(',', $s['ids']), $s['reason']);
        }

        if ($apply && $this->regionOwnership !== null) {
            echo "Besitz-Rollup neu rechnen …\n";
            $this->regionOwnership->recomputeAll();
            echo "fertig.\n";
        }
        return 0;
    }

    /**
     * regions:add-osm --relation=<id> --level=<n> --name=<Name> --cc=<ISO2>
     *                 [--center=lat,lon]
     *
     * Lädt EINE OSM-Verwaltungsgrenze (polygons.openstreetmap.fr) nach und fügt sie
     * als einzelne Region hinzu — für Gebiete, die beim Massen-Import fehlten (z. B.
     * Bundesstaat Alaska, dessen Antimeridian-Geometrie durchrutschte). Ringe in der
     * Osthalbkugel (lon > 0) werden verworfen (transpazifischer Aleuten-Zipfel), damit
     * die bbox nicht den halben Globus umspannt. Danach `regions:recorrect --apply`
     * ausführen, um Untergebiete (Boroughs) einzunisten.
     */
    private function regionsAddOsm(array $argv): int
    {
        if ($this->regionImport === null) {
            echo "regions:add-osm nicht verfügbar (Service nicht verdrahtet).\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $relation = (int)($opts['relation'] ?? 0);
        $level    = (int)($opts['level'] ?? 0);
        $name     = trim((string)($opts['name'] ?? ''));
        $cc       = strtoupper(trim((string)($opts['cc'] ?? '')));
        if ($relation <= 0 || $level <= 0 || $name === '' || $cc === '') {
            echo "Nutzung: regions:add-osm --relation=<id> --level=<2|4|6|8> --name=<Name> --cc=<ISO2> [--center=lat,lon]\n";
            return 1;
        }
        $centerLat = $centerLon = null;
        if (isset($opts['center']) && str_contains((string)$opts['center'], ',')) {
            [$centerLat, $centerLon] = array_map('floatval', explode(',', (string)$opts['center'], 2));
        }

        $url = "https://polygons.openstreetmap.fr/get_geojson.py?id={$relation}&params=0";
        echo "Lade OSM-Relation {$relation} …\n";
        $raw = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 60, 'header' => "User-Agent: GravelExplorer/1.0 (region add)\r\n"],
        ]));
        if ($raw === false || $raw === '') {
            echo "Fehler: Download fehlgeschlagen ({$url}).\n";
            return 1;
        }
        $decoded = json_decode($raw, true);
        $geometry = $this->extractGeometry($decoded);
        if ($geometry === null) {
            echo "Fehler: keine Polygon/MultiPolygon-Geometrie in der Antwort.\n";
            return 1;
        }
        $geometry = $this->clipEasternHemisphere($geometry);

        ini_set('memory_limit', '2G');
        try {
            $res = $this->regionImport->addSingleRegion($geometry, $level, $name, $cc, $relation, $centerLat, $centerLon);
        } catch (\Throwable $e) {
            echo "Fehler: {$e->getMessage()}\n";
            return 1;
        }
        echo sprintf("Region '%s' angelegt: #%d (Land-Elter #%s).\n",
            $name, $res['id'], $res['parent_id'] !== null ? (string)$res['parent_id'] : 'NULL');
        echo "Nächster Schritt: php public/index.php regions:recorrect --apply  (nistet Untergebiete ein)\n";
        return 0;
    }

    /**
     * Holt eine Polygon/MultiPolygon-Geometrie aus verschiedenen GeoJSON-Hüllen
     * (bare Geometry, Feature, FeatureCollection, GeometryCollection).
     *
     * @param mixed $d
     * @return array<string,mixed>|null
     */
    private function extractGeometry(mixed $d): ?array
    {
        if (!is_array($d)) {
            return null;
        }
        $type = $d['type'] ?? null;
        if ($type === 'Polygon' || $type === 'MultiPolygon') {
            return $d;
        }
        if ($type === 'Feature' && isset($d['geometry']) && is_array($d['geometry'])) {
            return $this->extractGeometry($d['geometry']);
        }
        if ($type === 'FeatureCollection' && isset($d['features'][0]['geometry'])) {
            return $this->extractGeometry($d['features'][0]['geometry']);
        }
        if ($type === 'GeometryCollection' && isset($d['geometries']) && is_array($d['geometries'])) {
            foreach ($d['geometries'] as $g) {
                $hit = $this->extractGeometry($g);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }
        return null;
    }

    /**
     * Verwirft (bei MultiPolygon) Teil-Polygone, die in der Osthalbkugel liegen
     * (irgendein lon > 0) — der transpazifische Antimeridian-Zipfel (z. B. äußere
     * Aleuten). So bleibt die bbox auf die Westhalbkugel beschränkt. Ein reiner
     * Polygon (kein MultiPolygon) bleibt unverändert.
     *
     * @param array<string,mixed> $geometry
     * @return array<string,mixed>
     */
    private function clipEasternHemisphere(array $geometry): array
    {
        if (($geometry['type'] ?? null) !== 'MultiPolygon' || !is_array($geometry['coordinates'] ?? null)) {
            return $geometry;
        }
        $kept = [];
        foreach ($geometry['coordinates'] as $polygon) {
            $hasEast = false;
            foreach ((array)$polygon as $ring) {
                foreach ((array)$ring as $pt) {
                    if (is_array($pt) && isset($pt[0]) && (float)$pt[0] > 0.0) {
                        $hasEast = true;
                        break 2;
                    }
                }
            }
            if (!$hasEast) {
                $kept[] = $polygon;
            }
        }
        if ($kept !== [] && count($kept) < count($geometry['coordinates'])) {
            $geometry['coordinates'] = $kept;
        }
        return $geometry;
    }

    /**
     * regions:ownership-refresh — rechnet den Gebiets-Besitz (game_region_ownership)
     * voll neu (Bottom-up-Rollup + Kontrollschwelle). Idempotent; Besitzwechsel
     * werden gezählt (später für region_taken/region_lost-Events nutzbar).
     */
    private function regionsOwnershipRefresh(): int
    {
        if ($this->regionOwnership === null) {
            echo "regions:ownership-refresh nicht verfügbar (Service nicht verdrahtet).\n";
            return 1;
        }
        ini_set('memory_limit', '1G');
        $res = $this->regionOwnership->recomputeAll();
        echo sprintf(
            "Gebiets-Besitz aktualisiert: %d Gebiet(e), %d Besitzwechsel.\n",
            $res['regions'], count($res['changes'])
        );
        return 0;
    }

    /**
     * game:region-activity-refresh — täglicher Nordstern-Aktivitäts-Cache
     * (game_region_activity): WAR + Solo/Crew je Gebiet und Fenster (7/30 Tage).
     */
    private function regionActivityRefresh(): int
    {
        if ($this->regionActivity === null) {
            echo "game:region-activity-refresh nicht verfügbar (Service nicht verdrahtet).\n";
            return 1;
        }
        ini_set('memory_limit', '1G');
        $res = $this->regionActivity->recomputeAll();
        echo sprintf(
            "Gebiets-Aktivität aktualisiert: %d Fenster, %d Gebiet-Zeilen.\n",
            $res['windows'], $res['regions']
        );
        return 0;
    }

    /**
     * regions:push [--base-url=] [--token=] [--chunk=2000]
     * Schiebt die lokal berechnete game_region-Hierarchie chunk-weise an
     * POST {base}/internal/regions/import auf PROD (verbatim inkl. id/parent_id/
     * path). Kein mysql-Client/osmium auf dem Server nötig — wie der Heatmap-
     * Cutover. Danach auf PROD /internal/regions/backfill?all=1 und
     * /internal/cron/region-ownership aufrufen.
     */
    private function regionsPush(array $argv): int
    {
        if ($this->regionImport === null) {
            fwrite(STDERR, "regions:push nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        ini_set('memory_limit', '1G');
        $opts = $this->parseOptions($argv);
        $base = rtrim((string)($opts['base-url'] ?? $this->config->get('APP_URL', '')), '/');
        $token = (string)($opts['token'] ?? $this->config->get('INTERNAL_TOKEN', ''));
        // Chunking nach BYTES (nicht Zeilen): Einzelgeometrien reichen von 1 KB
        // (Gemeinde) bis >1 MB (Bundesland mit vielen Inseln) — eine feste Zeilenzahl
        // sprengt sonst das Prod-Body-Limit (post_max_size). Default konservativ.
        $maxBytes = max(200_000, (int)($opts['max-bytes'] ?? 2_000_000));
        $maxRows = max(1, (int)($opts['chunk'] ?? 1000));
        if ($base === '' || $token === '') {
            fwrite(STDERR, "base-url und token nötig (--base-url=, --token= oder APP_URL/INTERNAL_TOKEN in .env).\n");
            return 1;
        }
        $total = $this->regionImport->regionCount();
        if ($total === 0) {
            fwrite(STDERR, "Keine Gebiete lokal — erst regions:import laufen lassen.\n");
            return 1;
        }
        $url = $base . '/internal/regions/import';
        echo sprintf("Push %d Gebiete → %s (max %.1f MB/Chunk)\n", $total, $url, $maxBytes / 1e6);

        $after = 0;
        $sent = 0;
        $first = true;
        $buffer = [];
        $bufBytes = 0;

        $flush = function () use (&$buffer, &$bufBytes, &$first, &$sent, $url, $token, $total): bool {
            if ($buffer === []) {
                return true;
            }
            $payload = json_encode(['replace' => $first, 'rows' => $buffer], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!$this->postJson($url, $token, (string)$payload)) {
                return false;
            }
            $sent += count($buffer);
            $first = false;
            echo sprintf("  … %d/%d gesendet\n", $sent, $total);
            $buffer = [];
            $bufBytes = 0;
            return true;
        };

        while (true) {
            $rows = $this->regionImport->exportPage($after, 500);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $after = (int)$r['id'];
                $rowBytes = strlen((string)json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                if ($buffer !== [] && ($bufBytes + $rowBytes > $maxBytes || count($buffer) >= $maxRows)) {
                    if (!$flush()) {
                        fwrite(STDERR, "Abbruch bei id>{$after} (HTTP-Fehler).\n");
                        return 1;
                    }
                }
                $buffer[] = $r;
                $bufBytes += $rowBytes;
            }
        }
        if (!$flush()) {
            fwrite(STDERR, "Abbruch beim letzten Chunk (HTTP-Fehler).\n");
            return 1;
        }
        echo sprintf("Fertig: %d Gebiete gepusht. Jetzt auf PROD: /internal/regions/backfill?all=1 dann /internal/cron/region-ownership\n", $sent);
        return 0;
    }

    private function postJson(string $url, string $token, string $body): bool
    {
        // Bis zu 3 Versuche gegen transiente 5xx/Netzfehler (Prod ist Shared-Hosting).
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($this->postJsonOnce($url, $token, $body)) {
                return true;
            }
            if ($attempt < 3) {
                echo "  … Retry {$attempt}\n";
                sleep($attempt);
            }
        }
        return false;
    }

    private function postJsonOnce(string $url, string $token, string $body): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Internal-Token: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            fwrite(STDERR, "  HTTP {$code} {$err}: " . substr((string)$resp, 0, 300) . "\n");
            return false;
        }
        return true;
    }

    private function backfillSpeed(array $argv): int
    {
        if ($this->edgeBackfill === null) {
            fwrite(STDERR, "game:backfill-speed nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $limit = max(1, (int)($opts['limit'] ?? 100));
        $sleepMs = max(0, (int)($opts['sleep-ms'] ?? 500));
        $after = max(0, (int)($opts['after-route-id'] ?? 0));
        $res = $this->edgeBackfill->run($limit, $sleepMs, $after);
        echo sprintf(
            "Backfill: %d Route(n) verarbeitet, %d Fehler, letzte route_id=%d\n",
            $res['processed'],
            $res['errors'],
            $res['last_route_id'],
        );
        return $res['errors'] > 0 ? 1 : 0;
    }

    /**
     * social:collect [--date=YYYY-MM-DD] — baut den Tagesbericht aus der
     * Aktivität und legt ihn (falls nicht leer) als pending-Kandidat in die
     * Redaktions-Queue (Twitter_Automation_Concept.md §5.1, E1). Idempotent.
     * Für Cron gedacht (z. B. täglich ~19:55 UTC, vor dem Sende-Slot).
     *
     * @param list<string> $argv
     */
    private function socialCollect(array $argv): int
    {
        if ($this->social === null) {
            fwrite(STDERR, "social:collect nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $date = trim((string)($opts['date'] ?? '')) ?: $this->social->today();
        $res  = $this->social->collectDaily($date);
        $kinds = [];
        foreach (($res['by_kind'] ?? []) as $k => $n) {
            $kinds[] = "{$k}={$n}";
        }
        echo sprintf(
            "Social-Collect %s: %d Kandidat(en), neu=%d, schon vorhanden=%d%s\n",
            $res['date'], $res['candidates'], $res['enqueued'], $res['already'],
            $kinds === [] ? '' : ' [' . implode(', ', $kinds) . ']',
        );
        return 0;
    }

    /**
     * social:publish — sendet fällige pending-Kandidaten des Kanals unter dem
     * Tages-Limit (E8). Solange SOCIAL_ENABLED=0 oder SOCIAL_DRY_RUN=1, wird
     * nichts gesendet (Dry-Run), nur protokolliert. Für Cron (z. B. 20:00).
     */
    private function socialPublish(): int
    {
        if ($this->social === null) {
            fwrite(STDERR, "social:publish nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $res = $this->social->publishPending();
        $dry = $res['dry_run'] ? ' DRY-RUN' : '';
        echo sprintf("Social-Publish%s: verfallen=%d\n", $dry, (int)$res['expired']);
        $anyFailed = false;
        foreach (($res['channels'] ?? []) as $channel => $r) {
            echo sprintf(
                "  [%s] gesendet=%d, übersprungen=%d, cooldown=%d, fehlgeschlagen=%d, Rest-Kontingent=%d\n",
                $channel, $r['published'], $r['skipped'], $r['cooldown'], $r['failed'], $r['remaining_quota'],
            );
            $anyFailed = $anyFailed || ($r['failed'] > 0);
        }
        return $anyFailed ? 1 : 0;
    }

    /**
     * social:preview [--date=YYYY-MM-DD] [--lang=en|de] — Trocken-Vorschau des
     * Tagesbericht-Textes samt Rohdaten. Speichert nichts, sendet nichts.
     *
     * @param list<string> $argv
     */
    private function socialPreview(array $argv): int
    {
        if ($this->social === null) {
            fwrite(STDERR, "social:preview nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $date = trim((string)($opts['date'] ?? '')) ?: $this->social->today();
        $lang = trim((string)($opts['lang'] ?? ''));
        $res  = $this->social->preview($date, $lang !== '' ? $lang : null);
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    }

    /** social:status — Betriebs-Überblick über Queue + letzte Sendungen. */
    private function socialStatus(): int
    {
        if ($this->social === null) {
            fwrite(STDERR, "social:status nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        echo json_encode($this->social->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    }

    /**
     * social:card --kind=<typ> [--date=YYYY-MM-DD] [--lang=en|de] [--out=datei.png]
     * Rendert die Media-Card des ersten Kandidaten dieses Typs in eine PNG-Datei
     * (Standard: storage/social-cards/<kind>-<date>.png). Speichert/sendet nichts
     * in die Queue.
     *
     * @param list<string> $argv
     */
    private function socialCard(array $argv): int
    {
        if ($this->social === null) {
            fwrite(STDERR, "social:card nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $kind = trim((string)($opts['kind'] ?? 'daily_report'));
        $date = trim((string)($opts['date'] ?? '')) ?: $this->social->today();
        $lang = trim((string)($opts['lang'] ?? ''));
        $res  = $this->social->previewCard($date, $kind, $lang !== '' ? $lang : null);

        if (!$res['found']) {
            echo "Kein Kandidat vom Typ '{$kind}' für {$date}.\n";
            return 0;
        }
        if ($res['png'] === null) {
            echo "Karte nicht renderbar (GD/Schrift fehlt oder Media aus). Text:\n{$res['text']}\n";
            return 0;
        }
        $out = trim((string)($opts['out'] ?? ''));
        if ($out === '') {
            $dir = $this->basePath . '/storage/social-cards';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $out = $dir . '/' . preg_replace('/[^a-z_]/', '', $kind) . '-' . $date . '.png';
        }
        if (@file_put_contents($out, $res['png']) === false) {
            echo "Konnte nicht schreiben: {$out}\n";
            return 1;
        }
        echo "Karte gerendert (" . strlen((string)$res['png']) . " Bytes) → {$out}\nText: {$res['text']}\n";
        return 0;
    }

    /** social:doctor — Startklar-Check (Config, Migrationen, X-Verbindung, Cards). Postet nichts. */
    private function socialDoctor(): int
    {
        if ($this->social === null) {
            fwrite(STDERR, "social:doctor nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $res = $this->social->doctor();
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        // Exit 0, wenn Migrationen ok und mindestens ein konfigurierter Kanal
        // verbunden ist (oder noch kein Kanal konfiguriert wurde).
        $anyConfigured = false;
        $anyOk = false;
        foreach (($res['channels'] ?? []) as $c) {
            $anyConfigured = $anyConfigured || ($c['configured'] ?? false);
            $anyOk = $anyOk || ($c['ok'] ?? false);
        }
        return ($res['migrations_ok'] && (!$anyConfigured || $anyOk)) ? 0 : 1;
    }

    /**
     * social:ig-setup --token=<kurzlebiger Graph-Token> [--app-id=] [--app-secret=] [--graph=v21.0]
     * Tauscht (falls App-ID/Secret vorhanden) in einen long-lived Token und
     * ermittelt die IG-User-ID. Gibt einen fertigen .env-Block aus. CLI-only.
     *
     * @param list<string> $argv
     */
    private function socialIgSetup(array $argv): int
    {
        $opts   = $this->parseOptions($argv);
        $token  = trim((string)($opts['token'] ?? ''));
        if ($token === '') {
            echo "Nutzung: social:ig-setup --token=<kurzlebiger Graph-Token> [--app-id=] [--app-secret=]\n";
            echo "Den kurzlebigen Token bekommst du im Graph API Explorer (Scopes: instagram_basic,\n";
            echo "instagram_content_publish, pages_show_list, pages_read_engagement, business_management).\n";
            return 1;
        }
        $graph    = trim((string)($opts['graph'] ?? ($this->config->get('IG_GRAPH_VERSION', 'v21.0') ?? 'v21.0')));
        $appId    = trim((string)($opts['app-id'] ?? ($this->config->get('FB_APP_ID', '') ?? '')));
        $appSecret= trim((string)($opts['app-secret'] ?? ($this->config->get('FB_APP_SECRET', '') ?? '')));

        $setup = new \App\Social\InstagramSetup($graph);

        // 1) Optional: kurzlebig → long-lived tauschen.
        $longToken = $token;
        $expiresIn = null;
        if ($appId !== '' && $appSecret !== '') {
            $ex = $setup->exchangeLongLived($appId, $appSecret, $token);
            if (!$ex['ok']) {
                echo "Token-Tausch fehlgeschlagen: {$ex['error']}\n";
                return 1;
            }
            $longToken = (string)$ex['token'];
            $expiresIn = $ex['expires_in'];
            echo "Long-lived Token erzeugt" . ($expiresIn !== null ? " (gültig ~" . intdiv((int)$expiresIn, 86400) . " Tage)" : "") . ".\n";
        } else {
            echo "Hinweis: --app-id/--app-secret fehlen → Token wird NICHT getauscht (nutze ihn als long-lived).\n";
        }

        // 2) IG-User-ID über verknüpfte Seite ermitteln.
        $disc = $setup->discoverBusinessAccount($longToken);
        if (!$disc['ok']) {
            echo "IG-Account nicht gefunden: {$disc['error']}\n";
            return 1;
        }

        echo "\n== Gefunden ==\n";
        echo "  Facebook-Seite : " . ($disc['page'] ?? '—') . "\n";
        echo "  IG-Username    : @" . ($disc['username'] ?? '—') . "\n";
        echo "  IG-User-ID     : " . $disc['ig_user_id'] . "\n";
        echo "\n== In die .env übernehmen ==\n";
        echo "SOCIAL_CHANNELS=instagram\n";
        echo "IG_USER_ID={$disc['ig_user_id']}\n";
        echo "IG_ACCESS_TOKEN={$longToken}\n";
        echo "IG_GRAPH_VERSION={$graph}\n";
        echo "\nDanach: social:doctor → channels.instagram.ok:true erwartet.\n";
        return 0;
    }

    private function help(): void
    {
        echo "GRAVA Backend CLI\n";
        echo "Nutzung: php public/index.php <befehl>\n\n";
        echo "Befehle:\n";
        echo "  cli:migrate         Wendet ausstehende Migrationen an\n";
        echo "  cron:cleanup        Löscht abgelaufene Tokens, Sessions, Verifizierungen, Rate-Limits + Heatmap-Rebuild\n";
        echo "  cron:heatmap        Aggregiert die Crowd-Heatmap (Centroids) aus public Routen neu\n";
        echo "  cron:heatmap-lines  Map-Matching der public Routen -> heatmap_edges (Streckenlinien)\n";
        echo "  heatmap:manifest    (PROD) Gibt das Manifest der public Routen als JSON aus (Cutover-Hinweg)\n";
        echo "  heatmap:rebuild-local  (LOKAL) Rebuild aus Manifest + Dateien: --manifest=.. --routes-dir=..\n";
        echo "  heatmap:export-edges   (LOKAL) heatmap_edges als JSON exportieren: --out=..\n";
        echo "  game:recompute      Berechnet alle Spiel-Kanten aus den Pässen neu [--bbox=minLon,minLat,maxLon,maxLat]\n";
        echo "  game:rush-tick      Aktualisiert fällige Rush-Status (planned→active→completed/expired)\n";
        echo "  game:heal-crews     Heilt captain-lose Crews (promotet ältestes Mitglied)\n";
        echo "  game:backfill-speed Rekord-Daten auf Bestands-Pässe [--limit=100] [--sleep-ms=500] [--after-route-id=0]\n";
        echo "  game:notify-dispatch Stellt den Spiel-Ereignis-Strom als Inbox+APNs zu (Digest-Fenster)\n";
        echo "  game:test-push      (Feldtest) edge_taken-Mitteilung erzeugen: --handle=<@h>|--user=<id> [--actor=<@h>] [--edge=<id>]\n";
        echo "  social:collect      Tagesbericht bauen + in die Post-Queue legen [--date=YYYY-MM-DD]\n";
        echo "  social:publish      Fällige Post-Kandidaten senden (Dry-Run, solange SOCIAL_ENABLED=0/SOCIAL_DRY_RUN=1)\n";
        echo "  social:preview      Trocken-Vorschau ALLER Kandidaten des Tages [--date=YYYY-MM-DD] [--lang=en|de]\n";
        echo "  social:status       Betriebs-Überblick: Queue-Zustand + letzte Sendungen\n";
        echo "  social:card         Media-Card rendern: --kind=<typ> [--date=] [--lang=] [--out=datei.png]\n";
        echo "  social:doctor       Startklar-Check (Config, Migrationen, X/IG-Verbindung) — postet nichts\n";
        echo "  social:ig-setup     IG-Einrichtung: --token=<kurzlebig> [--app-id=][--app-secret=] → long-lived Token + IG-User-ID\n";
        echo "  help                Zeigt diese Hilfe\n";
    }
}
