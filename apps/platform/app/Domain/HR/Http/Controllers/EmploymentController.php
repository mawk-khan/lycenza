<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * EmploymentRecord. Nested under `/employees/{employee}/employment-
 * records` -- `{employment}` ownership against the route's `{employee}`
 * is verified here (EmploymentService itself has no employee-mismatch
 * guard, since a directly-injected EmploymentRecord already carries its
 * own authoritative `employee_id`; this controller re-verifies it
 * anyway so a caller cannot address one Employee's EmploymentRecord
 * through a DIFFERENT Employee's nested route, mirroring the IDOR
 * protection every other Employee-nested service already provides).
 */
class EmploymentController extends Controller
{
    public function store(Request $request, School $school, string $employee, EmploymentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $employeeModel = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            // HR.1A (S-12): the closed catalogue; staff_type optional on the API (absent = unclassified).
            'employment_type' => ['required', 'string', Rule::in(EmploymentType::values())],
            'staff_type' => ['sometimes', 'nullable', 'string', Rule::in(StaffType::values())],
            'employee_category_id' => ['sometimes', 'nullable', 'uuid'],
            'starts_on' => ['required', 'date'],
            // HR.R1-C2 (HRC-1): an employment begins open; it ends only through /end or an approved separation.
            'ends_on' => ['prohibited'],
            'probation_ends_on' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(EmploymentRecord::STATUSES)],
        ]);

        $employment = $service->create($employeeModel, $validated, $request->user());

        return response()->json(['data' => $this->present($employment)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $employment, EmploymentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = $this->resolveOwned($employeeModel, $employment);

        // HR.1A (X-3): terms are never overwritten in place; they change as dated,
        // reasoned terms (the web terms change). Only probation_ends_on is editable here.
        $validated = $request->validate([
            'employment_type' => ['prohibited'],
            'staff_type' => ['prohibited'],
            'employee_category_id' => ['prohibited'],
            'probation_ends_on' => ['sometimes', 'nullable', 'date'],
        ], [
            'employment_type.prohibited' => 'Employment terms change as dated terms with a reason, not by an update.',
            'staff_type.prohibited' => 'Employment terms change as dated terms with a reason, not by an update.',
            'employee_category_id.prohibited' => 'Employment terms change as dated terms with a reason, not by an update.',
        ]);

        $updated = $service->update($employmentModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * HR.1A (S-8): ending over the API is the separation command -- a future
     * date is refused exactly as separate() refuses it.
     */
    public function end(Request $request, School $school, string $employee, string $employment, EmployeeLifecycleService $service, FreshMfaRequirement $mfa): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = $this->resolveOwned($employeeModel, $employment);

        $validated = $request->validate([
            'ends_on' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['separated', 'terminated', 'retired', 'deceased'])],
        ]);
        // HR.12: only a death is recorded directly; refused before an MFA code is spent.
        if (($validated['status'] ?? 'separated') !== 'deceased') {
            throw SeparationException::conflict('SEPARATION_USE_CASE', 'Record a resignation, retirement, contract end or mutual agreement as a separation case; another person approves it. Only a death is recorded directly.');
        }
        $proof = $mfa->requireForAction($request, $request->user());

        $ended = $service->separate($employmentModel, $validated['ends_on'], $request->user(), $proof, $validated['status']);

        return response()->json(['data' => $this->present($ended)]);
    }

    private function resolveOwned(Employee $employee, string $employmentId): EmploymentRecord
    {
        $model = EmploymentRecord::query()->findOrFail($employmentId);

        if ($model->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($model->id, $employee->id, $model->employee_id);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmploymentRecord $employment): array
    {
        return [
            'id' => $employment->id,
            'employeeId' => $employment->employee_id,
            'employeeCategoryId' => $employment->employee_category_id,
            'employmentType' => $employment->employment_type,
            'staffType' => $employment->staff_type,
            'startsOn' => $employment->starts_on->toDateString(),
            'endsOn' => $employment->ends_on?->toDateString(),
            'probationEndsOn' => $employment->probation_ends_on?->toDateString(),
            'status' => $employment->status,
        ];
    }
}
