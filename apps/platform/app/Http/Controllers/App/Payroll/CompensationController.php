<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationAssignmentSummary;
use App\Domain\Payroll\Application\CompensationAssignmentValueDetail;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationReadService;
use App\Domain\Payroll\Application\PayrollStructureReadService;
use App\Domain\Payroll\Application\SalaryStructureComponentSummary;
use App\Domain\Payroll\Application\SalaryStructureSummary;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Auth\Mfa\SensitiveReadAssurance;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9.9 -- session-authenticated Inertia pages for Employee
 * Compensation, deliberately split by sensitivity tier exactly like
 * `PayrollCompensationReadService`'s own two authorized read paths:
 * `show()` always calls `listAssignments()` (`payroll.compensation.view`
 * -- identity and effective dates only, structurally never carrying an
 * amount), and `values()` is a SEPARATE, on-demand JSON endpoint
 * calling `getAssignmentValues()` (`payroll.compensation.sensitive.view`)
 * -- the Highly Sensitive amounts are never included in `show()`'s
 * Inertia page props at all, fetched only when a viewer with the
 * sensitive capability explicitly requests one assignment's values
 * (docs/security/DATA-CLASSIFICATION.md; this checkpoint's own
 * mandatory requirement: suppression happens before serialization,
 * never via a client-side conditional).
 *
 * `{employmentRecord}` is HR's, resolved here read-only via Eloquent --
 * the same established cross-module read pattern
 * `App\Domain\Payroll\Http\Controllers\CompensationAssignmentController`'s
 * own docblock documents.
 */
class CompensationController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('payroll.compensation.view', $school);

        return Inertia::render('App/Payroll/Compensation/Index');
    }

    public function search(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('payroll.compensation.view', $school);

        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        $records = $context->withSchool($school, fn () => EmploymentRecord::query()
            ->where('school_id', $school->id)
            ->whereHas('employee', fn ($q) => $q
                ->where('school_id', $school->id)
                ->where(fn ($q2) => $q2
                    ->where('full_name', 'ilike', "%{$validated['q']}%")
                    ->orWhere('employee_number', 'ilike', "%{$validated['q']}%")))
            ->with('employee')
            ->limit(20)
            ->get());

        return response()->json([
            'data' => $records->map(fn (EmploymentRecord $er) => [
                'employmentRecordId' => $er->id,
                'employeeId' => $er->employee_id,
                'employeeFullName' => $er->employee?->full_name,
                'employeeNumber' => $er->employee?->employee_number,
                'status' => $er->status,
            ])->all(),
        ]);
    }

    public function show(Request $request, TenantContext $context, PayrollCompensationReadService $compensation, PayrollStructureReadService $structures, CapabilityResolver $capabilities, SensitiveReadAssurance $assurance, FreshMfaRequirement $mfa, string $employmentRecord): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $holdsSensitive = $capabilities->canInSchool($actor, 'payroll.compensation.sensitive.view', $school);
        $assured = $assurance->holds($request);
        $record = EmploymentRecord::query()->findOrFail($employmentRecord);

        $assignments = $compensation->listAssignments($school, $record, $actor);

        // Only fetched for an actor who also holds payroll.structures.view
        // -- a plain payroll.compensation.view holder must not be denied
        // this page merely because the "assign new compensation" form's
        // structure picker (itself only shown to a
        // payroll.compensation.sensitive.manage holder) needs a
        // capability compensation viewing never required.
        $canViewStructures = $capabilities->canInSchool($actor, 'payroll.structures.view', $school);
        $activeStructures = ! $canViewStructures ? [] : array_values(array_filter(
            $structures->listStructures($school, $actor),
            fn (SalaryStructureSummary $s) => $s->status === 'active',
        ));
        $structureOptions = array_map(function (SalaryStructureSummary $s) use ($school, $structures, $actor) {
            $detail = $structures->getStructureDetail($school, SalaryStructure::query()->findOrFail($s->id), $actor);

            return [
                'id' => $s->id,
                'code' => $s->code,
                'version' => $s->version,
                'name' => $s->name,
                'fixedComponents' => array_values(array_map(
                    fn (SalaryStructureComponentSummary $c) => [
                        'salaryStructureComponentId' => $c->id,
                        'salaryComponentId' => $c->salaryComponentId,
                    ],
                    array_filter($detail->components, fn (SalaryStructureComponentSummary $c) => $c->calculationType === 'fixed_amount'),
                )),
            ];
        }, $activeStructures);

        return Inertia::render('App/Payroll/Compensation/Show', [
            'employmentRecord' => [
                'id' => $record->id,
                'employeeId' => $record->employee_id,
                'employeeFullName' => $record->employee?->full_name,
                'employeeNumber' => $record->employee?->employee_number,
            ],
            'assignments' => array_map(fn (CompensationAssignmentSummary $a) => [
                'id' => $a->id,
                'salaryStructureId' => $a->salaryStructureId,
                'effectiveFrom' => $a->effectiveFrom->toDateString(),
                'effectiveTo' => $a->effectiveTo?->toDateString(),
            ], $assignments),
            'structureOptions' => $structureOptions,
            // SR.4 (ADR 0071 §26.7): amounts need current MFA assurance to be
            // read and a fresh code to be written.
            'canViewSensitive' => $holdsSensitive && $assured,
            'sensitiveNeedsMfa' => $holdsSensitive && ! $assured,
            'canManageSensitive' => $capabilities->canInSchool($actor, 'payroll.compensation.sensitive.manage', $school),
            'hasMfaFactor' => $mfa->hasActiveFactor($actor),
        ]);
    }

    public function store(Request $request, TenantContext $context, PayrollCompensationAdministrationService $service, FreshMfaRequirement $mfa, string $employmentRecord): RedirectResponse
    {
        $school = $context->requireSchool();
        $record = EmploymentRecord::query()->findOrFail($employmentRecord);
        $this->authorizeCapability('payroll.compensation.sensitive.manage', $school);

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

        // SR.4 (ADR 0071 §26.7): a fresh code, outside the assignment transaction.
        $mfa->requireForAction($request, $context->actor());
        $service->assign($school, $record, $structure, Carbon::parse($validated['effective_from']), $fixedValues, $context->actor());

        return redirect("/app/payroll/compensation/{$record->id}");
    }

    public function values(Request $request, TenantContext $context, PayrollCompensationReadService $service, SensitiveReadAssurance $assurance, string $compensationAssignment): JsonResponse
    {
        $school = $context->requireSchool();
        // SR.4 (ADR 0071 §26.7): capability, then current MFA assurance.
        $this->authorizeCapability('payroll.compensation.sensitive.view', $school);
        $assurance->requireForJson($request);
        $values = $service->getAssignmentValues($school, $compensationAssignment, $context->actor());

        return response()->json([
            'data' => array_map(fn (CompensationAssignmentValueDetail $v) => [
                'salaryStructureComponentId' => $v->salaryStructureComponentId,
                'amount' => $v->amount,
            ], $values),
        ]);
    }
}
