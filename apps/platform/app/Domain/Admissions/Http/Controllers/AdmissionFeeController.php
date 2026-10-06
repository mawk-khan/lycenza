<?php

namespace App\Domain\Admissions\Http\Controllers;

use App\Domain\Admissions\Application\AdmissionFeeSelectionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\AdmissionFeeSelection;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OPF.3 (ADR 0067 §16) -- Admissions' fee-integration API: the School's
 * Admission fee head (no amount) and a converted application's recorded
 * Admission-fee intent. Admissions capabilities only; nothing here can
 * assess, cancel or alter money (that stays with Finance, D9), and there is
 * no applicant-facing or pre-conversion fee (D1).
 */
class AdmissionFeeController extends Controller
{
    use AuthorizesCapability;

    public function showFeeHead(School $school, AdmissionFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('admissions.view', $school);

        return response()->json(['data' => $service->feeHead($school)]);
    }

    public function updateFeeHead(Request $request, School $school, AdmissionFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('admissions.manage', $school);

        $validated = $request->validate([
            'fee_head_id' => ['present', 'nullable', 'uuid'],
        ]);

        $service->setFeeHead($school, $validated['fee_head_id'], $request->user());

        return response()->json(['data' => $service->feeHead($school)]);
    }

    public function applicationFeeSelection(School $school, string $admissionApplication): JsonResponse
    {
        $this->authorizeCapability('admissions.view', $school);

        $application = AdmissionApplication::query()->findOrFail($admissionApplication);
        $link = AdmissionFeeSelection::query()->where('admission_application_id', $application->id)->first();

        return response()->json(['data' => $link === null ? null : [
            'id' => $link->id,
            'studentId' => $link->student_id,
            'academicYearId' => $link->academic_year_id,
            'feeHeadId' => $link->fee_head_id,
            'feeOptionalSelectionId' => $link->fee_optional_selection_id,
            'selectionOutcome' => $link->selection_outcome,
            'createdAt' => $link->created_at?->toIso8601String(),
        ]]);
    }
}
