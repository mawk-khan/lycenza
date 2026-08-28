<?php

namespace Tests\Feature\Postgres;

use App\Models\Campus;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F (CLAUDE.md rule 28). Also proves the composite-FK
 * cross-School denials and the campus-consistency structural
 * enforcement (create_canteen_outlets_table migration's own docblock).
 */
class CanteenOutletsRlsIsolationTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

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
            ['canteen_outlets', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $this->createCanteenOutlet($school, $location);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from canteen_outlets')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_outlet(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);
        $outletB = $this->createCanteenOutlet($schoolB, $locationB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from canteen_outlets where id = ?', [$outletB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_outlet(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);
        $outletB = $this->createCanteenOutlet($schoolB, $locationB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update('update canteen_outlets set status = ? where id = ?', ['inactive', $outletB->id]);
        $this->assertSame(0, $updated);
    }

    #[Test]
    public function an_outlet_cannot_reference_a_location_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_outlets')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'campus_id' => null,
                'inventory_location_id' => $locationB->id,
                'code' => 'X1',
                'name' => 'X',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_outlets_location_fk must reject a cross-School Location reference.');
    }

    #[Test]
    public function an_outlet_cannot_reference_a_campus_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationA = $this->createInventoryLocation($schoolA);
        $campusB = $this->createCampus($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_outlets')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'campus_id' => $campusB->id,
                'inventory_location_id' => $locationA->id,
                'code' => 'X2',
                'name' => 'X',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The plain campus_id FK must reject a cross-School Campus reference.');
    }

    #[Test]
    public function an_outlet_naming_a_campus_that_does_not_match_its_locations_campus_is_rejected_at_the_database_level(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        [$campusA, $campusB, $location] = $context->withSchool($school, function () use ($school) {
            $a = Campus::factory()->for($school)->create();
            $b = Campus::factory()->for($school)->create();
            $loc = $this->createInventoryLocation($school, ['campus_id' => $a->id]);

            return [$a, $b, $loc];
        });

        // The rejection check uses `pgsql_admin` (the superuser
        // connection, bypassing RLS) so an INSERT that FK-violates
        // resolves immediately -- Postgres's FK check on a tuple that
        // can NEVER match (mismatched campus) returns "not found"
        // without needing to wait on any other transaction.
        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_outlets')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'campus_id' => $campusB->id,
                'inventory_location_id' => $location->id,
                'code' => 'MISMATCH',
                'name' => 'Mismatch',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_outlets_location_campus_fk must reject an Outlet naming a Campus that does not match its Location\'s own Campus.');

        // The two SUCCESS cases below deliberately use the SAME
        // ('pgsql', RLS-scoped) connection and transaction the
        // fixtures above were created on -- unlike the rejection case
        // above, a row Postgres's FK check needs to find MUST actually
        // be visible to succeed; a separate `pgsql_admin` SESSION
        // cannot see this test's still-uncommitted fixture rows (this
        // test runs inside DatabaseTransactions' single outer
        // transaction, never committed until teardown), and Postgres's
        // FK checker would BLOCK indefinitely waiting to find out
        // whether that other, unrelated transaction commits.
        $this->setSchool($school->id);

        DB::connection('pgsql')->table('canteen_outlets')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $school->id,
            'campus_id' => $campusA->id,
            'inventory_location_id' => $location->id,
            'code' => 'MATCH',
            'name' => 'Match',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('canteen_outlets', ['code' => 'MATCH']);

        // A null-campus Outlet against the same Location must also succeed
        // (the second FK is skipped entirely when campus_id IS NULL).
        DB::connection('pgsql')->table('canteen_outlets')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $school->id,
            'campus_id' => null,
            'inventory_location_id' => $location->id,
            'code' => 'NULLCAMPUS',
            'name' => 'Null Campus',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('canteen_outlets', ['code' => 'NULLCAMPUS']);
    }

    #[Test]
    public function the_database_rejects_a_duplicate_code_case_insensitively(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $this->createCanteenOutlet($school, $location, ['code' => 'CANTEEN1']);

        // Same ('pgsql') connection/transaction as the fixture above --
        // a UNIQUE index conflict check, like an FK check, must wait to
        // resolve concurrent uncommitted transactions holding a
        // possibly-conflicting key; a separate `pgsql_admin` session
        // cannot see this test's still-uncommitted fixture row.
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_outlets')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'campus_id' => null,
                'inventory_location_id' => $location->id,
                'code' => 'canteen1',
                'name' => 'Dup',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_outlets_school_id_code_ci_unique must reject a case-insensitive duplicate code.');
    }

    #[Test]
    public function a_referenced_outlet_cannot_be_deleted_at_the_raw_sql_level(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $this->createCanteenOrderRaw($school, $student, $outlet);

        // Deliberately the SAME ('pgsql') connection/transaction the
        // fixtures above were created on -- a separate `pgsql_admin`
        // session cannot see this test's still-uncommitted Order row,
        // which would make the FK RESTRICT check find nothing to
        // block on (a false negative), not a genuine proof. See the
        // campus-consistency test above for the identical reasoning.
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->delete('delete from canteen_outlets where id = ?', [$outlet->id]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_outlet_fk RESTRICT must reject deleting an Outlet a real Order still references.');
    }
}
