<?php

namespace App\Http\Controllers\App;

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
use App\Domain\Students\Application\StudentLifecycleReason;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    // --- Create (nested under a Student) -----------------------------------

    public function create(TenantContext $context, string $student): Response
    {
        $school = $context->requireSchool();
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

        $studentModel = Student::query()->findOrFail($student);

        return Inertia::render('App/Enrollments/Create', [
            'student' => $this->presentStudentRef($studentModel),
            'sections' => $this->sectionOptions($school, onlyEligible: true),
        ]);
    }

    public function store(Request $request, TenantContext $context, StudentEnrollmentService $service, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

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

    /** ORG.8E (ADR 0076 §63): a closed reason, confirmation and a fresh MFA code; the service re-checks current authority. */
    public function withdraw(Request $request, TenantContext $context, StudentEnrollmentService $service, FreshMfaRequirement $mfa, string $enrollment): RedirectResponse
    {
        // An unknown placement is the 404 before a code is spent.
        StudentEnrollment::query()->findOrFail($enrollment);
        $assured = $this->assured($request, $context, $mfa, StudentLifecycleReason::WITHDRAW_ENROLLMENT);

        return $this->transition($request, $context, $enrollment, fn (StudentEnrollment $e, string $endsOn) => $service->withdraw($e, $endsOn, $assured[0], $context->actor(), $assured[1]));
    }

    public function cancel(Request $request, TenantContext $context, StudentEnrollmentService $service, FreshMfaRequirement $mfa, string $enrollment): RedirectResponse
    {
        // An unknown placement is the 404 before a code is spent.
        StudentEnrollment::query()->findOrFail($enrollment);
        $assured = $this->assured($request, $context, $mfa, StudentLifecycleReason::CANCEL_ENROLLMENT);

        return $this->transition($request, $context, $enrollment, fn (StudentEnrollment $e, string $endsOn) => $service->cancel($e, $endsOn, $assured[0], $context->actor(), $assured[1]));
    }

    /**
     * SIS 4.2: cancel a placement that has not started yet -- the closed cancel
     * reasons, confirmation and a fresh code like cancel(), but no end date
     * (the service records the empty interval). A started placement, or one
     * other records already depend on, is refused by the service.
     */
    public function cancelBeforeStart(Request $request, TenantContext $context, StudentEnrollmentService $service, FreshMfaRequirement $mfa, string $enrollment): RedirectResponse
    {
        $context->requireSchool();
        $model = StudentEnrollment::query()->findOrFail($enrollment);
        $validated = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(StudentLifecycleReason::CODES[StudentLifecycleReason::CANCEL_ENROLLMENT])],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that this placement is cancelled before it starts.']);
        $proof = $mfa->requireForAction($request, $context->actor());

        try {
            $service->cancelBeforeStart($model, $validated['reason_code'], $context->actor(), $proof);
        } catch (InvalidEnrollmentTransitionException|InvalidEnrollmentDateRangeException $e) {
            throw ValidationException::withMessages(['reason_code' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$model->student_id}");
    }

    /** SIS 4.2: correct an active placement's roll number (unique in its Section for the year). */
    public function correctRollNumber(Request $request, TenantContext $context, StudentEnrollmentService $service, string $enrollment): RedirectResponse
    {
        $context->requireSchool();
        $model = StudentEnrollment::query()->findOrFail($enrollment);
        $validated = $request->validate(['roll_number' => ['required', 'string', 'max:255']]);

        try {
            $service->correctRollNumber($model, $validated['roll_number'], $context->actor());
        } catch (DuplicateEnrollmentRollNumberException|InvalidEnrollmentRollNumberException|InvalidEnrollmentTransitionException $e) {
            throw ValidationException::withMessages(['roll_number' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$model->student_id}");
    }

    /**
     * The reason and confirmation are validated first, then the fresh code
     * (outside the transaction).
     *
     * @return array{0: string, 1: FreshMfaProof}
     */
    private function assured(Request $request, TenantContext $context, FreshMfaRequirement $mfa, string $action): array
    {
        $context->requireSchool();
        $validated = $request->validate([
            'ends_on' => ['required', 'date'],
            'reason_code' => ['required', 'string', Rule::in(StudentLifecycleReason::CODES[$action])],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that this placement ends.']);

        return [$validated['reason_code'], $mfa->requireForAction($request, $context->actor())];
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
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

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
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

        $model = StudentEnrollment::query()->with(['student', 'academicYear', 'campus', 'gradeLevel', 'section'])->findOrFail($enrollment);

        // ENR-01 (SIS 4.2): only Sections the transfer can accept -- the
        // placement's own year and Grade, never its current Section.
        $sections = array_values(array_filter(
            $this->sectionOptions($school, onlyEligible: true),
            fn (array $s) => $s['academicYearId'] === $model->academic_year_id && $s['gradeLevelId'] === $model->grade_level_id && $s['id'] !== $model->section_id,
        ));

        return Inertia::render('App/Enrollments/Transfer', [
            'enrollment' => $this->presentSummary($model),
            'sections' => $sections,
        ]);
    }

    public function transfer(Request $request, TenantContext $context, StudentEnrollmentService $service, string $enrollment): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7F-2: enrollments.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:enrollments.manage` (declared).

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

        // ENR-01 (SIS 4.2): the active year first, then draft (next) years
        // oldest first, then closed; within a year by Grade order and name.
        $yearRank = ['active' => 0, 'draft' => 1, 'closed' => 2];

        return $query->get()
            ->sortBy(fn (Section $s) => sprintf('%d|%s|%06d|%s', $yearRank[$s->academicYear->status] ?? 3, $s->academicYear->starts_on->toDateString(), $s->gradeLevel->sequence, mb_strtolower($s->name)))
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
