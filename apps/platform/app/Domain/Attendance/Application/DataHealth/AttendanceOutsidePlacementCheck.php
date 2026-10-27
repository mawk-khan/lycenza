<?php

namespace App\Domain\Attendance\Application\DataHealth;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * SIS 8.1 (SB-02, V11 benchmark delta): register marks dated outside the
 * dates of the placement they were recorded against -- almost always a
 * placement withdrawn or completed with a back-dated end date after its
 * registers were taken. Submission always matched the roster then; a later
 * end date makes the old marks fall outside it, and corrections deliberately
 * never re-check placement (AttendanceCorrectionService).
 *
 * Attendance stays authoritative: marks are history and are never removed,
 * and a recorded end date cannot be changed. One finding per placement (its
 * mark count and date span); the finding total is the number of placements.
 * The placement interval comes from Students
 * (StudentEnrollmentReadService::constrainToDateOutsidePlacement()), never a
 * read of placements here.
 */
class AttendanceOutsidePlacementCheck implements DataHealthCheck
{
    public function __construct(private readonly StudentEnrollmentReadService $placements) {}

    public function definition(): D
    {
        return new D(
            code: 'attendance.recorded_outside_placement',
            area: 'Attendance',
            title: 'Attendance recorded outside a Student\'s class dates',
            condition: 'A Student has register marks on dates when their class placement had not started or had already ended. This usually happens when a placement is withdrawn or completed with an earlier end date after registers were taken.',
            whyItMatters: 'The Student appears present or absent in a class they were no longer in, so their attendance history and the class\'s registers disagree with their placement history.',
            severity: D::REVIEW,
            kind: D::INCONSISTENT,
            viewCapabilities: ['attendance.view', 'enrollments.view'],
            repair: 'Check the placement\'s end date in the Student\'s Enrollment history. If the Student really left on that date, the marks stay as history: correct a mark in its register only if its status is wrong. A recorded end date cannot be changed, and register marks are never deleted.',
        );
    }

    public function hasFindings(School $school): bool
    {
        return $this->outside($school)->exists();
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = $this->outside($school)
            ->selectRaw('attendance_records.student_enrollment_id, min(attendance_sessions.attendance_date) AS first_date, max(attendance_sessions.attendance_date) AS last_date, count(*) AS marks')
            ->groupBy('attendance_records.student_enrollment_id')
            ->orderByRaw('min(attendance_sessions.attendance_date)')->orderBy('attendance_records.student_enrollment_id')
            ->paginate($perPage, ['*'], 'page', $page);
        $placements = $this->placements->placementsForFindings($school, collect($rows->items())->pluck('student_enrollment_id')->map(fn ($id) => (string) $id)->all());
        $today = SchoolTimezone::today($school);

        return $rows->through(function ($row) use ($placements, $today) {
            $placement = $placements->get($row->student_enrollment_id);
            $student = $placement?->student;
            $name = $student === null ? 'Unknown Student' : trim(implode(' ', array_filter([$student->first_name, $student->middle_name, $student->last_name])))." ({$student->student_number})";
            $first = CarbonImmutable::parse($row->getAttribute('first_date'))->toDateString();
            $last = CarbonImmutable::parse($row->getAttribute('last_date'))->toDateString();
            $marks = (int) $row->getAttribute('marks');
            $to = min($last, $today);
            $range = $first <= $to && CarbonImmutable::parse($first)->diffInDays(CarbonImmutable::parse($to)) < 366 ? '?'.http_build_query(['from' => $first, 'to' => $to]) : '';

            return new DataHealthFinding(
                entityType: 'student_enrollment',
                entityId: (string) $row->student_enrollment_id,
                label: $name,
                detail: trim(implode(' · ', array_filter([
                    $placement?->academicYear?->name,
                    trim(implode(' · ', array_filter([$placement?->gradeLevel?->name, $placement?->section?->name]))),
                    $placement === null ? null : 'placement '.$placement->starts_on->toDateString().' to '.($placement->ends_on?->toDateString() ?? 'open').' ('.$placement->status.')',
                    "{$marks} register ".($marks === 1 ? 'mark' : 'marks').' outside it, '.($first === $last ? "on {$first}" : "{$first} to {$last}"),
                ]))),
                links: $placement === null ? [] : [
                    new DataHealthLink('Enrollment history', "/app/students/{$placement->student_id}#enrollment-history", ['students.view']),
                    new DataHealthLink('Attendance records', "/app/attendance/students/{$placement->student_id}{$range}", ['attendance.view'], isRepair: true),
                ],
            );
        });
    }

    /** @return Builder<AttendanceRecord> */
    private function outside(School $school)
    {
        return $this->placements->constrainToDateOutsidePlacement(
            AttendanceRecord::query()
                ->join('attendance_sessions', fn ($j) => $j->on('attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
                    ->where('attendance_sessions.school_id', $school->id))
                ->where('attendance_records.school_id', $school->id),
            $school,
            'attendance_records.student_enrollment_id',
            'attendance_sessions.attendance_date',
        );
    }
}
