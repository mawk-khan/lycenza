<?php

namespace Database\Factories;

use App\Domain\Timetable\Infrastructure\TimetableEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimetableEntry>
 *
 * Deliberately does NOT default school_id/academic_year_id/campus_id/
 * grade_level_id/subject_offering_id/section_id/teacher_id/period_id --
 * a TimetableEntry spans several independent parents that must all
 * belong to the SAME School (and, for SubjectOffering/Section, the same
 * AcademicYear/Campus/GradeLevel context), which a single factory
 * relationship default cannot guarantee cleanly under RLS. Mirrors
 * SectionFactory/SubjectOfferingFactory's identical precedent -- tests
 * build the full graph explicitly and pass the resulting ids, or go
 * through App\Domain\Timetable\Application\TimetableScheduleService::create()
 * directly for tests about validation/conflict behavior itself.
 */
class TimetableEntryFactory extends Factory
{
    protected $model = TimetableEntry::class;

    public function definition(): array
    {
        return [
            'day_of_week' => 1,
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
