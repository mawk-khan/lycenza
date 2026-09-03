<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Domain\Payroll\Infrastructure\SalaryStructureComponent;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;

/**
 * Phase 9.7 -- the authorized ADMINISTRATIVE entry point for Payroll
 * structure/component policy, mirroring
 * `App\Domain\Finance\Application\LedgerAdministrationService`'s exact
 * split from its own trusted cores (`SalaryComponentService`,
 * `SalaryStructureService`, neither of which perform any capability
 * check themselves -- see their own docblocks). No transport may call
 * `SalaryComponentService`/`SalaryStructureService` directly; a future
 * controller depends on this class.
 *
 * Single capability, `payroll.structures.manage`, gates every
 * mutation here -- this family gates formula/policy shape only
 * (component names, calculation types, rates, ordering), never an
 * individual Employee's actual monetary value
 * (`payroll.compensation.sensitive.*` is the separate, Highly
 * Sensitive family for that -- see `PayrollCompensationAdministrationService`).
 */
class PayrollStructureAdministrationService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly SalaryComponentService $components,
        private readonly SalaryStructureService $structures,
    ) {}

    public function createComponent(School $school, string $code, string $name, string $type, ?string $liabilityLedgerAccountId, User $actor): SalaryComponent
    {
        $this->authorizeCapabilityFor($actor, 'payroll.structures.manage', $school);

        return $this->components->create($school, $code, $name, $type, $liabilityLedgerAccountId, $actor);
    }

    public function deactivateComponent(SalaryComponent $component, User $actor): SalaryComponent
    {
        $this->authorizeCapabilityFor($actor, 'payroll.structures.manage', $component->school);

        return $this->components->deactivate($component, $actor);
    }

    public function createDraftStructure(School $school, string $code, string $name, User $actor): SalaryStructure
    {
        $this->authorizeCapabilityFor($actor, 'payroll.structures.manage', $school);

        return $this->structures->createDraft($school, $code, $name, $actor);
    }

    public function addStructureComponent(SalaryStructure $structure, AddStructureComponentData $data, User $actor): SalaryStructureComponent
    {
        $this->authorizeCapabilityFor($actor, 'payroll.structures.manage', $structure->school);

        return $this->structures->addComponent($structure, $data, $actor);
    }

    public function activateStructure(SalaryStructure $structure, User $actor): SalaryStructure
    {
        $this->authorizeCapabilityFor($actor, 'payroll.structures.manage', $structure->school);

        return $this->structures->activate($structure, $actor);
    }
}
