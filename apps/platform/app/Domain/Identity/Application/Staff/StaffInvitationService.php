<?php

namespace App\Domain\Identity\Application\Staff;

use App\Domain\Identity\Application\SchoolAccessLock;
use App\Domain\Identity\Infrastructure\StaffAccountInvitation;
use App\Domain\Identity\Infrastructure\StaffAccountInvitationRole;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Domains\CanonicalOrigin;
use App\Support\Email\EmailPurpose;
use App\Support\Email\OutboundEmailGateway;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Email\SenderIdentity;
use App\Support\Privacy\EmailNormalizer;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 0O.12B (ADR 0059 section 6): flow B -- a School invites staff into
 * ITS OWN School. Issue, resend and revoke.
 *
 * Authorization (re-checked here; the controller also demands a fresh MFA
 * code): issue needs `school.members.manage` AND `school.roles.manage`;
 * resend and revoke need `school.members.manage`. Roles come from the closed
 * School-scope catalog and only within the issuer's own capabilities
 * (StaffRoleCatalog). Never elevation or Group authority (those never reach
 * School capabilities).
 *
 * Issuing is refused while critical email is unavailable (`email_unavailable`)
 * -- an invitation nobody can receive is never created. The invitation, its
 * roles, its email (outbox, ADR 0055) and its audit record commit together,
 * under SchoolOperationalGuard.
 *
 * SR.2 (ADR 0071 §10.1, §14): issue, resend and revoke run under the School
 * access lock (SchoolAccessLock, taken FIRST, like every other staff-access
 * mutation), and the issuer's authority over every invited role is decided
 * INSIDE that transaction (RoleGrantAuthority: held or covered by a held
 * grant right; the role active and non-empty) -- never on a stale
 * pre-transaction read. A resend re-issues the invitation under the
 * RESENDER's authority (they become its issuer of record, whose current
 * authority acceptance re-validates), so a resend can never carry role
 * intent the resender could not grant. A refused decision is audited once
 * (`school.membership.role_grant_refused`) after the rollback.
 *
 * Anti-enumeration (ADR 0059 section 15): the School learns only its OWN
 * facts (the address is already its member, or already has a pending
 * invitation). Every other identity state -- unknown, another School's
 * member, a Guardian elsewhere, a Group user, a disabled account, a platform
 * operator -- gets the same accepted result; the recipient simply cannot
 * accept if ineligible.
 */
final class StaffInvitationService
{
    public const LIFETIME_DAYS = 7;

    public const INVITED = 'staff.account_invited';

    public const REVOKED = 'staff.account_invitation_revoked';

    public function __construct(
        private readonly StaffRoleCatalog $roles,
        private readonly StaffInvitationSendLimiter $limiter,
        private readonly EmailProviderResolver $emailProviders,
        private readonly OutboundEmailGateway $email,
        private readonly SenderIdentity $sender,
        private readonly CanonicalOrigin $origins,
        private readonly SchoolOperationalGuard $guard,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly RoleGrantAuthority $authority,
        private readonly RoleGrantRefusalAudit $refusals,
    ) {}

    /**
     * @param  list<string>  $roleKeys
     *
     * @throws StaffAccountException
     */
    public function issue(School $school, User $actor, string $email, array $roleKeys): StaffAccountInvitation
    {
        $this->roles->requireIssuer($actor, $school);
        $email = EmailNormalizer::canonical($email);

        if (Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:254']])->fails()) {
            throw new StaffAccountException('invalid_email');
        }

        $roles = $this->roles->resolve($roleKeys);

        if (! $this->emailProviders->criticalEmailAvailable()) {
            throw new StaffAccountException('email_unavailable');
        }

        $this->limiter->hit($school->id, $actor->id);

        try {
            return DB::transaction(function () use ($school, $actor, $email, $roles): StaffAccountInvitation {
                $this->lockAndRequireOperational($school);

                return $this->context->withSchool($school, function () use ($school, $actor, $email, $roles): StaffAccountInvitation {
                    $this->authorizeRoles($school, $actor, $roles, 'invitation');
                    $this->roles->requireIssuer($actor, $school);

                    if ($this->isOwnMember($school, $email)) {
                        throw new StaffAccountException('already_member');
                    }

                    $pending = StaffAccountInvitation::query()
                        ->where('school_id', $school->id)
                        ->where('destination_email', $email)
                        ->where('status', StaffAccountInvitation::STATUS_PENDING)
                        ->lockForUpdate()
                        ->first();

                    if ($pending !== null && ! $pending->isExpired()) {
                        throw new StaffAccountException('already_invited');
                    }

                    if ($pending !== null) {
                        // An expired pending invitation is replaced, not left blocking.
                        $this->end($school, $actor, $pending, 'reissued');
                    }

                    return $this->create($school, $actor, $email, $roles);
                });
            });
        } catch (UniqueConstraintViolationException) {
            throw new StaffAccountException('already_invited');
        } catch (StaffAccountException $e) {
            $this->refusals->recordAfterRollback($school, $actor, $e);

            throw $e;
        }
    }

    /**
     * Ends the pending invitation (`reissued`) and issues a fresh one with the
     * same address and roles, in ONE transaction under the row lock -- never
     * two usable invitations.
     *
     * @throws StaffAccountException
     */
    public function resend(School $school, User $actor, string $invitationId): StaffAccountInvitation
    {
        $this->roles->requireMemberManager($actor, $school);

        if (! $this->emailProviders->criticalEmailAvailable()) {
            throw new StaffAccountException('email_unavailable');
        }

        $this->limiter->hit($school->id, $actor->id);

        try {
            return DB::transaction(function () use ($school, $actor, $invitationId): StaffAccountInvitation {
                $this->lockAndRequireOperational($school);

                return $this->context->withSchool($school, function () use ($school, $actor, $invitationId): StaffAccountInvitation {
                    $invitation = $this->lockPending($school, $invitationId);
                    $roles = $invitation->roles()->with('role')->get()->map(fn (StaffAccountInvitationRole $r) => $r->role)->sortBy('key')->values()->all();
                    // SR.2: the resender becomes the issuer of record -- only roles they could grant now.
                    $this->authorizeRoles($school, $actor, $roles, 'invitation_resend', $invitation->id);
                    $this->end($school, $actor, $invitation, 'reissued');

                    return $this->create($school, $actor, $invitation->destination_email, $roles);
                });
            });
        } catch (StaffAccountException $e) {
            $this->refusals->recordAfterRollback($school, $actor, $e);

            throw $e;
        }
    }

    /**
     * @throws StaffAccountException
     */
    public function revoke(School $school, User $actor, string $invitationId): void
    {
        $this->roles->requireMemberManager($actor, $school);

        DB::transaction(function () use ($school, $actor, $invitationId): void {
            $this->lockAndRequireOperational($school);

            $this->context->withSchool($school, function () use ($school, $actor, $invitationId): void {
                $this->end($school, $actor, $this->lockPending($school, $invitationId), 'revoked');
            });
        });
    }

    /** SR.2 (ADR 0071 §14): the School access lock FIRST, then the School FOR SHARE. */
    private function lockAndRequireOperational(School $school): void
    {
        SchoolAccessLock::hold($school->id);

        if (! $this->guard->holdOperational($school->id)) {
            throw new StaffAccountException('school_not_operational');
        }
    }

    /**
     * Every invited role decided under the locks, before anything is written.
     *
     * @param  list<Role>  $roles
     *
     * @throws StaffAccountException
     */
    private function authorizeRoles(School $school, User $actor, array $roles, string $stage, ?string $invitationId = null): void
    {
        foreach ($roles as $role) {
            $decision = $this->authority->forGrant($actor, $school, $role, lock: true);

            if (! $decision->allowed()) {
                throw StaffAccountException::refusedGrant($decision, ['stage' => $stage, 'invitationId' => $invitationId, 'roleKey' => $role->key]);
            }
        }
    }

    private function lockPending(School $school, string $invitationId): StaffAccountInvitation
    {
        $invitation = StaffAccountInvitation::query()
            ->where('school_id', $school->id)
            ->whereKey($invitationId)
            ->lockForUpdate()
            ->first();

        if ($invitation === null) {
            throw new StaffAccountException('not_found');
        }

        if ($invitation->status !== StaffAccountInvitation::STATUS_PENDING) {
            throw new StaffAccountException('invitation_not_pending');
        }

        return $invitation;
    }

    /** This School's OWN knowledge only: the address already belongs to one of its members. */
    private function isOwnMember(School $school, string $email): bool
    {
        return SchoolMembership::query()
            ->where('school_id', $school->id)
            ->whereIn('user_id', User::query()->where('email', $email)->select('id'))
            ->exists();
    }

    private function end(School $school, User $actor, StaffAccountInvitation $invitation, string $reason): void
    {
        $invitation->forceFill([
            'status' => StaffAccountInvitation::STATUS_REVOKED,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
            'revoked_by_user_id' => $actor->id,
        ])->save();

        $this->email->cancelForSource(StaffInvitationEmailSource::SOURCE_TYPE, $invitation->id, $reason === 'reissued' ? 'source_reissued' : 'source_revoked');

        $this->audit->school($school, self::REVOKED, actor: $actor, subject: $invitation, metadata: [
            'invitationId' => $invitation->id,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  list<Role>  $roles
     */
    private function create(School $school, User $actor, string $email, array $roles): StaffAccountInvitation
    {
        $credential = OneTimeCredential::generate();
        $now = now();

        $invitation = StaffAccountInvitation::query()->create([
            'school_id' => $school->id,
            'destination_email' => $email,
            'selector' => $credential->selector,
            'secret_hash' => OneTimeCredential::hash($credential->secret),
            'status' => StaffAccountInvitation::STATUS_PENDING,
            'expires_at' => $now->copy()->addDays(self::LIFETIME_DAYS),
            'invited_by_user_id' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($roles as $role) {
            StaffAccountInvitationRole::query()->create([
                'school_id' => $school->id,
                'staff_account_invitation_id' => $invitation->id,
                'role_id' => $role->id,
                'created_at' => $now,
            ]);
        }

        $this->audit->school($school, self::INVITED, actor: $actor, subject: $invitation, metadata: [
            'invitationId' => $invitation->id,
            'roleKeys' => array_map(fn ($role) => $role->key, $roles),
        ]);

        // ADR 0054 section 8.9: the School's stored canonical origin, never
        // the request Host. The secret exists only in this sealed (encrypted)
        // content and the link's #fragment.
        $view = [
            'schoolName' => $school->name,
            'platformName' => $this->sender->platformName(),
            'acceptanceUrl' => $this->origins->schoolUrl($school, "invitations/{$school->id}/staff/{$credential->selector}").'#'.$credential->secret,
            'expiresAt' => $invitation->expires_at,
        ];

        $message = $this->email->queue(
            school: $school,
            purpose: EmailPurpose::StaffAccountInvitation,
            sourceId: $invitation->id,
            recipient: $email,
            subject: "You're invited to a staff account at {$school->name}",
            text: view('emails.identity.staff-account-invitation', $view)->render(),
            html: view('emails.identity.staff-account-invitation-html', $view)->render(),
            expiresAt: $invitation->expires_at,
        );

        $invitation->forceFill(['email_message_id' => $message->id])->save();

        return $invitation;
    }
}
