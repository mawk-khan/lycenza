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
            // A unique word does not give a unique 6-letter prefix
            // ("consequatur"/"consequuntur" -> CONSEQ), which collided with
            // subjects_school_id_code_unique intermittently; the numeric
            // suffix keeps factory codes unique.
            'code' => strtoupper(substr($name, 0, 6)).fake()->unique()->numberBetween(1, 999999),
            'short_name' => null,
            'subject_type' => 'core',
            'status' => 'active',
        ];
    }
}
