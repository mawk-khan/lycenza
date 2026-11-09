<?php

namespace App\Domain\Leave\Application\Calendar;

use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/** HR.R1-C22 (WO-5): Leave's own part of the change notice: requests still awaiting a decision in the range. */
final class LeaveCalendarChangeImpact implements CalendarChangeImpactSource
{
    public function __construct(private readonly TenantContext $context) {}

    public function impact(School $school, string $from, string $to): array
    {
        $count = $this->context->withSchool($school, fn () => LeaveRequest::query()->where('school_id', $school->id)->where('status', 'submitted')
            ->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->count());

        return ['label' => 'leave requests awaiting a decision', 'count' => $count];
    }
}
