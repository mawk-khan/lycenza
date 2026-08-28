<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeEmergencyContactService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeEmergencyContact;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * an Employee's Restricted-tier emergency contacts. `is_primary` is
 * never accepted through store()/update() -- `setPrimary()` is the
 * sole promotion route, mirroring the service's own split.
 */
class EmployeeEmergencyContactController extends Controller
{
    public function store(Request $request, School $school, string $employee, EmployeeEmergencyContactService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'alternate_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $contact = $service->add($model, $validated, $request->user());

        return response()->json(['data' => $this->present($contact)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $contact, EmployeeEmergencyContactService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($contact), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $contactModel = EmployeeEmergencyContact::query()->findOrFail($contact);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'relationship' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'alternate_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $updated = $service->update($employeeModel, $contactModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, School $school, string $employee, string $contact, EmployeeEmergencyContactService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($contact), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $contactModel = EmployeeEmergencyContact::query()->findOrFail($contact);

        $service->remove($employeeModel, $contactModel, $request->user());

        return response()->json(status: 204);
    }

    public function setPrimary(Request $request, School $school, string $employee, string $contact, EmployeeEmergencyContactService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($contact), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $contactModel = EmployeeEmergencyContact::query()->findOrFail($contact);

        $updated = $service->setPrimary($employeeModel, $contactModel, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeEmergencyContact $contact): array
    {
        return [
            'id' => $contact->id,
            'employeeId' => $contact->employee_id,
            'name' => $contact->name,
            'relationship' => $contact->relationship,
            'phone' => $contact->phone,
            'alternatePhone' => $contact->alternate_phone,
            'email' => $contact->email,
            'isPrimary' => $contact->is_primary,
        ];
    }
}
