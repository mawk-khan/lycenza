<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Infrastructure\Employee;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * the Employee's 1:1 Restricted-tier personal details. Thin adapter
 * over `EmployeePersonalDetailService::setDetails()` (upsert -- creates
 * the row if absent, updates it otherwise).
 */
class EmployeePersonalDetailController extends Controller
{
    public function update(Request $request, School $school, string $employee, EmployeePersonalDetailService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:255'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preferred_language' => ['sometimes', 'nullable', 'string', 'max:255'],
            'personal_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'personal_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'alternate_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $detail = $service->setDetails($model, $validated, $request->user());

        return response()->json(['data' => [
            'id' => $detail->id,
            'employeeId' => $detail->employee_id,
            'dateOfBirth' => $detail->date_of_birth?->toDateString(),
            'nationality' => $detail->nationality,
            'maritalStatus' => $detail->marital_status,
            'preferredLanguage' => $detail->preferred_language,
            'personalEmail' => $detail->personal_email,
            'personalPhone' => $detail->personal_phone,
            'alternatePhone' => $detail->alternate_phone,
        ]]);
    }
}
