<?php

namespace Tests\Feature\Recovery;

use App\Console\Commands\RecoverQueuedWork;
use App\Domain\Automation\Application\AutomationTriggerConsumer;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Jobs\ProcessOutboxEventJob;
use App\Models\DomainEventOutbox;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use App\Support\Events\OutboxReconciler;
use App\Support\Observability\QueueName;
use App\Support\Settings\SchoolSettingsService;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.4A (ADR 0050 section 10): a COMPLETE Redis queue loss, on real
 * Redis (an isolated database index, never the developer's queues). Durable
 * PostgreSQL work exists, its queued jobs are lost, Redis comes back empty,
 * reconciliation runs, the jobs are restored, and every business effect
 * happens exactly once -- for outbox events, webhook deliveries and
 * Communication deliveries. Time moves with travel(), never sleep.
 */
class RedisQueueLossRecoveryTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private const REDIS_DATABASE = 13;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.redis.recovery_test' => [...config('database.redis.default'), 'database' => (string) self::REDIS_DATABASE],
            'queue.connections.recovery_test' => [
                'driver' => 'redis', 'connection' => 'recovery_test', 'queue' => 'default',
                'retry_after' => 90, 'block_for' => null, 'after_commit' => false,
            ],
            'queue.default' => 'recovery_test',
        ]);
        $this->loseRedis();
    }

    protected function tearDown(): void
    {
        $this->loseRedis();
        parent::tearDown();
    }

    /** The disaster: every queued job in this Redis database is gone. */
    private function loseRedis(): void
    {
        Redis::connection('recovery_test')->flushdb();
    }

    private function queued(QueueName $queue): int
    {
        return (int) Redis::connection('recovery_test')->llen('queues:'.$queue->value);
    }

    private function work(string $queues = 'default,integrations,notifications'): void
    {
        Artisan::call('queue:work', [
            'connection' => 'recovery_test',
            '--queue' => $queues,
            '--stop-when-empty' => true,
            '--sleep' => 0,
        ]);
    }

    private function in($school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    #[Test]
    public function outbox_and_webhook_work_survive_a_complete_redis_loss_exactly_once(): void
    {
        Http::fake(['*' => Http::response('ok', 202)]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->in($school, function () use ($school, $user) {
            $endpoint = WebhookEndpoint::query()->create([
                'school_id' => $school->id, 'name' => 'recovery', 'url' => 'https://8.8.8.8/hook',
                'secret_encrypted' => 'secret', 'status' => 'active',
            ]);
            app(WebhookSubscriptionService::class)->subscribe($endpoint, 'school.setting.changed.v1', $user);

            return $endpoint;
        });

        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $event = DomainEventOutbox::query()->where('school_id', $school->id)->latest('occurred_at')->firstOrFail();

        // 1. Dispatched to Redis ... and lost.
        Artisan::call('platform:outbox-dispatch');
        $this->assertSame('dispatched', $event->fresh()->status);
        $this->assertSame(1, $this->queued(QueueName::Default));
        $this->loseRedis();

        // Not yet stale: a job that may still be queued or running is never raced.
        Artisan::call('platform:outbox-dispatch');
        $this->assertSame(0, $this->queued(QueueName::Default));

        // 2. After the job's full retry lifecycle, PostgreSQL recovers it.
        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();
        Artisan::call('platform:outbox-dispatch');
        $this->assertSame(1, $this->queued(QueueName::Default));
        $this->work('default');

        $event->refresh();
        $this->assertNotNull($event->processed_at, 'Every consumer receipted: durably acknowledged.');
        $delivery = $this->in($school, fn () => WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->sole());

        // 3. The fan-out queued its webhook job -- and Redis is lost again.
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $this->queued(QueueName::Integrations));
        $this->loseRedis();
        Http::assertNothingSent();

        Artisan::call('platform:webhook-deliveries-redispatch');
        $this->assertSame(0, $this->queued(QueueName::Integrations), 'Within one lease the job may still be queued: not raced.');

        $this->travel((int) config('webhooks.processing_lease_seconds') + 1)->seconds();
        Artisan::call('platform:webhook-deliveries-redispatch');
        Artisan::call('platform:webhook-deliveries-redispatch');
        $this->assertSame(1, $this->queued(QueueName::Integrations), 'Re-dispatched once per lease, however often the redispatcher runs.');
        $this->work();

        $this->assertSame('delivered', $this->in($school, fn () => $delivery->fresh()->status));
        Http::assertSentCount(1);
        $this->assertSame(1, $this->in($school, fn () => WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->count()));

        // 4. Recovery is idempotent: nothing further is re-dispatched, and a
        // duplicate queued job does nothing.
        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();
        Artisan::call('platform:recover-queued-work');
        $this->assertSame(0, $this->queued(QueueName::Default) + $this->queued(QueueName::Integrations));

        ProcessOutboxEventJob::dispatch($event->id)->onQueue(QueueName::Default->value);
        DeliverWebhookJob::dispatch($school->id, $delivery->id)->onQueue(QueueName::Integrations->value);
        $this->work();
        Http::assertSentCount(1);
        $this->assertSame(1, $this->in($school, fn () => WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->count()));
    }

    #[Test]
    public function an_immediate_communication_delivery_survives_a_complete_redis_loss_exactly_once(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $message = $this->createMessage($this->createThread($school, $sender), $sender);
        $delivery = $this->createDelivery($this->createRecipient($message, $recipientUser));
        $this->assertSame('pending', $delivery->status);

        // Dispatched when created ... and lost.
        ProcessCommunicationDeliveryJob::dispatch($school->id, $delivery->id)->onQueue(QueueName::Notifications->value);
        $this->assertSame(1, $this->queued(QueueName::Notifications));
        $this->loseRedis();

        // Within one processing lease it may still be queued: not raced.
        Artisan::call('platform:communication-deliveries-redispatch');
        $this->assertSame(0, $this->queued(QueueName::Notifications));

        $this->travel((int) config('communications.delivery.processing_lease_seconds') + 1)->seconds();
        Artisan::call('platform:communication-deliveries-redispatch');
        $this->assertSame(1, $this->queued(QueueName::Notifications));

        // Re-dispatched at most once per lease, even if the redispatcher runs again.
        Artisan::call('platform:communication-deliveries-redispatch');
        $this->assertSame(1, $this->queued(QueueName::Notifications));

        $this->work();
        $fresh = $this->in($school, fn () => CommunicationDelivery::query()->findOrFail($delivery->id));
        $this->assertNotContains($fresh->status, ['pending', 'queued']);
        $this->assertSame(1, $this->in($school, fn () => CommunicationDeliveryAttempt::query()->where('communication_delivery_id', $delivery->id)->count()));

        // A duplicate job after completion sends nothing more.
        ProcessCommunicationDeliveryJob::dispatch($school->id, $delivery->id)->onQueue(QueueName::Notifications->value);
        $this->work();
        $this->assertSame(1, $this->in($school, fn () => CommunicationDeliveryAttempt::query()->where('communication_delivery_id', $delivery->id)->count()));
    }

    #[Test]
    public function a_deferred_communication_delivery_is_not_pulled_forward_by_recovery(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $message = $this->createMessage($this->createThread($school, $sender), $sender);
        $delivery = $this->createDelivery($this->createRecipient($message, $recipientUser), ['status' => 'queued', 'next_attempt_at' => now()->addHours(3)]);

        $this->travel(2)->hours();
        Artisan::call('platform:communication-deliveries-redispatch');
        $this->assertSame(0, $this->queued(QueueName::Notifications));
        $this->assertSame('queued', $this->in($school, fn () => $delivery->fresh()->status));
    }

    #[Test]
    public function automation_recovery_remains_covered_by_its_own_redispatch(): void
    {
        // Automation creates executions `pending` with a next_attempt_at grace
        // and its own redispatcher re-claims due ones (proven end to end in
        // Tests\Feature\Automation\AutomationExecutionPipelineTest); recovery
        // reuses it rather than duplicating it.
        $this->assertGreaterThan(0, AutomationTriggerConsumer::REDISPATCH_GRACE_SECONDS);
        $this->assertContains('automation:executions-redispatch', RecoverQueuedWork::SOURCES);
        $source = (string) file_get_contents(app_path('Console/Commands/RedispatchDueAutomationExecutions.php'));
        $this->assertStringContainsString('STATUS_PENDING', $source);
        $this->assertStringContainsString("'next_attempt_at', '<=', now()", $source);
    }

    #[Test]
    public function a_failing_event_is_marked_failed_not_recovered_forever(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'daily');
        $event = DomainEventOutbox::query()->where('school_id', $school->id)->latest('occurred_at')->firstOrFail();
        Artisan::call('platform:outbox-dispatch');

        (new ProcessOutboxEventJob($event->id))->failed(new \RuntimeException('consumer broke'));
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertSame('consumer_failures_exhausted_retries', $event->fresh()->last_error);

        $this->loseRedis();
        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();
        Artisan::call('platform:outbox-dispatch');
        $this->assertSame(0, $this->queued(QueueName::Default));
    }
}
