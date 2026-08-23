<?php

namespace Tests\Feature\Events;

use App\Jobs\ProcessOutboxEventJob;
use App\Models\DomainEventOutbox;
use App\Models\EventConsumerReceipt;
use App\Models\Notification;
use App\Support\Settings\SchoolSettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 67, REQUIRED END-TO-END PROOF A: authenticated School action
 * -> PostgreSQL transaction -> durable domain event outbox ->
 * dispatcher -> queue consumer -> idempotency receipt -> durable
 * audit/correlation. No business ERP module -- SchoolSettingsService
 * is Phase 0C's tiny demonstration action.
 */
class EndToEndProofATest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function the_full_chain_runs_end_to_end_and_is_idempotent_on_redelivery(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);
        // In a real request, ResolveSchoolContext middleware sets the
        // actor before any application code runs -- replicate that
        // here since this test calls the service directly.
        $context->setActor($user);

        // 1. Authenticated School action -> PostgreSQL transaction
        //    (SchoolSettingsService::set wraps write+audit+event in one
        //    transaction, proven separately in OutboxTransactionalityTest).
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly', $user);

        // 2. Durable domain event outbox.
        $event = $context->withSchool($school, fn () => DomainEventOutbox::query()->where('school_id', $school->id)->first());
        $this->assertNotNull($event);
        $this->assertSame('pending', $event->status);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertNotNull($event->correlation_id, 'correlation_id must propagate from TenantContext (section 34).');

        // 3. Dispatcher (real command, real FOR UPDATE SKIP LOCKED
        //    query) -> 4. Queue consumer. Under QUEUE_CONNECTION=sync
        //    (ADR 0024), ProcessOutboxEventJob::dispatch()->afterCommit()
        //    (called inside the dispatcher command) executes as soon as
        //    ITS enclosing transaction commits -- which happens as part
        //    of this single Artisan::call(), so the entire chain
        //    (dispatch -> job -> consumers -> receipt -> notification)
        //    is already complete by the time this call returns. No
        //    separate manual dispatchSync() is needed or correct here
        //    -- calling one immediately after would be a SECOND full
        //    execution, not "the first one running."
        Artisan::call('platform:outbox-dispatch');
        $event->refresh();
        $this->assertSame('dispatched', $event->status);
        $this->assertSame(1, $event->attempts);

        // 5. Idempotency receipt -- durable, not just "we didn't see an error."
        $this->assertSame(
            1,
            EventConsumerReceipt::query()
                ->where('consumer_name', 'notify-actor-of-setting-change')
                ->where('event_id', $event->id)
                ->count(),
        );

        // 6. Durable audit/correlation: the notification exists,
        //    correlated to the SAME id chain as the originating event.
        $notification = $context->withSchool(
            $school,
            fn () => Notification::query()->where('triggering_event_id', $event->id)->first(),
        );
        $this->assertNotNull($notification);
        $this->assertSame('sent', $notification->status);
        $this->assertSame($event->correlation_id, $notification->correlation_id);
        $this->assertSame($user->id, $notification->recipient_user_id);

        // Now explicitly simulate REDELIVERY (crash-window scenario,
        // section 51: "redelivered after side effect already happened")
        // by directly re-running the same job a second time.
        ProcessOutboxEventJob::dispatchSync($event->id);

        $this->assertSame(
            1,
            $context->withSchool($school, fn () => Notification::query()->where('triggering_event_id', $event->id)->count()),
            'Redelivering the same event must not create a second notification.',
        );
        $this->assertSame(
            1,
            EventConsumerReceipt::query()->where('consumer_name', 'notify-actor-of-setting-change')->where('event_id', $event->id)->count(),
            'Redelivery must not create a second receipt either.',
        );
    }
}
