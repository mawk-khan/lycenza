<?php

namespace App\Support\Email\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Throwable;

/**
 * TEST / LOCAL ONLY (`MAIL_PROVIDER_EVENTS=fake`; never resolved outside
 * local/testing and refused in production by ProductionConfigurationGuard).
 * An unmistakably fictional event feed used to exercise the real ingestion
 * path end to end: the header name, the payload shape and the vendor event
 * names below belong to no real provider.
 *
 * Header `X-Lycenza-Fake-Email-Signature: t=<unix>,v1=<hex>` where hex is
 * HMAC-SHA256(secret, "<t>.<raw body>") under any secret in the ring;
 * `t` must be within the configured tolerance (<= 300 s).
 *
 * Body: {"events": [{"id", "type", "message_id", "occurred_at", "bounce_class"?}]}
 * with fake types `delivered`, `deferred`, `soft_bounce`, `hard_bounce`,
 * `complaint`, `dropped`, and anything else ignored.
 */
final class FakeEmailEventAdapter implements EmailEventAdapter
{
    public const HEADER = 'X-Lycenza-Fake-Email-Signature';

    private const TYPES = [
        'delivered' => EmailEventType::Delivered,
        'deferred' => EmailEventType::Deferred,
        'soft_bounce' => EmailEventType::BounceTransient,
        'hard_bounce' => EmailEventType::BouncePermanent,
        'complaint' => EmailEventType::Complaint,
        'dropped' => EmailEventType::Rejected,
    ];

    public function __construct(private readonly Repository $config) {}

    /**
     * The fake feed stands in for the CONFIGURED submission provider's feed
     * (`fake` in the suite, `smtp` to Mailpit in DDEV): events are matched to
     * messages by (provider, provider message id), exactly as a real vendor's
     * would be.
     */
    public function provider(): string
    {
        return (string) $this->config->get('email.provider');
    }

    public function authenticate(Request $request): bool
    {
        $secrets = (array) $this->config->get('email.events.secrets', []);
        $header = (string) $request->header(self::HEADER, '');

        if ($secrets === [] || preg_match('/^t=(\d{1,12}),v1=([0-9a-f]{64})$/', $header, $m) !== 1) {
            return false;
        }

        if (abs(time() - (int) $m[1]) > min(300, (int) $this->config->get('email.events.timestamp_tolerance_seconds'))) {
            return false;
        }

        $signed = $m[1].'.'.$request->getContent();
        $valid = false;
        foreach ($secrets as $secret) {
            $valid = hash_equals(hash_hmac('sha256', $signed, (string) $secret), $m[2]) || $valid;
        }

        return $valid;
    }

    public function normalize(array $payload): array
    {
        $events = $payload['events'] ?? null;

        if (! is_array($events) || ! array_is_list($events)) {
            throw new InvalidEmailEventPayload('events must be a list');
        }

        return array_map(function ($event): NormalizedEmailEvent {
            if (! is_array($event) || ! is_string($event['message_id'] ?? null)) {
                throw new InvalidEmailEventPayload('malformed event');
            }

            $type = self::TYPES[$event['type'] ?? ''] ?? EmailEventType::Ignored;
            $messageId = mb_substr($event['message_id'], 0, 255);

            try {
                $occurred = is_string($event['occurred_at'] ?? null) ? CarbonImmutable::parse($event['occurred_at'])->utc() : null;
            } catch (Throwable) {
                throw new InvalidEmailEventPayload('malformed timestamp');
            }

            $bounceClass = $event['bounce_class'] ?? null;
            $bounceClass = is_string($bounceClass) && in_array($bounceClass, EmailEventType::BOUNCE_CLASSES, true) ? $bounceClass : null;

            $id = $event['id'] ?? null;
            $key = is_string($id) && preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $id) === 1
                ? 'id-'.$id
                : NormalizedEmailEvent::fingerprint($this->provider(), $messageId, $type, $occurred, (string) ($event['type'] ?? ''));

            return new NormalizedEmailEvent($key, $type, $messageId, $occurred, $bounceClass);
        }, $events);
    }

    /** Test/DDEV helper: the header value for a body under a secret. */
    public static function sign(string $body, string $secret, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret);
    }
}
