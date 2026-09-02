<?php

namespace Database\Factories;

use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExaminationPaper>
 *
 * Deliberately defaults NO parent ids or integrity pins -- an
 * ExaminationPaper's `examination_id` and `subject_offering_id` must both
 * belong to the SAME School AND the SAME AcademicYear as this row
 * (structurally enforced by `examination_papers_examination_fk` and
 * `examination_papers_subject_offering_fk`), which no single factory
 * default can guarantee cleanly under RLS. Same precedent as
 * ExaminationFactory/SectionFactory: tests build the graph explicitly and
 * pass the resulting ids (school_id, examination_id, subject_offering_id,
 * academic_year_id, campus_id, grade_level_id).
 *
 * `scheduled_on` is RELATIVE, never a fixed literal: it must fall inside
 * whatever Examination window a test builds, which is itself relative to
 * today.
 */
class ExaminationPaperFactory extends Factory
{
    protected $model = ExaminationPaper::class;

    public function definition(): array
    {
        return [
            'scheduled_on' => CarbonImmutable::now()->startOfDay()->addDays(12)->toDateString(),
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
            'max_marks' => '100.00',
            'status' => ExaminationPaper::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ExaminationPaper::STATUS_INACTIVE]);
    }
}
