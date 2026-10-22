<?php

namespace Tests\Feature\App;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\DataHealth\DataHealthService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * V11.3 (ADR 0075 section 11): resolving placements left open in a CLOSED
 * academic year from the browser, through the existing Complete/Withdraw
 * transitions (single placement) and the preview-then-complete page
 * (bulk). No new lifecycle semantics; Data Health recomputes clean.
 */
class HistoricalPlacementClosureUiTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private const array MANAGER = ['students.view', 'enrollments.view', 'enrollments.manage'];

    private function member(School $school, array $capabilities): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    private function in(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    private function fresh(StudentEnrollment $e): StudentEnrollment
    {
        return $this->in($e->school, fn () => StudentEnrollment::query()->findOrFail($e->id));
    }

    /**
     * A closed 2025-26 year and an active 2026-27 year, each with a Section.
     *
     * @return array<string, mixed>
     */
    private function world(?School $school = null): array
    {
        $school ??= $this->createSchool();
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $closed = $this->createAcademicYear($school, ['code' => 'C'.Str::random(5), 'name' => '2025-26', 'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31', 'status' => 'closed']);
        $active = $this->createAcademicYear($school, ['code' => 'A'.Str::random(5), 'name' => '2026-27', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'active']);

        return [
            'school' => $school,
            'closed' => $closed,
            'active' => $active,
            'closedSection' => $this->createSection($closed, $campus, $grade, ['code' => 'C'.Str::random(4), 'name' => '5 A']),
            'activeSection' => $this->createSection($active, $campus, $grade, ['code' => 'A'.Str::random(4), 'name' => '6 A']),
        ];
    }

    /** The rollover leftover: last year still open, this year placed. @return array{0: StudentEnrollment, 1: StudentEnrollment} */
    private function leftover(array $w, string $roll = '1'): array
    {
        $student = $this->createStudent($w['school']);
        $stale = $this->createStudentEnrollment($student, $w['closedSection'], ['status' => 'active', 'starts_on' => '2025-04-01', 'roll_number' => $roll]);
        $current = $this->createStudentEnrollment($student, $w['activeSection'], ['status' => 'active', 'starts_on' => '2026-04-01', 'roll_number' => $roll]);

        return [$stale, $current];
    }

    /** @return list<string> */
    private function dataHealthFindings(School $school): array
    {
        $viewer = $this->createUserWithCapabilities($school, ['enrollments.view']);

        return array_column(app(DataHealthService::class)->findings($school, 'enrollment.active_in_closed_year', $viewer, 1)['findings']->items(), 'entityId');
    }

    /** Inside the School's context: RLS hides the ledger without one (fails closed). */
    private function auditCount(string $type, StudentEnrollment $enrollment): int
    {
        return $this->in($enrollment->school, fn () => SchoolAuditEvent::query()->where('event_type', $type)->where('subject_id', $enrollment->id)->count());
    }

    // ------------------------------------------------------------------
    // Single placement (Student page, existing endpoints)
    // ------------------------------------------------------------------

    #[Test]
    public function the_student_page_marks_the_closed_year_placement_and_completing_it_clears_data_health(): void
    {
        $w = $this->world();
        [$stale, $current] = $this->leftover($w);
        $this->member($w['school'], self::MANAGER);

        $history = $this->get("/app/students/{$stale->student_id}")->assertOk()->viewData('page')['props']['enrollmentHistory'];
        $row = collect($history)->firstWhere('id', $stale->id);
        $this->assertSame(['status' => 'closed', 'endsOn' => '2026-03-31'], array_intersect_key($row['academicYear'], ['status' => 1, 'endsOn' => 1]));
        $this->assertSame([$stale->id], $this->dataHealthFindings($w['school']));

        $this->post("/app/enrollments/{$stale->id}/complete", ['ends_on' => '2026-03-31'])
            ->assertRedirect("/app/students/{$stale->student_id}");

        $after = $this->fresh($stale);
        $this->assertSame('completed', $after->status);
        $this->assertSame('2026-03-31', $after->ends_on->toDateString());
        $this->assertSame($stale->section_id, $after->section_id, 'History keeps its original Section.');
        $this->assertSame($stale->academic_year_id, $after->academic_year_id);
        $this->assertSame('active', $this->fresh($current)->status, 'The current placement is untouched.');
        $this->assertSame(1, $this->auditCount('student_enrollment.completed', $stale));
        $this->assertSame([], $this->dataHealthFindings($w['school']));
    }

    #[Test]
    public function withdrawing_a_closed_year_placement_uses_the_existing_withdraw_transition(): void
    {
        $w = $this->world();
        [$stale] = $this->leftover($w);
        $manager = $this->member($w['school'], self::MANAGER);

        // ORG.8E (ADR 0076 §63): a closed-year withdrawal carries the same reason, confirmation and fresh code.
        $this->post("/app/enrollments/{$stale->id}/withdraw", ['ends_on' => '2025-12-15'])->assertSessionHasErrors(['reason_code', 'confirm']);
        $this->assertSame('active', $this->fresh($stale)->status);
        $this->post("/app/enrollments/{$stale->id}/withdraw", ['ends_on' => '2025-12-15', 'reason_code' => 'left_school', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($manager)])->assertRedirect();

        $this->assertSame('withdrawn', $this->fresh($stale)->status);
        $this->assertSame(1, $this->auditCount('student_enrollment.withdrawn', $stale));
    }

    #[Test]
    public function a_repeated_or_stale_request_is_a_clean_validation_error_and_changes_nothing(): void
    {
        $w = $this->world();
        [$stale] = $this->leftover($w);
        $manager = $this->member($w['school'], self::MANAGER);

        $this->post("/app/enrollments/{$stale->id}/complete", ['ends_on' => '2026-03-31'])->assertRedirect();
        // Second click / another tab: already completed.
        $this->post("/app/enrollments/{$stale->id}/complete", ['ends_on' => '2026-03-31'])->assertSessionHasErrors('ends_on');
        $this->post("/app/enrollments/{$stale->id}/withdraw", ['ends_on' => '2026-03-31', 'reason_code' => 'left_school', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($manager)])->assertSessionHasErrors('ends_on');

        $this->assertSame('completed', $this->fresh($stale)->status);
        $this->assertSame(1, $this->auditCount('student_enrollment.completed', $stale));
        $this->assertSame(0, $this->auditCount('student_enrollment.withdrawn', $stale));
    }

    #[Test]
    public function an_end_date_before_the_placement_started_is_refused(): void
    {
        $w = $this->world();
        [$stale] = $this->leftover($w);
        $this->member($w['school'], self::MANAGER);

        $this->post("/app/enrollments/{$stale->id}/complete", ['ends_on' => '2025-01-01'])->assertSessionHasErrors('ends_on');
        $this->assertSame('active', $this->fresh($stale)->status);
    }

    #[Test]
    public function viewers_without_enrollments_manage_and_other_schools_cannot_end_it(): void
    {
        $w = $this->world();
        [$stale] = $this->leftover($w);

        $this->member($w['school'], ['students.view', 'enrollments.view']);
        $this->post("/app/enrollments/{$stale->id}/complete", ['ends_on' => '2026-03-31'])->assertForbidden();

        $other = $this->world();
        $this->member($other['school'], self::MANAGER);
        $this->post("/app/enrollments/{$stale->id}/complete", ['ends_on' => '2026-03-31'])->assertNotFound();

        $this->assertSame('active', $this->fresh($stale)->status);
    }

    #[Test]
    public function data_health_offers_repair_links_only_to_enrollment_managers(): void
    {
        $w = $this->world();
        [$stale] = $this->leftover($w);

        $this->member($w['school'], ['students.view', 'enrollments.view']);
        $links = $this->get('/app/data-health/enrollment.active_in_closed_year')->assertOk()->viewData('page')['props']['findings']['data'][0]['links'];
        $this->assertSame([], array_values(array_filter($links, fn ($l) => $l['isRepair'])));

        $this->member($w['school'], self::MANAGER);
        $links = collect($this->get('/app/data-health/enrollment.active_in_closed_year')->viewData('page')['props']['findings']['data'][0]['links']);
        $this->assertSame("/app/students/{$stale->student_id}#enrollment-history", $links->firstWhere('label', 'Complete or withdraw')['url']);
        $this->assertSame(
            '/app/enrollments/closed-year-placements?academic_year_id='.$w['closed']->id,
            $links->firstWhere('label', "Complete this year's open placements")['url'],
        );
    }

    // ------------------------------------------------------------------
    // Bulk: preview, then complete
    // ------------------------------------------------------------------

    #[Test]
    public function the_preview_lists_only_this_schools_open_placements_of_the_chosen_closed_year(): void
    {
        $w = $this->world();
        [$a, $currentA] = $this->leftover($w, '1');
        [$b] = $this->leftover($w, '2');
        $done = $this->createStudentEnrollment($this->createStudent($w['school']), $w['closedSection'], ['status' => 'completed', 'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31', 'roll_number' => '3']);
        $foreign = $this->world();
        [$theirs] = $this->leftover($foreign);
        $this->member($w['school'], self::MANAGER);

        $props = $this->get('/app/enrollments/closed-year-placements')->assertOk()->viewData('page')['props'];
        $this->assertSame([$w['closed']->id], array_column($props['years'], 'id'));
        $ids = array_column($props['preview']['placements'], 'id');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
        foreach ([$currentA->id, $done->id, $theirs->id] as $never) {
            $this->assertNotContains($never, $ids);
        }
        $this->assertSame('2026-03-31', $props['preview']['endsOn']);
        $this->assertSame(2, $props['preview']['total']);
    }

    #[Test]
    public function completing_the_batch_completes_each_row_with_its_own_audit_and_reports_truthfully(): void
    {
        $w = $this->world();
        [$a, $currentA] = $this->leftover($w, '1');
        [$b] = $this->leftover($w, '2');
        // Starts after its own year ended: the existing date rule refuses it.
        $odd = $this->createStudentEnrollment($this->createStudent($w['school']), $w['closedSection'], ['status' => 'active', 'starts_on' => '2026-05-01', 'roll_number' => '9']);
        $this->member($w['school'], self::MANAGER);

        $this->post('/app/enrollments/closed-year-placements/complete', [
            'academic_year_id' => $w['closed']->id,
            'enrollment_ids' => [$a->id, $b->id, $odd->id],
            'confirm' => true,
        ])->assertRedirect('/app/enrollments/closed-year-placements?academic_year_id='.$w['closed']->id);

        foreach ([$a, $b] as $e) {
            $this->assertSame('completed', $this->fresh($e)->status);
            $this->assertSame('2026-03-31', $this->fresh($e)->ends_on->toDateString());
            $this->assertSame(1, $this->auditCount('student_enrollment.completed', $e));
        }
        $this->assertSame('active', $this->fresh($odd)->status);
        $this->assertSame('active', $this->fresh($currentA)->status);

        $results = $this->get('/app/enrollments/closed-year-placements?academic_year_id='.$w['closed']->id)->viewData('page')['props']['results']['rows'];
        $byId = collect($results)->keyBy('id');
        $this->assertSame('completed', $byId[$a->id]['outcome']);
        $this->assertSame('date_conflict', $byId[$odd->id]['outcome']);
        $this->assertSame([$odd->id], $this->dataHealthFindings($w['school']), 'Only the refused row remains reported.');
    }

    #[Test]
    public function a_retry_and_tampered_ids_change_nothing_and_are_reported_not_eligible(): void
    {
        $w = $this->world();
        [$a, $current] = $this->leftover($w);
        [$theirs] = $this->leftover($this->world());
        $this->member($w['school'], self::MANAGER);
        $payload = ['academic_year_id' => $w['closed']->id, 'confirm' => true];

        $this->post('/app/enrollments/closed-year-placements/complete', $payload + ['enrollment_ids' => [$a->id]])->assertRedirect();
        $this->post('/app/enrollments/closed-year-placements/complete', $payload + ['enrollment_ids' => [$a->id, $current->id, $theirs->id]])->assertRedirect();

        $rows = collect($this->get('/app/enrollments/closed-year-placements')->viewData('page')['props']['results']['rows'])->keyBy('id');
        $this->assertSame('not_eligible', $rows[$a->id]['outcome']);
        $this->assertSame('not_eligible', $rows[$current->id]['outcome']);
        $this->assertSame('not_eligible', $rows[$theirs->id]['outcome']);
        $this->assertSame(1, $this->auditCount('student_enrollment.completed', $a), 'Never completed twice.');
        $this->assertSame('active', $this->fresh($current)->status);
        $this->assertSame('active', $this->fresh($theirs)->status);
    }

    #[Test]
    public function a_row_ended_elsewhere_after_the_preview_is_reported_and_others_still_complete(): void
    {
        $w = $this->world();
        [$a] = $this->leftover($w, '1');
        [$b] = $this->leftover($w, '2');
        $manager = $this->member($w['school'], self::MANAGER);
        // Someone withdraws $a on the Student page between preview and confirm.
        $this->withdrawEnrollment($a, '2025-12-01');

        $this->post('/app/enrollments/closed-year-placements/complete', [
            'academic_year_id' => $w['closed']->id, 'enrollment_ids' => [$a->id, $b->id], 'confirm' => true,
        ])->assertRedirect();

        $this->assertSame('withdrawn', $this->fresh($a)->status, 'Not overwritten.');
        $this->assertSame('completed', $this->fresh($b)->status);
    }

    #[Test]
    public function the_bulk_page_is_guarded_and_validates_its_input(): void
    {
        $w = $this->world();
        [$a] = $this->leftover($w);

        $this->member($w['school'], ['students.view', 'enrollments.view']);
        $this->get('/app/enrollments/closed-year-placements')->assertForbidden();
        $this->post('/app/enrollments/closed-year-placements/complete', ['academic_year_id' => $w['closed']->id, 'enrollment_ids' => [$a->id], 'confirm' => true])->assertForbidden();

        $this->member($w['school'], self::MANAGER);
        $base = ['academic_year_id' => $w['closed']->id, 'enrollment_ids' => [$a->id]];
        $this->post('/app/enrollments/closed-year-placements/complete', $base)->assertSessionHasErrors('confirm');
        $this->post('/app/enrollments/closed-year-placements/complete', ['academic_year_id' => $w['active']->id, 'enrollment_ids' => [$a->id], 'confirm' => true])->assertSessionHasErrors('academic_year_id');
        $this->post('/app/enrollments/closed-year-placements/complete', ['academic_year_id' => $w['closed']->id, 'enrollment_ids' => array_map(fn () => (string) Str::uuid(), range(1, 101)), 'confirm' => true])->assertSessionHasErrors('enrollment_ids');
        $this->assertSame('active', $this->fresh($a)->status);
    }
}
