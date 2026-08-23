<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'school_id' => School::factory(),
            'academic_department_id' => null,
            'name' => ucfirst($name),
            'code' => strtoupper(substr($name, 0, 6)),
            'short_name' => null,
            'subject_type' => 'core',
            'status' => 'active',
        ];
    }
}
