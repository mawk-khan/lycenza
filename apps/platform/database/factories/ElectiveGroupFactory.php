<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ElectiveGroup>
 *
 * Same reasoning as SubjectOfferingFactory: three independent parents
 * (AcademicYear, Campus, GradeLevel) that must share one School are
 * supplied explicitly by the caller, not defaulted here -- avoids
 * hidden cross-context fixtures (checkpoint brief §7/§44).
 */
class ElectiveGroupFactory extends Factory
{
    protected $model = ElectiveGroup::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name).' Group',
            'code' => strtoupper(fake()->unique()->lexify('ELEC???')),
        ];
    }
}
