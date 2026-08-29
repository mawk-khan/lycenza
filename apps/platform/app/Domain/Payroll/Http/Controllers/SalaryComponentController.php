<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureReadService;
use App\Domain\Payroll\Application\SalaryComponentSummary;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 9.8 -- thin HTTP transport over `PayrollStructureReadService`
 * (reads) and `PayrollStructureAdministrationService` (writes) --
 * never the trusted cores (`SalaryComponentService`/
 * `SalaryStructureService`) directly, mirroring
 * `App\Domain\Finance\Http\Controllers\JournalEntryController`'s exact
 * split. `{salaryComponent}` is a plain route parameter resolved via
 * `SalaryComponent::query()->findOrFail()` -- safe because
 * `school-membership` middleware has already set `TenantContext`
 * (hence `SchoolScope`) before this controller runs, exactly like
 * `App\Domain\Timetable\Http\Controllers\TimetablePeriodController`'s
 * identical established resolution pattern; a cross-School id 404s
 * the same as a nonexistent one.
 */
class SalaryComponentController extends Controller
{
    public function index(School $school, PayrollStructureReadService $service): JsonResponse
    {
        $components = $service->listComponents($school, request()->user());

        return response()->json([
            'data' => array_map(fn (SalaryComponentSummary $c) => $this->present($c), $components),
        ]);
    }

    public function store(Request $request, School $school, PayrollStructureAdministrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['earning', 'deduction'])],
            'liability_ledger_account_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $component = $service->createComponent(
            $school,
            $validated['code'],
            $validated['name'],
            $validated['type'],
            $validated['liability_ledger_account_id'] ?? null,
            $request->user(),
        );

        return response()->json(['data' => $this->present(SalaryComponentSummary::fromModel($component))], 201);
    }

    public function deactivate(Request $request, School $school, string $salaryComponent, PayrollStructureAdministrationService $service): JsonResponse
    {
        $component = SalaryComponent::query()->findOrFail($salaryComponent);
        $component = $service->deactivateComponent($component, $request->user());

        return response()->json(['data' => $this->present(SalaryComponentSummary::fromModel($component))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SalaryComponentSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'code' => $summary->code,
            'name' => $summary->name,
            'type' => $summary->type,
            'liabilityLedgerAccountId' => $summary->liabilityLedgerAccountId,
            'status' => $summary->status,
        ];
    }
}
