<?php

namespace App\Domain\Identity\Application\Staff;

use App\Domain\Identity\Infrastructure\StaffAccountInvitation;
use App\Models\EmailMessage;
use App\Support\Email\EmailSource;

/**
 * Phase 0O.12B (ADR 0059 section 9.2; ADR 0055 section 9.5): the invitation
 * side of a `staff_account_invitation` email. A message is still wanted only
 * while its invitation is pending and unexpired -- a revoked or reissued
 * invitation's unsent email is never submitted.
 */
final class StaffInvitationEmailSource implements EmailSource
{
    public const SOURCE_TYPE = 'staff_account_invitation';

    public function sourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public function isStillWanted(EmailMessage $message): bool
    {
        $invitation = StaffAccountInvitation::query()->find($message->source_id);

        return $invitation !== null && $invitation->isUsable();
    }

    public function project(EmailMessage $message): void
    {
        // Nothing to mirror: the invitation's lifecycle is its own.
    }
}
