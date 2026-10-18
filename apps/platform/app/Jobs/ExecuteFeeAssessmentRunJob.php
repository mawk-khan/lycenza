<?php

namespace App\Jobs;

use App\Domain\Fees\Application\FeeAssessmentItemExecutor;
use App\Domain\Fees\Application\FeeAssessmentRunService;
use App\Domain\Platform\Application\Modules\ExecutesModuleWork;
use App\Domain\Platform\Application\Modules\ModuleExecutionGuard;
use App\Models\School;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * FEE.2 (ADR 0062 §13): executes one bounded batch of an assessment run's
 * pending items, then re-dispatches itself while items remain, or
 * finalizes the run.
 *
 * - `$tries = 1`: the run owns its own resumption (only pending items are
 *   ever picked up, and the database key makes a repeat harmless); queue
 *   retries must not stack on it (rule 59).
 * - `$timeout = 60`, well below every connection's `retry_after = 90`
 *   (rule 58).
 * - School lifecycle (rule 86): `FeeAssessmentItemExecutor` re-checks the
 *   School with `SchoolOperationalGuard` inside every item transaction. A
 *   non-operational School PAUSES the run: no item changes, one
 *   `fee_assessment_run.paused` audit, no re-dispatch; staff resume it
 *   after reactivation.
 * - Tenant context comes only from the dispatch-time capture
 *   (`TenantScoped`); it is set and cleared by `SetTenantContextForJob`.
 */
class ExecuteFeeAssessmentRunJob implements ExecutesModuleWork, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantScoped;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $runId)
    {
        $this->captureTenantContext();
    }

    /**
     * ORG.5 (ADR 0076 §31): the executor re-checks the Fees entitlement
     * inside every item transaction; a School no longer entitled PAUSES the
     * run exactly like a non-operational one (no item changes, no
     * re-dispatch). Resuming needs a Fees capability, which resolves to
     * nothing until the module is enabled again.
     */
    public static function executesModule(): string
    {
        return 'fees';
    }

    public function handle(FeeAssessmentItemExecutor $executor, FeeAssessmentRunService $runs): void
    {
        $school = School::query()->find($this->contextSchoolId);
        if ($school === null) {
            return;
        }

        $result = $executor->executeBatch($school, $this->runId);

        if ($result['paused']) {
            $runs->recordPaused($school, $this->runId, app(ModuleExecutionGuard::class)->allows($school, self::executesModule()) ? 'school_not_operational' : 'module_not_entitled');

            return;
        }

        if ($result['pendingRemaining']) {
            self::dispatch($this->runId);

            return;
        }

        $runs->finalize($school, $this->runId);
    }
}
