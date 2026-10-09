<?php

namespace App\Domain\Communications\Application\Portal;

use RuntimeException;

/**
 * POR.4 (ADR 0070 §27): a Guardian reply that was refused for a reason the
 * Guardian can act on. The messages are fixed and never echo the submitted
 * text. Anything about visibility or authority is NOT this exception -- that
 * is the same 404 / portal refusal as every other portal read.
 */
final class GuardianReplyException extends RuntimeException
{
    public const INVALID_BODY = 'invalid_body';

    public const NOT_OPEN = 'not_open';

    public const KEY_CONFLICT = 'key_conflict';

    private const MESSAGES = [
        self::INVALID_BODY => 'Write a message of up to '.GuardianConversationService::MAX_BODY_LENGTH.' characters.',
        self::NOT_OPEN => 'This conversation is closed to replies.',
        self::KEY_CONFLICT => 'This reply form has expired. Reload the conversation and try again.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    /** The form field the message belongs to. */
    public function field(): string
    {
        return $this->reason === self::KEY_CONFLICT ? 'idempotency_key' : 'body';
    }
}
