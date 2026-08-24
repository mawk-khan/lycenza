<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\ActiveEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossAcademicYearTransferException;
use App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\IntraYearGradeChangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 1B.6: session-authenticated Inertia pages for Enrollment
 * administration -- mirrors StudentController/GuardianController's
 * exact convention (NOT the Bearer-token JSON API under /api/v1, which
 * remains the separate Phase 1B.5 surface for external/mobile
 * consumers). Every mutation delegates to
 * App\Domain\Students\Application\StudentEnrollmentService and every
 * non-trivial read to StudentEnrollmentReadService -- the SAME services
 * the JSON API controller uses, never duplicated or reimplemented here.
 *
 * Every domain exception a user can plausibly trigger is translated to
 * Laravel's own ValidationException (the GuardianController::storeContact()
 * pattern) so Inertia's `form.errors` renders it inline -- the
 * exception's own already-safe, already-crafted message is reused
 * as-is, never a raw class name/SQLSTATE.
 */
class StudentEnrollmentController extends Controller
{
    use AuthorizesCapability;

    // --- Directory --------------------------------------------------------

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities, StudentEnrollmentReadService $reads): Response
    {
        $school = $context->requireSchool();
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
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $filters = collect($validated)->except('page')->all();
        // StudentEnrollmentReadService::directory() is typed to the
        // LengthAwarePaginator CONTRACT (correctly, as a general read
        // API) but always returns the concrete Eloquent paginator at
        // runtime -- the same object StudentController::index() (this
        // checkpoint's own established web pattern) calls ->through()
        // on directly. The annotation only narrows the static type for
        // PHPStan; it changes no runtime behavior.
        /** @var LengthAwarePaginator<int, StudentEnrollment> $paginator */
        $paginator = $reads->directory($filters, 20)->withQueryString();

        return Inertia::render('App/Enrollments/Index', [
            'enrollments' => $paginator->through(fn (StudentEnrollment $e) => $this->presentSummary($e)),
            'filters' => [
                'academic_year_id' => $validated['academic_year_id'] ?? '',
                'campus_id' => $validated['campus_id'] ?? '',
                'grade_level_id' => $validated['grade_level_id'] ?? '',
                'section_id' => $validated['section_id'] ?? '',
                'status' => $validated['status'] ?? '',
                'student_number' => $validated['student_number'] ?? '',
                'student_name' => $validated['student_name'] ?? '',
                'roll_number' => $validated['roll_number'] ?? '',
            ],
            'academicYears' => $this->academicYearOptions(),
            'campuses' => $this->campusOptions(),
            'gradeLevels' => $this->gradeLevelOptions(),
            'sections' => $this->sectionOptions($school, onlyEligible: false),
        ]);
    }

    // --- Create (nested under a Student) -----------------------------------

    public function create(TenantContext $context, string $student): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);

        $studentModel = Student::query()->findOrFail($student);

        return Inertia::render('App/Enrollments/Create', [
            'student' => $this->presentStudentRef($studentModel),
            'sections' => $this->sectionOptions($school, onlyEligible: true),
        ]);
    }

    public function store(Request $request, TenantContext $context, StudentEnrollmentService $service, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);

        $studentModel = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'section_id' => ['required', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'roll_number' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
        ]);

        $section = Section::query()->findOrFail($validated['section_id']);

        try {
            $service->enroll($studentModel, $section, $validated['roll_number'], $validated['starts_on'], $context->actor());
        } catch (ActiveEnrollmentConflictException|CrossSchoolEnrollmentException $e) {
            throw ValidationException::withMessages(['section_id' => [$e->getMessage()]]);
        } catch (DuplicateEnrollmentRollNumberException|InvalidEnrollmentRollNumberException $e) {
            throw ValidationException::withMessages(['roll_number' => [$e->getMessage()]]);
        } catch (InvalidEnrollmentDateRangeException $e) {
            throw ValidationException::withMessages(['starts_on' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$studentModel->id}");
    }

    // --- Lifecycle ----------------------------------------------------------

    public function complete(Request $request, TenantContext $context, StudentEnrollmentService $service, string $enrollment): RedirectResponse
    {
        return $this->transition($request, $context, $enrollment, fn (StudentEnrollment $e, string $endsOn) => $service->complete($e, $endsOn, $context->actor()));
    }

    public function withdraw(Request $request, TenantContext $context, StudentEnrollmentService $service, string $enrollment): RedirectResponse
    {
        return $this->transition($request, $context, $enrollment, fn (StudentEnrollment $e, string $endsOn) => $service->withdraw($e, $endsOn, $context->actor()));
    }

    public function cancel(Request $request, TenantContext $context, StudentEnrollmentService $service, string $enrollment): RedirectResponse
    {
        return $this->transition($request, $context, $enrollment, fn (StudentEnrollment $e, string $endsOn) => $service->cancel($e, $endsOn, $context->actor()));
    }

    /**
     * Shared shape for the three terminal one-field (`ends_on`)
     * lifecycle actions -- each still calls its OWN named
     * StudentEnrollmentService method (never a generic status setter,
     * CLAUDE.md section 40 of the 1B.5 brief applies identically here).
     *
     * @param  callable(StudentEnrollment, string): StudentEnrollment  $apply
     */
    private function transition(Request $request, TenantContext $context, string $enrollment, callable $apply): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);

        $model = StudentEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $studentId = $model->student_id;

        try {
            $apply($model, $validated['ends_on']);
        } catch (InvalidEnrollmentTransitionException|InvalidEnrollmentDateRangeException $e) {
            throw ValidationException::withMessages(['ends_on' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$studentId}");
    }

    // --- Transfer -------------------------------------------------------

    public function transferCreate(TenantContext $context, string $enrollment): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);

        $model = StudentEnrollment::query()->with(['student', 'academicYear', 'campus', 'gradeLevel', 'section'])->findOrFail($enrollment);

        return Inertia::render('App/Enrollments/Transfer', [
            'enrollment' => $this->presentSummary($model),
            'sections' => $this->sectionOptions($school, onlyEligible: true),
        ]);
    }

    public function transfer(Request $request, TenantContext $context, StudentEnrollmentService $service, string $enrollment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);

        $model = StudentEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate([
            'target_section_id' => ['required', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'roll_number' => ['required', 'string', 'max:255'],
            'effective_date' => ['required', 'date'],
        ]);

        $targetSection = Section::query()->findOrFail($validated['target_section_id']);
        $studentId = $model->student_id;

        try {
            $service->transferPlacement($model, $targetSection, $validated['roll_number'], $validated['effective_date'], $context->actor());
        } catch (CrossAcademicYearTransferException|IntraYearGradeChangeException|CrossSchoolEnrollmentException $e) {
            throw ValidationException::withMessages(['target_section_id' => [$e->getMessage()]]);
        } catch (DuplicateEnrollmentRollNumberException|InvalidEnrollmentRollNumberException $e) {
            throw ValidationException::withMessages(['roll_number' => [$e->getMessage()]]);
        } catch (InvalidEnrollmentTransitionException|InvalidEnrollmentDateRangeException $e) {
            throw ValidationException::withMessages(['effective_date' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$studentId}");
    }

    // --- Reference data for filters/pickers --------------------------------

    /**
     * A Section option carries enough context (Grade, Campus, Academic
     * Year) to be unambiguous even when the same Section name repeats
     * across years/campuses/grades (this checkpoint's brief, section
     * 17) -- e.g. "Grade 5 · Section A · Main Campus · 2026-27".
     * `onlyEligible` restricts create/transfer pickers to Sections
     * whose Academic Year is not yet closed and whose own status is
     * active -- a UI convenience only (the server remains authoritative
     * regardless); the directory FILTER passes `false` so historical
     * Enrollments in closed years can still be filtered by Section.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sectionOptions(School $school, bool $onlyEligible): array
    {
        $query = Section::query()->with(['academicYear', 'campus', 'gradeLevel']);

        if ($onlyEligible) {
            $query->where('status', 'active')->whereHas('academicYear', fn ($q) => $q->whereIn('status', ['draft', 'active']));
        }

        return $query->get()
            ->sortByDesc(fn (Section $s) => $s->academicYear->starts_on)
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->gradeLevel->name} · Section {$s->name} · {$s->campus->name} · {$s->academicYear->name}",
                'academicYearId' => $s->academic_year_id,
                'campusId' => $s->campus_id,
                'gradeLevelId' => $s->grade_level_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function academicYearOptions(): array
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get()
            ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function campusOptions(): array
    {
        return Campus::query()->orderBy('name')->get()
            ->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function gradeLevelOptions(): array
    {
        return GradeLevel::query()->orderBy('sequence')->get()
            ->map(fn (GradeLevel $g) => ['id' => $g->id, 'name' => $g->name])
            ->all();
    }

    // --- Presentation -------------------------------------------------------

    /**
     * Deliberately excludes dateOfBirth/Guardian PII -- the same
     * privacy boundary as the Phase 1B.5 JSON API's presenter (CLAUDE.md
     * section 12/49 of that checkpoint's brief applies identically to
     * this Inertia surface).
     *
     * @return array<string, mixed>
     */
    private function presentSummary(StudentEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'student' => $this->presentStudentRef($enrollment->student),
            'academicYear' => ['id' => $enrollment->academicYear->id, 'name' => $enrollment->academicYear->name],
            'campus' => ['id' => $enrollment->campus->id, 'name' => $enrollment->campus->name],
            'gradeLevel' => ['id' => $enrollment->gradeLevel->id, 'name' => $enrollment->gradeLevel->name],
            'section' => ['id' => $enrollment->section->id, 'name' => $enrollment->section->name],
            'rollNumber' => $enrollment->roll_number,
            'status' => $enrollment->status,
            'startsOn' => $enrollment->starts_on->toDateString(),
            'endsOn' => $enrollment->ends_on?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStudentRef(Student $student): array
    {
        return [
            'id' => $student->id,
            'studentNumber' => $student->student_number,
            'firstName' => $student->first_name,
            'middleName' => $student->middle_name,
            'lastName' => $student->last_name,
        ];
    }
}
