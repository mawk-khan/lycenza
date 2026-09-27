<?php

namespace Tests\Feature\Postgres;

use App\Models\EmailSuppression;
use App\Support\Email\EmailState;
use App\Support\Email\Suppression\EmailSuppressionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 sections 9 and 12): what the DATABASE enforces for
 * the email layer, against direct writes -- the transition graph (identical
 * to EmailState's), identity immutability, the content purge, append-only
 * attempts, release-only suppressions and RLS.
 */
class EmailDatabaseInvariantsTest extends TestCase
{
    use CreatesEmailFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->holdEmailSubmission();
    }

    #[Test]
    public function the_database_transition_function_is_exactly_the_php_graph(): void
    {
        $states = array_map(fn (EmailState $s) => $s->value, EmailState::cases());

        foreach ($states as $from) {
            foreach ($states as $to) {
                $db = (bool) DB::selectOne('SELECT email_messages_transition_allowed(?, ?) AS ok', [$from, $to])->ok;
                $this->assertSame(EmailState::from($from)->canTransitionTo(EmailState::from($to)), $db, "{$from} -> {$to}");
            }
        }
    }

    #[Test]
    public function a_direct_write_cannot_move_a_message_backward_but_a_complaint_may_follow_delivery(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin);
        $set = fn (array $values) => $this->inSchool($school, fn () => DB::transaction(fn () => DB::table('email_messages')->where('id', $email->id)->update($values)));

        $set(['status' => 'submitting']);
        $set(['status' => 'submitted', 'provider_message_id' => 'p-1']);
        $set(['status' => 'delivered']);

        foreach (['submitted', 'deferred', 'pending', 'submitting', 'cancelled', 'suppressed'] as $backward) {
            try {
                $set(['status' => $backward]);
                $this->fail("delivered -> {$backward} must be refused");
            } catch (QueryException $e) {
                $this->assertStringContainsString('email_messages_transition', $e->getMessage());
            }
        }

        $set(['status' => 'complained']);
        $this->assertSame('complained', $this->emailRow($school, $email->id)->status->value);

        foreach (['delivered', 'bounced'] as $afterFinal) {
            try {
                $set(['status' => $afterFinal]);
                $this->fail("complained -> {$afterFinal} must be refused");
            } catch (QueryException) {
            }
        }
    }

    #[Test]
    public function identity_and_provider_id_are_immutable_and_content_is_purged_and_never_restored(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin);
        $set = fn (array $values) => $this->inSchool($school, fn () => DB::transaction(fn () => DB::table('email_messages')->where('id', $email->id)->update($values)));

        foreach ([['subject' => 'changed'], ['purpose' => 'account_invitation', 'kind' => 'critical'], ['recipient_encrypted' => 'x'], ['rfc_message_id' => '<other@x.test>'], ['from_display_name' => 'Other']] as $change) {
            try {
                $set($change);
                $this->fail('refused: '.json_encode($change));
            } catch (QueryException) {
            }
        }

        // Leaving the pre-submission states purges the content, in the database.
        $set(['status' => 'submitting']);
        $set(['status' => 'submitted', 'provider_message_id' => 'p-2']);
        $raw = $this->inSchool($school, fn () => DB::table('email_messages')->where('id', $email->id)->first());
        $this->assertNull($raw->sealed_content);
        $this->assertNotNull($raw->content_purged_at);

        foreach ([['sealed_content' => 'restored'], ['provider_message_id' => 'p-3']] as $change) {
            try {
                $set($change);
                $this->fail('refused: '.json_encode($change));
            } catch (QueryException) {
            }
        }
    }

    #[Test]
    public function a_message_must_start_pending_sealed_and_unattempted(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');

        $this->expectException(QueryException::class);
        $this->inSchool($school, fn () => DB::table('email_messages')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $school->id, 'purpose' => 'school_communication', 'kind' => 'standard',
            'source_type' => 'communication_delivery', 'source_id' => (string) Str::uuid(), 'recipient_encrypted' => 'x',
            'from_mailbox' => 'notifications', 'from_display_name' => 'L', 'subject' => 'S', 'sealed_content' => 'x',
            'status' => 'submitted', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    #[Test]
    public function header_values_with_control_characters_are_refused_by_the_database(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');

        $this->expectException(QueryException::class);
        $this->inSchool($school, fn () => DB::table('email_messages')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $school->id, 'purpose' => 'school_communication', 'kind' => 'standard',
            'source_type' => 'communication_delivery', 'source_id' => (string) Str::uuid(), 'recipient_encrypted' => 'x',
            'from_mailbox' => 'notifications', 'from_display_name' => 'L', 'subject' => "S\r\nBcc: x@y.test", 'sealed_content' => 'x',
            'status' => 'pending', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    #[Test]
    public function attempts_are_append_only_and_suppressions_are_release_only(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin);
        $this->fakeEmail();
        $this->submitEmail($school, $email->id);

        foreach (['UPDATE email_submission_attempts SET outcome = \'accepted\'', 'DELETE FROM email_submission_attempts'] as $sql) {
            try {
                $this->inSchool($school, fn () => DB::transaction(fn () => DB::statement($sql)));
                $this->fail("refused: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }

        $suppression = app(EmailSuppressionService::class)->suppress('x@example.com', 'all', 'hard_bounce');
        try {
            DB::transaction(fn () => DB::table('email_suppressions')->where('id', $suppression->id)->delete());
            $this->fail('the runtime role cannot delete a suppression');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('email_suppressions')->where('id', $suppression->id)->update(['scope' => 'standard']));
            $this->fail('only a release may change a suppression');
        } catch (QueryException $e) {
            $this->assertStringContainsString('email_suppressions_immutable', $e->getMessage());
        }

        DB::table('email_suppressions')->where('id', $suppression->id)->update(['released_at' => now(), 'release_reason' => 'mailbox_repaired']);
        try {
            DB::transaction(fn () => DB::table('email_suppressions')->where('id', $suppression->id)->update(['release_reason' => 'false_positive']));
            $this->fail('a released suppression is history');
        } catch (QueryException $e) {
            $this->assertStringContainsString('email_suppressions_released', $e->getMessage());
        }
        $this->assertNotNull(EmailSuppression::query()->find($suppression->id)->released_at);
    }

    #[Test]
    public function school_a_cannot_read_or_write_school_b_email_through_rls(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        [$emailA] = $this->queueStandardEmail($schoolA, $adminA);
        $this->queueStandardEmail($schoolB, $adminB);

        $this->assertSame([$emailA->id], $this->inSchool($schoolA, fn () => DB::table('email_messages')->pluck('id')->all()));
        $this->assertSame(0, $this->inSchool($schoolB, fn () => DB::table('email_messages')->where('id', $emailA->id)->update(['status_code' => 'x'])));

        // Missing tenant context fails closed.
        $this->assertSame(0, DB::table('email_messages')->count());
        $this->assertSame(0, DB::table('email_submission_attempts')->count());
    }
}
