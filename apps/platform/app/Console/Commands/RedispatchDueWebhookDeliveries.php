<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWebhookJob;
use App\Models\School;
use App\Support\Observability\QueueName;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drives webhook retry timing (sections 41/45) -- App\Jobs\
 * DeliverWebhookJob itself never schedules its own future re-attempt
 * (the sync queue connection used in tests, ADR 0024, has no delayed
 * requeue); this command is what re-dispatches a `retrying` delivery
 * once its `next_attempt_at` has passed, AND what recovers a `delivering`
 * delivery whose processing lease expired (a crashed worker's stale
 * claim -- DeliverWebhookJob's own atomic claim makes re-dispatching it
 * here safe even if the original worker is, in fact, still alive and
 * about to finish: the claim's conditional UPDATE means at most one of
 * them actually proceeds).
 *
 * Tenant-owned data requires per-School iteration (unlike
 * DispatchOutboxEvents' single cross-tenant query over the CENTRAL
 * domain_event_outbox table) -- see
 * App\Console\Commands\PruneIdempotencyRecords for the identical
 * pattern this command follows: TenantContext::withSchool() per School,
 * `FOR UPDATE SKIP LOCKED` within each School's batch so two concurrent
 * runs of this command never claim the same row twice.
 */
class RedispatchDueWebhookDeliveries extends Command
{
    protected $signature = 'platform:webhook-deliveries-redispatch {--batch=100 : Maximum rows to claim per School per run}';

    protected $description = 'Re-dispatch webhook_deliveries rows whose retry is due or whose processing lease expired.';

    public function handle(SchedulerHeartbeatRecorder $heartbeats): int
    {
        $batchSize = (int) $this->option('batch');
        $totalDispatched = 0;

        try {
            School::query()->orderBy('id')->chunk(100, function ($schools) use ($batchSize, &$totalDispatched): void {
                foreach ($schools as $school) {
                    $totalDispatched += app(TenantContext::class)->withSchool($school, function () use ($school, $batchSize) {
                        return $this->redispatchForSchool($school, $batchSize);
                    });
                }
            });
        } catch (\Throwable $e) {
            $heartbeats->recordFailure('webhook-deliveries-redispatch', $e->getMessage());
            Log::error('platform.webhook_deliveries_redispatch.failed', ['error' => $e->getMessage()]);
            $this->error("Webhook redispatch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $heartbeats->recordSuccess('webhook-deliveries-redispatch');
        $this->info("Re-dispatched {$totalDispatched} webhook deliverie(s).");
        Log::info('platform.webhook_deliveries_redispatch.completed', ['dispatched' => $totalDispatched]);

        return self::SUCCESS;
    }

    private function redispatchForSchool(School $school, int $batchSize): int
    {
        return DB::transaction(function () use ($school, $batchSize) {
            $rows = DB::table('webhook_deliveries')
                ->where('school_id', $school->id)
                ->where(function ($query) {
                    $query->where(function ($q) {
                        $q->where('status', 'retrying')->where('next_attempt_at', '<=', now());
                    })->orWhere(function ($q) {
                        $q->where('status', 'delivering')->where('processing_lease_expires_at', '<', now());
                    });
                })
                ->limit($batchSize)
                ->lock('for update skip locked')
                ->get(['id']);

            foreach ($rows as $row) {
                DeliverWebhookJob::dispatch($school->id, $row->id)
                    ->onQueue(QueueName::Integrations->value)
                    ->afterCommit();
            }

            return $rows->count();
        });
    }
}
