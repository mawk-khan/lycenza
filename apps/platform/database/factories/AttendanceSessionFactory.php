<?php

namespace Database\Factories;

use App\Domain\Attendance\Infrastructure\AttendanceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 *
 * Deliberately defaults NONE of the parent/context ids -- an
 * AttendanceSession pins a Section, a SubjectOffering, a teacher, a
 * Period and a TimetableEntry that must all belong to the SAME School
 * (and, for Section/SubjectOffering, the same AcademicYear/Campus/
 * GradeLevel), which a single factory relationship default cannot
 * guarantee cleanly under RLS. Same precedent as TimetableEntryFactory/
 * SectionFactory/SubjectOfferingFactory.
 *
 * A test factory is NOT a runtime writer: production code must always
 * go through
 * App\Domain\Attendance\Application\AttendanceSubmissionService, which
 * additionally enforces the Section-lock-serialized wall-clock overlap
 * invariant no database constraint expresses. This factory exists for
 * read/presentation/RLS tests that need a Session to already exist.
 */
class AttendanceSessionFactory extends Factory
{
    protected $model = AttendanceSession::class;

    public function definition(): array
    {
        return [
            'attendance_date' => '2026-09-15',
            'period_start_time' => '09:00:00',
            'period_end_time' => '10:00:00',
            'submitted_at' => now(),
        ];
    }
}
