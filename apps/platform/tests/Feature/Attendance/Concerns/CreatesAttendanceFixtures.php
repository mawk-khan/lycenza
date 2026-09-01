<?php

namespace Tests\Feature\Attendance\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;

/**
 * Phase 0H.2 fixture helpers. Builds the full Timetable + Academic
 * Structure + Students graph one Attendance test needs, in one call,
 * because a register legitimately depends on all of it.
 *
 * `Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures` and
 * `Tests\Concerns\CreatesTenancyFixtures` are USED read-only here,
 * never modified -- the same discipline CreatesTimetableFixtures itself
 * followed toward CreatesTenancyFixtures.
 */
trait CreatesAttendanceFixtures
{
    use CreatesTimetableFixtures;

    /** ISO Monday -- every fixture date below is a Monday. */
    protected const MONDAY = '2026-08-31';

    /**
     * @return array{
     *     school: School, campus: Campus, year: AcademicYear, grade: GradeLevel,
     *     section: Section, offering: SubjectOffering, teacher: Employee,
     *     period: TimetablePeriod, entry: TimetableEntry, actor: User
     * }
     */
    protected function attendanceWorld(array $periodTimes = ['start_time' => '09:00:00', 'end_time' => '10:00:00']): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, [
            'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31',
        ]);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, [
            'is_required' => true, 'status' => 'active',
        ]);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'A']);
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($school, $periodTimes);
        $entry = $this->createTimetableEntry($offering, $section, $teacher, $period, ['day_of_week' => 1]);

        return [
            'school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade,
            'section' => $section, 'offering' => $offering, 'teacher' => $teacher,
            'period' => $period, 'entry' => $entry,
            'actor' => $this->fullAttendanceActor($school),
        ];
    }

    /**
     * Enrolls a Student into $section through the REAL
     * StudentEnrollmentService -- never a direct factory write -- so
     * every Attendance test exercises the same sanctioned membership
     * path (including its new Section-before-Enrollment locking) that
     * production uses.
     */
    protected function enrollStudent(Section $section, string $rollNumber, string $startsOn, ?User $actor = null): StudentEnrollment
    {
        $school = $section->school;
        $student = $this->createStudent($school);

        return app(StudentEnrollmentService::class)->enroll($student, $section, $rollNumber, $startsOn, $actor);
    }

    protected function createStudentFor(School $school, array $attributes = []): Student
    {
        return $this->createStudent($school, $attributes);
    }

    protected function fullAttendanceActor(School $school): User
    {
        return $this->createUserWithCapabilities($school, [
            'attendance.view', 'attendance.manage',
            'timetable.schedule.view', 'timetable.schedule.manage',
            'timetable.periods.view', 'timetable.periods.manage',
        ]);
    }

    /**
     * @param  array<string, string>  $statusByEnrollmentId
     * @return list<array{student_enrollment_id: string, status: string}>
     */
    protected function registerPayload(array $statusByEnrollmentId): array
    {
        $records = [];
        foreach ($statusByEnrollmentId as $enrollmentId => $status) {
            $records[] = ['student_enrollment_id' => $enrollmentId, 'status' => $status];
        }

        return $records;
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }
}
