<?php

namespace Database\Factories;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumDelivery>
 *
 * Deliberately defaults NO parent ids -- a CurriculumDelivery's
 * Section, SyllabusUnit and SubjectOffering must all belong to the
 * SAME School AND the same AcademicYear/Campus/GradeLevel context
 * (structurally enforced by the three composite foreign keys), which a
 * single factory relationship default cannot guarantee cleanly under
 * RLS. Same precedent as SyllabusUnitFactory/SectionFactory/
 * SubjectOfferingFactory/TimetableEntryFactory: tests build the graph
 * explicitly and pass the resulting ids.
 */
class CurriculumDeliveryFactory extends Factory
{
    protected $model = CurriculumDelivery::class;

    public function definition(): array
    {
        return [
            // Relative, never a fixed literal: the AcademicYear window
            // these rows must sit inside is itself relative to today, so
            // a hardcoded date would become a time bomb.
            'started_on' => CarbonImmutable::now()->startOfDay()->subDays(10)->toDateString(),
            'completed_on' => null,
            'status' => CurriculumDelivery::STATUS_IN_PROGRESS,
        ];
    }

    public function completed(?string $completedOn = null): static
    {
        return $this->state(fn () => [
            'status' => CurriculumDelivery::STATUS_COMPLETED,
            'completed_on' => $completedOn ?? CarbonImmutable::now()->startOfDay()->subDays(5)->toDateString(),
        ]);
    }
}
