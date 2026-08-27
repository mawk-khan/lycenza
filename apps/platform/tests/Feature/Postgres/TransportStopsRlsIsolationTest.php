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
 * Phase 10B (mandatory per root CLAUDE.md rule 28).
 */
class TransportStopsRlsIsolationTest extends TestCase
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
            ['transport_stops', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $route = $this->createTransportRoute($school);
        $this->createTransportStop($route);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from transport_stops')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_stop(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);
        $stopB = $this->createTransportStop($routeB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from transport_stops where id = ?', [$stopB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_stop_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($schoolB, $routeB): void {
                DB::connection('pgsql')->table('transport_stops')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'route_id' => $routeB->id,
                    'name' => 'Cross-School Stop',
                    'sequence' => 1,
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
    public function school_a_cannot_update_school_bs_stop(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);
        $stopB = $this->createTransportStop($routeB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update transport_stops set status = ? where id = ?',
            ['inactive', $stopB->id],
        );

        $this->assertSame(0, $updated);
    }

    /**
     * The composite-FK proof (checkpoint brief section 8): a Stop
     * cannot reference a `route_id` belonging to a DIFFERENT School
     * than its own `school_id`, even under a raw privileged
     * (`pgsql_admin`) INSERT that bypasses RLS entirely.
     */
    #[Test]
    public function a_stop_cannot_reference_a_route_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('transport_stops')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'route_id' => $routeB->id,
                'name' => 'Cross-FK Stop',
                'sequence' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (route_id, school_id) -> transport_routes(id, school_id) must reject a cross-School Route reference at the database level.');
    }
}
