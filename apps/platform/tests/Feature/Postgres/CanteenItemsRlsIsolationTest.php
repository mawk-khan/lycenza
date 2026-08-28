<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F (CLAUDE.md rule 28). Also proves the case-insensitive code
 * uniqueness index and the `price >= 0` CHECK constraint.
 */
class CanteenItemsRlsIsolationTest extends TestCase
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
            ['canteen_items', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createCanteenItem($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from canteen_items')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_item(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createCanteenItem($schoolB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from canteen_items where id = ?', [$itemB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_item(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createCanteenItem($schoolB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update('update canteen_items set status = ? where id = ?', ['inactive', $itemB->id]);
        $this->assertSame(0, $updated);
    }

    #[Test]
    public function the_database_rejects_a_duplicate_code_case_insensitively(): void
    {
        $school = $this->createSchool();
        $this->createCanteenItem($school, ['code' => 'SAMOSA']);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_items')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'code' => 'samosa',
                'name' => 'Dup',
                'price' => '10.00',
                'currency' => 'INR',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_items_school_id_code_ci_unique must reject a case-insensitive duplicate code.');
    }

    #[Test]
    public function the_database_rejects_a_negative_price(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_items')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'code' => 'NEG',
                'name' => 'Negative',
                'price' => '-1.00',
                'currency' => 'INR',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_items_price_non_negative_check must reject a negative price.');
    }

    #[Test]
    public function the_database_rejects_a_non_inr_currency(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_items')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'code' => 'USD1',
                'name' => 'USD Item',
                'price' => '10.00',
                'currency' => 'USD',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_items_currency_inr_only_check must reject a non-INR currency.');
    }
}
