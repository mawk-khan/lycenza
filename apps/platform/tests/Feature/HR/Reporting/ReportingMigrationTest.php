<?php

namespace Tests\Feature\HR\Reporting;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.3A: the cut-over migration -- the conservative backfill from the
 * removed `manager_assignment_id` pointer, its refusal of ambiguous legacy
 * data, and the documented rollback (lines current today become pointers).
 *
 * Run on the admin connection inside ONE transaction that is always rolled
 * back, so the real schema is never changed (the LmsOwnershipMigration-
 * RollbackTest pattern). Non-transactional on the runtime connection: the
 * fixtures must be COMMITTED for the admin DDL to see them.
 */
class ReportingMigrationTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        parent::tearDown();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2027_01_26_090000_create_reporting_relationships.php');
    }

    /** Runs $callback with the migration connection as default, inside a transaction that is always rolled back. */
    private function onAdminAndRollBack(callable $callback): void
    {
        $admin = DB::connection('pgsql_admin');
        $default = DB::getDefaultConnection();
        $admin->beginTransaction();
        DB::setDefaultConnection('pgsql_admin');

        try {
            $callback();
        } finally {
            DB::setDefaultConnection($default);
            $admin->rollBack();
        }
    }

    /** @return array{0: EmploymentRecord, 1: EmployeeAssignment} */
    private function staff(string $startsOn = '2024-01-01'): array
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => $startsOn, 'ends_on' => null, 'status' => 'active']);
        $assignment = $this->createEmployeeAssignment($employment, $this->createPosition($this->school), ['starts_on' => $startsOn, 'ends_on' => null, 'is_primary' => true]);

        return [$employment, $assignment];
    }

    private function point(EmployeeAssignment $subordinate, ?EmployeeAssignment $manager): void
    {
        DB::connection('pgsql_admin')->table('employee_assignments')->where('id', $subordinate->id)->update(['manager_assignment_id' => $manager?->id]);
    }

    /** @return list<object> */
    private function lines(): array
    {
        return DB::connection('pgsql_admin')->table('reporting_relationships')->where('school_id', $this->school->id)
            ->orderBy('subordinate_employment_id')->get(['subordinate_employment_id', 'manager_employment_id', 'starts_on', 'ends_on', 'started_via', 'start_reason', 'started_by_user_id'])->all();
    }

    #[Test]
    public function the_backfill_starts_each_current_pointer_on_the_cut_over_date_and_fabricates_no_history(): void
    {
        $this->school = $this->createSchool();
        [$managerEmployment, $manager] = $this->staff();
        [$subEmployment, $sub] = $this->staff();
        $this->staff(); // no pointer: no line
        [, $ended] = $this->staff();
        $today = SchoolTimezone::today($this->school);

        $this->onAdminAndRollBack(function () use ($manager, $sub, $managerEmployment, $subEmployment, $ended, $today) {
            $this->migration()->down();
            $this->assertTrue(Schema::connection('pgsql_admin')->hasColumn('employee_assignments', 'manager_assignment_id'));
            $this->point($sub, $manager);
            // A pointer to an assignment that ended is no current line (the old reader gave none).
            DB::connection('pgsql_admin')->table('employee_assignments')->where('id', $ended->id)->update(['ends_on' => '2024-06-30']);
            $this->point($manager, $ended);

            $this->migration()->up();

            $this->assertFalse(Schema::connection('pgsql_admin')->hasColumn('employee_assignments', 'manager_assignment_id'));
            $lines = $this->lines();
            $this->assertCount(1, $lines);
            $this->assertSame([$subEmployment->id, $managerEmployment->id, $today, null, 'backfill', null, null], array_values((array) $lines[0]), 'from the cut-over date only: the overwritten pointer never told when it began');
        });
    }

    #[Test]
    public function conflicting_or_future_legacy_pointers_refuse_the_migration_and_change_nothing(): void
    {
        $this->school = $this->createSchool();
        [, $m1] = $this->staff();
        [, $m2] = $this->staff();
        [$subEmployment, $sub] = $this->staff();
        $secondary = $this->createEmployeeAssignment($subEmployment, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => false]);

        $this->onAdminAndRollBack(function () use ($m1, $m2, $sub, $secondary) {
            $this->migration()->down();
            $this->point($sub, $m1);
            $this->point($secondary, $m2);

            try {
                $this->migration()->up();
                $this->fail('ambiguous legacy data was migrated');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('refused (nothing changed)', $e->getMessage());
                $this->assertStringContainsString('conflicting managers', $e->getMessage());
            }
            $this->assertFalse(Schema::connection('pgsql_admin')->hasTable('reporting_relationships'), 'nothing was created');
            $this->assertTrue(Schema::connection('pgsql_admin')->hasColumn('employee_assignments', 'manager_assignment_id'), 'the pointer is still there');
        });
    }

    #[Test]
    public function rollback_keeps_the_lines_current_today_as_pointers(): void
    {
        $this->school = $this->createSchool();
        [$managerEmployment, $manager] = $this->staff();
        [$subEmployment, $sub] = $this->staff();
        [$futureEmployment] = $this->staff();
        [$laterEmployment, $later] = $this->staff();
        $this->setReportingLineFixture($subEmployment, $managerEmployment, '2025-01-01');
        $this->setReportingLineFixture($laterEmployment, $futureEmployment, '2099-01-01');

        $this->onAdminAndRollBack(function () use ($sub, $manager, $later) {
            $this->migration()->down();

            $pointers = DB::connection('pgsql_admin')->table('employee_assignments')->whereIn('id', [$sub->id, $later->id])->pluck('manager_assignment_id', 'id');
            $this->assertSame($manager->id, $pointers[$sub->id], 'the line current today');
            $this->assertNull($pointers[$later->id], 'a future line has no pointer form');
            $this->assertFalse(Schema::connection('pgsql_admin')->hasTable('reporting_relationships'));
        });
    }
}
