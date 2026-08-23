<?php

namespace App\Domain\Guardians\Http\Controllers;

use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Phase 1A.5: the administrative HTTP boundary for Guardian identity.
 * Delegates every mutation to App\Domain\Guardians\Application\
 * GuardianService (Phase 1A.4) and every contact concern to
 * App\Domain\Guardians\Application\GuardianContactService (Phase 1A.3)
 * -- this controller never computes a lookup hash, encrypts/decrypts a
 * raw value itself, or duplicates either service's logic. See
 * StudentController's docblock for the shared authorization/validation
 * discipline this controller follows identically.
 */
class GuardianController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('guardians.view', $school);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Guardian::query()->orderBy('first_name')->orderBy('last_name');

        if (isset($validated['name'])) {
            $term = '%'.$validated['name'].'%';
            $query->where(fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term));
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Guardian $g) => $this->presentSummary($g))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(School $school, string $guardian): JsonResponse
    {
        $this->authorizeCapability('guardians.view', $school);

        $model = Guardian::query()->with(['contacts', 'studentRelationships.student'])->findOrFail($guardian);

        return response()->json(['data' => $this->presentDetail($model)]);
    }

    public function store(Request $request, School $school, GuardianService $service): JsonResponse
    {
        $this->authorizeCapability('guardians.manage', $school);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        $guardian = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->presentSummary($guardian)], 201);
    }

    public function update(Request $request, School $school, string $guardian, GuardianService $service): JsonResponse
    {
        $this->authorizeCapability('guardians.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $updated = $service->update($model, $validated, $request->user());

        return response()->json(['data' => $this->presentSummary($updated)]);
    }

    public function changeStatus(Request $request, School $school, string $guardian, GuardianService $service): JsonResponse
    {
        $this->authorizeCapability('guardians.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $updated = $service->changeStatus($model, $validated['status'], $request->user());

        return response()->json(['data' => $this->presentSummary($updated)]);
    }

    /**
     * Same-School exact-match candidate lookup (Phase 1A.3's
     * GuardianContactService::findCandidatesBySchool()) -- used by a
     * future Student-create UI to ask "does a Guardian with this email/
     * mobile already exist here." Never decrypts every contact row,
     * never crosses Schools, never auto-merges; returns only enough
     * Guardian identity to let staff pick an existing record (no
     * contact values, no relationship data).
     */
    public function candidates(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('guardians.view', $school);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ContactType::class)],
            'value' => ['required', 'string', 'max:255'],
        ]);

        try {
            $candidates = app(GuardianContactService::class)->findCandidatesBySchool(
                $school,
                ContactType::from($validated['type']),
                $validated['value'],
            );
        } catch (InvalidArgumentException $e) {
            // EmailNormalizer/PhoneNormalizer reject malformed input with
            // a plain InvalidArgumentException (Phase 1A.3) -- translated
            // here to the established validation response shape rather
            // than a raw 500 (this checkpoint's brief, section 24).
            throw ValidationException::withMessages(['value' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $candidates->map(fn (Guardian $g) => $this->presentSummary($g))->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(Guardian $guardian): array
    {
        return [
            'id' => $guardian->id,
            'firstName' => $guardian->first_name,
            'middleName' => $guardian->middle_name,
            'lastName' => $guardian->last_name,
            'status' => $guardian->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Guardian $guardian): array
    {
        return [
            ...$this->presentSummary($guardian),
            'contacts' => $guardian->contacts->map(fn (GuardianContact $c) => $this->presentContact($c))->all(),
            'students' => $guardian->studentRelationships->map(fn (StudentGuardianRelationship $r) => [
                'relationshipId' => $r->id,
                'student' => [
                    'id' => $r->student->id,
                    'studentNumber' => $r->student->student_number,
                    'firstName' => $r->student->first_name,
                    'lastName' => $r->student->last_name,
                ],
                'relationshipType' => $r->relationship_type->value,
                'isPrimary' => $r->is_primary,
                'isLegalGuardian' => $r->is_legal_guardian,
                'isEmergencyContact' => $r->is_emergency_contact,
                'isAuthorizedPickup' => $r->is_authorized_pickup,
            ])->all(),
        ];
    }

    /**
     * Approved contact fields only (this checkpoint's brief, section
     * 16) -- NEVER `$contact->toArray()`. `encrypted_value` decrypts
     * in-memory via the Eloquent `encrypted` cast on direct property
     * access (unaffected by the model's own `$hidden`, which only
     * suppresses `toArray()`/`toJson()`); `lookup_hash`/
     * `lookup_key_version` are never referenced here at all.
     *
     * @return array<string, mixed>
     */
    private function presentContact(GuardianContact $contact): array
    {
        return [
            'id' => $contact->id,
            'type' => $contact->type->value,
            'value' => $contact->encrypted_value,
            'label' => $contact->label,
            'isPrimary' => $contact->is_primary,
            'isActive' => $contact->is_active,
            'verifiedAt' => $contact->verified_at?->toIso8601String(),
        ];
    }
}
