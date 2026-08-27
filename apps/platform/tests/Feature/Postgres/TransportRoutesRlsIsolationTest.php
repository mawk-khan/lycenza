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
 * Phase 10B (mandatory per root CLAUDE.md rule 28), mirroring
 * LibraryTitlesRlsIsolationTest's exact pattern.
 */
class TransportRoutesRlsIsolationTest extends TestCase
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
            ['transport_routes', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createTransportRoute($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from transport_routes')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_route(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from transport_routes where id = ?', [$routeB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_route_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($schoolB): void {
                DB::connection('pgsql')->table('transport_routes')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'code' => 'CROSS-1',
                    'name' => 'Cross-School Route',
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
    public function school_a_cannot_update_school_bs_route(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update transport_routes set status = ? where id = ?',
            ['inactive', $routeB->id],
        );

        $this->assertSame(0, $updated);
    }

    /**
     * The composite-FK proof: a Route cannot reference a `campus_id`
     * belonging to a DIFFERENT School than its own `school_id`, even
     * under a raw privileged (`pgsql_admin`) INSERT that bypasses RLS
     * entirely.
     */
    #[Test]
    public function a_route_cannot_reference_a_campus_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('transport_routes')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'campus_id' => $campusB->id,
                'code' => 'CROSS-FK-1',
                'name' => 'Cross-FK Route',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (campus_id, school_id) -> campuses(id, school_id) must reject a cross-School Campus reference at the database level.');
    }
}
