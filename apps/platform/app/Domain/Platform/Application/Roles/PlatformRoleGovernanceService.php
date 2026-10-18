<?php

namespace App\Domain\Platform\Application\Roles;

use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0N.7 (ADR 0046 sections 3, 4, 6): the ONLY runtime path that grants
 * or revokes a platform role -- and only a code-approved, runtime-
 * assignable, non-root one (`platform_auditor`; ORG.4 adds
 * `platform_manager`, never together with the auditor). The root role
 * `platform_super_admin` is provisioned out of band and can be neither
 * granted nor revoked here; the database refuses it too
 * (trg_platform_role_assignments_governance), as it refuses self-grants
 * and self-revocations. No role builder, no capability editor.
 *
 * Every refusal that reaches this service is audited as
 * `platform.role_grant.denied` (privilege-escalation signals); plain input
 * validation (an unknown or disabled person, a missing code) is not. A change
 * forgets the target's platform capability cache at once.
 *
 * ORG.1A (ADR 0076 §24, V-2): a grant or revoke also needs a fresh MFA
 * re-verification of the request's `mfa_code` -- FreshMfaRequirement, the
 * mechanism every other consequential platform write uses -- checked after
 * every authorization and assignability check (an unauthorized caller never
 * burns a code) and outside the transaction (a rollback never un-consumes
 * one). Stale sign-in assurance is never enough.
 */
class PlatformRoleGovernanceService
{
    public const CAPABILITY = 'platform.role_grants.manage';

    /** The code allowlist of runtime-assignable platform roles (ADR 0046 §3; ORG.4 adds the Manager). */
    public const RUNTIME_ASSIGNABLE = ['platform_auditor', 'platform_manager'];

    /**
     * ORG.4 (ADR 0076 §7.4, ORG-C3): roles one person may never hold at the
     * same time -- the auditor oversees the manager. Mirrored by
     * trg_platform_role_assignments_separation (the authoritative backstop).
     */
    public const EXCLUSIVE = [
        'platform_manager' => 'platform_auditor',
        'platform_auditor' => 'platform_manager',
    ];

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
        private readonly FreshMfaRequirement $mfa,
    ) {}

    public function grant(Request $request, User $actor, string $userIdentifier, string $roleKey): PlatformRoleAssignment
    {
        $this->requireCapability($actor, $roleKey, null);
        $role = $this->assignableRole($actor, $roleKey, null);
        $target = $this->resolveTarget($userIdentifier);

        if ($target->id === $actor->id) {
            $this->deny($actor, $target, 'self_grant', $roleKey, 'user', 'You cannot grant a platform role to yourself.');
        }

        // A static refusal, so it comes before a code is spent (ORG.1A order).
        if ($this->holdsExclusiveRole($target, $role->key)) {
            $this->deny($actor, $target, 'role_conflict', $roleKey, 'user', 'That person holds a platform role that cannot be combined with this one.');
        }

        $this->requireFreshMfa($request, $actor, $target, $role->key);

        try {
            $assignment = DB::transaction(function () use ($actor, $target, $role): PlatformRoleAssignment {
                $assignment = PlatformRoleAssignment::query()->create([
                    'user_id' => $target->id,
                    'role_id' => $role->id,
                    'granted_by_user_id' => $actor->id,
                    'granted_at' => now(),
                ]);

                $this->audit->platform('platform.role_grant.granted', actor: $actor, subject: $assignment, metadata: [
                    'user_id' => $target->id,
                    'role_key' => $role->key,
                ]);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            $this->deny($actor, $target, 'already_granted', $roleKey, 'user', 'That person already holds this role.');
        } catch (QueryException $e) {
            // A racing grant of the exclusive role committed first: the
            // separation trigger refused this one.
            if (! str_contains($e->getMessage(), 'platform_role_separation')) {
                throw $e;
            }
            $this->deny($actor, $target, 'role_conflict', $roleKey, 'user', 'That person holds a platform role that cannot be combined with this one.');
        }

        $this->capabilities->forgetCache($target);

        return $assignment;
    }

    public function revoke(Request $request, User $actor, PlatformRoleAssignment $assignment): void
    {
        $role = Role::query()->findOrFail($assignment->role_id);
        $target = User::query()->findOrFail($assignment->user_id);

        $this->requireCapability($actor, $role->key, $target);
        $this->assignableRole($actor, $role->key, $target);

        if ($target->id === $actor->id) {
            $this->deny($actor, $target, 'self_revoke', $role->key, 'grant', 'You cannot revoke your own platform role.');
        }

        $this->requireFreshMfa($request, $actor, $target, $role->key);

        $revoked = DB::transaction(function () use ($actor, $assignment, $target, $role): bool {
            $updated = PlatformRoleAssignment::query()->whereKey($assignment->id)->active()
                ->update(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id, 'updated_at' => now()]);

            if ($updated !== 1) {
                return false;
            }

            $this->audit->platform('platform.role_grant.revoked', actor: $actor, subject: $assignment, metadata: [
                'user_id' => $target->id,
                'role_key' => $role->key,
            ]);

            return true;
        });

        if (! $revoked) {
            throw ValidationException::withMessages(['grant' => 'That grant is already revoked.']);
        }

        $this->capabilities->forgetCache($target);
    }

    private function requireCapability(User $actor, string $roleKey, ?User $target): void
    {
        if (! $this->capabilities->canPlatform($actor, self::CAPABILITY)) {
            $this->audit->platform('platform.role_grant.denied', actor: $actor, subject: $target, metadata: $this->deniedMetadata('capability_missing', $roleKey));

            throw new AccessDeniedHttpException('This account cannot govern platform roles.');
        }
    }

    /**
     * A fresh re-verification of the request's `mfa_code`. No enrolled
     * factor, or a code that does not verify now (wrong, reused or expired),
     * is an audited refusal; a missing code is plain validation, as for the
     * School lifecycle (ADR 0047 section 7).
     */
    private function requireFreshMfa(Request $request, User $actor, User $target, string $roleKey): void
    {
        if (! $this->mfa->hasActiveFactor($actor)) {
            $this->deny($actor, $target, 'mfa_not_enrolled', $roleKey, 'mfa_code', 'This action requires multi-factor authentication. Enroll a factor under Account security first.');
        }

        $code = $request->input('mfa_code');
        if (! is_string($code) || trim($code) === '') {
            throw ValidationException::withMessages(['mfa_code' => 'Enter a current authentication code.']);
        }

        try {
            $this->mfa->requireForAction($request, $actor);
        } catch (ValidationException) {
            $this->deny($actor, $target, 'mfa_verification_failed', $roleKey, 'mfa_code', 'That code is not valid.');
        }
    }

    /**
     * The role only if it is in the code allowlist AND flagged
     * runtime_assignable in the database -- never the root role.
     */
    private function assignableRole(User $actor, string $roleKey, ?User $target): Role
    {
        $role = in_array($roleKey, self::RUNTIME_ASSIGNABLE, true)
            ? Role::query()->where('key', $roleKey)->where('scope', 'platform')->where('runtime_assignable', true)->first()
            : null;

        if ($role === null) {
            $this->deny($actor, $target, 'role_not_assignable', $roleKey, 'role', 'That platform role cannot be granted or revoked here.');
        }

        return $role;
    }

    private function holdsExclusiveRole(User $target, string $roleKey): bool
    {
        $exclusive = self::EXCLUSIVE[$roleKey] ?? null;

        return $exclusive !== null && PlatformRoleAssignment::query()->active()
            ->where('user_id', $target->id)
            ->whereHas('role', fn ($query) => $query->where('key', $exclusive))
            ->exists();
    }

    private function resolveTarget(string $identifier): User
    {
        $identifier = trim($identifier);
        $user = Str::isUuid($identifier)
            ? User::query()->find(strtolower($identifier))
            : User::query()->where('email', strtolower($identifier))->first();

        if ($user === null) {
            throw ValidationException::withMessages(['user' => 'No account has exactly that email or identifier.']);
        }

        if ($user->isDisabled()) {
            throw ValidationException::withMessages(['user' => 'That account is disabled.']);
        }

        return $user;
    }

    private function deny(User $actor, ?User $target, string $outcome, string $roleKey, string $field, string $message): never
    {
        $this->audit->platform('platform.role_grant.denied', actor: $actor, subject: $target, metadata: $this->deniedMetadata($outcome, $roleKey));

        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * @return array<string, string>
     */
    private function deniedMetadata(string $outcome, string $roleKey): array
    {
        $metadata = ['outcome_code' => $outcome];

        // Only a known role key is ever recorded -- never free input.
        if (Role::query()->where('key', $roleKey)->where('scope', 'platform')->exists()) {
            $metadata['role_key'] = $roleKey;
        }

        return $metadata;
    }
}
