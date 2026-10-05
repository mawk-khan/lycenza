<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Retention\Erasure\ErasureCaseService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * E21.2F (E21-D10): erasure execution racing a re-entry, in two real OS
 * processes with an observed lock wait. Execution reuses the domains'
 * locked purges (the Student or Employee row FOR UPDATE, the exit or
 * separation resolved after the lock), so:
 * - a re-enrollment or rehire committed first keeps the subject;
 * - an erasure committed first makes the late re-enrollment fail safely on
 *   its foreign key, never recreating dangling history.
 */
class ErasureConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        DB::connection('pgsql_admin')->table('erasure_cases')->whereIn('school_id', $this->schoolIds)->delete();
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        parent::tearDown();
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/erasure-op.php', ...$args];
    }

    private function approvedCase(School $school, string $type, string $id): string
    {
        config(['retention.student_operational_years' => 7, 'retention.student_core_years' => 25, 'retention.employee_ancillary_years' => 2, 'retention.employee_evidence_years' => 8]);
        $cases = app(ErasureCaseService::class);

        return $cases->decide($cases->open($school, $type, $id, 'written')->id, 'approve', 'request_valid')->id;
    }

    /** @return array{0: School, 1: string, 2: string} a School, a Student who left in 2000, a Section id */
    private function oldLeaver(): array
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $year = $this->createAcademicYear($school, ['starts_on' => '1999-04-01', 'ends_on' => '2000-03-31', 'status' => 'closed', 'code' => 'AY99']);
        $next = $this->createAcademicYear($school, ['starts_on' => '2027-04-01', 'ends_on' => '2028-03-31', 'status' => 'draft', 'code' => 'AY27']);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $this->createStudentEnrollment($student, $this->createSection($year, $campus, $grade, ['code' => 'A']), ['status' => 'withdrawn', 'starts_on' => '1999-06-01', 'ends_on' => '2000-01-31']);

        return [$school, $student->id, $this->createSection($next, $campus, $grade, ['code' => 'B'])->id];
    }

    private function exists(string $table, string $id): bool
    {
        return DB::connection('pgsql_admin')->table($table)->where('id', $id)->exists();
    }

    #[Test]
    public function a_re_enrollment_committed_first_keeps_the_student(): void
    {
        [$school, $studentId, $sectionId] = $this->oldLeaver();
        $case = $this->approvedCase($school, 'student', $studentId);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('re-enroll', $school->id, $studentId, $sectionId),
            $this->script('execute', $case),
        );

        $this->assertSame('re-enrolled', $holder);
        $this->assertStringContainsString('student_core=retained_until', $contender);
        $this->assertTrue($this->exists('students', $studentId));
    }

    #[Test]
    public function an_erasure_committed_first_refuses_the_late_re_enrollment(): void
    {
        [$school, $studentId, $sectionId] = $this->oldLeaver();
        $case = $this->approvedCase($school, 'student', $studentId);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('execute', $case),
            $this->script('re-enroll', $school->id, $studentId, $sectionId),
        );

        // E21-RH.6: the purge units run (and are held) on the retention connection, while the case's own outcome
        // is planned on the runtime connection, which cannot see their uncommitted deletes; the database state
        // below is the proof (in production each unit commits before that plan).
        $this->assertStringStartsWith('outcome:', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertFalse($this->exists('students', $studentId));
        $this->assertSame(0, DB::connection('pgsql_admin')->table('student_enrollments')->where('student_id', $studentId)->count());
    }

    #[Test]
    public function a_rehire_committed_first_keeps_the_employee(): void
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $employee = $this->createEmployee($school);
        $this->createEmploymentRecord($employee, ['status' => 'separated', 'starts_on' => '2010-01-01', 'ends_on' => '2015-01-31']);
        $case = $this->approvedCase($school, 'employee', $employee->id);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('rehire', $school->id, $employee->id),
            $this->script('execute', $case),
        );

        $this->assertSame('rehired', $holder);
        $this->assertStringContainsString('employee_evidence=retained_until', $contender);
        $this->assertTrue($this->exists('employees', $employee->id));
    }
}
