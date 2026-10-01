<?php

namespace Tests\Feature\Email;

use App\Jobs\ApplyEmailEventJob;
use App\Models\EmailEvent;
use App\Support\Email\Events\EmailEventAdapterResolver;
use App\Support\Email\Events\FakeEmailEventAdapter;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 section 11.2): the provider-event webhook's
 * boundary -- host, authentication, bounds, dedupe and tenancy. Uses the
 * test-only fake event adapter; production refuses it (guard-tested).
 */
class EmailProviderEventWebhookTest extends TestCase
{
    use CreatesEmailFixtures, CreatesSchoolDomains;

    private const URL = 'http://localhost/api/integrations/email-provider/events';

    private function secret(): string
    {
        return (string) config('email.events.secrets')[0];
    }

    /** @param array<mixed> $events */
    private function postEvents(array $events, ?string $signature = null, ?string $url = null, string $contentType = 'application/json', ?string $body = null): TestResponse
    {
        $body ??= (string) json_encode(['events' => $events]);

        return $this->call('POST', $url ?? self::URL, [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_LYCENZA_FAKE_EMAIL_SIGNATURE' => $signature ?? FakeEmailEventAdapter::sign($body, $this->secret()),
        ], $body);
    }

    /** @return array<string, string> */
    private function event(string $id, string $providerMessageId, string $type = 'delivered'): array
    {
        return ['id' => $id, 'type' => $type, 'message_id' => $providerMessageId, 'occurred_at' => now()->toIso8601String()];
    }

    #[Test]
    public function an_authenticated_event_is_stored_normalized_once_and_queued(): void
    {
        Queue::fake();

        $this->postEvents([$this->event('evt-1', 'fake-abc', 'hard_bounce')])->assertStatus(202)->assertCookieMissing('school-os-session');
        $this->postEvents([$this->event('evt-1', 'fake-abc', 'hard_bounce')])->assertStatus(202);

        $event = EmailEvent::query()->sole();
        $this->assertSame(['fake', 'id-evt-1', 'bounce_permanent', 'fake-abc', 'received'], [$event->provider, $event->event_key, $event->type, $event->provider_message_id, $event->result]);
        Queue::assertPushed(ApplyEmailEventJob::class, 1);
    }

    #[Test]
    public function missing_wrong_stale_and_foreign_signatures_are_refused_identically(): void
    {
        $body = (string) json_encode(['events' => [$this->event('evt-2', 'fake-x')]]);

        foreach ([
            'missing' => '',
            'garbage' => 'not-a-signature',
            'wrong secret' => FakeEmailEventAdapter::sign($body, 'another-deployment-secret-0000000000000'),
            // Well outside the 300 s window: a one-second margin was flaky,
            // because `future` became exactly 300 s (accepted) whenever a
            // second boundary passed between signing and verifying.
            'stale' => FakeEmailEventAdapter::sign($body, $this->secret(), time() - 330),
            'future' => FakeEmailEventAdapter::sign($body, $this->secret(), time() + 330),
            'other body' => FakeEmailEventAdapter::sign($body.' ', $this->secret()),
        ] as $case => $signature) {
            $response = $this->postEvents([], $signature, body: $body);
            $response->assertStatus(401);
            $this->assertSame('', $response->getContent(), $case);
        }

        $this->assertSame(0, EmailEvent::query()->count());
    }

    #[Test]
    public function the_previous_secret_stays_valid_during_rotation(): void
    {
        Queue::fake();
        $old = $this->secret();
        config(['email.events.secrets' => ['rotated-current-mail-event-secret-00000000', $old]]);
        $body = (string) json_encode(['events' => [$this->event('evt-3', 'fake-y')]]);

        $this->postEvents([], FakeEmailEventAdapter::sign($body, $old), body: $body)->assertStatus(202);
        $this->postEvents([], FakeEmailEventAdapter::sign($body, 'rotated-current-mail-event-secret-00000000'), body: $body)->assertStatus(202);
        config(['email.events.secrets' => ['rotated-current-mail-event-secret-00000000']]);
        $this->postEvents([], FakeEmailEventAdapter::sign($body, $old), body: $body)->assertStatus(401);
    }

    #[Test]
    public function oversized_too_deep_too_many_and_malformed_bodies_are_rejected(): void
    {
        Queue::fake();

        $big = str_repeat('a', 262145);
        $this->postEvents([], body: $big)->assertStatus(413);

        $deep = (string) json_encode(['events' => [$this->event('evt-4', 'fake-z')], 'x' => array_reduce(range(1, 40), fn ($carry) => ['n' => $carry], [])]);
        $this->postEvents([], body: $deep)->assertStatus(400);

        $many = array_map(fn (int $i) => $this->event("evt-many-{$i}", 'fake-z'), range(1, 101));
        $this->postEvents($many)->assertStatus(413);

        $this->postEvents([], body: '{"events": "nope"}')->assertStatus(400);
        $this->postEvents([], body: 'not json')->assertStatus(400);
        $this->postEvents([$this->event('evt-5', 'fake-z')], contentType: 'text/plain')->assertStatus(415);

        $this->assertSame(0, EmailEvent::query()->count());
    }

    #[Test]
    public function a_school_id_in_the_payload_is_ignored_and_no_school_context_is_set(): void
    {
        Queue::fake();
        [, $school] = $this->createSchoolAdmin('school_admin');

        $this->postEvents([array_merge($this->event('evt-6', 'fake-q'), ['school_id' => $school->id, 'schoolId' => $school->id])])->assertStatus(202);

        $this->assertFalse(app(TenantContext::class)->hasSchool());
        $this->assertArrayNotHasKey('school_id', EmailEvent::query()->sole()->getAttributes());
    }

    #[Test]
    public function the_route_exists_only_on_the_platform_host(): void
    {
        Queue::fake();
        config(['app.url' => 'https://app.lycenza-platform.com', 'domains.internal_hosts' => ['platform-internal.lycenza-ops.net']]);
        [, $school] = $this->createSchoolAdmin('school_admin');
        $this->createSchoolDomain($school, 'erp.northfield.org');

        $this->postEvents([$this->event('evt-7', 'fake-h')], url: 'https://erp.northfield.org/api/integrations/email-provider/events')->assertStatus(404);
        $this->postEvents([$this->event('evt-7', 'fake-h')], url: 'https://platform-internal.lycenza-ops.net/api/integrations/email-provider/events')->assertStatus(404);
        $this->postEvents([$this->event('evt-7', 'fake-h')], url: 'https://unknown.example.org/api/integrations/email-provider/events')->assertStatus(421);
        $this->assertSame(0, EmailEvent::query()->count());

        $this->postEvents([$this->event('evt-7', 'fake-h')], url: 'https://app.lycenza-platform.com/api/integrations/email-provider/events')->assertStatus(202);
    }

    #[Test]
    public function without_an_event_adapter_the_route_answers_404(): void
    {
        config(['email.events.adapter' => 'none']);

        $this->postEvents([$this->event('evt-8', 'fake-n')])->assertStatus(404);
        $this->assertSame(0, EmailEvent::query()->count());
    }

    #[Test]
    public function the_fake_adapter_is_never_resolved_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->assertNull(app(EmailEventAdapterResolver::class)->active());
            $this->assertNull(app(EmailProviderResolver::class)->adapter());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    #[Test]
    public function the_webhook_is_rate_limited_per_source(): void
    {
        Queue::fake();
        config(['email.events.per_minute' => 3]);

        foreach (range(1, 3) as $i) {
            $this->postEvents([$this->event("evt-r{$i}", 'fake-r')])->assertStatus(202);
        }
        $this->postEvents([$this->event('evt-r4', 'fake-r')])->assertStatus(429);
    }
}
