<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAddress;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * an Employee's Restricted-tier addresses. Thin adapter over
 * `EmployeeAddressService`; `{address}` ownership is re-verified by
 * the service itself (`EmployeeOwnershipMismatchException`), never
 * assumed from the nested route shape alone.
 */
class EmployeeAddressController extends Controller
{
    public function store(Request $request, School $school, string $employee, EmployeeAddressService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'address_type' => ['required', Rule::in(['current', 'permanent', 'mailing', 'other'])],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'state_region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'string', 'size:2'],
        ]);

        $address = $service->add($model, $validated, $request->user());

        return response()->json(['data' => $this->present($address)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $address, EmployeeAddressService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($address), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $addressModel = EmployeeAddress::query()->findOrFail($address);

        $validated = $request->validate([
            'address_type' => ['sometimes', Rule::in(['current', 'permanent', 'mailing', 'other'])],
            'address_line1' => ['sometimes', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'state_region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'string', 'size:2'],
        ]);

        $updated = $service->update($employeeModel, $addressModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, School $school, string $employee, string $address, EmployeeAddressService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($address), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $addressModel = EmployeeAddress::query()->findOrFail($address);

        $service->remove($employeeModel, $addressModel, $request->user());

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeAddress $address): array
    {
        return [
            'id' => $address->id,
            'employeeId' => $address->employee_id,
            'addressType' => $address->address_type,
            'addressLine1' => $address->address_line1,
            'addressLine2' => $address->address_line2,
            'city' => $address->city,
            'stateRegion' => $address->state_region,
            'postalCode' => $address->postal_code,
            'countryCode' => $address->country_code,
        ];
    }
}
