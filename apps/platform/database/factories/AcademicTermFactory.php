<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicTerm>
 */
class AcademicTermFactory extends Factory
{
    protected $model = AcademicTerm::class;

    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'school_id' => function (array $attributes) {
                return AcademicYear::find($attributes['academic_year_id'])->school_id;
            },
            'name' => 'Term 1',
            'code' => 'T1',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addMonths(3)->toDateString(),
            'sequence' => 1,
        ];
    }
}
