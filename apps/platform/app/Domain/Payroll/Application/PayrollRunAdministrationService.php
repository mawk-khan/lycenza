<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\Exceptions\PayrollSelfAdministrationException;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.7 (corrected at its own authorization review) -- the
 * authorized ADMINISTRATIVE entry point for `payroll_runs`' prepare/
 * calculate/approve lifecycle, mirroring
 * `PayrollStructureAdministrationService`'s exact split from its own
 * trusted core (`PayrollRunService`, which performs no capability
 * check itself). No transport may call `PayrollRunService` directly;
 * a future controller depends on this class.
 *
 * `payroll.runs.prepare` gates create (regular and correction),
 * recording manual-override/correction-delta adjustments, and
 * calculating/recalculating. `payroll.runs.approve` is a SEPARATE
 * capability gating ONLY `approve()` -- mirroring
 * `PayrollPostingAdministrationService`'s identical per-method
 * distinct-capability shape (itself mirroring
 * `App\Domain\Finance\Application\LedgerAdministrationService`'s
 * `post`/`reverse` split). Least-privilege authorization is a
 * capability-level property: an actor holding only `.prepare` can
 * never approve a run, and an actor holding only `.approve` can never
 * create/calculate one, REGARDLESS of the separate, actor-level
 * `preparer != approver` rule `PayrollRunService::approve()`'s own
 * `SelfApprovalNotAllowedException` (plus the database CHECK
 * `payroll_runs_sod_check`) enforces -- that actor-level rule remains
 * necessary (an actor holding BOTH capabilities must still never
 * approve their own prepared run) but is never treated as a
 * substitute for splitting the capabilities themselves.
 */
class PayrollRunAdministrationService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly PayrollRunService $runs,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly ApprovalDecisionAuthority $authority,
        private readonly TenantContext $context,
    ) {}

    public function createRun(PayrollPeriod $period, User $actor): PayrollRun
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.prepare', $period->school);

        return $this->runs->createRun($period, $actor);
    }

    public function createCorrectionRun(PayrollRun $correctsRun, PayrollPeriod $period, User $actor): PayrollRun
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.prepare', $period->school);

        return $this->runs->createCorrectionRun($correctsRun, $period, $actor);
    }

    /**
     * @param  array<string, string>  $componentAmounts
     */
    public function recordManualOverride(PayrollRun $run, EmploymentRecord $employmentRecord, array $componentAmounts, string $reason, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.prepare', $run->school);
        // SR.4 (ADR 0071 §26.4): nobody sets their own pay.
        if ($this->selfAdministration->ownsEmployment($actor, $run->school, $employmentRecord->id)) {
            throw new PayrollSelfAdministrationException('run.manual_override');
        }

        $this->runs->recordManualOverride($run, $employmentRecord, $componentAmounts, $reason, $actor);
    }

    /**
     * @param  list<CorrectionDeltaInput>  $lines
     */
    public function recordCorrectionDelta(PayrollRun $run, EmploymentRecord $employmentRecord, array $lines, string $reason, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.prepare', $run->school);
        // SR.4 (ADR 0071 §26.4): nobody sets their own pay.
        if ($this->selfAdministration->ownsEmployment($actor, $run->school, $employmentRecord->id)) {
            throw new PayrollSelfAdministrationException('run.correction_delta');
        }

        $this->runs->recordCorrectionDelta($run, $employmentRecord, $lines, $reason, $actor);
    }

    public function calculate(PayrollRun $run, User $actor): PayrollCalculationOutcome
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.prepare', $run->school);

        return $this->runs->calculate($run, $actor);
    }

    /**
     * `payroll.runs.approve` ONLY -- deliberately does NOT accept
     * `payroll.runs.prepare` as an alternative. An actor is never
     * authorized to approve merely because they can prepare payroll.
     */
    public function approve(PayrollRun $run, User $approver, ?FreshMfaProof $mfa = null): PayrollRun
    {
        $this->authorizeCapabilityFor($approver, 'payroll.runs.approve', $run->school);

        // ORG.8B (ADR 0076 §60): under the run lock, the approver's CURRENT authority (fresh grant,
        // module and School read), then the core decision (participants, results, transition) in the
        // same transaction.
        return $this->context->withSchool($run->school, fn (): PayrollRun => DB::transaction(function () use ($run, $approver, $mfa): PayrollRun {
            PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->authority->requireCurrent($approver, 'payroll.runs.approve', $run->school);

            return $this->runs->approve($run, $approver, $mfa);
        }));
    }
}
