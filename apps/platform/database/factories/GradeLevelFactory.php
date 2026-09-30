<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeLevel>
 */
class GradeLevelFactory extends Factory
{
    protected $model = GradeLevel::class;

    public function definition(): array
    {
        // Far above the small, hand-picked sequences/codes tests pass
        // explicitly (G5, 99, 401, ...), so a random factory row can never
        // collide with them inside one School (grade_levels_school_id_*_unique).
        $sequence = fake()->unique()->numberBetween(10000, 99999);

        return [
            'school_id' => School::factory(),
            'name' => "Grade {$sequence}",
            'code' => "G{$sequence}",
            'sequence' => $sequence,
            'education_stage' => null,
            'status' => 'active',
        ];
    }
}
