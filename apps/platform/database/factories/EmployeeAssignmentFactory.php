<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAssignment>
 *
 * Deliberately does NOT default `school_id`/`employment_record_id`/
 * `campus_id`/`department_id`/`position_id` -- same reasoning as
 * SectionFactory/SubjectOfferingFactory: several independent parents
 * that must all share one School are supplied explicitly by the
 * caller via
 * Tests\Concerns\CreatesTenancyFixtures::createEmployeeAssignment().
 */
class EmployeeAssignmentFactory extends Factory
{
    protected $model = EmployeeAssignment::class;

    public function definition(): array
    {
        return [
            'is_primary' => false,
            'starts_on' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'ends_on' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }

    /**
     * A closed, historical Assignment -- both dates in the past,
     * ends_on strictly after starts_on.
     */
    public function ended(): static
    {
        return $this->state(function (array $attributes) {
            $startsOn = $attributes['starts_on'] ?? fake()->dateTimeBetween('-2 years', '-1 year')->format('Y-m-d');

            return [
                'starts_on' => $startsOn,
                'ends_on' => fake()->dateTimeBetween($startsOn, 'now')->format('Y-m-d'),
            ];
        });
    }
}
