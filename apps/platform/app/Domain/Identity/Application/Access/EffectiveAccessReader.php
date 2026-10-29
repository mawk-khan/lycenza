<?php

namespace App\Domain\Identity\Application\Access;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12E (ADR 0080 §10.7): who holds which School access, and why --
 * derived, read-only, ONE School at a time.
 *
 * - The truth is the canonical resolver: whole-School capabilities are
 *   CapabilityResolver::schoolCapabilities() and Campus capabilities
 *   campusCapabilities() for the selected person in THIS School (module
 *   entitlements, disabled accounts, inactive memberships and inactive
 *   Campuses already applied there). Nothing is persisted or re-implemented
 *   as authority; this reader only explains the result from the person's
 *   active assignments (role template + whole School or one Campus).
 * - A person's access in another School is never read or merged (each
 *   School is computed separately); Group and platform authority are not
 *   School access and are never shown here.
 * - Privacy: name, the address the staff list already shows, membership
 *   status, whether the account is disabled -- never a password, MFA factor,
 *   token or HR record.
 */
final class EffectiveAccessReader
{
    public const PER_PAGE = 25;

    public const MAX_SEARCH = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolModuleEntitlements $entitlements,
    ) {}

    /**
     * Staff memberships of THIS School with their active assignments --
     * paginated, searchable by name or address.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function assignments(School $school, string $search, int $page): LengthAwarePaginator
    {
        $members = $this->context->withSchool($school, function () use ($school, $search, $page) {
            $query = SchoolMembership::query()
                ->where('school_memberships.school_id', $school->id)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('membership_role_assignments as a')
                    ->join('roles as r', 'r.id', '=', 'a.role_id')
                    ->whereColumn('a.school_membership_id', 'school_memberships.id')
                    ->where('r.scope', 'school'))
                ->join('users', 'users.id', '=', 'school_memberships.user_id')
                ->select('school_memberships.*');
            $search = trim(mb_substr($search, 0, self::MAX_SEARCH));
            if ($search !== '') {
                $pattern = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn ($q) => $q->where('users.name', 'ilike', $pattern)->orWhere('users.email', 'ilike', $pattern));
            }

            return $query->orderBy('users.name')->orderBy('school_memberships.id')
                ->with(['user', 'roleAssignments' => fn ($q) => $q->active()->staff()->with(['role:id,key,name', 'campus:id,name,status'])])
                ->paginate(self::PER_PAGE, ['*'], 'page', max($page, 1));
        });

        // Relations are already loaded: presenting reads nothing more.
        /** @var LengthAwarePaginator<int, array<string, mixed>> $presented */
        $presented = $members->through(fn (SchoolMembership $m): array => [
            'membershipId' => $m->id,
            'name' => $m->user?->name,
            'email' => $m->user?->publicEmail(),
            'membershipStatus' => $m->status,
            'accountDisabled' => (bool) $m->user?->isDisabled(),
            'assignments' => $m->roleAssignments->map(fn (MembershipRoleAssignment $a): array => $this->presentAssignment($a))
                ->sortBy(fn (array $a) => [$a['roleName'], $a['scope']])->values()->all(),
        ]);

        return $presented;
    }

    /** One staff member's effective access in THIS School, or null when the membership is not this School's staff. */
    public function forMembership(School $school, string $membershipId): ?array
    {
        $membership = $this->context->withSchool($school, fn () => SchoolMembership::query()
            ->where('school_id', $school->id)->whereKey($membershipId)
            // Staff only: a membership that has held a School role (never a Guardian's).
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('membership_role_assignments as a')
                ->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereColumn('a.school_membership_id', 'school_memberships.id')
                ->where('r.scope', 'school'))
            ->with(['user', 'roleAssignments' => fn ($q) => $q->active()->staff()->with(['role.capabilities:key,label', 'campus:id,name,status'])])
            ->first());
        if ($membership === null || $membership->user === null) {
            return null;
        }
        $user = $membership->user;
        $assignments = $membership->roleAssignments;

        // The canonical answers (module entitlements, disabled accounts, inactive memberships and Campuses applied).
        $whole = $this->capabilities->schoolCapabilities($user, $school);
        $campus = $this->capabilities->campusCapabilities($user, $school);
        $entitled = $this->entitlements->entitledModules($school);
        $labels = DB::table('capabilities')->where('namespace', 'school')->pluck('label', 'key')->all();

        $rows = [];
        foreach ($assignments as $assignment) {
            $source = $this->presentAssignment($assignment);
            foreach ($assignment->role->capabilities as $capability) {
                $key = $capability->key;
                $rows[$key] ??= ['key' => $key, 'label' => $labels[$key] ?? $key, 'sources' => [], 'reasons' => []];
                $rows[$key]['sources'][] = $source['roleName'].' — '.$source['scope'];
                // Shown only when the capability ends up not effective (the resolver decides).
                if ($assignment->campus_id !== null && ! CampusScopeCatalogue::isAdopted($key)) {
                    $rows[$key]['reasons'][] = 'campus_scope_not_adopted';
                }
                if ($assignment->campus_id !== null && $assignment->campus?->status !== 'active') {
                    $rows[$key]['reasons'][] = 'campus_inactive';
                }
            }
        }

        $campusNames = $assignments->filter(fn (MembershipRoleAssignment $a) => $a->campus !== null)->mapWithKeys(fn (MembershipRoleAssignment $a) => [$a->campus_id => $a->campus->name])->all();
        $modules = [];
        foreach ($rows as $key => $row) {
            $onCampuses = array_map(fn (string $id): string => $campusNames[$id] ?? $id, array_keys(array_filter($campus, fn (array $keys): bool => in_array($key, $keys, true))));
            $effective = in_array($key, $whole, true) ? 'whole_school' : ($onCampuses !== [] ? 'campus' : 'not_effective');
            $reasons = [];
            if ($effective === 'not_effective') {
                if ($user->isDisabled()) {
                    $reasons[] = 'account_disabled';
                }
                if (! $membership->isActive()) {
                    $reasons[] = 'membership_inactive';
                }
                $optional = array_filter(ModuleCatalogue::modulesFor($key), fn (string $m): bool => ! ModuleCatalogue::isCore($m));
                if (array_diff($optional, $entitled) !== []) {
                    $reasons[] = 'module_not_enabled';
                }
                $reasons = array_values(array_unique([...$reasons, ...$row['reasons']]));
            }
            foreach (ModuleCatalogue::modulesFor($key) ?: ['unmapped'] as $module) {
                $modules[$module][] = [
                    'key' => $key,
                    'label' => $row['label'],
                    'effective' => $effective,
                    'campuses' => $onCampuses,
                    'sources' => array_values(array_unique($row['sources'])),
                    'reasons' => $reasons,
                ];
            }
        }
        ksort($modules);
        $effectiveKeys = array_values(array_unique([...$whole, ...array_merge([], ...array_values($campus))]));

        return [
            'person' => [
                'membershipId' => $membership->id,
                'name' => $user->name,
                'email' => $user->publicEmail(),
                'membershipStatus' => $membership->status,
                'accountDisabled' => $user->isDisabled(),
            ],
            'assignments' => $assignments->map(fn (MembershipRoleAssignment $a): array => $this->presentAssignment($a))->values()->all(),
            'modules' => array_map(fn (string $module, array $caps): array => [
                'key' => $module,
                'label' => ModuleCatalogue::MODULES[$module]['label'] ?? 'Unmapped',
                'enabled' => ModuleCatalogue::isCore($module) || in_array($module, $entitled, true),
                'capabilities' => $caps,
            ], array_keys($modules), $modules),
            'summary' => [
                'wholeSchool' => count($whole),
                'campus' => count(array_diff($effectiveKeys, $whole)),
                'notEffective' => count(array_filter(array_merge([], ...array_values($modules)), fn (array $c): bool => $c['effective'] === 'not_effective')),
            ],
            'sensitiveFamilies' => SensitiveDataCatalogue::reachedBy($effectiveKeys),
        ];
    }

    /** @return array{roleKey: string, roleName: string, scope: string, campusId: ?string, effective: bool} */
    private function presentAssignment(MembershipRoleAssignment $a): array
    {
        return [
            'roleKey' => $a->role->key,
            'roleName' => $a->role->name,
            'scope' => $a->campus_id === null ? 'Whole School' : 'Campus: '.$a->campus->name,
            'campusId' => $a->campus_id,
            'effective' => $a->campus_id === null || $a->campus?->status === 'active',
        ];
    }
}
