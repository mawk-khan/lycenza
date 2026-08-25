<?php

namespace Database\Factories;

use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentSubjectEnrollment>
 *
 * Deliberately does NOT default `school_id`/`student_id`/
 * `subject_offering_id`/`academic_year_id` -- mirrors
 * StudentEnrollmentFactory's identical rationale. Tests build the full
 * graph via Tests\Concerns\CreatesTenancyFixtures::createStudentSubjectEnrollment()
 * and pass the resulting ids explicitly.
 */
class StudentSubjectEnrollmentFactory extends Factory
{
    protected $model = StudentSubjectEnrollment::class;

    public function definition(): array
    {
        return [
            'status' => 'active',
            'starts_on' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'ends_on' => null,
        ];
    }
}
