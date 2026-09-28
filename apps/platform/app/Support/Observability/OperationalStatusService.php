<?php

namespace App\Support\Observability;

use App\Support\Email\EmailStatus;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Observability\Signals\OperationalSignals;
use Illuminate\Support\Facades\Http;
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
    public function __construct(
        private readonly OperationalSignals $signals,
        private readonly MetricsRecorder $metrics,
    ) {}

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
            // Phase 0O.4A: the probe honours the configured sslmode
            // (production requires TLS) like every other connection.
            $sslmode = isset($config['sslmode']) && $config['sslmode'] !== '' ? ";sslmode={$config['sslmode']}" : '';
            $pdo = new PDO(
                "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};connect_timeout={$connectTimeoutSeconds}{$sslmode}",
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
            $this->metrics->counter('lycenza_dependency_check_failures_total', 1, ['dependency' => 'postgresql']);

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
            $this->metrics->counter('lycenza_dependency_check_failures_total', 1, ['dependency' => 'redis']);

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
            $this->metrics->counter('lycenza_dependency_check_failures_total', 1, ['dependency' => 'object_storage']);

            return new ComponentStatus('storage', OperationalStatus::Degraded, 'unreachable');
        }
    }

    /**
     * Phase 0O.5A (ADR 0051 §15): every scheduled task's heartbeat, with
     * the shared thresholds (a minute task is stale after 300 s and
     * critical after 1800 s; a daily task after 26 h).
     *
     * @return array<int, ComponentStatus>
     */
    public function scheduler(): array
    {
        return $this->guarded('scheduler', fn () => array_map(fn ($task) => new ComponentStatus(
            "scheduler:{$task->name}",
            match (true) {
                $task->critical => OperationalStatus::Unhealthy,
                $task->stale => OperationalStatus::Degraded,
                $task->lastErrorCode !== null => OperationalStatus::Degraded,
                default => OperationalStatus::Healthy,
            },
            match (true) {
                $task->lastSuccessAt === null => 'never_succeeded',
                $task->stale => 'stale',
                $task->lastErrorCode !== null => 'last_run_failed',
                default => null,
            },
            ['last_success_at' => $task->lastSuccessAt?->toIso8601String(), 'error_code' => $task->lastErrorCode],
        ), $this->signals->taskHeartbeats()));
    }

    /**
     * Worker classes (`default`, `integrations`, `notifications`): the
     * heartbeat is refreshed by every processed job and at least by the
     * per-minute canary, so a stale heartbeat means the worker class is
     * not processing -- whether or not work is waiting (ADR 0051 §11).
     *
     * @return array<int, ComponentStatus>
     */
    public function queues(): array
    {
        return $this->guarded('queues', function () {
            $failed = $this->signals->failedJobs();
            $warn = (int) config('observability.thresholds.backlog_warning_seconds');
            $high = (int) config('observability.thresholds.backlog_high_seconds');

            return array_map(function ($queue) use ($failed, $warn, $high) {
                $age = $queue->oldestPendingAgeSeconds ?? 0;
                [$status, $reason] = match (true) {
                    $queue->heartbeatStale => [OperationalStatus::Unhealthy, 'stalled'],
                    $age > $high => [OperationalStatus::Unhealthy, 'backlog'],
                    $age > $warn => [OperationalStatus::Degraded, 'backlog'],
                    ($failed[$queue->queue] ?? 0) > 0 => [OperationalStatus::Degraded, 'has failed jobs'],
                    default => [OperationalStatus::Healthy, null],
                };

                return new ComponentStatus("queue:{$queue->queue}", $status, $reason, [
                    'pending' => $queue->pending,
                    'delayed' => $queue->delayed,
                    'oldest_pending_age_seconds' => $queue->oldestPendingAgeSeconds,
                    'failed' => $failed[$queue->queue] ?? 0,
                    'last_success_at' => $queue->heartbeatAt?->toIso8601String(),
                ]);
            }, $this->signals->queues());
        });
    }

    public function outbox(): ComponentStatus
    {
        return $this->guarded('outbox', function () {
            $outbox = $this->signals->outbox();
            $warn = (int) config('observability.outbox_degraded_after_seconds');
            $high = (int) config('observability.outbox_critical_after_seconds');

            [$status, $reason] = match (true) {
                $outbox->pending > 0 && $outbox->oldestPendingAgeSeconds > $high => [OperationalStatus::Unhealthy, 'backlog'],
                $outbox->failed > 0 => [OperationalStatus::Degraded, 'failed_events'],
                $outbox->pending > 0 && $outbox->oldestPendingAgeSeconds > $warn => [OperationalStatus::Degraded, 'backlog'],
                $outbox->stale > 0 => [OperationalStatus::Degraded, 'stale_unacknowledged'],
                default => [OperationalStatus::Healthy, null],
            };

            return new ComponentStatus('outbox', $status, $reason, [
                'pending_count' => $outbox->pending,
                'oldest_pending_age_seconds' => $outbox->oldestPendingAgeSeconds,
                'unacknowledged_count' => $outbox->unacknowledged,
                'stale_count' => $outbox->stale,
                'failed_count' => $outbox->failed,
            ]);
        })[0];
    }

    /**
     * Recovery sweeps (Redis-loss reconciliation, ADR 0050 §10): fresh
     * when each sweep's task heartbeat succeeded within the minute-task
     * window.
     */
    public function recovery(): ComponentStatus
    {
        return $this->guarded('recovery', function () {
            $stale = (int) config('observability.thresholds.minute_task_stale_seconds');
            $detail = [];
            $late = [];

            foreach ($this->signals->recoverySweeps() as $source => $at) {
                $detail[$source] = $at?->toIso8601String();
                if ($at === null || $at->diffInSeconds(now(), true) > $stale) {
                    $late[] = $source;
                }
            }

            return new ComponentStatus('recovery', $late === [] ? OperationalStatus::Healthy : OperationalStatus::Degraded, $late === [] ? null : 'stale:'.implode(',', $late), $detail);
        })[0];
    }

    /**
     * Unfinished webhook deliveries -- never Unhealthy: a customer's
     * endpoint failing is not an ERP outage (section 59, rule 56).
     */
    public function webhooks(): ComponentStatus
    {
        return $this->backlogComponent('webhooks', 'webhook', OperationalStatus::Degraded);
    }

    public function communications(): ComponentStatus
    {
        return $this->backlogComponent('communications', 'communication', OperationalStatus::Unhealthy);
    }

    public function automation(): ComponentStatus
    {
        return $this->backlogComponent('automation', 'automation', OperationalStatus::Degraded);
    }

    /**
     * Phase 0O.9A (ADR 0055 section 16): email is Degraded at worst -- a
     * provider outage or disabled email never makes the ERP unready -- and
     * no live provider call is made.
     */
    public function email(): ComponentStatus
    {
        return $this->guarded('email', fn () => app(EmailStatus::class)->component())[0];
    }

    /**
     * Phase 0O.10A (ADR 0056 section 15): self-service account recovery --
     * `disabled` (the default mode), `unavailable` (enabled, but critical
     * email cannot carry it) or healthy. Degraded at worst; never readiness;
     * no provider call.
     */
    public function accountRecovery(): ComponentStatus
    {
        return $this->guarded('account_recovery', function (): ComponentStatus {
            if (! (bool) config('account_recovery.enabled')) {
                return new ComponentStatus('account_recovery', OperationalStatus::Degraded, 'disabled');
            }

            return app(EmailProviderResolver::class)->criticalEmailAvailable()
                ? new ComponentStatus('account_recovery', OperationalStatus::Healthy)
                : new ComponentStatus('account_recovery', OperationalStatus::Degraded, 'unavailable');
        })[0];
    }

    public function aiGateway(): ComponentStatus
    {
        // ADR 0053: the Gateway integration is enabled only by its base URL.
        if (trim((string) config('services.ai_gateway.base_url')) === '') {
            return new ComponentStatus('ai_gateway', OperationalStatus::Degraded, 'not_configured');
        }

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
            $this->recovery(),
            $this->webhooks(),
            $this->communications(),
            $this->automation(),
            $this->email(),
            $this->accountRecovery(),
            $this->aiGateway(),
        ];
    }

    private function backlogComponent(string $component, string $source, OperationalStatus $worst): ComponentStatus
    {
        return $this->guarded($component, function () use ($component, $source, $worst) {
            $backlog = $this->signals->backlog($source);
            $warn = (int) config('observability.thresholds.backlog_warning_seconds');
            $high = (int) config('observability.thresholds.backlog_high_seconds');

            [$status, $reason] = match (true) {
                $backlog->oldestOverdueAgeSeconds > $high => [$worst, 'overdue'],
                $backlog->oldestOverdueAgeSeconds > $warn => [OperationalStatus::Degraded, 'overdue'],
                default => [OperationalStatus::Healthy, null],
            };

            return new ComponentStatus($component, $status, $reason, [
                'unfinished' => $backlog->states,
                'overdue_count' => $backlog->overdue,
                'oldest_overdue_age_seconds' => $backlog->oldestOverdueAgeSeconds,
            ]);
        })[0];
    }

    /**
     * Phase 0O.5A (ADR 0051 §15): one component failing to compute (e.g.
     * PostgreSQL down) degrades to `unknown` with a fixed reason -- never an
     * exception, never the exception text.
     *
     * @return array<int, ComponentStatus>
     */
    private function guarded(string $component, callable $compute): array
    {
        try {
            $result = $compute();

            return is_array($result) ? array_values($result) : [$result];
        } catch (Throwable) {
            return [new ComponentStatus($component, OperationalStatus::Unknown, 'unavailable')];
        }
    }
}
