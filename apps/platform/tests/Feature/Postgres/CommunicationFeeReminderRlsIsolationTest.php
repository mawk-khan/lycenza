<?php

namespace Tests\Feature\Postgres;

use App\Domain\Communications\Application\CommunicationDeliveryFactory;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9D-2 (ADR 0076 §74; mandatory per root CLAUDE.md rule 28) -- raw-SQL
 * tenant isolation for the two fee-reminder tables: the Finance selection
 * (`communication_announcement_fee_reminders`) and each recipient's
 * reminder lines (`communication_fee_reminder_lines`, which also stay
 * append-only for the runtime role). Mirrors
 * CommunicationAnnouncementAcademicCohortsRlsIsolationTest.
 */
class CommunicationFeeReminderRlsIsolationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /** @return array{selection: string, line: string, school: School, recipient: string} */
    private function reminderRows(): array
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator, ['audience_type' => 'fee_reminder']);
        $selection = (string) new UuidV7;
        $line = (string) new UuidV7;

        $recipient = app(TenantContext::class)->withSchool($school, function () use ($school, $creator, $announcement, $selection, $line): string {
            DB::table('communication_announcement_fee_reminders')->insert([
                'id' => $selection, 'school_id' => $school->id, 'announcement_id' => $announcement->id,
                'balance_status' => 'outstanding', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $message = CommunicationMessage::factory()->create(['school_id' => $school->id, 'thread_id' => null, 'announcement_id' => $announcement->id, 'sender_user_id' => $creator->id]);
            $recipient = app(CommunicationDeliveryFactory::class)->createRecipient($school->id, $message->id, $creator->id);
            DB::table('communication_fee_reminder_lines')->insert([
                'id' => $line, 'school_id' => $school->id, 'recipient_id' => $recipient->id, 'position' => 1,
                'student_name' => 'Asha Pupil', 'outstanding_amount' => '100.00', 'currency' => 'INR', 'as_of' => now(),
            ]);

            return $recipient->id;
        });

        return ['selection' => $selection, 'line' => $line, 'school' => $school, 'recipient' => $recipient];
    }

    #[Test]
    public function both_tables_have_rls_enabled_and_forced(): void
    {
        foreach (['communication_announcement_fee_reminders', 'communication_fee_reminder_lines'] as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );
            $this->assertTrue($row->relrowsecurity, $table);
            $this->assertTrue($row->relforcerowsecurity, $table);
        }
    }

    #[Test]
    public function no_context_and_another_school_see_nothing_and_cannot_claim_a_school(): void
    {
        $rows = $this->reminderRows();

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from communication_announcement_fee_reminders')->c);
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from communication_fee_reminder_lines')->c);

        $other = $this->createSchool();
        $this->setSchool($other->id);
        $this->assertCount(0, DB::connection('pgsql')->select('select id from communication_announcement_fee_reminders where id = ?', [$rows['selection']]));
        $this->assertCount(0, DB::connection('pgsql')->select('select id from communication_fee_reminder_lines where id = ?', [$rows['line']]));

        // Under School A's context, a row claiming School B is refused by the policy.
        $this->assertThrows(fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('communication_fee_reminder_lines')->insert([
            'id' => (string) new UuidV7, 'school_id' => $rows['school']->id, 'recipient_id' => $rows['recipient'], 'position' => 2,
            'student_name' => 'X', 'outstanding_amount' => '1.00', 'currency' => 'INR', 'as_of' => now(),
        ])), QueryException::class);
    }

    #[Test]
    public function published_lines_are_append_only_for_the_runtime_role(): void
    {
        $rows = $this->reminderRows();
        $this->setSchool($rows['school']->id);

        $this->assertThrows(fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('communication_fee_reminder_lines')->where('id', $rows['line'])->update(['outstanding_amount' => '1.00'])), QueryException::class);
        $this->assertThrows(fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('communication_fee_reminder_lines')->where('id', $rows['line'])->delete()), QueryException::class);
        $this->assertThrows(fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('communication_fee_reminder_lines')->insert([
            'id' => (string) new UuidV7, 'school_id' => $rows['school']->id, 'recipient_id' => $rows['recipient'], 'position' => 3,
            'student_name' => 'Credit', 'outstanding_amount' => '0.00', 'currency' => 'INR', 'as_of' => now(),
        ])), QueryException::class, 'cfrl_amount_positive_check'); // a reminder line is strictly positive
    }
}
