<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\Exceptions\PayrollAccountingAccountInvalidException;
use App\Domain\Payroll\Application\Exceptions\PayrollAccountingNotConfiguredException;
use App\Domain\Payroll\Infrastructure\PayrollAccountingConfiguration;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.5 (ADR 0034 "Deduction accounting") -- the Payroll -> Finance
 * configuration seam named by `payroll_accounting_configurations`'
 * own migration docblock. Thin CRUD-with-audit for the School-wide
 * singleton configuration, plus the one real invariant shared between
 * "save the configuration" and "post a run": `resolveValidated()` is
 * the SINGLE place account-type/active validation lives, called by
 * both `configure()` (eager validation at save time, better UX) and
 * `PayrollPostingService::post()` (authoritative validation at posting
 * time -- an account's status/type can change after the configuration
 * was saved, so posting never trusts a stale save-time check alone).
 * Mirrors `App\Domain\Canteen\Application\CanteenBillingConfigurationService`'s
 * identical shape exactly.
 *
 * Reads `App\Domain\Finance\Infrastructure\LedgerAccount` directly
 * (read-only) -- Payroll already depends on Finance (composite FKs on
 * `salary_components`/`payroll_accounting_configurations`/
 * `payroll_run_result_lines`/`payroll_run_postings`), and no Finance
 * Application-layer read service exposes account-type/status
 * validation in the shape this module needs, mirroring
 * Canteen/Hostel/Library's own established precedent of reading a
 * depended-upon module's model directly for a narrow, read-only
 * purpose (never a write).
 *
 * Capability gating (`payroll.accounting.manage`) is deliberately
 * deferred to Checkpoint 9.7, exactly as `SalaryComponentService`'s
 * identical note -- this class takes an explicit `User $actor` now
 * (for audit attribution) but performs no capability check.
 */
class PayrollAccountingConfigurationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function configure(School $school, string $salaryExpenseLedgerAccountId, string $salaryPayableLedgerAccountId, User $actor): PayrollAccountingConfiguration
    {
        return $this->context->withSchool($school, function () use ($school, $salaryExpenseLedgerAccountId, $salaryPayableLedgerAccountId, $actor) {
            return DB::transaction(function () use ($school, $salaryExpenseLedgerAccountId, $salaryPayableLedgerAccountId, $actor) {
                $this->validateAccounts($school, $salaryExpenseLedgerAccountId, $salaryPayableLedgerAccountId);

                $config = PayrollAccountingConfiguration::query()->updateOrCreate(
                    ['school_id' => $school->id],
                    [
                        'salary_expense_ledger_account_id' => $salaryExpenseLedgerAccountId,
                        'salary_payable_ledger_account_id' => $salaryPayableLedgerAccountId,
                        'currency' => 'INR',
                    ],
                );

                $this->audit->school($school, 'payroll.accounting_configuration.saved', actor: $actor, subject: $config, metadata: [
                    'salaryExpenseLedgerAccountId' => $salaryExpenseLedgerAccountId,
                    'salaryPayableLedgerAccountId' => $salaryPayableLedgerAccountId,
                ]);

                return $config;
            });
        });
    }

    /**
     * The authoritative pre-posting check: a configuration row must
     * exist, and both accounts must exist/be active/belong to this
     * School/have the correct type (expense for salary expense,
     * liability for salary payable). Returns the validated
     * configuration row so `PayrollPostingService` never has to
     * re-query it. Callers that already hold an outer
     * `TenantContext::withSchool()`/transaction (as `PayrollPostingService::post()`
     * does) simply nest here -- safe by construction, see
     * `TenantContext::withSchool()`'s own save/restore-stack shape.
     */
    public function resolveValidated(School $school): PayrollAccountingConfiguration
    {
        return $this->context->withSchool($school, function () use ($school) {
            $config = PayrollAccountingConfiguration::query()->where('school_id', $school->id)->first();

            if ($config === null) {
                throw new PayrollAccountingNotConfiguredException;
            }

            $this->validateAccounts($school, $config->salary_expense_ledger_account_id, $config->salary_payable_ledger_account_id);

            return $config;
        });
    }

    /**
     * `type` alone already makes the two accounts structurally
     * distinct (a single `ledger_accounts` row carries exactly one
     * `type`, so no row can simultaneously be `expense` AND
     * `liability`) -- an explicit "must differ" check would be
     * unreachable dead code given the two type checks below, so,
     * unlike Canteen's otherwise-identical `validateAccounts()`, one
     * is deliberately not added here (mirrors
     * `App\Domain\Finance\Application\LedgerService::assertBalanced()`'s
     * own "deliberately omitted" reasoning for a structurally implied
     * check).
     */
    private function validateAccounts(School $school, string $salaryExpenseLedgerAccountId, string $salaryPayableLedgerAccountId): void
    {
        $expense = LedgerAccount::query()->where('school_id', $school->id)->find($salaryExpenseLedgerAccountId);
        $payable = LedgerAccount::query()->where('school_id', $school->id)->find($salaryPayableLedgerAccountId);

        if ($expense === null || $payable === null) {
            throw new PayrollAccountingAccountInvalidException('one or both ledger accounts could not be found for this School.');
        }

        if (! $expense->isActive() || ! $payable->isActive()) {
            throw new PayrollAccountingAccountInvalidException('one or both ledger accounts are inactive.');
        }

        if ($expense->type !== 'expense') {
            throw new PayrollAccountingAccountInvalidException("the salary expense account must be of type 'expense'.");
        }

        if ($payable->type !== 'liability') {
            throw new PayrollAccountingAccountInvalidException("the salary payable account must be of type 'liability'.");
        }
    }
}
