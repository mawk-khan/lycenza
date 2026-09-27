<?php

namespace App\Domain\Identity\Application;

use App\Domain\Communications\Application\Channels\GuardianEmailAddressResolver;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\Exceptions\GuardianAlreadyHasAccountLinkException;
use App\Domain\Identity\Application\Exceptions\GuardianAlreadyHasPendingInvitationException;
use App\Domain\Identity\Application\Exceptions\GuardianHasNoEmailContactException;
use App\Domain\Identity\Application\Exceptions\InvitationSendRateLimitedException;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Models\EmailMessage;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Domains\CanonicalOrigin;
use App\Support\Email\EmailPurpose;
use App\Support\Email\OutboundEmailGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
 * (brief §56) -- the invitation email is entirely outside the
 * Communication Hub's delivery/audience/policy machinery (brief §45:
 * never recorded as a CommunicationAnnouncement/CommunicationDelivery/
 * CommunicationRecipient).
 *
 * Phase 0O.9A (ADR 0055 section 9.5): the email is an OUTBOX row. The
 * invitation, its audit record and a sealed `account_invitation` email
 * message are written in ONE transaction; the provider is contacted only
 * after commit, by the submission job. A provider outage therefore never
 * fails or rolls back the invitation, a rolled-back invitation leaves no
 * email, and the admin sees the email's delivery state ("queued" is never
 * "delivered"). Sending and resending are limited to 10/min per admin and
 * 200/day per School (GuardianInvitationSendLimiter).
 */
class AccountInvitationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly GuardianEmailAddressResolver $emailResolver,
        private readonly AccountLinkService $accountLinks,
        private readonly OutboundEmailGateway $email,
        private readonly GuardianInvitationSendLimiter $limiter,
        private readonly CanonicalOrigin $origins,
    ) {}

    /**
     * @throws GuardianHasNoEmailContactException
     * @throws GuardianAlreadyHasAccountLinkException
     * @throws GuardianAlreadyHasPendingInvitationException
     * @throws InvitationSendRateLimitedException
     */
    public function invite(School $school, Guardian $guardian, User $actor): GuardianAccountInvitation
    {
        $this->limiter->hit($school->id, $actor->id);

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
                return DB::transaction(fn () => $this->createAndQueue($school, $guardian, $email, $actor));
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
     * @throws InvitationSendRateLimitedException
     */
    public function resend(School $school, Guardian $guardian, User $actor): GuardianAccountInvitation
    {
        $this->limiter->hit($school->id, $actor->id);

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
                    // Its unsent email goes with it; provider-accepted mail keeps its evidence.
                    $this->email->cancelForSource(GuardianInvitationEmailSource::SOURCE_TYPE, $current->id, 'source_reissued');

                    $this->audit->school($school, 'guardian.account_invitation_revoked', actor: $actor, subject: $current, metadata: [
                        'guardianId' => $guardian->id,
                        'reason' => 'reissued',
                    ]);
                }

                return $this->createAndQueue($school, $guardian, $email, $actor);
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

            DB::transaction(function () use ($school, $guardian, $actor, $invitation): void {
                $invitation->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();
                $this->email->cancelForSource(GuardianInvitationEmailSource::SOURCE_TYPE, $invitation->id, 'source_revoked');

                $this->audit->school($school, 'guardian.account_invitation_revoked', actor: $actor, subject: $invitation, metadata: [
                    'guardianId' => $guardian->id,
                    'reason' => 'revoked',
                ]);
            });
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

    /**
     * Phase 0O.9A: the invitation's email message (its transport state), for
     * the admin UI. Null when there is none.
     */
    public function emailFor(School $school, GuardianAccountInvitation $invitation): ?EmailMessage
    {
        return $this->context->withSchool($school, fn () => $this->email->forSource(GuardianInvitationEmailSource::SOURCE_TYPE, $invitation->id));
    }

    private function createAndQueue(School $school, Guardian $guardian, string $email, User $actor): GuardianAccountInvitation
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

        // ADR 0054 section 8.9: the School's stored canonical origin -- never
        // the request Host. The plaintext token exists only in this sealed
        // (encrypted) content, which is purged once submitted, and at the
        // invitation's own expiry at the latest.
        $view = [
            'schoolName' => $school->name,
            'guardianFirstName' => $guardian->first_name,
            'acceptanceUrl' => $this->origins->schoolUrl($school, "invitations/{$school->id}/{$plaintextToken}"),
            'expiresAt' => $invitation->expires_at,
        ];

        $this->email->queue(
            school: $school,
            purpose: EmailPurpose::AccountInvitation,
            sourceId: $invitation->id,
            recipient: $email,
            subject: "You're invited to {$school->name} on School OS",
            text: view('emails.identity.guardian-account-invitation', $view)->render(),
            html: view('emails.identity.guardian-account-invitation-html', $view)->render(),
            expiresAt: $invitation->expires_at,
        );

        return $invitation;
    }
}
