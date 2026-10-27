<?php

namespace App\Http\Controllers\App;

use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException;
use App\Domain\Students\Application\Exceptions\PossibleDuplicateStudentException;
use App\Domain\Students\Application\Exceptions\StudentLifecycleReasonException;
use App\Domain\Students\Application\Exceptions\StudentStatusUnchangedException;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentActivityHistoryService;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentLifecycleReason;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\App\Concerns\PresentsDuplicateStudentWarning;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\DateOfBirth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 1A.6: session-authenticated Inertia pages for Student identity
 * administration -- the same convention SchoolSetupController already
 * established (NOT the Bearer-token JSON API under /api/v1, which
 * remains the separate Flutter/external-consumer surface Phase 1A.5
 * built; docs/modules/STUDENT-GUARDIAN-IDENTITY.md "Administrative UI
 * architecture"). Every mutation delegates to
 * App\Domain\Students\Application\StudentService -- the exact same
 * service the JSON API controller uses -- never duplicated here.
 *
 * Unlike the JSON API (where DuplicateStudentNumberException's own
 * getStatusCode()/errorCode() render automatically via bootstrap/
 * app.php's /api/* exception envelope), Inertia's client-side form
 * error handling (`form.errors`) only recognizes Laravel's own
 * ValidationException -- so store()/update() explicitly translate the
 * one domain exception a user can plausibly trigger here into
 * ValidationException::withMessages(...), the same pattern
 * GuardianController::storeContact() already uses for its own two
 * expected failure modes.
 */
class StudentController extends Controller
{
    use AuthorizesCapability, PresentsDuplicateStudentWarning;

    /** SIS 2.3: a date of birth is never after the School's own today. */
    public function create(Request $request, TenantContext $context): Response
    {
        $school = $context->requireSchool();
        // ORG.7F-2: students.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:students.manage` (declared).

        return Inertia::render('App/Students/Create', [
            // SIS 2.3: the duplicate-risk warning of the last refused submit.
            'duplicateWarning' => $this->duplicateWarningFor($request, $school, $context->actor(), 'student-create'),
            'today' => SchoolTimezone::today($school),
        ]);
    }

    /**
     * SIS 2.3: the Student number is allocated by StudentService (never an
     * input); a same-name-and-birthday match is a warning the operator must
     * acknowledge (`duplicate_acknowledgement`), never an automatic block.
     */
    public function store(Request $request, TenantContext $context, StudentService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7F-2: students.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:students.manage` (declared).

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => DateOfBirth::rules($school),
            'duplicate_acknowledgement' => ['nullable', 'string', 'max:128'],
        ], DateOfBirth::messages());

        try {
            $student = $service->create($school, Arr::except($validated, ['duplicate_acknowledgement']), $context->actor(), $validated['duplicate_acknowledgement'] ?? null);
        } catch (PossibleDuplicateStudentException $e) {
            $this->refuseAsPossibleDuplicate($request, 'student-create', $e);
        }

        return redirect("/app/students/{$student->id}")->with('flash', "Student {$student->student_number} added.");
    }

    /**
     * V11.2 (ADR 0075): the Student's operational history -- a read
     * projection over the audit ledger (StudentActivityHistoryService),
     * each category shown only to holders of its source capability.
     */
    public function history(TenantContext $context, StudentActivityHistoryService $histories, string $student): Response
    {
        // ORG.7F-1 (ADR 0076 §45): a declared whole-School-only operation --
        // the route carries the ordinary `capability:students.view` floor,
        // which a Campus-scoped grant never satisfies.
        $school = $context->requireSchool();

        $result = $histories->forStudent($school, $student, $context->actor());
        abort_if($result === null, 404);

        return Inertia::render('App/Students/History', [
            'student' => $this->presentSummary($result['student']),
            'entries' => $result['entries'],
            'truncated' => $result['truncated'],
            'categories' => $result['categories'],
            'limit' => StudentActivityHistoryService::LIMIT,
            // Recorded times are shown in the School's own timezone.
            'timezone' => SchoolTimezone::resolve($school)->getName(),
        ]);
    }

    public function show(TenantContext $context, CapabilityResolver $capabilities, AccountLinkService $accountLinks, StudentEnrollmentReadService $enrollmentReads, StudentAccess $access, string $student): Response
    {
        // ORG.7F-1 (ADR 0076 §45): the full Profile is a declared
        // whole-School-only operation -- the route carries the ordinary
        // `capability:students.view` floor, which a Campus-scoped grant never
        // satisfies (a Campus reader uses the operational overview).
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($student), 404);
        $actor = $context->actor();

        // ORG.7F-1 (F-1): a Guardian's contact VALUE is Guardian contact
        // information, which is `guardians.view` -- never `students.view`
        // alone (docs/modules/STUDENT-GUARDIAN-IDENTITY.md). Without it the
        // contacts are not even loaded, so no value can be serialized.
        $canViewGuardianContacts = $capabilities->canInSchool($actor, 'guardians.view', $school);
        $model = Student::query()
            ->with($canViewGuardianContacts ? 'guardianRelationships.guardian.contacts' : 'guardianRelationships.guardian')
            ->findOrFail($student);

        // Phase 5B.2: the optional, explicit School OS account link.
        $link = $accountLinks->activeLinkForStudent($model);

        $props = [
            'student' => $this->presentDetail($model),
            'accountLink' => $link === null ? null : [
                'schoolMembershipId' => $link->school_membership_id,
                'memberName' => $link->membership->user->name,
                'membershipActive' => $link->membership->isActive(),
            ],
            'relationships' => $model->guardianRelationships->map(function ($r) use ($canViewGuardianContacts) {
                // Only an ACTIVE contact is ever shown: the active primary,
                // else the first active one (a deactivated primary is never
                // chosen).
                $primaryContact = ! $canViewGuardianContacts ? null
                    : ($r->guardian->contacts->first(fn ($c) => $c->is_primary && $c->is_active)
                        ?? $r->guardian->contacts->firstWhere('is_active', true));

                return [
                    'id' => $r->id,
                    'relationshipType' => $r->relationship_type->value,
                    'isPrimary' => $r->is_primary,
                    'isLegalGuardian' => $r->is_legal_guardian,
                    'isEmergencyContact' => $r->is_emergency_contact,
                    'isAuthorizedPickup' => $r->is_authorized_pickup,
                    'guardian' => [
                        'id' => $r->guardian->id,
                        'firstName' => $r->guardian->first_name,
                        'middleName' => $r->guardian->middle_name,
                        'lastName' => $r->guardian->last_name,
                        'status' => $r->guardian->status,
                    ],
                    'contact' => $primaryContact ? [
                        'type' => $primaryContact->type->value,
                        'value' => $primaryContact->encrypted_value,
                    ] : null,
                ];
            })->all(),
            'canManageStudents' => $access->holdsWholeSchool($actor, 'students.manage', $school),
            'canManageGuardians' => $capabilities->canInSchool($actor, 'guardians.manage', $school),
            'canViewGuardianContacts' => $canViewGuardianContacts,
            // SIS 6.1: a link to this Student's attendance records, for anyone who
            // reads Attendance (that page re-checks its own Campus scope).
            'canViewAttendance' => ! $access->scopeOf($actor, 'attendance.view', $school)->isNone(),
        ];

        // Phase 1B.6: Enrollment data is included ONLY when the actor
        // holds enrollments.view -- a user with students.view but not
        // enrollments.view (Phase 1B.4's independent capability design)
        // must see the Student's identity normally, with no Enrollment
        // props present at all (this checkpoint's brief, section 35).
        // enrollments.manage never implies enrollments.view (section 30)
        // -- canManageEnrollments is only meaningful/present alongside
        // canViewEnrollments.
        // ORG.7F-1: Enrollment history is whole-School (a Campus-scoped
        // enrollments.view grant reads current placements elsewhere).
        if ($access->holdsWholeSchool($actor, 'enrollments.view', $school)) {
            $current = $enrollmentReads->currentFor($model);
            $history = $enrollmentReads->historyFor($model);

            $props['canViewEnrollments'] = true;
            $props['canManageEnrollments'] = $access->holdsWholeSchool($actor, 'enrollments.manage', $school);
            $props['currentEnrollment'] = $current ? $this->presentEnrollment($current) : null;
            $props['enrollmentHistory'] = $history->map(fn (StudentEnrollment $e) => $this->presentEnrollment($e))->values()->all();
        } else {
            $props['canViewEnrollments'] = false;
        }

        // ORG.8E (ADR 0076 §63): the closed reasons of the high-impact actions, and whether a fresh code can be given.
        $props['lifecycleReasons'] = StudentLifecycleReason::CODES;
        $props['hasMfaFactor'] = app(FreshMfaRequirement::class)->hasActiveFactor($actor);

        return Inertia::render('App/Students/Show', $props);
    }

    public function edit(TenantContext $context, string $student): Response
    {
        $school = $context->requireSchool();
        // ORG.7F-2: students.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:students.manage` (declared).

        $model = Student::query()->findOrFail($student);

        return Inertia::render('App/Students/Edit', [
            'student' => $this->presentDetail($model),
            'today' => SchoolTimezone::today($school),
        ]);
    }

    public function update(Request $request, TenantContext $context, StudentService $service, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7F-2: students.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:students.manage` (declared).

        $model = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => DateOfBirth::rules($school),
        ], DateOfBirth::messages());

        try {
            $service->update($model, $validated, $context->actor());
        } catch (DuplicateStudentNumberException) {
            throw ValidationException::withMessages([
                'student_number' => ['This Student Number is already in use.'],
            ]);
        }

        return redirect("/app/students/{$model->id}")->with('flash', 'Student details saved.');
    }

    /**
     * ORG.8E (ADR 0076 §63): deactivate / reactivate -- a closed reason, an
     * explicit confirmation (UX only) and a fresh MFA code (after the route
     * capability, outside the transaction); the service re-checks current
     * whole-School authority and records the lifecycle event.
     */
    public function changeStatus(Request $request, TenantContext $context, StudentService $service, FreshMfaRequirement $mfa, string $student): RedirectResponse
    {
        $context->requireSchool();
        // ORG.7F-2: students.manage is Campus-adopted; this whole-School-only
        // operation's floor is its route `capability:students.manage` (declared).

        $model = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'reason_code' => ['required', 'string', Rule::in(StudentLifecycleReason::CODES[$request->input('status') === 'inactive' ? StudentLifecycleReason::DEACTIVATE : StudentLifecycleReason::REACTIVATE])],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm this change to the Student\'s status.']);

        $proof = $mfa->requireForAction($request, $context->actor());

        try {
            $service->changeStatus($model, $validated['status'], $validated['reason_code'], $context->actor(), $proof);
        } catch (StudentStatusUnchangedException|StudentLifecycleReasonException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$model->id}")->with('flash', "Student marked {$model->status}.");
    }

    /**
     * List-row shape -- deliberately excludes dateOfBirth (Highly
     * Sensitive children's data, docs/security/DATA-CLASSIFICATION.md),
     * exactly mirroring StudentController@presentSummary in the Phase
     * 1A.5 JSON API.
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
            'createdAt' => $student->created_at->toIso8601String(),
        ];
    }

    /**
     * Phase 1B.6: mirrors StudentEnrollmentController's own
     * presentSummary() exactly (a small, presentation-only duplication
     * -- the same StudentController API/web duplication pattern already
     * established above -- never a re-derivation of Enrollment business
     * rules). Deliberately excludes dateOfBirth/Guardian PII.
     *
     * @return array<string, mixed>
     */
    private function presentEnrollment(StudentEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            // V11.3: status/endsOn let the page offer the existing Complete/
            // Withdraw for a placement left open in a CLOSED year (R-1).
            'academicYear' => [
                'id' => $enrollment->academicYear->id,
                'name' => $enrollment->academicYear->name,
                'status' => $enrollment->academicYear->status,
                'endsOn' => $enrollment->academicYear->ends_on->toDateString(),
            ],
            'campus' => ['id' => $enrollment->campus->id, 'name' => $enrollment->campus->name],
            'gradeLevel' => ['id' => $enrollment->gradeLevel->id, 'name' => $enrollment->gradeLevel->name],
            'section' => ['id' => $enrollment->section->id, 'name' => $enrollment->section->name],
            'rollNumber' => $enrollment->roll_number,
            'status' => $enrollment->status,
            'startsOn' => $enrollment->starts_on->toDateString(),
            'endsOn' => $enrollment->ends_on?->toDateString(),
            // SIS 4.2: an active placement that has not started can be
            // cancelled before it starts; one so cancelled was never effective.
            'startsInFuture' => $enrollment->status === 'active' && $enrollment->starts_on->toDateString() > SchoolTimezone::today($enrollment->school),
            'cancelledBeforeStart' => $enrollment->status === 'cancelled' && $enrollment->ends_on !== null && $enrollment->ends_on->lt($enrollment->starts_on),
        ];
    }
}
