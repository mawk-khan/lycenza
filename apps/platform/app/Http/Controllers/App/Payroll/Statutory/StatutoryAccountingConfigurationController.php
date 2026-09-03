<?php

namespace App\Http\Controllers\App\Payroll\Statutory;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Statutory\Application\Admin\StatutoryAccountingAdministrationService;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryPayrollException;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkpoint 9.6I (Section 4) -- session-authenticated Inertia page
 * for the statutory Finance account mapping, mirroring
 * `App\Http\Controllers\App\Payroll\PayrollAccountingConfigurationController`'s
 * exact shape.
 */
class StatutoryAccountingConfigurationController extends Controller
{
    use AuthorizesCapability;

    private const ACCOUNT_FIELDS = [
        'employee_pf_payable_ledger_account_id',
        'employer_eps_payable_ledger_account_id',
        'employer_epf_payable_ledger_account_id',
        'pf_admin_charge_payable_ledger_account_id',
        'edli_payable_ledger_account_id',
        'esi_payable_ledger_account_id',
        'tds_payable_ledger_account_id',
        'professional_tax_payable_ledger_account_id',
        'lwf_payable_ledger_account_id',
        'employer_pf_contribution_expense_ledger_account_id',
        'pf_admin_charge_expense_ledger_account_id',
        'edli_expense_ledger_account_id',
        'employer_esi_contribution_expense_ledger_account_id',
        'employer_lwf_contribution_expense_ledger_account_id',
    ];

    public function show(TenantContext $context, StatutoryAccountingAdministrationService $service): Response
    {
        $school = $context->requireSchool();
        $config = $service->view($school, $context->actor());

        $accounts = $context->withSchool($school, fn () => LedgerAccount::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']));

        return Inertia::render('App/Payroll/Statutory/Accounting/Show', [
            'configuration' => $config === null ? null : collect(self::ACCOUNT_FIELDS)
                ->mapWithKeys(fn (string $field) => [$this->camel($field) => $config->{$field}])
                ->merge(['currency' => $config->currency])
                ->all(),
            'accounts' => $accounts->map(fn (LedgerAccount $a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
            ])->all(),
        ]);
    }

    public function update(Request $request, TenantContext $context, StatutoryAccountingAdministrationService $service): RedirectResponse
    {
        $school = $context->requireSchool();

        $rules = array_fill_keys(self::ACCOUNT_FIELDS, ['required', 'uuid']);
        $validated = $request->validate($rules);

        try {
            $service->configure($school, $validated, $context->actor());
        } catch (StatutoryPayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return redirect('/app/payroll/statutory/accounting');
    }

    private function camel(string $snake): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $snake))));
    }
}
