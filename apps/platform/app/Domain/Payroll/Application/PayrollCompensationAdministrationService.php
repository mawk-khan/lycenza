<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Support\Carbon;

/**
 * Phase 9.7 -- the authorized ADMINISTRATIVE entry point for assigning
 * an Employee's compensation, mirroring
 * `App\Domain\Fees\Application\ChargeAdministrationService`'s exact
 * split from its own trusted core (`CompensationService`, which
 * performs no capability check itself). No transport may call
 * `CompensationService::assign()` directly; a future controller
 * depends on this class.
 *
 * `payroll.compensation.sensitive.manage` -- the Highly Sensitive
 * family (`docs/security/DATA-CLASSIFICATION.md`), separate from
 * `payroll.structures.manage` (policy/formula shape only): assigning
 * compensation writes an individual Employee's actual monetary value
 * (`compensation_assignment_values.amount`).
 */
class PayrollCompensationAdministrationService
{
    use AuthorizesCapability;

    public function __construct(private readonly CompensationService $compensation) {}

    /**
     * @param  list<FixedComponentValueInput>  $fixedValues
     */
    public function assign(
        School $school,
        EmploymentRecord $employmentRecord,
        SalaryStructure $structure,
        Carbon $effectiveFrom,
        array $fixedValues,
        User $actor,
    ): EmployeeCompensationAssignment {
        $this->authorizeCapabilityFor($actor, 'payroll.compensation.sensitive.manage', $school);

        return $this->compensation->assign($school, $employmentRecord, $structure, $effectiveFrom, $fixedValues, $actor);
    }
}
