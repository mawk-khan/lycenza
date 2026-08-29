<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;

/**
 * Phase 9.7 -- the authorized ADMINISTRATIVE entry point for
 * `payroll_runs`' prepare/calculate/approve lifecycle, mirroring
 * `PayrollStructureAdministrationService`'s exact split from its own
 * trusted core (`PayrollRunService`, which performs no capability
 * check itself). No transport may call `PayrollRunService` directly;
 * a future controller depends on this class.
 *
 * A SINGLE capability, `payroll.runs.manage`, covers create
 * (regular and correction), recording manual-override/correction-delta
 * adjustments, calculating, AND approving -- Separation of Duties for
 * approval is an ACTOR-level rule (the preparer of a run may never
 * approve it, enforced by `PayrollRunService::approve()`'s own
 * `SelfApprovalNotAllowedException` check plus the database CHECK
 * `payroll_runs_sod_check`), never a capability-level one, so splitting
 * this into separate prepare/approve capabilities would add no
 * additional real guarantee (mirrors
 * `App\Domain\Fees\Application\ChargeAdministrationService`'s identical
 * reasoning for not splitting assess/cancel). `payroll.runs.post`/
 * `.reverse` are deliberately SEPARATE from `payroll.runs.manage` --
 * see `PayrollPostingAdministrationService`.
 */
class PayrollRunAdministrationService
{
    use AuthorizesCapability;

    public function __construct(private readonly PayrollRunService $runs) {}

    public function createRun(PayrollPeriod $period, User $actor): PayrollRun
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.manage', $period->school);

        return $this->runs->createRun($period, $actor);
    }

    public function createCorrectionRun(PayrollRun $correctsRun, PayrollPeriod $period, User $actor): PayrollRun
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.manage', $period->school);

        return $this->runs->createCorrectionRun($correctsRun, $period, $actor);
    }

    /**
     * @param  array<string, string>  $componentAmounts
     */
    public function recordManualOverride(PayrollRun $run, EmploymentRecord $employmentRecord, array $componentAmounts, string $reason, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.manage', $run->school);

        $this->runs->recordManualOverride($run, $employmentRecord, $componentAmounts, $reason, $actor);
    }

    /**
     * @param  list<CorrectionDeltaInput>  $lines
     */
    public function recordCorrectionDelta(PayrollRun $run, EmploymentRecord $employmentRecord, array $lines, string $reason, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.manage', $run->school);

        $this->runs->recordCorrectionDelta($run, $employmentRecord, $lines, $reason, $actor);
    }

    public function calculate(PayrollRun $run, User $actor): PayrollCalculationOutcome
    {
        $this->authorizeCapabilityFor($actor, 'payroll.runs.manage', $run->school);

        return $this->runs->calculate($run, $actor);
    }

    public function approve(PayrollRun $run, User $approver): PayrollRun
    {
        $this->authorizeCapabilityFor($approver, 'payroll.runs.manage', $run->school);

        return $this->runs->approve($run, $approver);
    }
}
