<?php

namespace App\Domain\Payroll\Statutory\Http\Controllers;

use App\Domain\Payroll\Statutory\Application\Admin\StatutoryAccountingAdministrationService;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryAccountingConfiguration;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checkpoint 9.6I (Section 2 "Statutory accounting configuration") --
 * thin HTTP transport over `StatutoryAccountingAdministrationService`,
 * mirroring `PayrollAccountingConfigurationController`'s exact shape.
 * Every account id is validated as a UUID here; same-School/type/
 * status validation happens inside `StatutoryAccountingConfigurationService::validateAccounts()`
 * (Checkpoint 9.6F) -- never a second, divergent check here.
 */
class StatutoryAccountingConfigurationController extends Controller
{
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

    public function show(Request $request, School $school, StatutoryAccountingAdministrationService $service): JsonResponse
    {
        $config = $service->view($school, $request->user());

        return response()->json(['data' => $config === null ? null : $this->present($config)]);
    }

    public function store(Request $request, School $school, StatutoryAccountingAdministrationService $service): JsonResponse
    {
        $rules = array_fill_keys(self::ACCOUNT_FIELDS, ['required', 'uuid']);
        $validated = $request->validate($rules);

        $config = $service->configure($school, $validated, $request->user());

        return response()->json(['data' => $this->present($config)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PayrollStatutoryAccountingConfiguration $config): array
    {
        return [
            'employeePfPayableLedgerAccountId' => $config->employee_pf_payable_ledger_account_id,
            'employerEpsPayableLedgerAccountId' => $config->employer_eps_payable_ledger_account_id,
            'employerEpfPayableLedgerAccountId' => $config->employer_epf_payable_ledger_account_id,
            'pfAdminChargePayableLedgerAccountId' => $config->pf_admin_charge_payable_ledger_account_id,
            'edliPayableLedgerAccountId' => $config->edli_payable_ledger_account_id,
            'esiPayableLedgerAccountId' => $config->esi_payable_ledger_account_id,
            'tdsPayableLedgerAccountId' => $config->tds_payable_ledger_account_id,
            'professionalTaxPayableLedgerAccountId' => $config->professional_tax_payable_ledger_account_id,
            'lwfPayableLedgerAccountId' => $config->lwf_payable_ledger_account_id,
            'employerPfContributionExpenseLedgerAccountId' => $config->employer_pf_contribution_expense_ledger_account_id,
            'pfAdminChargeExpenseLedgerAccountId' => $config->pf_admin_charge_expense_ledger_account_id,
            'edliExpenseLedgerAccountId' => $config->edli_expense_ledger_account_id,
            'employerEsiContributionExpenseLedgerAccountId' => $config->employer_esi_contribution_expense_ledger_account_id,
            'employerLwfContributionExpenseLedgerAccountId' => $config->employer_lwf_contribution_expense_ledger_account_id,
            'currency' => $config->currency,
        ];
    }
}
