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
        $sequence = fake()->unique()->numberBetween(1, 500);

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
