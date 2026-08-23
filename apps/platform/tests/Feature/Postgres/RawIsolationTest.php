<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 35: this checkpoint's acceptance criteria explicitly require
 * proof against REAL PostgreSQL, at the raw-SQL level, independent of
 * Eloquent/the application layer entirely. Every query here uses
 * DB::connection('pgsql') directly (the exact connection every
 * request/queue-job uses, ADR 0021) and DB::connection('pgsql_admin')
 * only to assert the runtime role's own privileges.
 */
class RawIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function the_runtime_role_is_not_a_superuser_and_does_not_bypass_rls(): void
    {
        $row = DB::connection('pgsql_admin')
            ->selectOne(
                'select rolsuper, rolbypassrls, rolcanlogin from pg_roles where rolname = ?',
                ['school_os_app'],
            );

        $this->assertNotNull($row, 'school_os_app role must exist.');
        $this->assertFalse($row->rolsuper, 'Runtime role must not be a superuser (ADR 0021).');
        $this->assertFalse($row->rolbypassrls, 'Runtime role must not have BYPASSRLS (ADR 0021).');
        $this->assertTrue($row->rolcanlogin);

        // And prove the CURRENT connection actually IS that role, not
        // just that the role exists with the right flags in isolation.
        $current = DB::connection('pgsql')->selectOne('select current_user as user');
        $this->assertSame('school_os_app', $current->user);
    }

    #[Test]
    public function campuses_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'campuses' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createCampus($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from campuses')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusA = $this->createCampus($schoolA);
        $this->createCampus($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from campuses');

        $this->assertCount(1, $rows);
        $this->assertSame($campusA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB): void {
            DB::connection('pgsql')->insert(
                'insert into campuses (id, school_id, name, code, created_at, updated_at) values (?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, 'Rogue Campus', 'RG'.random_int(100, 999)],
            );
        });
    }

    #[Test]
    public function raw_update_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB, ['name' => 'Original Name']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update campuses set name = ? where id = ?',
            ['Hacked Name', $campusB->id],
        );

        $this->assertSame(0, $affected);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolB->id]);
        $stillOriginal = DB::connection('pgsql')->selectOne('select name from campuses where id = ?', [$campusB->id]);
        $this->assertSame('Original Name', $stillOriginal->name);
    }

    #[Test]
    public function raw_delete_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from campuses where id = ?', [$campusB->id]);

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function leaving_school_a_context_leaves_no_residual_visibility(): void
    {
        $schoolA = $this->createSchool();
        $this->createCampus($schoolA);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);
        $this->assertSame(1, (int) DB::connection('pgsql')->selectOne('select count(*) as c from campuses')->c);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from campuses')->c);
    }
}
