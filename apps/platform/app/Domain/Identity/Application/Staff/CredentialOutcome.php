<?php

namespace App\Domain\Identity\Application\Staff;

use Illuminate\Support\MessageBag;

/**
 * Phase 0O.12B (ADR 0059 sections 10, 15): the result of consuming an
 * activation or staff-invitation credential. `invalid` is ONE outcome for
 * every credential failure (unknown selector, wrong secret, ended, expired,
 * changed version, ineligible account, School not active, issuer no longer
 * authorized) -- there is no token oracle.
 */
final class CredentialOutcome
{
    public const INVALID = 'invalid';

    public const POLICY_REJECTED = 'policy_rejected';

    /** A staff invitation for an address that already has an account: sign in as it first. */
    public const SIGN_IN_REQUIRED = 'sign_in_required';

    public const ACTIVATED = 'activated';

    public const ACCEPTED_NEW = 'accepted_new';

    public const ACCEPTED_EXISTING = 'accepted_existing';

    private function __construct(public readonly string $outcome, public readonly ?MessageBag $errors = null) {}

    public static function of(string $outcome): self
    {
        return new self($outcome);
    }

    public static function policyRejected(MessageBag $errors): self
    {
        return new self(self::POLICY_REJECTED, $errors);
    }

    public function succeeded(): bool
    {
        return in_array($this->outcome, [self::ACTIVATED, self::ACCEPTED_NEW, self::ACCEPTED_EXISTING], true);
    }
}
