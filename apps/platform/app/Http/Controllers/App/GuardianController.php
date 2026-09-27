<?php

namespace App\Http\Controllers\App;

use App\Domain\Communications\Application\Channels\GuardianEmailAddressResolver;
use App\Domain\Communications\Application\Policy\DomainCommunicationPreferenceReadModel;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Application\AccountLinkService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Email\EmailDeliveryPresenter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Phase 1A.6: session-authenticated Inertia pages for Guardian
 * identity and contact administration -- mirrors StudentController's
 * docblock exactly. Every mutation delegates to
 * App\Domain\Guardians\Application\{GuardianService,GuardianContactService}
 * (Phase 1A.4/1A.3), never rewritten here.
 */
class GuardianController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.view', $school);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $query = Guardian::query()->withCount('studentRelationships')->orderBy('first_name')->orderBy('last_name');

        if (isset($validated['name'])) {
            $term = '%'.$validated['name'].'%';
            $query->where(fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term));
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Guardians/Index', [
            'guardians' => $paginator->through(fn (Guardian $g) => [
                ...$this->presentSummary($g),
                'linkedStudentCount' => $g->student_relationships_count,
            ]),
            'filters' => [
                'name' => $validated['name'] ?? '',
                'status' => $validated['status'] ?? '',
            ],
            'canManage' => $capabilities->canInSchool($context->actor(), 'guardians.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        return Inertia::render('App/Guardians/Create');
    }

    public function store(Request $request, TenantContext $context, GuardianService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        $guardian = $service->create($school, $validated, $context->actor());

        return redirect("/app/guardians/{$guardian->id}");
    }

    public function show(
        TenantContext $context,
        CapabilityResolver $capabilities,
        AccountLinkService $accountLinks,
        DomainCommunicationPreferenceReadModel $preferenceReadModel,
        AccountInvitationService $invitations,
        GuardianEmailAddressResolver $emailResolver,
        string $guardian,
    ): Response {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.view', $school);

        $model = Guardian::query()->with(['contacts', 'studentRelationships.student'])->findOrFail($guardian);

        // Phase 5B.2: the optional, explicit School OS account link --
        // App\Domain\Identity\Application\AccountLinkService is the
        // sole source of truth; never inferred here.
        $link = $accountLinks->activeLinkForGuardian($model);

        // Phase 5D.2 §31/§37: EMAIL preference/consent/endpoint status
        // only -- never a decrypted contact value (the read model never
        // exposes one; see GuardianEmailAddressResolver's docblock).
        $emailState = $preferenceReadModel->forGuardianEmail($school, $model);
        $actor = $context->actor();

        // Phase 5D.3 §31: invitation/activation read-model for the
        // admin UI -- never exposes the token/hash/security data,
        // only lifecycle status.
        $pendingInvitation = $link === null ? $invitations->currentPendingInvitation($school, $model) : null;
        $canManageAccountInvitations = $capabilities->canInSchool($actor, 'guardians.manage', $school)
            && $capabilities->canInSchool($actor, 'school.members.manage', $school);

        return Inertia::render('App/Guardians/Show', [
            'communicationPreferences' => [
                'email' => [
                    'preferenceEnabled' => $emailState->preferenceEnabled,
                    'consentStatus' => $emailState->consentStatus?->value,
                    'endpointAvailable' => $emailState->endpointAvailable,
                ],
            ],
            'canManageCommunicationPreferences' => $capabilities->canInSchool($actor, 'communications.manage', $school)
                && $capabilities->canInSchool($actor, 'guardians.manage', $school),
            'accountLink' => $link === null ? null : [
                'schoolMembershipId' => $link->school_membership_id,
                'memberName' => $link->membership->user->name,
                'membershipActive' => $link->membership->isActive(),
            ],
            'accountInvitation' => [
                'canManage' => $canManageAccountInvitations,
                'hasEmailContact' => $emailResolver->resolve($model) !== null,
                'pending' => $pendingInvitation === null ? null : [
                    'status' => $pendingInvitation->effectiveStatus(),
                    'expiresAt' => $pendingInvitation->expires_at->toIso8601String(),
                    // Phase 0O.9A (ADR 0055): the invitation EMAIL's transport
                    // state -- closed codes only, never an address or a
                    // provider message.
                    'email' => EmailDeliveryPresenter::present($invitations->emailFor($school, $pendingInvitation)),
                ],
            ],
            'guardian' => $this->presentSummary($model),
            'contacts' => $model->contacts->map(fn (GuardianContact $c) => $this->presentContact($c))->all(),
            'students' => $model->studentRelationships->map(fn ($r) => [
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
            'canManage' => $capabilities->canInSchool($context->actor(), 'guardians.manage', $school),
        ]);
    }

    public function edit(TenantContext $context, string $guardian): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);

        return Inertia::render('App/Guardians/Edit', [
            'guardian' => $this->presentSummary($model),
        ]);
    }

    public function update(Request $request, TenantContext $context, GuardianService $service, string $guardian): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        $service->update($model, $validated, $context->actor());

        return redirect("/app/guardians/{$model->id}");
    }

    public function changeStatus(Request $request, TenantContext $context, GuardianService $service, string $guardian): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $service->changeStatus($model, $validated['status'], $context->actor());

        return redirect("/app/guardians/{$model->id}");
    }

    public function storeContact(Request $request, TenantContext $context, GuardianContactService $service, string $guardian): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ContactType::class)],
            'value' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $attributes = collect($validated)->only(['label', 'is_primary'])->all();

        try {
            $service->create($model, ContactType::from($validated['type']), $validated['value'], $attributes, $context->actor());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['value' => [$e->getMessage()]]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'value' => ['This contact conflicts with an existing record for this Guardian (a duplicate value, or an existing active primary contact of this type).'],
            ]);
        }

        return redirect("/app/guardians/{$model->id}");
    }

    public function setPrimaryContact(TenantContext $context, GuardianContactService $service, string $contact): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        $model = GuardianContact::query()->findOrFail($contact);
        $service->setPrimary($model, $context->actor());

        return redirect("/app/guardians/{$model->guardian_id}");
    }

    public function deactivateContact(TenantContext $context, GuardianContactService $service, string $contact): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('guardians.manage', $school);

        $model = GuardianContact::query()->findOrFail($contact);
        $service->deactivate($model, $context->actor());

        return redirect("/app/guardians/{$model->guardian_id}");
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
     * Approved contact fields only -- never encrypted_value/lookup_hash/
     * lookup_key_version, mirroring GuardianController@presentContact
     * in the Phase 1A.5 JSON API exactly.
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
