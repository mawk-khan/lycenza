<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureReadService;
use App\Domain\Payroll\Application\SalaryComponentSummary;
use App\Domain\Payroll\Application\SalaryStructureComponentSummary;
use App\Domain\Payroll\Application\SalaryStructureSummary;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Http\Controllers\Controller;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9.9 -- session-authenticated Inertia pages for Salary
 * Structures: the component catalogue's ordered formula lines, draft
 * creation/revision, activation, and superseded revision history.
 * Delegates every read/write to the SAME `PayrollStructureReadService`/
 * `PayrollStructureAdministrationService` the JSON API controller
 * calls. `createDraftStructure()` computes the next `version` for a
 * given `code` automatically (`SalaryStructureService::createDraft()`'s
 * own docblock) -- the same "code" input on the Create page produces a
 * fresh structure for a new code and a new revision for an existing
 * one, never a separate "revise" action.
 */
class SalaryStructureController extends Controller
{
    public function index(TenantContext $context, PayrollStructureReadService $service, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $user = $context->actor();

        $structures = $service->listStructures($school, $user);

        return Inertia::render('App/Payroll/Structures/Index', [
            'structures' => array_map(fn (SalaryStructureSummary $s) => $this->presentSummary($s), $structures),
            'canManage' => $capabilities->canInSchool($user, 'payroll.structures.manage', $school),
        ]);
    }

    public function create(TenantContext $context, PayrollStructureReadService $service): Response
    {
        $school = $context->requireSchool();
        $user = $context->actor();

        $components = $service->listComponents($school, $user);

        return Inertia::render('App/Payroll/Structures/Create', [
            'components' => array_map(
                fn (SalaryComponentSummary $c) => $this->presentComponent($c),
                array_values(array_filter($components, fn (SalaryComponentSummary $c) => $c->status === 'active')),
            ),
        ]);
    }

    public function store(Request $request, TenantContext $context, PayrollStructureAdministrationService $service): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $structure = $service->createDraftStructure($school, $validated['code'], $validated['name'], $context->actor());

        return redirect("/app/payroll/structures/{$structure->id}");
    }

    public function show(TenantContext $context, PayrollStructureReadService $service, CapabilityResolver $capabilities, string $salaryStructure): Response
    {
        $school = $context->requireSchool();
        $user = $context->actor();

        $structure = SalaryStructure::query()->findOrFail($salaryStructure);
        $detail = $service->getStructureDetail($school, $structure, $user);

        $allStructures = $service->listStructures($school, $user);
        $revisions = array_values(array_filter($allStructures, fn (SalaryStructureSummary $s) => $s->code === $detail->code));

        $components = $service->listComponents($school, $user);
        $componentsById = [];
        foreach ($components as $c) {
            $componentsById[$c->id] = $c;
        }

        return Inertia::render('App/Payroll/Structures/Show', [
            'structure' => [
                'id' => $detail->id,
                'code' => $detail->code,
                'version' => $detail->version,
                'name' => $detail->name,
                'status' => $detail->status,
                'components' => array_map(function (SalaryStructureComponentSummary $sc) use ($componentsById) {
                    $component = $componentsById[$sc->salaryComponentId] ?? null;

                    return [
                        'id' => $sc->id,
                        'salaryComponentId' => $sc->salaryComponentId,
                        'salaryComponentCode' => $component?->code,
                        'salaryComponentName' => $component?->name,
                        'calculationType' => $sc->calculationType,
                        'baseComponentId' => $sc->baseComponentId,
                        'rate' => $sc->rate,
                        'displayOrder' => $sc->displayOrder,
                    ];
                }, $detail->components),
            ],
            'revisions' => array_map(fn (SalaryStructureSummary $s) => $this->presentSummary($s), $revisions),
            'availableComponents' => array_map(
                fn (SalaryComponentSummary $c) => $this->presentComponent($c),
                array_values(array_filter($components, fn (SalaryComponentSummary $c) => $c->status === 'active')),
            ),
            'canManage' => $capabilities->canInSchool($user, 'payroll.structures.manage', $school)
                && $detail->status === 'draft',
        ]);
    }

    public function storeComponent(Request $request, TenantContext $context, PayrollStructureAdministrationService $service, string $salaryStructure): RedirectResponse
    {
        $context->requireSchool();
        $structure = SalaryStructure::query()->findOrFail($salaryStructure);

        $validated = $request->validate([
            'salary_component_id' => ['required', 'uuid'],
            'calculation_type' => ['required', Rule::in(['fixed_amount', 'percentage_of_base'])],
            'base_component_id' => ['sometimes', 'nullable', 'uuid'],
            'rate' => ['sometimes', 'nullable', 'string', 'regex:/^0(\.\d{1,4})?$|^1(\.0{1,4})?$/'],
            'display_order' => ['required', 'integer', 'min:1'],
        ]);

        $service->addStructureComponent($structure, new AddStructureComponentData(
            $validated['salary_component_id'],
            $validated['calculation_type'],
            $validated['base_component_id'] ?? null,
            $validated['rate'] ?? null,
            $validated['display_order'],
        ), $context->actor());

        return redirect("/app/payroll/structures/{$structure->id}");
    }

    public function activate(TenantContext $context, PayrollStructureAdministrationService $service, string $salaryStructure): RedirectResponse
    {
        $context->requireSchool();
        $structure = SalaryStructure::query()->findOrFail($salaryStructure);
        $service->activateStructure($structure, $context->actor());

        return redirect("/app/payroll/structures/{$structure->id}");
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
    private function presentComponent(SalaryComponentSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'code' => $summary->code,
            'name' => $summary->name,
            'type' => $summary->type,
        ];
    }
}
