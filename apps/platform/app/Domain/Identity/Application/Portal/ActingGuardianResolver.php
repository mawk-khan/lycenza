<?php

namespace App\Domain\Identity\Application\Portal;

use App\Domain\Guardians\Application\GuardianStudentScope;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * POR (ADR 0070 §5): the Identity-owned resolver of ActingGuardian, the
 * Guardian counterpart of HR's ActingEmployeeResolver.
 *
 *   authenticated, enabled User
 *   -> its ACTIVE SchoolMembership in this (active) School
 *   -> exactly one ACTIVE Guardian account link on that membership
 *   -> an ACTIVE Guardian persona
 *   -> at least one eligible Guardian<->Student relationship
 *      (GuardianStudentScope: is_legal_guardian, active Student -- an
 *      engineering fail-closed default pending POR-L1, not a legal
 *      conclusion).
 *
 * Every part is read from current rows on every call; nothing is cached, so
 * revoking a link, ending the last relationship, deactivating the persona or
 * suspending the membership denies the very next request. Any missing or
 * ambiguous part answers null (fail closed). The link proves identity only:
 * it never authorizes a Student by itself.
 */
final class ActingGuardianResolver
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GuardianStudentScope $scope,
    ) {}

    public function resolve(User $user, School $school): ?ActingGuardian
    {
        return $this->resolveFrom($user, $school, lock: false);
    }

    /**
     * POR.4 (ADR 0070 §27.6): resolve for a WRITE. The Guardian's active
     * account link and then its membership are held FOR SHARE until the
     * caller's transaction ends -- link first, membership second, the order
     * Guardian unlink and off-boarding take them FOR UPDATE (after the School
     * access lock, which this never takes). So an unlink, off-boarding or
     * membership suspension either committed before this answer (and it is
     * null) or waits for the caller to commit. Must run inside a transaction.
     */
    public function resolveLocked(User $user, School $school): ?ActingGuardian
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ActingGuardianResolver::resolveLocked() must run inside a transaction.');
        }

        return $this->resolveFrom($user, $school, lock: true);
    }

    private function resolveFrom(User $user, School $school, bool $lock): ?ActingGuardian
    {
        if ($user->isDisabled() || ! $school->isActive()) {
            return null;
        }

        return $this->context->withSchool($school, function () use ($user, $school, $lock): ?ActingGuardian {
            $memberships = SchoolMembership::query()
                ->select('id')
                ->where('user_id', $user->id)
                ->where('school_id', $school->id)
                ->active();

            // At most one can exist (sgal_one_active_per_membership); anything
            // else is ambiguous and fails closed.
            $links = StudentGuardianAccountLink::query()
                ->where('school_id', $school->id)
                ->whereIn('school_membership_id', $memberships)
                ->whereNotNull('guardian_id')
                ->active()
                ->when($lock, fn ($q) => $q->sharedLock())
                ->limit(2)
                ->get();

            if ($links->count() !== 1) {
                return null;
            }

            $link = $links->first();

            $membership = SchoolMembership::query()
                ->whereKey($link->school_membership_id)
                ->where('user_id', $user->id)
                ->where('school_id', $school->id)
                ->active()
                ->when($lock, fn ($q) => $q->sharedLock())
                ->first();

            if ($membership === null) {
                return null;
            }

            if (! $this->scope->isActiveGuardian($school, $link->guardian_id) || ! $this->scope->hasEligibleStudent($school, $link->guardian_id)) {
                return null;
            }

            return new ActingGuardian($school->id, $user->id, $membership->id, $link->id, $link->guardian_id);
        });
    }

    /** @throws GuardianPortalAccessDeniedException */
    public function require(User $user, School $school): ActingGuardian
    {
        return $this->resolve($user, $school) ?? throw new GuardianPortalAccessDeniedException;
    }
}
