<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Application\Exceptions\ConversationParticipantNotLinkedException;
use App\Domain\Communications\Application\Exceptions\ConversationPolicyDisabledException;
use App\Domain\Communications\Application\Exceptions\ConversationTargetNotFoundException;
use App\Domain\Communications\Application\Exceptions\UnrelatedGuardianStudentConversationException;
use App\Domain\Communications\Application\Policy\CommunicationConversationPolicyService;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 5D.1 §18 -- the ONE centralized "may $actor start/join a
 * private conversation involving this Guardian/Student domain
 * identity" decision path. Never scattered across controllers (root
 * CLAUDE.md rule 3/24) -- App\Domain\Communications\Application\
 * CommunicationThreadService::createThread() is the only caller.
 *
 * A valid account link only proves authenticated REACHABILITY (brief
 * §5/§10) -- it is never, by itself, authorization to start a private
 * conversation. Every resolve*Participant() call enforces, in this
 * fixed order:
 *
 *   1. capability (communications.conversations.guardians/.students) --
 *      an AuthorizationException (403), exactly like every other
 *      communications.* capability gate in this module.
 *   2. School-level safeguarding policy
 *      (CommunicationConversationPolicyService) -- independent of the
 *      capability grant (brief §16).
 *   3. the target actually exists in this School (never trusts a
 *      client-supplied id, root CLAUDE.md rule 19/40).
 *   4. an active StudentGuardianAccountLink whose linked
 *      SchoolMembership is itself currently active.
 *
 * Returns the resolved, authenticated SchoolMembership endpoint (brief
 * §8) -- CommunicationThreadService is the only thing that ever turns
 * that into an actual CommunicationThreadParticipant row.
 */
class ConversationParticipantAuthorizationService
{
    public function __construct(
        private readonly CommunicationConversationPolicyService $policy,
        private readonly AccountLinkService $accountLinks,
        private readonly TenantContext $context,
    ) {}

    /**
     * Phase 5D.1: self-contained tenant scoping, like every other
     * Application-layer service in this module (e.g.
     * App\Domain\Identity\Application\AccountLinkService) -- never
     * assumes a caller has already activated a matching TenantContext,
     * since this may be called from a queued job, a test, or a request
     * whose ambient context does not yet match $school.
     */
    public function resolveGuardianParticipant(School $school, User $actor, string $guardianId): SchoolMembership
    {
        Gate::forUser($actor)->authorize('capability', ['communications.conversations.guardians', $school]);

        return $this->context->withSchool($school, function () use ($school, $guardianId) {
            if (! $this->policy->policyFor($school)->allowGuardianConversations) {
                throw new ConversationPolicyDisabledException;
            }

            $guardian = Guardian::query()->where('school_id', $school->id)->find($guardianId);

            if ($guardian === null) {
                throw new ConversationTargetNotFoundException;
            }

            $link = $this->accountLinks->activeLinkForGuardian($guardian);

            if ($link === null || ! $link->membership->isActive()) {
                throw new ConversationParticipantNotLinkedException;
            }

            return $link->membership;
        });
    }

    public function resolveStudentParticipant(School $school, User $actor, string $studentId): SchoolMembership
    {
        Gate::forUser($actor)->authorize('capability', ['communications.conversations.students', $school]);

        return $this->context->withSchool($school, function () use ($school, $studentId) {
            if (! $this->policy->policyFor($school)->allowStudentConversations) {
                throw new ConversationPolicyDisabledException;
            }

            $student = Student::query()->where('school_id', $school->id)->find($studentId);

            if ($student === null) {
                throw new ConversationTargetNotFoundException;
            }

            $link = $this->accountLinks->activeLinkForStudent($student);

            if ($link === null || ! $link->membership->isActive()) {
                throw new ConversationParticipantNotLinkedException;
            }

            return $link->membership;
        });
    }

    /**
     * Phase 5D.1 §22/§23/§46 -- when a thread would include both
     * Guardian and Student domain participants, every Guardian/Student
     * pair being composed together must share an eligible relationship
     * (same `is_primary OR is_legal_guardian` predicate
     * GuardianProjectionResolver already established -- see that
     * class's docblock). Called with the FULL candidate id lists for
     * one thread composition; a no-op if either side is empty.
     *
     * @param  array<int, string>  $guardianIds
     * @param  array<int, string>  $studentIds
     */
    public function assertGuardianStudentRelationshipsEligible(School $school, array $guardianIds, array $studentIds): void
    {
        if ($guardianIds === [] || $studentIds === []) {
            return;
        }

        $this->context->withSchool($school, function () use ($school, $guardianIds, $studentIds) {
            $eligiblePairs = DB::table('student_guardian_relationships')
                ->where('school_id', $school->id)
                ->whereIn('guardian_id', $guardianIds)
                ->whereIn('student_id', $studentIds)
                ->where(fn ($q) => $q->where('is_primary', true)->orWhere('is_legal_guardian', true))
                ->get(['guardian_id', 'student_id'])
                ->map(fn ($row) => "{$row->guardian_id}|{$row->student_id}")
                ->all();

            foreach ($guardianIds as $guardianId) {
                foreach ($studentIds as $studentId) {
                    if (! in_array("{$guardianId}|{$studentId}", $eligiblePairs, true)) {
                        throw new UnrelatedGuardianStudentConversationException;
                    }
                }
            }
        });
    }
}
