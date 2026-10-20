<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ORG.7F-1 (ADR 0076 §45): the Campus-aware Enrollment reads of the JSON
 * API -- the directory, one Enrollment and a Student's current placement.
 *
 * - Whole School: unchanged (every Enrollment, any year and status).
 * - Campus set: only CURRENT placements (the active Enrollment in the
 *   School's active year) on in-scope Campuses of Students whose current
 *   operational Campus is in scope, applied in SQL by StudentAccess. History,
 *   draft/future years and other Campuses are NOT FOUND (404), exactly like
 *   an unknown id; a Student's full history is a declared whole-School-only
 *   operation (StudentEnrollmentController::historyForStudent).
 */
class EnrollmentDirectoryController extends Controller
{
    use AuthorizesCampusScope;
    use PresentsStudentEnrollment;

    public function index(Request $request, School $school, StudentAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('enrollments.view', $school);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
            'campus_id' => ['sometimes', 'uuid'],
            'grade_level_id' => ['sometimes', 'uuid'],
            'section_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(['active', 'completed', 'withdrawn', 'transferred', 'cancelled'])],
            'student_number' => ['sometimes', 'string', 'max:255'],
            'student_name' => ['sometimes', 'string', 'max:255'],
            'roll_number' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = collect($validated)->except('per_page')->all();
        $paginator = $access->enrollmentDirectory($school, $scope, $filters)->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (StudentEnrollment $e) => $this->presentSummary($e))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(School $school, string $enrollment, StudentAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('enrollments.view', $school);

        return response()->json(['data' => $this->presentDetail($access->enrollment($school, $scope, $enrollment))]);
    }

    /**
     * The Student's current placement (null when none). A Campus actor
     * reaches only a Student whose current operational Campus is in scope,
     * and only the current year: naming another year never reveals a
     * historical placement (null).
     */
    public function currentForStudent(Request $request, School $school, string $student, StudentAccess $access, CurrentAcademicYearResolver $years): JsonResponse
    {
        $scope = $this->authorizeCampusScope('enrollments.view', $school);
        $studentModel = $access->student($school, $scope, $student);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
        ]);

        if (isset($validated['academic_year_id']) && $validated['academic_year_id'] !== $years->tryResolve($school)?->id) {
            // Another year is history: whole-School only (unchanged for the
            // whole School through StudentEnrollmentReadService::currentFor).
            $current = $access->placementInYear($school, $scope, $studentModel, $validated['academic_year_id']);
        } else {
            $current = $access->currentPlacements($school, $scope, [$studentModel->id])->get($studentModel->id);
        }

        return response()->json(['data' => $current === null ? null : $this->presentDetail($current->setRelation('student', $studentModel))]);
    }
}
