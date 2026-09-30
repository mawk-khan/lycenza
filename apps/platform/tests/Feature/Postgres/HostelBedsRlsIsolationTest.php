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
class HostelBedsRlsIsolationTest extends TestCase
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
            ['hostel_beds', 'public'],
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
        $room = $this->createHostelRoom($hostel);
        $this->createHostelBed($room);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from hostel_beds')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_bed(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);
        $bedB = $this->createHostelBed($roomB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from hostel_beds where id = ?', [$bedB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_bed_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($schoolB, $roomB): void {
                DB::connection('pgsql')->table('hostel_beds')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'hostel_room_id' => $roomB->id,
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
    public function school_a_cannot_update_school_bs_bed(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);
        $bedB = $this->createHostelBed($roomB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update hostel_beds set status = ? where id = ?',
            ['inactive', $bedB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function a_bed_cannot_reference_a_room_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('hostel_beds')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'hostel_room_id' => $roomB->id,
                'code' => 'CROSS-FK-1',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (hostel_room_id, school_id) -> hostel_rooms(id, school_id) must reject a cross-School Room reference at the database level.');
    }

    /**
     * THE historical-integrity proof for this level of the hierarchy:
     * `hostel_residency_assignments.hostel_bed_id` RESTRICTs on
     * delete -- a Bed referenced by any residency assignment (active
     * or historical) cannot be physically deleted.
     */
    #[Test]
    public function a_bed_referenced_by_a_residency_assignment_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school);
        // One timestamp for both ends: two now() calls can straddle a second
        // and break hostel_residency_assignments_end_after_start_check.
        $at = now();
        $assignment = $this->createHostelResidencyAssignment($student, $bed, ['status' => 'ended', 'starts_on' => $at, 'ends_on' => $at]);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($bed): void {
                DB::connection('pgsql')->table('hostel_beds')->where('id', $bed->id)->delete();
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'hostel_residency_assignments_hostel_bed_id_school_id_foreign must RESTRICT deletion of a Bed referenced by a residency assignment.');

        $bedStillExists = DB::connection('pgsql')->table('hostel_beds')->where('id', $bed->id)->exists();
        $this->assertTrue($bedStillExists);

        $assignmentStillExists = DB::connection('pgsql')->table('hostel_residency_assignments')->where('id', $assignment->id)->exists();
        $this->assertTrue($assignmentStillExists);
    }
}
