<?php

namespace Tests\Feature\Email;

use App\Jobs\ApplyEmailEventJob;
use App\Models\EmailEvent;
use App\Models\EmailSuppression;
use App\Support\Email\EmailState;
use App\Support\Email\EmailTelemetry;
use App\Support\Email\Events\EmailEventAdapterResolver;
use App\Support\Email\Events\EmailEventApplier;
use App\Support\Email\Events\SendGridEmailEventAdapter;
use App\Support\Email\Providers\SubmissionResult;
use App\Support\Email\Suppression\EmailSuppressionService;
use App\Support\Observability\MetricsRecorder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * ADR 0060 sections 11-16 and 28 (E18, development / non-production only):
 * the SendGrid Signed Event Webhook, with synthetic fixtures signed by a
 * P-256 key generated for each run -- no public tunnel, no real account, no
 * stored key. Events are built from SendGrid's documented field names.
 */
class SendGridEmailEventWebhookTest extends TestCase
{
    use CreatesEmailFixtures;

    private const URL = 'http://localhost/api/integrations/email-provider/events';

    private \OpenSSLAsymmetricKey $private;

    private string $publicB64;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->private, $this->publicB64] = self::keyPair();
        config(['email.events.adapter' => 'sendgrid', 'email.events.secrets' => [$this->publicB64]]);
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private static function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($key)['key'];
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem), true);

        return [$key, base64_encode($der)];
    }

    private function sign(string $body, ?int $timestamp = null, ?\OpenSSLAsymmetricKey $key = null): array
    {
        $t = (string) ($timestamp ?? time());
        openssl_sign($t.$body, $signature, $key ?? $this->private, OPENSSL_ALGO_SHA256);

        return [base64_encode($signature), $t];
    }

    /** Records counter calls (name + labels) made through EmailTelemetry. */
    private function recordMetrics(): \ArrayObject
    {
        $recorded = new \ArrayObject;
        $this->app->instance(MetricsRecorder::class, new class($recorded) implements MetricsRecorder
        {
            public function __construct(private \ArrayObject $recorded) {}

            public function counter(string $name, int|float $value = 1, array $labels = []): void
            {
                $this->recorded->append([$name, $labels]);
            }

            public function gauge(string $name, float $value, array $labels = []): void {}

            public function observe(string $name, float $seconds, array $labels = []): void {}
        });
        $this->app->forgetInstance(EmailTelemetry::class);

        return $recorded;
    }

    private function postSigned(string $body, ?array $signed = null, string $contentType = 'application/json'): TestResponse
    {
        [$signature, $timestamp] = $signed ?? $this->sign($body);

        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => $timestamp,
        ], $body);
    }

    /** A synthetic SendGrid event with the documented fields (recipient, IP and SMTP text included). */
    private function event(string $type, string $eventId, ?string $sgMessageId = 'SynthMsg001.filter0001.1.2.3', array $extra = []): array
    {
        return array_filter([
            'email' => 'synthetic.recipient@example.test',
            'timestamp' => time(),
            'event' => $type,
            'sg_event_id' => $eventId,
            'sg_message_id' => $sgMessageId,
            'ip' => '192.0.2.10',
            'response' => '250 synthetic smtp response',
            'tls' => 1,
            'smtp-id' => '<synthetic@example.test>',
            ...$extra,
        ], fn ($v) => $v !== null);
    }

    #[Test]
    public function a_signed_batch_is_stored_minimised_and_queued_once(): void
    {
        Queue::fake();
        $body = (string) json_encode([$this->event('delivered', 'synthetic-event-1')]);

        $this->postSigned($body)->assertStatus(202);
        $this->postSigned($body)->assertStatus(202);

        $event = EmailEvent::query()->sole();
        $this->assertSame(
            ['sendgrid', 'sg:'.hash('sha256', 'synthetic-event-1'), 'delivered', 'SynthMsg001', 'received'],
            [$event->provider, $event->event_key, $event->type, $event->provider_message_id, $event->result],
        );
        $this->assertSame(67, strlen($event->event_key));
        Queue::assertPushed(ApplyEmailEventJob::class, 1);

        // Nothing but the normalized fields is stored: no address, IP, SMTP
        // response, raw event id or payload, anywhere in the row.
        $row = (string) json_encode(DB::table('email_events')->first());
        foreach (['synthetic.recipient@example.test', '192.0.2.10', 'synthetic smtp response', 'synthetic-event-1', 'filter0001', 'smtp-id'] as $value) {
            $this->assertStringNotContainsString($value, $row);
        }
    }

    #[Test]
    public function missing_wrong_stale_future_tampered_and_foreign_signatures_are_refused(): void
    {
        $body = (string) json_encode([$this->event('delivered', 'synthetic-event-2')]);
        $otherKey = self::keyPair()[0];

        foreach ([
            'missing' => ['', (string) time()],
            'not base64' => ['%%%', (string) time()],
            'no timestamp' => [$this->sign($body)[0], ''],
            'non-numeric timestamp' => [$this->sign($body)[0], 'yesterday'],
            'stale' => $this->sign($body, time() - 330),
            'future' => $this->sign($body, time() + 330),
            'tampered body' => $this->sign($body.' '),
            'foreign key' => $this->sign($body, null, $otherKey),
        ] as $case => $signed) {
            $this->postSigned($body, $signed)->assertStatus(401)->assertContent('');
            $this->assertNotSame('', $case);
        }

        $this->assertSame(0, EmailEvent::query()->count());
    }

    #[Test]
    public function the_previous_ring_key_still_verifies_during_rotation(): void
    {
        [$newPrivate, $newPublic] = self::keyPair();
        config(['email.events.secrets' => [$newPublic, $this->publicB64]]);
        $body = (string) json_encode([$this->event('delivered', 'synthetic-event-3')]);

        $this->postSigned($body, $this->sign($body))->assertStatus(202);
        $this->postSigned($body, $this->sign($body, null, $newPrivate))->assertStatus(202);
    }

    #[Test]
    public function a_non_ec_or_garbage_ring_entry_never_verifies(): void
    {
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $rsaPublic = base64_encode(base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details($rsa)['key'])));

        $this->assertNull(SendGridEmailEventAdapter::publicKey($rsaPublic));
        $this->assertNull(SendGridEmailEventAdapter::publicKey('not-a-key'));
        $this->assertNotNull(SendGridEmailEventAdapter::publicKey($this->publicB64));

        config(['email.events.secrets' => ['phpunit-only-not-a-public-key-000000000000']]);
        $body = (string) json_encode([$this->event('delivered', 'synthetic-event-4')]);
        $this->postSigned($body)->assertStatus(401);
    }

    #[Test]
    public function only_a_json_array_is_a_valid_body(): void
    {
        $this->postSigned('{"event":"delivered"}')->assertStatus(400);
        $this->postSigned('not json')->assertStatus(400);
        $this->postSigned('[]', null, 'text/plain')->assertStatus(415);
    }

    #[Test]
    public function events_map_to_the_closed_vocabulary(): void
    {
        Queue::fake();
        $cases = [
            ['processed', [], 'ignored', null],
            ['delivered', [], 'delivered', null],
            ['deferred', ['attempt' => '1'], 'deferred', null],
            ['bounce', ['type' => 'bounce', 'bounce_classification' => 'Invalid Address'], 'bounce_permanent', 'mailbox_unknown'],
            ['bounce', ['type' => 'bounce', 'bounce_classification' => 'Mailbox Unavailable'], 'bounce_permanent', 'mailbox_full'],
            ['bounce', ['type' => 'bounce', 'bounce_classification' => 'Content'], 'bounce_permanent', 'content_rejected'],
            ['bounce', ['type' => 'bounce', 'bounce_classification' => 'Reputation'], 'bounce_permanent', 'policy_rejected'],
            ['bounce', ['type' => 'bounce', 'bounce_classification' => 'Frequency/Volume'], 'bounce_permanent', 'policy_rejected'],
            ['bounce', ['bounce_classification' => 'Technical'], 'bounce_permanent', 'other'],
            ['bounce', ['type' => 'blocked', 'bounce_classification' => 'Reputation'], 'rejected', 'policy_rejected'],
            ['dropped', ['reason' => 'Bounced Address'], 'rejected', 'provider_suppressed'],
            ['dropped', ['reason' => 'Unsubscribed Address'], 'rejected', 'provider_suppressed'],
            ['dropped', ['reason' => 'Spam Reporting Address'], 'rejected', 'provider_suppressed'],
            ['dropped', ['reason' => 'Invalid Address'], 'rejected', 'provider_suppressed'],
            ['dropped', ['reason' => 'Invalid SMTPAPI header'], 'rejected', 'other'],
            ['spamreport', [], 'complaint', null],
            ['unsubscribe', [], 'ignored', null],
            ['group_unsubscribe', ['asm_group_id' => 1, 'useragent' => 'synthetic-agent'], 'ignored', null],
            ['open', ['useragent' => 'synthetic-agent'], 'ignored', null],
            ['click', ['url' => 'https://example.test/x'], 'ignored', null],
            ['account_status_change', [], 'ignored', null],
            ['brand_new_type', [], 'ignored', null],
        ];

        $events = [];
        foreach ($cases as $i => [$type, $extra]) {
            $events[] = $this->event($type, "synthetic-map-{$i}", 'SynthMap'.$i.'.filter', $extra);
        }
        $this->postSigned((string) json_encode($events))->assertStatus(202);

        foreach ($cases as $i => [$type, , $expectedType, $expectedClass]) {
            $row = EmailEvent::query()->where('event_key', 'sg:'.hash('sha256', "synthetic-map-{$i}"))->sole();
            $this->assertSame([$expectedType, $expectedClass, 'SynthMap'.$i], [$row->type, $row->bounce_class, $row->provider_message_id], "{$i}: {$type}");
        }
    }

    #[Test]
    public function one_malformed_element_never_rejects_the_batch(): void
    {
        Queue::fake();
        $body = (string) json_encode([
            'not-an-object',
            ['event' => 'delivered', 'sg_message_id' => 'SynthNoEventId.filter'],
            $this->event('delivered', 'synthetic-good-1'),
        ]);

        $this->postSigned($body)->assertStatus(202);

        $this->assertSame(3, EmailEvent::query()->count());
        $this->assertSame(2, EmailEvent::query()->where('type', 'ignored')->count());
        $this->assertSame('delivered', EmailEvent::query()->where('event_key', 'sg:'.hash('sha256', 'synthetic-good-1'))->value('type'));
    }

    #[Test]
    public function sendgrid_batches_use_their_own_larger_bounds(): void
    {
        Queue::fake();
        $events = array_map(fn (int $i) => $this->event('delivered', "synthetic-bulk-{$i}"), range(1, 1000));
        $body = (string) json_encode($events);
        $this->assertGreaterThan((int) config('email.events.max_body_bytes'), strlen($body), 'beyond the global 256 KiB / 100-event bound');
        $this->assertLessThan(SendGridEmailEventAdapter::MAX_BODY_BYTES, strlen($body));

        $this->postSigned($body)->assertStatus(202);
        $this->assertSame(1000, EmailEvent::query()->count());

        $this->postSigned(str_repeat(' ', SendGridEmailEventAdapter::MAX_BODY_BYTES).'[]')->assertStatus(413);
        $this->postSigned((string) json_encode(array_fill(0, SendGridEmailEventAdapter::MAX_EVENTS + 1, 0)))->assertStatus(413);
    }

    #[Test]
    public function production_never_resolves_the_sendgrid_event_adapter(): void
    {
        $original = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->assertNull(app(EmailEventAdapterResolver::class)->active());
        } finally {
            $this->app['env'] = $original;
        }
    }

    #[Test]
    public function linked_bounces_complaints_and_provider_drops_suppress_through_the_stored_message(): void
    {
        $this->holdEmailSubmission();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        foreach ([
            'bounce' => [['type' => 'bounce', 'bounce_classification' => 'Invalid Address'], EmailState::Bounced, 'hard_bounce', 'all'],
            'spamreport' => [[], EmailState::Complained, 'complaint', 'standard'],
            'dropped' => [['reason' => 'Bounced Address'], EmailState::Failed, 'provider_suppressed', 'all'],
        ] as $type => [$extra, $state, $reason, $scope]) {
            $address = "{$type}@school-os.test";
            [$email] = $this->queueStandardEmail($school, $admin, $address);
            $this->fakeEmail()->queue(SubmissionResult::accepted("synthetic-{$type}"));
            $this->submitEmail($school, $email->id);
            $this->inSchool($school, fn () => DB::table('email_messages')->where('id', $email->id)->update(['provider' => 'sendgrid']));
            DB::table('email_provider_references')->where('email_message_id', $email->id)->update(['provider' => 'sendgrid']);

            $this->postSigned((string) json_encode([$this->event($type, "synthetic-apply-{$type}", "synthetic-{$type}.filter", $extra)]))->assertStatus(202);
            app(EmailEventApplier::class)->apply(EmailEvent::query()->where('event_key', 'sg:'.hash('sha256', "synthetic-apply-{$type}"))->value('id'));

            $this->assertSame($state, $this->emailRow($school, $email->id)->status, $type);
            $suppression = app(EmailSuppressionService::class)->blocking($address, $this->emailRow($school, $email->id)->kind);
            $this->assertNotNull($suppression, $type);
            $this->assertSame([$reason, $scope], [$suppression->reason, $suppression->scope], $type);
        }
    }

    #[Test]
    public function an_asynchronous_bounce_without_a_message_id_is_signalled_and_suppresses_nothing(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $metrics = $this->recordMetrics();

        // ADR 0060 section 28.4: SendGrid documents that delayed/asynchronous
        // bounces carry no message id. The event cannot be linked, and the
        // payload address is NOT used to correlate it.
        $body = (string) json_encode([$this->event('bounce', 'synthetic-async-1', null, ['type' => 'bounce', 'bounce_classification' => 'Invalid Address'])]);
        $this->postSigned($body)->assertStatus(202);

        $event = EmailEvent::query()->sole();
        $this->assertSame(['ignored', null], [$event->type, $event->provider_message_id]);
        app(EmailEventApplier::class)->apply($event->id);
        $this->assertSame('ignored', $event->fresh()->result);

        $this->assertSame(0, EmailSuppression::query()->count(), 'no suppression is invented from the payload address');
        $this->assertCount(1, array_filter($logged, fn (string $l) => str_starts_with($l, 'email.provider.async_bounce_unlinked')));
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('synthetic.recipient@example.test', $line);
        }
        $this->assertContains(['lycenza_email_provider_event_signals_total', ['outcome' => 'async_bounce_unlinked']], $metrics->getArrayCopy());
    }

    #[Test]
    public function the_next_send_to_an_async_bounced_address_is_suppressed_through_the_linked_drop(): void
    {
        // SendGrid itself keeps the bounced address on its bounce list; the
        // next message to it is `dropped` ("Bounced Address") -- an event
        // that DOES carry its message id -- and the existing linked path
        // suppresses the address for all mail. No new identifier is needed.
        $this->holdEmailSubmission();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin, 'async@school-os.test');
        $this->fakeEmail()->queue(SubmissionResult::accepted('synthetic-next-send'));
        $this->submitEmail($school, $email->id);
        DB::table('email_provider_references')->where('email_message_id', $email->id)->update(['provider' => 'sendgrid']);

        $this->postSigned((string) json_encode([$this->event('dropped', 'synthetic-drop-1', 'synthetic-next-send.filter', ['reason' => 'Bounced Address'])]))->assertStatus(202);
        app(EmailEventApplier::class)->apply(EmailEvent::query()->where('event_key', 'sg:'.hash('sha256', 'synthetic-drop-1'))->value('id'));

        $this->assertSame('provider_suppressed', app(EmailSuppressionService::class)->blocking('async@school-os.test', $this->emailRow($school, $email->id)->kind)?->reason);
    }

    #[Test]
    public function open_and_click_events_are_signalled_because_tracking_must_be_off(): void
    {
        Queue::fake();
        $metrics = $this->recordMetrics();

        $this->postSigned((string) json_encode([$this->event('open', 'synthetic-open-1', 'SynthOpen.filter', ['useragent' => 'synthetic-agent'])]))->assertStatus(202);

        $this->assertSame('ignored', EmailEvent::query()->sole()->type);
        $this->assertContains(['lycenza_email_provider_event_signals_total', ['outcome' => 'tracking_event_received']], $metrics->getArrayCopy());
    }
}
