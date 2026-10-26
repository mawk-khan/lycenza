<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\AcademicTermService;
use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicCalendarChangedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicCalendarDatesFixedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicTermOutOfRangeException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicTermOverlapException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearDependentsOutsideException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearNotStartedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearOverlapException;
use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverItemExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 5.1 (MC-3, ADR 0077): a DRAFT Academic Year's dates, and its Terms'
 * dates, can be corrected while the year stays draft -- with creation's rules
 * and without leaving any Term or dependent record outside the year. Active
 * and closed calendars stay fixed. Dates only: status, name, code and School
 * never change through it.
 */
class DraftCalendarCorrectionTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private AcademicYear $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->admin = $this->createUserWithCapabilities($this->school, ['academics.years.view', 'academics.years.manage']);
        $this->draft = $this->createAcademicYear($this->school, ['code' => 'Y27', 'name' => '2027-28', 'status' => 'draft', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31']);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function fresh(AcademicYear $year): AcademicYear
    {
        return $this->inSchool(fn () => AcademicYear::query()->findOrFail($year->id));
    }

    /** @return array{starts_on: string, ends_on: string, expected_updated_at: string} */
    private function input(AcademicYear $year, string $startsOn, string $endsOn): array
    {
        return ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'expected_updated_at' => $this->fresh($year)->updated_at->toIso8601String()];
    }

    private function correctYear(AcademicYear $year, string $startsOn, string $endsOn, ?User $actor = null): AcademicYear
    {
        return app(AcademicYearService::class)->correctDraftDates($this->school, $year->id, $this->input($year, $startsOn, $endsOn), $actor ?? $this->admin);
    }

    private function correctTerm(AcademicTerm $term, string $startsOn, string $endsOn): AcademicTerm
    {
        $version = $this->inSchool(fn () => AcademicTerm::query()->findOrFail($term->id))->updated_at->toIso8601String();

        return app(AcademicTermService::class)->correctDraftDates($this->school, $term->id, ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'expected_updated_at' => $version], $this->admin);
    }

    /** @return array{0: string, 1: string} */
    private function datesOf(AcademicYear|AcademicTerm $record): array
    {
        $row = $this->inSchool(fn () => $record::query()->findOrFail($record->id));

        return [$row->starts_on->toDateString(), $row->ends_on->toDateString()];
    }

    private function refusedWithDependents(string $message, callable $operation): void
    {
        try {
            $operation();
            $this->fail('expected a dependent-records refusal');
        } catch (AcademicYearDependentsOutsideException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    // --- Academic Year ----------------------------------------------------------

    #[Test]
    public function a_draft_years_dates_are_corrected_and_audited_and_nothing_else_changes(): void
    {
        $corrected = $this->correctYear($this->draft, '2027-06-01', '2028-05-31');

        $this->assertSame(['2027-06-01', '2028-05-31', 'draft', '2027-28', 'Y27'], [
            $corrected->starts_on->toDateString(), $corrected->ends_on->toDateString(), $corrected->status, $corrected->name, $corrected->code,
        ]);
        $event = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'academic_year.dates_corrected')->sole());
        $this->assertSame([$this->admin->id, $this->draft->id], [$event->actor_user_id, $event->subject_id]);
        $this->assertEquals(['changed' => ['starts_on', 'ends_on'], 'before' => ['starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'], 'after' => ['starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']], $event->metadata);
    }

    #[Test]
    public function only_the_dates_are_taken_from_the_input(): void
    {
        $other = $this->createSchool();
        app(AcademicYearService::class)->correctDraftDates($this->school, $this->draft->id, $this->input($this->draft, '2027-04-02', '2028-03-31') + [
            'status' => 'active', 'name' => 'Forged', 'code' => 'X', 'school_id' => $other->id,
        ], $this->admin);

        $year = $this->fresh($this->draft);
        $this->assertSame(['draft', '2027-28', 'Y27', $this->school->id, '2027-04-02'], [$year->status, $year->name, $year->code, $year->school_id, $year->starts_on->toDateString()]);
    }

    #[Test]
    public function an_active_or_closed_years_dates_stay_fixed(): void
    {
        $active = $this->createAcademicYear($this->school, ['code' => 'Y26', 'status' => 'active', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $closed = $this->createAcademicYear($this->school, ['code' => 'Y25', 'status' => 'closed', 'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31']);

        foreach ([$active, $closed] as $year) {
            try {
                $this->correctYear($year, $year->starts_on->toDateString(), $year->ends_on->subDay()->toDateString());
                $this->fail('a non-draft year was re-dated');
            } catch (AcademicCalendarDatesFixedException $e) {
                $this->assertStringContainsString("This Academic Year is {$year->status}", $e->getMessage());
            }
            $this->assertSame([$year->starts_on->toDateString(), $year->ends_on->toDateString()], $this->datesOf($year));
        }
    }

    #[Test]
    public function creations_rules_apply_order_and_overlap(): void
    {
        $this->createAcademicYear($this->school, ['code' => 'Y28', 'status' => 'draft', 'starts_on' => '2028-04-01', 'ends_on' => '2029-03-31']);

        $this->assertThrows(fn () => $this->correctYear($this->draft, '2028-03-31', '2027-04-01'), ValidationException::class);
        $this->assertThrows(fn () => $this->correctYear($this->draft, '2027-04-01', '2027-04-01'), ValidationException::class);
        $this->assertThrows(fn () => $this->correctYear($this->draft, '2027-04-01', '2028-04-02'), AcademicYearOverlapException::class);
        $this->assertSame(['2027-04-01', '2028-03-31'], $this->datesOf($this->draft));

        // Overlapping only its own old range is fine.
        $this->assertSame('2027-03-15', $this->correctYear($this->draft, '2027-03-15', '2028-03-31')->starts_on->toDateString());
        // Sharing a boundary day with the next year is not an overlap (as at creation).
        $this->assertSame('2028-04-01', $this->correctYear($this->draft, '2027-03-15', '2028-04-01')->ends_on->toDateString());
    }

    #[Test]
    public function a_stale_form_is_refused(): void
    {
        $stale = $this->input($this->draft, '2027-05-01', '2028-03-31');
        $this->travel(2)->seconds();
        $this->correctYear($this->draft, '2027-04-15', '2028-03-31');

        $this->assertThrows(fn () => app(AcademicYearService::class)->correctDraftDates($this->school, $this->draft->id, $stale, $this->admin), AcademicCalendarChangedException::class);
        $this->assertSame(['2027-04-15', '2028-03-31'], $this->datesOf($this->draft));
    }

    #[Test]
    public function authority_and_tenancy_are_unchanged(): void
    {
        $viewer = $this->createUserWithCapabilities($this->school, ['academics.years.view']);
        $this->assertThrows(fn () => $this->correctYear($this->draft, '2027-04-02', '2028-03-31', $viewer), AuthorizationException::class);

        $other = $this->createSchool();
        $foreign = $this->createAcademicYear($other, ['status' => 'draft']);
        $this->assertThrows(fn () => app(AcademicYearService::class)->correctDraftDates($this->school, $foreign->id, ['starts_on' => '2026-06-02', 'ends_on' => '2027-05-31', 'expected_updated_at' => 'x'], $this->admin), ModelNotFoundException::class);
        $this->assertSame(['2026-06-01', '2027-05-31'], app(TenantContext::class)->withSchool($other, fn () => [
            AcademicYear::query()->findOrFail($foreign->id)->starts_on->toDateString(), AcademicYear::query()->findOrFail($foreign->id)->ends_on->toDateString(),
        ]));
    }

    // --- Dependents ---------------------------------------------------------------

    #[Test]
    public function terms_must_stay_inside_the_corrected_year(): void
    {
        $this->createAcademicTerm($this->draft, ['code' => 'T1', 'sequence' => 1, 'starts_on' => '2027-04-01', 'ends_on' => '2027-09-30']);

        $this->refusedWithDependents('Correct the Term dates first: 1 Term would fall outside the proposed Academic Year dates.', fn () => $this->correctYear($this->draft, '2027-04-02', '2028-03-31'));
        $this->assertSame(['2027-04-01', '2028-03-31'], $this->datesOf($this->draft));

        // Growing the year, or shrinking it around the Term, is fine.
        $this->correctYear($this->draft, '2027-03-01', '2028-03-31');
        $this->correctYear($this->draft, '2027-04-01', '2027-12-31');
        $this->assertSame(['2027-04-01', '2027-12-31'], $this->datesOf($this->draft));
    }

    #[Test]
    public function placements_must_stay_inside_the_corrected_year(): void
    {
        $section = $this->createSection($this->draft, $this->createCampus($this->school), $this->createGradeLevel($this->school), ['status' => 'active']);
        $placement = app(StudentEnrollmentService::class)->enroll($this->createStudent($this->school), $section, '1', '2027-04-05');

        $this->refusedWithDependents('These dates cannot be saved: existing placements would fall outside the Academic Year (1). Correct or end them first.', fn () => $this->correctYear($this->draft, '2027-04-10', '2028-03-31'));
        $this->refusedWithDependents('These dates cannot be saved: existing placements would fall outside the Academic Year (1). Correct or end them first.', fn () => $this->correctYear($this->draft, '2026-04-01', '2027-04-04'));

        // A correction that keeps the placement inside is accepted; the placement is untouched.
        $this->correctYear($this->draft, '2027-04-05', '2028-04-30');
        $this->assertSame(['2027-04-05', 'active', null], $this->inSchool(fn () => [$placement->fresh()->starts_on->toDateString(), $placement->fresh()->status, $placement->fresh()->ends_on]));
    }

    #[Test]
    public function teaching_assignments_examinations_and_assignment_due_dates_must_stay_inside(): void
    {
        $w = $this->teachingWorld($this->school, 'draft');
        $year = $w['year'];
        $this->assign($w, '2026-06-01', '2027-02-28');

        $this->refusedWithDependents('These dates cannot be saved: existing teaching assignments would fall outside the Academic Year (1). Correct or end them first.', fn () => $this->correctYear($year, '2026-04-01', '2027-02-27'));

        $this->inSchool(fn () => Examination::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $year->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-10']));
        $this->refusedWithDependents('These dates cannot be saved: existing examinations would fall outside the Academic Year (1). Correct or end them first.', fn () => $this->correctYear($year, '2026-05-02', '2027-03-31'));

        $this->inSchool(fn () => Assignment::factory()->create(['school_id' => $this->school->id, 'subject_offering_id' => $w['offering']->id, 'due_on' => '2027-03-30']));
        $this->refusedWithDependents('These dates cannot be saved: existing assignment due dates would fall outside the Academic Year (1). Correct or end them first.', fn () => $this->correctYear($year, '2026-04-01', '2027-03-29'));

        $this->assertSame(['2026-04-01', '2027-03-31'], $this->datesOf($year));
        $this->assertSame('2026-03-15', $this->correctYear($year, '2026-03-15', '2027-03-31')->starts_on->toDateString());
    }

    #[Test]
    public function a_rollover_placement_into_the_draft_year_is_protected_like_any_other(): void
    {
        $source = $this->createAcademicYear($this->school, ['code' => 'Y26', 'status' => 'active', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $campus = $this->createCampus($this->school);
        $g5 = $this->createGradeLevel($this->school, ['code' => 'G5', 'sequence' => 5]);
        $g6 = $this->createGradeLevel($this->school, ['code' => 'G6', 'sequence' => 6]);
        $from = $this->createSection($source, $campus, $g5, ['status' => 'active']);
        $to = $this->createSection($this->draft, $campus, $g6, ['status' => 'active']);
        app(StudentEnrollmentService::class)->enroll($this->createStudent($this->school), $from, '1', '2026-04-01');

        $plans = app(EnrollmentRolloverPlanService::class);
        $plan = $plans->createDraft($this->school, $source, $this->draft);
        $plans->upsertMapping($plan, $g5, null, $g6, $to);
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        $item = $this->inSchool(fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->sole());
        $plans->setItemDecision($this->inSchool(fn () => $plan->fresh()), $item, 'promote', null, 'preserve_source');
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        $this->assertSame('succeeded', app(EnrollmentRolloverItemExecutionService::class)->execute($this->inSchool(fn () => $item->fresh()))->execution_status);

        // The rollover placement starts on the draft year's first day.
        $this->refusedWithDependents('These dates cannot be saved: existing placements would fall outside the Academic Year (1). Correct or end them first.', fn () => $this->correctYear($this->draft, '2027-04-02', '2028-03-31'));
        $this->assertSame('2028-04-30', $this->correctYear($this->draft, '2027-04-01', '2028-04-30')->ends_on->toDateString());
    }

    // --- Activation interplay ----------------------------------------------------------

    /** A School of its own, so a year around today never meets the setUp draft year. */
    private function ownSchool(): void
    {
        $this->school = $this->createSchool();
        $this->admin = $this->createUserWithCapabilities($this->school, ['academics.years.view', 'academics.years.manage']);
    }

    #[Test]
    public function activation_re_checks_a_start_date_corrected_after_the_page_loaded(): void
    {
        $this->ownSchool();
        $today = CarbonImmutable::parse(SchoolTimezone::today($this->school));
        $year = $this->createAcademicYear($this->school, ['code' => 'NOW', 'status' => 'draft', 'starts_on' => $today->subMonth()->toDateString(), 'ends_on' => $today->addMonths(10)->toDateString()]);
        $staleCopy = $this->fresh($year);

        $this->correctYear($year, $today->addDay()->toDateString(), $today->addMonths(10)->toDateString());

        $this->assertThrows(fn () => app(AcademicYearService::class)->activate($staleCopy), AcademicYearNotStartedException::class);
        $this->assertSame('draft', $this->fresh($year)->status);
    }

    #[Test]
    public function a_correction_after_activation_is_refused(): void
    {
        $this->ownSchool();
        $today = CarbonImmutable::parse(SchoolTimezone::today($this->school));
        $year = $this->createAcademicYear($this->school, ['code' => 'NOW', 'status' => 'draft', 'starts_on' => $today->subMonth()->toDateString(), 'ends_on' => $today->addMonths(10)->toDateString()]);
        $form = $this->input($year, $today->subMonths(2)->toDateString(), $today->addMonths(10)->toDateString());

        app(AcademicYearService::class)->activate($this->fresh($year));

        $this->assertThrows(fn () => app(AcademicYearService::class)->correctDraftDates($this->school, $year->id, $form, $this->admin), AcademicCalendarDatesFixedException::class);
        $this->assertSame([$today->subMonth()->toDateString(), $today->addMonths(10)->toDateString()], $this->datesOf($year));
    }

    // --- Terms ------------------------------------------------------------------------------

    #[Test]
    public function a_draft_years_term_dates_are_corrected_and_audited(): void
    {
        $term = $this->createAcademicTerm($this->draft, ['code' => 'T1', 'name' => 'Term 1', 'sequence' => 1, 'starts_on' => '2027-04-01', 'ends_on' => '2027-09-30']);

        $corrected = $this->correctTerm($term, '2027-04-05', '2027-10-15');

        $this->assertSame(['2027-04-05', '2027-10-15', 'Term 1', 'T1', 1], [$corrected->starts_on->toDateString(), $corrected->ends_on->toDateString(), $corrected->name, $corrected->code, $corrected->sequence]);
        $this->assertSame(['2027-04-01', '2028-03-31'], $this->datesOf($this->draft), 'the year is unchanged');
        $event = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'academic_term.dates_corrected')->sole());
        $this->assertEquals(['academicYearId' => $this->draft->id, 'changed' => ['starts_on', 'ends_on'], 'before' => ['starts_on' => '2027-04-01', 'ends_on' => '2027-09-30'], 'after' => ['starts_on' => '2027-04-05', 'ends_on' => '2027-10-15']], $event->metadata);
    }

    #[Test]
    public function a_term_stays_inside_its_year_and_off_its_siblings(): void
    {
        $t1 = $this->createAcademicTerm($this->draft, ['code' => 'T1', 'sequence' => 1, 'starts_on' => '2027-04-01', 'ends_on' => '2027-09-30']);
        $this->createAcademicTerm($this->draft, ['code' => 'T2', 'sequence' => 2, 'starts_on' => '2027-10-01', 'ends_on' => '2028-03-31']);

        $this->assertThrows(fn () => $this->correctTerm($t1, '2027-03-31', '2027-09-30'), AcademicTermOutOfRangeException::class);
        $this->assertThrows(fn () => $this->correctTerm($t1, '2027-04-01', '2027-10-02'), AcademicTermOverlapException::class);
        $this->assertThrows(fn () => $this->correctTerm($t1, '2027-09-30', '2027-04-01'), ValidationException::class);
        $this->assertSame(['2027-04-01', '2027-09-30'], $this->datesOf($t1));

        // A shared boundary day is allowed, as at creation.
        $this->assertSame('2027-10-01', $this->correctTerm($t1, '2027-04-01', '2027-10-01')->ends_on->toDateString());
    }

    #[Test]
    public function an_active_or_closed_years_terms_stay_fixed(): void
    {
        foreach (['active' => 'Y26', 'closed' => 'Y25'] as $status => $code) {
            $start = $status === 'active' ? '2026-04-01' : '2025-04-01';
            $year = $this->createAcademicYear($this->school, ['code' => $code, 'status' => $status, 'starts_on' => $start, 'ends_on' => CarbonImmutable::parse($start)->addYear()->subDay()->toDateString()]);
            $term = $this->createAcademicTerm($year, ['code' => "T{$code}", 'sequence' => 1, 'starts_on' => $start, 'ends_on' => CarbonImmutable::parse($start)->addMonths(5)->toDateString()]);

            $this->assertThrows(fn () => $this->correctTerm($term, CarbonImmutable::parse($start)->addDay()->toDateString(), CarbonImmutable::parse($start)->addMonths(5)->toDateString()), AcademicCalendarDatesFixedException::class);
            $this->assertSame($start, $this->datesOf($term)[0]);
        }
    }

    #[Test]
    public function a_stale_term_form_and_another_schools_term_are_refused(): void
    {
        $term = $this->createAcademicTerm($this->draft, ['code' => 'T1', 'sequence' => 1, 'starts_on' => '2027-04-01', 'ends_on' => '2027-09-30']);
        $this->assertThrows(fn () => app(AcademicTermService::class)->correctDraftDates($this->school, $term->id, ['starts_on' => '2027-04-02', 'ends_on' => '2027-09-30', 'expected_updated_at' => '2000-01-01T00:00:00+00:00'], $this->admin), AcademicCalendarChangedException::class);

        $other = $this->createSchool();
        $foreignTerm = $this->createAcademicTerm($this->createAcademicYear($other, ['status' => 'draft']), ['code' => 'F1', 'sequence' => 1, 'starts_on' => '2026-06-01', 'ends_on' => '2026-09-30']);
        $this->assertThrows(fn () => app(AcademicTermService::class)->correctDraftDates($this->school, $foreignTerm->id, ['starts_on' => '2026-06-02', 'ends_on' => '2026-09-30', 'expected_updated_at' => 'x'], $this->admin), ModelNotFoundException::class);
        $this->assertSame(['2027-04-01', '2027-09-30'], $this->datesOf($term));
    }

    // --- Browser surface ------------------------------------------------------------------------

    private function signIn(?User $user = null): void
    {
        [$admin] = $this->createSchoolAdmin('school_admin');
        $this->assignSchoolRole($this->createMembership($admin, $this->school), 'school_admin');
        $this->actingAs($user ?? $admin)->post("/app/schools/{$this->school->id}/activate");
    }

    #[Test]
    public function the_year_page_corrects_a_draft_and_shows_errors_on_the_right_fields(): void
    {
        $this->signIn();
        $page = "/app/school-setup/academic-years/{$this->draft->id}/dates";

        $this->get($page)->assertInertia(fn ($p) => $p->component('App/SchoolSetup/AcademicYearDates')
            ->where('academicYear.startsOn', '2027-04-01')->where('academicYear.status', 'draft')->has('academicYear.updatedAt'));

        $this->from($page)->put($page, $this->input($this->draft, '2028-01-01', '2027-06-01'))
            ->assertRedirect($page)->assertSessionHasErrors('ends_on');
        $this->createAcademicTerm($this->draft, ['code' => 'T1', 'sequence' => 1, 'starts_on' => '2027-04-01', 'ends_on' => '2027-09-30']);
        $this->from($page)->put($page, $this->input($this->draft, '2027-05-01', '2028-03-31'))
            ->assertRedirect($page)->assertSessionHasErrors(['refusal' => 'Correct the Term dates first: 1 Term would fall outside the proposed Academic Year dates.']);
        $this->assertSame(['2027-04-01', '2028-03-31'], $this->datesOf($this->draft));

        $this->from($page)->put($page, $this->input($this->draft, '2027-04-01', '2028-04-30'))
            ->assertRedirect('/app/school-setup/academic-years')->assertSessionHasNoErrors();
        $this->assertSame(['2027-04-01', '2028-04-30'], $this->datesOf($this->draft));
    }

    #[Test]
    public function an_active_year_has_no_correction_page_and_a_forged_correction_is_refused(): void
    {
        $active = $this->createAcademicYear($this->school, ['code' => 'Y26', 'status' => 'active', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $this->signIn();
        $page = "/app/school-setup/academic-years/{$active->id}/dates";

        $this->get($page)->assertRedirect('/app/school-setup/academic-years')->assertSessionHasErrors('refusal');
        $this->from('/app/school-setup/academic-years')->put($page, $this->input($active, '2026-04-02', '2027-03-31'))
            ->assertRedirect('/app/school-setup/academic-years')
            ->assertSessionHasErrors(['refusal' => "Only a draft Academic Year's dates (and its Terms' dates) can be corrected. This Academic Year is active, so its calendar is fixed."]);
        $this->assertSame(['2026-04-01', '2027-03-31'], $this->datesOf($active));
    }

    #[Test]
    public function the_term_page_corrects_a_draft_years_term(): void
    {
        $term = $this->createAcademicTerm($this->draft, ['code' => 'T1', 'sequence' => 1, 'starts_on' => '2027-04-01', 'ends_on' => '2027-09-30']);
        $this->signIn();
        $page = "/app/school-setup/terms/{$term->id}/dates";
        $version = fn () => $this->inSchool(fn () => AcademicTerm::query()->findOrFail($term->id))->updated_at->toIso8601String();

        $this->get($page)->assertInertia(fn ($p) => $p->component('App/SchoolSetup/TermDates')->where('term.startsOn', '2027-04-01')->where('academicYear.status', 'draft'));
        $this->from($page)->put($page, ['starts_on' => '2027-03-01', 'ends_on' => '2027-09-30', 'expected_updated_at' => $version()])
            ->assertRedirect($page)->assertSessionHasErrors('starts_on');
        $this->from($page)->put($page, ['starts_on' => '2027-04-10', 'ends_on' => '2027-09-30', 'expected_updated_at' => $version()])
            ->assertRedirect("/app/school-setup/terms?academic_year_id={$this->draft->id}")->assertSessionHasNoErrors();
        $this->assertSame(['2027-04-10', '2027-09-30'], $this->datesOf($term));
    }

    #[Test]
    public function the_correction_pages_need_the_management_capability(): void
    {
        $term = $this->createAcademicTerm($this->draft, ['code' => 'T1', 'sequence' => 1, 'starts_on' => '2027-04-01', 'ends_on' => '2027-09-30']);
        $viewer = $this->createUserWithCapabilities($this->school, ['academics.years.view']);
        $this->signIn($viewer);

        $this->get("/app/school-setup/academic-years/{$this->draft->id}/dates")->assertForbidden();
        $this->put("/app/school-setup/academic-years/{$this->draft->id}/dates", $this->input($this->draft, '2027-04-02', '2028-03-31'))->assertForbidden();
        $this->get("/app/school-setup/terms/{$term->id}/dates")->assertForbidden();
        $this->put("/app/school-setup/terms/{$term->id}/dates", ['starts_on' => '2027-04-02', 'ends_on' => '2027-09-30', 'expected_updated_at' => 'x'])->assertForbidden();
        $this->assertSame(['2027-04-01', '2028-03-31'], $this->datesOf($this->draft));
    }

    #[Test]
    public function another_schools_year_or_term_is_not_found_on_the_web(): void
    {
        $other = $this->createSchool();
        $foreignYear = $this->createAcademicYear($other, ['status' => 'draft']);
        $foreignTerm = $this->createAcademicTerm($foreignYear, ['code' => 'F1', 'sequence' => 1, 'starts_on' => '2026-06-01', 'ends_on' => '2026-09-30']);
        $this->signIn();

        $this->get("/app/school-setup/academic-years/{$foreignYear->id}/dates")->assertNotFound();
        $this->put("/app/school-setup/academic-years/{$foreignYear->id}/dates", ['starts_on' => '2026-06-02', 'ends_on' => '2027-05-31', 'expected_updated_at' => 'x'])->assertNotFound();
        $this->get("/app/school-setup/terms/{$foreignTerm->id}/dates")->assertNotFound();
        $this->put("/app/school-setup/terms/{$foreignTerm->id}/dates", ['starts_on' => '2026-06-02', 'ends_on' => '2026-09-30', 'expected_updated_at' => 'x'])->assertNotFound();
    }
}
