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
            'roll_number' => (string) fake()->unique()->numberBetween(1, 60),
            'status' => 'active',
            'starts_on' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'ends_on' => null,
        ];
    }
}
