<?php

namespace App\Console\Commands;

use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Jobs\RunAutomationExecutionJob;
use App\Models\School;
use App\Support\Observability\QueueName;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0L.6 (ADR 0043 §7): drives Automation execution retry timing, the
 * role RedispatchDueWebhookDeliveries/RedispatchDueCommunicationDeliveries
 * play for their rows. RunAutomationExecutionJob never re-queues itself
 * (`tries = 1`); this command re-dispatches a `pending` execution once its
 * `next_attempt_at` has passed (a backoff after a failed attempt, or a
 * first dispatch that was lost) and a `running` one whose lease expired (a
 * crashed worker). The job's lease claim makes re-dispatching safe even if
 * another worker is about to finish. One School at a time, inside that
 * School's context.
 */
class RedispatchDueAutomationExecutions extends Command
{
    protected $signature = 'automation:executions-redispatch {--batch=100 : Maximum rows per School per run}';

    protected $description = 'Re-dispatch Automation executions whose retry is due or whose processing lease expired.';

    public function handle(SchedulerHeartbeatRecorder $heartbeats): int
    {
        $batchSize = (int) $this->option('batch');
        $total = 0;

        try {
            // Every School, suspended ones included (ADR 0047 section 8): a
            // pending execution of a suspended School is dispatched once and
            // RunAutomationExecutionJob records it `skipped`
            // (`school_suspended`) -- terminal, so this never loops.
            School::query()->orderBy('id')->chunk(100, function ($schools) use ($batchSize, &$total): void {
                foreach ($schools as $school) {
                    $total += app(TenantContext::class)->withSchool($school, fn () => $this->redispatchForSchool($school, $batchSize));
                }
            });
        } catch (\Throwable $e) {
            $heartbeats->recordFailure('automation-executions-redispatch', $e::class);
            Log::error('automation.executions_redispatch.failed', ['exception' => $e::class]);
            $this->error('Automation execution redispatch failed.');

            return self::FAILURE;
        }

        $heartbeats->recordSuccess('automation-executions-redispatch');
        $this->info("Re-dispatched {$total} Automation execution(s).");

        return self::SUCCESS;
    }

    private function redispatchForSchool(School $school, int $batchSize): int
    {
        return DB::transaction(function () use ($school, $batchSize): int {
            $ids = AutomationExecution::query()
                ->where('school_id', $school->id)
                ->where(function ($query) {
                    $query->where(fn ($q) => $q->where('status', AutomationExecution::STATUS_PENDING)->where('next_attempt_at', '<=', now()))
                        ->orWhere(fn ($q) => $q->where('status', AutomationExecution::STATUS_RUNNING)->where('processing_lease_expires_at', '<', now()));
                })
                ->limit($batchSize)
                ->lock('for update skip locked')
                ->pluck('id');

            foreach ($ids as $id) {
                RunAutomationExecutionJob::dispatch($school->id, $id)
                    ->onQueue(QueueName::Default->value)
                    ->afterCommit();
            }

            return $ids->count();
        });
    }
}
