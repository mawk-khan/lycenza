<?php

namespace App\Domain\Identity\Application;

use App\Domain\Communications\Application\Channels\GuardianEmailAddressResolver;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\Exceptions\GuardianAlreadyHasAccountLinkException;
use App\Domain\Identity\Application\Exceptions\GuardianAlreadyHasPendingInvitationException;
use App\Domain\Identity\Application\Exceptions\GuardianHasNoEmailContactException;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Domain\Identity\Mail\GuardianAccountInvitationMail;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Phase 5D.3 -- Identity domain (root CLAUDE.md architectural
 * ownership: see this checkpoint's docs, "Identity owns account
 * provisioning"). The sole write path for issuing/resending/revoking a
 * Guardian account invitation. Deliberately does NOT create a User or
 * SchoolMembership at issue time -- see
 * App\Domain\Identity\Application\GuardianAccountActivationService's
 * docblock for why that is deferred entirely to acceptance (no
 * dangling accounts for invitations that are never accepted).
 *
 * Never depends on AnnouncementService/CommunicationDelivery/
 * CommunicationRecipient/ConversationParticipantAuthorizationService
 * (brief §56) -- the invitation email is sent directly via this
 * service using the application's default mail transport, entirely
 * outside the Communication Hub's delivery/audience/policy machinery
 * (brief §45: never recorded as a CommunicationAnnouncement/
 * CommunicationDelivery/CommunicationRecipient).
 */
class AccountInvitationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly GuardianEmailAddressResolver $emailResolver,
        private readonly AccountLinkService $accountLinks,
    ) {}

    /**
     * @throws GuardianHasNoEmailContactException
     * @throws GuardianAlreadyHasAccountLinkException
     * @throws GuardianAlreadyHasPendingInvitationException
     */
    public function invite(School $school, Guardian $guardian, User $actor): GuardianAccountInvitation
    {
        return $this->context->withSchool($school, function () use ($school, $guardian, $actor) {
            $email = $this->emailResolver->resolve($guardian);

            if ($email === null) {
                throw new GuardianHasNoEmailContactException;
            }

            if ($this->accountLinks->activeLinkForGuardian($guardian) !== null) {
                throw new GuardianAlreadyHasAccountLinkException;
            }

            if (GuardianAccountInvitation::query()->where('guardian_id', $guardian->id)->pending()->exists()) {
                throw new GuardianAlreadyHasPendingInvitationException;
            }

            try {
                return DB::transaction(fn () => $this->createAndSend($school, $guardian, $email, $actor));
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'giai_one_pending_per_guardian')) {
                    throw new GuardianAlreadyHasPendingInvitationException;
                }

                throw $e;
            }
        });
    }

    /**
     * Brief §18: reissuing revokes the current pending invitation and
     * creates a fresh one in the SAME transaction, so
     * `giai_one_pending_per_guardian` is never transiently violated
     * and no unlimited concurrent tokens can accumulate.
     *
     * @throws GuardianHasNoEmailContactException
     */
    public function resend(School $school, Guardian $guardian, User $actor): GuardianAccountInvitation
    {
        return $this->context->withSchool($school, function () use ($school, $guardian, $actor) {
            $email = $this->emailResolver->resolve($guardian);

            if ($email === null) {
                throw new GuardianHasNoEmailContactException;
            }

            return DB::transaction(function () use ($school, $guardian, $email, $actor) {
                $current = GuardianAccountInvitation::query()
                    ->where('guardian_id', $guardian->id)
                    ->pending()
                    ->lockForUpdate()
                    ->first();

                if ($current !== null) {
                    $current->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();

                    $this->audit->school($school, 'guardian.account_invitation_revoked', actor: $actor, subject: $current, metadata: [
                        'guardianId' => $guardian->id,
                        'reason' => 'reissued',
                    ]);
                }

                return $this->createAndSend($school, $guardian, $email, $actor);
            });
        });
    }

    public function revoke(School $school, Guardian $guardian, User $actor): void
    {
        $this->context->withSchool($school, function () use ($school, $guardian, $actor) {
            $invitation = GuardianAccountInvitation::query()
                ->where('guardian_id', $guardian->id)
                ->pending()
                ->first();

            if ($invitation === null) {
                return;
            }

            $invitation->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();

            $this->audit->school($school, 'guardian.account_invitation_revoked', actor: $actor, subject: $invitation, metadata: [
                'guardianId' => $guardian->id,
                'reason' => 'revoked',
            ]);
        });
    }

    /**
     * Read-model helper for the admin UI (brief §31) -- the current
     * pending invitation only, never the full history (which stays
     * internal/audit-only).
     */
    public function currentPendingInvitation(School $school, Guardian $guardian): ?GuardianAccountInvitation
    {
        return $this->context->withSchool($school, fn () => GuardianAccountInvitation::query()
            ->where('guardian_id', $guardian->id)
            ->pending()
            ->first());
    }

    private function createAndSend(School $school, Guardian $guardian, string $email, User $actor): GuardianAccountInvitation
    {
        $plaintextToken = Str::random(64);

        $invitation = GuardianAccountInvitation::query()->create([
            'school_id' => $school->id,
            'guardian_id' => $guardian->id,
            'token_hash' => hash('sha256', $plaintextToken),
            'destination_email_hash' => hash('sha256', $email),
            'status' => 'pending',
            'expires_at' => now()->addDays((int) config('identity.guardian_invitation_expiry_days')),
            'invited_by_user_id' => $actor->id,
        ]);

        $this->audit->school($school, 'guardian.account_invited', actor: $actor, subject: $invitation, metadata: [
            'guardianId' => $guardian->id,
        ]);

        Mail::to($email)->send(new GuardianAccountInvitationMail($school, $guardian, $invitation, $plaintextToken));

        return $invitation;
    }
}
