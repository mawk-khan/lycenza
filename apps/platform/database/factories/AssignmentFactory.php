<?php

namespace Database\Factories;

use App\Domain\LMS\Infrastructure\Assignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 *
 * Deliberately defaults NO parent id -- an Assignment's
 * `subject_offering_id` must belong to the SAME School as its
 * `school_id` (structurally enforced by
 * `assignments_subject_offering_fk`), which a single factory
 * relationship default cannot guarantee cleanly under RLS. Same
 * precedent as LearningContentFactory/SyllabusUnitFactory: tests build
 * the graph explicitly and pass the resulting ids.
 */
class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'instructions' => fake()->paragraph(),
            'due_on' => null,
            'status' => Assignment::STATUS_DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => Assignment::STATUS_PUBLISHED, 'due_on' => fake()->date()]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => Assignment::STATUS_CLOSED, 'due_on' => fake()->date()]);
    }
}
