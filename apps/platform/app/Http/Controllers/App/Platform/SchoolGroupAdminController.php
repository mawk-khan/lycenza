<?php

namespace App\Http\Controllers\App\Platform;

use App\Domain\Platform\Application\Groups\GroupGovernanceScope;
use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Domain\Platform\Application\Groups\NetworkRoleChangeReason;
use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Domain\Platform\Application\Groups\SchoolGroupStructureService;
use App\Http\Controllers\Controller;
use App\Models\GroupRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0N.5 (ADR 0045 section 5): the platform governance surface for
 * School Groups -- separate from the Group Admin's own view. Reading needs
 * `platform.school_groups.view`; boundary changes
 * `platform.school_groups.manage`; grants `platform.school_group_grants.manage`
 * (the grant list itself is Sensitive and is shown only to that
 * capability). Every change goes through SchoolGroupGovernanceService,
 * which re-checks the capability and audits. Exact identifiers only: a
 * School by UUID or verified domain, a person by exact email or UUID.
 * Holding these capabilities never makes the operator a Group Admin.
 *
 * ORG.12E (ADR 0080 §10): the platform reads every Group whole, and also
 * appoints Network Managers and read-only Network Viewers (whole Group, one
 * Region or one Zone) through NetworkAccessService -- floor check, then a
 * fresh MFA code, then the service. Group Admin grants keep their ADR 0045
 * path unchanged.
 */
class SchoolGroupAdminController extends Controller
{
    public function __construct(
        private readonly SchoolGroupGovernanceService $governance,
        private readonly SchoolGroupStructureService $structure,
        private readonly NetworkAccessService $network,
    ) {}

    public function index(Request $request, CapabilityResolver $capabilities): Response
    {
        return Inertia::render('App/Platform/Groups/Index', [
            'groups' => SchoolGroup::query()->orderBy('name')->get(['id', 'name', 'slug', 'status'])
                ->map(fn (SchoolGroup $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug, 'status' => $g->status]),
            'canManage' => $capabilities->canPlatform($request->user(), SchoolGroupGovernanceService::MANAGE),
        ]);
    }

    public function show(Request $request, SchoolGroup $schoolGroup, CapabilityResolver $capabilities, SchoolGroupDirectoryReadService $directory, FreshMfaRequirement $mfa): Response
    {
        $user = $request->user();
        $canManageGrants = $capabilities->canPlatform($user, SchoolGroupGovernanceService::MANAGE_GRANTS);
        $filters = array_filter($request->only(['relationship', 'region_id', 'zone_id', 'status', 'q']), 'is_string');

        return Inertia::render('App/Platform/Groups/Show', [
            'group' => ['id' => $schoolGroup->id, 'name' => $schoolGroup->name, 'slug' => $schoolGroup->slug, 'status' => $schoolGroup->status],
            // ORG.12D (ADR 0080 §9): structure, classification and the directory
            // -- platform tables only (SchoolGroupDirectoryReadService).
            'overview' => $directory->overview($schoolGroup, GroupGovernanceScope::whole()),
            'filters' => $filters,
            'directory' => $directory->directory($schoolGroup, $filters, (int) $request->query('page', 1), GroupGovernanceScope::whole())
                ->withQueryString()
                ->through(fn (object $row): array => SchoolGroupDirectoryReadService::present($row)),
            'relationships' => SchoolGroupRelationship::options(),
            'reasons' => SchoolGroupMembershipReason::options(),
            'grantReasons' => NetworkRoleChangeReason::options(),
            'groupRoles' => [
                ['code' => 'group_admin', 'label' => 'Group Admin'],
                ['code' => NetworkAccessService::MANAGER, 'label' => 'Network Manager'],
                ['code' => NetworkAccessService::VIEWER, 'label' => 'Network Viewer (read-only)'],
            ],
            'grants' => $canManageGrants
                ? $schoolGroup->grants()->active()->with(['user:id,name,email', 'role:id,key,name', 'region:id,name', 'zone:id,name'])->orderBy('granted_at')->get()
                    ->map(fn (GroupRoleAssignment $g) => [
                        'id' => $g->id,
                        'userName' => $g->user->name,
                        'userEmail' => $g->user->email,
                        'role' => $g->role->key,
                        'roleName' => $g->role->name,
                        'scope' => NetworkAccessService::scopeLabel($g->region_id, $g->zone_id, $g->region?->name, $g->zone?->name),
                        'grantedAt' => $g->granted_at->toIso8601String(),
                    ])->values()
                : null,
            'canManage' => $capabilities->canPlatform($user, SchoolGroupGovernanceService::MANAGE),
            'canManageGrants' => $canManageGrants,
            'hasMfaFactor' => $mfa->hasActiveFactor($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = $this->governance->create($request->user(), (string) $request->input('name'), (string) $request->input('slug'));

        return redirect()->route('app.platform.groups.show', $group)->with('status', 'Group created.');
    }

    public function rename(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->governance->rename($request->user(), $schoolGroup, (string) $request->input('name'));

        return back();
    }

    public function archive(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->governance->archive($request->user(), $schoolGroup);

        return back();
    }

    /** ORG.12D (OD-12.12): capability, then a fresh MFA code, then the assured service call. */
    public function addSchool(Request $request, SchoolGroup $schoolGroup, FreshMfaRequirement $mfa): RedirectResponse
    {
        $this->governance->assertMayManage($request->user());
        $validated = $request->validate([
            'school' => ['required', 'string', 'max:255'],
            'relationship_type' => ['required', Rule::enum(SchoolGroupRelationship::class)],
            'reason_code' => ['required', Rule::enum(SchoolGroupMembershipReason::class)],
            'network_reference' => ['nullable', 'string', 'max:40'],
        ]);
        $proof = $mfa->requireForAction($request, $request->user());

        $this->governance->addSchool($request->user(), $schoolGroup, $validated['school'],
            SchoolGroupRelationship::from($validated['relationship_type']), SchoolGroupMembershipReason::from($validated['reason_code']), $proof, $validated['network_reference'] ?? null);

        return back();
    }

    public function removeSchool(Request $request, SchoolGroup $schoolGroup, School $school, FreshMfaRequirement $mfa): RedirectResponse
    {
        $this->governance->assertMayManage($request->user());
        $validated = $request->validate(['reason_code' => ['required', Rule::enum(SchoolGroupMembershipReason::class)]]);
        $proof = $mfa->requireForAction($request, $request->user());

        $this->governance->removeSchool($request->user(), $schoolGroup, $school, SchoolGroupMembershipReason::from($validated['reason_code']), $proof);

        return back();
    }

    public function classify(Request $request, SchoolGroup $schoolGroup, School $school, FreshMfaRequirement $mfa): RedirectResponse
    {
        $this->governance->assertMayManage($request->user());
        $validated = $request->validate([
            'relationship_type' => ['required', Rule::enum(SchoolGroupRelationship::class)],
            'network_reference' => ['nullable', 'string', 'max:40'],
            'expected_relationship_type' => ['present', 'nullable', 'string'],
            'expected_network_reference' => ['present', 'nullable', 'string'],
            'reason_code' => ['required', Rule::enum(SchoolGroupMembershipReason::class)],
        ]);
        $proof = $mfa->requireForAction($request, $request->user());

        $this->governance->classifyMembership($request->user(), $schoolGroup, $school,
            SchoolGroupRelationship::from($validated['relationship_type']), $validated['network_reference'] ?? null,
            ['relationship_type' => $validated['expected_relationship_type'] ?? null, 'network_reference' => $validated['expected_network_reference'] ?? null],
            SchoolGroupMembershipReason::from($validated['reason_code']), $proof);

        return back();
    }

    public function place(Request $request, SchoolGroup $schoolGroup, School $school): RedirectResponse
    {
        $validated = $request->validate([
            'region_id' => ['nullable', 'uuid'],
            'zone_id' => ['nullable', 'uuid'],
            'expected_region_id' => ['present', 'nullable', 'uuid'],
            'expected_zone_id' => ['present', 'nullable', 'uuid'],
            'reason_code' => ['required', Rule::enum(SchoolGroupMembershipReason::class)],
        ]);

        $this->governance->placeMembership($request->user(), $schoolGroup, $school, $validated['region_id'] ?? null, $validated['zone_id'] ?? null,
            ['region_id' => $validated['expected_region_id'] ?? null, 'zone_id' => $validated['expected_zone_id'] ?? null],
            SchoolGroupMembershipReason::from($validated['reason_code']));

        return back();
    }

    public function storeRegion(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->structure->createRegion($request->user(), $schoolGroup, $request->only(['code', 'name', 'description', 'sort_order']));

        return back();
    }

    public function updateRegion(Request $request, SchoolGroup $schoolGroup, string $region): RedirectResponse
    {
        $this->structure->updateRegion($request->user(), $schoolGroup, $region, $request->only(['code', 'name', 'description', 'sort_order', 'status']));

        return back();
    }

    public function storeZone(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->structure->createZone($request->user(), $schoolGroup, $request->only(['code', 'name', 'description', 'sort_order', 'region_id']));

        return back();
    }

    public function updateZone(Request $request, SchoolGroup $schoolGroup, string $zone): RedirectResponse
    {
        $this->structure->updateZone($request->user(), $schoolGroup, $zone, $request->only(['code', 'name', 'description', 'sort_order', 'status', 'region_id']));

        return back();
    }

    public function grant(Request $request, SchoolGroup $schoolGroup, FreshMfaRequirement $mfa): RedirectResponse
    {
        $role = (string) $request->input('role', 'group_admin');
        if ($role === 'group_admin') {
            $this->governance->grant($request->user(), $schoolGroup, (string) $request->input('user'));

            return back();
        }

        // ORG.12E: a Network role -- floor first, so a refused caller never spends a code.
        $networkRole = $this->network->networkRole($role);
        $this->network->assertMayAdminister($request->user(), $schoolGroup, $networkRole === null ? null : NetworkAccessService::classOf($networkRole));
        $data = $request->validate([
            'user' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(NetworkAccessService::ROLES)],
            'region_id' => ['nullable', 'uuid'],
            'zone_id' => ['nullable', 'uuid'],
            'reason_code' => ['required', Rule::enum(NetworkRoleChangeReason::class)],
        ]);
        $proof = $mfa->requireForAction($request, $request->user());
        $this->network->grant($request->user(), $schoolGroup, $data['user'], $data['role'], $data['region_id'] ?? null, $data['zone_id'] ?? null, NetworkRoleChangeReason::from($data['reason_code']), $proof);

        return back();
    }

    public function revoke(Request $request, SchoolGroup $schoolGroup, GroupRoleAssignment $grant, FreshMfaRequirement $mfa): RedirectResponse
    {
        abort_unless($grant->school_group_id === $schoolGroup->id, 404);

        $class = $this->network->classOfGrant($grant);
        if ($class === null) {
            $this->governance->revoke($request->user(), $grant);

            return back();
        }

        $this->network->assertMayAdminister($request->user(), $schoolGroup, $class);
        $data = $request->validate(['reason_code' => ['required', Rule::enum(NetworkRoleChangeReason::class)]]);
        $proof = $mfa->requireForAction($request, $request->user());
        $this->network->revoke($request->user(), $schoolGroup, $grant, NetworkRoleChangeReason::from($data['reason_code']), $proof);

        return back();
    }
}
