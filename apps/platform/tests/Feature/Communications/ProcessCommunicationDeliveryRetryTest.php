<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Concerns\StubsRetryableChannelFailure;
use Tests\TestCase;

/**
 * Phase 5A.3 §21: a retryable transient failure is scheduled for a
 * bounded, backed-off re-attempt rather than immediately terminal --
 * proven here with a stub channel driver reporting a retryable failure
 * (since Phase 0O.9A the real email driver hands off to the email layer,
 * which owns email retries). A deterministic failure (covered by
 * EmailChannelDriverTest) never reaches this retry path at all.
 */
class ProcessCommunicationDeliveryRetryTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail, StubsRetryableChannelFailure;

    #[Test]
    public function a_transient_transport_failure_schedules_a_bounded_backed_off_retry_not_an_immediate_terminal_failure(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Config::set('communications.delivery.max_attempts', 3);
        Config::set('communications.delivery.retry_backoff_seconds', [30, 120, 300]);

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'destination_snapshot' => ['email' => 'someone@school-os.test'],
        ]);

        $this->stubRetryableEmailChannelFailure();

        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle(
            app(CommunicationChannelRegistry::class),
            app(TenantContext::class),
            app(CommunicationDeliveryTimingPolicyService::class),
        );

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $delivery->fresh());

        $this->assertSame('queued', $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNotNull($fresh->next_attempt_at);
        $this->assertTrue($fresh->next_attempt_at->isFuture());

        $attempt = $context->withSchool($school, fn () => CommunicationDeliveryAttempt::query()
            ->where('communication_delivery_id', $delivery->id)->first());
        $this->assertSame('transient_failure', $attempt->outcome);
        $this->assertSame('email_transport_unavailable', $attempt->failure_code);
    }

    #[Test]
    public function a_retryable_failure_becomes_terminal_once_max_attempts_is_reached(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Config::set('communications.delivery.max_attempts', 2);
        Config::set('communications.delivery.retry_backoff_seconds', [1]);

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'destination_snapshot' => ['email' => 'someone@school-os.test'],
            'attempts' => 1,
        ]);

        $this->stubRetryableEmailChannelFailure();

        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle(
            app(CommunicationChannelRegistry::class),
            app(TenantContext::class),
            app(CommunicationDeliveryTimingPolicyService::class),
        );

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $delivery->fresh());

        $this->assertSame('failed', $fresh->status);
        $this->assertSame(2, $fresh->attempts);
        $this->assertNull($fresh->next_attempt_at);
    }

    #[Test]
    public function redispatch_command_requeues_a_delivery_whose_retry_is_due_and_ignores_one_not_yet_due(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);

        $due = $this->createDelivery($recipient, [
            'channel' => 'email',
            'status' => 'queued',
            'attempts' => 1,
            'next_attempt_at' => now()->subMinute(),
            'destination_snapshot' => ['email' => 'someone@school-os.test'],
        ]);

        $recipient2 = $this->createRecipient($message, $this->createUser());
        $notDue = $this->createDelivery($recipient2, [
            'channel' => 'email',
            'status' => 'queued',
            'attempts' => 1,
            'next_attempt_at' => now()->addHour(),
            'destination_snapshot' => ['email' => 'someone-else@school-os.test'],
        ]);

        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $this->artisan('platform:communication-deliveries-redispatch')->assertExitCode(0);

        $context = app(TenantContext::class);
        $dueFresh = $context->withSchool($school, fn () => $due->fresh());
        $notDueFresh = $context->withSchool($school, fn () => $notDue->fresh());

        // The due delivery was picked up, processed (sync queue), and
        // moved out of `queued`. The not-yet-due delivery is untouched.
        $this->assertNotSame('queued', $dueFresh->status);
        $this->assertSame('queued', $notDueFresh->status);
        $this->assertEmailAcceptedCount(1);
    }
}
