<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS 5.2 (owner decision): rollover prepares the next Academic Year while it
 * is still DRAFT -- a plan is created, validated and executed only into a
 * draft target. An active or closed target is refused at every stage, before
 * any Student is touched; the year is activated separately, never by rollover.
 */
class RolloverTargetYearNotDraftException extends StudentException
{
    public function __construct(string $yearName, string $status)
    {
        parent::__construct(
            422,
            'ROLLOVER_TARGET_YEAR_NOT_DRAFT',
            "Rollover prepares a draft Academic Year. Target Academic Year {$yearName} is {$status}, so it cannot be rolled into.",
        );
    }
}
