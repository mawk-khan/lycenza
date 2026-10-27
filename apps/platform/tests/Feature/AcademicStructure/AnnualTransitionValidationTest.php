<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\AcademicTermService;
use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearActivationBlockedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearClosedForStructureException;
use App\Domain\AcademicStructure\Application\SectionAdministrationService;
use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverItemExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\CrossSchoolRolloverPlanException;
use App\Domain\Students\Application\Exceptions\RolloverTargetYearNotDraftException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\TeachingAssignments\Application\ElectiveTeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\Exceptions\AssignmentOutsideAcademicYearException;
use App\Domain\TeachingAssignments\Application\Exceptions\InvalidAssignmentDatesException;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 5.2 (owner decisions): a closed Academic Year takes no new Section or
 * Term (it keeps and shows the old ones); rollover prepares only a DRAFT
 * target -- checked at plan creation, validation, execution claim and every
 * item, and activation waits while a rollover into the year is executing; a
 * teaching assignment never ends after its year.
 */
class AnnualTransitionValidationTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Campus $campus;

    private GradeLevel $g5;

    private GradeLevel $g6;

    private AcademicYear $closed;

    private AcademicYear $current;

    private AcademicYear $next;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $today = CarbonImmutable::parse(SchoolTimezone::today($this->school));
        $this->admin = $this->createUserWithCapabilities($this->school, ['academics.years.view', 'academics.years.manage', 'academics.structure.view', 'academics.structure.manage', 'enrollments.view', 'enrollments.manage', 'enrollments.rollovers.view', 'enrollments.rollovers.manage']);
        $this->campus = $this->createCampus($this->school);
        $this->g5 = $this->createGradeLevel($this->school, ['code' => 'G5', 'sequence' => 5]);
        $this->g6 = $this->createGradeLevel($this->school, ['code' => 'G6', 'sequence' => 6]);
        $this->closed = $this->createAcademicYear($this->school, ['code' => 'Y0', 'name' => 'Old', 'status' => 'closed', 'starts_on' => $today->subDays(800)->toDateString(), 'ends_on' => $today->subDays(431)->toDateString()]);
        $this->current = $this->createAcademicYear($this->school, ['code' => 'Y1', 'name' => 'Current', 'status' => 'active', 'starts_on' => $today->subDays(430)->toDateString(), 'ends_on' => $today->subDays(31)->toDateString()]);
        // The next year has started by the School's date, so it could be activated.
        $this->next = $this->createAcademicYear($this->school, ['code' => 'Y2', 'name' => 'Next', 'status' => 'draft', 'starts_on' => $today->subDays(30)->toDateString(), 'ends_on' => $today->addDays(330)->toDateString()]);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** A direct status change (bypassing the services); making a year active closes the active one first. */
    private function setStatus(AcademicYear $year, string $status): void
    {
        $this->inSchool(function () use ($year, $status): void {
            if ($status === 'active') {
                AcademicYear::query()->where('school_id', $this->school->id)->where('status', 'active')->update(['status' => 'closed']);
            }
            AcademicYear::query()->whereKey($year->id)->update(['status' => $status]);
        });
    }

    private function section(AcademicYear $year, string $code): Section
    {
        return app(SectionAdministrationService::class)->create($this->school, $year->id, ['campus_id' => $this->campus->id, 'grade_level_id' => $this->g5->id, 'name' => $code, 'code' => $code], $this->admin);
    }

    private function term(AcademicYear $year, string $code): AcademicTerm
    {
        return app(AcademicTermService::class)->createFromInput($this->school, $year->id, ['name' => $code, 'code' => $code, 'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->starts_on->copy()->addMonth()->toDateString(), 'sequence' => 1], $this->admin);
    }

    // --- Closed-year structure ------------------------------------------------------------

    #[Test]
    public function a_draft_or_active_year_takes_new_sections_and_terms_and_a_closed_one_does_not(): void
    {
        $this->assertSame($this->next->id, $this->section($this->next, 'D')->academic_year_id);
        $this->assertSame($this->current->id, $this->section($this->current, 'A')->academic_year_id);
        $this->assertSame($this->next->id, $this->term($this->next, 'TD')->academic_year_id);
        $this->assertSame($this->current->id, $this->term($this->current, 'TA')->academic_year_id);

        $this->assertThrows(fn () => $this->section($this->closed, 'C'), AcademicYearClosedForStructureException::class, 'Academic Year Old is closed. It keeps its existing Sections but takes no new ones.');
        $this->assertThrows(fn () => $this->term($this->closed, 'TC'), AcademicYearClosedForStructureException::class, 'It keeps its existing Terms but takes no new ones.');
        $this->assertSame([0, 0], $this->inSchool(fn () => [Section::query()->where('academic_year_id', $this->closed->id)->count(), AcademicTerm::query()->where('academic_year_id', $this->closed->id)->count()]));
    }

    #[Test]
    public function a_closed_years_existing_structure_stays_readable_and_a_forged_web_create_is_refused(): void
    {
        $historic = $this->createSection($this->closed, $this->campus, $this->g5, ['code' => 'H', 'name' => 'H']);
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        $this->get("/app/school-setup/sections?academic_year_id={$this->closed->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('sections.data.0.id', $historic->id));

        $this->from("/app/school-setup/sections?academic_year_id={$this->closed->id}")
            ->post("/app/school-setup/academic-years/{$this->closed->id}/sections", ['campus_id' => $this->campus->id, 'grade_level_id' => $this->g5->id, 'name' => 'X', 'code' => 'X'])
            ->assertSessionHasErrors(['refusal' => 'Academic Year Old is closed. It keeps its existing Sections but takes no new ones.']);
        $this->from("/app/school-setup/terms?academic_year_id={$this->closed->id}")
            ->post("/app/school-setup/academic-years/{$this->closed->id}/terms", ['name' => 'X', 'code' => 'X', 'sequence' => 1, 'starts_on' => $this->closed->starts_on->toDateString(), 'ends_on' => $this->closed->starts_on->copy()->addMonth()->toDateString()])
            ->assertSessionHasErrors(['refusal' => 'Academic Year Old is closed. It keeps its existing Terms but takes no new ones.'])
            ->assertSessionDoesntHaveErrors('starts_on');
        $this->assertSame(1, $this->inSchool(fn () => Section::query()->where('academic_year_id', $this->closed->id)->count()));
        $this->assertSame(0, $this->inSchool(fn () => AcademicTerm::query()->where('academic_year_id', $this->closed->id)->count()));
    }

    // --- Rollover target ---------------------------------------------------------------------

    /** @return array{0: EnrollmentRolloverPlan, 1: Section} a plan Current -> Next with one promoted Student */
    private function plan(): array
    {
        $source = $this->createSection($this->current, $this->campus, $this->g5, ['code' => 'S5', 'status' => 'active']);
        $target = $this->createSection($this->next, $this->campus, $this->g6, ['code' => 'T6', 'status' => 'active']);
        $this->createStudentEnrollment($this->createStudent($this->school), $source, ['roll_number' => '1']);
        $plans = app(EnrollmentRolloverPlanService::class);
        $plan = $plans->createDraft($this->school, $this->current, $this->next, $this->admin);
        $plans->upsertMapping($plan, $this->g5, null, $this->g6, $target);
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        $item = $this->inSchool(fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->sole());
        $plans->setItemDecision($this->inSchool(fn () => $plan->fresh()), $item, 'promote', null, 'preserve_source');

        return [$this->inSchool(fn () => $plan->fresh()), $target];
    }

    private function targetPlacements(): int
    {
        return $this->inSchool(fn () => StudentEnrollment::query()->where('academic_year_id', $this->next->id)->count());
    }

    #[Test]
    public function a_plan_can_target_only_a_draft_year(): void
    {
        $plans = app(EnrollmentRolloverPlanService::class);

        $this->assertThrows(fn () => $plans->createDraft($this->school, $this->closed, $this->current, $this->admin), RolloverTargetYearNotDraftException::class, 'Target Academic Year Current is active');
        $this->assertThrows(fn () => $plans->createDraft($this->school, $this->current, $this->closed, $this->admin), RolloverTargetYearNotDraftException::class, 'Target Academic Year Old is closed');
        $this->assertSame(0, $this->inSchool(fn () => EnrollmentRolloverPlan::query()->count()));
        $this->assertSame('draft', $plans->createDraft($this->school, $this->current, $this->next, $this->admin)->status);
    }

    #[Test]
    public function validation_refuses_a_target_that_stopped_being_draft_before_any_student_is_judged(): void
    {
        [$plan] = $this->plan();
        $this->setStatus($this->next, 'active');

        $this->assertThrows(fn () => app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh())), RolloverTargetYearNotDraftException::class, 'Target Academic Year Next is active');
        $this->assertSame('draft', $this->inSchool(fn () => $plan->fresh())->status);
    }

    #[Test]
    public function execution_refuses_a_target_that_stopped_being_draft_and_touches_no_student(): void
    {
        [$plan] = $this->plan();
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        $this->assertSame('validated', $this->inSchool(fn () => $plan->fresh())->status);
        $this->setStatus($this->next, 'active');

        $this->assertThrows(fn () => app(EnrollmentRolloverExecutionService::class)->start($this->inSchool(fn () => $plan->fresh()), $this->admin), RolloverTargetYearNotDraftException::class);
        $this->assertSame(['validated', 0], [$this->inSchool(fn () => $plan->fresh())->status, $this->targetPlacements()]);
    }

    #[Test]
    public function an_item_never_places_into_a_target_that_stopped_being_draft(): void
    {
        [$plan] = $this->plan();
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        $this->inSchool(fn () => EnrollmentRolloverPlan::query()->whereKey($plan->id)->update(['status' => 'executing']));
        $this->setStatus($this->next, 'active'); // only possible by bypassing the activation blocker

        $item = $this->inSchool(fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->sole());
        $executed = app(EnrollmentRolloverItemExecutionService::class)->execute($item, $this->admin);

        $this->assertSame(['failed', 'blocked', 'target_year_not_draft'], [$executed->execution_status, $executed->validation_result, $executed->validation_reason]);
        $this->assertSame(0, $this->targetPlacements());
    }

    #[Test]
    public function activation_waits_while_a_rollover_into_the_year_is_executing_and_proceeds_after_it(): void
    {
        [$plan] = $this->plan();
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        $this->inSchool(fn () => EnrollmentRolloverPlan::query()->whereKey($plan->id)->update(['status' => 'executing', 'execution_started_at' => now()]));

        $this->assertThrows(fn () => app(AcademicYearService::class)->activate($this->inSchool(fn () => $this->next->fresh()), $this->admin), AcademicYearActivationBlockedException::class, 'An enrollment rollover into Next is still running');
        $this->assertSame('draft', $this->inSchool(fn () => $this->next->fresh())->status);

        // The run finishes (resume), then the year activates normally -- never by rollover itself.
        app(EnrollmentRolloverExecutionService::class)->resume($this->inSchool(fn () => $plan->fresh()), $this->admin);
        $this->assertSame('completed', $this->inSchool(fn () => $plan->fresh())->status);
        $this->assertSame([1, 'draft'], [$this->targetPlacements(), $this->inSchool(fn () => $this->next->fresh())->status], 'rollover never activates the year');
        $this->assertSame('active', app(AcademicYearService::class)->activate($this->inSchool(fn () => $this->next->fresh()), $this->admin)->status);
    }

    #[Test]
    public function the_rollover_create_page_offers_only_draft_targets_and_a_forged_target_is_a_field_error(): void
    {
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        $this->get('/app/enrollment-rollovers/create')->assertOk();
        $this->from('/app/enrollment-rollovers/create')->post('/app/enrollment-rollovers', ['source_academic_year_id' => $this->closed->id, 'target_academic_year_id' => $this->current->id])
            ->assertSessionHasErrors(['target_academic_year_id' => 'Rollover prepares a draft Academic Year. Target Academic Year Current is active, so it cannot be rolled into.']);
        $this->assertSame(0, $this->inSchool(fn () => EnrollmentRolloverPlan::query()->count()));
    }

    // --- Teaching assignments ------------------------------------------------------------------

    #[Test]
    public function a_teaching_assignment_ends_inside_its_year_including_its_last_day_never_after(): void
    {
        $w = $this->teachingWorld($this->createSchool());
        $yearEnd = $w['year']->ends_on->toDateString();
        $first = $this->assign($w, '2026-06-01');
        $service = app(TeachingAssignmentService::class);

        $this->assertThrows(fn () => $service->end($w['school'], $first->id, CarbonImmutable::parse($yearEnd)->addDay()->toDateString(), 'completed', $w['admin']), AssignmentOutsideAcademicYearException::class);
        $this->assertThrows(fn () => $service->end($w['school'], $first->id, '2026-05-31', 'completed', $w['admin']), InvalidAssignmentDatesException::class);
        $this->assertNull(app(TenantContext::class)->withSchool($w['school'], fn () => TeachingAssignment::query()->findOrFail($first->id))->ends_on);

        $this->assertSame($yearEnd, $service->end($w['school'], $first->id, $yearEnd, 'completed', $w['admin'])->ends_on->toDateString());
    }

    #[Test]
    public function an_elective_teaching_assignment_never_ends_after_its_year(): void
    {
        $w = $this->teachingWorld($this->createSchool());
        $elective = $this->assignElective($w, $this->electiveOffering($w), '2026-06-01');

        $this->assertThrows(fn () => app(ElectiveTeachingAssignmentService::class)->end($w['school'], $elective->id, $w['year']->ends_on->copy()->addDay()->toDateString(), 'completed', $w['admin']), AssignmentOutsideAcademicYearException::class);
        $this->assertSame('2026-12-31', app(ElectiveTeachingAssignmentService::class)->end($w['school'], $elective->id, '2026-12-31', 'completed', $w['admin'])->ends_on->toDateString());
    }

    #[Test]
    public function an_employment_end_after_the_year_ends_an_open_assignment_on_the_years_last_day(): void
    {
        $w = $this->teachingWorld($this->createSchool());
        $open = $this->assign($w, '2026-06-01');
        $after = $w['year']->ends_on->copy()->addMonths(2)->toDateString();
        $hrActor = $this->createUserWithCapabilities($w['school'], [TeachingAssignmentService::CAPABILITY_EMPLOYMENT_END]);

        app(TenantContext::class)->withSchool($w['school'], fn () => DB::transaction(fn () => app(TeachingAssignmentService::class)->endForEmployment($w['school'], $w['employee']->id, $after, $hrActor)));

        $ended = app(TenantContext::class)->withSchool($w['school'], fn () => TeachingAssignment::query()->findOrFail($open->id));
        $this->assertSame([$w['year']->ends_on->toDateString(), TeachingAssignment::END_REASON_EMPLOYMENT_ENDED], [$ended->ends_on->toDateString(), $ended->end_reason]);
    }

    #[Test]
    public function the_assignment_end_form_shows_the_year_refusal_on_the_date(): void
    {
        $w = $this->teachingWorld($this->createSchool());
        $assignment = $this->assign($w, '2026-06-01');
        $this->actingAs($w['admin'])->withHeader('X-School-Id', $w['school']->id);

        $this->from('/app/teaching-assignments')->post("/app/teaching-assignments/{$assignment->id}/end", ['ends_on' => $w['year']->ends_on->copy()->addDay()->toDateString(), 'reason' => 'completed'])
            ->assertSessionHasErrors(['ends_on' => 'The assignment dates must fall inside the academic year.']);
    }

    #[Test]
    public function another_schools_records_stay_out_of_reach(): void
    {
        $other = $this->createSchool();
        $foreignYear = $this->createAcademicYear($other, ['status' => 'draft']);

        $this->assertThrows(fn () => app(SectionAdministrationService::class)->create($this->school, $foreignYear->id, ['campus_id' => $this->campus->id, 'grade_level_id' => $this->g5->id, 'name' => 'F', 'code' => 'F'], $this->admin), ModelNotFoundException::class);
        $this->assertThrows(fn () => app(EnrollmentRolloverPlanService::class)->createDraft($this->school, $this->current, $foreignYear, $this->admin), CrossSchoolRolloverPlanException::class);
    }
}
