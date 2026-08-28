<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10D (mandatory per root CLAUDE.md rule 28).
 */
class HostelsRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

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
            ['hostels', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $this->createHostel($school, $campus);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from hostels')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_hostel(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from hostels where id = ?', [$hostelB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_hostel_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($schoolB, $campusB): void {
                DB::connection('pgsql')->table('hostels')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'campus_id' => $campusB->id,
                    'code' => 'CROSS-1',
                    'name' => 'Cross-School Hostel',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'RLS WITH CHECK must reject a row written under School A context but claiming School B.');
    }

    #[Test]
    public function school_a_cannot_update_school_bs_hostel(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update hostels set status = ? where id = ?',
            ['inactive', $hostelB->id],
        );

        $this->assertSame(0, $updated);
    }

    /**
     * A Hostel cannot reference a Campus from a different School --
     * the composite FK `(campus_id, school_id) -> campuses(id, school_id)`.
     */
    #[Test]
    public function a_hostel_cannot_reference_a_campus_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('hostels')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'campus_id' => $campusB->id,
                'code' => 'CROSS-FK-1',
                'name' => 'Cross-FK Hostel',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (campus_id, school_id) -> campuses(id, school_id) must reject a cross-School Campus reference at the database level.');
    }

    /**
     * THE historical-integrity proof: `hostel_rooms.hostel_id`
     * RESTRICTs on delete (docs/modules/HOSTEL.md "Historical FK
     * deletion semantics") -- a Hostel referenced by any Room (which
     * may itself carry residency history several levels down) cannot
     * be physically deleted. Uses the SAME `pgsql` connection/session
     * as the fixture writes above (never `pgsql_admin`) -- mirrors
     * VisitorsRlsIsolationTest::a_visitor_referenced_by_a_historical_visit_cannot_be_deleted's
     * exact pattern and the lesson it documents: a separate connection
     * cannot see this test's still-uncommitted fixture rows, which
     * would make the delete silently "succeed" against a row it never
     * actually saw a reference to.
     */
    #[Test]
    public function a_hostel_referenced_by_a_room_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($hostel): void {
                DB::connection('pgsql')->table('hostels')->where('id', $hostel->id)->delete();
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'hostel_rooms_hostel_id_school_id_foreign must RESTRICT deletion of a Hostel referenced by a Room.');

        $hostelStillExists = DB::connection('pgsql')->table('hostels')->where('id', $hostel->id)->exists();
        $this->assertTrue($hostelStillExists);

        $roomStillExists = DB::connection('pgsql')->table('hostel_rooms')->where('id', $room->id)->exists();
        $this->assertTrue($roomStillExists);
    }
}
