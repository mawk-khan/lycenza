<?php

namespace Tests\Feature\TeachingAssignments;

use App\Domain\TeachingAssignments\Application\Exceptions\AcademicYearNotOpenException;
use App\Domain\TeachingAssignments\Application\Exceptions\AssignmentOutsideAcademicYearException;
use App\Domain\TeachingAssignments\Application\Exceptions\EmployeeNotAssignableException;
use App\Domain\TeachingAssignments\Application\Exceptions\InvalidAssignmentDatesException;
use App\Domain\TeachingAssignments\Application\Exceptions\RequiredOfferingOnlyException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentAlreadyEndedException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentOverlapException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingContextInactiveException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingContextMismatchException;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentReadService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.2 (ADR 0063 sections 7-10, 15, 22): the TeachingAssignment write
 * path -- creation rules, the administrative capability, structural
 * refusals, the create/end lifecycle, and the inclusive-date overlap rule
 * per (Employee, Section, SubjectOffering), with co-teaching allowed.
 */
class TeachingAssignmentServiceTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function service(): TeachingAssignmentService
    {
        return app(TeachingAssignmentService::class);
    }

    /** @return list<SchoolAuditEvent> */
    private function audits(array $w, string $type): array
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->orderBy('occurred_at')->get()->all());
    }

    private function assignmentCount(array $w): int
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => TeachingAssignment::query()->count());
    }

    #[Test]
    public function a_manager_assigns_an_active_employee_to_a_section_and_required_offering(): void
    {
        $w = $this->teachingWorld();

        $a = $this->assign($w, '2026-06-01', '2026-12-31');

        $this->assertSame($w['employee']->id, $a->employee_id);
        $this->assertSame([$w['section']->id, $w['offering']->id], [$a->section_id, $a->subject_offering_id]);
        $this->assertSame([$w['year']->id, $w['campus']->id, $w['section']->grade_level_id], [$a->academic_year_id, $a->campus_id, $a->grade_level_id], 'Context is server-derived.');
        $this->assertSame(['2026-06-01', '2026-12-31'], [$a->starts_on->toDateString(), $a->ends_on->toDateString()]);
        $this->assertSame($w['admin']->id, $a->created_by_user_id);
        $this->assertNull($a->ended_at);

        [$audit] = $this->audits($w, 'teaching_assignment.created');
        $this->assertSame($w['admin']->id, $audit->actor_user_id);
        $this->assertEquals([
            'teachingAssignmentId' => $a->id, 'employeeId' => $w['employee']->id, 'sectionId' => $w['section']->id,
            'subjectOfferingId' => $w['offering']->id, 'startsOn' => '2026-06-01', 'endsOn' => '2026-12-31',
        ], $audit->metadata);
    }

    #[Test]
    public function the_owner_is_an_employee_who_needs_no_user_account_and_the_administrator_needs_no_employee_record(): void
    {
        $w = $this->teachingWorld();
        $this->assertNull($w['employee']->user_id);
        $this->assertSame($w['employee']->id, $this->assign($w)->employee_id);

        // A seeded School Admin (no Employee row, no ActingEmployee)
        // administers assignments through its capability alone.
        [$schoolAdmin, $school] = $this->createSchoolAdmin();
        $w2 = $this->teachingWorld($school);
        $this->assertSame($w2['employee']->id, $this->assign($w2, actor: $schoolAdmin)->employee_id);
    }

    #[Test]
    public function creating_requires_teaching_assignments_manage(): void
    {
        $w = $this->teachingWorld();

        foreach ([[], ['teaching.assignments.view'], ['timetable.schedule.manage', 'hr.employees.manage']] as $capabilities) {
            try {
                $this->assign($w, actor: $this->createUserWithCapabilities($w['school'], $capabilities));
                $this->fail('Created without teaching.assignments.manage.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->assignmentCount($w));
    }

    #[Test]
    public function a_principal_holds_the_administrative_capabilities(): void
    {
        [$principal, $school] = $this->createSchoolAdmin('principal');
        $w = $this->teachingWorld($school);

        $this->assertSame($w['employee']->id, $this->assign($w, actor: $principal)->employee_id);
    }

    #[Test]
    public function another_schools_employee_section_or_offering_is_the_same_not_found(): void
    {
        $w = $this->teachingWorld();
        $other = $this->teachingWorld();

        $attempts = [
            [$other['employee']->id, $w['section']->id, $w['offering']->id],
            [$w['employee']->id, $other['section']->id, $w['offering']->id],
            [$w['employee']->id, $w['section']->id, $other['offering']->id],
        ];

        foreach ($attempts as [$employee, $section, $offering]) {
            try {
                $this->service()->create($w['school'], $employee, $section, $offering, '2026-06-01', null, $w['admin']);
                $this->fail('A cross-School reference was accepted.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->assignmentCount($w));
    }

    #[Test]
    public function a_section_and_offering_of_different_contexts_are_refused(): void
    {
        $w = $this->teachingWorld();
        $otherGrade = $this->createGradeLevel($w['school']);
        $otherSection = $this->createSection($w['year'], $w['campus'], $otherGrade);

        $this->expectException(TeachingContextMismatchException::class);
        $this->service()->create($w['school'], $w['employee']->id, $otherSection->id, $w['offering']->id, '2026-06-01', null, $w['admin']);
    }

    #[Test]
    public function an_elective_offering_is_refused(): void
    {
        $w = $this->teachingWorld();
        app(TenantContext::class)->withSchool($w['school'], fn () => $w['offering']->forceFill(['is_required' => false])->save());

        $this->expectException(RequiredOfferingOnlyException::class);
        $this->assign($w);
    }

    #[Test]
    public function an_inactive_section_or_offering_is_refused(): void
    {
        foreach (['section', 'offering'] as $target) {
            $w = $this->teachingWorld();
            app(TenantContext::class)->withSchool($w['school'], fn () => $w[$target]->forceFill(['status' => 'inactive'])->save());

            try {
                $this->assign($w);
                $this->fail("An inactive {$target} was accepted.");
            } catch (TeachingContextInactiveException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function an_archived_employee_or_one_without_covering_employment_is_refused(): void
    {
        $w = $this->teachingWorld();

        $archived = $this->employedTeacher($w['school']);
        app(TenantContext::class)->withSchool($w['school'], fn () => $archived->forceFill(['record_status' => 'archived'])->save());

        $separated = $this->employedTeacher($w['school'], ['starts_on' => '2025-01-01', 'ends_on' => '2026-03-31', 'status' => 'separated']);
        $draft = $this->employedTeacher($w['school'], ['status' => 'draft']);
        $unemployed = $this->createEmployee($w['school'], ['user_id' => null]);

        foreach ([$archived, $separated, $draft, $unemployed] as $employee) {
            try {
                $this->assign($w, employee: $employee);
                $this->fail('An ineligible Employee was assigned.');
            } catch (EmployeeNotAssignableException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }

        $this->assertSame(0, $this->assignmentCount($w));
    }

    #[Test]
    public function a_future_hire_can_be_planned_from_their_start_but_not_before(): void
    {
        $w = $this->teachingWorld();
        $joiner = $this->employedTeacher($w['school'], ['starts_on' => '2026-08-01', 'status' => 'pre_joining']);

        $this->assertSame('2026-08-01', $this->assign($w, '2026-08-01', employee: $joiner)->starts_on->toDateString());

        $this->expectException(EmployeeNotAssignableException::class);
        $this->assign($w, '2026-07-01', '2026-07-31', employee: $joiner);
    }

    #[Test]
    public function the_academic_year_must_be_open_and_contain_the_dates(): void
    {
        $w = $this->teachingWorld();

        foreach ([['2026-03-31', null], ['2026-06-01', '2027-04-01'], ['2027-04-01', null]] as [$starts, $ends]) {
            try {
                $this->assign($w, $starts, $ends);
                $this->fail("Accepted dates outside the year: {$starts}..{$ends}");
            } catch (AssignmentOutsideAcademicYearException) {
                $this->addToAssertionCount(1);
            }
        }

        app(TenantContext::class)->withSchool($w['school'], fn () => $w['year']->forceFill(['status' => 'closed'])->save());
        $this->expectException(AcademicYearNotOpenException::class);
        $this->assign($w);
    }

    #[Test]
    public function an_end_before_the_start_is_refused_at_creation(): void
    {
        $w = $this->teachingWorld();

        $this->expectException(InvalidAssignmentDatesException::class);
        $this->assign($w, '2026-06-02', '2026-06-01');
    }

    /** @return array<string, array{0: string, 1: ?string, 2: string, 3: ?string}> */
    public static function overlappingPeriods(): array
    {
        return [
            'exact duplicate' => ['2026-06-01', '2026-09-30', '2026-06-01', '2026-09-30'],
            'partial overlap' => ['2026-06-01', '2026-09-30', '2026-09-01', '2026-12-31'],
            'contained' => ['2026-06-01', '2026-09-30', '2026-07-01', '2026-07-31'],
            'containing' => ['2026-07-01', '2026-07-31', '2026-06-01', '2026-09-30'],
            'touching on one inclusive day' => ['2026-06-01', '2026-09-30', '2026-09-30', '2026-12-31'],
            'starting before and ending on its first day' => ['2026-06-01', '2026-09-30', '2026-05-01', '2026-06-01'],
            'open-ended existing blocks a later period' => ['2026-06-01', null, '2027-01-01', '2027-02-28'],
            'open-ended new over an earlier period' => ['2026-09-01', '2026-09-30', '2026-06-01', null],
        ];
    }

    #[Test]
    #[DataProvider('overlappingPeriods')]
    public function an_overlapping_period_for_the_same_employee_section_and_offering_is_refused(string $s1, ?string $e1, string $s2, ?string $e2): void
    {
        $w = $this->teachingWorld();
        $this->assign($w, $s1, $e1);

        try {
            $this->assign($w, $s2, $e2);
            $this->fail('An overlapping period was accepted.');
        } catch (TeachingAssignmentOverlapException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->assignmentCount($w));
    }

    #[Test]
    public function adjacent_periods_and_a_future_replacement_are_allowed(): void
    {
        $w = $this->teachingWorld();

        $this->assign($w, '2026-06-01', '2026-09-30');
        $this->assign($w, '2026-10-01', '2026-12-31');
        // A replacement planned after the current one's end, created while
        // the current one is still unended -- the rejected "one open row"
        // index would have refused this.
        $this->assign($w, '2027-01-01', null);

        $this->assertSame(3, $this->assignmentCount($w));
    }

    #[Test]
    public function co_teaching_and_different_contexts_for_the_same_employee_are_allowed(): void
    {
        $w = $this->teachingWorld();
        $coTeacher = $this->employedTeacher($w['school']);

        $this->assign($w, '2026-06-01', null);
        $this->assign($w, '2026-06-01', null, $coTeacher);

        $otherSection = $this->createSection($w['year'], $w['campus'], $w['grade'], ['name' => 'Other', 'code' => 'OTHER-SECTION']);
        $otherOffering = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']));
        $this->service()->create($w['school'], $w['employee']->id, $otherSection->id, $w['offering']->id, '2026-06-01', null, $w['admin']);
        $this->service()->create($w['school'], $w['employee']->id, $w['section']->id, $otherOffering->id, '2026-06-01', null, $w['admin']);

        $this->assertSame(4, $this->assignmentCount($w));
    }

    #[Test]
    public function an_assignment_can_be_ended_once_and_the_ended_row_remains(): void
    {
        $w = $this->teachingWorld();
        $a = $this->assign($w, '2026-06-01');

        $ended = $this->service()->end($w['school'], $a->id, '2026-09-30', 'reassigned', $w['admin']);

        $this->assertSame('2026-09-30', $ended->ends_on->toDateString());
        $this->assertNotNull($ended->ended_at);
        $this->assertSame([$w['admin']->id, 'reassigned'], [$ended->ended_by_user_id, $ended->end_reason]);
        $this->assertSame(1, $this->assignmentCount($w), 'An ended assignment is kept.');
        [$audit] = $this->audits($w, 'teaching_assignment.ended');
        $this->assertEquals(['teachingAssignmentId' => $a->id, 'employeeId' => $w['employee']->id, 'previousEndsOn' => null, 'endsOn' => '2026-09-30', 'endReason' => 'reassigned'], $audit->metadata);

        try {
            $this->service()->end($w['school'], $a->id, '2026-08-31', 'completed', $w['admin']);
            $this->fail('An ended assignment was ended again.');
        } catch (TeachingAssignmentAlreadyEndedException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        app(TenantContext::class)->set($w['school']);
        $this->assertSame(['2026-09-30', 'reassigned'], [$a->fresh()->ends_on->toDateString(), $a->fresh()->end_reason]);
    }

    #[Test]
    public function ending_may_shorten_but_never_extend_or_precede_the_start(): void
    {
        $w = $this->teachingWorld();
        $a = $this->assign($w, '2026-06-01', '2026-09-30');

        foreach (['2026-10-01', '2026-05-31'] as $bad) {
            try {
                $this->service()->end($w['school'], $a->id, $bad, 'completed', $w['admin']);
                $this->fail("Accepted end date {$bad}.");
            } catch (InvalidAssignmentDatesException) {
                $this->addToAssertionCount(1);
            }
        }

        // The shortest possible end is the start date itself: there is no
        // cancellation (ADR 0063 section 9).
        $this->assertSame('2026-06-01', $this->service()->end($w['school'], $a->id, '2026-06-01', 'completed', $w['admin'])->ends_on->toDateString());
    }

    #[Test]
    public function ending_frees_the_period_after_it(): void
    {
        $w = $this->teachingWorld();
        $a = $this->assign($w, '2026-06-01');

        $this->service()->end($w['school'], $a->id, '2026-09-30', 'reassigned', $w['admin']);

        $this->assertSame('2026-10-01', $this->assign($w, '2026-10-01')->starts_on->toDateString());
        $this->expectException(TeachingAssignmentOverlapException::class);
        $this->assign($w, '2026-09-30', '2026-09-30');
    }

    #[Test]
    public function ending_requires_manage_and_an_existing_assignment_of_this_school(): void
    {
        $w = $this->teachingWorld();
        $a = $this->assign($w);
        $other = $this->teachingWorld();

        try {
            $this->service()->end($w['school'], $a->id, '2026-09-30', 'completed', $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view']));
            $this->fail('A viewer ended an assignment.');
        } catch (AuthorizationException) {
        }

        $this->expectException(ModelNotFoundException::class);
        $this->service()->end($other['school'], $a->id, '2026-09-30', 'completed', $other['admin']);
    }

    #[Test]
    public function reads_require_view_and_derive_state_from_the_school_local_date(): void
    {
        $w = $this->teachingWorld();
        $a = $this->assign($w, '2026-06-01', '2026-06-30');
        $reads = app(TeachingAssignmentReadService::class);

        $this->assertSame('past', TeachingAssignmentReadService::present($a, '2026-07-01')['state']);
        $this->assertSame('current', TeachingAssignmentReadService::present($a, '2026-06-30')['state']);
        $this->assertSame('upcoming', TeachingAssignmentReadService::present($a, '2026-05-31')['state']);

        $viewer = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view']);
        $this->assertSame($a->id, $reads->find($w['school'], $a->id, $viewer)['id']);
        $this->assertSame(1, $reads->list($w['school'], [], 1, 25, $viewer)->total());

        $this->expectException(AuthorizationException::class);
        $reads->list($w['school'], [], 1, 25, $this->createUserWithCapabilities($w['school'], []));
    }

    #[Test]
    public function a_rolled_back_create_leaves_neither_the_row_nor_its_audit(): void
    {
        $w = $this->teachingWorld();

        try {
            DB::transaction(function () use ($w): void {
                $this->assign($w);

                throw new RuntimeException('caller aborts');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->assignmentCount($w));
        $this->assertSame([], $this->audits($w, 'teaching_assignment.created'));
    }
}
