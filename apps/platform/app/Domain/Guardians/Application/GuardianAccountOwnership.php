<?php

namespace App\Domain\Guardians\Application;

use App\Models\School;
use App\Models\User;

/**
 * ORG.8A-R1 (ADR 0076 §59): which Guardian identities in a School belong to a
 * User -- every Guardian their membership has EVER been account-linked to
 * (active or revoked: a revoked link is history of that ownership, never an
 * error marker). A port Guardians declares and Identity implements (Identity
 * owns the account links; Guardians never depends on Identity), bound in
 * AppServiceProvider -- the Leave/StaffAttendance port precedent.
 */
interface GuardianAccountOwnership
{
    /** @return list<string> Guardian ids, same School only */
    public function guardianIdsOwnedBy(User $user, School $school): array;
}
