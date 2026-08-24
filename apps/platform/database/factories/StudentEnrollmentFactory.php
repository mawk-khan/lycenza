<?php

namespace Database\Factories;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentEnrollment>
 *
 * Deliberately does NOT default `school_id`/`student_id`/
 * `academic_year_id`/`campus_id`/`grade_level_id`/`section_id` -- an
 * Enrollment spans five independent parents that must all belong to
 * the SAME School, which a single factory relationship default cannot
 * guarantee cleanly under RLS (each parent's own creation must happen
 * inside that School's TenantContext), matching SectionFactory's exact
 * precedent. Tests build the full graph via
 * Tests\Concerns\CreatesTenancyFixtures::createStudentEnrollment() and
 * pass the resulting ids explicitly.
 */
class StudentEnrollmentFactory extends Factory
{
    protected $model = StudentEnrollment::class;

    public function definition(): array
    {
        return [
            // Range is deliberately far wider than any single test's
            // section size (some scale tests create 60+ enrollments
            // sharing one Section/AcademicYear, each still evaluating
            // this default before an explicit override -- if any --
            // is merged in): fake()->unique() tracks draws for the
            // life of the current Faker generator instance with zero
            // slack once the requested count meets the range size, so
            // a range sized to exactly the largest known fixture count
            // is one Faker retry away from an intermittent
            // OverflowException. 100000 leaves comfortable headroom.
            'roll_number' => (string) fake()->unique()->numberBetween(1, 100000),
            'status' => 'active',
            'starts_on' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'ends_on' => null,
        ];
    }
}
