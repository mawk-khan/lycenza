<?php

namespace App\Domain\Platform\Application\Roles;

use App\Models\PlatformAuditEvent;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 0O.1 (ADR 0046 section 2): the out-of-band provisioning of the
 * root platform role, called ONLY by the operator console commands
 * `platform:provision-root` (existing account) and `platform:bootstrap-root`
 * (first boot, Phase 0O.1A) and by the local DDEV demo seed -- no HTTP
 * route, no UI, no runtime grant path. Since Phase 0O.1A the DATABASE
 * refuses a grantor-less grant from anything but the administrative role
 * (migration 2026_10_19_090000), so this service's admin connection is a
 * requirement, not a convention. PlatformRoleGovernanceService still refuses the root role, and the
 * database still refuses any runtime grant of it that names a grantor.
 *
 * - Runs on the migration/admin connection (`pgsql_admin`, ADR 0021): the
 *   operator must hold infrastructure credentials, not an application
 *   session. It refuses when that connection is not a distinct role from
 *   the runtime connection.
 * - The root role is identified structurally -- the one system platform
 *   role that is not runtime-assignable and holds the root-reserved
 *   `platform.role_grants.manage` -- never by name (rule 85's guard).
 * - The target is one existing, enabled account named by its exact email
 *   or id; no user is ever created and nothing is searched.
 * - Idempotent: an existing active root assignment is "already
 *   provisioned" (no row, no event). A concurrent provisioning is settled
 *   by the partial unique index `platform_role_assignments_one_active`,
 *   never by the pre-check alone.
 * - One transaction writes the assignment (grantor NULL = provisioned out
 *   of band) and `platform.role_grant.provisioned` (actor null, subject
 *   the assignment, metadata role_key, user_id, method: console).
 */
class PlatformRootProvisioningService
{
    public const EVENT = 'platform.role_grant.provisioned';

    public const CONNECTION = 'pgsql_admin';

    /** The root-reserved governance capability that identifies the root role (ADR 0046 section 4). */
    public const ROOT_CAPABILITY = PlatformRoleGovernanceService::CAPABILITY;

    public function __construct(private readonly CapabilityResolver $capabilities) {}

    public function assertOperatorConnection(): void
    {
        $admin = config('database.connections.'.self::CONNECTION.'.username');
        $runtime = config('database.connections.pgsql.username');

        if (! is_string($admin) || $admin === '' || $admin === $runtime) {
            throw new PlatformRootProvisioningRefusedException('operator_connection_not_separate',
                'Root provisioning needs the separate migration/admin database connection (DB_ADMIN_USERNAME), never the runtime role.');
        }
    }

    public function resolveTarget(string $identifier): User
    {
        $identifier = trim($identifier);

        if (Str::isUuid($identifier)) {
            $user = User::on(self::CONNECTION)->find(strtolower($identifier));
        } elseif (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            $user = User::on(self::CONNECTION)->where('email', strtolower($identifier))->first();
        } else {
            throw new PlatformRootProvisioningRefusedException('identifier_malformed',
                'Name the account by its exact email address or user id.');
        }

        if ($user === null) {
            throw new PlatformRootProvisioningRefusedException('user_unknown', 'No account has exactly that email address or id.');
        }

        if ($user->isDisabled()) {
            throw new PlatformRootProvisioningRefusedException('user_disabled', 'That account is disabled.');
        }

        return $user;
    }

    public function rootRole(): Role
    {
        $holders = DB::connection(self::CONNECTION)->table('role_capabilities')
            ->where('capability_key', self::ROOT_CAPABILITY)->select('role_id');

        $roles = Role::on(self::CONNECTION)
            ->where('scope', 'platform')->where('is_system', true)->where('runtime_assignable', false)
            ->whereIn('id', $holders)->get();

        if ($roles->count() !== 1) {
            throw new PlatformRootProvisioningRefusedException('root_role_unresolved',
                'The root platform role could not be identified; run the catalog seeder first.');
        }

        return $roles->first();
    }

    public function isProvisioned(User $target, Role $root): bool
    {
        return PlatformRoleAssignment::on(self::CONNECTION)
            ->where('user_id', $target->id)->where('role_id', $root->id)->active()->exists();
    }

    public function hasActiveRoot(Role $root): bool
    {
        return PlatformRoleAssignment::on(self::CONNECTION)->where('role_id', $root->id)->active()->exists();
    }

    /**
     * Provisions the root role for one EXISTING account (ADR 0046 section
     * 2). `$method` is the audited provisioning source: `console` for the
     * operator command, `demo_seed` for the local DDEV demo only.
     *
     * @param  'console'|'demo_seed'  $method
     * @return 'provisioned'|'already_provisioned'
     */
    public function provision(User $target, string $method = 'console'): string
    {
        $this->assertOperatorConnection();
        $root = $this->rootRole();

        try {
            $outcome = DB::connection(self::CONNECTION)->transaction(function () use ($target, $root, $method): string {
                $this->lockRootRole($root);

                if ($this->isProvisioned($target, $root)) {
                    return 'already_provisioned';
                }

                $this->grantRoot($target, $root, $method);

                return 'provisioned';
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent provisioning committed first; its row and event stand.
            $outcome = 'already_provisioned';
        }

        $this->capabilities->forgetCache($target);

        return $outcome;
    }

    /**
     * Phase 0O.1A: the FIRST-BOOT path (`platform:bootstrap-root`). Creates
     * one new, enabled account with the given password (hashed through the
     * application's hasher, never stored or logged in clear) and provisions
     * the root role for it -- account, grant and audit event in ONE
     * administrative transaction. Only while NO active root assignment
     * exists: it is not an account factory. Serialized with every other
     * root provisioning on the root role row, so two concurrent first boots
     * cannot both succeed. Creates no School membership and no Group grant.
     */
    public function bootstrapFirstRoot(string $name, string $email, string $password): User
    {
        $this->assertOperatorConnection();
        $root = $this->rootRole();
        $email = strtolower(trim($email));

        try {
            $user = DB::connection(self::CONNECTION)->transaction(function () use ($root, $name, $email, $password): User {
                $this->lockRootRole($root);

                if ($this->hasActiveRoot($root)) {
                    throw new PlatformRootProvisioningRefusedException('root_already_exists',
                        'A root platform account already exists. First-boot bootstrap is only for a fresh installation; use platform:provision-root for an existing account.');
                }

                if (User::on(self::CONNECTION)->where('email', $email)->exists()) {
                    throw new PlatformRootProvisioningRefusedException('user_exists',
                        'An account with that email address already exists; use platform:provision-root for an existing account.');
                }

                $user = User::on(self::CONNECTION)->create([
                    'name' => trim($name),
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);

                $this->grantRoot($user, $root, 'console');

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PlatformRootProvisioningRefusedException('user_exists',
                'An account with that email address already exists; use platform:provision-root for an existing account.');
        }

        $user = User::query()->findOrFail($user->id);
        $this->capabilities->forgetCache($user);

        return $user;
    }

    /**
     * Serializes root provisioning: FOR NO KEY UPDATE on the root role row
     * (it never conflicts with the KEY SHARE locks foreign keys take).
     */
    private function lockRootRole(Role $root): void
    {
        Role::on(self::CONNECTION)->whereKey($root->id)->lock('for no key update')->first();
    }

    /**
     * The grant (grantor NULL: out of band) and its ADR 0046 event, inside
     * the caller's administrative transaction. The database accepts a
     * grantor-less grant only from the administrative role (Phase 0O.1A).
     */
    private function grantRoot(User $target, Role $root, string $method): void
    {
        $assignment = PlatformRoleAssignment::on(self::CONNECTION)->create([
            'user_id' => $target->id,
            'role_id' => $root->id,
            'granted_by_user_id' => null,
            'granted_at' => now(),
        ]);

        PlatformAuditEvent::on(self::CONNECTION)->create([
            'occurred_at' => now(),
            'actor_user_id' => null,
            'event_type' => self::EVENT,
            'subject_type' => PlatformRoleAssignment::class,
            'subject_id' => $assignment->id,
            'metadata' => ['role_key' => $root->key, 'user_id' => $target->id, 'method' => $method],
        ]);
    }
}
