<?php

namespace App\Support\Email\Events;

use App\Support\Email\EmailTelemetry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ADR 0060 sections 11-16 (E18, Phase 0O.13A): the Twilio SendGrid Signed
 * Event Webhook. DEVELOPMENT / NON-PRODUCTION ONLY (ADR 0060 section 28):
 * the resolver never hands it out in production and the boot guard refuses
 * it there.
 *
 * Authentication: ECDSA (P-256) / SHA-256 over the timestamp string
 * concatenated with the RAW body, verified against the operator-installed
 * public key ring (`email.events.secrets`: base64 DER, current + previous).
 * The timestamp must be an integer within +-300 s. No key is ever fetched,
 * and no key id or URL from the request is honoured.
 *
 * Normalization keeps ONLY: the normalized type, the event time, the
 * bounce class, `"sg:" + sha256(sg_event_id)` and the provider message id
 * (the `sg_message_id` prefix before the first "."). The recipient address,
 * IPs, SMTP responses, reasons, user agents and every other field are
 * dropped here and never stored. One bad element never rejects the batch:
 * it is normalized as `Ignored`.
 */
final class SendGridEmailEventAdapter implements DeclaresEventBounds, EmailEventAdapter
{
    public const SIGNATURE_HEADER = 'X-Twilio-Email-Event-Webhook-Signature';

    public const TIMESTAMP_HEADER = 'X-Twilio-Email-Event-Webhook-Timestamp';

    /** ADR 0060 section 12: SendGrid batches reach ~768 KB. */
    public const MAX_BODY_BYTES = 1_048_576;

    public const MAX_EVENTS = 5_000;

    /**
     * ADR 0060 section 13: `dropped` reasons meaning "the recipient is on a
     * SendGrid suppression list" (SendGrid Event Webhook reference,
     * reviewed 2026-10-13). Everything else is `other`.
     */
    public const SUPPRESSED_DROP_REASONS = ['bounced address', 'unsubscribed address', 'spam reporting address', 'invalid address'];

    public function __construct(
        private readonly Repository $config,
        private readonly EmailTelemetry $telemetry,
    ) {}

    public function provider(): string
    {
        return 'sendgrid';
    }

    public function maxBodyBytes(): int
    {
        return self::MAX_BODY_BYTES;
    }

    public function maxEvents(): int
    {
        return self::MAX_EVENTS;
    }

    public function authenticate(Request $request): bool
    {
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');
        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER, '');

        if ($signature === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return false;
        }

        $tolerance = min(300, (int) $this->config->get('email.events.timestamp_tolerance_seconds'));
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $der = base64_decode($signature, true);
        if ($der === false || $der === '') {
            return false;
        }

        $signed = $timestamp.$request->getContent();
        $valid = false;
        foreach ((array) $this->config->get('email.events.secrets', []) as $key) {
            $public = self::publicKey((string) $key);
            if ($public !== null && openssl_verify($signed, $der, $public, OPENSSL_ALGO_SHA256) === 1) {
                $valid = true;
            }
        }

        return $valid;
    }

    /**
     * A SendGrid verification key: base64 DER (as the console shows it) of
     * an EC public key. Anything else is refused.
     */
    public static function publicKey(string $base64Der): ?\OpenSSLAsymmetricKey
    {
        $der = base64_decode(trim($base64Der), true);
        if ($der === false || $der === '') {
            return null;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC ? $key : null;
    }

    public function normalize(array $payload): array
    {
        // SendGrid posts one JSON array; only a non-array body is invalid.
        if (! array_is_list($payload)) {
            throw new InvalidEmailEventPayload('events must be a list');
        }

        return array_map(fn ($element) => $this->normalizeOne($element), $payload);
    }

    private function normalizeOne(mixed $element): NormalizedEmailEvent
    {
        if (! is_array($element)) {
            return new NormalizedEmailEvent(NormalizedEmailEvent::fingerprint($this->provider(), null, EmailEventType::Ignored, null, 'non_object'), EmailEventType::Ignored, null, null);
        }

        $vendorType = is_string($element['event'] ?? null) ? $element['event'] : '';
        $occurred = self::timestamp($element['timestamp'] ?? null);
        $messageId = self::providerMessageId($element['sg_message_id'] ?? null);
        $eventId = is_string($element['sg_event_id'] ?? null) && $element['sg_event_id'] !== '' ? $element['sg_event_id'] : null;

        [$type, $bounceClass] = $this->classify($vendorType, $element);

        // ADR 0060 section 28.4: an asynchronous bounce carries no
        // `sg_message_id`. It cannot be linked to a message, so it changes
        // nothing and suppresses nothing -- and it is SIGNALLED, never
        // silently ignored. No address from the payload is used to
        // correlate it (that would expand the E17 data contract).
        if ($messageId === null && $vendorType === 'bounce' && $type === EmailEventType::BouncePermanent) {
            $this->telemetry->providerEventSignal('async_bounce_unlinked');
            Log::warning('email.provider.async_bounce_unlinked', ['provider' => $this->provider()]);
        }

        if (in_array($vendorType, ['open', 'click'], true)) {
            $this->telemetry->providerEventSignal('tracking_event_received');
            Log::warning('email.provider.tracking_event_received', ['provider' => $this->provider()]);
        }

        if ($eventId === null || $messageId === null) {
            // Section 12: never reject the batch for one element.
            return new NormalizedEmailEvent(
                $eventId !== null ? self::eventKey($eventId) : NormalizedEmailEvent::fingerprint($this->provider(), $messageId, EmailEventType::Ignored, $occurred, $vendorType),
                EmailEventType::Ignored,
                $messageId,
                $occurred,
            );
        }

        return new NormalizedEmailEvent(self::eventKey($eventId), $type, $messageId, $occurred, $bounceClass);
    }

    /**
     * ADR 0060 section 13.
     *
     * @param  array<mixed>  $element
     * @return array{EmailEventType, string|null}
     */
    private function classify(string $vendorType, array $element): array
    {
        return match ($vendorType) {
            'delivered' => [EmailEventType::Delivered, null],
            'deferred' => [EmailEventType::Deferred, null],
            'bounce' => ($element['type'] ?? 'bounce') === 'blocked'
                // The receiving server refused this message; the address is
                // not proven invalid and SendGrid does not suppress blocks.
                ? [EmailEventType::Rejected, 'policy_rejected']
                : [EmailEventType::BouncePermanent, self::bounceClass($element['bounce_classification'] ?? null)],
            'dropped' => [EmailEventType::Rejected, in_array(strtolower(trim((string) ($element['reason'] ?? ''))), self::SUPPRESSED_DROP_REASONS, true) ? 'provider_suppressed' : 'other'],
            'spamreport' => [EmailEventType::Complaint, null],
            // `processed` (acceptance is recorded from the 202), open/click
            // (tracking is off), (group_)unsubscribe/resubscribe (no
            // List-Unsubscribe in v1; never a preference change), account
            // status changes and anything unknown.
            default => [EmailEventType::Ignored, null],
        };
    }

    private static function bounceClass(mixed $classification): string
    {
        return match (strtolower(trim(is_string($classification) ? $classification : ''))) {
            'invalid address' => 'mailbox_unknown',
            'mailbox unavailable' => 'mailbox_full',
            'content' => 'content_rejected',
            'reputation', 'frequency/volume', 'frequency or volume too high' => 'policy_rejected',
            default => 'other',
        };
    }

    /** ADR 0060 section 14: deterministic, 67 characters, no migration. */
    public static function eventKey(string $sgEventId): string
    {
        return 'sg:'.hash('sha256', $sgEventId);
    }

    /** ADR 0060 section 15: the part before the first ".". */
    public static function providerMessageId(mixed $sgMessageId): ?string
    {
        if (! is_string($sgMessageId) || trim($sgMessageId) === '') {
            return null;
        }

        $prefix = explode('.', trim($sgMessageId), 2)[0];

        return $prefix === '' ? null : mb_substr($prefix, 0, 255);
    }

    /** Unix seconds (SendGrid sends an integer, sometimes as a string). */
    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,12}$/', $value) === 1)) {
            $seconds = (int) $value;

            return $seconds > 0 && $seconds < 100_000_000_000 ? CarbonImmutable::createFromTimestampUTC($seconds) : null;
        }

        return null;
    }
}
