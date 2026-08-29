<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 9.8 -- the authorized read path for Payroll's policy/formula
 * shape (`payroll.structures.view`), completing the view/manage pair
 * `payroll.structures.*` was registered as in Checkpoint 9.7 (that
 * checkpoint enforced `.manage` but never gave `.view` a consumer --
 * closed here rather than left as a dead capability). Never an
 * Employee-specific monetary value -- see `PayrollCompensationReadService`
 * for that Highly Sensitive family instead.
 */
class PayrollStructureReadService
{
    use AuthorizesCapability;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<SalaryComponentSummary>
     */
    public function listComponents(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'payroll.structures.view', $school);

        return $this->context->withSchool($school, function () use ($school) {
            return SalaryComponent::query()
                ->where('school_id', $school->id)
                ->orderBy('code')
                ->get()
                ->map(fn (SalaryComponent $c) => SalaryComponentSummary::fromModel($c))
                ->all();
        });
    }

    /**
     * @return list<SalaryStructureSummary>
     */
    public function listStructures(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'payroll.structures.view', $school);

        return $this->context->withSchool($school, function () use ($school) {
            return SalaryStructure::query()
                ->where('school_id', $school->id)
                ->orderBy('code')
                ->orderByDesc('version')
                ->get()
                ->map(fn (SalaryStructure $s) => SalaryStructureSummary::fromModel($s))
                ->all();
        });
    }

    public function getStructureDetail(School $school, SalaryStructure $structure, User $actor): SalaryStructureDetail
    {
        $this->authorizeCapabilityFor($actor, 'payroll.structures.view', $school);

        return $this->context->withSchool($school, function () use ($structure) {
            $structure->loadMissing('components');

            return SalaryStructureDetail::fromModel($structure);
        });
    }
}
