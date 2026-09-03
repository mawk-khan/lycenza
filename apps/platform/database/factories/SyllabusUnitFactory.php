<?php

namespace Database\Factories;

use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyllabusUnit>
 *
 * Deliberately defaults NO parent id -- a SyllabusUnit's
 * `subject_offering_id` must belong to the SAME School as its
 * `school_id` (structurally enforced by
 * `syllabus_units_subject_offering_fk`), which a single factory
 * relationship default cannot guarantee cleanly under RLS. Same
 * precedent as SectionFactory/SubjectOfferingFactory/
 * TimetableEntryFactory: tests build the graph explicitly and pass the
 * resulting ids.
 */
class SyllabusUnitFactory extends Factory
{
    protected $model = SyllabusUnit::class;

    public function definition(): array
    {
        return [
            'code' => 'U'.fake()->unique()->numberBetween(1, 99999),
            'title' => fake()->sentence(3),
            'sequence' => 1,
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
