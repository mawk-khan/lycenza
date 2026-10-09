<?php

namespace App\Domain\Identity\Application\Staff;

use App\Domain\Identity\Application\Credentials\CredentialChangeService;
use App\Domain\Identity\Application\SchoolAccessLock;
use App\Domain\Identity\Infrastructure\StaffAccountInvitation;
use App\Domain\Identity\Infrastructure\StaffAccountInvitationRole;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Phase 0O.12B (ADR 0059 sections 6.3, 14, 15): accepting a staff account
 * invitation -- the ONE path that turns a School's invitation into a staff
 * membership with its roles.
 *
 * One transaction, in this order:
 * 0. SR.2 (ADR 0071 §14): the School access lock (SchoolAccessLock), FIRST,
 *    exactly like every other staff-access mutation;
 * 1. SchoolOperationalGuard hold (a suspended School: invalid, the invitation
 *    left pending until it expires);
 * 2. the invitation row lock, inside the route's School context; pending,
 *    unexpired, constant-time secret match;
 * 3. the ISSUER's CURRENT authority re-decided now, under the lock (SR.2:
 *    stale invitation-time authority never survives): still enabled with an
 *    active membership, holding `school.members.manage` +
 *    `school.roles.manage`, and every invited role still active, non-empty
 *    and held or covered by a grant right they hold (RoleGrantAuthority,
 *    issuer membership and grants FOR SHARE) -- otherwise invalid (fail
 *    closed), and the refusal audited (`role_grant_refused`, stage
 *    `invitation_acceptance`) in this committing transaction;
 * 4. identity by the invitation's canonical email:
 *    - no User: a NEW User (name + `Password::defaults()` password through
 *      CredentialChangeService::establishInitialPassword); an insert race is
 *      lost to the unique email index and answered "sign in required" --
 *      one email never creates two Users;
 *    - a User: the request must be signed in AS that User (the Guardian
 *      precedent); its password and MFA are never touched;
 * 5. refused as invalid: a disabled User, a holder of any unrevoked platform
 *    role (platform-audited, never shown to the School), any existing
 *    membership of this School in any state (a suspended one is never
 *    reactivated by an invitation);
 * 6. an ACTIVE membership plus the invited role grants; the invitation
 *    accepted; the School audit.
 *
 * Every credential failure is ONE `invalid` outcome (no oracle). No
 * auto-login. No Employee is created -- User is not Employee.
 */
final class StaffInvitationAcceptanceService
{
    public const ACTIVATED = 'staff.account_activated';

    public const LINKED_EXISTING = 'staff.account_linked_existing';

    public const ROLE_ASSIGNED = 'school.membership.role_assigned';

    public const REFUSED_PROTECTED = 'staff.account_invitation_refused_protected';

    public function __construct(
        private readonly CredentialChangeService $credentials,
        private readonly SchoolOperationalGuard $guard,
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
        private readonly RoleGrantAuthority $authority,
        private readonly RoleGrantRefusalAudit $refusals,
    ) {}

    public function accept(
        School $school,
        string $selector,
        string $secret,
        ?User $signedIn,
        ?string $name,
        ?string $password,
        ?string $passwordConfirmation,
    ): CredentialOutcome {
        $outcome = OneTimeCredential::wellFormed($selector, $secret)
            ? $this->attempt($school, $selector, $secret, $signedIn, $name, $password, $passwordConfirmation)
            : CredentialOutcome::of(CredentialOutcome::INVALID);

        Log::info('identity.staff_invitation.accept', ['outcome' => $outcome->outcome]);

        return $outcome;
    }

    private function attempt(School $school, string $selector, string $secret, ?User $signedIn, ?string $name, ?string $password, ?string $passwordConfirmation): CredentialOutcome
    {
        try {
            return $this->transact($school, $selector, $secret, $signedIn, $name, $password, $passwordConfirmation);
        } catch (StaffAccountException $e) {
            // Only the never-expected database backstop reaches here (the
            // decision above already refused everything else, in-transaction).
            $this->refusals->recordAfterRollback($school, $signedIn, $e);

            return CredentialOutcome::of(CredentialOutcome::INVALID);
        }
    }

    private function transact(School $school, string $selector, string $secret, ?User $signedIn, ?string $name, ?string $password, ?string $passwordConfirmation): CredentialOutcome
    {
        return DB::transaction(function () use ($school, $selector, $secret, $signedIn, $name, $password, $passwordConfirmation): CredentialOutcome {
            SchoolAccessLock::hold($school->id);

            if (! $this->guard->holdOperational($school->id)) {
                return CredentialOutcome::of(CredentialOutcome::INVALID);
            }

            return $this->context->withSchool($school, function () use ($school, $selector, $secret, $signedIn, $name, $password, $passwordConfirmation): CredentialOutcome {
                $invitation = StaffAccountInvitation::query()
                    ->where('school_id', $school->id)
                    ->where('selector', $selector)
                    ->lockForUpdate()
                    ->first();

                if ($invitation === null || ! $invitation->isUsable() || ! OneTimeCredential::matches($secret, $invitation->secret_hash)) {
                    return CredentialOutcome::of(CredentialOutcome::INVALID);
                }

                /** @var list<Role> $roles */
                $roles = StaffAccountInvitationRole::query()
                    ->where('staff_account_invitation_id', $invitation->id)
                    ->with('role')
                    ->get()
                    ->map(fn (StaffAccountInvitationRole $row) => $row->role)
                    ->sortBy('key')
                    ->values()
                    ->all();

                $decisions = $this->issuerCurrentAuthority($school, $invitation, $roles, $signedIn);

                if ($decisions === null) {
                    return CredentialOutcome::of(CredentialOutcome::INVALID);
                }

                $existing = User::query()->where('email', $invitation->destination_email)->lockForUpdate()->first();

                if ($existing === null) {
                    return $this->acceptAsNewUser($school, $invitation, $roles, $decisions, $name, $password, $passwordConfirmation);
                }

                if ($signedIn === null || $signedIn->id !== $existing->id) {
                    return CredentialOutcome::of(CredentialOutcome::SIGN_IN_REQUIRED);
                }

                return $this->acceptAsExistingUser($school, $invitation, $roles, $decisions, $existing);
            });
        });
    }

    /**
     * SR.2: the issuer's CURRENT authority over every invited role, decided
     * under the locks -- or null (refused, audited here: this transaction
     * commits its `invalid` outcome, so the event persists).
     *
     * @param  list<Role>  $roles
     * @return array<string, RoleGrantDecision>|null role id => decision
     */
    private function issuerCurrentAuthority(School $school, StaffAccountInvitation $invitation, array $roles, ?User $signedIn): ?array
    {
        $issuer = $invitation->invited_by_user_id !== null ? User::query()->find($invitation->invited_by_user_id) : null;

        if ($issuer === null || $roles === []) {
            return null;
        }

        $context = ['stage' => 'invitation_acceptance', 'invitationId' => $invitation->id];
        $decisions = [];

        foreach ($roles as $role) {
            $decision = $this->authority->forGrant($issuer, $school, $role, lock: true);

            if (! $decision->allowed()) {
                $this->refusals->record($school, $signedIn, StaffAccountException::refusedGrant($decision, $context + ['roleKey' => $role->key])->refusal);

                return null;
            }

            $decisions[$role->id] = $decision;
        }

        // The invitation contract: the issuer still administers staff here
        // (fresh, from the rows already locked above -- never the cache).
        if (! in_array(StaffRoleCatalog::MEMBERS, $this->authority->held($issuer, $school) ?? [], true)) {
            $this->refusals->record($school, $signedIn, StaffAccountException::refusedGrant(RoleGrantDecision::refuse('not_role_manager'), $context + ['roleKey' => $roles[0]->key])->refusal);

            return null;
        }

        return $decisions;
    }

    /**
     * @param  list<Role>  $roles
     * @param  array<string, RoleGrantDecision>  $decisions
     */
    private function acceptAsNewUser(School $school, StaffAccountInvitation $invitation, array $roles, array $decisions, ?string $name, ?string $password, ?string $passwordConfirmation): CredentialOutcome
    {
        $validator = Validator::make(
            ['name' => is_string($name) ? trim($name) : $name, 'password' => $password, 'password_confirmation' => $passwordConfirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            return CredentialOutcome::policyRejected($validator->errors());
        }

        try {
            // A savepoint: losing the unique-email race rolls back only this
            // insert, and the person is told to sign in instead.
            $user = DB::transaction(fn (): User => User::query()->create([
                'name' => trim((string) $name),
                'email' => $invitation->destination_email,
                'password' => null,
            ]));
        } catch (UniqueConstraintViolationException) {
            return CredentialOutcome::of(CredentialOutcome::SIGN_IN_REQUIRED);
        }

        $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $this->credentials->establishInitialPassword($locked, (string) $password);
        // Receiving the invitation proved control of the mailbox.
        $locked->forceFill(['email_verified_at' => now()])->save();

        $membership = $this->grantMembership($school, $invitation, $roles, $decisions, $locked);

        $this->audit->school($school, self::ACTIVATED, actor: $locked, subject: $membership, metadata: [
            'invitationId' => $invitation->id,
            'userId' => $locked->id,
            'schoolMembershipId' => $membership->id,
        ]);

        return CredentialOutcome::of(CredentialOutcome::ACCEPTED_NEW);
    }

    /**
     * @param  list<Role>  $roles
     * @param  array<string, RoleGrantDecision>  $decisions
     */
    private function acceptAsExistingUser(School $school, StaffAccountInvitation $invitation, array $roles, array $decisions, User $user): CredentialOutcome
    {
        if ($user->isDisabled() || ! $user->hasLocalCredential()) {
            return CredentialOutcome::of(CredentialOutcome::INVALID);
        }

        if ($this->holdsPlatformRole($user)) {
            // Platform operators never become School staff through a School
            // invitation; recorded on the platform side only.
            $this->audit->platform(self::REFUSED_PROTECTED, actor: $user, subject: $user, metadata: [
                'school_id' => $school->id,
                'invitation_id' => $invitation->id,
                'outcome_code' => 'platform_role_holder',
            ]);

            return CredentialOutcome::of(CredentialOutcome::INVALID);
        }

        if (SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $user->id)->exists()) {
            return CredentialOutcome::of(CredentialOutcome::INVALID);
        }

        $membership = $this->grantMembership($school, $invitation, $roles, $decisions, $user);

        $this->audit->school($school, self::LINKED_EXISTING, actor: $user, subject: $membership, metadata: [
            'invitationId' => $invitation->id,
            'userId' => $user->id,
            'schoolMembershipId' => $membership->id,
        ]);

        return CredentialOutcome::of(CredentialOutcome::ACCEPTED_EXISTING);
    }

    /**
     * @param  list<Role>  $roles
     * @param  array<string, RoleGrantDecision>  $decisions
     */
    private function grantMembership(School $school, StaffAccountInvitation $invitation, array $roles, array $decisions, User $user): SchoolMembership
    {
        $membership = SchoolMembership::query()->create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'status' => SchoolMembership::STATUS_ACTIVE,
            'invited_at' => $invitation->created_at,
            'joined_at' => now(),
        ]);

        foreach ($roles as $role) {
            $grant = RoleGrantAuthority::backstopped(fn () => MembershipRoleAssignment::query()->create([
                'school_id' => $school->id,
                'school_membership_id' => $membership->id,
                'role_id' => $role->id,
                'assigned_by_user_id' => $invitation->invited_by_user_id,
                'assigned_at' => now(),
            ]), ['stage' => 'invitation_acceptance', 'invitationId' => $invitation->id, 'roleKey' => $role->key]);

            $this->audit->school($school, self::ROLE_ASSIGNED, actor: $user, subject: $grant, metadata: [
                'schoolMembershipId' => $membership->id,
                'roleKey' => $role->key,
                'invitationId' => $invitation->id,
                ...$decisions[$role->id]->grantMetadata(),
            ]);
        }

        $invitation->forceFill([
            'status' => StaffAccountInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_user_id' => $user->id,
        ])->save();

        $this->capabilities->forgetCache($user, $school);

        return $membership;
    }

    private function holdsPlatformRole(User $user): bool
    {
        return PlatformRoleAssignment::query()->where('user_id', $user->id)->active()->exists();
    }
}
