<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 8A closure correction (item 3) -- the sole HTTP mutation
 * transport for the Employee core aggregate. Thin adapter over
 * `EmployeeService`, which already performs its own `hr.employees.*`
 * capability check against the real authenticated actor before doing
 * anything else -- this controller performs no capability logic of its
 * own beyond the route-level `capability:` middleware, mirroring
 * `EmployeeDirectoryController`/`EmployeeProfileController`'s
 * documented "no divergent capability matrix" decision (8A.14),
 * extended to mutations by this correction's item 9.
 *
 * `{employee}` is always a raw route-parameter string, resolved here
 * via a tenant-scoped `findOrFail()` (SchoolScope + TenantContext,
 * already established by the `school-membership` middleware for this
 * entire route group) -- never implicit Eloquent route-model binding.
 * A malformed (non-UUID) id is rejected as the same tenant-safe 404 a
 * genuinely nonexistent/cross-School Employee already produces (8A.15's
 * fix, applied consistently to every new HR mutation route this
 * correction adds).
 */
class EmployeeController extends Controller
{
    public function store(Request $request, School $school, EmployeeService $service): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $employee = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($employee)], 201);
    }

    public function update(Request $request, School $school, string $employee, EmployeeService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $updated = $service->update($model, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function archive(Request $request, School $school, string $employee, EmployeeService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);
        $archived = $service->archive($model, $request->user());

        return response()->json(['data' => $this->present($archived)]);
    }

    public function restore(Request $request, School $school, string $employee, EmployeeService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);
        $restored = $service->restore($model, $request->user());

        return response()->json(['data' => $this->present($restored)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employeeNumber' => $employee->employee_number,
            'fullName' => $employee->full_name,
            'workEmail' => $employee->work_email,
            'workPhone' => $employee->work_phone,
            'userId' => $employee->user_id,
            'recordStatus' => $employee->record_status,
        ];
    }
}
