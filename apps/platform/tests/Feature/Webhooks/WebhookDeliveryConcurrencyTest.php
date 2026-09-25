<?php

namespace Tests\Feature\Webhooks;

use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSubscriptionService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Support\LocalWebhookReceiver;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (Phase 0C.3 section 44/94): two
 * GENUINELY separate OS processes -- not two sequential calls in one
 * PHP process -- both run App\Jobs\DeliverWebhookJob::handle() for the
 * IDENTICAL delivery id against REAL PostgreSQL and a REAL local
 * receiver process. The processing-lease claim (webhook_deliveries'
 * migration) must ensure only ONE of them actually performs the HTTP
 * attempt.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the two subprocesses
 * are separate PostgreSQL sessions and can never see this test
 * process's uncommitted rows, mirroring
 * IdempotencyRealConcurrencyTest's identical reasoning.
 */
class WebhookDeliveryConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?LocalWebhookReceiver $receiver = null;

    private ?School $school = null;

    private ?User $user = null;

    protected function tearDown(): void
    {
        $this->receiver?->stop();

        if ($this->school !== null) {
            DomainEventOutbox::query()->where('school_id', $this->school->id)->delete();
            $this->deleteSchoolAsAdmin($this->school); // cascades endpoint/subscription/delivery/attempts
        }
        $this->user?->delete();

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_workers_do_not_both_deliver_the_same_logical_delivery(): void
    {
        $this->receiver = new LocalWebhookReceiver;
        [$this->user, $this->school] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);

        $eventId = (string) new UuidV7;
        DomainEventOutbox::query()->create([
            'id' => $eventId,
            'event_type' => 'school.setting.changed.v1',
            'event_version' => 1,
            'school_id' => $this->school->id,
            'correlation_id' => (string) new UuidV7,
            'payload' => ['key' => 'communications.digest_frequency', 'value' => 'weekly'],
            'metadata' => [],
            'occurred_at' => now(),
            'available_at' => now(),
            'status' => 'dispatched',
        ]);

        $delivery = $context->withSchool($this->school, function () use ($eventId) {
            $endpoint = WebhookEndpoint::query()->create([
                'school_id' => $this->school->id,
                'name' => 'concurrency-test',
                'url' => $this->receiver->url(),
                'secret_encrypted' => 'secret',
                'status' => 'active',
            ]);
            app(WebhookSubscriptionService::class)->subscribe($endpoint, 'school.setting.changed.v1');

            return WebhookDelivery::query()->create([
                'school_id' => $this->school->id,
                'webhook_endpoint_id' => $endpoint->id,
                'event_id' => $eventId,
                'event_type' => 'school.setting.changed.v1',
                'status' => 'pending',
            ]);
        });

        // The parent test process's runtime config(['webhooks.allow_
        // loopback_for_tests' => true]) call (made inside
        // LocalWebhookReceiver's constructor) does NOT propagate to
        // these child processes -- each boots Laravel fresh from its
        // own environment, so the double-guarded override (section 29)
        // must be passed explicitly as a real env var here.
        $env = ['WEBHOOKS_ALLOW_LOOPBACK_FOR_TESTS' => 'true'];
        $script = __DIR__.'/../../Support/run-webhook-delivery.php';
        $processA = new Process(['php', $script, $this->school->id, $delivery->id], null, $env);
        $processB = new Process(['php', $script, $this->school->id, $delivery->id], null, $env);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $this->assertSame(
            1,
            $this->receiver->requestCount(),
            'Exactly one real HTTP delivery attempt must have reached the receiver, regardless of two workers racing the same delivery.'
        );

        $finalDelivery = $context->withSchool($this->school, fn () => WebhookDelivery::query()->find($delivery->id));
        $this->assertSame('delivered', $finalDelivery->status);
        $this->assertSame(1, $finalDelivery->attempts);

        $attemptCount = $context->withSchool(
            $this->school,
            fn () => WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->count(),
        );
        $this->assertSame(1, $attemptCount, 'Exactly one durable attempt record, never two, under a real race.');
    }
}
