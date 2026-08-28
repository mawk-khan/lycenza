<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\StudentSubjectEnrollmentHistoryReadService;
use App\Domain\Students\Application\SubjectOfferingRosterReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 1H.1: session-authenticated Inertia pages for the
 * SubjectOffering roster / elective administration workspace
 * (docs/students/PHASE-1H-0-ELECTIVE-ADMINISTRATION-UI-ARCHITECTURE.md
 * -- Option C, a genuinely new top-level `/app/subject-offerings`
 * surface, matching the Phase 1G.4 Enrollment-Rollovers precedent
 * rather than extending School Setup or Students admin). Read-only
 * concerns only -- every mutation lives in
 * `App\Http\Controllers\App\StudentSubjectEnrollmentController`,
 * mirroring the exact domain-level split already established by
 * `App\Domain\AcademicStructure\Http\Controllers\SubjectOfferingController`
 * vs `App\Domain\Students\Http\Controllers\StudentSubjectEnrollmentController`.
 *
 * `SubjectOfferingRosterReadService` remains the ONLY authority for
 * CURRENT roster membership -- this controller never recomputes
 * required/elective membership itself, it only hydrates the safe
 * Student projection for the ids that service already returned
 * (identical shape to the JSON API's `roster()` action).
 */
class SubjectOfferingController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.view', $school);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $academicYearId = $validated['academic_year_id'] ?? AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->value('id');

        /** @var LengthAwarePaginator<int, SubjectOffering> $paginator */
        $paginator = SubjectOffering::query()
            ->when($academicYearId, fn ($q) => $q->where('academic_year_id', $academicYearId))
            ->with(['subject', 'campus', 'gradeLevel', 'electiveGroup'])
            ->orderBy('sequence')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('App/SubjectOfferings/Index', [
            'offerings' => $paginator->through(fn (SubjectOffering $o) => $this->presentOffering($o)),
            'filters' => ['academic_year_id' => $academicYearId ?? ''],
            'academicYears' => AcademicYear::query()->where('school_id', $school->id)->orderByDesc('starts_on')->get()
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code])->all(),
            'canManage' => $capabilities->canInSchool($context->actor(), 'academics.subjects.manage', $school),
        ]);
    }

    public function show(
        Request $request,
        TenantContext $context,
        CapabilityResolver $capabilities,
        SubjectOfferingRosterReadService $roster,
        StudentSubjectEnrollmentHistoryReadService $history,
        string $subjectOffering,
    ): Response {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.view', $school);

        $offering = SubjectOffering::query()->with(['subject', 'campus', 'gradeLevel', 'electiveGroup', 'academicYear'])->findOrFail($subjectOffering);

        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $rosterStudentIds = $roster->currentRosterStudentIds($offering);
        $rosterStudents = Student::query()
            ->whereIn('id', $rosterStudentIds)
            ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name'])
            ->keyBy('id');

        // For an elective Offering, the workspace needs the active
        // StudentSubjectEnrollment id per roster row to withdraw/cancel/
        // transfer it -- membership itself is NOT redecided here, this
        // is a bounded lookup keyed by the ids the roster service
        // already returned as authoritative (root task §10/§13).
        $enrollmentIdsByStudent = $offering->is_required
            ? collect()
            : StudentSubjectEnrollment::query()
                ->where('school_id', $offering->school_id)
                ->where('subject_offering_id', $offering->id)
                ->where('status', 'active')
                ->whereIn('student_id', $rosterStudentIds)
                ->pluck('id', 'student_id');

        $actor = $context->actor();
        $canManage = $capabilities->canInSchool($actor, 'academics.subjects.manage', $school);
        $canViewStudentDetail = $capabilities->canInSchool($actor, 'students.view', $school);

        $historyPaginator = $offering->is_required ? null : $history->forOffering($offering, 25)->withQueryString();

        return Inertia::render('App/SubjectOfferings/Show', [
            'offering' => $this->presentOffering($offering),
            'roster' => collect($rosterStudentIds)
                ->map(function (string $id) use ($rosterStudents, $enrollmentIdsByStudent) {
                    $student = $this->presentStudentRef($rosterStudents->get($id));
                    if ($student === null) {
                        return null;
                    }

                    return [...$student, 'studentSubjectEnrollmentId' => $enrollmentIdsByStudent->get($id)];
                })
                ->filter()
                ->values()
                ->all(),
            'history' => $historyPaginator === null ? null : $historyPaginator->through(fn (StudentSubjectEnrollment $e) => $this->presentHistoryRow($e)),
            'canManage' => $canManage,
            'canViewStudentDetail' => $canViewStudentDetail,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOffering(SubjectOffering $offering): array
    {
        return [
            'id' => $offering->id,
            'subject' => $offering->subject === null ? null : ['id' => $offering->subject->id, 'name' => $offering->subject->name, 'code' => $offering->subject->code],
            'academicYear' => $offering->academicYear === null ? null : ['id' => $offering->academicYear->id, 'name' => $offering->academicYear->name, 'code' => $offering->academicYear->code],
            'campus' => $offering->campus === null ? null : ['id' => $offering->campus->id, 'name' => $offering->campus->name],
            'gradeLevel' => $offering->gradeLevel === null ? null : ['id' => $offering->gradeLevel->id, 'name' => $offering->gradeLevel->name],
            'status' => $offering->status,
            'isRequired' => $offering->is_required,
            'electiveGroup' => $offering->electiveGroup === null ? null : ['id' => $offering->electiveGroup->id, 'name' => $offering->electiveGroup->name],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentStudentRef(?Student $student): ?array
    {
        if ($student === null) {
            return null;
        }

        return [
            'id' => $student->id,
            'studentNumber' => $student->student_number,
            'firstName' => $student->first_name,
            'middleName' => $student->middle_name,
            'lastName' => $student->last_name,
        ];
    }

    /**
     * Deliberately excludes every field beyond status/dates/Student
     * reference -- no transcript/GPA semantics (root task §16/49).
     *
     * @return array<string, mixed>
     */
    private function presentHistoryRow(StudentSubjectEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'student' => $this->presentStudentRef($enrollment->student),
            'status' => $enrollment->status,
            'startsOn' => $enrollment->starts_on->toDateString(),
            'endsOn' => $enrollment->ends_on?->toDateString(),
        ];
    }
}
