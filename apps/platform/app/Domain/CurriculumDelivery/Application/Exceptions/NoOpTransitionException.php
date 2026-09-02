<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * A transition whose `new_status` equals the current status changes
 * nothing, so it is rejected rather than writing a misleading audit
 * record claiming a change that never happened. Mirrors
 * App\Domain\Attendance\Application\Exceptions\AttendanceCorrectionNoOpException.
 */
class NoOpTransitionException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_TRANSITION_NO_OP', "This delivery is already '{$status}'; a transition must change the status.");
    }
}
