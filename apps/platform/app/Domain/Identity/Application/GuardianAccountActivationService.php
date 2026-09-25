<?php

namespace App\Domain\Identity\Application;

use App\Domain\Communications\Application\Channels\GuardianEmailAddressResolver;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\Exceptions\ExistingAccountConfirmationRequiredException;
use App\Domain\Identity\Application\Exceptions\InvitationNotUsableException;
use App\Domain\Identity\Application\Exceptions\PersonaAlreadyLinkedException;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 5D.3 -- Identity domain. The sole write path that turns a
 * usable GuardianAccountInvitation into a real User + SchoolMembership
 * + AccountLink (brief §24: one atomic transaction).
 *
 * Deliberately creates NEITHER a User nor a SchoolMembership at
 * invitation-issue time (App\Domain\Identity\Application\AccountInvitationService
 * never does either) -- both are created here, for the first time,
 * only once a real person has proven control of the invited mailbox
 * (new-user branch) or is already authenticated as the matching
 * existing account (existing-user branch). An invitation that is
 * never accepted therefore never leaves behind an orphaned account.
 *
 * `resolveUsableInvitation()` and `accept()` BOTH independently
 * re-verify token usability and destination-email match (brief §29) --
 * the former for the public GET render (read-only), the latter again,
 * under a row lock, inside the actual write transaction, closing the
 * TOCTOU window between "show the form" and "submit the form".
 */
class GuardianAccountActivationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly GuardianEmailAddressResolver $emailResolver,
        private readonly AccountLinkService $accountLinks,
    ) {}

    public function resolveUsableInvitation(School $school, string $plaintextToken): ?GuardianAccountInvitation
    {
        // Phase 0N.9 (ADR 0047 section 8): an invitation into a School that
        // is not active is simply unusable -- the same non-disclosing
        // "no longer valid" answer, the invitation itself left intact.
        if (! $school->isActive()) {
            return null;
        }

        return $this->context->withSchool($school, function () use ($school, $plaintextToken) {
            $invitation = GuardianAccountInvitation::query()
                ->where('school_id', $school->id)
                ->where('token_hash', hash('sha256', $plaintextToken))
                ->first();

            if ($invitation === null || $invitation->guardian_id === null) {
                return null;
            }

            return $this->isCurrentlyUsable($invitation) ? $invitation : null;
        });
    }

    /**
     * Read-only convenience for the acceptance page: the invitation's
     * current destination email, and whether a User already exists
     * for it (brief §11-13's "existing vs new" branch, decided before
     * any write). Safe to expose to the token-holder -- it is their
     * own invited address.
     *
     * @return array{email: string, accountAlreadyExists: bool}
     */
    public function describeForAcceptance(School $school, GuardianAccountInvitation $invitation): array
    {
        return $this->context->withSchool($school, function () use ($invitation) {
            $email = $this->emailResolver->resolve($invitation->guardian);

            return [
                'email' => $email,
                'accountAlreadyExists' => $email !== null && User::query()->where('email', $email)->exists(),
            ];
        });
    }

    /**
     * @throws InvitationNotUsableException
     * @throws ExistingAccountConfirmationRequiredException
     */
    public function accept(School $school, GuardianAccountInvitation $invitation, ?User $authenticatedUser, ?string $newPassword): StudentGuardianAccountLink
    {
        return $this->context->withSchool($school, function () use ($school, $invitation, $authenticatedUser, $newPassword) {
            return DB::transaction(function () use ($school, $invitation, $authenticatedUser, $newPassword) {
                // Phase 0N.9: re-checked at acceptance, FOR SHARE, so no
                // account, membership or link is created once a suspension
                // has committed.
                if (! app(SchoolOperationalGuard::class)->holdOperational($school->id)) {
                    throw new InvitationNotUsableException;
                }

                $fresh = GuardianAccountInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

                if ($fresh === null || ! $this->isCurrentlyUsable($fresh)) {
                    throw new InvitationNotUsableException;
                }

                $guardian = $fresh->guardian;
                $email = $this->emailResolver->resolve($guardian);

                if ($email === null || hash('sha256', $email) !== $fresh->destination_email_hash) {
                    throw new InvitationNotUsableException;
                }

                $user = User::query()->where('email', $email)->first();
                $isNewUser = $user === null;

                if ($isNewUser) {
                    $user = User::query()->create([
                        'name' => trim("{$guardian->first_name} {$guardian->last_name}"),
                        'email' => $email,
                        'password' => Hash::make($newPassword),
                    ]);
                    // email_verified_at is deliberately NOT in User's
                    // fillable attribute list (mass-assignment
                    // protection) -- set it explicitly rather than
                    // widening that list just for this one caller.
                    $user->forceFill(['email_verified_at' => now()])->save();
                } elseif ($authenticatedUser === null || $authenticatedUser->id !== $user->id) {
                    throw new ExistingAccountConfirmationRequiredException;
                }

                $membership = SchoolMembership::query()
                    ->where('user_id', $user->id)
                    ->where('school_id', $school->id)
                    ->first();

                if ($membership === null) {
                    $membership = SchoolMembership::query()->create([
                        'user_id' => $user->id,
                        'school_id' => $school->id,
                        'status' => 'active',
                        'invited_at' => $fresh->created_at,
                        'joined_at' => now(),
                    ]);
                }

                $link = $this->linkOrReuse($school, $guardian, $membership, $user);

                $fresh->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();

                $this->audit->school(
                    $school,
                    $isNewUser ? 'guardian.account_activated' : 'guardian.account_linked_existing',
                    actor: $user,
                    subject: $fresh,
                    metadata: [
                        'guardianId' => $guardian->id,
                        'schoolMembershipId' => $membership->id,
                    ],
                );

                return $link;
            });
        });
    }

    private function isCurrentlyUsable(GuardianAccountInvitation $invitation): bool
    {
        return $invitation->isUsable();
    }

    /**
     * Brief's "duplicate activation" case: if the Guardian is somehow
     * already linked (e.g. an admin linked an existing account
     * manually while this invitation was still pending -- brief §34's
     * separate "link existing account" UI is never disabled by an
     * outstanding invitation), reuse the existing link idempotently
     * rather than raising an error the end user cannot act on.
     */
    private function linkOrReuse(School $school, Guardian $guardian, SchoolMembership $membership, User $actor): StudentGuardianAccountLink
    {
        try {
            return $this->accountLinks->linkGuardian($school, $guardian, $membership, $actor);
        } catch (PersonaAlreadyLinkedException) {
            return $this->accountLinks->activeLinkForGuardian($guardian);
        }
    }
}
