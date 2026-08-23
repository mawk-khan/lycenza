<?php

namespace Database\Factories;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_number' => (string) fake()->unique()->numberBetween(100000, 999999),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-18 years', '-4 years')->format('Y-m-d'),
            'status' => 'active',
        ];
    }
}
