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

    /**
     * A private, per-process monotonic counter -- deliberately NOT
     * Faker's `unique()`. `Factory::getRawAttributes()` always calls
     * `definition()` as the seed value for its `array_merge()` reduce,
     * before any caller-supplied `create([...])` override is applied,
     * so `fake()->unique()->numberBetween(...)` consumes one slot of
     * its finite tracked pool on every call regardless of whether the
     * caller immediately overrides `roll_number` -- exactly what
     * exhausted a 1-60 pool during a rollover test needing >60 real
     * Enrollments in one placement. A plain incrementing integer can
     * never exhaust and never collides, needs no per-test reset, and
     * remains safe under whatever number of Enrollments a single test
     * (or the whole suite) creates.
     */
    private static int $rollNumberSequence = 0;

    public function definition(): array
    {
        return [
            'roll_number' => (string) ++self::$rollNumberSequence,
            'status' => 'active',
            'starts_on' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'ends_on' => null,
        ];
    }
}
