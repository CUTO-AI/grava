<?php
declare(strict_types=1);

namespace App\Social;

use App\Config\Config;
use App\Database\Db;
use App\Support\Clock;
use PDO;

/**
 * Orchestriert die Social-Automatik (Konzept §5/§6): einsammeln → in die
 * Redaktions-Queue → getaktet senden. Kanal-agnostisch (E7); in Phase A ist
 * der einzige Meldungstyp der Tagesbericht (E1), gesendet über EINEN Post/Tag
 * (Free-Tier, E8). Dry-Run (E-Preview) sendet nichts.
 */
final class SocialService
{
    private readonly PDO $pdo;
    private readonly PostCopy $copy;
    private readonly DailyReportCollector $collector;
    private readonly string $channel;
    private readonly string $lang;
    private readonly int $maxPerDay;
    private readonly bool $dryRun;
    private readonly EditorialPolicy $policy;
    private readonly bool $mediaEnabled;
    private readonly SocialCardRenderer $cards;

    public function __construct(private readonly Config $config, ?PDO $pdo = null)
    {
        $this->pdo       = $pdo ?? Db::pdo();
        $webUrl          = (string)($config->get('PUBLIC_WEB_URL', $config->get('APP_URL', 'https://cyberride.world')) ?? 'https://cyberride.world');
        $this->copy      = new PostCopy($webUrl);
        $this->collector = new DailyReportCollector($this->pdo);
        $this->channel   = (string)($config->get('SOCIAL_CHANNEL', 'twitter') ?? 'twitter');
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
        $this->cards = new SocialCardRenderer($fontDir, $this->pdo);
    }

    /** Heutiges UTC-Datum (Fallback für die Kommandos). */
    public function today(): string
    {
        return Clock::nowUtc()->format('Y-m-d');
    }

    /**
     * Trocken-Vorschau (§9/A `social:preview`): baut ALLE Kandidaten des Tages
     * (Tagesbericht + Ereignisse) und rendert sie, ohne etwas zu speichern/senden.
     *
     * @return array{date:string, lang:string, count:int, candidates:list<array<string,mixed>>}
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
            'count'      => count($cands),
            'candidates' => $cands,
        ];
    }

    /**
     * Einsammeln (§5.1): baut den Tagesbericht und legt ihn — sofern nicht leer —
     * als pending-Kandidat in die Queue. Idempotent über den dedupe_key.
     *
     * @return array{date:string, enqueued:bool, reason:string}
     */
    public function collectDaily(string $date): array
    {
        $candidates = $this->gatherCandidates($date, $this->lang);

        $enqueued = 0;
        $already  = 0;
        $byKind   = [];
        foreach ($candidates as $c) {
            $ok = $this->enqueue($c);
            $ok ? $enqueued++ : $already++;
            $byKind[$c->kind] = ($byKind[$c->kind] ?? 0) + ($ok ? 1 : 0);
        }

        return [
            'date'       => $date,
            'candidates' => count($candidates),
            'enqueued'   => $enqueued,
            'already'    => $already,
            'by_kind'    => $byKind,
        ];
    }

    /**
     * Alle Kandidaten des Tages in der gewünschten Sprache: Tagesbericht (E1)
     * + Ereignis-Quellen (Phase B). Geteilt von preview() und collectDaily().
     *
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
            dedupeKey:   "daily_report:{$date}:{$lang}:{$this->channel}",
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

    /**
     * @return list<PostSource> Aktive Ereignis-Quellen (Phase B) in der Sprache.
     */
    private function sources(string $lang): array
    {
        return [
            new RegionTakenCollector($this->pdo, $this->copy, $lang, $this->channel),
            new RushResultCollector($this->pdo, $this->copy, $lang, $this->channel),
            new FactionStandingCollector($this->pdo, $this->copy, $lang, $this->channel),
            // Personenbezogen, strikt opt-in-gated (Konzept §8/E3):
            new BadgeEarnedCollector($this->pdo, $this->copy, $lang, $this->channel),
            new RecordBeatenCollector($this->pdo, $this->copy, $lang, $this->channel),
            // Community-Aggregate (Phase F):
            new WeeklyRecapCollector($this->pdo, $this->copy, $lang, $this->channel),
            new CommunityMilestoneCollector($this->pdo, $this->copy, $lang, $this->channel),
        ];
    }

    /** Schreibt einen Kandidaten in die Queue; false = schon vorhanden (idempotent). */
    private function enqueue(PostCandidate $c): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO social_post_queue (kind, channel, lang, dedupe_key, entity_key, status, score, body, payload)
             VALUES (:kind, :channel, :lang, :dedupe, :entity, 'pending', :score, :body, :payload)
             ON DUPLICATE KEY UPDATE id = id"
        );
        $stmt->execute([
            ':kind'    => $c->kind,
            ':channel' => $this->channel,
            ':lang'    => $this->lang,
            ':dedupe'  => $c->dedupeKey,
            ':entity'  => $c->entityKey,
            ':score'   => $c->score,
            ':body'    => $c->body,
            ':payload' => $c->payloadJson,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Senden (§5.5): Redaktions-Layer (§5) + Sendung. Verfallene Kandidaten
     * werden aussortiert; die verbleibenden werden score-sortiert unter dem
     * Tages-Limit (E8) und Cooldown gesendet und protokolliert.
     *
     * @return array{channel:string, dry_run:bool, published:int, skipped:int, cooldown:int, expired:int, failed:int, remaining_quota:int}
     */
    public function publishPending(): array
    {
        // §5.1: veraltete Kandidaten verfallen lassen (kein verspätetes Posten).
        $expired = $this->policy->pruneStale();

        $publisher = $this->makePublisher();
        $sentToday = $this->publishedToday();
        $quota     = max(0, $this->maxPerDay - $sentToday);

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
        $rows->execute([':channel' => $this->channel]);
        $candidates = $rows->fetchAll() ?: [];

        foreach ($candidates as $c) {
            $id = (int)$c['id'];

            // §5.3: dasselbe Objekt nicht binnen Cooldown erneut posten.
            if ($this->policy->entityOnCooldown((string)$c['kind'], (string)($c['entity_key'] ?? ''))) {
                $this->markSkipped($id, 'cooldown');
                $cooldown++;
                continue;
            }

            if ($quota <= 0 && !$this->dryRun) {
                // Kontingent erschöpft: für heute liegen lassen (bleibt pending).
                $skipped++;
                continue;
            }

            $card = $this->renderCard((string)$c['kind'], $c['payload'] ?? null);
            $result = $publisher->publish((string)$c['body'], $card);
            $this->log($id, $result);

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
            'channel'         => $this->channel,
            'dry_run'         => $this->dryRun,
            'published'       => $published,
            'skipped'         => $skipped,
            'cooldown'        => $cooldown,
            'expired'         => $expired,
            'failed'          => $failed,
            'remaining_quota' => max(0, $quota),
        ];
    }

    /** Rendert die Media-Card für einen Kandidaten (oder null: aus/fehlerhaft/text-only). */
    private function renderCard(string $kind, ?string $payloadJson): ?string
    {
        if (!$this->mediaEnabled || $payloadJson === null || $payloadJson === '') {
            return null;
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }
        return $this->cards->render($kind, $payload);
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

    /** Wählt den Sende-Adapter: Dry-Run oder fehlende Credentials → NullPublisher. */
    private function makePublisher(): Publisher
    {
        if ($this->dryRun) {
            return new NullPublisher($this->channel);
        }
        if ($this->channel === 'twitter') {
            $tw = new TwitterPublisher(
                (string)($this->config->get('TWITTER_CONSUMER_KEY', '') ?? ''),
                (string)($this->config->get('TWITTER_CONSUMER_SECRET', '') ?? ''),
                (string)($this->config->get('TWITTER_ACCESS_TOKEN', '') ?? ''),
                (string)($this->config->get('TWITTER_ACCESS_TOKEN_SECRET', '') ?? ''),
            );
            return $tw->usable() ? $tw : new NullPublisher($this->channel);
        }
        return new NullPublisher($this->channel);
    }

    /** Anzahl heute (UTC) erfolgreich echt gesendeter Posts über alle Kanäle. */
    private function publishedToday(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM social_post_log
              WHERE status = 'ok'
                AND created_at >= UTC_DATE()
                AND created_at <  UTC_DATE() + INTERVAL 1 DAY"
        );
        return (int)$stmt->fetchColumn();
    }

    private function markPublished(int $id, PublishResult $r): void
    {
        // Dry-Run lässt den Kandidaten pending (er soll später echt gesendet
        // werden); ein echter Post wird als published fixiert.
        if ($r->dryRun) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE social_post_queue
                SET status = 'published', published_at = UTC_TIMESTAMP(3), error = NULL
              WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    private function markFailed(int $id, PublishResult $r): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE social_post_queue SET status = 'failed', error = ? WHERE id = ?"
        );
        $stmt->execute([mb_substr((string)$r->error, 0, 500), $id]);
    }

    private function markSkipped(int $id, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE social_post_queue SET status = 'skipped', error = ? WHERE id = ?"
        );
        $stmt->execute([$reason, $id]);
    }

    /**
     * Betriebs-Überblick (`social:status`): Queue-Zustand + letzte Sendungen.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $byStatus = [];
        foreach ($this->pdo->query(
            "SELECT status, COUNT(*) c FROM social_post_queue GROUP BY status"
        ) as $r) {
            $byStatus[(string)$r['status']] = (int)$r['c'];
        }

        $pendingByKind = [];
        foreach ($this->pdo->query(
            "SELECT kind, COUNT(*) c FROM social_post_queue WHERE status = 'pending' GROUP BY kind"
        ) as $r) {
            $pendingByKind[(string)$r['kind']] = (int)$r['c'];
        }

        $lastPublished = $this->pdo->query(
            "SELECT kind, body, published_at FROM social_post_queue
              WHERE status = 'published' ORDER BY published_at DESC LIMIT 3"
        )->fetchAll() ?: [];

        return [
            'channel'         => $this->channel,
            'dry_run'         => $this->dryRun,
            'lang'            => $this->lang,
            'max_per_day'     => $this->maxPerDay,
            'published_today' => $this->publishedToday(),
            'by_status'       => $byStatus,
            'pending_by_kind' => $pendingByKind,
            'last_published'  => $lastPublished,
        ];
    }

    private function log(int $queueId, PublishResult $r): void
    {
        $status = $r->dryRun ? 'dry_run' : ($r->ok ? 'ok' : 'error');
        $stmt = $this->pdo->prepare(
            "INSERT INTO social_post_log (queue_id, channel, external_id, status, response)
             VALUES (:qid, :channel, :ext, :status, :response)"
        );
        $stmt->execute([
            ':qid'      => $queueId,
            ':channel'  => $this->channel,
            ':ext'      => $r->externalId,
            ':status'   => $status,
            ':response' => $r->response !== null ? mb_substr($r->response, 0, 2000) : null,
        ]);
    }
}
