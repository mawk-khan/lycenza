<?php

namespace App\Support\Email\Providers;

/**
 * The outcome of one provider call. `code` is a closed, short machine code
 * (never a provider response text); `providerMessageId` only on acceptance.
 */
final class SubmissionResult
{
    /** The closed failure codes an adapter may report. */
    public const CODES = [
        'timeout', 'network_error', 'tls_unavailable', 'provider_throttled', 'provider_unavailable',
        'recipient_rejected', 'sender_rejected', 'content_rejected', 'message_too_large',
        'authentication_failed', 'provider_error',
    ];

    private function __construct(
        public readonly SubmissionOutcome $outcome,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $code = null,
    ) {}

    public static function accepted(?string $providerMessageId): self
    {
        $id = $providerMessageId !== null && trim($providerMessageId) !== '' ? mb_substr(trim($providerMessageId), 0, 255) : null;

        return new self(SubmissionOutcome::Accepted, $id);
    }

    public static function transient(string $code): self
    {
        return new self(SubmissionOutcome::TransientFailure, code: self::code($code));
    }

    public static function permanent(string $code): self
    {
        return new self(SubmissionOutcome::PermanentFailure, code: self::code($code));
    }

    public static function authFailure(string $code = 'authentication_failed'): self
    {
        return new self(SubmissionOutcome::AuthFailure, code: self::code($code));
    }

    private static function code(string $code): string
    {
        return in_array($code, self::CODES, true) ? $code : 'provider_error';
    }
}
