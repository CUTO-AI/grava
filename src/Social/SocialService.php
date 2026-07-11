<?php
declare(strict_types=1);

namespace App\Social;

use App\Config\Config;
use App\Database\Db;
use App\Support\Clock;
use PDO;

/**
 * Orchestriert die Social-Automatik (Konzept §5/§6): einsammeln → in die
 * Redaktions-Queue → getaktet senden. **Multi-Channel** (Instagram_Automation_
 * Concept.md): EIN Einsammel-/Redaktions-Prozess erzeugt kanal-neutrale
 * Kandidaten, die je konfiguriertem Kanal (`SOCIAL_CHANNELS`) eine eigene
 * Queue-Zeile bekommen und über den jeweiligen Adapter gesendet werden.
 * Dry-Run sendet nichts.
 */
final class SocialService
{
    private readonly PDO $pdo;
    private readonly PostCopy $copy;
    private readonly DailyReportCollector $collector;
    /** @var list<string> */
    private readonly array $channels;
    private readonly string $lang;
    private readonly int $maxPerDay;
    private readonly bool $dryRun;
    private readonly EditorialPolicy $policy;
    private readonly bool $mediaEnabled;
    private readonly SocialCardRenderer $cards;
    private readonly string $fontDir;
    private readonly string $webUrl;

    public function __construct(private readonly Config $config, ?PDO $pdo = null)
    {
        $this->pdo       = $pdo ?? Db::pdo();
        $this->webUrl    = rtrim((string)($config->get('PUBLIC_WEB_URL', $config->get('APP_URL', 'https://cyberride.world')) ?? 'https://cyberride.world'), '/');
        $this->copy      = new PostCopy($this->webUrl);
        $this->collector = new DailyReportCollector($this->pdo);
        $this->channels  = $this->parseChannels($config);
        $this->lang      = $this->copy->normalizeLang((string)($config->get('SOCIAL_LANG', 'en') ?? 'en'));
        $this->maxPerDay = max(1, $config->int('SOCIAL_MAX_POSTS_PER_DAY', 1));
        // Sicher standardmäßig trocken: es wird erst gesendet, wenn der
        // Betreiber SOCIAL_ENABLED=1 UND SOCIAL_DRY_RUN=0 setzt.
        $this->dryRun    = !$config->bool('SOCIAL_ENABLED', false) || $config->bool('SOCIAL_DRY_RUN', true);
        $this->policy    = new EditorialPolicy(
            $this->pdo,
            $config->int('SOCIAL_MAX_AGE_HOURS', 36),
            $config->int('SOCIAL_ENTITY_COOLDOWN_DAYS', 3),
        );
        $this->mediaEnabled = $config->bool('SOCIAL_MEDIA_ENABLED', true);
        $fontDir = (string)($config->get('SOCIAL_FONT_DIR', '') ?? '');
        if ($fontDir === '') {
            $fontDir = dirname(__DIR__, 2) . '/resources/fonts';
        }
        $this->fontDir = $fontDir;
        $this->cards = new SocialCardRenderer($fontDir, $this->pdo);
    }

    /** @return list<string> Konfigurierte Kanäle (SOCIAL_CHANNELS, Fallback SOCIAL_CHANNEL). */
    private function parseChannels(Config $config): array
    {
        $raw = (string)($config->get('SOCIAL_CHANNELS', '') ?? '');
        if (trim($raw) === '') {
            $raw = (string)($config->get('SOCIAL_CHANNEL', 'twitter') ?? 'twitter');
        }
        $out = [];
        foreach (explode(',', $raw) as $c) {
            $c = strtolower(trim($c));
            if ($c !== '' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out === [] ? ['twitter'] : $out;
    }

    /** Heutiges UTC-Datum (Fallback für die Kommandos). */
    public function today(): string
    {
        return Clock::nowUtc()->format('Y-m-d');
    }

    /**
     * Trocken-Vorschau (`social:preview`): baut ALLE (kanal-neutralen) Kandidaten
     * des Tages und rendert sie, ohne etwas zu speichern/senden.
     *
     * @return array{date:string, lang:string, channels:list<string>, count:int, candidates:list<array<string,mixed>>}
     */
    public function preview(string $date, ?string $lang = null): array
    {
        $lang = $this->copy->normalizeLang($lang ?? $this->lang);
        $cands = array_map(static fn(PostCandidate $c) => [
            'kind'   => $c->kind,
            'dedupe' => $c->dedupeKey,
            'score'  => $c->score,
            'length' => mb_strlen($c->body),
            'text'   => $c->body,
            'payload'=> $c->payloadJson !== null ? json_decode($c->payloadJson, true) : null,
        ], $this->gatherCandidates($date, $lang));

        return [
            'date'       => $date,
            'lang'       => $lang,
            'channels'   => $this->channels,
            'count'      => count($cands),
            'candidates' => $cands,
        ];
    }

    /**
     * Einsammeln (§5.1): kanal-neutrale Kandidaten bauen und je konfiguriertem
     * Kanal eine pending-Zeile anlegen. Idempotent über den dedupe_key (inkl. Kanal).
     *
     * @return array{date:string, channels:list<string>, candidates:int, enqueued:int, already:int, by_kind:array<string,int>}
     */
    public function collectDaily(string $date): array
    {
        $candidates = $this->gatherCandidates($date, $this->lang);

        $enqueued = 0;
        $already  = 0;
        $byKind   = [];
        foreach ($candidates as $c) {
            foreach ($this->channels as $channel) {
                $ok = $this->enqueue($c, $channel);
                $ok ? $enqueued++ : $already++;
                if ($ok) {
                    $byKind[$c->kind] = ($byKind[$c->kind] ?? 0) + 1;
                }
            }
        }

        return [
            'date'       => $date,
            'channels'   => $this->channels,
            'candidates' => count($candidates),
            'enqueued'   => $enqueued,
            'already'    => $already,
            'by_kind'    => $byKind,
        ];
    }

    /**
     * Alle kanal-neutralen Kandidaten des Tages. Geteilt von preview() und collectDaily().
     * @return list<PostCandidate>
     */
    private function gatherCandidates(string $date, string $lang): array
    {
        $candidates = [];
        $daily = $this->buildDailyCandidate($date, $lang);
        if ($daily !== null) {
            $candidates[] = $daily;
        }
        foreach ($this->sources($lang) as $source) {
            foreach ($source->collect($date) as $cand) {
                $candidates[] = $cand;
            }
        }
        return $candidates;
    }

    /** Baut den Tagesbericht-Kandidaten oder null, wenn keine Aktivität vorliegt. */
    private function buildDailyCandidate(string $date, string $lang): ?PostCandidate
    {
        $report = $this->collector->collect($date);
        if ($report->isEmpty()) {
            return null;
        }
        return new PostCandidate(
            kind:        'daily_report',
            dedupeKey:   "daily_report:{$date}:{$lang}",
            entityKey:   "day:{$date}",
            score:       50,
            body:        $this->copy->dailyReport($report, $lang),
            payloadJson: json_encode([
                'rides'            => $report->rides,
                'distance_km'      => $report->distanceKm,
                'edges_taken_over' => $report->edgesTakenOver,
                'counties_changed' => $report->countiesChanged,
                'rush_crew'        => $report->rushCrewName,
                'rush_edges'       => $report->rushEdges,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /** @return list<PostSource> Aktive Ereignis-Quellen (kanal-neutral). */
    private function sources(string $lang): array
    {
        return [
            new RegionTakenCollector($this->pdo, $this->copy, $lang),
            new RushResultCollector($this->pdo, $this->copy, $lang),
            new FactionStandingCollector($this->pdo, $this->copy, $lang),
            // Personenbezogen, strikt opt-in-gated (Konzept §8/E3):
            new BadgeEarnedCollector($this->pdo, $this->copy, $lang),
            new RecordBeatenCollector($this->pdo, $this->copy, $lang),
            // Community-Aggregate (Phase F):
            new WeeklyRecapCollector($this->pdo, $this->copy, $lang),
            new CommunityMilestoneCollector($this->pdo, $this->copy, $lang),
        ];
    }

    /** Schreibt einen Kandidaten für einen Kanal in die Queue; false = schon vorhanden. */
    private function enqueue(PostCandidate $c, string $channel): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO social_post_queue (kind, channel, lang, dedupe_key, entity_key, status, score, body, payload)
             VALUES (:kind, :channel, :lang, :dedupe, :entity, 'pending', :score, :body, :payload)
             ON DUPLICATE KEY UPDATE id = id"
        );
        $stmt->execute([
            ':kind'    => $c->kind,
            ':channel' => $channel,
            ':lang'    => $this->lang,
            ':dedupe'  => $c->dedupeKey . ':' . $channel, // kanal-eigene Idempotenz
            ':entity'  => $c->entityKey,
            ':score'   => $c->score,
            ':body'    => $c->body,
            ':payload' => $c->payloadJson,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Senden (§5.5): Redaktions-Layer + Fan-out je Kanal. Verfallene Kandidaten
     * werden global aussortiert; danach postet jeder Kanal seine pending-Zeilen
     * score-sortiert unter seinem Tages-Limit + Cooldown. Ein Kanal-Fehler
     * blockiert die anderen nicht.
     *
     * @return array{dry_run:bool, expired:int, channels:array<string,array<string,int|string>>}
     */
    public function publishPending(): array
    {
        // §5.1: veraltete Kandidaten (alle Kanäle) verfallen lassen.
        $expired = $this->policy->pruneStale();

        $perChannel = [];
        foreach ($this->channels as $channel) {
            $perChannel[$channel] = $this->publishChannel($channel);
        }

        return [
            'dry_run'  => $this->dryRun,
            'expired'  => $expired,
            'channels' => $perChannel,
        ];
    }

    /** @return array<string,int|string> */
    private function publishChannel(string $channel): array
    {
        $publisher = $this->makePublisher($channel);
        $quota     = max(0, $this->maxPerDay - $this->publishedToday($channel));

        $published = 0;
        $failed    = 0;
        $skipped   = 0;
        $cooldown  = 0;

        $rows = $this->pdo->prepare(
            "SELECT id, kind, entity_key, body, payload FROM social_post_queue
              WHERE status = 'pending' AND channel = :channel
                AND (scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP(3))
           ORDER BY score DESC, id ASC
              LIMIT 20"
        );
        $rows->execute([':channel' => $channel]);

        foreach (($rows->fetchAll() ?: []) as $c) {
            $id = (int)$c['id'];

            if ($this->policy->entityOnCooldown((string)$c['kind'], (string)($c['entity_key'] ?? ''), $channel)) {
                $this->markSkipped($id, 'cooldown');
                $cooldown++;
                continue;
            }
            if ($quota <= 0 && !$this->dryRun) {
                $skipped++;
                continue;
            }

            $card = $this->renderCard((string)$c['kind'], $c['payload'] ?? null);
            $result = $publisher->publish((string)$c['body'], $card, $this->cardUrl($id));
            $this->log($id, $channel, $result);

            if ($result->ok) {
                $this->markPublished($id, $result);
                $published++;
                if (!$result->dryRun) {
                    $quota--;
                }
            } else {
                $this->markFailed($id, $result);
                $failed++;
            }
        }

        return [
            'published'       => $published,
            'skipped'         => $skipped,
            'cooldown'        => $cooldown,
            'failed'          => $failed,
            'remaining_quota' => max(0, $quota),
        ];
    }

    /** Öffentliche URL der Media-Card einer Queue-Zeile (für Instagram). */
    private function cardUrl(int $id): string
    {
        return $this->webUrl . '/social/card/' . $id . '.png';
    }

    /**
     * Startklar-Check (`social:doctor`): Konfiguration, Migrationen, Karten-
     * Voraussetzungen und je Kanal die Verbindung. Postet nichts, gibt keine
     * Secrets aus.
     *
     * @return array<string,mixed>
     */
    public function doctor(): array
    {
        $enabled = $this->config->bool('SOCIAL_ENABLED', false);

        $migrationsOk = true;
        try {
            $this->pdo->query('SELECT entity_key FROM social_post_queue LIMIT 0'); // 0052 + 0053
        } catch (\PDOException $e) {
            $migrationsOk = false;
        }

        $gd       = \function_exists('imagecreatetruecolor');
        $freetype = \function_exists('imagettftext');
        $fonts    = is_file($this->fontDir . '/ChakraPetch-Bold.ttf')
            && is_file($this->fontDir . '/Rajdhani-SemiBold.ttf');
        $mediaReady = !$this->mediaEnabled || ($gd && $freetype && $fonts);

        $channels = [];
        $anyChannelOk = false;
        foreach ($this->channels as $channel) {
            $info = $this->channelDoctor($channel);
            $channels[$channel] = $info;
            $anyChannelOk = $anyChannelOk || ($info['ok'] ?? false);
        }

        return [
            'enabled'       => $enabled,
            'dry_run'       => $this->dryRun,
            'channels'      => $channels,
            'lang'          => $this->lang,
            'max_per_day'   => $this->maxPerDay,
            'migrations_ok' => $migrationsOk,
            'media_enabled' => $this->mediaEnabled,
            'media_ready'   => $mediaReady,
            'public_web_url'=> $this->webUrl,
            'verdict'       => $this->doctorVerdict($migrationsOk, $anyChannelOk, $enabled, $mediaReady),
        ];
    }

    /** @return array{configured:bool, ok:bool, account:?string, error:?string} */
    private function channelDoctor(string $channel): array
    {
        $pub = $this->makeLivePublisher($channel);
        if ($pub === null) {
            return ['configured' => false, 'ok' => false, 'account' => null, 'error' => 'not_configured'];
        }
        $v = $pub->verify();
        return ['configured' => true, 'ok' => (bool)$v['ok'], 'account' => $v['handle'] ?? null, 'error' => $v['error'] ?? null];
    }

    private function doctorVerdict(bool $migrationsOk, bool $anyChannelOk, bool $enabled, bool $mediaReady): string
    {
        if (!$migrationsOk) return 'NICHT bereit — Migrationen 0052/0053 fehlen (cli:migrate).';
        if (!$anyChannelOk) return 'NICHT bereit — kein Kanal verbunden (Credentials prüfen, siehe channels.*.error).';
        if (!$mediaReady)   return 'Text-only bereit, aber Media-Cards nicht renderbar (GD/Schrift prüfen).';
        if (!$enabled || $this->dryRun) return 'Bereit — aber im Dry-Run. Für Echtbetrieb SOCIAL_ENABLED=1 + SOCIAL_DRY_RUN=0 setzen.';
        return 'OK — versandbereit für den Echtbetrieb.';
    }

    /** Rendert die Media-Card für einen Kandidaten (oder null: aus/fehlerhaft/text-only). */
    private function renderCard(string $kind, ?string $payloadJson): ?string
    {
        if (!$this->mediaEnabled || $payloadJson === null || $payloadJson === '') {
            return null;
        }
        $payload = json_decode($payloadJson, true);
        return is_array($payload) ? $this->cards->render($kind, $payload) : null;
    }

    /**
     * Rendert die Card einer bereits eingereihten Queue-Zeile (für den
     * öffentlichen Card-Endpunkt). Null, wenn Zeile/Payload fehlt.
     */
    public function renderCardForQueueId(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT kind, payload FROM social_post_queue WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false || ($row['payload'] ?? null) === null) {
            return null;
        }
        $payload = json_decode((string)$row['payload'], true);
        return is_array($payload) ? $this->cards->render((string)$row['kind'], $payload) : null;
    }

    /**
     * Vorschau einer Media-Card (`social:card`): rendert die Karte des ersten
     * Kandidaten des gewünschten Typs für den Tag, ohne zu speichern/senden.
     *
     * @return array{found:bool, kind:string, text:?string, png:?string}
     */
    public function previewCard(string $date, string $kind, ?string $lang = null): array
    {
        $lang = $this->copy->normalizeLang($lang ?? $this->lang);
        foreach ($this->gatherCandidates($date, $lang) as $c) {
            if ($c->kind === $kind) {
                return [
                    'found' => true,
                    'kind'  => $kind,
                    'text'  => $c->body,
                    'png'   => $c->payloadJson !== null
                        ? $this->cards->render($kind, (array)json_decode($c->payloadJson, true))
                        : null,
                ];
            }
        }
        return ['found' => false, 'kind' => $kind, 'text' => null, 'png' => null];
    }

    /** Sende-Adapter je Kanal: Dry-Run oder fehlende Credentials → NullPublisher. */
    private function makePublisher(string $channel): Publisher
    {
        if ($this->dryRun) {
            return new NullPublisher($channel);
        }
        return $this->makeLivePublisher($channel) ?? new NullPublisher($channel);
    }

    /** Der echte Adapter eines Kanals, oder null wenn nicht (voll) konfiguriert. */
    private function makeLivePublisher(string $channel): ?Publisher
    {
        if ($channel === 'twitter') {
            $tw = new TwitterPublisher(
                (string)($this->config->get('TWITTER_CONSUMER_KEY', '') ?? ''),
                (string)($this->config->get('TWITTER_CONSUMER_SECRET', '') ?? ''),
                (string)($this->config->get('TWITTER_ACCESS_TOKEN', '') ?? ''),
                (string)($this->config->get('TWITTER_ACCESS_TOKEN_SECRET', '') ?? ''),
            );
            return $tw->usable() ? $tw : null;
        }
        if ($channel === 'instagram') {
            $ig = new InstagramPublisher(
                (string)($this->config->get('IG_USER_ID', '') ?? ''),
                (string)($this->config->get('IG_ACCESS_TOKEN', '') ?? ''),
                (string)($this->config->get('IG_GRAPH_VERSION', 'v21.0') ?? 'v21.0'),
            );
            return $ig->usable() ? $ig : null;
        }
        return null;
    }

    /** Anzahl heute (UTC) erfolgreich echt gesendeter Posts im Kanal. */
    private function publishedToday(string $channel): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM social_post_log
              WHERE status = 'ok' AND channel = :channel
                AND created_at >= UTC_DATE() AND created_at < UTC_DATE() + INTERVAL 1 DAY"
        );
        $stmt->execute([':channel' => $channel]);
        return (int)$stmt->fetchColumn();
    }

    private function markPublished(int $id, PublishResult $r): void
    {
        if ($r->dryRun) {
            return; // Dry-Run lässt die Zeile pending.
        }
        $stmt = $this->pdo->prepare(
            "UPDATE social_post_queue SET status = 'published', published_at = UTC_TIMESTAMP(3), error = NULL WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    private function markFailed(int $id, PublishResult $r): void
    {
        $stmt = $this->pdo->prepare("UPDATE social_post_queue SET status = 'failed', error = ? WHERE id = ?");
        $stmt->execute([mb_substr((string)$r->error, 0, 500), $id]);
    }

    private function markSkipped(int $id, string $reason): void
    {
        $stmt = $this->pdo->prepare("UPDATE social_post_queue SET status = 'skipped', error = ? WHERE id = ?");
        $stmt->execute([$reason, $id]);
    }

    /**
     * Betriebs-Überblick (`social:status`): Queue-Zustand + letzte Sendungen.
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $byStatus = [];
        foreach ($this->pdo->query("SELECT status, COUNT(*) c FROM social_post_queue GROUP BY status") as $r) {
            $byStatus[(string)$r['status']] = (int)$r['c'];
        }
        $pendingByChannel = [];
        foreach ($this->pdo->query("SELECT channel, COUNT(*) c FROM social_post_queue WHERE status='pending' GROUP BY channel") as $r) {
            $pendingByChannel[(string)$r['channel']] = (int)$r['c'];
        }
        $publishedToday = [];
        foreach ($this->channels as $ch) {
            $publishedToday[$ch] = $this->publishedToday($ch);
        }
        $lastPublished = $this->pdo->query(
            "SELECT kind, channel, published_at FROM social_post_queue
              WHERE status = 'published' ORDER BY published_at DESC LIMIT 5"
        )->fetchAll() ?: [];

        return [
            'channels'          => $this->channels,
            'dry_run'           => $this->dryRun,
            'lang'              => $this->lang,
            'max_per_day'       => $this->maxPerDay,
            'published_today'   => $publishedToday,
            'by_status'         => $byStatus,
            'pending_by_channel'=> $pendingByChannel,
            'last_published'    => $lastPublished,
        ];
    }

    private function log(int $queueId, string $channel, PublishResult $r): void
    {
        $status = $r->dryRun ? 'dry_run' : ($r->ok ? 'ok' : 'error');
        $stmt = $this->pdo->prepare(
            "INSERT INTO social_post_log (queue_id, channel, external_id, status, response)
             VALUES (:qid, :channel, :ext, :status, :response)"
        );
        $stmt->execute([
            ':qid'      => $queueId,
            ':channel'  => $channel,
            ':ext'      => $r->externalId,
            ':status'   => $status,
            ':response' => $r->response !== null ? mb_substr($r->response, 0, 2000) : null,
        ]);
    }
}
