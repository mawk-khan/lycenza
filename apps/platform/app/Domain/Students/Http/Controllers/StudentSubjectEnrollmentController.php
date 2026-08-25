<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Application\SubjectOfferingRosterReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 1C.1: the administrative HTTP boundary for
 * StudentSubjectEnrollment -- deliberately narrow (roster read + the
 * four write actions only), reusing `academics.subjects.view`/`.manage`
 * rather than inventing new capabilities (this is a direct extension of
 * "manage Subjects and Subject Offerings", not a new concern -- see the
 * documentation file's "Authorization" section for the reuse decision).
 * Mirrors StudentEnrollmentController's exact shape one level down.
 *
 * The roster endpoint deliberately returns Student ids/summary only --
 * never grades, attendance, medical data, fee state, or private Student
 * notes (CLAUDE.md section on Sensitive/Highly Sensitive data) -- it is
 * the same narrow surface SubjectOfferingRosterReadService itself
 * exposes, just presented as JSON.
 */
class StudentSubjectEnrollmentController extends Controller
{
    use AuthorizesCapability;

    public function roster(School $school, string $subjectOffering, SubjectOfferingRosterReadService $reads): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.view', $school);

        $offering = SubjectOffering::query()->findOrFail($subjectOffering);

        $studentIds = $reads->currentRosterStudentIds($offering);

        $students = Student::query()
            ->whereIn('id', $studentIds)
            ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name'])
            ->keyBy('id');

        return response()->json([
            'data' => collect($studentIds)->map(fn (string $id) => $this->presentStudentRef($students->get($id)))->filter()->values()->all(),
            'meta' => ['count' => count($studentIds), 'isRequired' => $offering->is_required],
        ]);
    }

    public function store(Request $request, School $school, string $student, StudentSubjectEnrollmentService $service): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $studentModel = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid', Rule::exists('subject_offerings', 'id')->where('school_id', $school->id)],
            'starts_on' => ['required', 'date'],
        ]);

        $offering = SubjectOffering::query()->findOrFail($validated['subject_offering_id']);

        $enrollment = $service->enroll($studentModel, $offering, $validated['starts_on'], $request->user());
        $enrollment->setRelation('student', $studentModel);

        return response()->json(['data' => $this->presentDetail($enrollment)], 201);
    }

    public function withdraw(Request $request, School $school, string $enrollment, StudentSubjectEnrollmentService $service): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $model = StudentSubjectEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $withdrawn = $service->withdraw($model, $validated['ends_on'], $request->user());

        return response()->json(['data' => $this->presentDetail($withdrawn->load(['student', 'subjectOffering']))]);
    }

    public function cancel(Request $request, School $school, string $enrollment, StudentSubjectEnrollmentService $service): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $model = StudentSubjectEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $cancelled = $service->cancel($model, $validated['ends_on'], $request->user());

        return response()->json(['data' => $this->presentDetail($cancelled->load(['student', 'subjectOffering']))]);
    }

    public function transfer(Request $request, School $school, string $enrollment, StudentSubjectEnrollmentService $service): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $model = StudentSubjectEnrollment::query()->findOrFail($enrollment);

        $validated = $request->validate([
            'target_subject_offering_id' => ['required', 'uuid', Rule::exists('subject_offerings', 'id')->where('school_id', $school->id)],
            'effective_date' => ['required', 'date'],
        ]);

        $targetOffering = SubjectOffering::query()->findOrFail($validated['target_subject_offering_id']);

        $transferred = $service->transfer($model, $targetOffering, $validated['effective_date'], $request->user());

        return response()->json(['data' => $this->presentDetail($transferred->load(['student', 'subjectOffering']))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(StudentSubjectEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'student' => $this->presentStudentRef($enrollment->student),
            'subjectOfferingId' => $enrollment->subject_offering_id,
            'academicYearId' => $enrollment->academic_year_id,
            'status' => $enrollment->status,
            'startsOn' => $enrollment->starts_on->toDateString(),
            'endsOn' => $enrollment->ends_on?->toDateString(),
            'createdAt' => $enrollment->created_at->toIso8601String(),
            'updatedAt' => $enrollment->updated_at->toIso8601String(),
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
}
