<?php

namespace App\Http\Controllers\App\Attendance;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;

/**
 * The Attendance pages' register presentation (Phase 0H.2), shared unchanged
 * by the Tier 1 AttendanceController and the TCH.4 owned
 * MyAttendanceController so both render one shape.
 */
trait PresentsAttendancePages
{
    /**
     * @return array<string, mixed>
     */
    private function presentSummary(AttendanceSession $session): array
    {
        return [
            'id' => $session->id,
            'attendanceDate' => $session->attendance_date->toDateString(),
            'sectionCode' => $session->section?->code,
            'subjectName' => $session->subjectOffering?->subject?->name,
            'teacherName' => $session->teacher?->full_name,
            'periodName' => $session->period?->name,
            // Immutable historical values, never the current Period row.
            'periodStartTime' => $session->period_start_time,
            'periodEndTime' => $session->period_end_time,
            'recordCount' => $session->records()->count(),
        ];
    }

    /**
     * Identical sourcing rules to the API presenter: class identity from
     * the Session's own immutable snapshot; names/codes resolved through
     * those snapshotted identities to the referenced entity's CURRENT
     * row; weekday derived from `attendance_date`; `timetableEntryId`
     * echoed as provenance and never dereferenced.
     *
     * @return array<string, mixed>
     */
    private function presentDetail(AttendanceSession $session): array
    {
        return [
            'id' => $session->id,
            'attendanceDate' => $session->attendance_date->toDateString(),
            'dayOfWeek' => $session->dayOfWeek(),
            'academicYearName' => $session->academicYear?->name,
            'campusName' => $session->campus?->name,
            'gradeLevelName' => $session->gradeLevel?->name,
            'sectionCode' => $session->section?->code,
            'sectionName' => $session->section?->name,
            'subjectName' => $session->subjectOffering?->subject?->name,
            'subjectCode' => $session->subjectOffering?->subject?->code,
            'teacherName' => $session->teacher?->full_name,
            'periodName' => $session->period?->name,
            'periodCode' => $session->period?->code,
            'periodStartTime' => $session->period_start_time,
            'periodEndTime' => $session->period_end_time,
            'timetableEntryId' => $session->timetable_entry_id,
            'submittedAt' => $session->submitted_at->toIso8601String(),
            'records' => $session->records->map(function (AttendanceRecord $record): array {
                $enrollment = $record->studentEnrollment;
                $student = $enrollment?->student;

                return [
                    'id' => $record->id,
                    'rollNumber' => $enrollment?->roll_number,
                    'fullName' => $student === null ? null : trim(implode(' ', array_filter(
                        [$student->first_name, $student->middle_name, $student->last_name],
                        fn (?string $part) => $part !== null && $part !== '',
                    ))),
                    'status' => $record->status,
                    'correctedAt' => $record->corrected_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }
}
