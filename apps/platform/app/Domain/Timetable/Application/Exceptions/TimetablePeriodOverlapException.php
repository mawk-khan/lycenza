<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * Thrown when a candidate [start_time, end_time) interval overlaps an
 * ALREADY-ACTIVE Period for the same School (half-open interval
 * comparison, current Period excluded when updating/reactivating
 * itself). No two active Periods for one School may overlap --
 * enforced by App\Domain\Timetable\Application\TimetablePeriodService
 * under an App\Support\Concurrency\TenantLock, never by a database
 * constraint (PostgreSQL's plain btree unique index cannot express a
 * range overlap without an exclusion constraint this checkpoint does
 * not introduce).
 */
class TimetablePeriodOverlapException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(409, 'TIMETABLE_PERIOD_OVERLAP', 'This time range overlaps an existing active Period for this School.');
    }
}
