<?php

namespace Tests\Feature\Postgres;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
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
 * Phase 10F (CLAUDE.md rule 28). Also proves both composite-FK
 * cross-School+currency denials, the `unique(school_id)` singleton
 * constraint, and the distinct-accounts CHECK constraint.
 */
class CanteenBillingConfigurationsRlsIsolationTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function ledgerAccounts(School $school): array
    {
        return app(TenantContext::class)->withSchool($school, function () use ($school) {
            $receivable = LedgerAccount::factory()->create(['school_id' => $school->id, 'type' => 'asset']);
            $revenue = LedgerAccount::factory()->create(['school_id' => $school->id, 'type' => 'income']);

            return [$receivable, $revenue];
        });
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['canteen_billing_configurations', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        [$receivable, $revenue] = $this->ledgerAccounts($school);
        $this->createCanteenBillingConfigurationRaw($school, $receivable, $revenue);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from canteen_billing_configurations')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_configuration(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        [$receivableB, $revenueB] = $this->ledgerAccounts($schoolB);
        $configB = $this->createCanteenBillingConfigurationRaw($schoolB, $receivableB, $revenueB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from canteen_billing_configurations where id = ?', [$configB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function a_configuration_cannot_reference_a_receivable_account_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        [, $revenueA] = $this->ledgerAccounts($schoolA);
        [$receivableB] = $this->ledgerAccounts($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_billing_configurations')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'receivable_ledger_account_id' => $receivableB->id,
                'revenue_ledger_account_id' => $revenueA->id,
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_billing_config_receivable_fk must reject a cross-School receivable LedgerAccount reference.');
    }

    #[Test]
    public function a_configuration_cannot_reference_a_revenue_account_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        [$receivableA] = $this->ledgerAccounts($schoolA);
        [, $revenueB] = $this->ledgerAccounts($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_billing_configurations')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'receivable_ledger_account_id' => $receivableA->id,
                'revenue_ledger_account_id' => $revenueB->id,
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_billing_config_revenue_fk must reject a cross-School revenue LedgerAccount reference.');
    }

    #[Test]
    public function the_database_rejects_a_second_configuration_row_for_the_same_school(): void
    {
        $school = $this->createSchool();
        [$receivable, $revenue] = $this->ledgerAccounts($school);
        $this->createCanteenBillingConfigurationRaw($school, $receivable, $revenue);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_billing_configurations')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'receivable_ledger_account_id' => $receivable->id,
                'revenue_ledger_account_id' => $revenue->id,
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'unique(school_id) must reject a second configuration row for the same School.');
    }

    #[Test]
    public function the_database_rejects_identical_receivable_and_revenue_accounts(): void
    {
        $school = $this->createSchool();
        [$receivable] = $this->ledgerAccounts($school);
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_billing_configurations')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'receivable_ledger_account_id' => $receivable->id,
                'revenue_ledger_account_id' => $receivable->id,
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_billing_config_distinct_accounts_check must reject identical receivable/revenue accounts.');
    }
}
