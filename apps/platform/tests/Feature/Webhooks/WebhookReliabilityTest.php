<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Jobs\ProcessOutboxEventJob;
use App\Models\DomainEventOutbox;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use App\Support\Settings\SchoolSettingsService;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.3 section 92: the retry/classification matrix, driven by
 * Http::fake() for fast, deterministic coverage of every status-code
 * class -- see EndToEndProofBTest for the equivalent LIVE, unmocked
 * proof against a real local receiver process (section 95 requires
 * both, clearly distinguished).
 */
class WebhookReliabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function createSubscribedEndpoint($school, $user): WebhookEndpoint
    {
        $context = app(TenantContext::class);

        return $context->withSchool($school, function () use ($school, $user) {
            $endpoint = WebhookEndpoint::query()->create([
                'school_id' => $school->id,
                'name' => 'test',
                'url' => 'https://8.8.8.8/hook',
                'secret_encrypted' => 'secret',
                'status' => 'active',
            ]);
            app(WebhookSubscriptionService::class)->subscribe($endpoint, 'school.setting.changed.v1', $user);

            return $endpoint;
        });
    }

    private function fireEventAndDispatch($school): DomainEventOutbox
    {
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => DomainEventOutbox::query()->where('school_id', $school->id)->latest('occurred_at')->first(),
        );
        Artisan::call('platform:outbox-dispatch');

        return $event;
    }

    private function delivery($school, $endpoint): WebhookDelivery
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->firstOrFail(),
        );
    }

    #[Test]
    public function a_2xx_response_marks_the_delivery_delivered(): void
    {
        Http::fake(['*' => Http::response('ok', 202)]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);

        $this->fireEventAndDispatch($school);
        $delivery = $this->delivery($school, $endpoint);

        $this->assertSame('delivered', $delivery->status);
    }

    #[Test]
    public function a_500_schedules_a_retry_and_a_subsequent_200_succeeds(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);

        // A single fakeSequence, not two separate Http::fake() calls --
        // Laravel's wildcard URL stubs from an earlier fake() call are
        // NOT replaced by a later one (both remain registered, and the
        // first-registered match wins), so two fake() calls would keep
        // returning 500 on the "second" request too. fakeSequence()
        // is the correct tool for "first attempt fails, second
        // succeeds".
        Http::fakeSequence()->push('error', 500)->push('ok', 200);
        $this->fireEventAndDispatch($school);
        $delivery = $this->delivery($school, $endpoint);

        $this->assertSame('retrying', $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);

        app(TenantContext::class)->withSchool($school, fn () => DeliverWebhookJob::dispatchSync($school->id, $delivery->id));

        $delivery = app(TenantContext::class)->withSchool($school, fn () => $delivery->fresh());
        $this->assertSame('delivered', $delivery->status);

        $attempts = app(TenantContext::class)->withSchool(
            $school,
            fn () => WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->orderBy('attempt_number')->get(),
        );
        $this->assertCount(2, $attempts, 'Both the failed and the successful attempt must be preserved in history.');
        $this->assertSame('transient_failure', $attempts[0]->outcome);
        $this->assertSame('success', $attempts[1]->outcome);
    }

    #[Test]
    public function a_429_with_retry_after_schedules_a_retry_respecting_the_header(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '120'])]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);

        $this->fireEventAndDispatch($school);
        $delivery = $this->delivery($school, $endpoint);

        $this->assertSame('retrying', $delivery->status);
        // 120s Retry-After, +/-10% jitter (section 41) -- must be
        // clearly in that neighborhood, not the plain schedule's 60s.
        $this->assertTrue($delivery->next_attempt_at->diffInSeconds(now(), true) >= 100);
    }

    #[Test]
    public function an_absurd_retry_after_is_clamped_to_the_configured_maximum(): void
    {
        config(['webhooks.max_retry_after_seconds' => 3600]);
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '1000000000'])]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);

        $this->fireEventAndDispatch($school);
        $delivery = $this->delivery($school, $endpoint);

        $secondsAhead = now()->diffInSeconds($delivery->next_attempt_at, false);
        $this->assertLessThanOrEqual(3600 * 1.1 + 5, $secondsAhead, 'Must be clamped, not the raw absurd value.');
    }

    #[Test]
    public function a_404_is_a_permanent_failure_and_is_never_retried(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);

        $this->fireEventAndDispatch($school);
        $delivery = $this->delivery($school, $endpoint);

        $this->assertSame('failed', $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
    }

    #[Test]
    public function a_redirect_is_not_followed_and_is_classified_as_a_permanent_failure(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);

        $this->fireEventAndDispatch($school);
        $delivery = $this->delivery($school, $endpoint);

        $this->assertSame('failed', $delivery->status);
    }

    #[Test]
    public function exhausting_max_attempts_abandons_the_delivery(): void
    {
        config(['webhooks.max_attempts' => 2]);
        Http::fake(['*' => Http::response('error', 500)]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);

        $this->fireEventAndDispatch($school);
        $delivery = $this->delivery($school, $endpoint);
        $this->assertSame('retrying', $delivery->status);

        app(TenantContext::class)->withSchool($school, fn () => DeliverWebhookJob::dispatchSync($school->id, $delivery->id));

        $delivery = app(TenantContext::class)->withSchool($school, fn () => $delivery->fresh());
        $this->assertSame('abandoned', $delivery->status);
        $this->assertSame(2, $delivery->attempts);
    }

    #[Test]
    public function a_disabled_endpoint_receives_no_new_delivery(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);
        app(TenantContext::class)->withSchool($school, fn () => $endpoint->update(['status' => 'disabled']));

        Http::fake(['*' => Http::response('ok', 200)]);
        $this->fireEventAndDispatch($school);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->count(),
        );
        $this->assertSame(0, $count);
    }

    #[Test]
    public function replaying_the_same_domain_event_does_not_create_a_second_logical_delivery(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);

        $event = $this->fireEventAndDispatch($school);

        // Simulate consumer redelivery of the SAME outbox event.
        ProcessOutboxEventJob::dispatchSync($event->id);
        ProcessOutboxEventJob::dispatchSync($event->id);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => WebhookDelivery::query()->where('event_id', $event->id)->count(),
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_stale_processing_lease_can_be_reclaimed_by_the_redispatch_command(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->createSubscribedEndpoint($school, $user);

        // A real event + a real (uniquely-constrained) delivery row for
        // it, manually forced into `delivering` with an
        // already-expired lease -- simulating a worker that claimed
        // the job and then crashed before completing it.
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => DomainEventOutbox::query()->where('school_id', $school->id)->latest('occurred_at')->first(),
        );

        $delivery = app(TenantContext::class)->withSchool($school, fn () => WebhookDelivery::query()->create([
            'school_id' => $school->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => $event->id,
            'event_type' => $event->event_type,
            'status' => 'delivering',
            'processing_lease_expires_at' => now()->subMinute(),
        ]));

        Http::fake(['*' => Http::response('ok', 200)]);
        Artisan::call('platform:webhook-deliveries-redispatch');

        $delivery = app(TenantContext::class)->withSchool($school, fn () => $delivery->fresh());
        $this->assertSame('delivered', $delivery->status, 'A stale delivering lease must be recoverable, not stuck forever.');
    }
}
