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
 * Phase 8A.1/8A.2/8A.3/8A.4: the HR-table-specific counterpart to
 * tests/Feature/Postgres/RawIsolationTest -- proof against REAL
 * PostgreSQL RLS, independent of Eloquent/SchoolScope entirely
 * (docs/modules/HR.md "Test strategy"). Every query uses
 * DB::connection('pgsql') directly, the exact connection every real
 * request/queue job uses (ADR 0021). Covers `employees`/
 * `hr_employee_number_counters` (8A.1), `employee_personal_details`/
 * `employee_addresses`/`employee_emergency_contacts` (8A.2),
 * `hr_departments`/`positions` (8A.3), `employment_records`/
 * `employee_assignments` (8A.4), `employee_qualifications`/
 * `employee_experience_records`/`employee_certifications` (8A.6), and
 * `employee_documents` (8A.7).
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

    // --- Phase 8A.2: employee_personal_details --------------------------

    #[Test]
    public function employee_personal_details_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employee_personal_details' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employee_personal_details_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeePersonalDetail($employee);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employee_personal_details')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_personal_details(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $detailA = $this->createEmployeePersonalDetail($employeeA);
        $this->createEmployeePersonalDetail($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employee_personal_details');

        $this->assertCount(1, $rows);
        $this->assertSame($detailA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_a_personal_detail_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $employeeB): void {
            DB::connection('pgsql')->insert(
                'insert into employee_personal_details (id, school_id, employee_id, created_at, updated_at) '.
                'values (?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $employeeB->id],
            );
        });
    }

    #[Test]
    public function raw_update_of_a_personal_detail_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $detailB = $this->createEmployeePersonalDetail($employeeB, ['personal_phone' => '1111111111']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employee_personal_details set personal_phone = ? where id = ?',
            ['9999999999', $detailB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_a_personal_detail_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $detailB = $this->createEmployeePersonalDetail($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employee_personal_details where id = ?', [$detailB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.2: employee_addresses ----------------------------------

    #[Test]
    public function employee_addresses_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employee_addresses' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employee_addresses_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeAddress($employee);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employee_addresses')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_addresses(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $addressA = $this->createEmployeeAddress($employeeA);
        $this->createEmployeeAddress($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employee_addresses');

        $this->assertCount(1, $rows);
        $this->assertSame($addressA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_an_address_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $employeeB): void {
            DB::connection('pgsql')->insert(
                'insert into employee_addresses (id, school_id, employee_id, address_type, address_line1, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $employeeB->id, 'other', 'Rogue Address'],
            );
        });
    }

    #[Test]
    public function raw_update_of_an_address_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $addressB = $this->createEmployeeAddress($employeeB, ['city' => 'Original City']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employee_addresses set city = ? where id = ?',
            ['Hacked City', $addressB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_an_address_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $addressB = $this->createEmployeeAddress($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employee_addresses where id = ?', [$addressB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.2: employee_emergency_contacts -------------------------

    #[Test]
    public function employee_emergency_contacts_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employee_emergency_contacts' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employee_emergency_contacts_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeEmergencyContact($employee);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employee_emergency_contacts')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_emergency_contacts(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $contactA = $this->createEmployeeEmergencyContact($employeeA);
        $this->createEmployeeEmergencyContact($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employee_emergency_contacts');

        $this->assertCount(1, $rows);
        $this->assertSame($contactA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_an_emergency_contact_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $employeeB): void {
            DB::connection('pgsql')->insert(
                'insert into employee_emergency_contacts (id, school_id, employee_id, name, phone, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $employeeB->id, 'Rogue Contact', '0000000000'],
            );
        });
    }

    #[Test]
    public function raw_update_of_an_emergency_contact_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $contactB = $this->createEmployeeEmergencyContact($employeeB, ['name' => 'Original Name']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employee_emergency_contacts set name = ? where id = ?',
            ['Hacked Name', $contactB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_an_emergency_contact_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $contactB = $this->createEmployeeEmergencyContact($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employee_emergency_contacts where id = ?', [$contactB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.3: hr_departments --------------------------------------

    #[Test]
    public function hr_departments_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'hr_departments' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_hr_departments_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createDepartment($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from hr_departments')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_departments(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $departmentA = $this->createDepartment($schoolA);
        $this->createDepartment($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from hr_departments');

        $this->assertCount(1, $rows);
        $this->assertSame($departmentA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_a_department_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB): void {
            DB::connection('pgsql')->insert(
                'insert into hr_departments (id, school_id, name, code, status, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, 'Rogue Department', 'ROGUE', 'active'],
            );
        });
    }

    #[Test]
    public function raw_update_of_a_department_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $departmentB = $this->createDepartment($schoolB, ['name' => 'Original Name']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update hr_departments set name = ? where id = ?',
            ['Hacked Name', $departmentB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_a_department_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $departmentB = $this->createDepartment($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from hr_departments where id = ?', [$departmentB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.3: positions --------------------------------------------

    #[Test]
    public function positions_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'positions' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_positions_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createPosition($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from positions')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_positions(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $positionA = $this->createPosition($schoolA);
        $this->createPosition($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from positions');

        $this->assertCount(1, $rows);
        $this->assertSame($positionA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_a_position_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB): void {
            DB::connection('pgsql')->insert(
                'insert into positions (id, school_id, name, code, status, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, 'Rogue Position', 'ROGUE', 'active'],
            );
        });
    }

    #[Test]
    public function raw_update_of_a_position_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $positionB = $this->createPosition($schoolB, ['name' => 'Original Name']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update positions set name = ? where id = ?',
            ['Hacked Name', $positionB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_a_position_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $positionB = $this->createPosition($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from positions where id = ?', [$positionB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.4: employment_records -----------------------------------

    #[Test]
    public function employment_records_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employment_records' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employment_records_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmploymentRecord($employee);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employment_records')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_employment_records(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $this->createEmploymentRecord($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employment_records');

        $this->assertCount(1, $rows);
        $this->assertSame($employmentA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_an_employment_record_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $employeeB): void {
            DB::connection('pgsql')->insert(
                'insert into employment_records (id, school_id, employee_id, employment_type, starts_on, status, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $employeeB->id, 'permanent', '2026-01-01', 'active'],
            );
        });
    }

    #[Test]
    public function raw_update_of_an_employment_record_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB, ['employment_type' => 'permanent']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employment_records set employment_type = ? where id = ?',
            ['contract', $employmentB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_an_employment_record_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employment_records where id = ?', [$employmentB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.4: employee_assignments ----------------------------------

    #[Test]
    public function employee_assignments_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employee_assignments' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employee_assignments_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $this->createEmployeeAssignment($employment, $position);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employee_assignments')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_assignments(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $positionA = $this->createPosition($schoolA);
        $positionB = $this->createPosition($schoolB);
        $assignmentA = $this->createEmployeeAssignment($employmentA, $positionA);
        $this->createEmployeeAssignment($employmentB, $positionB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employee_assignments');

        $this->assertCount(1, $rows);
        $this->assertSame($assignmentA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_an_assignment_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $positionB = $this->createPosition($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $employmentB, $positionB): void {
            DB::connection('pgsql')->insert(
                'insert into employee_assignments (id, school_id, employment_record_id, position_id, starts_on, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $employmentB->id, $positionB->id, '2026-01-01'],
            );
        });
    }

    #[Test]
    public function raw_update_of_an_assignment_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $positionB = $this->createPosition($schoolB);
        $assignmentB = $this->createEmployeeAssignment($employmentB, $positionB, ['is_primary' => false]);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employee_assignments set is_primary = true where id = ?',
            [$assignmentB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_an_assignment_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $positionB = $this->createPosition($schoolB);
        $assignmentB = $this->createEmployeeAssignment($employmentB, $positionB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employee_assignments where id = ?', [$assignmentB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.5: employee_assignments.manager_assignment_id -----------

    #[Test]
    public function raw_insert_of_an_assignment_with_a_cross_school_manager_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $positionB = $this->createPosition($schoolB);
        $managerB = $this->createEmployeeAssignment($employmentB, $positionB);

        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionA = $this->createPosition($schoolA);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolA, $employmentA, $positionA, $managerB): void {
            DB::connection('pgsql')->insert(
                'insert into employee_assignments (id, school_id, employment_record_id, position_id, manager_assignment_id, starts_on, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolA->id, $employmentA->id, $positionA->id, $managerB->id, '2026-01-01'],
            );
        });
    }

    #[Test]
    public function raw_update_setting_a_cross_school_manager_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionA = $this->createPosition($schoolA);
        $subordinateA = $this->createEmployeeAssignment($employmentA, $positionA);

        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $positionB = $this->createPosition($schoolB);
        $managerB = $this->createEmployeeAssignment($employmentB, $positionB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($subordinateA, $managerB): void {
            DB::connection('pgsql')->update(
                'update employee_assignments set manager_assignment_id = ? where id = ?',
                [$managerB->id, $subordinateA->id],
            );
        });
    }

    // --- Phase 8A.6: employee_qualifications -------------------------------

    #[Test]
    public function employee_qualifications_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employee_qualifications' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employee_qualifications_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeQualification($employee);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employee_qualifications')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_qualifications(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $qualificationA = $this->createEmployeeQualification($employeeA);
        $this->createEmployeeQualification($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employee_qualifications');

        $this->assertCount(1, $rows);
        $this->assertSame($qualificationA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_a_qualification_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $employeeB): void {
            DB::connection('pgsql')->insert(
                'insert into employee_qualifications (id, school_id, employee_id, qualification_type, qualification_name, institution, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $employeeB->id, 'bachelors', 'Rogue Degree', 'Rogue University'],
            );
        });
    }

    #[Test]
    public function raw_update_of_a_qualification_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $qualificationB = $this->createEmployeeQualification($employeeB, ['grade_or_result' => 'Original']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employee_qualifications set grade_or_result = ? where id = ?',
            ['Hacked', $qualificationB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_a_qualification_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $qualificationB = $this->createEmployeeQualification($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employee_qualifications where id = ?', [$qualificationB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.6: employee_experience_records ----------------------------

    #[Test]
    public function employee_experience_records_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employee_experience_records' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employee_experience_records_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeExperience($employee);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employee_experience_records')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_experience_records(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $experienceA = $this->createEmployeeExperience($employeeA);
        $this->createEmployeeExperience($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employee_experience_records');

        $this->assertCount(1, $rows);
        $this->assertSame($experienceA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_an_experience_record_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $employeeB): void {
            DB::connection('pgsql')->insert(
                'insert into employee_experience_records (id, school_id, employee_id, organization, job_title, starts_on, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $employeeB->id, 'Rogue Organization', 'Rogue Role', '2020-01-01'],
            );
        });
    }

    #[Test]
    public function raw_update_of_an_experience_record_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $experienceB = $this->createEmployeeExperience($employeeB, ['organization' => 'Original Organization']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employee_experience_records set organization = ? where id = ?',
            ['Hacked Organization', $experienceB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_an_experience_record_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $experienceB = $this->createEmployeeExperience($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employee_experience_records where id = ?', [$experienceB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.6: employee_certifications --------------------------------

    #[Test]
    public function employee_certifications_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employee_certifications' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employee_certifications_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeCertification($employee);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employee_certifications')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_certifications(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $certificationA = $this->createEmployeeCertification($employeeA);
        $this->createEmployeeCertification($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employee_certifications');

        $this->assertCount(1, $rows);
        $this->assertSame($certificationA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_a_certification_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $employeeB): void {
            DB::connection('pgsql')->insert(
                'insert into employee_certifications (id, school_id, employee_id, name, issuer, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $employeeB->id, 'Rogue Certificate', 'Rogue Issuer'],
            );
        });
    }

    #[Test]
    public function raw_update_of_a_certification_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $certificationB = $this->createEmployeeCertification($employeeB, ['issuer' => 'Original Issuer']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employee_certifications set issuer = ? where id = ?',
            ['Hacked Issuer', $certificationB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_a_certification_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $certificationB = $this->createEmployeeCertification($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employee_certifications where id = ?', [$certificationB->id]);

        $this->assertSame(0, $affected);
    }

    // --- Phase 8A.7: employee_documents -------------------------------------

    #[Test]
    public function employee_documents_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'employee_documents' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_select_on_employee_documents_with_no_school_context_returns_zero_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employee_documents')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function raw_select_with_school_a_context_sees_only_school_as_documents(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $documentA = $this->createEmployeeDocument($employeeA);
        $this->createEmployeeDocument($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from employee_documents');

        $this->assertCount(1, $rows);
        $this->assertSame($documentA->id, $rows[0]->id);
    }

    #[Test]
    public function raw_insert_of_a_document_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $employeeB): void {
            DB::connection('pgsql')->insert(
                'insert into employee_documents (id, school_id, employee_id, category, storage_disk, storage_path, original_filename, mime_type, size_bytes, uploaded_at, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $employeeB->id, 'other', 'local', 'employee-documents/rogue.pdf', 'rogue.pdf', 'application/pdf', 1000],
            );
        });
    }

    #[Test]
    public function raw_update_of_a_document_across_schools_affects_zero_rows_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $documentB = $this->createEmployeeDocument($employeeB, ['category' => 'other']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->update(
            'update employee_documents set category = ? where id = ?',
            ['id_proof', $documentB->id],
        );

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function raw_delete_of_a_document_across_schools_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $documentB = $this->createEmployeeDocument($employeeB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $affected = DB::connection('pgsql')->delete('delete from employee_documents where id = ?', [$documentB->id]);

        $this->assertSame(0, $affected);
    }
}
