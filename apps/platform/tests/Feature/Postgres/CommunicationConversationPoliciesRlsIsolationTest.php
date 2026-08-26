<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.1 §39/§50 -- raw-SQL RLS proof for the new
 * `communication_conversation_policies` table, mirroring
 * CommunicationsRlsIsolationTest's exact pattern (root CLAUDE.md rule
 * 28/39, mandatory for every new tenant-owned table).
 */
class CommunicationConversationPoliciesRlsIsolationTest extends TestCase
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
            ['communication_conversation_policies', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_conversation_policies')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_read_school_bs_policy_row(): void
    {
        $schoolA = $this->createSchool();
        [, $schoolB] = $this->createSchoolAdmin('school_admin');
        $policyB = $this->createConversationPolicy($schoolB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_conversation_policies where id = ?', [$policyB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        [, $schoolB] = $this->createSchoolAdmin('school_admin');
        $policyB = $this->createConversationPolicy($schoolB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            'update communication_conversation_policies set allow_guardian_conversations = false where id = ?',
            [$policyB->id],
        ));
    }
}
