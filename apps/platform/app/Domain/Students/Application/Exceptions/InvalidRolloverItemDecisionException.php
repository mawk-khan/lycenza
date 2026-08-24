<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * `EnrollmentRolloverItem.decision` accepts only
 * `undecided`/`promote`/`repeat`/`exclude`/`manual_review` (a plain
 * string column, matching every other lifecycle status in this
 * codebase -- no DB CHECK enumerating values). This is the
 * application-layer guard `EnrollmentRolloverPlanService::setItemDecision()`
 * enforces before ever writing one.
 */
class InvalidRolloverItemDecisionException extends StudentException
{
    public function __construct(string $decision)
    {
        parent::__construct(
            422,
            'INVALID_ROLLOVER_ITEM_DECISION',
            "\"{$decision}\" is not a valid rollover item decision. Supported: undecided, promote, repeat, exclude, manual_review.",
        );
    }
}
