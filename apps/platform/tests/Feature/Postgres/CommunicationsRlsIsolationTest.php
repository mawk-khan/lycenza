<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsRuntimeDeleteRevoked;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.1 §3 / root CLAUDE.md rule 28 (mandatory): every Communication
 * Hub table, proven at the raw-SQL level against real PostgreSQL,
 * independent of Eloquent -- mirrors AcademicStructureRlsIsolationTest's
 * pattern exactly.
 */
class CommunicationsRlsIsolationTest extends TestCase
{
    use AssertsRuntimeDeleteRevoked, CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private const TABLES = [
        'communication_threads', 'communication_thread_participants',
        'communication_messages', 'communication_recipients',
        'communication_deliveries', 'communication_delivery_attempts',
    ];

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function every_communications_table_has_rls_enabled_and_forced(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class '.
                'where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertNotNull($row, "{$table} must exist");
            $this->assertTrue($row->relrowsecurity, "{$table} must have RLS enabled");
            $this->assertTrue($row->relforcerowsecurity, "{$table} must FORCE RLS");
        }
    }

    #[Test]
    public function no_school_context_sees_zero_rows_on_every_communications_table(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);

        $thread = $this->createThread($school, $sender);
        $this->createParticipant($thread, $sender);
        $this->createParticipant($thread, $recipientUser);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient);
        $this->createDeliveryAttempt($delivery);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        foreach (self::TABLES as $table) {
            $count = DB::connection('pgsql')->selectOne("select count(*) as c from {$table}")->c;
            $this->assertSame(0, (int) $count, "{$table} must be invisible with no TenantContext");
        }
    }

    #[Test]
    public function school_a_cannot_read_school_bs_rows_on_every_communications_table(): void
    {
        $schoolA = $this->createSchool();
        [$senderB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $recipientUserB = $this->createUser();
        $this->createMembership($recipientUserB, $schoolB);

        $threadB = $this->createThread($schoolB, $senderB);
        $participantB = $this->createParticipant($threadB, $senderB);
        $messageB = $this->createMessage($threadB, $senderB);
        $recipientB = $this->createRecipient($messageB, $recipientUserB);
        $deliveryB = $this->createDelivery($recipientB);
        $attemptB = $this->createDeliveryAttempt($deliveryB);

        $this->setSchool($schoolA->id);

        $ids = [
            'communication_threads' => $threadB->id,
            'communication_thread_participants' => $participantB->id,
            'communication_messages' => $messageB->id,
            'communication_recipients' => $recipientB->id,
            'communication_deliveries' => $deliveryB->id,
            'communication_delivery_attempts' => $attemptB->id,
        ];

        foreach ($ids as $table => $id) {
            $rows = DB::connection('pgsql')->select("select id from {$table} where id = ?", [$id]);
            $this->assertCount(0, $rows, "School A must not see School B's row in {$table}");
        }
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows_on_communications_tables(): void
    {
        $schoolA = $this->createSchool();
        [$senderB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $threadB = $this->createThread($schoolB, $senderB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update("update communication_threads set status = 'closed' where id = ?", [$threadB->id]));
        $this->assertRuntimeDeleteRevoked('delete from communication_threads where id = ?', [$threadB->id]);
    }

    #[Test]
    public function communication_delivery_attempts_cannot_be_updated_or_deleted_by_the_runtime_role(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);

        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient);
        $attempt = $this->createDeliveryAttempt($delivery);

        $this->setSchool($school->id);

        try {
            DB::connection('pgsql')->transaction(function () use ($attempt): void {
                DB::connection('pgsql')->table('communication_delivery_attempts')->where('id', $attempt->id)->update(['outcome' => 'tampered']);
            });
            $this->fail('Expected a QueryException: runtime role must not be able to UPDATE delivery attempts.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }

        try {
            DB::connection('pgsql')->transaction(function () use ($attempt): void {
                DB::connection('pgsql')->table('communication_delivery_attempts')->where('id', $attempt->id)->delete();
            });
            $this->fail('Expected a QueryException: runtime role must not be able to DELETE delivery attempts.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }
    }
}
