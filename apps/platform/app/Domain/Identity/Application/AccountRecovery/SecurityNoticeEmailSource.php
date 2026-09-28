<?php

namespace App\Domain\Identity\Application\AccountRecovery;

use App\Models\EmailMessage;
use App\Support\Email\EmailSource;

/** Phase 0O.10A: a security notice is always wanted until it expires (ADR 0055 expiry applies). */
final class SecurityNoticeEmailSource implements EmailSource
{
    public const SOURCE_TYPE = 'user_security_notice';

    public function sourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public function isStillWanted(EmailMessage $message): bool
    {
        return true;
    }

    public function project(EmailMessage $message): void {}
}
