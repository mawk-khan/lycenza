<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.7G: StudentEnrollmentFactory's `roll_number` default used
 * to be `fake()->unique()->numberBetween(1, 60)`. Laravel's own
 * `Factory::getRawAttributes()` always evaluates `definition()` as the
 * seed value for its `array_merge()` reduce BEFORE any caller-supplied
 * `create([...])`/`make([...])` override is applied, so every single
 * invocation consumed one slot of that finite 60-value pool regardless
 * of whether the caller immediately overrode `roll_number` -- a single
 * test needing more than 60 Enrollments (e.g. a rollover Plan with 101
 * Items) reliably exhausted it with `OverflowException: Maximum
 * retries of 10000 reached`. These tests prove the corrected factory
 * (a private static monotonic counter -- no `Faker\UniqueGenerator`
 * involved at all) survives far beyond that former ceiling, both with
 * and without a caller override, and that persisted defaults never
 * collide against the real composite database constraint.
 */
class StudentEnrollmentFactoryReliabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const INVOCATIONS = 200;

    #[Test]
    public function the_factory_default_survives_far_more_than_sixty_invocations_in_one_process(): void
    {
        for ($i = 1; $i <= self::INVOCATIONS; $i++) {
            $enrollment = StudentEnrollment::factory()->make();
            $this->assertIsString($enrollment->roll_number);
            $this->assertNotSame('', $enrollment->roll_number);
        }
    }

    #[Test]
    public function an_explicit_roll_number_override_does_not_prevent_the_factory_from_surviving_far_more_than_sixty_invocations(): void
    {
        // Mirrors every real call site in this suite:
        // createStudentEnrollment()/StudentEnrollment::factory()->create()
        // always pass their own explicit roll_number via array_merge()
        // -- the old implementation still silently consumed a hidden
        // Faker unique() slot on every call despite this override,
        // which is exactly what made the defect invisible until a test
        // needed enough Enrollments to exhaust the pool.
        for ($i = 1; $i <= self::INVOCATIONS; $i++) {
            $rollNumber = sprintf('%03d', $i);
            $enrollment = StudentEnrollment::factory()->make(['roll_number' => $rollNumber]);
            $this->assertSame($rollNumber, $enrollment->roll_number);
        }
    }

    #[Test]
    public function one_hundred_default_generated_enrollments_in_the_same_placement_persist_without_a_roll_number_collision(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);

        $count = 100;
        for ($i = 1; $i <= $count; $i++) {
            $student = $this->createStudent($school, ['student_number' => "S-FACTORY-{$i}"]);
            $this->createStudentEnrollment($student, $section);
        }

        $persisted = app(TenantContext::class)->withSchool(
            $school,
            fn () => StudentEnrollment::query()->where('section_id', $section->id)->count(),
        );
        $this->assertSame($count, $persisted, 'every default-generated Enrollment must persist -- none rejected by the composite Roll Number uniqueness constraint');

        $distinctRollNumbers = app(TenantContext::class)->withSchool(
            $school,
            fn () => StudentEnrollment::query()->where('section_id', $section->id)->distinct()->count('roll_number'),
        );
        $this->assertSame($count, $distinctRollNumbers, 'every default-generated Roll Number in this placement must be distinct');
    }
}
