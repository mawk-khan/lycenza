<?php

namespace Tests\Feature\Postgres;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TCH.1 (ADR 0063 section 5): the status-constraint migration refuses --
 * never repairs -- a legacy row outside the closed catalogue. Proven on
 * the admin connection inside ONE transaction that is always rolled back:
 * the constraint is dropped, a bad row inserted, and the real migration's
 * up() run; it must throw and leave the row untouched.
 *
 * Non-transactional on the runtime connection (it takes an ACCESS
 * EXCLUSIVE lock on employees/employment_records through the admin
 * connection, which an open runtime transaction must not contend with).
 */
class HrIdentityStatusMigrationRefusalTest extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_03_090000_constrain_hr_identity_statuses.php');
    }

    /** @param  callable(Connection): void  $plantBadRow */
    private function assertRefusesWith(string $table, string $constraint, callable $plantBadRow, string $rowId): void
    {
        $admin = DB::connection('pgsql_admin');
        $default = DB::getDefaultConnection();
        $admin->beginTransaction();

        try {
            // Back to the pre-migration schema, then one bad legacy row.
            $admin->statement('ALTER TABLE employees DROP CONSTRAINT employees_record_status_check');
            $admin->statement('ALTER TABLE employment_records DROP CONSTRAINT employment_records_status_check');
            $plantBadRow($admin);

            DB::setDefaultConnection('pgsql_admin');
            try {
                $this->migration()->up();
                $this->fail("The migration accepted a {$table} row outside the catalogue.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString("{$constraint}: refusing", $e->getMessage());
                $this->assertStringContainsString('No row was changed', $e->getMessage());
            } finally {
                DB::setDefaultConnection($default);
            }

            // The failed ALTER aborted the admin transaction; nothing ran after it.
            $admin->rollBack();
            $admin->beginTransaction();
        } finally {
            $admin->rollBack();
        }

        $this->assertSame(0, $admin->table($table)->where('id', $rowId)->count(), 'The planted row existed only inside the rolled-back transaction.');
        $this->assertSame(1, (int) $admin->selectOne('select count(*) as c from pg_constraint where conname = ?', [$constraint])->c, 'The constraint is back.');
    }

    #[Test]
    public function a_legacy_employee_record_status_makes_the_migration_refuse(): void
    {
        $schoolId = (string) Str::uuid7();
        $employeeId = (string) Str::uuid7();

        $this->assertRefusesWith('employees', 'employees_record_status_check', function ($admin) use ($schoolId, $employeeId): void {
            $admin->table('schools')->insert(['id' => $schoolId, 'name' => 'Legacy School', 'slug' => 'legacy-'.Str::lower(Str::random(8)), 'status' => 'provisioning', 'created_at' => now(), 'updated_at' => now()]);
            $admin->table('employees')->insert(['id' => $employeeId, 'school_id' => $schoolId, 'employee_number' => 'EMP-000001', 'full_name' => 'Legacy', 'record_status' => 'inactive', 'created_at' => now(), 'updated_at' => now()]);
        }, $employeeId);
    }

    #[Test]
    public function a_legacy_employment_status_makes_the_migration_refuse(): void
    {
        $schoolId = (string) Str::uuid7();
        $employeeId = (string) Str::uuid7();
        $recordId = (string) Str::uuid7();

        $this->assertRefusesWith('employment_records', 'employment_records_status_check', function ($admin) use ($schoolId, $employeeId, $recordId): void {
            $admin->table('schools')->insert(['id' => $schoolId, 'name' => 'Legacy School', 'slug' => 'legacy-'.Str::lower(Str::random(8)), 'status' => 'provisioning', 'created_at' => now(), 'updated_at' => now()]);
            $admin->table('employees')->insert(['id' => $employeeId, 'school_id' => $schoolId, 'employee_number' => 'EMP-000001', 'full_name' => 'Legacy', 'record_status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $admin->table('employment_records')->insert(['id' => $recordId, 'school_id' => $schoolId, 'employee_id' => $employeeId, 'employment_type' => 'permanent', 'starts_on' => '2026-01-01', 'status' => 'employed', 'created_at' => now(), 'updated_at' => now()]);
        }, $recordId);
    }
}
