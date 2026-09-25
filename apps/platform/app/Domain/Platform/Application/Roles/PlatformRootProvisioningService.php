<?php

namespace App\Domain\Platform\Application\Roles;

use App\Models\PlatformAuditEvent;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 0O.1 (ADR 0046 section 2): the out-of-band provisioning of the
 * root platform role, called ONLY by the operator console command
 * `platform:provision-root` -- no HTTP route, no UI, no runtime grant
 * path. PlatformRoleGovernanceService still refuses the root role, and the
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

    /**
     * @return 'provisioned'|'already_provisioned'
     */
    public function provision(User $target): string
    {
        $this->assertOperatorConnection();
        $root = $this->rootRole();

        try {
            $outcome = DB::connection(self::CONNECTION)->transaction(function () use ($target, $root): string {
                if ($this->isProvisioned($target, $root)) {
                    return 'already_provisioned';
                }

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
                    'metadata' => ['role_key' => $root->key, 'user_id' => $target->id, 'method' => 'console'],
                ]);

                return 'provisioned';
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent provisioning committed first; its row and event stand.
            $outcome = 'already_provisioned';
        }

        $this->capabilities->forgetCache($target);

        return $outcome;
    }
}
