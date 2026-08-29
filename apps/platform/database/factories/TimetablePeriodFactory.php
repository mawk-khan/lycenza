<?php

namespace Database\Factories;

use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimetablePeriod>
 */
class TimetablePeriodFactory extends Factory
{
    protected $model = TimetablePeriod::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'code' => 'PERIOD-'.strtoupper(fake()->unique()->bothify('??##')),
            'name' => 'Period '.fake()->unique()->numberBetween(1, 9999),
            'start_time' => '08:00:00',
            'end_time' => '08:45:00',
            'sort_order' => null,
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
