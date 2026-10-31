<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\Students\Application\StudentLifecycleReason;
use App\Domain\Students\Application\StudentProfileVersion;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Validation\DateOfBirth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 1A.5: the administrative HTTP boundary for Student identity --
 * thin by construction. Every mutation delegates to
 * App\Domain\Students\Application\StudentService (authorization-neutral,
 * Phase 1A.4); this controller's only jobs are authorization, tenant-
 * safe resolution, input validation, and response shaping. Never
 * `Student::create($request->all())`/`$model->update($request->all())`
 * -- every write uses an explicit validated whitelist.
 *
 * `$this->authorizeCapability(...)` runs on every action (matching
 * AcademicYearController/CampusController's established pattern) --
 * for mutation actions this is deliberately redundant with the
 * `capability:` route middleware already applied in routes/api.php
 * (defense in depth, the same double-check every existing mutation
 * controller in this codebase already performs).
 */
class StudentController extends Controller
{
    use AuthorizesCapability;

    /**
     * ORG.7F-1 (ADR 0076 §45): the full detail (date of birth included) is a
     * declared whole-School-only operation -- the route carries the ordinary
     * `capability:students.view` floor, which a Campus-scoped grant never
     * satisfies. The list is Campus-aware (StudentDirectoryController).
     */
    public function show(School $school, string $student): JsonResponse
    {

        abort_if(! Str::isUuid($student), 404);
        $model = Student::query()->findOrFail($student);

        return response()->json(['data' => $this->presentDetail($model)]);
    }

    public function store(Request $request, School $school, StudentService $service): JsonResponse
    {
        // ORG.7F-2: students.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:students.manage` (declared).

        // SIS 2.3: the Student number is allocated by StudentService, never
        // supplied (a client still sending one gets a field error rather than
        // a silently different number). A same-name-and-birthday match is a
        // 409 POSSIBLE_DUPLICATE_STUDENT carrying `details.candidateCount` and
        // `details.duplicateAcknowledgement` (never names or ids); resending
        // with that acknowledgement (a new request, so a new Idempotency-Key:
        // the refusal is stored and replayed under its own key like any other
        // response) creates a distinct Student, and the candidates are
        // re-evaluated first.
        $validated = $request->validate([
            'student_number' => ['prohibited'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => DateOfBirth::rules($school),
            'duplicate_acknowledgement' => ['nullable', 'string', 'max:128'],
        ], [
            'student_number.prohibited' => 'The Student number is allocated by the School and cannot be supplied.',
            ...DateOfBirth::messages(),
        ]);

        $student = $service->create($school, Arr::except($validated, ['duplicate_acknowledgement', 'student_number']), $request->user(), $validated['duplicate_acknowledgement'] ?? null);

        return response()->json(['data' => $this->presentDetail($student)], 201);
    }

    public function update(Request $request, School $school, string $student, StudentService $service): JsonResponse
    {
        // ORG.7F-2: students.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:students.manage` (declared).

        abort_if(! Str::isUuid($student), 404);
        $model = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'student_number' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_of_birth' => DateOfBirth::rules($school, required: false),
            // SIS 11.H1 (SIS 11.R1, SIS.EXP-R1 R-2): required -- the StudentProfileVersion
            // the client read; a stale write is refused (409 STUDENT_PROFILE_CHANGED).
            'version' => ['required', 'string', 'size:64'],
        ], DateOfBirth::messages());
        $version = $validated['version'];
        unset($validated['version']);

        $updated = $service->update($model, $validated, $request->user(), $version);

        return response()->json(['data' => $this->presentDetail($updated)]);
    }

    public function changeStatus(Request $request, School $school, string $student, StudentService $service): JsonResponse
    {
        // ORG.7F-2: students.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:students.manage` (declared).

        abort_if(! Str::isUuid($student), 404);
        $model = Student::query()->findOrFail($student);

        // ORG.8E (ADR 0076 §63): a closed reason and a fresh MFA code (as on the web).
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'reason_code' => ['required', 'string', Rule::in(StudentLifecycleReason::CODES[$request->input('status') === 'inactive' ? StudentLifecycleReason::DEACTIVATE : StudentLifecycleReason::REACTIVATE])],
        ]);
        $proof = app(FreshMfaRequirement::class)->requireForAction($request, $request->user());

        $updated = $service->changeStatus($model, $validated['status'], $validated['reason_code'], $request->user(), $proof);

        return response()->json(['data' => $this->presentDetail($updated)]);
    }

    /**
     * List/search row -- deliberately excludes `dateOfBirth`
     * (Highly Sensitive children's data,
     * docs/security/DATA-CLASSIFICATION.md: "minimized default
     * visibility... not included in broad list/summary views by
     * default"). Full detail is available via show()/store()/update(),
     * a single-record view where the capability check already gates
     * exactly the actor who needs it.
     *
     * @return array<string, mixed>
     */
    private function presentSummary(Student $student): array
    {
        return [
            'id' => $student->id,
            'studentNumber' => $student->student_number,
            'firstName' => $student->first_name,
            'middleName' => $student->middle_name,
            'lastName' => $student->last_name,
            'status' => $student->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Student $student): array
    {
        return [
            ...$this->presentSummary($student),
            'dateOfBirth' => $student->date_of_birth->toDateString(),
            // SIS 11.H1: required back on PATCH (stale-update protection).
            'version' => StudentProfileVersion::of($student),
            'createdAt' => $student->created_at->toIso8601String(),
            'updatedAt' => $student->updated_at->toIso8601String(),
        ];
    }
}
