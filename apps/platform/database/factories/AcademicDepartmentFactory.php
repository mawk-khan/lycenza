<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicDepartment>
 */
class AcademicDepartmentFactory extends Factory
{
    protected $model = AcademicDepartment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->unique()->word().' Department',
            'code' => strtoupper(fake()->unique()->lexify('DEPT???')),
            'status' => 'active',
        ];
    }
}
