<?php

namespace Database\Factories;

use App\Domain\Examinations\Infrastructure\Examination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Examination>
 *
 * Deliberately defaults NO parent ids -- an Examination's
 * `academic_year_id` must belong to the SAME School as its `school_id`
 * (structurally enforced by `examinations_academic_year_fk`), which a
 * single factory relationship default cannot guarantee cleanly under
 * RLS. Same precedent as SyllabusUnitFactory/CurriculumDeliveryFactory/
 * SectionFactory: tests build the graph explicitly and pass the
 * resulting ids.
 *
 * Dates are RELATIVE, never fixed literals: the AcademicYear window a
 * test builds is itself relative to today, so a hardcoded range would
 * become a time bomb the moment the wall clock passed it.
 */
class ExaminationFactory extends Factory
{
    protected $model = Examination::class;

    public function definition(): array
    {
        return [
            'code' => 'EX'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'Examination '.fake()->word(),
            'starts_on' => CarbonImmutable::now()->startOfDay()->addDays(10)->toDateString(),
            'ends_on' => CarbonImmutable::now()->startOfDay()->addDays(20)->toDateString(),
            'status' => Examination::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Examination::STATUS_INACTIVE]);
    }
}
