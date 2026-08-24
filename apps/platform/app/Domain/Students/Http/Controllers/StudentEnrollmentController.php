<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 1B.5: the administrative HTTP boundary for StudentEnrollment --
 * exposes the already-built Enrollment domain (Phase 1B.1-1B.4A)
 * through the repository's existing authenticated School administrative
 * API. Deliberately thin, mirroring StudentController/AcademicYearController's
 * established shape: every mutation delegates to
 * App\Domain\Students\Application\StudentEnrollmentService (the sole
 * sanctioned write path); every non-trivial read delegates to
 * App\Domain\Students\Application\StudentEnrollmentReadService (the
 * sole sanctioned read path). No Enrollment lifecycle/placement rule is
 * duplicated here. See docs/modules/STUDENT-ENROLLMENT.md
 * ("Administrative HTTP boundary").
 *
 * `academic_year_id`/`campus_id`/`grade_level_id` are NEVER accepted as
 * independent request input anywhere in this controller -- a caller
 * supplies only a Section (create) or a target Section (transfer); the
 * placement is always derived server-side from that Section, exactly
 * like StudentEnrollmentService itself enforces (rule 19/CLAUDE.md
 * section 16).
 *
 * Every cross-referenced id a caller supplies in a request body
 * (`section_id`, `target_section_id`) is resolved through
 * `Rule::exists(...)->where('school_id', $school->id)` followed by an
 * explicit `::query()->findOrFail(...)` -- the exact StudentGuardianRelationshipController
 * pattern already established for this exact "pick an existing sibling
 * entity by id from the body" shape: a missing id and a foreign-School
 * id both fail validation with the identical generic message, never
 * distinguishing "doesn't exist" from "belongs to another School".
 * Path-identified resources (`{student}`, `{enrollment}`) are resolved
 * via a plain `::query()->findOrFail($id)` -- SchoolScope/RLS already
 * make a foreign-School id 404 exactly like a random UUID, matching
 * StudentController/SectionController's own `show()`/`update()` shape.
 */
class StudentEnrollmentController extends Controller
{
    use AuthorizesCapability;

    // --- Directory / detail --------------------------------------------

    public function index(Request $request, School $school, StudentEnrollmentReadService $reads): JsonResponse
    {
        $this->authorizeCapability('enrollments.view', $school);

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

        $perPage = $validated['per_page'] ?? 25;
        $filters = collect($validated)->except('per_page')->all();

        $paginator = $reads->directory($filters, $perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (StudentEnrollment $e) => $this->presentSummary($e))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(School $school, string $enrollment, StudentEnrollmentReadService $reads): JsonResponse
    {
        $this->authorizeCapability('enrollments.view', $school);

        $model = $reads->detail($enrollment);
        abort_if($model === null, 404);

        return response()->json(['data' => $this->presentDetail($model)]);
    }

    // --- Per-Student reads ------------------------------------------------

    public function historyForStudent(School $school, string $student, StudentEnrollmentReadService $reads): JsonResponse
    {
        $this->authorizeCapability('enrollments.view', $school);

        $studentModel = Student::query()->findOrFail($student);

        $history = $reads->historyFor($studentModel)->each(
            fn (StudentEnrollment $e) => $e->setRelation('student', $studentModel)
        );

        return response()->json(['data' => $history->map(fn (StudentEnrollment $e) => $this->presentDetail($e))->values()->all()]);
    }

    public function currentForStudent(Request $request, School $school, string $student, StudentEnrollmentReadService $reads): JsonResponse
    {
        $this->authorizeCapability('enrollments.view', $school);

        $studentModel = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
        ]);

        // A foreign-School academic_year_id 404s exactly like a random
        // UUID (SchoolScope/RLS), never silently falling back to the
        // School's own currently-active year -- CLAUDE.md rule 36.
        $academicYear = isset($validated['academic_year_id'])
            ? AcademicYear::query()->findOrFail($validated['academic_year_id'])
            : null;

        $current = $reads->currentFor($studentModel, $academicYear)?->setRelation('student', $studentModel);

        return response()->json(['data' => $current === null ? null : $this->presentDetail($current)]);
    }

    // --- Create -------------------------------------------------------

    /**
     * The Section is the single authoritative placement input --
     * AcademicYear/Campus/GradeLevel are always derived from it by
     * StudentEnrollmentService::enroll(), never accepted independently
     * here (CLAUDE.md rule 16/19).
     */
    public function store(Request $request, School $school, string $student, StudentEnrollmentService $service): JsonResponse
    {
        $this->authorizeCapability('enrollments.manage', $school);

        $studentModel = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'section_id' => ['required', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'roll_number' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
        ]);

        $section = Section::query()->findOrFail($validated['section_id']);

        $enrollment = $service->enroll($studentModel, $section, $validated['roll_number'], $validated['starts_on'], $request->user());
        $enrollment->setRelation('student', $studentModel);

        return response()->json(['data' => $this->presentDetail($enrollment)], 201);
    }

    // --- Lifecycle ------------------------------------------------------

    public function complete(Request $request, School $school, string $enrollment, StudentEnrollmentService $service): JsonResponse
    {
        $this->authorizeCapability('enrollments.manage', $school);

        $model = StudentEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $completed = $service->complete($model, $validated['ends_on'], $request->user());

        return response()->json(['data' => $this->presentDetail($completed->load(['student', 'academicYear', 'campus', 'gradeLevel', 'section']))]);
    }

    public function withdraw(Request $request, School $school, string $enrollment, StudentEnrollmentService $service): JsonResponse
    {
        $this->authorizeCapability('enrollments.manage', $school);

        $model = StudentEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $withdrawn = $service->withdraw($model, $validated['ends_on'], $request->user());

        return response()->json(['data' => $this->presentDetail($withdrawn->load(['student', 'academicYear', 'campus', 'gradeLevel', 'section']))]);
    }

    public function cancel(Request $request, School $school, string $enrollment, StudentEnrollmentService $service): JsonResponse
    {
        $this->authorizeCapability('enrollments.manage', $school);

        $model = StudentEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $cancelled = $service->cancel($model, $validated['ends_on'], $request->user());

        return response()->json(['data' => $this->presentDetail($cancelled->load(['student', 'academicYear', 'campus', 'gradeLevel', 'section']))]);
    }

    /**
     * The target Section is the single authoritative placement input --
     * `target_academic_year_id`/`target_campus_id`/`target_grade_level_id`
     * are never accepted; they are always derived from the target
     * Section by StudentEnrollmentService::transferPlacement() (CLAUDE.md
     * rule 27). Returns the NEW (target) Enrollment -- the source
     * Enrollment's id is the route parameter, not the response body's.
     */
    public function transfer(Request $request, School $school, string $enrollment, StudentEnrollmentService $service): JsonResponse
    {
        $this->authorizeCapability('enrollments.manage', $school);

        $model = StudentEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate([
            'target_section_id' => ['required', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'roll_number' => ['required', 'string', 'max:255'],
            'effective_date' => ['required', 'date'],
        ]);

        $targetSection = Section::query()->findOrFail($validated['target_section_id']);

        $transferred = $service->transferPlacement($model, $targetSection, $validated['roll_number'], $validated['effective_date'], $request->user());

        return response()->json(['data' => $this->presentDetail($transferred->load(['student', 'academicYear', 'campus', 'gradeLevel', 'section']))], 201);
    }

    // --- Presentation -----------------------------------------------------

    /**
     * Deliberately excludes `dateOfBirth`/Guardian PII/encrypted
     * contact values/lookup hashes -- the Enrollment API operates on
     * academic placement only (CLAUDE.md section 12/49). Used for both
     * directory rows and single-record detail; there is no broader
     * "full" student shape exposed through this controller at all.
     *
     * @return array<string, mixed>
     */
    private function presentSummary(StudentEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'student' => [
                'id' => $enrollment->student->id,
                'studentNumber' => $enrollment->student->student_number,
                'firstName' => $enrollment->student->first_name,
                'middleName' => $enrollment->student->middle_name,
                'lastName' => $enrollment->student->last_name,
            ],
            'academicYear' => $this->presentRef($enrollment->academicYear),
            'campus' => $this->presentRef($enrollment->campus),
            'gradeLevel' => $this->presentRef($enrollment->gradeLevel),
            'section' => $this->presentRef($enrollment->section),
            'rollNumber' => $enrollment->roll_number,
            'status' => $enrollment->status,
            'startsOn' => $enrollment->starts_on->toDateString(),
            'endsOn' => $enrollment->ends_on?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(StudentEnrollment $enrollment): array
    {
        return [
            ...$this->presentSummary($enrollment),
            'createdAt' => $enrollment->created_at->toIso8601String(),
            'updatedAt' => $enrollment->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRef(AcademicYear|Campus|GradeLevel|Section $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'code' => $model->code,
        ];
    }
}
