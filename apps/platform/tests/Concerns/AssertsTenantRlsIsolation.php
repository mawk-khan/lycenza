<?php

namespace Tests\Concerns;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CLAUDE.md rule 28 at the raw PostgreSQL layer, for a set of tenant-owned
 * tables that already hold School A's rows: RLS is enabled and forced; the
 * rows are invisible to School B's session and to a session with no tenant
 * context (fail closed); School B's session can neither rewrite them nor
 * insert a row for School A; and the Eloquent layer without tenant context
 * sees nothing. Plain SQL on the runtime connection, bypassing every
 * Application-layer and SchoolScope check.
 */
trait AssertsTenantRlsIsolation
{
    /** @param  array<string, class-string<Model>>  $tables  table => model, each with at least one row of School A */
    protected function assertTenantRlsIsolation(array $tables, string $schoolA, string $schoolB): void
    {
        $runtime = DB::connection('pgsql');
        $as = fn (?string $schoolId) => $runtime->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);

        foreach (array_keys($tables) as $table) {
            $rls = DB::connection('pgsql_admin')->selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace', [$table, 'public']);
            $this->assertTrue($rls !== null && $rls->relrowsecurity && $rls->relforcerowsecurity, "{$table} must have RLS enabled and forced");

            $as($schoolA);
            $rows = $runtime->table($table)->count();
            $this->assertGreaterThan(0, $rows, "{$table}: the fixture holds School A rows");
            $row = collect((array) $runtime->table($table)->first())->except('retention_recorded_at')->all();

            $as($schoolB);
            $this->assertSame(0, $runtime->table($table)->count(), "{$table} of School A is invisible to School B");
            try {
                $affected = $runtime->transaction(fn () => $runtime->table($table)->where('school_id', $schoolA)->update(['school_id' => DB::raw('school_id')]));
                $this->assertSame(0, $affected, "{$table}: School B rewrites no School A row");
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage(), "{$table}: an insert-only table refuses the rewrite itself");
            }
            $this->assertThrows(fn () => $runtime->transaction(fn () => $runtime->table($table)->insert([...$row, 'id' => (string) Str::uuid7()])), QueryException::class);

            $as(null);
            $this->assertSame(0, $runtime->table($table)->count(), "{$table} is empty without tenant context");

            $as($schoolA);
            $this->assertSame($rows, $runtime->table($table)->count(), "{$table}: nothing changed for School A");
        }

        $as(null);
        app(TenantContext::class)->clearAll();
        foreach ($tables as $model) {
            $this->assertSame(0, $model::query()->count(), "{$model} without tenant context");
        }
    }
}
