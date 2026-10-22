<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentOperationalEligibility;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7D-4 (ADR 0076 §40): StudentOperationalEligibility::lockOperationalOn()
 * answers exactly the ORG.7D-2 operational-Campus rule, only inside a write
 * transaction, with four single-row share-locked reads. The concurrency
 * proofs are StudentEligibilityConcurrencyTest.
 */
class StudentOperationalEligibilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $year;

    private Section $sectionA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school);
        $this->b = $this->createCampus($this->school);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'E1', 'name' => 'E1', 'status' => 'active']);
        $this->sectionA = $this->createSection($this->year, $this->a, $this->createGradeLevel($this->school), ['status' => 'active']);
    }

    private function placed(array $attributes = []): Student
    {
        $student = $this->createStudent($this->school, ['status' => 'active', ...$attributes]);
        $this->createStudentEnrollment($student, $this->sectionA, ['status' => 'active']);

        return $student;
    }

    private function eligible(string $studentId, string $campusId, ?School $school = null): bool
    {
        return DB::transaction(fn () => app(StudentOperationalEligibility::class)->lockOperationalOn($school ?? $this->school, $studentId, $campusId));
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    #[Test]
    public function it_answers_true_only_on_the_students_current_operational_campus(): void
    {
        $student = $this->placed();

        $this->assertTrue($this->eligible($student->id, $this->a->id));
        $this->assertFalse($this->eligible($student->id, $this->b->id), 'another Campus');
    }

    #[Test]
    public function every_component_of_the_rule_is_required(): void
    {
        $inactive = $this->placed(['status' => 'inactive']);
        $this->assertFalse($this->eligible($inactive->id, $this->a->id), 'inactive Student');

        $unplaced = $this->createStudent($this->school, ['status' => 'active']);
        $this->assertFalse($this->eligible($unplaced->id, $this->a->id), 'no current placement');

        $left = $this->placed();
        $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $left->id)->get())->each(fn (StudentEnrollment $e) => $this->forceEnrollmentStatus($e, 'withdrawn', ['ends_on' => now()->toDateString()]));
        $this->assertFalse($this->eligible($left->id, $this->a->id), 'ended placement');

        $draft = $this->createAcademicYear($this->school, ['code' => 'E2', 'name' => 'E2', 'status' => 'draft']);
        $future = $this->createStudent($this->school, ['status' => 'active']);
        $this->createStudentEnrollment($future, $this->createSection($draft, $this->a, $this->createGradeLevel($this->school), ['status' => 'active']), ['status' => 'active']);
        $this->assertFalse($this->eligible($future->id, $this->a->id), 'a draft-year placement is not current');

        $student = $this->placed();
        $this->inSchool(fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->assertFalse($this->eligible($student->id, $this->a->id), 'inactive Campus');
        $this->inSchool(fn () => $this->a->forceFill(['status' => 'active'])->save());
        $this->assertTrue($this->eligible($student->id, $this->a->id), 'reactivation restores it');

        $this->inSchool(fn () => $this->year->forceFill(['status' => 'closed'])->save());
        $this->assertFalse($this->eligible($student->id, $this->a->id), 'no active AcademicYear');
    }

    #[Test]
    public function it_never_answers_for_another_school_or_an_unknown_id(): void
    {
        $student = $this->placed();
        $other = $this->createSchool();
        $otherCampus = $this->createCampus($other);

        $this->assertFalse($this->eligible($student->id, $this->a->id, $other), 'another School\'s Student');
        $this->assertFalse($this->eligible($student->id, $otherCampus->id), 'another School\'s Campus');
        $this->assertFalse($this->eligible((string) Str::uuid(), $this->a->id));
        $this->assertFalse($this->eligible('not-a-uuid', $this->a->id));
    }

    #[Test]
    public function it_costs_four_single_row_share_locked_reads(): void
    {
        $student = $this->placed();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($this->eligible($student->id, $this->a->id));

        $reads = array_values(array_filter(DB::getQueryLog(), fn (array $q) => str_starts_with(ltrim($q['query']), 'select')));
        $this->assertCount(4, $reads, 'year, Student, current Enrollment, Campus -- never a School-wide scan');
        foreach ($reads as $read) {
            $this->assertStringContainsString('for share', $read['query']);
            $this->assertStringContainsString('limit 1', $read['query']);
        }
    }
}
