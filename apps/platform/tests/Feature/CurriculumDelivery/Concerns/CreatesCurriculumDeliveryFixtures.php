<?php

namespace Tests\Feature\CurriculumDelivery\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Tests\Concerns\CreatesTenancyFixtures;

/**
 * Phase 0H.3B fixture helpers. `Tests\Concerns\CreatesTenancyFixtures`
 * is USED read-only here, never modified -- the same discipline
 * CreatesSyllabusFixtures/CreatesAttendanceFixtures followed.
 *
 * The AcademicYear spans today-6mo..today+6mo by default, deliberately
 * RELATIVE rather than a fixed literal pair: several invariants under
 * test ("not in the future", "inside the AcademicYear") are evaluated
 * against the real current date, so a hardcoded window would quietly
 * turn into a time bomb the moment the wall clock passed it.
 */
trait CreatesCurriculumDeliveryFixtures
{
    use CreatesTenancyFixtures;

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel,
     *               offering: SubjectOffering, section: Section, unit: SyllabusUnit, actor: User}
     */
    protected function deliveryWorld(array $yearAttributes = [], array $offeringAttributes = []): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, array_merge([
            'status' => 'active',
            'starts_on' => $this->today()->subMonths(6)->toDateString(),
            'ends_on' => $this->today()->addMonths(6)->toDateString(),
        ], $yearAttributes));
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, array_merge([
            'is_required' => true, 'status' => 'active',
        ], $offeringAttributes));
        $section = $this->createSection($year, $campus, $grade, ['code' => 'A', 'status' => 'active']);
        $unit = $this->createSyllabusUnitFor($offering, ['code' => 'U1', 'sequence' => 1]);

        return [
            'school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade,
            'subject' => $subject, 'offering' => $offering, 'section' => $section, 'unit' => $unit,
            'actor' => $this->fullDeliveryActor($school),
        ];
    }

    /**
     * "Today" exactly as CurriculumDeliveryService evaluates it: in the
     * School's own timezone (SchoolTimezone), not UTC. Every
     * deliveryWorld() School uses SchoolFactory's default timezone; a
     * UTC "today" disagreed with it for part of every day (e.g. 18:30-
     * 24:00 UTC for Asia/Kolkata), making future-date assertions flaky.
     */
    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now(new DateTimeZone((string) School::factory()->make()->timezone))->startOfDay();
    }

    protected function createSyllabusUnitFor(SubjectOffering $offering, array $attributes = []): SyllabusUnit
    {
        return app(TenantContext::class)->withSchool(
            $offering->school,
            fn () => SyllabusUnit::factory()->create(array_merge([
                'school_id' => $offering->school_id,
                'subject_offering_id' => $offering->id,
            ], $attributes)),
        );
    }

    /**
     * Builds a delivery row directly, bypassing the service, so a test
     * can arrange an EXISTING delivery without depending on the very
     * write path it is about to exercise. The four structural pins are
     * derived from the Offering exactly as the service derives them.
     */
    protected function createDelivery(SubjectOffering $offering, Section $section, SyllabusUnit $unit, array $attributes = []): CurriculumDelivery
    {
        return app(TenantContext::class)->withSchool(
            $offering->school,
            fn () => CurriculumDelivery::factory()->create(array_merge([
                'school_id' => $offering->school_id,
                'section_id' => $section->id,
                'syllabus_unit_id' => $unit->id,
                'subject_offering_id' => $offering->id,
                'academic_year_id' => $offering->academic_year_id,
                'campus_id' => $offering->campus_id,
                'grade_level_id' => $offering->grade_level_id,
                'started_on' => $this->today()->subDays(10)->toDateString(),
            ], $attributes)),
        );
    }

    protected function fullDeliveryActor(School $school): User
    {
        return $this->createUserWithCapabilities($school, ['curriculum.delivery.view', 'curriculum.delivery.manage']);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected function inDeliverySchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }
}
