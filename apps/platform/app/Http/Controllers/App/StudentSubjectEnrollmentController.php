<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\ElectiveEnrollmentCandidateReadService;
use App\Domain\Students\Application\Exceptions\ActiveSubjectEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossSchoolSubjectEnrollmentException;
use App\Domain\Students\Application\Exceptions\ElectiveGroupConflictException;
use App\Domain\Students\Application\Exceptions\InactiveSubjectOfferingException;
use App\Domain\Students\Application\Exceptions\IncompatibleSubjectOfferingException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\InvalidSubjectEnrollmentTransitionException;
use App\Domain\Students\Application\Exceptions\RequiredSubjectOfferingEnrollmentException;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 1H.1: session-authenticated mutation actions plus the two
 * narrow read adapters (`eligibleStudents`/`transferTargets`) the
 * elective administration workspace needs -- mirrors the domain-level
 * split already established by
 * `App\Domain\Students\Http\Controllers\StudentSubjectEnrollmentController`
 * (the Bearer-token JSON API, unchanged by this checkpoint). Every
 * mutation delegates entirely to the SAME
 * `StudentSubjectEnrollmentService` the JSON API uses -- no business
 * logic duplicated, only the HTTP binding/error-translation layer is
 * mirrored, exactly like `EnrollmentRolloverSubjectMappingController`
 * (App) already establishes for Phase 1G.4.
 *
 * All four mutation actions are gated by `academics.subjects.manage`
 * only -- never `students.view`/`students.manage`/`enrollments.manage`
 * (docs/students/PHASE-1H-0-...ARCHITECTURE.md §13).
 */
class StudentSubjectEnrollmentController extends Controller
{
    use AuthorizesCapability;

    public function enroll(Request $request, TenantContext $context, StudentSubjectEnrollmentService $service, string $subjectOffering): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $offering = SubjectOffering::query()->findOrFail($subjectOffering);

        $validated = $request->validate([
            'student_id' => ['required', 'uuid', Rule::exists('students', 'id')->where('school_id', $school->id)],
            'starts_on' => ['required', 'date'],
        ]);

        $student = Student::query()->findOrFail($validated['student_id']);

        try {
            $service->enroll($student, $offering, $validated['starts_on'], $context->actor());
        } catch (RequiredSubjectOfferingEnrollmentException $e) {
            throw ValidationException::withMessages(['student_id' => ['This Offering is required and does not support explicit enrollment.']]);
        } catch (InactiveSubjectOfferingException $e) {
            throw ValidationException::withMessages(['student_id' => ['This Offering is not currently active and cannot accept new participation.']]);
        } catch (IncompatibleSubjectOfferingException $e) {
            throw ValidationException::withMessages(['student_id' => ["This Student's current enrollment doesn't match this Offering's Year, Grade, or Campus."]]);
        } catch (ActiveSubjectEnrollmentConflictException $e) {
            throw ValidationException::withMessages(['student_id' => ['This Student is already enrolled in this Offering.']]);
        } catch (ElectiveGroupConflictException $e) {
            throw ValidationException::withMessages(['student_id' => ['This Student already has an active choice in the same elective group -- withdraw or transfer that one first.']]);
        } catch (CrossSchoolSubjectEnrollmentException $e) {
            throw ValidationException::withMessages(['student_id' => ['This Student could not be enrolled into this Offering.']]);
        }

        return redirect("/app/subject-offerings/{$offering->id}");
    }

    public function withdraw(Request $request, TenantContext $context, StudentSubjectEnrollmentService $service, string $enrollment): RedirectResponse
    {
        return $this->transition($request, $context, $enrollment, fn (StudentSubjectEnrollment $e, string $endsOn) => $service->withdraw($e, $endsOn, $context->actor()));
    }

    public function cancel(Request $request, TenantContext $context, StudentSubjectEnrollmentService $service, string $enrollment): RedirectResponse
    {
        return $this->transition($request, $context, $enrollment, fn (StudentSubjectEnrollment $e, string $endsOn) => $service->cancel($e, $endsOn, $context->actor()));
    }

    /**
     * Shared shape for the two terminal one-field (`ends_on`) lifecycle
     * actions -- each still calls its OWN named service method (never a
     * generic status setter), matching
     * `App\Http\Controllers\App\StudentEnrollmentController::transition()`'s
     * identical established pattern one level down.
     *
     * @param  callable(StudentSubjectEnrollment, string): StudentSubjectEnrollment  $apply
     */
    private function transition(Request $request, TenantContext $context, string $enrollment, callable $apply): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $model = StudentSubjectEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        try {
            $apply($model, $validated['ends_on']);
        } catch (InvalidSubjectEnrollmentTransitionException $e) {
            throw ValidationException::withMessages(['ends_on' => ['This enrollment can no longer be withdrawn or cancelled from its current state.']]);
        } catch (InvalidEnrollmentDateRangeException $e) {
            throw ValidationException::withMessages(['ends_on' => [$e->getMessage()]]);
        }

        return redirect("/app/subject-offerings/{$model->subject_offering_id}");
    }

    public function transfer(Request $request, TenantContext $context, StudentSubjectEnrollmentService $service, string $enrollment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $model = StudentSubjectEnrollment::query()->findOrFail($enrollment);
        $sourceOfferingId = $model->subject_offering_id;

        $validated = $request->validate([
            'target_subject_offering_id' => ['required', 'uuid', Rule::exists('subject_offerings', 'id')->where('school_id', $school->id)],
            'effective_date' => ['required', 'date'],
        ]);

        $target = SubjectOffering::query()->findOrFail($validated['target_subject_offering_id']);

        try {
            $transferred = $service->transfer($model, $target, $validated['effective_date'], $context->actor());
        } catch (InactiveSubjectOfferingException $e) {
            throw ValidationException::withMessages(['target_subject_offering_id' => ['This Offering is not currently active and cannot accept transferred-in participation.']]);
        } catch (RequiredSubjectOfferingEnrollmentException $e) {
            throw ValidationException::withMessages(['target_subject_offering_id' => ['This Offering is required and does not support explicit enrollment.']]);
        } catch (IncompatibleSubjectOfferingException $e) {
            throw ValidationException::withMessages(['target_subject_offering_id' => ["This Student's current enrollment doesn't match this Offering's Year, Grade, or Campus."]]);
        } catch (ElectiveGroupConflictException $e) {
            throw ValidationException::withMessages(['target_subject_offering_id' => ['This Student already has an active choice in the same elective group.']]);
        } catch (InvalidSubjectEnrollmentTransitionException $e) {
            throw ValidationException::withMessages(['target_subject_offering_id' => ['This enrollment can no longer be transferred from its current state.']]);
        } catch (InvalidEnrollmentDateRangeException $e) {
            throw ValidationException::withMessages(['effective_date' => [$e->getMessage()]]);
        } catch (CrossSchoolSubjectEnrollmentException $e) {
            throw ValidationException::withMessages(['target_subject_offering_id' => ['This transfer could not be completed.']]);
        }

        return redirect("/app/subject-offerings/{$sourceOfferingId}");
    }

    // --- Read adapters (root task §8/§9, App-only, never OpenAPI) --------

    public function eligibleStudents(Request $request, TenantContext $context, ElectiveEnrollmentCandidateReadService $reads, string $subjectOffering): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $offering = SubjectOffering::query()->findOrFail($subjectOffering);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);

        return response()->json(['data' => $reads->eligibleStudents($offering, $validated['q'] ?? null)]);
    }

    public function transferTargets(TenantContext $context, ElectiveEnrollmentCandidateReadService $reads, string $enrollment): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $source = StudentSubjectEnrollment::query()->findOrFail($enrollment);

        $targets = $reads->transferTargets($source)->map(fn (SubjectOffering $o) => [
            'id' => $o->id,
            'subject' => $o->subject === null ? null : ['id' => $o->subject->id, 'name' => $o->subject->name, 'code' => $o->subject->code],
            'campus' => $o->campus === null ? null : ['id' => $o->campus->id, 'name' => $o->campus->name],
            'gradeLevel' => $o->gradeLevel === null ? null : ['id' => $o->gradeLevel->id, 'name' => $o->gradeLevel->name],
            'status' => $o->status,
            'electiveGroup' => $o->electiveGroup === null ? null : ['id' => $o->electiveGroup->id, 'name' => $o->electiveGroup->name],
        ])->values()->all();

        return response()->json(['data' => $targets]);
    }
}
