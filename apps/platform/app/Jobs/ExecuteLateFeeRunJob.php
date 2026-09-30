<?php

namespace App\Jobs;

use App\Domain\Payments\Application\LateFeeItemExecutor;
use App\Domain\Payments\Application\LateFeeRunService;
use App\Models\School;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * FEE.5 (ADR 0062 §16.2, §13): executes one bounded batch of a late-fee
 * run's pending items, then re-dispatches itself while items remain, or
 * finalizes the run. The `ExecuteFeeAssessmentRunJob` shape exactly:
 * `$tries = 1` (the run owns resumption; the database key makes a repeat
 * harmless -- rule 59), `$timeout = 60` below every `retry_after` (rule
 * 58), a non-operational School pauses the run (rule 86; the executor
 * re-checks `SchoolOperationalGuard` per item), and tenant context comes
 * only from the dispatch-time capture (`TenantScoped`). Staff-triggered
 * only: nothing schedules this job.
 */
class ExecuteLateFeeRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantScoped;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $runId)
    {
        $this->captureTenantContext();
    }

    public function handle(LateFeeItemExecutor $executor, LateFeeRunService $runs): void
    {
        $school = School::query()->find($this->contextSchoolId);
        if ($school === null) {
            return;
        }

        $result = $executor->executeBatch($school, $this->runId);

        if ($result['paused']) {
            $runs->recordPaused($school, $this->runId);

            return;
        }

        if ($result['pendingRemaining']) {
            self::dispatch($this->runId);

            return;
        }

        $runs->finalize($school, $this->runId);
    }
}
