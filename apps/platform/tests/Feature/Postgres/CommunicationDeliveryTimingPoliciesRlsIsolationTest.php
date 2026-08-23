<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.9 §45 (mandatory per root CLAUDE.md rule 28), mirroring
 * CommunicationChannelPoliciesRlsIsolationTest's exact shape.
 */
class CommunicationDeliveryTimingPoliciesRlsIsolationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

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
            ['communication_delivery_timing_policies', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createDeliveryTimingPolicy($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_delivery_timing_policies')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_policy(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $policyB = $this->createDeliveryTimingPolicy($schoolB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_delivery_timing_policies where id = ?', [$policyB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_policy_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($schoolB): void {
                DB::connection('pgsql')->table('communication_delivery_timing_policies')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'channel' => 'email',
                    'enabled' => true,
                    'quiet_hours_start' => '20:00:00',
                    'quiet_hours_end' => '07:00:00',
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
    public function school_a_cannot_update_school_bs_policy(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $policyB = $this->createDeliveryTimingPolicy($schoolB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update communication_delivery_timing_policies set enabled = false where id = ?',
            [$policyB->id],
        );

        $this->assertSame(0, $updated);
    }
}
