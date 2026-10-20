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
use Illuminate\Support\Str;
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
    use PresentsStudentEnrollment;

    // --- Per-Student reads ------------------------------------------------

    /**
     * ORG.7F-1 (ADR 0076 §45): a Student's FULL placement history is a
     * declared whole-School-only operation -- the route carries the ordinary
     * `capability:enrollments.view` floor, which a Campus-scoped grant never
     * satisfies. The directory, detail and current-placement reads are
     * Campus-aware (EnrollmentDirectoryController).
     */
    public function historyForStudent(School $school, string $student, StudentEnrollmentReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($student), 404);
        $studentModel = Student::query()->findOrFail($student);

        $history = $reads->historyFor($studentModel)->each(
            fn (StudentEnrollment $e) => $e->setRelation('student', $studentModel)
        );

        return response()->json(['data' => $history->map(fn (StudentEnrollment $e) => $this->presentDetail($e))->values()->all()]);
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
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

        abort_if(! Str::isUuid($student), 404);
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
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

        abort_if(! Str::isUuid($enrollment), 404);
        $model = StudentEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $completed = $service->complete($model, $validated['ends_on'], $request->user());

        return response()->json(['data' => $this->presentDetail($completed->load(['student', 'academicYear', 'campus', 'gradeLevel', 'section']))]);
    }

    public function withdraw(Request $request, School $school, string $enrollment, StudentEnrollmentService $service): JsonResponse
    {
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

        abort_if(! Str::isUuid($enrollment), 404);
        $model = StudentEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $withdrawn = $service->withdraw($model, $validated['ends_on'], $request->user());

        return response()->json(['data' => $this->presentDetail($withdrawn->load(['student', 'academicYear', 'campus', 'gradeLevel', 'section']))]);
    }

    public function cancel(Request $request, School $school, string $enrollment, StudentEnrollmentService $service): JsonResponse
    {
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

        abort_if(! Str::isUuid($enrollment), 404);
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
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

        abort_if(! Str::isUuid($enrollment), 404);
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
}
