<?php

namespace Tests\Feature\Webhooks;

use App\Models\DomainEventOutbox;
use App\Models\SchoolAuditEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.3 section 48/90: the manual-redelivery service boundary,
 * exercised through the real HTTP API (capability + idempotency-key
 * protected), proving prior attempt history is preserved rather than
 * mutated.
 */
class WebhookDeliveryRedeliveryTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function createFailedDelivery($school, $user): WebhookDelivery
    {
        $context = app(TenantContext::class);

        $eventId = (string) new UuidV7;
        DomainEventOutbox::query()->create([
            'id' => $eventId,
            'event_type' => 'school.setting.changed.v1',
            'event_version' => 1,
            'school_id' => $school->id,
            'correlation_id' => (string) new UuidV7,
            'payload' => ['key' => 'communications.digest_frequency', 'value' => 'weekly'],
            'metadata' => [],
            'occurred_at' => now(),
            'available_at' => now(),
            'status' => 'dispatched',
        ]);

        return $context->withSchool($school, function () use ($school, $user, $eventId) {
            $endpoint = WebhookEndpoint::query()->create([
                'school_id' => $school->id,
                'name' => 'redeliver-test',
                'url' => 'https://8.8.8.8/hook',
                'secret_encrypted' => 'secret',
                'status' => 'active',
                'created_by_user_id' => $user->id,
            ]);

            $delivery = WebhookDelivery::query()->create([
                'school_id' => $school->id,
                'webhook_endpoint_id' => $endpoint->id,
                'event_id' => $eventId,
                'event_type' => 'school.setting.changed.v1',
                'status' => 'failed',
                'attempts' => 1,
            ]);

            WebhookDeliveryAttempt::query()->create([
                'school_id' => $school->id,
                'webhook_delivery_id' => $delivery->id,
                'attempt_number' => 1,
                'started_at' => now()->subMinute(),
                'completed_at' => now()->subMinute(),
                'response_status' => 404,
                'outcome' => 'permanent_failure',
                'error_class' => '404',
            ]);

            return $delivery;
        });
    }

    #[Test]
    public function redelivering_a_terminal_delivery_resets_it_to_pending_preserves_history_and_audits(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $delivery = $this->createFailedDelivery($school, $user);

        Http::fake(['*' => Http::response('ok', 200)]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'redeliver-key-001')
            ->postJson("/api/v1/schools/{$school->id}/webhook-deliveries/{$delivery->id}/redeliver")
            ->assertOk();

        // The redispatched job (afterCommit) has already run under the
        // sync queue connection by the time the HTTP response returns.
        $this->assertSame('delivered', $response->json('data.status'));

        $context = app(TenantContext::class);
        $attempts = $context->withSchool(
            $school,
            fn () => WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->orderBy('attempt_number')->get(),
        );
        $this->assertCount(2, $attempts, 'The original failed attempt must survive redelivery, not be deleted or overwritten.');
        $this->assertSame('permanent_failure', $attempts[0]->outcome);
        $this->assertSame(404, $attempts[0]->response_status);
        $this->assertSame('success', $attempts[1]->outcome);

        $auditCount = $context->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'integrations.webhook_delivery.redelivery_requested')->count(),
        );
        $this->assertSame(1, $auditCount);
    }

    #[Test]
    public function redelivering_a_non_terminal_delivery_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $delivery = $this->createFailedDelivery($school, $user);
        app(TenantContext::class)->withSchool($school, fn () => $delivery->update(['status' => 'delivering']));

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'redeliver-key-002')
            ->postJson("/api/v1/schools/{$school->id}/webhook-deliveries/{$delivery->id}/redeliver")
            ->assertUnprocessable();
    }

    #[Test]
    public function redelivery_requires_the_manage_capability(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal'); // view-only-ish, no manage
        $delivery = $this->createFailedDelivery($school, $user);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'redeliver-key-003')
            ->postJson("/api/v1/schools/{$school->id}/webhook-deliveries/{$delivery->id}/redeliver")
            ->assertForbidden();
    }

    #[Test]
    public function replaying_the_same_redelivery_request_does_not_redeliver_twice(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $delivery = $this->createFailedDelivery($school, $user);

        Http::fake(['*' => Http::response('ok', 200)]);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'redeliver-key-004');

        $first = $client->postJson("/api/v1/schools/{$school->id}/webhook-deliveries/{$delivery->id}/redeliver")->assertOk();
        $this->assertNull($first->headers->get('Idempotency-Replayed'));

        $second = $client->postJson("/api/v1/schools/{$school->id}/webhook-deliveries/{$delivery->id}/redeliver")->assertOk();
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $attemptCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->count(),
        );
        $this->assertSame(2, $attemptCount, 'The replayed request must not trigger a second real redelivery.');
    }
}
