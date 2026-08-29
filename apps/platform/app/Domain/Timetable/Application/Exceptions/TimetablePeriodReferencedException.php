<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * A narrow, deliberate exception to this codebase's usual "a reference
 * entity can always be deactivated, existing rows just keep pointing at
 * a now-inactive parent" convention (CLAUDE.md rule 73's normal
 * pattern). A TimetablePeriod is different: an ACTIVE TimetableEntry
 * referencing it is an ONGOING weekly commitment that depends on the
 * Period's own definition (its time range) remaining valid RIGHT NOW,
 * unlike a purely historical/completed record another module's
 * reference entities represent. So:
 *
 *  - Deactivating a Period is rejected while any ACTIVE TimetableEntry
 *    still references it.
 *  - Changing a Period's start_time/end_time is rejected under the
 *    same condition (non-temporal field changes -- name/code/
 *    sort_order -- are NOT affected and remain always allowed).
 *
 * A caller must first deactivate/reassign every referencing
 * TimetableEntry before either operation is permitted.
 */
class TimetablePeriodReferencedException extends TimetableException
{
    public function __construct(string $action)
    {
        parent::__construct(409, 'TIMETABLE_PERIOD_REFERENCED', "Cannot {$action} this Period while an active TimetableEntry still references it.");
    }
}
