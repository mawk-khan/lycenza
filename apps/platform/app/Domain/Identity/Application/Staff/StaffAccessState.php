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
