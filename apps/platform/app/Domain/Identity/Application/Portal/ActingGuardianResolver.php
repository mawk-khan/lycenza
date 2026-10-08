<?php

namespace App\Domain\Identity\Application\Portal;

use App\Domain\Guardians\Application\GuardianStudentScope;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

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
        if ($user->isDisabled() || ! $school->isActive()) {
            return null;
        }

        return $this->context->withSchool($school, function () use ($user, $school): ?ActingGuardian {
            $membership = SchoolMembership::query()
                ->where('user_id', $user->id)
                ->where('school_id', $school->id)
                ->active()
                ->first();

            if ($membership === null) {
                return null;
            }

            // At most one can exist (sgal_one_active_per_membership); anything
            // else is ambiguous and fails closed.
            $links = StudentGuardianAccountLink::query()
                ->where('school_id', $school->id)
                ->where('school_membership_id', $membership->id)
                ->whereNotNull('guardian_id')
                ->active()
                ->limit(2)
                ->get();

            if ($links->count() !== 1) {
                return null;
            }

            $link = $links->first();

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
