<?php

namespace App\Domain\Identity\Application;

use App\Domain\Guardians\Application\GuardianAccountOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ORG.8A-R1 (ADR 0076 §59): Identity's answer to Guardians'
 * `GuardianAccountOwnership` -- every Guardian the User's membership in this
 * School has ever been account-linked to (`student_guardian_account_links`,
 * active or revoked; links are never deleted). Read fresh, School-scoped.
 */
final class GuardianAccountOwnershipReader implements GuardianAccountOwnership
{
    public function __construct(private readonly TenantContext $context) {}

    public function guardianIdsOwnedBy(User $user, School $school): array
    {
        return $this->context->withSchool($school, fn (): array => DB::table('student_guardian_account_links as l')
            ->join('school_memberships as m', fn ($join) => $join->on('m.id', '=', 'l.school_membership_id')->on('m.school_id', '=', 'l.school_id'))
            ->where('l.school_id', $school->id)
            ->where('m.user_id', $user->id)
            ->whereNotNull('l.guardian_id')
            ->distinct()
            ->pluck('l.guardian_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all());
    }
}
