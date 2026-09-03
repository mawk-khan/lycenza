<?php

namespace App\Domain\Payroll\Statutory\Application\Admin;

use App\Domain\Payroll\Statutory\Application\StatutoryAccountingConfigurationService;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryAccountingConfiguration;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Checkpoint 9.6I -- mirrors the established
 * `App\Domain\Payroll\Application\PayrollAccountingAdministrationService`
 * "core service + capability-checking Administration wrapper" split:
 * `StatutoryAccountingConfigurationService` (Checkpoint 9.6F) has no
 * capability check of its own (it is also called internally by
 * `StatutoryPayrollPostingService`, which authorizes at the posting
 * boundary instead) -- only THIS wrapper is ever reachable from a
 * controller.
 */
class StatutoryAccountingAdministrationService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly StatutoryAccountingConfigurationService $config,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, string>  $accountIds
     */
    public function configure(School $school, array $accountIds, User $actor): PayrollStatutoryAccountingConfiguration
    {
        $this->authorizeCapabilityFor($actor, 'payroll.statutory.manage', $school);

        return $this->config->configure($school, $accountIds, $actor);
    }

    public function view(School $school, User $actor): ?PayrollStatutoryAccountingConfiguration
    {
        $this->authorizeCapabilityFor($actor, 'payroll.statutory.view', $school);

        return $this->context->withSchool($school, fn () => PayrollStatutoryAccountingConfiguration::query()->where('school_id', $school->id)->first());
    }
}
