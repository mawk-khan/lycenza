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
 * TCH.1 (ADR 0063 section 5): the ActingEmployee inputs the DATABASE
 * guarantees, proven with raw SQL on the runtime connection -- the closed
 * `employees.record_status` and `employment_records.status` catalogues,
 * one User per Employee per School, and RLS keeping the link School-owned.
 */
class HrIdentityStatusConstraintTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function inSchool(string $schoolId): void
    {
        DB::select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function assertRefused(callable $statement, string $fragment): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());

            return;
        }

        $this->fail("PostgreSQL accepted a statement it must refuse ({$fragment}).");
    }

    #[Test]
    public function employee_record_status_is_the_closed_set(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->inSchool($school->id);

        foreach (['suspended', 'ACTIVE', '', 'deleted'] as $bad) {
            $this->assertRefused(fn () => DB::table('employees')->where('id', $employee->id)->update(['record_status' => $bad]), 'employees_record_status_check');
        }

        foreach (['archived', 'active'] as $good) {
            DB::table('employees')->where('id', $employee->id)->update(['record_status' => $good]);
        }
        $this->assertSame('active', DB::table('employees')->where('id', $employee->id)->value('record_status'));
    }

    #[Test]
    public function employment_status_is_the_closed_catalogue(): void
    {
        $school = $this->createSchool();
        $record = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2026-01-01', 'status' => 'active']);
        $this->inSchool($school->id);

        foreach (['employed', 'Active', 'on_leave', ''] as $bad) {
            $this->assertRefused(fn () => DB::table('employment_records')->where('id', $record->id)->update(['status' => $bad]), 'employment_records_status_check');
        }

        foreach (['draft', 'pre_joining', 'active', 'notice_period', 'separated', 'terminated', 'retired', 'deceased'] as $good) {
            DB::table('employment_records')->where('id', $record->id)->update(['status' => $good]);
        }
        $this->assertSame('deceased', DB::table('employment_records')->where('id', $record->id)->value('status'));
    }

    #[Test]
    public function one_user_is_linked_to_at_most_one_employee_per_school_but_may_be_one_at_each_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $this->createEmployee($schoolA, ['user_id' => $user->id]);
        $secondA = $this->createEmployee($schoolA, ['user_id' => null]);
        $this->createEmployee($schoolB, ['user_id' => $user->id]);

        $this->inSchool($schoolA->id);
        $this->assertRefused(fn () => DB::table('employees')->where('id', $secondA->id)->update(['user_id' => $user->id]), 'employees_school_id_user_id_unique');
    }

    #[Test]
    public function a_school_context_can_neither_read_nor_relink_another_schools_employee(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $employeeB = $this->createEmployee($schoolB, ['user_id' => null]);

        $this->inSchool($schoolA->id);
        $this->assertSame(0, DB::table('employees')->where('id', $employeeB->id)->count());
        $this->assertSame(0, DB::table('employees')->where('id', $employeeB->id)->update(['user_id' => $user->id]));

        $this->inSchool($schoolB->id);
        $this->assertNull(DB::table('employees')->where('id', $employeeB->id)->value('user_id'));
    }

    #[Test]
    public function both_constraints_exist_as_validated_checks(): void
    {
        $rows = DB::connection('pgsql_admin')->select(
            "select conname, convalidated, pg_get_constraintdef(oid) as def from pg_constraint where conname in ('employees_record_status_check', 'employment_records_status_check') order by conname"
        );

        $this->assertSame(['employees_record_status_check', 'employment_records_status_check'], array_column($rows, 'conname'));
        foreach ($rows as $row) {
            $this->assertTrue($row->convalidated);
        }
        $this->assertStringContainsString("'notice_period'", $rows[1]->def);
        $this->assertSame(4, Str::substrCount($rows[0]->def, "'"), 'Exactly two record_status values.');
    }
}
