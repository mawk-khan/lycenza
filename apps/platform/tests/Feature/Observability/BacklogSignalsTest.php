<?php

namespace Tests\Feature\Observability;

use App\Models\DomainEventOutbox;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Observability\Signals\OperationalSignals;
use App\Support\Settings\SchoolSettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §11, brief step 67): alert quality. Work that is
 * legitimately waiting -- a future next_attempt_at (backoff), a deferred
 * Communication, an outbox event scheduled for later -- is NEVER overdue;
 * eligible work that nobody picked up is, and its age survives the 0O.4A
 * redispatch `updated_at` bump. All from operational_work_backlog, kept
 * by triggers, read without any School context.
 */
class BacklogSignalsTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function in($school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function signals(): OperationalSignals
    {
        return app(OperationalSignals::class);
    }

    private function webhook(array $attributes): WebhookDelivery
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $event = DomainEventOutbox::query()->where('school_id', $school->id)->latest('occurred_at')->firstOrFail();

        return $this->in($school, function () use ($school, $event, $attributes) {
            $endpoint = WebhookEndpoint::query()->create([
                'school_id' => $school->id, 'name' => 'signals', 'url' => 'https://8.8.8.8/hook', 'secret_encrypted' => 'secret', 'status' => 'active',
            ]);

            return WebhookDelivery::query()->create([
                'school_id' => $school->id, 'webhook_endpoint_id' => $endpoint->id, 'event_id' => $event->id, 'event_type' => $event->event_type,
                ...$attributes,
            ]);
        });
    }

    #[Test]
    public function the_trigger_mirrors_only_unfinished_work_without_any_tenant_data(): void
    {
        $delivery = $this->webhook(['status' => 'pending']);

        $row = DB::table('operational_work_backlog')->where('item_id', $delivery->id)->first();
        $this->assertSame('webhook', $row->source);
        $this->assertSame('pending', $row->state);
        $this->assertSame(['source', 'item_id', 'state', 'state_since', 'next_attempt_at', 'lease_expires_at'], array_keys((array) $row));

        $this->in($delivery->school, fn () => $delivery->update(['status' => 'delivered']));
        $this->assertSame(0, DB::table('operational_work_backlog')->where('item_id', $delivery->id)->count(), 'finished work leaves the backlog');
    }

    #[Test]
    public function a_webhook_in_legitimate_backoff_is_never_overdue(): void
    {
        $this->webhook(['status' => 'retrying', 'next_attempt_at' => now()->addHours(8)]);
        $this->travel(7)->hours();

        $signal = $this->signals()->backlog('webhook');
        $this->assertSame(1, $signal->states['retrying']);
        $this->assertSame(0, $signal->overdue);
        $this->assertSame(0, $signal->oldestOverdueAgeSeconds);

        $this->travel(2)->hours();
        $this->assertSame(1, $this->signals()->backlog('webhook')->overdue, 'past next_attempt_at and not picked up: overdue');
    }

    #[Test]
    public function an_unclaimed_pending_webhook_ages_across_redispatch_bumps(): void
    {
        $this->webhook(['status' => 'pending']);
        $lease = (int) config('webhooks.processing_lease_seconds');

        $this->travel($lease - 5)->seconds();
        $this->assertSame(0, $this->signals()->backlog('webhook')->overdue, 'inside its first lease: not overdue');

        // Nobody works the integrations queue; the sweep keeps re-dispatching
        // (and bumping updated_at) every minute for 40 minutes.
        Queue::fake();
        foreach (range(1, 40) as $_) {
            $this->travel(60)->seconds();
            Artisan::call('platform:webhook-deliveries-redispatch');
        }

        $signal = $this->signals()->backlog('webhook');
        $this->assertSame(1, $signal->overdue);
        $this->assertGreaterThan((int) config('observability.thresholds.backlog_high_seconds'), $signal->oldestOverdueAgeSeconds, 'the bump never resets how long it has waited');
    }

    #[Test]
    public function a_delivering_webhook_is_overdue_only_after_its_lease(): void
    {
        $this->webhook(['status' => 'delivering', 'processing_lease_expires_at' => now()->addSeconds(60)]);

        $this->assertSame(0, $this->signals()->backlog('webhook')->overdue);
        $this->travel(61)->seconds();
        $this->assertSame(1, $this->signals()->backlog('webhook')->overdue);
    }

    #[Test]
    public function a_deferred_communication_is_never_overdue_but_an_unclaimed_immediate_one_is(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $message = $this->createMessage($this->createThread($school, $sender), $sender);
        $this->createDelivery($this->createRecipient($message, $recipient), ['status' => 'queued', 'next_attempt_at' => now()->addHours(3)]);

        $this->travel(2)->hours();
        $this->assertSame(0, $this->signals()->backlog('communication')->overdue);
        $this->assertSame(1, $this->signals()->deferredCommunications());

        $other = $this->createUser();
        $this->createMembership($other, $school);
        $this->createDelivery($this->createRecipient($message, $other), ['status' => 'pending']);
        $this->travel((int) config('communications.delivery.processing_lease_seconds') + 1)->seconds();

        $signal = $this->signals()->backlog('communication');
        $this->assertSame(1, $signal->overdue, 'only the immediate one');
        $this->assertSame(1, $this->signals()->deferredCommunications());
    }

    #[Test]
    public function an_automation_execution_waiting_for_its_backoff_is_not_overdue(): void
    {
        DB::table('operational_work_backlog')->insert([
            ['source' => 'automation', 'item_id' => (string) Str::uuid(), 'state' => 'pending', 'state_since' => now(), 'next_attempt_at' => now()->addSeconds(300), 'lease_expires_at' => null],
            ['source' => 'automation', 'item_id' => (string) Str::uuid(), 'state' => 'running', 'state_since' => now(), 'next_attempt_at' => null, 'lease_expires_at' => now()->addSeconds(120)],
        ]);

        $this->travel(100)->seconds();
        $this->assertSame(0, $this->signals()->backlog('automation')->overdue);

        $this->travel(250)->seconds();
        $signal = $this->signals()->backlog('automation');
        $this->assertSame(2, $signal->overdue);
        $this->assertSame(230, $signal->oldestOverdueAgeSeconds, 'the running lease expired 230 s ago');
    }

    #[Test]
    public function an_outbox_event_scheduled_for_later_is_not_backlog(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        DomainEventOutbox::query()->where('school_id', $school->id)->update(['available_at' => now()->addHour()]);

        $outbox = $this->signals()->outbox();
        $this->assertGreaterThanOrEqual(1, $outbox->pending);
        $this->assertLessThan(5, $outbox->oldestPendingAgeSeconds, 'a future event contributes no age');
    }

    #[Test]
    public function a_queue_signal_reports_zero_age_when_empty_and_never_counts_delayed_as_pending(): void
    {
        foreach ($this->signals()->queues() as $queue) {
            $this->assertSame(0, $queue->oldestPendingAgeSeconds ?? 0);
        }
    }
}
