<?php

namespace App\Domain\Identity\Application\Portal;

use RuntimeException;

/**
 * POR.1 (ADR 0070 §9.2): a refused Guardian off-boarding. `outcome` is a
 * closed code (safe for audit and logs); the message never names another
 * School or account state the caller may not learn.
 */
final class GuardianOffboardingException extends RuntimeException
{
    public const MESSAGES = [
        'not_authorized' => 'You cannot off-board Guardian accounts in this School.',
        'school_not_operational' => 'This School is not active, so its Guardian accounts cannot be changed.',
        'no_active_link' => 'That Guardian has no active account in this School.',
        'self_administration' => 'You cannot change your own access here. Ask another School administrator.',
    ];

    public function __construct(public readonly string $outcome)
    {
        parent::__construct(self::MESSAGES[$outcome] ?? 'This action is not possible.');
    }
}
