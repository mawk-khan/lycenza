<?php

namespace Database\Factories;

use App\Domain\Visitor\Infrastructure\Visitor;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    protected $model = Visitor::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'full_name' => fake()->name(),
            'phone' => fake()->numerify('##########'),
            'status' => 'active',
        ];
    }
}
