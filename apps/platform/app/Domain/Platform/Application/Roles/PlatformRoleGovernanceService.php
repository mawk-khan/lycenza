<?php

namespace App\Domain\Platform\Application\Roles;

use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0N.7 (ADR 0046 sections 3, 4, 6): the ONLY runtime path that grants
 * or revokes a platform role -- and only a code-approved, runtime-
 * assignable, non-root one (v1: `platform_auditor`). The root role
 * `platform_super_admin` is provisioned out of band and can be neither
 * granted nor revoked here; the database refuses it too
 * (trg_platform_role_assignments_governance), as it refuses self-grants
 * and self-revocations. No role builder, no capability editor.
 *
 * Every refusal that reaches this service is audited as
 * `platform.role_grant.denied` (privilege-escalation signals); plain input
 * validation (an unknown or disabled person) is not. A change forgets the
 * target's platform capability cache at once.
 */
class PlatformRoleGovernanceService
{
    public const CAPABILITY = 'platform.role_grants.manage';

    /** The code allowlist of runtime-assignable platform roles (ADR 0046 §3). */
    public const RUNTIME_ASSIGNABLE = ['platform_auditor'];

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
    ) {}

    public function grant(User $actor, string $userIdentifier, string $roleKey): PlatformRoleAssignment
    {
        $this->requireCapability($actor, $roleKey, null);
        $role = $this->assignableRole($actor, $roleKey, null);
        $target = $this->resolveTarget($userIdentifier);

        if ($target->id === $actor->id) {
            $this->deny($actor, $target, 'self_grant', $roleKey, 'user', 'You cannot grant a platform role to yourself.');
        }

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
        }

        $this->capabilities->forgetCache($target);

        return $assignment;
    }

    public function revoke(User $actor, PlatformRoleAssignment $assignment): void
    {
        $role = Role::query()->findOrFail($assignment->role_id);
        $target = User::query()->findOrFail($assignment->user_id);

        $this->requireCapability($actor, $role->key, $target);
        $this->assignableRole($actor, $role->key, $target);

        if ($target->id === $actor->id) {
            $this->deny($actor, $target, 'self_revoke', $role->key, 'grant', 'You cannot revoke your own platform role.');
        }

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
