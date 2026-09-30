<?php

namespace Tests\Concerns;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use Illuminate\Support\Facades\DB;

/**
 * TCH.4 fixtures on top of the Phase 0H.2 Attendance world (active year
 * from 2026-06-01, Section "A", required Offering "X", a timetable teacher
 * and a Monday entry A/X): a second Section "B" (entry B/X), a second
 * Offering "Y" (entry A/Y), Students enrolled in A and B from 2026-06-01,
 * and a TeachingAssignment administrator. Every fixture date is a past
 * Monday, so the "not in the future" register rule never depends on the
 * wall clock.
 *
 * Requires CreatesAttendanceFixtures and CreatesTeacherDeliveryFixtures
 * (whose teacher()/own() it reuses).
 */
trait CreatesTeacherAttendanceFixtures
{
    /** @return array<string, mixed> */
    protected function teacherAttendanceWorld(): array
    {
        $w = $this->attendanceWorld();
        $w['sectionB'] = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);
        $w['offeringY'] = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => true, 'status' => 'active']);
        $periodB = $this->createTimetablePeriod($w['school'], ['start_time' => '10:00:00', 'end_time' => '11:00:00']);
        $periodY = $this->createTimetablePeriod($w['school'], ['start_time' => '11:00:00', 'end_time' => '12:00:00']);
        $w['entryB'] = $this->createTimetableEntry($w['offering'], $w['sectionB'], $w['teacher'], $periodB, ['day_of_week' => 1]);
        $w['entryY'] = $this->createTimetableEntry($w['offeringY'], $w['section'], $w['teacher'], $periodY, ['day_of_week' => 1]);
        $w['studentsA'] = [$this->enrollStudent($w['section'], '1', '2026-06-01'), $this->enrollStudent($w['section'], '2', '2026-06-01')];
        $w['studentsB'] = [$this->enrollStudent($w['sectionB'], '1', '2026-06-01')];
        $w['admin'] = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view', 'teaching.assignments.manage']);

        return $w;
    }

    /** @return list<array{student_enrollment_id: string, status: string}> */
    protected function allPresent(array $enrollments): array
    {
        return array_map(fn ($e) => ['student_enrollment_id' => $e->id, 'status' => 'present'], $enrollments);
    }

    /** A register taken through the Tier 1 path (arrangement only). */
    protected function adminRegister(array $w, TimetableEntry $entry, string $date): AttendanceSession
    {
        $students = $entry->section_id === $w['section']->id ? $w['studentsA'] : $w['studentsB'];
        $service = app(AttendanceSubmissionService::class);

        return $this->inSchool($w['school'], fn () => $service->guarded(fn () => DB::transaction(
            fn () => $service->submit($w['school'], $entry->id, $date, $this->allPresent($students), $w['actor']),
        )));
    }
}
