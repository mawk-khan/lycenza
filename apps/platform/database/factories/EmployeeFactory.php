<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 *
 * For schema/relationship/isolation tests that need an Employee to
 * exist but aren't specifically testing employee-number allocation --
 * `employee_number` here is a plausible fake value (Faker's unique()
 * modifier avoids in-run collisions), NOT allocated through
 * App\Domain\HR\Application\EmployeeNumberAllocator. A test about
 * allocation/concurrency behavior itself must go through
 * App\Domain\HR\Application\EmployeeService::create() directly, not
 * this factory -- mirrors how AcademicYearFactory sets `code` directly
 * rather than going through AcademicYearService.
 *
 * `user_id` defaults to null (no User account) -- ordinary factory
 * usage must not accidentally create a User for every Employee.
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'user_id' => null,
            'employee_number' => 'EMP-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'full_name' => fake()->name(),
            'record_status' => 'active',
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['record_status' => 'archived']);
    }
}
