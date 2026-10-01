<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * E21.2D (E21-D7): the core Student purge racing a re-entry, in two real OS
 * processes with an observed lock wait. The purge locks the Student row
 * FOR UPDATE and resolves the exit AFTER the lock. An Enrollment insert
 * takes FOR KEY SHARE on its Student, and a reactivation updates the row,
 * so they serialize:
 * - a re-entry committed first keeps the Student;
 * - a purge committed first makes the late re-enrollment fail safely on
 *   its foreign key.
 * Never a half-deleted record.
 */
class StudentRetentionConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        parent::tearDown();
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-retention-op.php', ...$args];
    }

    /** @return array{0: School, 1: Student, 2: string} school, a Student who left in 2026, a next-year Section id */
    private function leaver(): array
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'active', 'code' => 'AY26']);
        $next = $this->createAcademicYear($school, ['starts_on' => '2027-04-01', 'ends_on' => '2028-03-31', 'status' => 'draft', 'code' => 'AY27']);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $this->createStudentEnrollment($student, $this->createSection($year, $campus, $grade, ['code' => 'A']), ['status' => 'withdrawn', 'starts_on' => '2026-06-01', 'ends_on' => '2026-09-30']);

        return [$school, $student, $this->createSection($next, $campus, $grade, ['code' => 'B'])->id];
    }

    private function exists(string $studentId): bool
    {
        return DB::connection('pgsql_admin')->table('students')->where('id', $studentId)->exists();
    }

    #[Test]
    public function a_re_enrollment_committed_first_keeps_the_student(): void
    {
        [$school, $student, $sectionId] = $this->leaver();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('re-enroll', $school->id, $student->id, $sectionId),
            $this->script('core-prune', $school->id, '2060-01-01'),
        );

        $this->assertSame('re-enrolled', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertTrue($this->exists($student->id));
        $this->assertSame(2, DB::connection('pgsql_admin')->table('student_enrollments')->where('student_id', $student->id)->count());
    }

    #[Test]
    public function a_purge_committed_first_refuses_the_late_re_enrollment(): void
    {
        [$school, $student, $sectionId] = $this->leaver();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('core-prune', $school->id, '2060-01-01'),
            $this->script('re-enroll', $school->id, $student->id, $sectionId),
        );

        $this->assertSame('deleted:1', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertFalse($this->exists($student->id));
        $this->assertSame(0, DB::connection('pgsql_admin')->table('student_enrollments')->where('student_id', $student->id)->count());
    }

    #[Test]
    public function a_reactivation_committed_first_keeps_the_student(): void
    {
        [$school, $student] = $this->leaver();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('reactivate', $school->id, $student->id),
            $this->script('core-prune', $school->id, '2060-01-01'),
        );

        $this->assertSame('reactivated', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertTrue($this->exists($student->id));
    }
}
