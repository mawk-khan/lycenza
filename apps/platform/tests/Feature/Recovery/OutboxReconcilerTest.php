<?php

namespace Tests\Feature\Recovery;

use App\Jobs\ProcessOutboxEventJob;
use App\Models\DomainEventOutbox;
use App\Models\EventConsumerReceipt;
use App\Support\Events\EventConsumerRegistry;
use App\Support\Events\OutboxReconciler;
use App\Support\Settings\SchoolSettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.4A (ADR 0050 section 10): the PostgreSQL-driven outbox
 * reconciliation that runs inside `platform:outbox-dispatch`.
 */
class OutboxReconcilerTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function dispatchedEvent(): DomainEventOutbox
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $event = DomainEventOutbox::query()->where('school_id', $school->id)->latest('occurred_at')->firstOrFail();

        Queue::fake();
        Artisan::call('platform:outbox-dispatch');
        $this->assertSame('dispatched', $event->fresh()->status);
        Queue::assertPushed(ProcessOutboxEventJob::class, 1);

        return $event->fresh();
    }

    private function reconcile(): array
    {
        return app(OutboxReconciler::class)->reconcile(100);
    }

    #[Test]
    public function a_recently_dispatched_event_is_never_raced(): void
    {
        $event = $this->dispatchedEvent();

        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS - 5)->seconds();
        $this->assertSame(['redispatched' => 0, 'acknowledged' => 0, 'failed' => 0], $this->reconcile());
        Queue::assertPushed(ProcessOutboxEventJob::class, 1);
        $this->assertNull($event->fresh()->processed_at);
    }

    #[Test]
    public function a_stale_unreceipted_event_is_redispatched_once_per_window(): void
    {
        $event = $this->dispatchedEvent();

        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();
        $this->assertSame(1, $this->reconcile()['redispatched']);
        $this->assertSame(0, $this->reconcile()['redispatched'], 'dispatched_at was bumped: not again within the window');

        Queue::assertPushed(ProcessOutboxEventJob::class, fn ($job) => $job->eventId === $event->id);
        Queue::assertPushed(ProcessOutboxEventJob::class, 2);
        $this->assertSame($event->attempts + 1, $event->fresh()->attempts);
        $this->assertSame('dispatched', $event->fresh()->status);
    }

    #[Test]
    public function an_event_every_consumer_already_receipted_is_acknowledged_not_replayed(): void
    {
        // e.g. a row processed before `processed_at` existed, or a worker
        // that died between its last receipt and the acknowledgement.
        $event = $this->dispatchedEvent();
        foreach (app(EventConsumerRegistry::class)->forEventType($event->event_type) as $consumer) {
            EventConsumerReceipt::query()->create([
                'consumer_name' => $consumer->name(), 'event_id' => $event->id, 'school_id' => $event->school_id, 'processed_at' => now(),
            ]);
        }

        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();
        $this->assertSame(['redispatched' => 0, 'acknowledged' => 1, 'failed' => 0], $this->reconcile());
        Queue::assertPushed(ProcessOutboxEventJob::class, 1);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    #[Test]
    public function a_partially_receipted_event_is_redispatched(): void
    {
        $event = $this->dispatchedEvent();
        $consumers = app(EventConsumerRegistry::class)->forEventType($event->event_type);
        $this->assertGreaterThan(1, count($consumers));
        EventConsumerReceipt::query()->create([
            'consumer_name' => $consumers[0]->name(), 'event_id' => $event->id, 'school_id' => $event->school_id, 'processed_at' => now(),
        ]);

        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();
        $this->assertSame(1, $this->reconcile()['redispatched']);
    }

    #[Test]
    public function reconciliation_is_bounded_by_attempts(): void
    {
        $event = $this->dispatchedEvent();
        DomainEventOutbox::query()->whereKey($event->id)->update(['attempts' => OutboxReconciler::MAX_ATTEMPTS]);

        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();
        $this->assertSame(['redispatched' => 0, 'acknowledged' => 0, 'failed' => 1], $this->reconcile());
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertSame('reconciliation_attempts_exhausted', $event->fresh()->last_error);
    }

    #[Test]
    public function the_batch_bound_is_respected(): void
    {
        $first = $this->dispatchedEvent();
        [, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'daily');
        Artisan::call('platform:outbox-dispatch');

        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();
        $this->assertSame(1, app(OutboxReconciler::class)->reconcile(1)['redispatched']);
        $this->assertSame(1, app(OutboxReconciler::class)->reconcile(1)['redispatched']);
        $this->assertSame(0, app(OutboxReconciler::class)->reconcile(1)['redispatched']);
        $this->assertSame(1, $first->fresh()->attempts - $first->attempts);
    }

    #[Test]
    public function a_successfully_processed_event_is_acknowledged_by_the_job(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $event = DomainEventOutbox::query()->where('school_id', $school->id)->latest('occurred_at')->firstOrFail();

        Artisan::call('platform:outbox-dispatch'); // sync queue in tests

        $this->assertNotNull($event->fresh()->processed_at);
        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();
        $this->assertSame(['redispatched' => 0, 'acknowledged' => 0, 'failed' => 0], $this->reconcile());
    }

    #[Test]
    public function the_dispatch_command_reports_recovery(): void
    {
        $this->dispatchedEvent();
        $this->travel(OutboxReconciler::STALE_AFTER_SECONDS + 1)->seconds();

        $this->artisan('platform:outbox-dispatch')
            ->expectsOutputToContain('recovered 1, acknowledged 0, failed 0')
            ->assertSuccessful();
    }
}
