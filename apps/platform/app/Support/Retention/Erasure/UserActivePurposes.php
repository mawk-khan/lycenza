<?php

namespace App\Support\Retention\Erasure;

use App\Domain\HR\Application\Retention\EmployeeRetentionEligibility;
use App\Models\School;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * E21.4 (E21-L1 project-adopted, India-aligned development position): the
 * ONE read-only answer to "does this platform User still serve a current
 * purpose anywhere, or is anything it touches held?". The User is global,
 * so every School it ever belonged to is read, each in its own tenant
 * context (Employees, account links and automations are RLS-scoped); a
 * School's own erasure case never decides for another School.
 *
 * Current purposes (UserReferenceCatalog::ACTIVE_PURPOSE_BLOCKER), never
 * inferred from a last login or an archive flag:
 * - an invited or active School membership;
 * - a linked Employee who is current (any EmploymentRecord not terminal,
 *   or an unresolved separation), asked of HR
 *   (EmployeeRetentionEligibility::linksCurrentEmployee: only HR resolves an
 *   Employee from a User);
 * - an active Guardian/Student account link on one of its memberships;
 * - an unrevoked platform or Group role grant (root included);
 * - an active elevation;
 * - an enabled automation it owns.
 *
 * Holds: the platform hold, or a hold on any School it ever belonged to.
 * Also fail closed on any live foreign key to `users` that
 * UserReferenceCatalog does not classify.
 */
final class UserActivePurposes
{
    public function __construct(
        private readonly RetentionHolds $holds,
        private readonly TenantContext $context,
        private readonly EmployeeRetentionEligibility $employees,
    ) {}

    /** @return list<string> closed reason codes; empty means none */
    public function purposes(string $userId): array
    {
        $purposes = [];
        $memberships = DB::table('school_memberships')->where('user_id', $userId)->get(['id', 'school_id', 'status']);
        if ($memberships->contains(fn ($m) => in_array($m->status, ['invited', 'active'], true))) {
            $purposes[] = 'active_membership';
        }
        if (DB::table('platform_role_assignments')->where('user_id', $userId)->whereNull('revoked_at')->exists()) {
            $purposes[] = 'active_platform_role';
        }
        if (DB::table('group_role_assignments')->where('user_id', $userId)->whereNull('revoked_at')->exists()) {
            $purposes[] = 'active_group_role';
        }
        if (DB::table('school_elevations')->where('actor_user_id', $userId)->where('status', 'active')->exists()) {
            $purposes[] = 'active_elevation';
        }

        foreach (School::query()->whereIn('id', $memberships->pluck('school_id')->unique()->all())->orderBy('id')->get() as $school) {
            if ($this->employees->linksCurrentEmployee($school, $userId)) {
                $purposes[] = 'active_employee_link';
            }
            $ids = $memberships->where('school_id', $school->id)->pluck('id')->all();
            foreach ($this->context->withSchool($school, fn (): array => $this->schoolPurposes($userId, $ids)) as $purpose) {
                $purposes[] = $purpose;
            }
        }

        return array_values(array_unique($purposes));
    }

    /** @return list<string> 'platform_hold' and/or 'school_hold' */
    public function holds(string $userId): array
    {
        $schools = DB::table('school_memberships')->where('user_id', $userId)->distinct()->pluck('school_id')->all();

        return array_values(array_filter([
            $this->holds->platformHeld() ? 'platform_hold' : null,
            array_filter($schools, fn ($id) => $this->holds->isHeld((string) $id)) !== [] ? 'school_hold' : null,
        ]));
    }

    /** @return list<string> live foreign keys to `users` the catalog does not classify */
    public function unclassifiedReferences(): array
    {
        $live = array_map(fn ($r): string => "{$r->tbl}.{$r->col}", DB::select(
            "SELECT c.conrelid::regclass::text AS tbl, a.attname AS col FROM pg_constraint c
               JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
              WHERE c.contype = 'f' AND c.confrelid = 'public.users'::regclass",
        ));

        return UserReferenceCatalog::unclassified($live);
    }

    /**
     * @param  list<string>  $membershipIds
     * @return list<string>
     */
    private function schoolPurposes(string $userId, array $membershipIds): array
    {
        $purposes = [];
        if (DB::table('student_guardian_account_links')->whereIn('school_membership_id', $membershipIds)->where('status', 'active')->exists()) {
            $purposes[] = 'active_account_link';
        }
        if (DB::table('automation_rule_instances')->where('owner_user_id', $userId)->where('status', 'enabled')->exists()) {
            $purposes[] = 'active_automation_owner';
        }

        return $purposes;
    }
}
