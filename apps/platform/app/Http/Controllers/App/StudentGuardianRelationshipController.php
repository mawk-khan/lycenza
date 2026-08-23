<?php

namespace App\Http\Controllers\App;

use App\Domain\Guardians\Application\Exceptions\ConcurrentPrimaryGuardianConflictException;
use App\Domain\Guardians\Application\Exceptions\CrossSchoolRelationshipException;
use App\Domain\Guardians\Application\Exceptions\DuplicateRelationshipException;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Phase 1A.6: the "Add Guardian" workflow and relationship management
 * for the Student detail page. Every mutation delegates to
 * App\Domain\Guardians\Application\StudentGuardianRelationshipService
 * (Phase 1A.4) -- never Student::guardians()->attach()/sync(). Every
 * action requires BOTH students.manage AND guardians.manage, matching
 * the Phase 1A.4/1A.5 accepted capability design exactly (the
 * operation mutates both domain identities' relationship at once) --
 * including the two read-only search/candidate lookup actions, since
 * they only exist in service of this dual-authorized workflow.
 */
class StudentGuardianRelationshipController extends Controller
{
    use AuthorizesCapability;

    /**
     * The "Add guardian" page itself -- find-existing / create-new
     * tabs, both submitting back to this controller.
     */
    public function create(TenantContext $context, string $student): Response
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $model = Student::query()->findOrFail($student);

        return Inertia::render('App/Students/AddGuardian', [
            'student' => [
                'id' => $model->id,
                'firstName' => $model->first_name,
                'lastName' => $model->last_name,
            ],
        ]);
    }

    /**
     * Same-School Guardian name search for the "find existing Guardian"
     * panel -- a plain JSON endpoint (not an Inertia page), fetched
     * live from Vue. Read-only, so no CSRF token is required for this
     * GET request.
     */
    public function searchGuardians(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $term = '%'.$validated['q'].'%';
        $guardians = Guardian::query()
            ->where(fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term))
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $guardians->map(fn (Guardian $g) => [
                'id' => $g->id, 'firstName' => $g->first_name, 'middleName' => $g->middle_name,
                'lastName' => $g->last_name, 'status' => $g->status,
            ])->all(),
        ]);
    }

    /**
     * Exact-match same-School contact candidate lookup -- thin wrapper
     * over the unchanged GuardianContactService::findCandidatesBySchool()
     * (Phase 1A.3). Never decrypts every contact row, never crosses
     * Schools, never auto-merges.
     */
    public function candidateGuardians(Request $request, TenantContext $context, GuardianContactService $service): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ContactType::class)],
            'value' => ['required', 'string', 'max:255'],
        ]);

        try {
            $candidates = $service->findCandidatesBySchool($school, ContactType::from($validated['type']), $validated['value']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['value' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $candidates->map(fn (Guardian $g) => [
                'id' => $g->id, 'firstName' => $g->first_name, 'middleName' => $g->middle_name,
                'lastName' => $g->last_name, 'status' => $g->status,
            ])->all(),
        ]);
    }

    /**
     * Links an EXISTING Guardian to a Student ("find existing Guardian"
     * panel's submit action).
     */
    public function linkExisting(Request $request, TenantContext $context, StudentGuardianRelationshipService $service, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $studentModel = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'guardian_id' => ['required', 'uuid', Rule::exists('guardians', 'id')->where('school_id', $school->id)],
            ...$this->relationshipRules(),
        ]);

        $guardian = Guardian::query()->findOrFail($validated['guardian_id']);

        try {
            $service->link(
                $studentModel,
                $guardian,
                RelationshipType::from($validated['relationship_type']),
                $this->relationshipAttributes($validated),
                $context->actor(),
            );
        } catch (DuplicateRelationshipException) {
            throw ValidationException::withMessages([
                'guardian_id' => ['This Guardian is already linked to this Student.'],
            ]);
        } catch (CrossSchoolRelationshipException) {
            // Defense-in-depth only -- Rule::exists() above already
            // makes this unreachable via this form; see the class
            // docblock.
            throw ValidationException::withMessages([
                'guardian_id' => ['The selected guardian id is invalid.'],
            ]);
        }

        return redirect("/app/students/{$studentModel->id}");
    }

    /**
     * Creates a NEW Guardian identity and links it to the Student in
     * the same request -- orchestrates two already-atomic services
     * (GuardianService::create() then
     * StudentGuardianRelationshipService::link(), each with its own
     * transaction/audit) rather than duplicating either's logic. Never
     * collects contact information here (this checkpoint's brief: "do
     * not implement one gigantic nested Student+Guardian+Contact
     * payload") -- contact is a separate follow-up action from the
     * Guardian detail page.
     */
    public function linkNew(
        Request $request,
        TenantContext $context,
        GuardianService $guardianService,
        StudentGuardianRelationshipService $relationshipService,
        string $student
    ): RedirectResponse {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $studentModel = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            ...$this->relationshipRules(),
        ]);

        $guardian = $guardianService->create($school, collect($validated)->only(['first_name', 'middle_name', 'last_name'])->all(), $context->actor());

        // A brand-new Guardian can never already be linked to this
        // Student, so DuplicateRelationshipException is structurally
        // unreachable here -- nothing to catch.
        $relationshipService->link(
            $studentModel,
            $guardian,
            RelationshipType::from($validated['relationship_type']),
            $this->relationshipAttributes($validated),
            $context->actor(),
        );

        return redirect("/app/students/{$studentModel->id}");
    }

    public function update(Request $request, TenantContext $context, StudentGuardianRelationshipService $service, string $relationship): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $model = StudentGuardianRelationship::query()->findOrFail($relationship);

        $validated = $request->validate($this->relationshipRules());
        $attributes = $this->relationshipAttributes($validated);
        $attributes['relationship_type'] = RelationshipType::from($validated['relationship_type']);

        $service->update($model, $attributes, $context->actor());

        return redirect("/app/students/{$model->student_id}");
    }

    public function setPrimary(TenantContext $context, StudentGuardianRelationshipService $service, string $relationship): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $model = StudentGuardianRelationship::query()->findOrFail($relationship);

        try {
            $service->setPrimary($model, $context->actor());
        } catch (ConcurrentPrimaryGuardianConflictException) {
            return redirect("/app/students/{$model->student_id}")->withErrors([
                'primary' => 'Another primary Guardian change was made at the same time. Please try again.',
            ]);
        }

        return redirect("/app/students/{$model->student_id}");
    }

    public function destroy(TenantContext $context, StudentGuardianRelationshipService $service, string $relationship): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeBoth($school);

        $model = StudentGuardianRelationship::query()->findOrFail($relationship);
        $studentId = $model->student_id;
        $service->unlink($model, $context->actor());

        return redirect("/app/students/{$studentId}");
    }

    private function authorizeBoth(School $school): void
    {
        $this->authorizeCapability('students.manage', $school);
        $this->authorizeCapability('guardians.manage', $school);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function relationshipRules(): array
    {
        return [
            'relationship_type' => ['required', Rule::enum(RelationshipType::class)],
            'is_legal_guardian' => ['sometimes', 'boolean'],
            'is_emergency_contact' => ['sometimes', 'boolean'],
            'is_authorized_pickup' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, bool>
     */
    private function relationshipAttributes(array $validated): array
    {
        return collect($validated)->only(['is_legal_guardian', 'is_emergency_contact', 'is_authorized_pickup'])->all();
    }
}
