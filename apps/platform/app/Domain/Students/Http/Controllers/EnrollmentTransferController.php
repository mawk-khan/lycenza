<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\CampusStudentTransferService;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\SchoolTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ORG.7F-3 (ADR 0076 §47): the JSON API transfer of one placement.
 *
 * - Whole School: unchanged -- any active placement, any Section of the same
 *   year and Grade, the given effective date.
 * - Campus set: only the Student's CURRENT placement on an in-scope Campus
 *   (else NOT FOUND, like an unknown id), to another in-scope Campus, today,
 *   through CampusStudentTransferService (dual scope, blockers, one generic
 *   refusal).
 *
 * The route's `capability:enrollments.manage,campus` floor binds the actor's
 * CampusScope into the idempotency fingerprint (ORG.7D-1), so a stored
 * response is never replayed once that scope changed.
 */
class EnrollmentTransferController extends Controller
{
    use AuthorizesCampusScope;
    use PresentsStudentEnrollment;

    public function transfer(Request $request, School $school, string $enrollment, StudentAccess $access, StudentEnrollmentService $enrollments, CampusStudentTransferService $transfers): JsonResponse
    {
        $scope = $this->authorizeCampusScope('enrollments.manage', $school);
        $source = $access->enrollment($school, $scope, $enrollment);

        $validated = $request->validate([
            // A Campus actor never learns whether an out-of-scope Section exists:
            // an unknown one gets the same generic refusal (the service).
            'target_section_id' => ['required', 'uuid', ...($scope->isWholeSchool() ? [Rule::exists('sections', 'id')->where('school_id', $school->id)] : [])],
            'roll_number' => ['required', 'string', 'max:255'],
            'effective_date' => ['required', 'date', ...($scope->isWholeSchool() ? [] : ['date_equals:'.SchoolTimezone::today($school)])],
        ], ['effective_date.date_equals' => 'A Campus transfer takes effect today.']);

        $transferred = $scope->isWholeSchool()
            ? $enrollments->transferPlacement($source, Section::query()->findOrFail($validated['target_section_id']), $validated['roll_number'], $validated['effective_date'], $request->user())
            : $transfers->transfer($school, $scope, $source->student_id, $validated['target_section_id'], $validated['roll_number'], $request->user(), $source->id);

        return response()->json(['data' => $this->presentDetail($transferred->load(['student', 'academicYear', 'campus', 'gradeLevel', 'section']))], 201);
    }
}
