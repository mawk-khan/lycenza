<?php

namespace App\Domain\Timetable\Application;

use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligationSource;
use App\Domain\HR\Application\Separation\SeparationSubject;
use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * HR.12 (X-1): the weekly timetable entries a separating teacher still
 * holds. Timetable reassigns them through its own pages; a separation never
 * edits a timetable, and past registers keep their teacher.
 */
final class TimetableSeparationObligations implements SeparationObligationSource
{
    public function owner(): string
    {
        return 'Timetable';
    }

    public function obligations(School $school, SeparationSubject $subject): array
    {
        $entries = DB::table('timetable_entries as t')
            ->join('academic_years as y', 'y.id', '=', 't.academic_year_id')
            ->where('t.school_id', $school->id)->where('t.teacher_id', $subject->employeeId)
            ->where('t.status', 'active')->whereIn('y.status', ['active', 'draft'])
            ->count();

        return [
            new SeparationObligation('timetable_entries', 'Timetable', 'Weekly timetable periods taught',
                $entries === 0 ? SeparationObligation::CLEAR : ($subject->finalized ? SeparationObligation::OPEN : SeparationObligation::ATTENTION), $entries,
                $entries > 0 ? 'Reassign these periods to another teacher in the Timetable.' : null),
        ];
    }
}
