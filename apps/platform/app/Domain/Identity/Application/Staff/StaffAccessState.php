<?php

namespace App\Domain\Identity\Application\Staff;

use App\Domain\Identity\Application\SchoolAccessLock;
use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * HR.12: Identity's read-only answer to "does this account still hold staff
 * access in this School?" -- the number of unrevoked `school`-scope role
 * grants on its ACTIVE membership here (a Guardian grant is not staff
 * access). It suspends and revokes nothing: off-boarding stays
 * StaffAccessService::suspend() (rule 92). Any module may ask; Identity
 * depends on none of them.
 */
final class StaffAccessState
{
    public function activeSchoolGrantCount(School $school, string $userId): int
    {
        return DB::table('school_memberships as m')
            ->join('membership_role_assignments as a', 'a.school_membership_id', '=', 'm.id')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('m.school_id', $school->id)->where('m.user_id', $userId)->where('m.status', 'active')
            ->whereNull('a.revoked_at')->where('r.scope', 'school')
            ->count();
    }

    /**
     * HR.R1-C35 WO-20 (HRC-4): the same answer as a SET -- the ids of those
     * grants (`membership_role_assignments.id`), sorted as PostgreSQL sorts
     * uuids, so a caller can bind exactly what it saw. A grant row is never
     * deleted and a re-grant is a new row (rule 92), so an id names one grant
     * of one role for good. Empty means none (an unknown answer throws).
     *
     * @return list<string>
     */
    public function activeSchoolGrantIds(School $school, string $userId): array
    {
        return DB::table('school_memberships as m')
            ->join('membership_role_assignments as a', 'a.school_membership_id', '=', 'm.id')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('m.school_id', $school->id)->where('m.user_id', $userId)->where('m.status', 'active')
            ->whereNull('a.revoked_at')->where('r.scope', 'school')
            ->orderBy('a.id')->pluck('a.id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * HR.R1-C35 WO-20 (review M2, delta M-A): the live School grants of whoever
     * holds the given grants -- the ACTIVE memberships in this School those
     * grants belong to, limited to the accounts the caller names ($userIds:
     * HR's accounts still believed to be the person). So access kept for one
     * account is still checked after the Employee is unlinked or linked to
     * another, but never an account since corrected as someone else. Same
     * filters as activeSchoolGrantIds(); sorted.
     *
     * @param  list<string>  $grantIds
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function activeSchoolGrantIdsOfHolders(School $school, array $grantIds, array $userIds): array
    {
        if ($grantIds === [] || $userIds === []) {
            return [];
        }

        return DB::table('school_memberships as m')
            ->join('membership_role_assignments as a', 'a.school_membership_id', '=', 'm.id')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('m.school_id', $school->id)->where('m.status', 'active')->whereIn('m.user_id', $userIds)
            ->whereIn('m.id', DB::table('membership_role_assignments')->where('school_id', $school->id)->whereIn('id', $grantIds)->select('school_membership_id'))
            ->whereNull('a.revoked_at')->where('r.scope', 'school')
            ->orderBy('a.id')->pluck('a.id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * HR.R1-C35 WO-20: the roles of a bound grant set, for display -- each
     * grant's id, its role's immutable key and current name, the Campus it is
     * scoped to (null: the whole School), and whether it has since been
     * revoked. This School's grants only; an id of another School (or none) is
     * simply absent.
     *
     * @param  list<string>  $grantIds
     * @return list<array{grantId: string, roleKey: string, roleName: string, campus: ?string, revoked: bool}>
     */
    public function grantRoles(School $school, array $grantIds): array
    {
        if ($grantIds === []) {
            return [];
        }

        return DB::table('membership_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->leftJoin('campuses as c', fn ($j) => $j->on('c.id', '=', 'a.campus_id')->on('c.school_id', '=', 'a.school_id'))
            ->where('a.school_id', $school->id)->whereIn('a.id', $grantIds)
            ->orderBy('r.name')->orderBy('a.id')
            ->get(['a.id', 'r.key', 'r.name', 'c.name as campus', 'a.revoked_at'])
            ->map(fn ($row) => ['grantId' => (string) $row->id, 'roleKey' => (string) $row->key, 'roleName' => (string) $row->name,
                'campus' => $row->campus === null ? null : (string) $row->campus, 'revoked' => $row->revoked_at !== null])
            ->values()->all();
    }

    /**
     * HR.16B (R-HR15-1): for a caller that CERTIFIES this answer inside its
     * transaction (separation completion), hold the School's access lock --
     * the one every grant, revocation, reactivation, invitation acceptance
     * and off-boarding takes FIRST (SchoolAccessLock). A concurrent change
     * then either committed before (and is seen by the next read) or waits
     * until the certifying transaction commits; a brand-new grant on a
     * membership that held none is covered too, because the lock is the
     * School's, never a role row's. Call it BEFORE any other row lock.
     * It reads and changes nothing.
     */
    public function holdForCertification(School $school): void
    {
        SchoolAccessLock::hold($school->id);
    }
}
