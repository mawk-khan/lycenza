<?php

namespace Database\Factories;

use App\Domain\Examinations\Infrastructure\GradeBand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeBand>
 *
 * Deliberately defaults NO `grade_scale_id` -- a GradeBand's parent
 * must belong to the same School, so tests build the graph explicitly
 * and pass the resulting scale, exactly like `ExaminationPaperFactory`
 * defaults no parent ids either.
 */
class GradeBandFactory extends Factory
{
    protected $model = GradeBand::class;

    public function definition(): array
    {
        return [
            'min_percentage' => fake()->randomFloat(2, 0, 99),
            'label' => fake()->randomElement(['A', 'B', 'C', 'D', 'F']),
        ];
    }
}
