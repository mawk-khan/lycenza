<?php

namespace Tests\Concerns;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * TCH.3 fixtures on top of the TCH.2 teaching world (2026-27 year from
 * 2026-04-01): a second Section ("B") and a second required Offering ("Y")
 * in the same context, active SyllabusUnits, a Tier 1 delivery
 * administrator, and helpers for fully eligible teachers and their
 * TeachingAssignments. All dates are fixed and in the past, so the
 * "not in the future" delivery rule never depends on the wall clock.
 *
 * Requires CreatesTenancyFixtures and CreatesTeachingAssignmentFixtures.
 */
trait CreatesTeacherDeliveryFixtures
{
    /** @return array<string, mixed> */
    protected function teacherWorld(): array
    {
        $w = $this->teachingWorld();
        $w['sectionB'] = $this->createSection($w['year'], $w['campus'], $w['grade'], ['name' => 'B', 'code' => 'TCH-B']);
        $w['offeringY'] = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => true]);
        $w['units'] = [
            $this->unitFor($w, $w['offering'], 'X1', 1),
            $this->unitFor($w, $w['offering'], 'X2', 2),
            $this->unitFor($w, $w['offering'], 'X3', 3),
        ];
        $w['unitY'] = $this->unitFor($w, $w['offeringY'], 'Y1', 1);
        $w['deliveryAdmin'] = $this->createUserWithCapabilities($w['school'], ['curriculum.delivery.view', 'curriculum.delivery.manage']);

        return $w;
    }

    protected function unitFor(array $w, $offering, string $code, int $sequence): SyllabusUnit
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => SyllabusUnit::query()->create([
            'school_id' => $w['school']->id,
            'subject_offering_id' => $offering->id,
            'code' => $code,
            'title' => "Unit {$code}",
            'sequence' => $sequence,
            'status' => 'active',
        ]));
    }

    /**
     * An enabled member linked to an active Employee with an open, active
     * employment from 2026-01-01 -- holding the production `teacher` role
     * unless $roleKey says otherwise (null = no role at all).
     *
     * @return array{0: User, 1: Employee, 2: SchoolMembership}
     */
    protected function teacher(array $w, ?string $roleKey = 'teacher', bool $linked = true): array
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $w['school']);
        if ($roleKey !== null) {
            $this->assignSchoolRole($membership, $roleKey);
        }

        $employee = $this->createEmployee($w['school'], ['user_id' => $linked ? $user->id : null]);
        $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active']);

        return [$user, $employee, $membership];
    }

    protected function own(array $w, Employee $employee, string $startsOn = '2026-04-01', ?string $endsOn = null, $section = null, $offering = null): TeachingAssignment
    {
        return app(TeachingAssignmentService::class)->create(
            $w['school'],
            $employee->id,
            ($section ?? $w['section'])->id,
            ($offering ?? $w['offering'])->id,
            $startsOn,
            $endsOn,
            $w['admin'],
        );
    }

    /** A delivery written directly (arrangement only), in the given context. */
    protected function deliveryRow(array $w, SyllabusUnit $unit, string $startedOn, ?string $completedOn = null, $section = null): CurriculumDelivery
    {
        return app(TenantContext::class)->withSchool($w['school'], function () use ($w, $unit, $startedOn, $completedOn, $section) {
            $offering = $unit->subject_offering_id === $w['offering']->id ? $w['offering'] : $w['offeringY'];
            $delivery = new CurriculumDelivery;
            $delivery->forceFill([
                'school_id' => $w['school']->id,
                'section_id' => ($section ?? $w['section'])->id,
                'syllabus_unit_id' => $unit->id,
                'subject_offering_id' => $offering->id,
                'academic_year_id' => $offering->academic_year_id,
                'campus_id' => $offering->campus_id,
                'grade_level_id' => $offering->grade_level_id,
                'started_on' => $startedOn,
                'completed_on' => $completedOn,
                'status' => $completedOn === null ? 'in_progress' : 'completed',
            ])->save();

            return $delivery;
        });
    }
}
