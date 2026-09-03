<?php

namespace App\Domain\Payroll\Statutory\Application;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryAccountingAccountInvalidException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryAccountingNotConfiguredException;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryAccountingConfiguration;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 9.6F (ADR 0035 correction addendum §1.11) -- the
 * School-scoped statutory liability/expense account configuration
 * seam, mirroring
 * `App\Domain\Payroll\Application\PayrollAccountingConfigurationService`'s
 * exact shape: `configure()` (eager validation, better UX) and
 * `resolveValidated()` (the single authoritative pre-posting check,
 * re-validated live -- an account's status/type can change after the
 * configuration was saved).
 */
class StatutoryAccountingConfigurationService
{
    private const ACCOUNT_TYPES = [
        'employee_pf_payable_ledger_account_id' => 'liability',
        'employer_eps_payable_ledger_account_id' => 'liability',
        'employer_epf_payable_ledger_account_id' => 'liability',
        'pf_admin_charge_payable_ledger_account_id' => 'liability',
        'edli_payable_ledger_account_id' => 'liability',
        'esi_payable_ledger_account_id' => 'liability',
        'tds_payable_ledger_account_id' => 'liability',
        'professional_tax_payable_ledger_account_id' => 'liability',
        'lwf_payable_ledger_account_id' => 'liability',
        'employer_pf_contribution_expense_ledger_account_id' => 'expense',
        'pf_admin_charge_expense_ledger_account_id' => 'expense',
        'edli_expense_ledger_account_id' => 'expense',
        'employer_esi_contribution_expense_ledger_account_id' => 'expense',
        'employer_lwf_contribution_expense_ledger_account_id' => 'expense',
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, string>  $accountIds  keyed by the 12 columns in self::ACCOUNT_TYPES
     */
    public function configure(School $school, array $accountIds, User $actor): PayrollStatutoryAccountingConfiguration
    {
        return $this->context->withSchool($school, function () use ($school, $accountIds, $actor) {
            return DB::transaction(function () use ($school, $accountIds, $actor) {
                $this->validateAccounts($school, $accountIds);

                $config = PayrollStatutoryAccountingConfiguration::query()->updateOrCreate(
                    ['school_id' => $school->id],
                    $accountIds + ['currency' => 'INR'],
                );

                $this->audit->school($school, 'payroll.statutory_accounting_configuration.saved', actor: $actor, subject: $config, metadata: $accountIds);

                return $config;
            });
        });
    }

    public function resolveValidated(School $school): PayrollStatutoryAccountingConfiguration
    {
        return $this->context->withSchool($school, function () use ($school) {
            $config = PayrollStatutoryAccountingConfiguration::query()->where('school_id', $school->id)->first();

            if ($config === null) {
                throw new StatutoryAccountingNotConfiguredException;
            }

            $this->validateAccounts($school, $config->only(array_keys(self::ACCOUNT_TYPES)));

            return $config;
        });
    }

    /**
     * @param  array<string, string>  $accountIds
     */
    private function validateAccounts(School $school, array $accountIds): void
    {
        foreach (self::ACCOUNT_TYPES as $column => $expectedType) {
            $accountId = $accountIds[$column] ?? null;

            if ($accountId === null) {
                throw new StatutoryAccountingAccountInvalidException("'{$column}' is not configured.");
            }

            $account = LedgerAccount::query()->where('school_id', $school->id)->find($accountId);

            if ($account === null) {
                throw new StatutoryAccountingAccountInvalidException("'{$column}' does not reference a valid ledger account for this School.");
            }

            if (! $account->isActive()) {
                throw new StatutoryAccountingAccountInvalidException("'{$column}' references an inactive ledger account.");
            }

            if ($account->type !== $expectedType) {
                throw new StatutoryAccountingAccountInvalidException("'{$column}' must reference an account of type '{$expectedType}'.");
            }
        }
    }
}
