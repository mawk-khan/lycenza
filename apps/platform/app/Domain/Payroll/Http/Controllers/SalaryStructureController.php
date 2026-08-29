<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureReadService;
use App\Domain\Payroll\Application\SalaryStructureComponentSummary;
use App\Domain\Payroll\Application\SalaryStructureDetail;
use App\Domain\Payroll\Application\SalaryStructureSummary;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 9.8 -- thin HTTP transport over `PayrollStructureReadService`/
 * `PayrollStructureAdministrationService`, mirroring
 * `SalaryComponentController`'s identical shape. `{salaryStructure}`
 * resolved via `SalaryStructure::query()->findOrFail()` -- see that
 * controller's docblock for why this is safe.
 */
class SalaryStructureController extends Controller
{
    public function index(School $school, PayrollStructureReadService $service): JsonResponse
    {
        $structures = $service->listStructures($school, request()->user());

        return response()->json([
            'data' => array_map(fn (SalaryStructureSummary $s) => $this->presentSummary($s), $structures),
        ]);
    }

    public function show(School $school, string $salaryStructure, PayrollStructureReadService $service): JsonResponse
    {
        $structure = SalaryStructure::query()->findOrFail($salaryStructure);
        $detail = $service->getStructureDetail($school, $structure, request()->user());

        return response()->json(['data' => $this->presentDetail($detail)]);
    }

    public function store(Request $request, School $school, PayrollStructureAdministrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $structure = $service->createDraftStructure($school, $validated['code'], $validated['name'], $request->user());

        return response()->json(['data' => $this->presentSummary(SalaryStructureSummary::fromModel($structure))], 201);
    }

    public function addComponent(Request $request, School $school, string $salaryStructure, PayrollStructureAdministrationService $service): JsonResponse
    {
        $structure = SalaryStructure::query()->findOrFail($salaryStructure);

        $validated = $request->validate([
            'salary_component_id' => ['required', 'uuid'],
            'calculation_type' => ['required', Rule::in(['fixed_amount', 'percentage_of_base'])],
            'base_component_id' => ['sometimes', 'nullable', 'uuid'],
            'rate' => ['sometimes', 'nullable', 'string', 'regex:/^0(\.\d+)?|1(\.0+)?$/'],
            'display_order' => ['required', 'integer', 'min:1'],
        ]);

        $component = $service->addStructureComponent($structure, new AddStructureComponentData(
            $validated['salary_component_id'],
            $validated['calculation_type'],
            $validated['base_component_id'] ?? null,
            $validated['rate'] ?? null,
            $validated['display_order'],
        ), $request->user());

        return response()->json(['data' => $this->presentComponent(SalaryStructureComponentSummary::fromModel($component))], 201);
    }

    public function activate(Request $request, School $school, string $salaryStructure, PayrollStructureAdministrationService $service): JsonResponse
    {
        $structure = SalaryStructure::query()->findOrFail($salaryStructure);
        $structure = $service->activateStructure($structure, $request->user());

        return response()->json(['data' => $this->presentSummary(SalaryStructureSummary::fromModel($structure))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(SalaryStructureSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'code' => $summary->code,
            'version' => $summary->version,
            'name' => $summary->name,
            'status' => $summary->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(SalaryStructureDetail $detail): array
    {
        return [
            'id' => $detail->id,
            'code' => $detail->code,
            'version' => $detail->version,
            'name' => $detail->name,
            'status' => $detail->status,
            'components' => array_map(fn (SalaryStructureComponentSummary $c) => $this->presentComponent($c), $detail->components),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentComponent(SalaryStructureComponentSummary $component): array
    {
        return [
            'id' => $component->id,
            'salaryComponentId' => $component->salaryComponentId,
            'calculationType' => $component->calculationType,
            'baseComponentId' => $component->baseComponentId,
            'rate' => $component->rate,
            'displayOrder' => $component->displayOrder,
        ];
    }
}
