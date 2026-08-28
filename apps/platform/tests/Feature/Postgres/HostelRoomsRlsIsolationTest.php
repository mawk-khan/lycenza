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
class HostelRoomsRlsIsolationTest extends TestCase
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
            ['hostel_rooms', 'public'],
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
        $hostel = $this->createHostel($school, $campus);
        $this->createHostelRoom($hostel);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from hostel_rooms')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_room(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from hostel_rooms where id = ?', [$roomB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_room_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($schoolB, $hostelB): void {
                DB::connection('pgsql')->table('hostel_rooms')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'hostel_id' => $hostelB->id,
                    'code' => 'CROSS-1',
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
    public function school_a_cannot_update_school_bs_room(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update hostel_rooms set status = ? where id = ?',
            ['inactive', $roomB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function a_room_cannot_reference_a_hostel_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('hostel_rooms')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'hostel_id' => $hostelB->id,
                'code' => 'CROSS-FK-1',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (hostel_id, school_id) -> hostels(id, school_id) must reject a cross-School Hostel reference at the database level.');
    }

    /**
     * THE historical-integrity proof for this level of the hierarchy:
     * `hostel_beds.hostel_room_id` RESTRICTs on delete -- a Room
     * referenced by any Bed (which may itself carry residency history)
     * cannot be physically deleted.
     */
    #[Test]
    public function a_room_referenced_by_a_bed_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($room): void {
                DB::connection('pgsql')->table('hostel_rooms')->where('id', $room->id)->delete();
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'hostel_beds_hostel_room_id_school_id_foreign must RESTRICT deletion of a Room referenced by a Bed.');

        $roomStillExists = DB::connection('pgsql')->table('hostel_rooms')->where('id', $room->id)->exists();
        $this->assertTrue($roomStillExists);

        $bedStillExists = DB::connection('pgsql')->table('hostel_beds')->where('id', $bed->id)->exists();
        $this->assertTrue($bedStillExists);
    }
}
