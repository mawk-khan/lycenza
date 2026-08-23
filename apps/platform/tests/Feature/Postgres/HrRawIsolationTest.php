<?php

namespace Tests\Feature\Postgres;

use App\Domain\HR\Application\EmployeeNumberAllocator;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.1: the `employees`/`hr_employee_number_counters`-specific
 * counterpart to tests/Feature/Postgres/RawIsolationTest -- proof
 * against REAL PostgreSQL RLS, independent of Eloquent/SchoolScope
 * entirely (docs/modules/HR.md "Test strategy"). Every query uses
 * DB::connection('pgsql') directly, the exact connection every real
 * request/queue job uses (ADR 0021).
 */
class HrRawIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function employees_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employees' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function hr_employee_number_counters_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'hr_employee_number_counters' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employees_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employees')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_employees(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employees');

        $this->assertCount(1, $rows);
        $this->assertSame($employeeA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_an_employee_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB): void {
            DB::connection('pgsql')->insert(
                'insert into employees (id, school_id, employee_number, full_name, record_status, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, 'EMP-999999', 'Rogue Employee', 'active'],
            );
        });
    }

    #[Test]
    public function raw_update_of_an_employee_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB, ['full_name' => 'Original Name']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employees set full_name = ? where id = ?',
            ['Hacked Name', $employeeB->id],
        );

        $this->assertSame(0, $affected);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolB->id]);
        $stillOriginal = DB::connection('pgsql')->selectOne('select full_name from employees where id = ?', [$employeeB->id]);
        $this->assertSame('Original Name', $stillOriginal->full_name);
    }

    #[Test]
    public function raw_delete_of_an_employee_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employees where id = ?', [$employeeB->id]);

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_select_on_the_counter_table_respects_school_context(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        app(TenantContext::class)->withSchool($schoolA, fn () => app(EmployeeNumberAllocator::class)->allocate($schoolA));
        app(TenantContext::class)->withSchool($schoolB, fn () => app(EmployeeNumberAllocator::class)->allocate($schoolB));

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select school_id from hr_employee_number_counters');

        $this->assertCount(1, $rows);
        $this->assertSame($schoolA->id, $rows[0]->school_id);
    }
}
