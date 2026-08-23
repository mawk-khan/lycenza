<?php

namespace App\Support\Observability;

use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PDO;
use Predis\Client as PredisClient;
use Throwable;

/**
 * Phase 0C.4 section 51: the one application service aggregating
 * every subsystem's health into a small, consistent set of statuses.
 * Deliberately not a "monitoring engine" -- no alerting, no history,
 * just "what is true right now," computed fresh on every call (section
 * 61: readiness is not long-cached).
 *
 * Every check is wrapped in its own try/catch: one broken check must
 * never take down the whole aggregation (readiness in particular must
 * always return SOMETHING, even if every dependency is down).
 */
class OperationalStatusService
{
    /**
     * Readiness (section 7): PostgreSQL + Redis only -- the two
     * dependencies genuinely essential for "can this instance safely
     * receive normal application traffic." Storage/outbox/webhooks/AI
     * are deliberately excluded (sections 7/12/50/59) -- a third-party
     * webhook endpoint or an optional AI Gateway outage must never make
     * the whole ERP "unready."
     */
    public function readiness(): OperationalStatus
    {
        return OperationalStatus::worstOf([
            $this->database()->status,
            $this->redis()->status,
        ]);
    }

    public function database(): ComponentStatus
    {
        $timeoutMs = (int) config('observability.readiness_check_timeout_ms');

        try {
            $start = microtime(true);
            // A FRESH, dedicated connection with an explicit connect
            // timeout (section 8) -- deliberately not the app's own
            // persistent `pgsql` connection, so a health check can
            // never be slowed down by (or itself slow down) whatever
            // state that connection is already in.
            // `PDO::ATTR_TIMEOUT` alone does NOT bound the initial TCP
            // connection attempt for the pdo_pgsql driver (a real gap
            // found via this checkpoint's own live proof: readiness
            // hung indefinitely, not just 500ms, against a genuinely
            // unreachable host) -- libpq's own `connect_timeout` DSN
            // parameter is what actually bounds connection
            // establishment. libpq treats a value of 1 or less as "no
            // timeout," so this is floored at 2 seconds regardless of
            // how low `readiness_check_timeout_ms` is configured.
            $config = config('database.connections.pgsql');
            $connectTimeoutSeconds = max(2, (int) ceil($timeoutMs / 1000));
            $pdo = new PDO(
                "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};connect_timeout={$connectTimeoutSeconds}",
                $config['username'],
                $config['password'],
                [PDO::ATTR_TIMEOUT => $connectTimeoutSeconds],
            );
            $pdo->query('SELECT 1');
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            return new ComponentStatus('database', OperationalStatus::Healthy, detail: ['duration_ms' => $durationMs]);
        } catch (Throwable) {
            // Never the exception message/class (section 60) -- could
            // contain the host/database name/credentials.
            return new ComponentStatus('database', OperationalStatus::Unhealthy, 'unreachable');
        }
    }

    public function redis(): ComponentStatus
    {
        try {
            $start = microtime(true);
            $config = (array) config('database.redis.default');
            $client = new PredisClient([
                'scheme' => 'tcp',
                'host' => $config['host'] ?? '127.0.0.1',
                'port' => $config['port'] ?? 6379,
                'password' => $config['password'] ?? null,
                'timeout' => max(0.1, (int) config('observability.readiness_check_timeout_ms') / 1000),
                'read_write_timeout' => max(0.1, (int) config('observability.readiness_check_timeout_ms') / 1000),
            ]);
            $client->ping();
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            return new ComponentStatus('redis', OperationalStatus::Healthy, detail: ['duration_ms' => $durationMs]);
        } catch (Throwable) {
            return new ComponentStatus('redis', OperationalStatus::Unhealthy, 'unreachable');
        }
    }

    public function storage(): ComponentStatus
    {
        try {
            Storage::disk(config('filesystems.default'))->exists('__health_check_probe__');

            return new ComponentStatus('storage', OperationalStatus::Healthy);
        } catch (Throwable) {
            // Object storage is NOT essential to readiness (section
            // 59) -- most routes do not touch it, so a failure here is
            // degraded, never unhealthy.
            return new ComponentStatus('storage', OperationalStatus::Degraded, 'unreachable');
        }
    }

    /**
     * @return array<int, ComponentStatus>
     */
    public function scheduler(): array
    {
        $recorder = app(SchedulerHeartbeatRecorder::class);
        $staleAfter = (int) config('observability.scheduler_stale_after_seconds');

        return array_map(function (string $name) use ($recorder, $staleAfter) {
            $heartbeat = $recorder->get($name);

            if ($heartbeat === null) {
                return new ComponentStatus("scheduler:{$name}", OperationalStatus::Unknown, 'never run');
            }

            if ($recorder->isStale($heartbeat, $staleAfter)) {
                return new ComponentStatus(
                    "scheduler:{$name}",
                    OperationalStatus::Unhealthy,
                    'stale',
                    ['last_success_at' => $heartbeat->last_success_at?->toIso8601String()],
                );
            }

            return new ComponentStatus(
                "scheduler:{$name}",
                $heartbeat->last_error !== null ? OperationalStatus::Degraded : OperationalStatus::Healthy,
                $heartbeat->last_error !== null ? 'last run failed' : null,
                ['last_success_at' => $heartbeat->last_success_at?->toIso8601String()],
            );
        }, ['outbox-dispatch', 'webhook-deliveries-redispatch']);
    }

    /**
     * @return array<int, ComponentStatus>
     */
    public function queues(): array
    {
        $recorder = app(SchedulerHeartbeatRecorder::class);
        $staleAfter = (int) config('observability.queue_stale_after_seconds');

        return array_map(function (QueueName $queue) use ($recorder, $staleAfter) {
            $name = "queue:{$queue->value}";
            $pending = $this->queueDepth($queue->value);
            $failed = DB::table('failed_jobs')->where('queue', $queue->value)->count();
            $heartbeat = $recorder->get($name);

            // Section 20: an idle queue with zero pending work is
            // NEVER stalled, regardless of heartbeat age -- there is
            // simply nothing to have processed recently.
            if ($pending === 0) {
                $status = $failed > 0 ? OperationalStatus::Degraded : OperationalStatus::Healthy;

                return new ComponentStatus($name, $status, $failed > 0 ? 'has failed jobs' : null, [
                    'pending' => $pending,
                    'failed' => $failed,
                ]);
            }

            $stalled = $heartbeat === null || $recorder->isStale($heartbeat, $staleAfter);

            return new ComponentStatus(
                $name,
                $stalled ? OperationalStatus::Unhealthy : OperationalStatus::Healthy,
                $stalled ? 'stalled' : null,
                [
                    'pending' => $pending,
                    'failed' => $failed,
                    'last_success_at' => $heartbeat?->last_success_at?->toIso8601String(),
                ],
            );
        }, [QueueName::Default, QueueName::Integrations]);
    }

    private function queueDepth(string $queue): int
    {
        try {
            return Queue::size($queue);
        } catch (Throwable) {
            return 0;
        }
    }

    public function outbox(): ComponentStatus
    {
        $pending = DomainEventOutbox::query()->where('status', 'pending')->count();
        $oldest = DomainEventOutbox::query()->where('status', 'pending')->min('available_at');
        // min()/max() are query-builder aggregates -- they return the
        // RAW database value, not a cast Carbon instance, even though
        // the model casts this column -- parse explicitly.
        $ageSeconds = $oldest !== null ? now()->diffInSeconds(Carbon::parse($oldest)) : 0;

        $degradedAfter = (int) config('observability.outbox_degraded_after_seconds');
        $criticalAfter = (int) config('observability.outbox_critical_after_seconds');

        $status = match (true) {
            $pending === 0 => OperationalStatus::Healthy,
            $ageSeconds > $criticalAfter => OperationalStatus::Unhealthy,
            $ageSeconds > $degradedAfter => OperationalStatus::Degraded,
            default => OperationalStatus::Healthy,
        };

        return new ComponentStatus('outbox', $status, detail: [
            'pending_count' => $pending,
            'oldest_pending_age_seconds' => $ageSeconds,
        ]);
    }

    public function webhooks(): ComponentStatus
    {
        // webhook_deliveries is tenant-owned/RLS-protected (unlike the
        // central domain_event_outbox) -- a cross-School aggregate
        // MUST iterate every School's own context explicitly (the same
        // pattern App\Console\Commands\RedispatchDueWebhookDeliveries
        // already uses), never an unscoped/raw query. There is no
        // "see every tenant's rows at once" mode by design (ADR 0004);
        // `pgsql_admin` is migration-only (ADR 0021) and must never be
        // used by runtime application code to bypass this.
        $pending = 0;
        $retrying = 0;
        $abandoned = 0;
        $oldestRetryAt = null;
        $context = app(TenantContext::class);

        School::query()->select('id')->orderBy('id')->chunk(200, function ($schools) use (&$pending, &$retrying, &$abandoned, &$oldestRetryAt, $context): void {
            foreach ($schools as $school) {
                $context->withSchool($school, function () use ($school, &$pending, &$retrying, &$abandoned, &$oldestRetryAt): void {
                    $pending += WebhookDelivery::query()->where('school_id', $school->id)->whereIn('status', ['pending', 'retrying'])->count();
                    $retrying += WebhookDelivery::query()->where('school_id', $school->id)->where('status', 'retrying')->count();
                    $abandoned += WebhookDelivery::query()->where('school_id', $school->id)->where('status', 'abandoned')->count();

                    $schoolOldest = WebhookDelivery::query()->where('school_id', $school->id)->where('status', 'retrying')->min('next_attempt_at');
                    if ($schoolOldest !== null) {
                        $schoolOldestAt = Carbon::parse($schoolOldest);
                        if ($oldestRetryAt === null || $schoolOldestAt->lt($oldestRetryAt)) {
                            $oldestRetryAt = $schoolOldestAt;
                        }
                    }
                });
            }
        });

        $ageSeconds = $oldestRetryAt !== null ? now()->diffInSeconds($oldestRetryAt) : 0;

        $degradedAfter = (int) config('observability.webhook_degraded_after_seconds');
        $criticalAfter = (int) config('observability.webhook_critical_after_seconds');

        // Section 49/59: a customer's own endpoint being down is never
        // OUR unhealthy -- capped at Degraded, never Unhealthy,
        // regardless of how old the backlog gets.
        $status = match (true) {
            $pending === 0 => OperationalStatus::Healthy,
            $ageSeconds > $degradedAfter || $ageSeconds > $criticalAfter => OperationalStatus::Degraded,
            default => OperationalStatus::Healthy,
        };

        return new ComponentStatus('webhooks', $status, detail: [
            'pending_count' => $pending,
            'retrying_count' => $retrying,
            'abandoned_count' => $abandoned,
            'oldest_retry_age_seconds' => $ageSeconds,
        ]);
    }

    public function aiGateway(): ComponentStatus
    {
        try {
            $timeoutSeconds = max(0.1, (int) config('observability.ai_gateway_check_timeout_ms') / 1000);
            $response = Http::timeout($timeoutSeconds)
                ->get(rtrim((string) config('services.ai_gateway.base_url'), '/').'/health/live');

            // AI is OPTIONAL (section 12/50): unreachable is Degraded,
            // never Unhealthy -- it never determines core ERP
            // readiness either way (readiness() above doesn't call
            // this at all).
            return new ComponentStatus(
                'ai_gateway',
                $response->successful() ? OperationalStatus::Healthy : OperationalStatus::Degraded,
                $response->successful() ? null : 'unreachable',
            );
        } catch (Throwable) {
            return new ComponentStatus('ai_gateway', OperationalStatus::Degraded, 'unreachable');
        }
    }

    /**
     * Full internal diagnostics (section 10/51) -- every component,
     * for an authenticated platform operator only. Never exposed
     * unauthenticated.
     *
     * @return array<int, ComponentStatus>
     */
    public function full(): array
    {
        return [
            $this->database(),
            $this->redis(),
            $this->storage(),
            ...$this->scheduler(),
            ...$this->queues(),
            $this->outbox(),
            $this->webhooks(),
            $this->aiGateway(),
        ];
    }
}
