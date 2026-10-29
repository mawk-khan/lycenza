<?php

namespace App\Http\Controllers\App\Groups;

use App\Domain\Platform\Application\Groups\GroupGovernanceAccess;
use App\Domain\Platform\Application\Groups\GroupGovernanceScope;
use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Domain\Platform\Application\Groups\NetworkBrandChangeReason;
use App\Domain\Platform\Application\Groups\NetworkBrandService;
use App\Domain\Platform\Application\Groups\NetworkRoleChangeReason;
use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Domain\Platform\Application\Groups\SchoolGroupStructureService;
use App\Http\Controllers\Controller;
use App\Models\GroupRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.12E (ADR 0080 §10.4-§10.6, OD-12.13): the Network Manager's surface
 * for ONE Group, in Group context (never School context, never the platform
 * routes).
 *
 * - Governance (a whole-Group `group.network.manage` grant, or the platform):
 *   Regions and Zones, member-School classification (fresh MFA), placement,
 *   and removal (fresh MFA). Adding a School stays platform-only: it extends
 *   the Group's reach into another tenant (ADR 0045 §5).
 * - Network Access: the Group's Network roles, its read-only operators and
 *   the viewer's own effective governance access; a Network Manager grants
 *   and revokes `network_viewer` only (fresh MFA, reason), never a manager.
 * - Order on every change: the cached floor (a refused caller never spends a
 *   code), then the fresh MFA code where required, then the service, which
 *   re-reads authority inside its transaction and audits.
 * - A Group the actor has no Network authority in answers 404, like the Group
 *   page. Nothing here reads or grants a School capability.
 */
class NetworkGovernanceController extends Controller
{
    public function __construct(
        private readonly GroupGovernanceAccess $access,
        private readonly SchoolGroupGovernanceService $governance,
        private readonly SchoolGroupStructureService $structure,
        private readonly NetworkAccessService $network,
        private readonly SchoolGroupDirectoryReadService $directory,
        private readonly NetworkBrandService $brands,
    ) {}

    public function access(Request $request, SchoolGroup $schoolGroup, FreshMfaRequirement $mfa): Response
    {
        $user = $request->user();
        $scope = $this->visible($request, $schoolGroup);
        $manageOperators = $schoolGroup->isActive() && $this->access->mayManageOperators($user, $schoolGroup);
        $structure = $this->directory->structure($schoolGroup, $scope);

        return Inertia::render('App/Groups/Access', [
            'group' => ['id' => $schoolGroup->id, 'name' => $schoolGroup->name, 'status' => $schoolGroup->status],
            // The code-owned Group roles and what each may do -- labels, never authority.
            'roles' => Role::query()->where('scope', Role::SCOPE_GROUP)->where('is_system', true)->whereNull('retired_at')
                ->with('capabilities:key,label')->orderBy('name')->get()
                ->map(fn (Role $role): array => [
                    'key' => $role->key,
                    'name' => $role->name,
                    'capabilities' => $role->capabilities->pluck('label')->values()->all(),
                    'scopable' => $role->capabilities->pluck('key')->all() === [GroupGovernanceAccess::VIEW],
                    'grantsSchoolAccess' => false,
                ])->values(),
            // The viewer's own Group authority, Group context only.
            'own' => [
                'scope' => $scope->describe(),
                'roles' => GroupRoleAssignment::query()->active()->where('user_id', $user->id)->where('school_group_id', $schoolGroup->id)
                    ->with(['role:id,key,name', 'region:id,name', 'zone:id,name'])->get()
                    ->map(fn (GroupRoleAssignment $g): array => [
                        'roleName' => $g->role->name,
                        'scope' => NetworkAccessService::scopeLabel($g->region_id, $g->zone_id, $g->region?->name, $g->zone?->name),
                    ])->values(),
                'canManage' => $schoolGroup->isActive() && $this->access->mayManage($user, $schoolGroup),
            ],
            // Grant holders are Sensitive: only an operator manager sees them.
            'assignments' => $manageOperators ? $this->network->assignments($schoolGroup)->values() : null,
            'canManageOperators' => $manageOperators,
            'regions' => $structure['regions'],
            'zones' => $structure['zones'],
            'reasons' => NetworkRoleChangeReason::options(),
            'hasMfaFactor' => $manageOperators && $mfa->hasActiveFactor($user),
        ]);
    }

    public function grantOperator(Request $request, SchoolGroup $schoolGroup, FreshMfaRequirement $mfa): RedirectResponse
    {
        $this->visible($request, $schoolGroup);
        $this->network->assertMayAdminister($request->user(), $schoolGroup, NetworkAccessService::CLASS_OPERATOR);
        $data = $request->validate([
            'user' => ['required', 'string', 'max:255'],
            'region_id' => ['nullable', 'uuid'],
            'zone_id' => ['nullable', 'uuid'],
            'reason_code' => ['required', Rule::enum(NetworkRoleChangeReason::class)],
        ]);
        $proof = $mfa->requireForAction($request, $request->user());
        $this->network->grant($request->user(), $schoolGroup, $data['user'], NetworkAccessService::VIEWER,
            $data['region_id'] ?? null, $data['zone_id'] ?? null, NetworkRoleChangeReason::from($data['reason_code']), $proof);

        return back();
    }

    public function revokeOperator(Request $request, SchoolGroup $schoolGroup, GroupRoleAssignment $grant, FreshMfaRequirement $mfa): RedirectResponse
    {
        $this->visible($request, $schoolGroup);
        abort_unless($grant->school_group_id === $schoolGroup->id, 404);
        $this->network->assertMayAdminister($request->user(), $schoolGroup, NetworkAccessService::CLASS_OPERATOR);
        // Only a read-only operator grant, checked before a code is spent (the service checks again).
        abort_unless($this->network->classOfGrant($grant) === NetworkAccessService::CLASS_OPERATOR, 403);
        $data = $request->validate(['reason_code' => ['required', Rule::enum(NetworkRoleChangeReason::class)]]);
        $proof = $mfa->requireForAction($request, $request->user());
        $this->network->revoke($request->user(), $schoolGroup, $grant, NetworkRoleChangeReason::from($data['reason_code']), $proof);

        return back();
    }

    public function classify(Request $request, SchoolGroup $schoolGroup, School $school, FreshMfaRequirement $mfa): RedirectResponse
    {
        $this->floor($request, $schoolGroup);
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
        $this->floor($request, $schoolGroup);
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

    public function removeSchool(Request $request, SchoolGroup $schoolGroup, School $school, FreshMfaRequirement $mfa): RedirectResponse
    {
        $this->floor($request, $schoolGroup);
        $validated = $request->validate(['reason_code' => ['required', Rule::enum(SchoolGroupMembershipReason::class)]]);
        $proof = $mfa->requireForAction($request, $request->user());

        $this->governance->removeSchool($request->user(), $schoolGroup, $school, SchoolGroupMembershipReason::from($validated['reason_code']), $proof);

        return back();
    }

    public function storeRegion(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->floor($request, $schoolGroup);
        $this->structure->createRegion($request->user(), $schoolGroup, $request->only(['code', 'name', 'description', 'sort_order']));

        return back();
    }

    public function updateRegion(Request $request, SchoolGroup $schoolGroup, string $region): RedirectResponse
    {
        $this->floor($request, $schoolGroup);
        $this->structure->updateRegion($request->user(), $schoolGroup, $region, $request->only(['code', 'name', 'description', 'sort_order', 'status']));

        return back();
    }

    public function storeZone(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->floor($request, $schoolGroup);
        $this->structure->createZone($request->user(), $schoolGroup, $request->only(['code', 'name', 'description', 'sort_order', 'region_id']));

        return back();
    }

    public function updateZone(Request $request, SchoolGroup $schoolGroup, string $zone): RedirectResponse
    {
        $this->floor($request, $schoolGroup);
        $this->structure->updateZone($request->user(), $schoolGroup, $zone, $request->only(['code', 'name', 'description', 'sort_order', 'status', 'region_id']));

        return back();
    }

    /** ORG.12F (ADR 0080 §11.7): the Network brand -- floor, fresh MFA, then the service (reason, version, audit). */
    public function updateBrand(Request $request, SchoolGroup $schoolGroup, FreshMfaRequirement $mfa): RedirectResponse
    {
        $this->visible($request, $schoolGroup);
        $this->brands->assertMayManage($request->user(), $schoolGroup);
        $data = $request->validate([
            // The service's rules, checked before a fresh code is spent.
            'display_label' => ['nullable', 'string', 'max:60', 'not_regex:/[<>\p{Cc}\p{Cf}]/u'],
            'primary_accent' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'version' => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', Rule::enum(NetworkBrandChangeReason::class)],
        ]);
        $proof = $mfa->requireForAction($request, $request->user());
        $this->brands->update($request->user(), $schoolGroup, $data['display_label'] ?? null, $data['primary_accent'] ?? null,
            (int) $data['version'], NetworkBrandChangeReason::from($data['reason_code']), $proof);

        return back()->with('status', 'Network brand saved. Schools that chose it now show it.');
    }

    /** 404 unless the actor holds Network authority in this Group; returns the read scope. */
    private function visible(Request $request, SchoolGroup $schoolGroup): GroupGovernanceScope
    {
        $scope = $this->access->viewScope($request->user(), $schoolGroup);
        abort_if($scope === null, 404);

        return $scope;
    }

    /** The cached management floor -- before any MFA code is spent. */
    private function floor(Request $request, SchoolGroup $schoolGroup): void
    {
        $this->visible($request, $schoolGroup);
        $this->governance->requireMayManage($request->user(), $schoolGroup);
    }
}
