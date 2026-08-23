<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubjectOffering>
 *
 * Same reasoning as SectionFactory: four independent parents that must
 * share one School are supplied explicitly by the caller, not defaulted
 * here.
 */
class SubjectOfferingFactory extends Factory
{
    protected $model = SubjectOffering::class;

    public function definition(): array
    {
        return [
            'is_required' => true,
            'sequence' => null,
            'weekly_periods_target' => null,
            'status' => 'active',
        ];
    }
}
