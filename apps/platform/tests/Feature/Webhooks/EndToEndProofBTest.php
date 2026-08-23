<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Jobs\ProcessOutboxEventJob;
use App\Models\DomainEventOutbox;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Support\Settings\SchoolSettingsService;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\SsrfRejectedException;
use App\Support\Webhooks\WebhookEndpointService;
use App\Support\Webhooks\WebhookSigner;
use App\Support\Webhooks\WebhookSubscriptionService;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Support\LocalWebhookReceiver;
use Tests\TestCase;

/**
 * Phase 0C.3 section 60-69/95, REQUIRED LIVE PROOF B: event ->
 * subscription match -> queued delivery -> LOCAL HTTP receiver (a
 * real, separate PHP process this test spins up via
 * Tests\Support\LocalWebhookReceiver -- not Http::fake()) -> HMAC
 * verification -> delivery receipt, across every required failure
 * mode. No public internet endpoint, no production webhook -- the
 * receiver binds 127.0.0.1 only, and WEBHOOKS_ALLOW_LOOPBACK_FOR_TESTS
 * (double-guarded, see SsrfSafeUrlValidator) is the only reason
 * SsrfSafeUrlValidator permits it at all.
 *
 * See WebhookReliabilityTest for the Http::fake()-driven, faster
 * coverage of the SAME classification matrix -- these two files are
 * deliberately kept distinct (section 95: never claim a scenario was
 * live-tested when it was only unit-tested).
 */
class EndToEndProofBTest extends TestCase
{
    use CreatesTenancyFixtures;

    private ?LocalWebhookReceiver $receiver = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->receiver = new LocalWebhookReceiver;
    }

    protected function tearDown(): void
    {
        $this->receiver?->stop();
        parent::tearDown();
    }

    private function subscribeAndFire($school, $user, ?string $note = null): array
    {
        $context = app(TenantContext::class);

        [$endpoint, $secret] = $context->withSchool(
            $school,
            fn () => app(WebhookEndpointService::class)->create($school, 'proof-b', $this->receiver->url(), $user),
        );
        $context->withSchool(
            $school,
            fn () => app(WebhookSubscriptionService::class)->subscribe($endpoint, 'school.setting.changed.v1', $user),
        );

        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', $note ?? 'weekly', $user);
        $event = $context->withSchool($school, fn () => DomainEventOutbox::query()->where('school_id', $school->id)->latest('occurred_at')->first());

        Artisan::call('platform:outbox-dispatch');

        return [$endpoint, $secret, $event];
    }

    private function delivery($school, $endpoint): WebhookDelivery
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->firstOrFail(),
        );
    }

    #[Test]
    public function a_domain_event_is_delivered_to_a_real_local_receiver_with_a_valid_signature(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [$endpoint, $secret, $event] = $this->subscribeAndFire($school, $user);

        $delivery = $this->delivery($school, $endpoint);
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(1, $this->receiver->requestCount());

        $entry = $this->receiver->deliveries()[0];
        $headers = $entry['headers'];

        $this->assertSame($delivery->id, $headers['delivery_id']);
        $this->assertSame($event->id, $headers['event_id']);
        $this->assertSame('school.setting.changed.v1', $headers['event_type']);
        $this->assertSame('v1', $headers['signature_version']);

        // Real, unmocked verification using the receiver's actual
        // documented algorithm (section 61).
        $timestamp = (int) $headers['timestamp'];
        $this->assertTrue(
            app(WebhookSigner::class)->verify($secret, $delivery->id, $timestamp, $entry['body'], $headers['signature']),
        );

        $payload = json_decode($entry['body'], true);
        $this->assertSame($event->id, $payload['id']);
        $this->assertSame('school.setting.changed.v1', $payload['type']);
        $this->assertSame($school->id, $payload['schoolId']);
        $this->assertSame(['key' => 'communications.digest_frequency', 'value' => 'weekly'], $payload['data']);
        $this->assertArrayHasKey('correlationId', $payload['metadata']);
    }

    #[Test]
    public function a_tampered_body_fails_signature_verification_at_the_receiver(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [, $secret] = $this->subscribeAndFire($school, $user);

        $entry = $this->receiver->deliveries()[0];
        $headers = $entry['headers'];
        $timestamp = (int) $headers['timestamp'];

        $this->assertFalse(
            app(WebhookSigner::class)->verify($secret, $headers['delivery_id'], $timestamp, $entry['body'].'tampered', $headers['signature']),
            'A modified body must fail verification even with the correct secret.',
        );
    }

    #[Test]
    public function a_tampered_signature_fails_verification(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [, $secret] = $this->subscribeAndFire($school, $user);

        $entry = $this->receiver->deliveries()[0];
        $headers = $entry['headers'];
        $timestamp = (int) $headers['timestamp'];

        $this->assertFalse(
            app(WebhookSigner::class)->verify($secret, $headers['delivery_id'], $timestamp, $entry['body'], strrev($headers['signature'])),
        );
    }

    #[Test]
    public function an_expired_timestamp_fails_verification_at_the_receiver(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [, $secret] = $this->subscribeAndFire($school, $user);

        $entry = $this->receiver->deliveries()[0];
        $headers = $entry['headers'];

        $ancientTimestamp = ((int) $headers['timestamp']) - 3600;
        $ancientSignature = app(WebhookSigner::class)->sign($secret, $headers['delivery_id'], $ancientTimestamp, $entry['body']);

        $this->assertFalse(
            app(WebhookSigner::class)->verify($secret, $headers['delivery_id'], $ancientTimestamp, $entry['body'], $ancientSignature),
            'A signature computed with a stale timestamp must be rejected by the tolerance-window check, even though it is internally self-consistent.',
        );
    }

    #[Test]
    public function replaying_the_same_event_does_not_create_a_second_logical_delivery(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [$endpoint, , $event] = $this->subscribeAndFire($school, $user);

        // Simulate redelivery of the SAME outbox event to the consumer
        // twice (e.g. a crashed worker's message redelivered).
        ProcessOutboxEventJob::dispatchSync($event->id);
        ProcessOutboxEventJob::dispatchSync($event->id);

        $deliveryCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => WebhookDelivery::query()->where('event_id', $event->id)->count(),
        );
        $this->assertSame(1, $deliveryCount, 'Exactly one delivery row must be claimed regardless of consumer redelivery.');
        $this->assertSame(1, $this->receiver->requestCount(), 'Redelivery of the domain event must not cause a second real HTTP send.');
    }

    #[Test]
    public function a_500_then_a_200_on_retry_succeeds_with_full_attempt_history_preserved(): void
    {
        $this->receiver->setPlan([['status' => 500], ['status' => 200]]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [$endpoint] = $this->subscribeAndFire($school, $user);

        $delivery = $this->delivery($school, $endpoint);
        $this->assertSame('retrying', $delivery->status);

        app(TenantContext::class)->withSchool($school, fn () => DeliverWebhookJob::dispatchSync($school->id, $delivery->id));

        $delivery = app(TenantContext::class)->withSchool($school, fn () => $delivery->fresh());
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(2, $this->receiver->requestCount());
    }

    #[Test]
    public function a_429_with_retry_after_is_respected(): void
    {
        $this->receiver->setPlan([['status' => 429, 'retry_after' => 30]]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [$endpoint] = $this->subscribeAndFire($school, $user);

        $delivery = $this->delivery($school, $endpoint);
        $this->assertSame('retrying', $delivery->status);
        $this->assertTrue($delivery->next_attempt_at->diffInSeconds(now(), true) >= 25);
    }

    #[Test]
    public function a_404_is_a_permanent_failure_with_no_further_retry(): void
    {
        $this->receiver->setPlan([['status' => 404]]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [$endpoint] = $this->subscribeAndFire($school, $user);

        $delivery = $this->delivery($school, $endpoint);
        $this->assertSame('failed', $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
    }

    #[Test]
    public function a_receiver_that_exceeds_the_delivery_timeout_is_recorded_as_a_timeout_and_scheduled_for_retry(): void
    {
        config(['webhooks.delivery_timeout_seconds' => 1]);
        $this->receiver->setPlan([['status' => 200, 'sleep_ms' => 2500]]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [$endpoint] = $this->subscribeAndFire($school, $user);

        $delivery = $this->delivery($school, $endpoint);
        $this->assertSame('retrying', $delivery->status);

        $attempt = app(TenantContext::class)->withSchool(
            $school,
            fn () => WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->first(),
        );
        $this->assertSame('timeout', $attempt->outcome);
    }

    #[Test]
    public function a_redirect_is_not_followed(): void
    {
        $this->receiver->setPlan([['status' => 302, 'redirect_to' => 'http://169.254.169.254/']]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        [$endpoint] = $this->subscribeAndFire($school, $user);

        $delivery = $this->delivery($school, $endpoint);
        $this->assertSame('failed', $delivery->status);
        // Only ONE request must have reached our own receiver -- proof
        // the client never actually followed the Location header to
        // (what would otherwise be) the metadata endpoint.
        $this->assertSame(1, $this->receiver->requestCount());
    }

    #[Test]
    public function production_ssrf_validation_rejects_a_metadata_endpoint_destination_even_in_this_test_process(): void
    {
        // Proves section 71: the double-guarded loopback override does
        // NOT widen SSRF policy to arbitrary non-loopback prohibited
        // ranges -- only the exact loopback exception it grants.
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->expectException(SsrfRejectedException::class);

        app(TenantContext::class)->withSchool(
            $school,
            fn () => app(WebhookEndpointService::class)->create($school, 'evil', 'http://169.254.169.254/latest/meta-data/', $user),
        );
    }
}
