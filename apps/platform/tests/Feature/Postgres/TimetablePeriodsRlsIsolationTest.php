<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H (Timetable foundation), mandatory per root CLAUDE.md rule
 * 28. Also proves `timetable_periods_school_id_code_ci_unique`'s
 * database-level case-insensitive uniqueness and
 * `timetable_periods_start_before_end_check`'s CHECK constraint --
 * both immune to any caller bypassing the Eloquent model layer.
 * Mirrors InventoryLocationsRlsIsolationTest.php's exact pattern.
 */
class TimetablePeriodsRlsIsolationTest extends TestCase
{
    use CreatesTimetableFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['timetable_periods', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createTimetablePeriod($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from timetable_periods')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_period(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $periodB = $this->createTimetablePeriod($schoolB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from timetable_periods where id = ?', [$periodB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_period(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $periodB = $this->createTimetablePeriod($schoolB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update timetable_periods set status = ? where id = ?',
            ['inactive', $periodB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function period_code_is_case_insensitively_unique_at_the_database_level(): void
    {
        $school = $this->createSchool();
        $this->createTimetablePeriod($school, ['code' => 'MATH']);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('timetable_periods')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'code' => 'math',
                'name' => 'Duplicate',
                'start_time' => '11:00:00',
                'end_time' => '11:45:00',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_periods_school_id_code_ci_unique must reject a case-different duplicate code for the same School.');
    }

    #[Test]
    public function a_different_school_may_reuse_the_same_period_code_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createTimetablePeriod($schoolA, ['code' => 'MATH']);

        $this->setSchool($schoolB->id);

        $inserted = DB::connection('pgsql')->table('timetable_periods')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $schoolB->id,
            'code' => 'MATH',
            'name' => 'Reused Code',
            'start_time' => '11:00:00',
            'end_time' => '11:45:00',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($inserted);
    }

    #[Test]
    public function start_time_must_be_before_end_time_at_the_database_level(): void
    {
        $school = $this->createSchool();

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('timetable_periods')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'code' => 'BAD',
                'name' => 'Invalid Range',
                'start_time' => '10:00:00',
                'end_time' => '09:00:00',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_periods_start_before_end_check must reject start_time >= end_time.');
    }
}
