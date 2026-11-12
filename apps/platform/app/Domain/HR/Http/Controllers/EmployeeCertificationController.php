<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * an Employee's Restricted-tier certifications/licences. Identical
 * shape to EmployeeQualificationController -- see that class's
 * docblock for the verify()/reject() split reasoning.
 */
class EmployeeCertificationController extends Controller
{
    public function store(Request $request, School $school, string $employee, EmployeeCertificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'issuer' => ['required', 'string', 'max:255'],
            'credential_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $certification = $service->add($model, $validated, $request->user());

        return response()->json(['data' => $this->present($certification)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $certification, EmployeeCertificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'issuer' => ['sometimes', 'string', 'max:255'],
            'credential_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $updated = $service->update($employeeModel, $certificationModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, School $school, string $employee, string $certification, EmployeeCertificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        // HR.2A (S-16): an archive, with a closed reason; the record stays as evidence.
        $reason = ProfileRecordArchiveReason::from((string) $request->validate(['reason_code' => ['required', 'string', Rule::in(ProfileRecordArchiveReason::values())]], ['reason_code.*' => 'Choose why this record is being archived.'])['reason_code']);
        $service->remove($employeeModel, $certificationModel, $request->user(), $reason);

        return response()->json(status: 204);
    }

    public function verify(Request $request, School $school, string $employee, string $certification, EmployeeCertificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        // HR.R1-C29 (C14-N5): the revision the decision-maker saw; a stale one is a 409, a missing one a 422.
        $revision = (int) $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
        $updated = $service->verify($employeeModel, $certificationModel, $request->user(), $revision);

        return response()->json(['data' => $this->present($updated)]);
    }

    public function reject(Request $request, School $school, string $employee, string $certification, EmployeeCertificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        // HR.R1-C29 (C14-N5): the revision the decision-maker saw; a stale one is a 409, a missing one a 422.
        $revision = (int) $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
        $updated = $service->reject($employeeModel, $certificationModel, $request->user(), $revision);

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeCertification $certification): array
    {
        return [
            'id' => $certification->id,
            'employeeId' => $certification->employee_id,
            'name' => $certification->name,
            'issuer' => $certification->issuer,
            'credentialNumber' => $certification->credential_number,
            'issuedOn' => $certification->issued_on?->toDateString(),
            'expiresOn' => $certification->expires_on?->toDateString(),
            'verificationStatus' => $certification->verification_status,
            'verifiedAt' => $certification->verified_at?->toIso8601String(),
            // HR.R1-C29: the current revision, and the one the status names (null with a status: before version recording).
            'revision' => (int) $certification->revision,
            'verificationRevision' => $certification->verification_revision,
        ];
    }
}
