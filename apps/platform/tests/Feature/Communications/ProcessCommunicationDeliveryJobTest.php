<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.1 §2.9/§21: the job-level idempotency invariant --
 * re-running the job for an already-terminal delivery must not create
 * a second attempt or re-process, mirroring
 * App\Jobs\DeliverWebhookJob::claim()'s proven pattern.
 */
class ProcessCommunicationDeliveryJobTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    #[Test]
    public function processing_an_in_app_delivery_marks_it_delivered_with_one_attempt(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);

        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient);

        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle(
            app(CommunicationChannelRegistry::class),
            app(TenantContext::class),
        );

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $delivery->fresh());
        $this->assertSame('delivered', $fresh->status);
        $this->assertSame(1, $fresh->attempts);
    }

    #[Test]
    public function running_the_job_twice_for_the_same_delivery_never_produces_a_second_attempt(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);

        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient);

        $registry = app(CommunicationChannelRegistry::class);
        $context = app(TenantContext::class);

        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle($registry, $context);
        // Second run: the delivery is already `delivered` (terminal),
        // so the atomic claim's WHERE status IN ('pending','queued')
        // matches 0 rows -- no second attempt is recorded.
        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle($registry, $context);

        $attemptCount = $context->withSchool(
            $school,
            fn () => CommunicationDeliveryAttempt::query()->where('communication_delivery_id', $delivery->id)->count(),
        );
        $this->assertSame(1, $attemptCount);
    }

    #[Test]
    public function a_delivery_on_a_channel_with_no_registered_driver_is_marked_failed_not_silently_dropped(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);

        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, ['channel' => 'email']);

        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle(
            app(CommunicationChannelRegistry::class),
            app(TenantContext::class),
        );

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $delivery->fresh());
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('channel_not_supported', $fresh->failure_code);
    }
}
