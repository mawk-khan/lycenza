<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;

/**
 * The Attendance API's register and record presentation (Phase 0H.2),
 * shared unchanged by the Tier 1 AttendanceSessionController and the TCH.4
 * owned TeacherAttendanceController so both tiers return one shape.
 */
trait PresentsAttendanceSessions
{
    /**
     * @return list<string>
     */
    private function presentationRelations(bool $withRecords = false): array
    {
        $relations = [
            'academicYear', 'campus', 'gradeLevel', 'section',
            'subjectOffering.subject', 'teacher', 'period',
        ];

        if ($withRecords) {
            $relations[] = 'records.studentEnrollment.student:id,school_id,first_name,middle_name,last_name';
        }

        return $relations;
    }

    /**
     * EVERY class-identity field below comes from the Session's own
     * immutable snapshot columns or is derived from `attendance_date`.
     * Nothing is read from `timetable_entries`. Names/codes resolve
     * through the snapshotted IDENTITY to the referenced entity's
     * CURRENT row, so a later rename correctly propagates under an
     * unchanged historical identity -- while `periodStartTime`/
     * `periodEndTime` stay frozen, because a Period's times CAN be
     * legitimately changed after the fact and would otherwise re-time
     * history (docs/modules/ATTENDANCE.md).
     *
     * @return array<string, mixed>
     */
    private function present(AttendanceSession $session, bool $withRecords): array
    {
        $payload = [
            'id' => $session->id,
            'attendanceDate' => $session->attendance_date->toDateString(),
            // Derived, never `timetable_entries.day_of_week`.
            'dayOfWeek' => $session->dayOfWeek(),

            'academicYearId' => $session->academic_year_id,
            'academicYearName' => $session->academicYear?->name,
            'academicYearCode' => $session->academicYear?->code,
            'campusId' => $session->campus_id,
            'campusName' => $session->campus?->name,
            'campusCode' => $session->campus?->code,
            'gradeLevelId' => $session->grade_level_id,
            'gradeLevelName' => $session->gradeLevel?->name,
            'gradeLevelCode' => $session->gradeLevel?->code,
            'sectionId' => $session->section_id,
            'sectionCode' => $session->section?->code,
            'sectionName' => $session->section?->name,

            'subjectOfferingId' => $session->subject_offering_id,
            'subjectId' => $session->subjectOffering?->subject_id,
            'subjectCode' => $session->subjectOffering?->subject?->code,
            'subjectName' => $session->subjectOffering?->subject?->name,

            // Scheduled teacher at submission -- NOT the submitter, and
            // NOT an actual substitute (v1 has no substitution model).
            'teacherId' => $session->teacher_id,
            'teacherName' => $session->teacher?->full_name,

            'periodId' => $session->period_id,
            // CURRENT human-facing labels, resolved through period_id.
            'periodCode' => $session->period?->code,
            'periodName' => $session->period?->name,
            // IMMUTABLE historical values from this row -- never the
            // current TimetablePeriod's start_time/end_time.
            'periodStartTime' => $session->period_start_time,
            'periodEndTime' => $session->period_end_time,

            // Provenance only. Never dereferenced above.
            'timetableEntryId' => $session->timetable_entry_id,

            'submittedByUserId' => $session->submitted_by_user_id,
            'submittedAt' => $session->submitted_at->toIso8601String(),
        ];

        if ($withRecords) {
            $payload['records'] = $session->records
                ->map(fn (AttendanceRecord $r) => $this->presentRecord($r))
                ->values()->all();
        }

        return $payload;
    }

    /**
     * Student identity is derived THROUGH the StudentEnrollment (there
     * is no `student_id` column on `attendance_records`). The record's
     * structural context columns are deliberately NOT serialized -- they
     * exist only to carry the composite FKs.
     *
     * @return array<string, mixed>
     */
    private function presentRecord(AttendanceRecord $record): array
    {
        $enrollment = $record->studentEnrollment;
        $student = $enrollment?->student;

        return [
            'id' => $record->id,
            'studentEnrollmentId' => $record->student_enrollment_id,
            'studentId' => $enrollment?->student_id,
            'rollNumber' => $enrollment?->roll_number,
            'fullName' => $student === null ? null : trim(implode(' ', array_filter(
                [$student->first_name, $student->middle_name, $student->last_name],
                fn (?string $part) => $part !== null && $part !== '',
            ))),
            'status' => $record->status,
            'correctedAt' => $record->corrected_at?->toIso8601String(),
        ];
    }
}
