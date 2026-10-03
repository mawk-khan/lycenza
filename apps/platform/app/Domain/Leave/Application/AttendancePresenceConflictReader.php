<?php

namespace App\Domain\Leave\Application;

use App\Models\School;

/**
 * HRX.3 (ADR 0065 §8, §24.5): the PORT Leave's approval uses to refuse leave
 * over recorded staff presence -- owned by Leave, implemented by Staff
 * Attendance, bound in the composition root (AppServiceProvider). Leave
 * therefore names no Staff Attendance class (one-way dependency).
 *
 * The caller holds LeaveLocks::staffDays() for these dates, so the answer
 * cannot change before its transaction ends.
 */
interface AttendancePresenceConflictReader
{
    /**
     * The halves recorded `present` for this employment on these dates. Only
     * presence blocks an approval; recorded absence never does.
     *
     * @param  list<string>  $dates  School-local Y-m-d
     * @return array<string, list<int>> date => halves (1 = first, 2 = second); dates without presence are omitted
     */
    public function presentHalves(School $school, string $employmentRecordId, array $dates): array;
}
