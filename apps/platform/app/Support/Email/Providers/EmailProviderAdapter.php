<?php

namespace App\Support\Email\Providers;

/**
 * ADR 0055 section 6: the ONE provider boundary. Exactly one adapter is
 * configured at a time (`MAIL_PROVIDER`); nothing outside an adapter knows
 * a vendor's protocol, status codes or payloads.
 *
 * An adapter must:
 * - never throw for a provider failure -- classify it (SubmissionResult);
 * - bound every network call (connect <= 5 s, request <= 15 s);
 * - never log or return a credential, a response body or the message;
 * - use `OutboundEmail::$idempotencyKey` where the provider supports a
 *   submission idempotency key (optional -- ADR 0055 section 5), and the
 *   stable `rfcMessageId` always.
 *
 * A vendor's HTTPS API adapter implements this when a vendor is selected;
 * none is invented here.
 */
interface EmailProviderAdapter
{
    /** A short, stable provider name (stored with attempts and events). */
    public function name(): string;

    public function supportsIdempotencyKey(): bool;

    public function submit(OutboundEmail $email): SubmissionResult;
}
