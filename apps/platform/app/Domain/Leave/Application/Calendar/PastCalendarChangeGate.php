<?php

namespace App\Domain\Leave\Application\Calendar;

use App\Models\School;

/**
 * HR.R1-C22 (WO-5, D6-2): a past-dated calendar change may reach only months whose Staff Attendance is
 * still open. Leave declares this port and Staff Attendance implements it, so Leave never names a
 * Staff Attendance class (rule 4: Staff Attendance already depends on Leave).
 *
 * Called inside the change's transaction, after the School guard and BEFORE `LeaveLocks::calendar`:
 * it takes each month key of [from, to] shared, in month order, and refuses if any month is locked.
 */
interface PastCalendarChangeGate
{
    public function holdOpenMonths(School $school, string $from, string $to): void;
}
