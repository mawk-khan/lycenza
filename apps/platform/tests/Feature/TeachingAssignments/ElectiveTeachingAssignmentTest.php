<?php

namespace Tests\Feature\TeachingAssignments;

use App\Domain\TeachingAssignments\Application\ElectiveTeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\Exceptions\AcademicYearNotOpenException;
use App\Domain\TeachingAssignments\Application\Exceptions\AssignmentOutsideAcademicYearException;
use App\Domain\TeachingAssignments\Application\Exceptions\ElectiveOfferingOnlyException;
use App\Domain\TeachingAssignments\Application\Exceptions\InvalidAssignmentDatesException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentAlreadyEndedException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentOverlapException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingContextInactiveException;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH-E (ADR 0063 section 45): elective teaching ownership -- the write path
 * (elective Offerings only, the TeachingAssignment rules, Offering-wide,
 * co-teachers allowed, ended never deleted) and the TeachingOwnership seam
 * (elective periods, the lock-capable elective hold, and holdOffering() for
 * any Offering). Ownership is only ownership: no capability, identity or
 * StudentMark authority comes from it.
 */
class ElectiveTeachingAssignmentTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function service(): ElectiveTeachingAssignmentService
    {
        return app(ElectiveTeachingAssignmentService::class);
    }

    private function ownership(): TeachingOwnership
    {
        return app(TeachingOwnership::class);
    }

    /** @return list<SchoolAuditEvent> */
    private function audits(array $w, string $type): array
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->orderBy('occurred_at')->get()->all());
    }

    private function holds(array $w, string $offeringId, string $date, ?string $employeeId = null): bool
    {
        return DB::transaction(fn () => $this->ownership()->holdElective($w['school'], $employeeId ?? $w['employee']->id, $offeringId, $date));
    }

    #[Test]
    public function a_manager_assigns_an_employee_to_an_elective_offering_offering_wide(): void
    {
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);

        $a = $this->assignElective($w, $elective, '2026-06-01', '2026-12-31');

        $this->assertSame([$w['employee']->id, $elective->id, $w['year']->id, $w['campus']->id, $w['grade']->id, '2026-06-01', '2026-12-31', $w['admin']->id],
            [$a->employee_id, $a->subject_offering_id, $a->academic_year_id, $a->campus_id, $a->grade_level_id, $a->starts_on->toDateString(), $a->ends_on->toDateString(), $a->created_by_user_id]);
        $this->assertEquals([
            'electiveTeachingAssignmentId' => $a->id, 'employeeId' => $w['employee']->id, 'subjectOfferingId' => $elective->id,
            'startsOn' => '2026-06-01', 'endsOn' => '2026-12-31',
        ], $this->audits($w, 'elective_teaching_assignment.created')[0]->metadata);
    }

    #[Test]
    public function only_an_active_elective_offering_inside_an_open_year_can_be_assigned(): void
    {
        $w = $this->teachingWorld();

        $this->assertThrows(fn () => $this->assignElective($w, $w['offering']), ElectiveOfferingOnlyException::class);

        $inactive = $this->electiveOffering($w);
        app(TenantContext::class)->withSchool($w['school'], fn () => $inactive->forceFill(['status' => 'inactive'])->save());
        $this->assertThrows(fn () => $this->assignElective($w, $inactive), TeachingContextInactiveException::class);

        $elective = $this->electiveOffering($w);
        $this->assertThrows(fn () => $this->assignElective($w, $elective, '2026-03-31'), AssignmentOutsideAcademicYearException::class);
        $this->assertThrows(fn () => $this->assignElective($w, $elective, '2026-06-01', '2027-04-01'), AssignmentOutsideAcademicYearException::class);
        $this->assertThrows(fn () => $this->assignElective($w, $elective, '2026-06-02', '2026-06-01'), InvalidAssignmentDatesException::class);

        $closed = $this->teachingWorld($w['school'], 'closed');
        $this->assertThrows(fn () => $this->assignElective($closed, $this->electiveOffering($closed)), AcademicYearNotOpenException::class);
        $this->assertSame(0, app(TenantContext::class)->withSchool($w['school'], fn () => ElectiveTeachingAssignment::query()->count()));
    }

    #[Test]
    public function the_manage_capability_is_required_and_another_schools_ids_are_not_found(): void
    {
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);

        $viewer = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view']);
        $this->assertThrows(fn () => $this->assignElective($w, $elective, actor: $viewer), AuthorizationException::class);

        $other = $this->teachingWorld();
        $this->assertThrows(fn () => $this->service()->create($other['school'], $w['employee']->id, $this->electiveOffering($other)->id, '2026-06-01', null, $other['admin']), ModelNotFoundException::class);
        $this->assertThrows(fn () => $this->service()->create($other['school'], $other['employee']->id, $elective->id, '2026-06-01', null, $other['admin']), ModelNotFoundException::class);
    }

    #[Test]
    public function one_employee_never_holds_overlapping_periods_but_co_teachers_may(): void
    {
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);
        $coTeacher = $this->employedTeacher($w['school']);

        $this->assignElective($w, $elective, '2026-06-01', '2026-09-30');
        $this->assertThrows(fn () => $this->assignElective($w, $elective, '2026-09-30'), TeachingAssignmentOverlapException::class);
        $this->assignElective($w, $elective, '2026-10-01');
        $this->assignElective($w, $elective, '2026-06-01', employee: $coTeacher);

        $this->assertTrue($this->holds($w, $elective->id, '2026-07-01'));
        $this->assertTrue($this->holds($w, $elective->id, '2026-07-01', $coTeacher->id), 'co-teachers are equal owners');
    }

    #[Test]
    public function ending_is_once_only_shortens_and_keeps_the_history(): void
    {
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);
        $a = $this->assignElective($w, $elective, '2026-06-01', '2026-12-31');

        $this->assertThrows(fn () => $this->service()->end($w['school'], $a->id, '2027-01-31', 'completed', $w['admin']), InvalidAssignmentDatesException::class);
        $this->assertThrows(fn () => $this->service()->end($w['school'], $a->id, '2026-05-31', 'completed', $w['admin']), InvalidAssignmentDatesException::class);
        $ended = $this->service()->end($w['school'], $a->id, '2026-09-15', 'reassigned', $w['admin']);
        $this->assertSame(['2026-09-15', 'reassigned', $w['admin']->id], [$ended->ends_on->toDateString(), $ended->end_reason, $ended->ended_by_user_id]);
        $this->assertThrows(fn () => $this->service()->end($w['school'], $a->id, '2026-09-01', 'completed', $w['admin']), TeachingAssignmentAlreadyEndedException::class);

        // Historical dates stay owned; dates after the end do not.
        $this->assertTrue($this->holds($w, $elective->id, '2026-09-15'));
        $this->assertFalse($this->holds($w, $elective->id, '2026-09-16'));
        $this->assertEquals([
            'electiveTeachingAssignmentId' => $a->id, 'employeeId' => $w['employee']->id, 'previousEndsOn' => '2026-12-31', 'endsOn' => '2026-09-15', 'endReason' => 'reassigned',
        ], $this->audits($w, 'elective_teaching_assignment.ended')[0]->metadata);
    }

    #[Test]
    public function the_elective_hold_is_dated_inclusive_and_per_employee_and_school(): void
    {
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);
        $this->assignElective($w, $elective, '2026-06-01', '2026-06-30');
        $unrelated = $this->employedTeacher($w['school']);

        $this->assertFalse($this->holds($w, $elective->id, '2026-05-31'), 'before the start');
        $this->assertTrue($this->holds($w, $elective->id, '2026-06-01'), 'the start day');
        $this->assertTrue($this->holds($w, $elective->id, '2026-06-30'), 'the last day');
        $this->assertFalse($this->holds($w, $elective->id, '2026-07-01'), 'after the end');
        $this->assertFalse($this->holds($w, $elective->id, '2026-06-15', $unrelated->id), 'another teacher of the same School');
        $this->assertFalse($this->holds($w, $this->electiveOffering($w)->id, '2026-06-15'), 'another elective');

        $other = $this->teachingWorld();
        $this->assertFalse(DB::transaction(fn () => $this->ownership()->holdElective($other['school'], $w['employee']->id, $elective->id, '2026-06-15')), 'another School never sees it');

        $periods = $this->ownership()->electivePeriods($w['school'], $w['employee']->id);
        $this->assertCount(1, $periods);
        $this->assertSame([$elective->id, '2026-06-01', '2026-06-30'], [$periods[0]->subjectOfferingId, $periods[0]->startsOn, $periods[0]->endsOn]);
        $this->assertSame([], $this->ownership()->periods($w['school'], $w['employee']->id), 'required periods never include electives');
    }

    #[Test]
    public function hold_offering_asks_the_fact_that_matches_the_offering(): void
    {
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);
        $this->assign($w, '2026-06-01');
        $this->assignElective($w, $elective, '2026-06-01');
        $hold = fn (string $offeringId, ?string $sectionId) => DB::transaction(fn () => $this->ownership()->holdOffering($w['school'], $w['employee']->id, $offeringId, $sectionId, '2026-07-01'));

        $this->assertTrue($hold($w['offering']->id, $w['section']->id), 'required: Section x Offering through TeachingAssignment');
        $this->assertFalse($hold($w['offering']->id, null), 'required needs the Section');
        $this->assertTrue($hold($elective->id, null), 'elective: Offering-wide');
        $this->assertFalse($hold($elective->id, $w['section']->id), 'an elective is never judged by a Section');
        $this->assertFalse($hold((string) str()->uuid7(), null), 'an unknown Offering');

        // Required ownership is untouched by the elective fact.
        $this->assertTrue(DB::transaction(fn () => $this->ownership()->hold($w['school'], $w['employee']->id, $w['section']->id, $w['offering']->id, '2026-07-01')));
    }

    #[Test]
    public function ownership_is_not_identity_an_unlinked_employee_still_owns(): void
    {
        // employedTeacher() has no User: ownership is a fact about an Employee; ActingEmployee, capability and
        // authentication are separate predicates every consumer must still check.
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);
        $this->assignElective($w, $elective, '2026-06-01');

        $this->assertNull($w['employee']->user_id);
        $this->assertTrue($this->holds($w, $elective->id, '2026-06-01'));
    }
}
