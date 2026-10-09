<?php

namespace App\Support\Testing;

use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantContext;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * SR.1 (ADR 0071 §11): the sanctioned LOCAL/TESTING seam for writing the
 * role catalogue and fixture role grants.
 *
 * Since SR.1 the runtime role cannot write `roles`, `role_capabilities` or
 * `capabilities`, and a runtime School-role grant must name an assigning User
 * who covers the role. Test fixtures and the guarded DDEV demo still need
 * arbitrary roles and grantor-less grants, inside the CALLER's transaction (so
 * a test's rollback removes them). They get them through a handful of
 * SECURITY DEFINER functions in the `local_fixtures` schema, owned by the
 * migration/admin role -- the same trusted administrative boundary the
 * database already recognises (`pg_has_role(current_user, owner)`).
 *
 * The seam is installed ONLY in a local or testing environment (refused
 * otherwise, in code, with no configuration override), only through the
 * verified admin connection, and `platform:verify-database` FAILS if the
 * schema exists anywhere else. No application code may call it (an
 * architecture guard pins its callers: tests, the guarded demo builder and
 * the install command).
 */
final class LocalCatalogueFixtures
{
    public const SCHEMA = 'local_fixtures';

    private const RUNTIME_ROLE = 'school_os_app';

    /** @var array<string, true> databases this process already installed into */
    private static array $installed = [];

    public static function assertAvailable(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('The local catalogue fixture seam exists only in local/testing environments.');
        }
    }

    /** Idempotent; through the verified admin connection only. */
    public static function install(): void
    {
        self::assertAvailable();

        $admin = DB::connection('pgsql_admin');
        $database = (string) $admin->selectOne('select current_database() as d')->d;
        if (isset(self::$installed[$database])) {
            return;
        }

        $runtime = self::RUNTIME_ROLE;
        $admin->unprepared(<<<SQL
            CREATE SCHEMA IF NOT EXISTS local_fixtures;
            REVOKE ALL ON SCHEMA local_fixtures FROM PUBLIC;
            GRANT USAGE ON SCHEMA local_fixtures TO {$runtime};

            CREATE OR REPLACE FUNCTION local_fixtures.exec(p_sql text) RETURNS void
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS \$fn\$
            BEGIN
                IF p_sql !~* '^\s*(insert\s+into|update|delete\s+from)\s+"?(roles|role_capabilities|capabilities|membership_role_assignments)"?[\s(]'
                    AND p_sql !~* '^\s*select\s+set_config\(' THEN
                    RAISE EXCEPTION 'local_fixtures.exec: only role-catalogue and role-grant fixture statements';
                END IF;
                EXECUTE p_sql;
            END;
            \$fn\$;

            REVOKE ALL ON FUNCTION local_fixtures.exec(text) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION local_fixtures.exec(text) TO {$runtime};
            SQL);

        self::$installed[$database] = true;
    }

    /**
     * Runs $writes against the default connection in PRETEND mode and
     * replays every captured statement through the owner-privileged seam,
     * inside the caller's transaction. $writes must only WRITE (a read inside
     * it returns nothing); read what it needs beforehand.
     *
     * @template T
     *
     * @param  Closure(): T  $writes
     * @return T
     */
    public static function asOwner(Closure $writes): mixed
    {
        self::assertAvailable();
        self::install();

        $connection = DB::connection();
        $result = null;
        $queries = $connection->pretend(function () use ($writes, &$result): void {
            $result = $writes();
        });
        // pretend() leaves its captured statements in the query log; a test that
        // later enables the log must not see them as queries it issued.
        $connection->flushQueryLog();

        $grammar = $connection->getQueryGrammar();
        foreach ($queries as $query) {
            // Reads return nothing while pretending (e.g. sync()'s pivot lookup); only
            // the writes -- and tenant-context set_config calls -- are replayed.
            if (preg_match('/^\s*select\b/i', $query['query']) === 1 && preg_match('/^\s*select\s+set_config\(/i', $query['query']) !== 1) {
                continue;
            }
            $bindings = array_map(
                fn ($value) => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s.u') : $value,
                $query['bindings'],
            );
            $connection->select('select local_fixtures.exec(?)', [$grammar->substituteBindingsIntoRawSql($query['query'], $bindings)]);
        }

        return $result;
    }

    /**
     * A fixture role carrying exactly $capabilities (never a production role:
     * non-system by default).
     *
     * @param  list<string>  $capabilities
     */
    public static function createRole(array $capabilities, string $scope = 'school', ?string $key = null, string $name = 'Test Capability Grant', bool $isSystem = false): Role
    {
        $role = self::asOwner(function () use ($capabilities, $scope, $key, $name, $isSystem): Role {
            $role = Role::query()->create([
                'key' => $key ?? 'test.capability_grant.'.Str::uuid(),
                'name' => $name,
                'scope' => $scope,
                'is_system' => $isSystem,
            ]);
            foreach (array_values(array_unique($capabilities)) as $capability) {
                DB::table('role_capabilities')->insert(['role_id' => $role->id, 'capability_key' => $capability]);
            }

            return $role;
        });

        return Role::query()->findOrFail($role->id);
    }

    /**
     * Replaces a role's capability set (fixture use only -- the product has no
     * runtime role editing).
     *
     * @param  list<string>  $capabilities
     */
    public static function setRoleCapabilities(Role $role, array $capabilities): void
    {
        self::asOwner(function () use ($role, $capabilities): void {
            DB::table('role_capabilities')->where('role_id', $role->id)->delete();
            foreach (array_values(array_unique($capabilities)) as $capability) {
                DB::table('role_capabilities')->insert(['role_id' => $role->id, 'capability_key' => $capability]);
            }
        });
    }

    /** Removes one capability from a role (fixture use only). */
    public static function removeRoleCapability(Role $role, string $capability): void
    {
        self::asOwner(fn () => DB::table('role_capabilities')->where('role_id', $role->id)->where('capability_key', $capability)->delete());
    }

    /** Adds one capability to a role (fixture use only). */
    public static function addRoleCapability(Role $role, string $capability): void
    {
        self::asOwner(fn () => DB::table('role_capabilities')->insert(['role_id' => $role->id, 'capability_key' => $capability]));
    }

    /** A fixture grant written below the administrative boundary (no assigning User). */
    public static function grantRole(SchoolMembership $membership, Role $role): MembershipRoleAssignment
    {
        $grant = app(TenantContext::class)->withSchool($membership->school, fn () => self::asOwner(
            fn () => MembershipRoleAssignment::query()->create([
                'school_id' => $membership->school_id,
                'school_membership_id' => $membership->id,
                'role_id' => $role->id,
            ]),
        ));

        return app(TenantContext::class)->withSchool($membership->school, fn () => MembershipRoleAssignment::query()->findOrFail($grant->id));
    }
}
