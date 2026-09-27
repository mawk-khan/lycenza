<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Concerns\StubsRetryableChannelFailure;
use Tests\TestCase;

/**
 * Phase 5A.9 §33/§57 -- a retry backoff timestamp that itself falls
 * inside a quiet-hours window is pushed out to the next permitted
 * instant, so every provider attempt (not just the first) respects
 * current timing eligibility.
 */
class ProcessCommunicationDeliveryRetryTimingTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail, StubsRetryableChannelFailure;

    #[Test]
    public function a_retry_backoff_that_would_land_inside_quiet_hours_is_pushed_to_the_next_permitted_instant(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Config::set('communications.delivery.max_attempts', 3);
        // A 300-second backoff pushes the retry candidate well past
        // 06:59:30 -> into the still-quiet 07:00 boundary region.
        Config::set('communications.delivery.retry_backoff_seconds', [300]);

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'destination_snapshot' => ['email' => 'someone@school-os.test'],
        ]);

        // 22:00:00 + a 300s backoff candidate (22:05:00) is itself
        // still well inside the 20:00-07:00 quiet window.
        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $this->stubRetryableEmailChannelFailure();

        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle(
            app(CommunicationChannelRegistry::class),
            app(TenantContext::class),
            app(CommunicationDeliveryTimingPolicyService::class),
        );

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $delivery->fresh());

        $this->assertSame('queued', $fresh->status);
        $this->assertNotNull($fresh->next_attempt_at);
        // The naive backoff candidate (22:05:00) is inside quiet hours
        // -- the actual persisted next_attempt_at must be pushed all
        // the way out to the window's end instead.
        $this->assertTrue($fresh->next_attempt_at->equalTo(Carbon::parse('2026-08-24 07:00:00', 'Asia/Kolkata')));
    }

    #[Test]
    public function a_retry_backoff_that_lands_outside_quiet_hours_is_used_unmodified(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Config::set('communications.delivery.max_attempts', 3);
        Config::set('communications.delivery.retry_backoff_seconds', [30]);

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'destination_snapshot' => ['email' => 'someone@school-os.test'],
        ]);

        // 12:00:00 + 30s candidate is nowhere near the quiet window.
        $this->travelTo(Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata'));
        $expectedCandidate = now()->copy()->addSeconds(30);

        $this->stubRetryableEmailChannelFailure();

        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle(
            app(CommunicationChannelRegistry::class),
            app(TenantContext::class),
            app(CommunicationDeliveryTimingPolicyService::class),
        );

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $delivery->fresh());

        $this->assertSame('queued', $fresh->status);
        $this->assertTrue($fresh->next_attempt_at->equalTo($expectedCandidate));
    }

    #[Test]
    public function a_quiet_hours_deferred_delivery_is_sent_exactly_once_even_if_redispatch_races(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);

        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        // Simulates exactly what AnnouncementService::publish() would
        // have written for a quiet-hours-deferred first attempt.
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'status' => 'queued',
            'attempts' => 0,
            'next_attempt_at' => Carbon::parse('2026-08-24 07:00:00', 'Asia/Kolkata')->utc(),
            'destination_snapshot' => ['email' => 'someone@school-os.test'],
        ]);

        $this->travelTo(Carbon::parse('2026-08-24 07:00:01', 'Asia/Kolkata'));

        $this->artisan('platform:communication-deliveries-redispatch')->assertExitCode(0);
        $this->artisan('platform:communication-deliveries-redispatch')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $delivery->fresh());

        $this->assertSame('sent', $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertEmailAcceptedCount(1);
    }
}
