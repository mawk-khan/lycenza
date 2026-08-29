<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollAccountingConfiguration;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9.8 -- thin HTTP transport over
 * `PayrollAccountingAdministrationService::configure()`
 * (`payroll.accounting.manage`, already checked internally). No `show`
 * -- no read side/capability was built for this configuration
 * (Checkpoint 9.7's own documented reasoning), and `configure()` is an
 * idempotent upsert (`updateOrCreate` keyed on `school_id`) a caller
 * can safely call again to read back the effect of their own write via
 * the response body.
 */
class PayrollAccountingConfigurationController extends Controller
{
    public function store(Request $request, School $school, PayrollAccountingAdministrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'salary_expense_ledger_account_id' => ['required', 'uuid'],
            'salary_payable_ledger_account_id' => ['required', 'uuid'],
        ]);

        $config = $service->configure(
            $school,
            $validated['salary_expense_ledger_account_id'],
            $validated['salary_payable_ledger_account_id'],
            $request->user(),
        );

        return response()->json(['data' => $this->present($config)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PayrollAccountingConfiguration $config): array
    {
        return [
            'id' => $config->id,
            'salaryExpenseLedgerAccountId' => $config->salary_expense_ledger_account_id,
            'salaryPayableLedgerAccountId' => $config->salary_payable_ledger_account_id,
            'currency' => $config->currency,
        ];
    }
}
