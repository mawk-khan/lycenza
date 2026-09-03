<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * Compare-and-swap failure: the delivery's current status is not the
 * `expected_status` the caller supplied, so another transition landed
 * first. Refusing here is what stops a stale administrator silently
 * overwriting a colleague's completion or reopening -- two admins who
 * both read `in_progress` and both submit `completed` will see exactly
 * one succeed. Mirrors
 * App\Domain\Attendance\Application\Exceptions\AttendanceRecordStatusChangedException.
 */
class DeliveryStatusChangedException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $expected, public readonly string $actual)
    {
        parent::__construct(409, 'CURRICULUM_DELIVERY_STATUS_CHANGED', "This delivery is now '{$actual}', not the expected '{$expected}'; reload before transitioning.");
    }
}
