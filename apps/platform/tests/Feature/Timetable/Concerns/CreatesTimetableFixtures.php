<?php

namespace Tests\Feature\Timetable\Concerns;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\CreatesTenancyFixtures;

/**
 * Phase 0H (Timetable foundation) fixture helpers, deliberately kept
 * alongside the Timetable tests themselves (this checkpoint's brief
 * scopes writes to `tests/Feature/Timetable/` + `tests/Feature/Postgres/`
 * + `tests/Feature/Authorization/` only -- `Tests\Concerns\CreatesTenancyFixtures`
 * is read-only USED here, never modified) rather than added to it,
 * mirroring that trait's own established per-module helper shape
 * (`createSection()`, `createSubjectOffering()`, ...) for the two new
 * Timetable models.
 */
trait CreatesTimetableFixtures
{
    use CreatesTenancyFixtures;

    protected function createTimetablePeriod(School $school, array $attributes = []): TimetablePeriod
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => TimetablePeriod::factory()->create(array_merge([
                'school_id' => $school->id,
            ], $attributes)),
        );
    }

    /**
     * `academic_year_id`/`campus_id`/`grade_level_id` are always
     * derived from the given SubjectOffering, exactly like the real
     * App\Domain\Timetable\Application\TimetableScheduleService derives
     * them in production -- a test fixture must never be able to
     * construct an inconsistent SubjectOffering-vs-denormalized-context
     * state real code could not produce. `room_id` is set only when
     * `$attributes` supplies one (or a real Room is passed) -- omit it
     * entirely for a roomless entry.
     */
    protected function createTimetableEntry(
        SubjectOffering $offering,
        Section $section,
        Employee $teacher,
        TimetablePeriod $period,
        array $attributes = [],
    ): TimetableEntry {
        return app(TenantContext::class)->withSchool(
            $offering->school,
            fn () => TimetableEntry::factory()->create(array_merge([
                'school_id' => $offering->school_id,
                'academic_year_id' => $offering->academic_year_id,
                'campus_id' => $offering->campus_id,
                'grade_level_id' => $offering->grade_level_id,
                'subject_offering_id' => $offering->id,
                'section_id' => $section->id,
                'teacher_id' => $teacher->id,
                'period_id' => $period->id,
            ], $attributes)),
        );
    }

    /**
     * Phase 0H fixture convenience: an actor holding EVERY Timetable
     * capability at $school, mirroring `fullHrActor()`/`fullFinanceActor()`
     * in Tests\Concerns\CreatesTenancyFixtures. Used by tests that are
     * not themselves testing authorization.
     */
    protected function fullTimetableActor(School $school): User
    {
        return $this->createUserWithCapabilities($school, [
            'timetable.periods.view', 'timetable.periods.manage',
            'timetable.schedule.view', 'timetable.schedule.manage',
        ]);
    }
}
