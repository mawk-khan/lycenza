<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.view', $school);

        $validated = $request->validate([
            'student_number' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Student::query()->orderBy('first_name')->orderBy('last_name');

        if (isset($validated['student_number'])) {
            $query->where('student_number', $validated['student_number']);
        }

        if (isset($validated['name'])) {
            $term = '%'.$validated['name'].'%';
            $query->where(fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term));
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Students/Index', [
            'students' => $paginator->through(fn (Student $s) => $this->presentSummary($s)),
            'filters' => [
                'student_number' => $validated['student_number'] ?? '',
                'name' => $validated['name'] ?? '',
                'status' => $validated['status'] ?? '',
            ],
            'canManage' => $capabilities->canInSchool($context->actor(), 'students.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.manage', $school);

        return Inertia::render('App/Students/Create');
    }

    public function store(Request $request, TenantContext $context, StudentService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.manage', $school);

        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
        ]);

        try {
            $student = $service->create($school, $validated, $context->actor());
        } catch (DuplicateStudentNumberException) {
            throw ValidationException::withMessages([
                'student_number' => ['This Student Number is already in use.'],
            ]);
        }

        return redirect("/app/students/{$student->id}");
    }

    public function show(TenantContext $context, CapabilityResolver $capabilities, StudentEnrollmentReadService $enrollmentReads, string $student): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.view', $school);

        // Guardian contacts are eager-loaded here (a single-record
        // detail view) but deliberately excluded from the Student
        // INDEX row -- the same "detail views may show more than a
        // broad list view" principle already applied to dateOfBirth
        // (docs/security/DATA-CLASSIFICATION.md).
        $model = Student::query()->with('guardianRelationships.guardian.contacts')->findOrFail($student);

        $props = [
            'student' => $this->presentDetail($model),
            'relationships' => $model->guardianRelationships->map(function ($r) {
                $primaryContact = $r->guardian->contacts->firstWhere('is_primary', true)
                    ?? $r->guardian->contacts->firstWhere('is_active', true);

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
            'canManageStudents' => $capabilities->canInSchool($context->actor(), 'students.manage', $school),
            'canManageGuardians' => $capabilities->canInSchool($context->actor(), 'guardians.manage', $school),
        ];

        // Phase 1B.6: Enrollment data is included ONLY when the actor
        // holds enrollments.view -- a user with students.view but not
        // enrollments.view (Phase 1B.4's independent capability design)
        // must see the Student's identity normally, with no Enrollment
        // props present at all (this checkpoint's brief, section 35).
        // enrollments.manage never implies enrollments.view (section 30)
        // -- canManageEnrollments is only meaningful/present alongside
        // canViewEnrollments.
        if ($capabilities->canInSchool($context->actor(), 'enrollments.view', $school)) {
            $current = $enrollmentReads->currentFor($model);
            $history = $enrollmentReads->historyFor($model);

            $props['canViewEnrollments'] = true;
            $props['canManageEnrollments'] = $capabilities->canInSchool($context->actor(), 'enrollments.manage', $school);
            $props['currentEnrollment'] = $current ? $this->presentEnrollment($current) : null;
            $props['enrollmentHistory'] = $history->map(fn (StudentEnrollment $e) => $this->presentEnrollment($e))->values()->all();
        } else {
            $props['canViewEnrollments'] = false;
        }

        return Inertia::render('App/Students/Show', $props);
    }

    public function edit(TenantContext $context, string $student): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.manage', $school);

        $model = Student::query()->findOrFail($student);

        return Inertia::render('App/Students/Edit', [
            'student' => $this->presentDetail($model),
        ]);
    }

    public function update(Request $request, TenantContext $context, StudentService $service, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.manage', $school);

        $model = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
        ]);

        try {
            $service->update($model, $validated, $context->actor());
        } catch (DuplicateStudentNumberException) {
            throw ValidationException::withMessages([
                'student_number' => ['This Student Number is already in use.'],
            ]);
        }

        return redirect("/app/students/{$model->id}");
    }

    public function changeStatus(Request $request, TenantContext $context, StudentService $service, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.manage', $school);

        $model = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $service->changeStatus($model, $validated['status'], $context->actor());

        return redirect("/app/students/{$model->id}");
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
}
