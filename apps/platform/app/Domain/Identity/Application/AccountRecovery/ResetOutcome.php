<?php

namespace App\Domain\Identity\Application\AccountRecovery;

use Illuminate\Support\MessageBag;

/**
 * The reset's result. `Invalid` is ONE outcome for every credential failure
 * (unknown selector, wrong secret, consumed, invalidated, expired, changed
 * version or email, ineligible User) -- there is no token oracle.
 */
final class ResetOutcome
{
    public const SUCCEEDED = 'succeeded';

    public const INVALID = 'invalid';

    public const POLICY_REJECTED = 'policy_rejected';

    private function __construct(public readonly string $outcome, public readonly ?MessageBag $errors = null) {}

    public static function succeeded(): self
    {
        return new self(self::SUCCEEDED);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function policyRejected(MessageBag $errors): self
    {
        return new self(self::POLICY_REJECTED, $errors);
    }
}
