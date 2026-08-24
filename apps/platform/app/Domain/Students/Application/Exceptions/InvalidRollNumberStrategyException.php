<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * `EnrollmentRolloverItem.roll_number_strategy` accepts only
 * `explicit`/`preserve_source` -- the accepted architecture's
 * deliberately narrow first-release Roll Number strategy set
 * (docs/modules/STUDENT-ENROLLMENT.md, "Roll Number strategy") --
 * there is no `auto`/`generate`/`next_number`/`alphabetical` value,
 * and never will be without a dedicated future product decision.
 */
class InvalidRollNumberStrategyException extends StudentException
{
    public function __construct(string $strategy)
    {
        parent::__construct(
            422,
            'INVALID_ROLL_NUMBER_STRATEGY',
            "\"{$strategy}\" is not a valid Roll Number strategy. Supported: explicit, preserve_source.",
        );
    }
}
