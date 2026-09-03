<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationAssignmentSummary;
use App\Domain\Payroll\Application\CompensationAssignmentValueDetail;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationReadService;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 9.8 -- thin HTTP transport over `PayrollCompensationReadService`
 * (two sensitivity tiers: `index()` non-sensitive, `values()` Highly
 * Sensitive) and `PayrollCompensationAdministrationService::assign()`.
 * `{employmentRecord}` and `{compensationAssignment}` are plain route
 * parameters, resolved the same way every other Payroll controller
 * resolves its route parameters -- see `SalaryComponentController`'s
 * docblock. `EmploymentRecord` belongs to HR, not Payroll -- resolved
 * here read-only via Eloquent (already an established cross-module
 * read pattern, e.g. `App\Domain\Timetable\Http\Controllers\TimetableEntryController`'s
 * own Employee/Section lookups), never written to.
 */
class CompensationAssignmentController extends Controller
{
    public function index(School $school, string $employmentRecord, PayrollCompensationReadService $service): JsonResponse
    {
        $record = EmploymentRecord::query()->findOrFail($employmentRecord);
        $assignments = $service->listAssignments($school, $record, request()->user());

        return response()->json([
            'data' => array_map(fn (CompensationAssignmentSummary $a) => $this->presentSummary($a), $assignments),
        ]);
    }

    public function values(School $school, string $compensationAssignment, PayrollCompensationReadService $service): JsonResponse
    {
        $values = $service->getAssignmentValues($school, $compensationAssignment, request()->user());

        return response()->json([
            'data' => array_map(fn (CompensationAssignmentValueDetail $v) => $this->presentValue($v), $values),
        ]);
    }

    public function store(Request $request, School $school, string $employmentRecord, PayrollCompensationAdministrationService $service): JsonResponse
    {
        $record = EmploymentRecord::query()->findOrFail($employmentRecord);

        $validated = $request->validate([
            'salary_structure_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
            'fixed_values' => ['required', 'array', 'min:0'],
            'fixed_values.*.salary_structure_component_id' => ['required', 'uuid'],
            'fixed_values.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);

        $structure = SalaryStructure::query()->findOrFail($validated['salary_structure_id']);

        $fixedValues = array_map(
            fn (array $v) => new FixedComponentValueInput($v['salary_structure_component_id'], $v['amount']),
            $validated['fixed_values'],
        );

        $assignment = $service->assign(
            $school, $record, $structure, Carbon::parse($validated['effective_from']), $fixedValues, $request->user(),
        );

        return response()->json(['data' => $this->presentSummary(CompensationAssignmentSummary::fromModel($assignment))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(CompensationAssignmentSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'employmentRecordId' => $summary->employmentRecordId,
            'salaryStructureId' => $summary->salaryStructureId,
            'effectiveFrom' => $summary->effectiveFrom->toDateString(),
            'effectiveTo' => $summary->effectiveTo?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentValue(CompensationAssignmentValueDetail $value): array
    {
        return [
            'salaryStructureComponentId' => $value->salaryStructureComponentId,
            'amount' => $value->amount,
        ];
    }
}
