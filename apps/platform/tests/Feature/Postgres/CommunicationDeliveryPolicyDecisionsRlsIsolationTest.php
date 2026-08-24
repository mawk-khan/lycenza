<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.5 §20/§33 (mandatory per root CLAUDE.md rule 28). Mirrors
 * CommunicationAnnouncementsRlsIsolationTest's append-only proof
 * pattern for communication_announcement_recipients.
 */
class CommunicationDeliveryPolicyDecisionsRlsIsolationTest extends TestCase
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
            ['communication_delivery_policy_decisions', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->createThread($school, $creator);
        $message = $this->createMessage($thread, $creator);
        $this->createPolicyDecision($school, $message->id, $creator);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_delivery_policy_decisions')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_decisions(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $threadB = $this->createThread($schoolB, $creatorB);
        $messageB = $this->createMessage($threadB, $creatorB);
        $decisionB = $this->createPolicyDecision($schoolB, $messageB->id, $creatorB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_delivery_policy_decisions where id = ?', [$decisionB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function the_runtime_role_cannot_update_or_delete_a_decision(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->createThread($school, $creator);
        $message = $this->createMessage($thread, $creator);
        $decision = $this->createPolicyDecision($school, $message->id, $creator);

        $this->setSchool($school->id);

        try {
            DB::connection('pgsql')->transaction(function () use ($decision): void {
                DB::connection('pgsql')->table('communication_delivery_policy_decisions')
                    ->where('id', $decision->id)->update(['reason' => 'recipient_ineligible']);
            });
            $this->fail('Expected a QueryException: runtime role must not be able to UPDATE policy decisions.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }

        try {
            DB::connection('pgsql')->transaction(function () use ($decision): void {
                DB::connection('pgsql')->table('communication_delivery_policy_decisions')->where('id', $decision->id)->delete();
            });
            $this->fail('Expected a QueryException: runtime role must not be able to DELETE policy decisions.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }
    }
}
