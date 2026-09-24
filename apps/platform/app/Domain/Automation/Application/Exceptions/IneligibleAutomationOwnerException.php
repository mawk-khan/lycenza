<?php

namespace App\Domain\Automation\Application\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * The would-be owner does not currently hold every capability the rule
 * type requires (ADR 0043 §5), so the rule is not enabled for them.
 */
class IneligibleAutomationOwnerException extends ValidationException
{
    public static function because(string $reason): self
    {
        return self::withMessages([
            'owner' => "You cannot own this rule: {$reason}.",
        ]);
    }
}
