<?php

namespace App\Domain\Identity\Application\AccountRecovery;

use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Models\EmailMessage;
use App\Support\Email\EmailSource;

/**
 * Phase 0O.10A (ADR 0056 section 9.3): the recovery side of an
 * `account_recovery` email. Still wanted only while its credential could
 * still be used: open (not consumed, invalidated or expired), the User
 * eligible, and the credential version and email unchanged. A consumed or
 * invalidated request's unsent email is therefore never submitted.
 */
final class AccountRecoveryEmailSource implements EmailSource
{
    public const SOURCE_TYPE = 'account_recovery_request';

    public function __construct(private readonly AccountRecoveryEligibility $eligibility) {}

    public function sourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public function isStillWanted(EmailMessage $message): bool
    {
        $request = AccountRecoveryRequest::query()->with('user')->find($message->source_id);
        $user = $request?->user;

        return $request !== null && $user !== null
            && $request->isOpen()
            && $request->credential_version === $user->credential_version
            && hash_equals($request->email_hash, hash('sha256', $user->email))
            && $this->eligibility->isEligible($user);
    }

    public function project(EmailMessage $message): void
    {
        // Delivery is never evidence the link was seen or used.
    }
}
