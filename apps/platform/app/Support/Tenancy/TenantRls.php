<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Migration-time helper applying the single, consistent RLS pattern
 * every tenant-owned table must use. See
 * docs/architecture/adr/0022-tenant-context-propagation.md.
 *
 * Usage in a migration (which must run via the pgsql_admin connection,
 * ADR 0021 -- `php artisan migrate --database=pgsql_admin`):
 *
 *   Schema::create('campuses', function (Blueprint $table) {
 *       $table->uuid('id')->primary();
 *       $table->foreignUuid('school_id')->constrained('schools');
 *       ...
 *   });
 *   TenantRls::enable('campuses');
 *
 * and in down():
 *
 *   TenantRls::disable('campuses');
 *   Schema::dropIfExists('campuses');
 */
class TenantRls
{
    /**
     * Postgres session-local GUC the policy below reads. Session-level
     * (not transaction-local) so it survives across multiple statements
     * within one request/job -- callers (TenantContext) are responsible
     * for clearing it when the unit of work ends. See ADR 0022.
     */
    public const SESSION_VAR = 'app.current_school_id';

    public static function enable(string $table, string $column = 'school_id'): void
    {
        self::assertSafeIdentifier($table);
        self::assertSafeIdentifier($column);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        $policy = self::policyName($table);
        $expression = "{$column} = NULLIF(current_setting('".self::SESSION_VAR."', true), '')::uuid";

        DB::statement(
            "CREATE POLICY {$policy} ON {$table} ".
            "USING ({$expression}) WITH CHECK ({$expression})"
        );
    }

    public static function disable(string $table): void
    {
        self::assertSafeIdentifier($table);

        $policy = self::policyName($table);
        DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    /**
     * Revokes UPDATE/DELETE from the runtime app role for an
     * append-only (audit) table, so immutability is a database-enforced
     * guarantee, not only an application-code convention. See
     * docs/architecture/adr/0017-audit-architecture.md and this
     * checkpoint's durable-audit implementation notes.
     */
    public static function makeAppendOnly(string $table, string $runtimeRole = 'school_os_app'): void
    {
        self::assertSafeIdentifier($table);
        self::assertSafeIdentifier($runtimeRole);

        DB::statement("REVOKE UPDATE, DELETE ON {$table} FROM {$runtimeRole}");
    }

    private static function policyName(string $table): string
    {
        return "tenant_isolation_{$table}";
    }

    private static function assertSafeIdentifier(string $identifier): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Unsafe identifier for RLS DDL: {$identifier}");
        }
    }
}
