<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 4.2: the database's own placement rules, through the RUNTIME role with
 * raw SQL -- no application code involved. A new placement starts inside its
 * (Section's) academic year, both ends inclusive; and an end before the start
 * is admitted only as the pre-start cancellation's empty interval.
 */
class PlacementDatabaseBackstopTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private AcademicYear $year;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $this->section = $this->createSection($this->year, $this->createCampus($this->school), $this->createGradeLevel($this->school), ['status' => 'active']);
    }

    private function insert(Student $student, string $startsOn, string $roll): string
    {
        $id = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($this->school, fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('student_enrollments')->insert([
            'id' => $id, 'school_id' => $this->school->id, 'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'campus_id' => $this->section->campus_id, 'grade_level_id' => $this->section->grade_level_id, 'section_id' => $this->section->id,
            'roll_number' => $roll, 'status' => 'active', 'starts_on' => $startsOn, 'created_at' => now(), 'updated_at' => now(),
        ])));

        return $id;
    }

    private function refused(callable $statement, string $rule): void
    {
        try {
            $statement();
            $this->fail("expected {$rule}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($rule, $e->getMessage());
        }
    }

    #[Test]
    public function a_start_outside_the_sections_year_is_refused_and_both_boundaries_are_accepted(): void
    {
        $this->refused(fn () => $this->insert($this->createStudent($this->school), '2026-03-31', '1'), 'student_enrollments_start_within_year');
        $this->refused(fn () => $this->insert($this->createStudent($this->school), '2027-04-01', '2'), 'student_enrollments_start_within_year');

        $this->insert($this->createStudent($this->school), '2026-04-01', '3');
        $this->insert($this->createStudent($this->school), '2027-03-31', '4');
        $this->assertSame(2, app(TenantContext::class)->withSchool($this->school, fn () => DB::table('student_enrollments')->count()));
    }

    #[Test]
    public function an_end_before_the_start_is_only_the_cancellations_empty_interval(): void
    {
        $id = $this->insert($this->createStudent($this->school), '2026-06-01', '1');

        $this->refused(fn () => app(TenantContext::class)->withSchool($this->school, fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('student_enrollments')->where('id', $id)->update(['status' => 'transferred', 'ends_on' => '2026-05-31']))), 'student_enrollments_date_range_check');
        $this->refused(fn () => app(TenantContext::class)->withSchool($this->school, fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('student_enrollments')->where('id', $id)->update(['status' => 'transferred', 'ends_on' => '2026-05-30']))), 'student_enrollments_date_range_check');
    }
}
