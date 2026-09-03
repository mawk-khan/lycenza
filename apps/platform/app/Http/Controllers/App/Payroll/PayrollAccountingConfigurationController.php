<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollAccountingConfiguration;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9.9 -- session-authenticated Inertia page for the Payroll ->
 * Finance accounting configuration seam. No dedicated read
 * service/capability was built here yet (the JSON API controller's own
 * documented reasoning: "no read side/capability was built for this
 * configuration") -- this page's own read is a direct, tenant-scoped,
 * non-sensitive query (account codes/names/ids, never a monetary
 * figure) gated on the SAME `payroll.accounting.manage` capability
 * `configure()` itself requires, mirroring `PayrollPeriodController`'s
 * identical "no dedicated read service yet" reasoning.
 *
 * Also surfaces whether every active deduction-type SalaryComponent
 * has a resolved liability ledger account mapping -- required reading
 * before a run can be posted (`PAYROLL_DEDUCTION_MISSING_LEDGER_MAPPING`)
 * -- so the posting UI can explain a missing prerequisite instead of
 * merely failing.
 */
class PayrollAccountingConfigurationController extends Controller
{
    use AuthorizesCapability;

    public function show(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('payroll.accounting.manage', $school);

        $config = $context->withSchool($school, fn () => PayrollAccountingConfiguration::query()
            ->where('school_id', $school->id)
            ->with(['salaryExpenseLedgerAccount', 'salaryPayableLedgerAccount'])
            ->first());

        $missingMappings = $context->withSchool($school, fn () => SalaryComponent::query()
            ->where('school_id', $school->id)
            ->where('type', 'deduction')
            ->where('status', 'active')
            ->whereNull('liability_ledger_account_id')
            ->get(['id', 'code', 'name']));

        $accounts = LedgerAccount::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        return Inertia::render('App/Payroll/Accounting/Show', [
            'configuration' => $config === null ? null : [
                'id' => $config->id,
                'salaryExpenseLedgerAccountId' => $config->salary_expense_ledger_account_id,
                'salaryExpenseLedgerAccountLabel' => $config->salaryExpenseLedgerAccount
                    ? "{$config->salaryExpenseLedgerAccount->code} — {$config->salaryExpenseLedgerAccount->name}"
                    : null,
                'salaryPayableLedgerAccountId' => $config->salary_payable_ledger_account_id,
                'salaryPayableLedgerAccountLabel' => $config->salaryPayableLedgerAccount
                    ? "{$config->salaryPayableLedgerAccount->code} — {$config->salaryPayableLedgerAccount->name}"
                    : null,
                'currency' => $config->currency,
            ],
            'missingDeductionMappings' => $missingMappings->map(fn (SalaryComponent $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
            ])->all(),
            'accounts' => $accounts->map(fn (LedgerAccount $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
            ])->all(),
        ]);
    }

    public function update(Request $request, TenantContext $context, PayrollAccountingAdministrationService $service): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'salary_expense_ledger_account_id' => ['required', 'uuid'],
            'salary_payable_ledger_account_id' => ['required', 'uuid'],
        ]);

        $service->configure(
            $school,
            $validated['salary_expense_ledger_account_id'],
            $validated['salary_payable_ledger_account_id'],
            $context->actor(),
        );

        return redirect('/app/payroll/accounting');
    }
}
