<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * The requested state pair is not a legal transition. Both values
 * belonging to the status vocabulary is NOT sufficient -- the machine
 * is explicitly closed to exactly `in_progress -> completed` and
 * `completed -> in_progress`, so it can never grow an unreviewed edge
 * just because a new status value is added to the enum later.
 */
class IllegalTransitionException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_ILLEGAL_TRANSITION', "'{$from}' -> '{$to}' is not a legal curriculum delivery transition.");
    }
}
