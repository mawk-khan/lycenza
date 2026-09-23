<?php

namespace Database\Factories;

use App\Domain\LMS\Infrastructure\LearningContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearningContent>
 *
 * Deliberately defaults NO parent id -- a LearningContent's
 * `subject_offering_id` must belong to the SAME School as its
 * `school_id` (structurally enforced by
 * `learning_content_subject_offering_fk`), which a single factory
 * relationship default cannot guarantee cleanly under RLS. Same
 * precedent as SyllabusUnitFactory/SectionFactory/SubjectOfferingFactory:
 * tests build the graph explicitly and pass the resulting ids.
 */
class LearningContentFactory extends Factory
{
    protected $model = LearningContent::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'sequence' => 1,
            'status' => LearningContent::STATUS_DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => LearningContent::STATUS_PUBLISHED]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => LearningContent::STATUS_ARCHIVED]);
    }
}
