<?php

namespace Tests\Feature\Email;

use App\Models\EmailProviderReference;
use App\Support\Email\EmailPurpose;
use App\Support\Email\EmailState;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Email\Providers\OutboundEmail;
use App\Support\Email\Providers\SendGridConfiguration;
use App\Support\Email\Providers\SendGridEmailProvider;
use App\Support\Email\Providers\SmtpEmailProvider;
use App\Support\Email\Providers\SubmissionOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * ADR 0060 sections 6-10 and 28 (E18, development / non-production only):
 * the SendGrid Mail Send adapter. Every HTTP call is faked; stray requests
 * fail the test. Every value is synthetic -- no School, person or real
 * address ever appears here.
 */
class SendGridEmailProviderTest extends TestCase
{
    use CreatesEmailFixtures;

    /** Synthetic, key-shaped, never a real credential. */
    private const KEY = 'SG.phpunitOnlyKeyId01.phpunitOnlySecretNotARealKey0123456789';

    private const GLOBAL_URL = 'https://api.sendgrid.com/v3/mail/send';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->useSendGrid();
    }

    /** @param array<string, mixed> $overrides */
    private function useSendGrid(array $overrides = []): void
    {
        config([
            'email.provider' => 'sendgrid',
            'email.sendgrid' => [
                'api_key' => self::KEY,
                'region' => 'global',
                'mode' => 'sandbox',
                'test_recipient_domains' => ['school-os.test', 'example.test'],
                ...$overrides,
            ],
        ]);
    }

    private function outbound(EmailPurpose $purpose = EmailPurpose::SchoolCommunication, string $to = 'reader@example.test', array $attachments = []): OutboundEmail
    {
        return new OutboundEmail(
            messageId: '01920000-0000-7000-8000-000000000001',
            rfcMessageId: '01920000-0000-7000-8000-000000000001@notify.lycenza-suite.test',
            idempotencyKey: 'lycenza-email-01920000-0000-7000-8000-000000000001',
            purpose: $purpose,
            fromAddress: 'notifications@notify.lycenza-suite.test',
            fromName: 'Synthetic School via Lycenza',
            to: $to,
            subject: 'Synthetic subject',
            text: 'Synthetic body text',
            html: '<p>Synthetic body text</p>',
            attachments: $attachments,
        );
    }

    private function provider(): SendGridEmailProvider
    {
        return app(SendGridEmailProvider::class);
    }

    /** @return array<string, mixed> */
    private function sentPayload(): array
    {
        $payload = null;
        Http::assertSent(function (Request $request) use (&$payload) {
            $payload = $request->data();

            return true;
        });

        return (array) $payload;
    }

    #[Test]
    public function a_valid_non_production_configuration_selects_the_sendgrid_adapter(): void
    {
        $resolver = app(EmailProviderResolver::class);

        $this->assertSame('sendgrid', $resolver->mode());
        $this->assertInstanceOf(SendGridEmailProvider::class, $resolver->adapter());
        $this->assertNull($resolver->blockedReason());
        $this->assertSame('sendgrid', $resolver->adapter()->name());
        $this->assertFalse($resolver->adapter()->supportsIdempotencyKey(), 'ADR 0060 section 7: no idempotency key is documented');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidConfigurations(): array
    {
        return [
            'no key' => [['api_key' => null], 'api_key_invalid'],
            'placeholder key' => [['api_key' => 'changeme'], 'api_key_invalid'],
            'no region' => [['region' => null], 'region_invalid'],
            'free-form region' => [['region' => 'https://evil.example'], 'region_invalid'],
            'no mode' => [['mode' => null], 'mode_invalid'],
            'malformed mode' => [['mode' => 'live'], 'mode_invalid'],
            'boolean-ish mode' => [['mode' => 'false'], 'mode_invalid'],
            'no test domains' => [['test_recipient_domains' => []], 'test_recipient_domains_invalid'],
            'malformed test domain' => [['test_recipient_domains' => ['*']], 'test_recipient_domains_invalid'],
        ];
    }

    #[Test]
    #[DataProvider('invalidConfigurations')]
    public function a_missing_or_malformed_value_blocks_submission_and_never_falls_back(array $overrides, string $violation): void
    {
        $this->useSendGrid($overrides);
        $resolver = app(EmailProviderResolver::class);

        $this->assertSame($violation, app(SendGridConfiguration::class)->violation());
        $this->assertNull($resolver->adapter(), 'no adapter: never Mailpit/SMTP, never live delivery');
        $this->assertSame('provider_misconfigured', $resolver->blockedReason());
        $this->assertFalse($resolver->criticalEmailAvailable());
    }

    #[Test]
    public function tracking_enabled_anywhere_blocks_sendgrid(): void
    {
        config(['email.tracking.clicks' => true]);

        $this->assertSame('tracking_enabled', app(SendGridConfiguration::class)->violation());
        $this->assertSame('provider_misconfigured', app(EmailProviderResolver::class)->blockedReason());
    }

    #[Test]
    public function production_can_never_use_sendgrid_even_when_fully_configured(): void
    {
        $this->useSendGrid(['mode' => 'delivery']);
        $original = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $resolver = app(EmailProviderResolver::class);
            $this->assertSame('production_not_authorised', app(SendGridConfiguration::class)->violation());
            $this->assertNull($resolver->adapter());
            $this->assertSame('provider_not_authorised', $resolver->blockedReason());

            // Even a directly built adapter refuses before any network call.
            $result = app(SendGridEmailProvider::class)->submit($this->outbound());
            $this->assertSame(SubmissionOutcome::AuthFailure, $result->outcome);
            Http::assertNothingSent();
        } finally {
            $this->app['env'] = $original;
        }
    }

    #[Test]
    public function sandbox_mode_is_explicit_and_the_documented_200_is_accepted_without_delivery(): void
    {
        Http::fake([self::GLOBAL_URL => Http::response('', 200)]);

        $result = $this->provider()->submit($this->outbound());

        $this->assertSame(SubmissionOutcome::Accepted, $result->outcome);
        $this->assertNull($result->providerMessageId, 'sandbox mode returns no message id and emits no events');
        $this->assertSame(['sandbox_mode' => ['enable' => true]], $this->sentPayload()['mail_settings']);
    }

    #[Test]
    public function delivery_mode_omits_sandbox_and_links_the_x_message_id(): void
    {
        $this->useSendGrid(['mode' => 'delivery']);
        Http::fake([self::GLOBAL_URL => Http::response('', 202, ['X-Message-Id' => 'XBg2anf2TqCy6WXKQFhieQ'])]);

        $result = $this->provider()->submit($this->outbound());

        $this->assertSame(SubmissionOutcome::Accepted, $result->outcome);
        $this->assertSame('XBg2anf2TqCy6WXKQFhieQ', $result->providerMessageId);
        $this->assertArrayNotHasKey('mail_settings', $this->sentPayload());
    }

    #[Test]
    public function the_request_is_exactly_the_frozen_minimised_shape(): void
    {
        Http::fake([self::GLOBAL_URL => Http::response('', 200)]);

        $this->provider()->submit($this->outbound(EmailPurpose::SchoolCommunication));

        Http::assertSent(function (Request $request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame(self::GLOBAL_URL, $request->url());
            $this->assertSame('Bearer '.self::KEY, $request->header('Authorization')[0]);

            return true;
        });

        $payload = $this->sentPayload();
        $this->assertSame(['personalizations', 'from', 'subject', 'content', 'headers', 'tracking_settings', 'mail_settings'], array_keys($payload));
        $this->assertSame([['to' => [['email' => 'reader@example.test']]]], $payload['personalizations'], 'one recipient, address only, no name');
        $this->assertSame(['email' => 'notifications@notify.lycenza-suite.test', 'name' => 'Synthetic School via Lycenza'], $payload['from']);
        $this->assertSame('Synthetic subject', $payload['subject']);
        $this->assertSame([['type' => 'text/plain', 'value' => 'Synthetic body text'], ['type' => 'text/html', 'value' => '<p>Synthetic body text</p>']], $payload['content']);
        $this->assertSame(['Message-ID' => '<01920000-0000-7000-8000-000000000001@notify.lycenza-suite.test>'], $payload['headers'], 'standard mail: no Auto-Submitted');

        $json = (string) json_encode($payload);
        foreach (['custom_args', 'unique_args', 'categories', 'reply_to', 'reply_to_list', '"cc"', '"bcc"', 'send_at', 'batch_id', 'asm', 'ip_pool_name', 'template_id', 'bypass', 'school', 'user_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "{$forbidden} must never be sent (ADR 0060 sections 6 and 19)");
        }
    }

    #[Test]
    public function tracking_is_disabled_on_every_request_in_both_modes(): void
    {
        foreach (['sandbox' => 200, 'delivery' => 202] as $mode => $status) {
            $this->useSendGrid(['mode' => $mode]);
            Http::fake([self::GLOBAL_URL => Http::response('', $status, ['X-Message-Id' => 'abc'])]);

            $payload = $this->provider()->payload($this->outbound());

            $this->assertSame([
                'click_tracking' => ['enable' => false, 'enable_text' => false],
                'open_tracking' => ['enable' => false],
                'subscription_tracking' => ['enable' => false],
                'ganalytics' => ['enable' => false],
            ], $payload['tracking_settings'], $mode);
        }
    }

    #[Test]
    public function critical_mail_carries_auto_submitted(): void
    {
        foreach ([EmailPurpose::AccountInvitation, EmailPurpose::StaffAccountInvitation, EmailPurpose::AccountRecovery, EmailPurpose::SecurityNotice] as $purpose) {
            $headers = $this->provider()->payload($this->outbound($purpose))['headers'];
            $this->assertSame('auto-generated', $headers['Auto-Submitted'], $purpose->value);
            $this->assertSame(['Message-ID', 'Auto-Submitted'], array_keys($headers), $purpose->value);
        }
    }

    #[Test]
    public function the_eu_region_uses_only_the_eu_host(): void
    {
        $this->useSendGrid(['region' => 'eu']);
        Http::fake(['https://api.eu.sendgrid.com/v3/mail/send' => Http::response('', 200)]);

        $this->provider()->submit($this->outbound());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.eu.sendgrid.com/v3/mail/send');
    }

    #[Test]
    public function attachments_are_sent_base64_from_private_storage(): void
    {
        Storage::fake('private-test');
        Storage::disk('private-test')->put('comm/a.txt', 'synthetic attachment');
        Http::fake([self::GLOBAL_URL => Http::response('', 200)]);

        $this->provider()->submit($this->outbound(attachments: [['disk' => 'private-test', 'path' => 'comm/a.txt', 'name' => 'notes.txt', 'mime' => 'text/plain']]));

        $this->assertSame([[
            'content' => base64_encode('synthetic attachment'),
            'filename' => 'notes.txt',
            'type' => 'text/plain',
            'disposition' => 'attachment',
        ]], $this->sentPayload()['attachments']);
    }

    #[Test]
    public function an_unreadable_attachment_fails_permanently_without_a_request(): void
    {
        Storage::fake('private-test');

        $result = $this->provider()->submit($this->outbound(attachments: [['disk' => 'private-test', 'path' => 'missing.bin', 'name' => 'x.bin', 'mime' => 'application/octet-stream']]));

        $this->assertSame([SubmissionOutcome::PermanentFailure, 'content_rejected'], [$result->outcome, $result->code]);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_recipient_outside_the_test_domains_is_refused_before_any_request(): void
    {
        foreach (['someone@gmail.com', 'reader@sub.example.test', 'reader@example.test.evil.com', 'no-at-sign'] as $address) {
            $result = $this->provider()->submit($this->outbound(to: $address));
            $this->assertSame([SubmissionOutcome::PermanentFailure, 'recipient_not_permitted'], [$result->outcome, $result->code], $address);
        }

        Http::assertNothingSent();
    }

    /** @return array<string, array{0: int, 1: SubmissionOutcome, 2: string|null}> */
    public static function statuses(): array
    {
        return [
            'unauthorized' => [401, SubmissionOutcome::AuthFailure, 'authentication_failed'],
            'forbidden' => [403, SubmissionOutcome::AuthFailure, 'authentication_failed'],
            'bad request' => [400, SubmissionOutcome::PermanentFailure, 'content_rejected'],
            'too large' => [413, SubmissionOutcome::PermanentFailure, 'message_too_large'],
            'other 4xx' => [404, SubmissionOutcome::PermanentFailure, 'provider_error'],
            'throttled' => [429, SubmissionOutcome::TransientFailure, 'provider_throttled'],
            'timeout status' => [408, SubmissionOutcome::TransientFailure, 'provider_throttled'],
            'server error' => [500, SubmissionOutcome::TransientFailure, 'provider_unavailable'],
            'unavailable' => [503, SubmissionOutcome::TransientFailure, 'provider_unavailable'],
            'redirect is never followed' => [302, SubmissionOutcome::PermanentFailure, 'provider_error'],
        ];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function every_provider_status_is_classified_never_thrown(int $status, SubmissionOutcome $outcome, ?string $code): void
    {
        Http::fake([self::GLOBAL_URL => Http::response('{"errors":[{"message":"synthetic provider text"}]}', $status, $status === 302 ? ['Location' => 'https://elsewhere.example'] : [])]);

        $result = $this->provider()->submit($this->outbound());

        $this->assertSame([$outcome, $code], [$result->outcome, $result->code]);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function networkFailures(): array
    {
        return [
            'timeout' => ['cURL error 28: Operation timed out', 'timeout'],
            'connection refused' => ['cURL error 7: Failed to connect', 'network_error'],
        ];
    }

    #[Test]
    #[DataProvider('networkFailures')]
    public function network_failures_are_transient(string $message, string $code): void
    {
        Http::fake(fn () => throw new ConnectionException($message));

        $result = $this->provider()->submit($this->outbound());

        $this->assertSame([SubmissionOutcome::TransientFailure, $code], [$result->outcome, $result->code]);
    }

    #[Test]
    public function nothing_logged_carries_the_key_the_body_the_recipient_or_the_provider_text(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $this->useSendGrid(['mode' => 'delivery']);
        Http::fake([self::GLOBAL_URL => Http::sequence()
            ->push('', 202) // accepted without X-Message-Id: logged by code
            ->push('', 200) // unexpected status for the mode: logged by code
            ->push('{"errors":[{"message":"synthetic provider text"}]}', 500)]);

        for ($i = 0; $i < 3; $i++) {
            $this->provider()->submit($this->outbound());
        }

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            foreach ([self::KEY, 'Synthetic body text', 'Synthetic subject', 'reader@example.test', 'synthetic provider text'] as $secret) {
                $this->assertStringNotContainsString($secret, $line);
            }
        }
    }

    #[Test]
    public function the_email_layer_submits_through_sendgrid_and_records_the_provider_reference(): void
    {
        $this->holdEmailSubmission();
        $this->useSendGrid(['mode' => 'delivery']);
        Http::fake([self::GLOBAL_URL => Http::response('', 202, ['X-Message-Id' => 'synthetic-sg-id-1'])]);
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin, 'family@school-os.test');

        $row = $this->submitEmail($school, $email->id);

        $this->assertSame(EmailState::Submitted, $row->status);
        $this->assertSame(['sendgrid', 'synthetic-sg-id-1'], [$row->provider, $row->provider_message_id]);
        $this->assertNull($row->sealed_content, 'content is purged once accepted');
        $this->assertSame('sendgrid', EmailProviderReference::query()->where('email_message_id', $email->id)->value('provider'));
    }

    #[Test]
    public function the_email_layer_waits_rather_than_sending_when_sendgrid_is_misconfigured(): void
    {
        $this->holdEmailSubmission();
        $this->useSendGrid(['mode' => null]);
        Http::fake();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin, 'family@school-os.test');

        $row = $this->submitEmail($school, $email->id);

        $this->assertSame([EmailState::Pending, 'provider_misconfigured'], [$row->status, $row->status_code]);
        Http::assertNothingSent();
    }

    #[Test]
    public function operators_always_see_the_sendgrid_mode_and_why_it_is_blocked(): void
    {
        $this->artisan('platform:mail-status')->expectsOutputToContain('sendgrid mode: sandbox (development / non-production only)')->assertSuccessful();

        $this->useSendGrid(['mode' => 'live']);
        $this->artisan('platform:mail-status')
            ->expectsOutputToContain('(provider_misconfigured)')
            ->expectsOutputToContain('sendgrid mode: invalid')
            ->assertSuccessful();
    }

    #[Test]
    public function the_ddev_mailpit_values_select_the_smtp_adapter_never_sendgrid(): void
    {
        // .ddev/config.yaml's email lines (pinned by the release tooling's
        // test_email_provider_hygiene.py, which sees the whole checkout):
        // the hardened SMTP adapter to Mailpit, no SendGrid value at all.
        config([
            'email.provider' => 'smtp', 'email.smtp.tls' => 'none', 'email.smtp.host' => '127.0.0.1', 'email.smtp.port' => 1025,
            'email.sendgrid' => ['api_key' => null, 'region' => null, 'mode' => null, 'test_recipient_domains' => []],
        ]);

        $this->assertInstanceOf(SmtpEmailProvider::class, app(EmailProviderResolver::class)->adapter());
        $this->assertNull(app(EmailProviderResolver::class)->blockedReason());
    }
}
