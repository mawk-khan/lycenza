<?php

namespace App\Support\Email\Providers;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR 0060 sections 6-8 (E18, Phase 0O.13A): the Twilio SendGrid HTTPS v3
 * Mail Send adapter. DEVELOPMENT / NON-PRODUCTION ONLY (ADR 0060 section
 * 28): it refuses to submit in production, and the resolver and the boot
 * guard refuse it there too.
 *
 * What goes on the wire is exactly the frozen minimised request:
 * - one personalization with ONE `to` -- the address only, no name;
 * - the catalog From (address + sanitized display name), the subject,
 *   text/plain first and optional HTML, Communications attachments;
 * - headers `Message-ID` and, on critical mail, `Auto-Submitted`;
 * - `tracking_settings` all disabled, on every request;
 * - `mail_settings.sandbox_mode` only in sandbox mode.
 *
 * Never: `custom_args`, `categories`, a reply address, a copy recipient,
 * any School or User id, any `bypass_*` mail setting, or tracking.
 *
 * Every outcome is classified, never thrown, and never carries the
 * response body, the key or the message.
 */
final class SendGridEmailProvider implements EmailProviderAdapter
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const REQUEST_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly SendGridConfiguration $configuration,
        private readonly HttpFactory $http,
        private readonly FilesystemFactory $storage,
    ) {}

    public function name(): string
    {
        return 'sendgrid';
    }

    /** SendGrid's Mail Send API documents no idempotency key (ADR 0060 section 7). */
    public function supportsIdempotencyKey(): bool
    {
        return false;
    }

    public function submit(OutboundEmail $email): SubmissionResult
    {
        // Defence in depth: the resolver never hands this adapter out when
        // the configuration is invalid or the environment is production.
        if (! $this->configuration->valid()) {
            return SubmissionResult::authFailure('provider_error');
        }

        // OD-1 (ADR 0060 section 27.3): synthetic / operator-controlled test
        // mailboxes only, checked before anything leaves the process.
        if (! $this->configuration->recipientPermitted($email->to)) {
            return SubmissionResult::permanent('recipient_not_permitted');
        }

        $payload = $this->payload($email);
        if ($payload === null) {
            return SubmissionResult::permanent('content_rejected');
        }

        try {
            $response = $this->http
                ->withToken($this->configuration->apiKey())
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false])
                ->post($this->configuration->endpoint(), $payload);
        } catch (ConnectionException $e) {
            return str_contains(strtolower($e->getMessage()), 'timed out')
                ? SubmissionResult::transient('timeout')
                : SubmissionResult::transient('network_error');
        } catch (Throwable) {
            return SubmissionResult::transient('network_error');
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            // 202: accepted for delivery, `X-Message-Id` links the events.
            // 200: the documented sandbox-mode answer -- validated, never
            // delivered, no events. A 2xx is never resent (that could
            // duplicate the email); a missing id is recorded as such.
            $id = $response->header('X-Message-Id');
            $id = $id !== '' ? $id : null;

            if ($status !== ($this->configuration->sandbox() ? 200 : 202)) {
                Log::warning('email.provider.sendgrid_unexpected_success_status', ['email_message_id' => $email->messageId, 'status' => $status, 'sandbox' => $this->configuration->sandbox()]);
            }

            if ($id === null && ! $this->configuration->sandbox()) {
                Log::warning('email.provider.provider_message_id_missing', ['email_message_id' => $email->messageId, 'provider' => $this->name()]);
            }

            return SubmissionResult::accepted($id);
        }

        return self::classify($status);
    }

    /** ADR 0060 section 6 / ADR 0055 section 10: status -> outcome. */
    public static function classify(int $status): SubmissionResult
    {
        return match (true) {
            $status === 401, $status === 403 => SubmissionResult::authFailure(),
            $status === 413 => SubmissionResult::permanent('message_too_large'),
            $status === 400 => SubmissionResult::permanent('content_rejected'),
            $status === 408, $status === 429 => SubmissionResult::transient('provider_throttled'),
            $status >= 500 => SubmissionResult::transient('provider_unavailable'),
            default => SubmissionResult::permanent('provider_error'),
        };
    }

    /**
     * The frozen, minimised request body (ADR 0060 sections 6 and 10).
     *
     * @return array<string, mixed>|null null when an attachment cannot be read
     */
    public function payload(OutboundEmail $email): ?array
    {
        $content = [['type' => 'text/plain', 'value' => $email->text]];
        if ($email->html !== null) {
            $content[] = ['type' => 'text/html', 'value' => $email->html];
        }

        $payload = [
            'personalizations' => [['to' => [['email' => $email->to]]]],
            'from' => ['email' => $email->fromAddress, 'name' => $email->fromName],
            'subject' => $email->subject,
            'content' => $content,
            'headers' => ['Message-ID' => '<'.trim($email->rfcMessageId, '<>').'>', ...$email->additionalHeaders()],
            'tracking_settings' => [
                'click_tracking' => ['enable' => false, 'enable_text' => false],
                'open_tracking' => ['enable' => false],
                'subscription_tracking' => ['enable' => false],
                'ganalytics' => ['enable' => false],
            ],
        ];

        if ($email->attachments !== []) {
            $attachments = [];
            foreach ($email->attachments as $attachment) {
                try {
                    $bytes = $this->storage->disk($attachment['disk'])->get($attachment['path']);
                } catch (Throwable) {
                    $bytes = null;
                }
                if (! is_string($bytes)) {
                    return null;
                }
                $attachments[] = [
                    'content' => base64_encode($bytes),
                    'filename' => $attachment['name'],
                    'type' => $attachment['mime'],
                    'disposition' => 'attachment',
                ];
            }
            $payload['attachments'] = $attachments;
        }

        if ($this->configuration->sandbox()) {
            $payload['mail_settings'] = ['sandbox_mode' => ['enable' => true]];
        }

        return $payload;
    }
}
