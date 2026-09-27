<?php

namespace App\Domain\Identity\Application;

use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Models\EmailMessage;
use App\Support\Email\EmailSource;

/**
 * ADR 0055 section 9.5: the invitation side of an `account_invitation`
 * email. A message is still wanted only while its invitation is pending
 * and unexpired -- a revoked or reissued invitation's unsent email is never
 * submitted. The invitation keeps no copy of the email state; the admin UI
 * reads it through OutboundEmailGateway::forSource().
 */
final class GuardianInvitationEmailSource implements EmailSource
{
    public const SOURCE_TYPE = 'guardian_account_invitation';

    public function sourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public function isStillWanted(EmailMessage $message): bool
    {
        $invitation = GuardianAccountInvitation::query()->find($message->source_id);

        return $invitation !== null && $invitation->status === 'pending' && ! $invitation->isExpired();
    }

    public function project(EmailMessage $message): void
    {
        // Nothing to mirror: the invitation's lifecycle is its own.
    }
}
