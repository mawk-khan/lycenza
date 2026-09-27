<?php

namespace App\Domain\Communications\Application\Channels;

final class CommunicationDeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $status = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly bool $retryable = false,
    ) {}

    /**
     * In-app "delivery" is definitionally complete the instant the row
     * exists (Phase 5A.1) -- there is no external transport step, so
     * `delivered` is an honest terminal status for that channel alone.
     */
    public static function delivered(?string $providerReference = null): self
    {
        return new self(true, status: 'delivered', providerReference: $providerReference);
    }

    /**
     * Phase 5A.3 §19: a real external channel (email today) can only
     * ever honestly claim SENT -- "the configured transport accepted
     * the send operation without throwing" -- never DELIVERED/READ/
     * BOUNCED/REJECTED, which require provider-level evidence no
     * driver in this checkpoint has.
     */
    public static function sent(?string $providerReference = null): self
    {
        return new self(true, status: 'sent', providerReference: $providerReference);
    }

    /**
     * Phase 0O.9A (ADR 0055 section 9.5): handed to the platform email layer
     * -- durable, but NOT yet submitted to a provider, let alone delivered.
     * `providerReference` is the internal email message id. The delivery
     * then follows the email's state (CommunicationDeliveryEmailSource).
     */
    public static function accepted(string $emailMessageId): self
    {
        return new self(true, status: 'accepted', providerReference: $emailMessageId);
    }

    /**
     * `retryable` (brief §21): true only for a transient transport
     * condition worth a bounded retry (e.g. a connection failure). A
     * deterministic failure (no address, channel disabled, recipient
     * ineligible) must pass false -- App\Jobs\ProcessCommunicationDeliveryJob
     * never retries those regardless of remaining attempts.
     */
    public static function failed(string $failureCode, ?string $failureMessage = null, bool $retryable = false): self
    {
        return new self(false, failureCode: $failureCode, failureMessage: $failureMessage, retryable: $retryable);
    }
}
