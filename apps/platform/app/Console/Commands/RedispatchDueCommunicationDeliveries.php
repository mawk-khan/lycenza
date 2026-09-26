<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Models\School;
use App\Support\Observability\ErrorReporter;
use App\Support\Observability\QueueName;
use App\Support\Observability\RecoveryMetrics;
use App\Support\Observability\SafeException;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use App\Support\Tenancy\SchoolStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5A.3 §21: drives communication-delivery retry timing, the
 * exact same role App\Console\Commands\RedispatchDueWebhookDeliveries
 * plays for webhook_deliveries -- App\Jobs\ProcessCommunicationDeliveryJob
 * never schedules its own future re-attempt (no delayed requeue on the
 * sync queue connection used in tests, ADR 0024); this command
 * re-dispatches a `queued` delivery once its `next_attempt_at` has
 * passed, and recovers a `sending` delivery whose processing lease
 * expired (a crashed worker's stale claim -- the job's own atomic
 * claim() makes re-dispatching it here safe even if the original
 * worker is still alive and about to finish).
 *
 * `in_app` deliveries never reach either condition (InAppChannelDriver
 * always succeeds immediately), so today this command only ever acts
 * on `email` deliveries -- but it is channel-agnostic by construction,
 * exactly like ProcessCommunicationDeliveryJob itself.
 */
class RedispatchDueCommunicationDeliveries extends Command
{
    protected $signature = 'platform:communication-deliveries-redispatch {--batch=100 : Maximum rows to claim per School per run}';

    protected $description = 'Re-dispatch communication_deliveries rows whose retry is due or whose processing lease expired.';

    public function handle(SchedulerHeartbeatRecorder $heartbeats): int
    {
        $startedAt = microtime(true);
        $batchSize = (int) $this->option('batch');
        $totalDispatched = 0;

        try {
            // Phase 0N.9 (ADR 0047 section 8): only operational Schools. A
            // suspended School's deferred rows wait, untouched, until RESUME
            // -- skipping here is what keeps a deferral from looping.
            School::query()->where('status', SchoolStatus::Active->value)->orderBy('id')->chunk(100, function ($schools) use ($batchSize, &$totalDispatched): void {
                foreach ($schools as $school) {
                    $totalDispatched += app(TenantContext::class)->withSchool($school, function () use ($school, $batchSize) {
                        return $this->redispatchForSchool($school, $batchSize);
                    });
                }
            });
        } catch (\Throwable $e) {
            app(RecoveryMetrics::class)->record('communication', false, $startedAt);
            $heartbeats->recordFailure('communication-deliveries-redispatch', SafeException::code($e));
            app(ErrorReporter::class)->report($e, 'platform.communication_deliveries_redispatch.failed', 'scheduler', 'communication-deliveries-redispatch');
            $this->error('Communication delivery redispatch failed ('.SafeException::code($e).').');

            return self::FAILURE;
        }

        $heartbeats->recordSuccess('communication-deliveries-redispatch');
        app(RecoveryMetrics::class)->record('communication', true, $startedAt, ['inspected' => $totalDispatched, 'redispatched' => $totalDispatched]);
        $this->info("Re-dispatched {$totalDispatched} communication deliverie(s).");
        Log::info('platform.communication_deliveries_redispatch.completed', ['dispatched' => $totalDispatched]);

        return self::SUCCESS;
    }

    private function redispatchForSchool(School $school, int $batchSize): int
    {
        return DB::transaction(function () use ($school, $batchSize) {
            $rows = DB::table('communication_deliveries')
                ->where('school_id', $school->id)
                ->where(function ($query) {
                    $query->where(function ($q) {
                        $q->where('status', 'queued')->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now());
                    })->orWhere(function ($q) {
                        $q->where('status', 'sending')->where('processing_lease_expires_at', '<', now());
                    })->orWhere(function ($q) {
                        // Phase 0O.4A (ADR 0050 section 10): an immediate
                        // (`pending`) delivery is dispatched when created; one
                        // no worker has claimed for a whole processing lease
                        // lost its queued job (e.g. Redis loss). Re-dispatch
                        // is duplicate-safe (the job's atomic claim), sends
                        // nothing itself and consumes no attempt; a deferred
                        // delivery is `queued` and untouched here. `updated_at`
                        // is bumped: at most once per lease per row.
                        $q->where('status', 'pending')
                            ->where('updated_at', '<=', now()->subSeconds((int) config('communications.delivery.processing_lease_seconds')));
                    });
                })
                ->limit($batchSize)
                ->lock('for update skip locked')
                ->get(['id', 'status']);

            $pending = $rows->where('status', 'pending')->pluck('id')->all();
            if ($pending !== []) {
                DB::table('communication_deliveries')->whereIn('id', $pending)->where('status', 'pending')->update(['updated_at' => now()]);
            }

            foreach ($rows as $row) {
                ProcessCommunicationDeliveryJob::dispatch($school->id, $row->id)
                    ->onQueue(QueueName::Notifications->value)
                    ->afterCommit();
            }

            return $rows->count();
        });
    }
}
