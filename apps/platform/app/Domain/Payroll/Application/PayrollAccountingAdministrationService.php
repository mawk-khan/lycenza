<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollAccountingConfiguration;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;

/**
 * Phase 9.7 -- the authorized ADMINISTRATIVE entry point for
 * `payroll_accounting_configurations`, mirroring
 * `PayrollStructureAdministrationService`'s exact split from its own
 * trusted core (`PayrollAccountingConfigurationService`, which
 * performs no capability check itself). No transport may call
 * `PayrollAccountingConfigurationService::configure()` directly; a
 * future controller depends on this class. `payroll.accounting.manage`
 * mirrors `canteen.settings.manage`'s identical "financial account
 * configuration" role.
 */
class PayrollAccountingAdministrationService
{
    use AuthorizesCapability;

    public function __construct(private readonly PayrollAccountingConfigurationService $config) {}

    public function configure(School $school, string $salaryExpenseLedgerAccountId, string $salaryPayableLedgerAccountId, User $actor): PayrollAccountingConfiguration
    {
        $this->authorizeCapabilityFor($actor, 'payroll.accounting.manage', $school);

        return $this->config->configure($school, $salaryExpenseLedgerAccountId, $salaryPayableLedgerAccountId, $actor);
    }
}
