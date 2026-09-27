<?php

namespace Tests\Feature\Email;

use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Models\EmailProviderReference;
use App\Models\EmailSubmissionAttempt;
use App\Support\Email\EmailState;
use App\Support\Email\ProviderAuthPause;
use App\Support\Email\Providers\EmailProviderAdapter;
use App\Support\Email\Providers\FakeEmailProvider;
use App\Support\Email\Providers\OutboundEmail;
use App\Support\Email\Providers\SimulatedWorkerCrash;
use App\Support\Email\Providers\SubmissionResult;
use App\Support\Email\RetrySchedule;
use App\Support\Email\Suppression\EmailSuppressionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 sections 9.3, 9.4, 10, 12): one submission attempt
 * at a time, bounded retries, the provider-auth pause, suppression,
 * expiry, disabled mode and the at-least-once residual.
 */
class EmailSubmissionTest extends TestCase
{
    use CreatesEmailFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutRetryJitter();
        $this->holdEmailSubmission();
        $this->fakeEmail();
    }

    #[Test]
    public function an_accepted_submission_is_submitted_not_delivered_and_its_content_is_purged(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email, $delivery] = $this->queueStandardEmail($school, $admin, 'a@school-os.test', 'Subject', 'The body text');

        $submitted = $this->submitEmail($school, $email->id);

        $this->assertSame(EmailState::Submitted, $submitted->status);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertNull($submitted->delivered_at);
        $this->assertNull($submitted->sealed_content, 'content is purged once the provider accepted it');
        $this->assertNotNull($submitted->content_purged_at);
        $this->assertSame('a@school-os.test', $submitted->recipient(), 'the recipient stays: events and suppression need it');
        $this->assertSame('The body text', $this->lastAcceptedEmail()->text);

        $attempt = $this->inSchool($school, fn () => EmailSubmissionAttempt::query()->where('email_message_id', $email->id)->sole());
        $this->assertSame(['accepted', 1, 'fake'], [$attempt->outcome, $attempt->attempt_number, $attempt->provider]);
        $this->assertSame($submitted->provider_message_id, EmailProviderReference::query()->where('email_message_id', $email->id)->value('provider_message_id'));

        // Communications sees `sent` (the provider accepted it) -- never delivered.
        $this->assertSame('sent', $this->inSchool($school, fn () => CommunicationDelivery::query()->find($delivery->id)->status));
    }

    #[Test]
    public function transient_failures_back_off_on_the_adr_schedule_and_give_up_after_six_attempts(): void
    {
        $this->freezeSecond();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email, $delivery] = $this->queueStandardEmail($school, $admin);
        $this->emailFake()->queue(...array_fill(0, 6, SubmissionResult::transient('timeout')));

        foreach ([30, 120, 600, 1800, 7200] as $n => $delay) {
            $row = $this->submitEmail($school, $email->id);
            $this->assertSame(EmailState::Pending, $row->status, 'attempt '.($n + 1));
            $this->assertSame('timeout', $row->status_code);
            $this->assertSame($n + 1, $row->attempts);
            $this->assertEquals(now()->addSeconds($delay), $row->next_attempt_at);
            $this->assertNotNull($row->sealed_content, 'content is kept while it may still be sent');

            // Not due yet: a redelivered job does nothing.
            $this->assertSame($n + 1, $this->submitEmail($school, $email->id)->attempts);
            $this->travel($delay)->seconds();
        }

        $final = $this->submitEmail($school, $email->id);
        $this->assertSame(EmailState::Failed, $final->status);
        $this->assertSame('attempts_exhausted', $final->status_code);
        $this->assertSame(6, $final->attempts);
        $this->assertNull($final->sealed_content);
        $this->assertSame(6, $this->inSchool($school, fn () => EmailSubmissionAttempt::query()->where('email_message_id', $email->id)->count()));
        $this->assertSame('failed', $this->inSchool($school, fn () => CommunicationDelivery::query()->find($delivery->id)->status));
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function retry_jitter_stays_within_twenty_percent(): void
    {
        foreach ([0.0, 1.0] as $random) {
            $schedule = new RetrySchedule(config(), fn (): float => $random);
            foreach ([1 => 30, 2 => 120, 3 => 600, 4 => 1800, 5 => 7200] as $attempt => $base) {
                $delay = $schedule->delayAfter($attempt);
                $this->assertGreaterThanOrEqual((int) round($base * 0.8), $delay);
                $this->assertLessThanOrEqual((int) round($base * 1.2), $delay);
            }
        }
    }

    #[Test]
    public function a_permanent_failure_is_never_retried(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email, $delivery] = $this->queueStandardEmail($school, $admin);
        $this->emailFake()->queue(SubmissionResult::permanent('recipient_rejected'));

        $row = $this->submitEmail($school, $email->id);

        $this->assertSame([EmailState::Failed, 'recipient_rejected', 1], [$row->status, $row->status_code, $row->attempts]);
        $this->assertNull($row->next_attempt_at);
        $this->assertSame(['failed', 'email_submission_failed'], $this->inSchool($school, fn () => [($d = CommunicationDelivery::query()->find($delivery->id))->status, $d->failure_code]));
    }

    #[Test]
    public function an_authentication_failure_pauses_every_submission_instead_of_hammering_the_provider(): void
    {
        $this->freezeSecond();
        Log::spy();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$first] = $this->queueStandardEmail($school, $admin, 'one@school-os.test');
        [$second] = $this->queueStandardEmail($school, $admin, 'two@school-os.test');
        $this->emailFake()->queue(SubmissionResult::authFailure());

        $row = $this->submitEmail($school, $first->id);
        $this->assertSame([EmailState::Pending, 'provider_auth_failure'], [$row->status, $row->status_code]);
        $this->assertEquals(now()->addSeconds(900), $row->next_attempt_at);
        $this->assertNotNull(app(ProviderAuthPause::class)->until());

        // The other message waits without contacting the provider at all.
        $other = $this->submitEmail($school, $second->id);
        $this->assertSame([EmailState::Pending, 'provider_auth_paused', 0], [$other->status, $other->status_code, $other->attempts]);
        $this->assertCount(1, $this->emailFake()->calls(), 'only the first message reached the provider');

        Log::shouldHaveReceived('error')->withArgs(fn ($event, $context) => $event === 'email.provider.auth_failure' && ! str_contains(json_encode($context), 'password'));

        // After the pause the provider is tried again, and accepts.
        $this->travel(901)->seconds();
        $this->assertSame(EmailState::Submitted, $this->submitEmail($school, $second->id)->status);
    }

    #[Test]
    public function an_adapter_that_throws_is_treated_as_a_transient_provider_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin);
        $this->app->bind(FakeEmailProvider::class, fn () => new class implements EmailProviderAdapter
        {
            public function name(): string
            {
                return 'fake';
            }

            public function supportsIdempotencyKey(): bool
            {
                return false;
            }

            public function submit(OutboundEmail $email): SubmissionResult
            {
                throw new \RuntimeException('adapter bug with user@example.com in the message');
            }
        });

        $row = $this->submitEmail($school, $email->id);

        $this->assertSame([EmailState::Pending, 'provider_error', 1], [$row->status, $row->status_code, $row->attempts]);
    }

    #[Test]
    public function a_suppressed_address_is_never_submitted_and_critical_mail_respects_an_all_scope_suppression(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        app(EmailSuppressionService::class)->suppress('blocked@example.com', 'all', 'hard_bounce');
        app(EmailSuppressionService::class)->suppress('standard-only@example.com', 'standard', 'complaint');

        [$standard, $delivery] = $this->queueStandardEmail($school, $admin, 'blocked@example.com');
        $this->assertSame([EmailState::Suppressed, 'suppressed_hard_bounce'], [($row = $this->submitEmail($school, $standard->id))->status, $row->status_code]);
        $this->assertNull($row->sealed_content);
        $this->assertSame(['rejected', 'email_suppressed'], $this->inSchool($school, fn () => [($d = CommunicationDelivery::query()->find($delivery->id))->status, $d->failure_code]));

        [$critical] = $this->queueInvitationEmail($school, $admin, 'blocked@example.com');
        $this->assertSame(EmailState::Suppressed, $this->submitEmail($school, $critical->id)->status, 'critical mail never bypasses suppression');

        // A standard-scope suppression (a complaint about standard mail) does not block critical mail.
        [$invitation] = $this->queueInvitationEmail($school, $admin, 'standard-only@example.com');
        $this->assertSame(EmailState::Submitted, $this->submitEmail($school, $invitation->id)->status);
        [$announcement] = $this->queueStandardEmail($school, $admin, 'standard-only@example.com');
        $this->assertSame(EmailState::Suppressed, $this->submitEmail($school, $announcement->id)->status);

        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function mail_provider_none_records_and_waits_but_never_submits_or_claims_sent(): void
    {
        config(['email.provider' => 'none']);
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueInvitationEmail($school, $admin);

        $row = $this->submitEmail($school, $email->id);

        $this->assertSame([EmailState::Pending, 'email_disabled', 0], [$row->status, $row->status_code, $row->attempts]);
        $this->assertNotNull($row->sealed_content);
        $this->assertTrue($row->next_attempt_at->isFuture());
        $this->assertSame([], $this->emailFake()->calls());
    }

    #[Test]
    public function an_expired_message_is_cancelled_and_its_content_purged_before_any_submission(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueInvitationEmail($school, $admin);

        $this->travelTo($email->expires_at->copy()->addSecond());
        $row = $this->submitEmail($school, $email->id);

        $this->assertSame([EmailState::Cancelled, 'expired'], [$row->status, $row->status_code]);
        $this->assertNull($row->sealed_content);
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function the_sweeper_expires_waiting_messages_of_a_suspended_school_on_time(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueInvitationEmail($school, $admin);
        DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);

        $this->travelTo($email->expires_at->copy()->addMinute());
        $this->artisan('platform:email-messages-redispatch')->assertExitCode(0);

        $row = $this->emailRow($school, $email->id);
        $this->assertSame([EmailState::Cancelled, 'expired'], [$row->status, $row->status_code]);
        $this->assertNull($row->sealed_content);
    }

    #[Test]
    public function a_suspended_schools_message_waits_and_is_sent_after_resume(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin);
        DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);

        $row = $this->submitEmail($school, $email->id);
        $this->assertSame([EmailState::Pending, 'school_not_operational'], [$row->status, $row->status_code]);
        $this->assertNoEmailAccepted();

        DB::table('schools')->where('id', $school->id)->update(['status' => 'active']);
        $this->assertSame(EmailState::Submitted, $this->submitEmail($school, $email->id)->status);
    }

    #[Test]
    public function a_withdrawn_source_is_never_sent(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email, $delivery] = $this->queueStandardEmail($school, $admin);
        $this->inSchool($school, fn () => CommunicationDelivery::query()->whereKey($delivery->id)->update(['status' => 'cancelled']));

        $row = $this->submitEmail($school, $email->id);

        $this->assertSame([EmailState::Cancelled, 'source_withdrawn'], [$row->status, $row->status_code]);
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function a_crash_after_the_provider_accepted_is_resubmitted_with_the_same_identity_at_least_once_never_exactly_once(): void
    {
        $this->freezeSecond();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin);
        $this->emailFake()->crashAfterAcceptingNext();

        try {
            $this->submitEmail($school, $email->id);
            $this->fail('the simulated worker crash must propagate');
        } catch (SimulatedWorkerCrash) {
        }

        $stuck = $this->emailRow($school, $email->id);
        $this->assertSame(EmailState::Submitting, $stuck->status, 'nothing was recorded: the worker died');
        $this->assertNotNull($stuck->sealed_content);

        // Inside the lease nobody else takes it.
        $this->assertSame(EmailState::Submitting, $this->submitEmail($school, $email->id)->status);

        // After the lease the sweeper recovers it: submitted AGAIN, with the same
        // Message-ID and idempotency key. Without provider idempotency that is a
        // real duplicate -- the documented residual.
        $this->travel(121)->seconds();
        $this->artisan('platform:email-messages-redispatch')->assertExitCode(0);
        $this->submitEmail($school, $email->id);

        $accepted = $this->emailFake()->acceptedEmails();
        $this->assertCount(2, $accepted);
        $this->assertSame($accepted[0]->rfcMessageId, $accepted[1]->rfcMessageId);
        $this->assertSame($accepted[0]->idempotencyKey, $accepted[1]->idempotencyKey);
        $this->assertSame(EmailState::Submitted, $this->emailRow($school, $email->id)->status);
    }

    #[Test]
    public function a_provider_honouring_the_idempotency_key_collapses_the_crash_resubmission(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin);
        $fake = $this->emailFake()->honourIdempotency()->crashAfterAcceptingNext();

        try {
            $this->submitEmail($school, $email->id);
        } catch (SimulatedWorkerCrash) {
        }
        $this->travel(121)->seconds();
        $row = $this->submitEmail($school, $email->id);

        $this->assertCount(1, $fake->acceptedEmails(), 'one message at the provider');
        $this->assertSame($fake->calls()[0]['result']->providerMessageId, $row->provider_message_id);
    }

    #[Test]
    public function attempts_and_logs_hold_no_address_body_or_credential(): void
    {
        Log::spy();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin, 'private.person@school-os.test', 'Private subject', 'Private body');
        $this->emailFake()->queue(SubmissionResult::transient('network_error'));
        $this->submitEmail($school, $email->id);

        $attempts = json_encode($this->inSchool($school, fn () => EmailSubmissionAttempt::query()->where('email_message_id', $email->id)->get()->toArray()));
        foreach (['private.person', 'Private subject', 'Private body'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $attempts);
        }

        Log::shouldNotHaveReceived('info', fn ($event, $context = []) => str_contains(json_encode($context), 'private.person'));
        Log::shouldNotHaveReceived('warning', fn ($event, $context = []) => str_contains(json_encode($context), 'private.person'));
    }

    #[Test]
    public function the_operator_retry_makes_a_waiting_message_due_once_and_is_audited_but_resurrects_nothing(): void
    {
        $this->freezeSecond();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin);
        $this->emailFake()->queue(...array_fill(0, 5, SubmissionResult::transient('timeout')));
        foreach ([30, 120, 600, 1800] as $delay) {
            $this->submitEmail($school, $email->id);
            $this->travel($delay)->seconds();
        }
        $this->submitEmail($school, $email->id);
        $this->assertSame(5, $this->emailRow($school, $email->id)->attempts);

        $this->artisan('platform:mail-retry', ['school' => $school->id, 'message' => $email->id])->assertExitCode(0);

        $row = $this->emailRow($school, $email->id);
        $this->assertSame([1, 5], [$row->manual_retries, $row->retry_base]);
        $this->assertDatabaseHas('platform_audit_events', ['event_type' => 'platform.email_message.retry_requested']);

        // A fresh budget: the sixth attempt no longer exhausts it.
        $this->emailFake()->queue(SubmissionResult::transient('timeout'));
        $this->assertSame(EmailState::Pending, $this->submitEmail($school, $email->id)->status);

        // A finished message is refused.
        [$done] = $this->queueStandardEmail($school, $admin, 'b@school-os.test');
        $this->submitEmail($school, $done->id);
        $this->artisan('platform:mail-retry', ['school' => $school->id, 'message' => $done->id])->assertExitCode(1);
        $this->assertSame(EmailState::Submitted, $this->emailRow($school, $done->id)->status);
    }
}
