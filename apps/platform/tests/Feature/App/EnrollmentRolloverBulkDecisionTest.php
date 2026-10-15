<?php

namespace Tests\Feature\App;

use App\Domain\Students\Application\EnrollmentRolloverBulkDecisionService;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\StaleRolloverDecisionsException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C2 (UAT-YR-01b): bulk Promote / Exclude on a rollover plan. Local UAT
 * needed about four actions per Student (Edit, decision, Roll Number rule,
 * Save) -- thousands for a real School. A bulk decision is judged by the
 * same rules as validation (EnrollmentRolloverDryRunService::simulate()),
 * writes only Students whose placement outcome is clean, keeps the current
 * Roll Number and never resolves a collision by guessing.
 */
class EnrollmentRolloverBulkDecisionTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const array MANAGE = ['enrollments.view', 'enrollments.manage', 'enrollments.rollovers.view', 'enrollments.rollovers.manage'];

    private function in(School $school, \Closure $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    /**
     * A School whose 2025-26 Grade 6 A and 6 B (rolls 1..$perSection each)
     * roll into 2026-27 Grade 7 A and 7 B; plan items populated by a first
     * validation run, every decision still undecided.
     *
     * @return array<string, mixed>
     */
    private function world(int $perSection = 3, bool $sectionMappings = true): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $source = $this->createAcademicYear($school, ['code' => 'SRC', 'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31']);
        $target = $this->createAcademicYear($school, ['code' => 'TGT', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $g6 = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $g7 = $this->createGradeLevel($school, ['name' => 'Grade 7', 'code' => 'G7', 'sequence' => 7]);
        $g8 = $this->createGradeLevel($school, ['name' => 'Grade 8', 'code' => 'G8', 'sequence' => 8]);
        $s6A = $this->createSection($source, $campus, $g6, ['name' => 'A', 'code' => '6A', 'capacity' => 200]);
        $s6B = $this->createSection($source, $campus, $g6, ['name' => 'B', 'code' => '6B', 'capacity' => 200]);
        $s8A = $this->createSection($source, $campus, $g8, ['name' => 'A', 'code' => '8A', 'capacity' => 200]);
        $t7A = $this->createSection($target, $campus, $g7, ['name' => 'A', 'code' => '7A', 'capacity' => 200]);
        $t7B = $this->createSection($target, $campus, $g7, ['name' => 'B', 'code' => '7B', 'capacity' => 200]);

        $enroll = app(StudentEnrollmentService::class);
        foreach ([$s6A, $s6B] as $section) {
            for ($r = 1; $r <= $perSection; $r++) {
                $enroll->enroll($this->createStudent($school, ['status' => 'active']), $section, (string) $r, '2025-04-01');
            }
        }
        $enroll->enroll($this->createStudent($school, ['status' => 'active', 'student_number' => 'G8-LEAVER']), $s8A, '1', '2025-04-01');

        $plans = app(EnrollmentRolloverPlanService::class);
        $plan = $plans->createDraft($school, $source, $target);
        $plans->upsertMapping($plan, $g6, null, $g7, $t7A);
        if ($sectionMappings) {
            $plans->upsertMapping($this->fresh($school, $plan), $g6, $s6B, $g7, $t7B);
        }
        app(EnrollmentRolloverDryRunService::class)->run($this->fresh($school, $plan));

        return compact('school', 'campus', 'source', 'target', 'g6', 'g7', 'g8', 's6A', 's6B', 's8A', 't7A', 't7B') + ['plan' => $this->fresh($school, $plan)];
    }

    private function fresh(School $school, EnrollmentRolloverPlan $plan): EnrollmentRolloverPlan
    {
        return $this->in($school, fn () => EnrollmentRolloverPlan::query()->findOrFail($plan->id));
    }

    /** @return array<string, string> item id by "6A-1" style key (section code + source roll) */
    private function itemsByKey(array $w): array
    {
        return $this->in($w['school'], fn () => EnrollmentRolloverItem::query()->where('plan_id', $w['plan']->id)->with('sourceEnrollment.section')->get()
            ->mapWithKeys(fn (EnrollmentRolloverItem $i) => [$i->sourceEnrollment->section->code.'-'.$i->sourceEnrollment->roll_number => $i->id])->all());
    }

    private function item(array $w, string $id): EnrollmentRolloverItem
    {
        return $this->in($w['school'], fn () => EnrollmentRolloverItem::query()->findOrFail($id));
    }

    private function manager(School $school): User
    {
        $user = $this->createUserWithCapabilities($school, self::MANAGE);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** @return array<string, string> item id => outcome */
    private function outcomes(array $rows): array
    {
        return array_column($rows, 'outcome', 'itemId');
    }

    // ------------------------------------------------------------------ Promote

    #[Test]
    public function promoting_one_student_writes_the_same_decision_and_audit_as_a_single_save(): void
    {
        $w = $this->world();
        $id = $this->itemsByKey($w)['6A-1'];
        $version = $w['plan']->configuration_version;

        $rows = app(EnrollmentRolloverBulkDecisionService::class)->apply($w['plan'], [$id], 'promote', $version);

        $this->assertSame([$id => 'applied'], $this->outcomes($rows));
        $this->assertSame('Grade 7 · Section A', $rows[0]['target']);
        $item = $this->item($w, $id);
        $this->assertSame('promote', $item->decision);
        $this->assertSame('preserve_source', $item->roll_number_strategy);
        $this->assertNull($item->target_section_id, 'The mapping supplies the target; no per-Student override is invented.');
        $this->assertSame($version + 1, $this->fresh($w['school'], $w['plan'])->configuration_version);
        $this->assertSame(1, $this->in($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'enrollment_rollover_plan.configuration_changed')->where('metadata->itemId', $id)->where('metadata->bulk', true)->count()));
    }

    #[Test]
    public function promoting_many_students_through_the_page_applies_all_clean_ones_and_validation_agrees(): void
    {
        $w = $this->world();
        $this->manager($w['school']);
        $ids = array_values(array_filter($this->itemsByKey($w), fn ($k) => str_starts_with($k, '6'), ARRAY_FILTER_USE_KEY));

        $this->post("/app/enrollment-rollovers/{$w['plan']->id}/bulk-decisions", [
            'decision' => 'promote', 'item_ids' => $ids, 'configuration_version' => $w['plan']->configuration_version,
        ])->assertSessionHasNoErrors()->assertSessionHas('flash', 'Promote saved for 6 of 6 selected Students. Run Validation when the plan is ready.');

        foreach ($ids as $id) {
            $this->assertSame('promote', $this->item($w, $id)->decision);
        }

        $summary = app(EnrollmentRolloverDryRunService::class)->run($this->fresh($w['school'], $w['plan']));
        $this->assertSame(6, $summary['ready'], 'Every bulk-promoted Student is ready at validation.');
    }

    #[Test]
    public function colliding_roll_numbers_leave_every_colliding_student_undecided_never_first_wins(): void
    {
        // Grade default only: 6 A and 6 B both go to 7 A, so rolls 1-2 collide; 6A-3 does not.
        $w = $this->world(perSection: 2, sectionMappings: false);
        $keys = $this->itemsByKey($w);
        $extra = $this->in($w['school'], fn () => app(StudentEnrollmentService::class)->enroll($this->createStudent($w['school'], ['status' => 'active']), $w['s6A'], '3', '2025-04-01'));
        app(EnrollmentRolloverDryRunService::class)->run($this->fresh($w['school'], $w['plan']));
        $keys = $this->itemsByKey($w);
        $ids = [$keys['6A-1'], $keys['6A-2'], $keys['6B-1'], $keys['6B-2'], $keys['6A-3']];

        $rows = app(EnrollmentRolloverBulkDecisionService::class)->apply($this->fresh($w['school'], $w['plan']), $ids, 'promote', $this->fresh($w['school'], $w['plan'])->configuration_version);

        $outcomes = $this->outcomes($rows);
        foreach (['6A-1', '6A-2', '6B-1', '6B-2'] as $k) {
            $this->assertSame('roll_number_conflict', $outcomes[$keys[$k]], $k);
            $this->assertSame('undecided', $this->item($w, $keys[$k])->decision, "{$k} must stay undecided");
        }
        $this->assertSame('applied', $outcomes[$keys['6A-3']]);
        $this->assertNotNull($extra);
    }

    #[Test]
    public function a_roll_number_already_held_in_the_target_section_is_not_taken(): void
    {
        $w = $this->world(perSection: 1);
        $this->in($w['school'], fn () => app(StudentEnrollmentService::class)->enroll($this->createStudent($w['school'], ['status' => 'active']), $w['t7A'], '1', '2026-04-01'));
        $id = $this->itemsByKey($w)['6A-1'];

        $rows = app(EnrollmentRolloverBulkDecisionService::class)->apply($w['plan'], [$id], 'promote', $w['plan']->configuration_version);

        $this->assertSame('roll_number_conflict', $rows[0]['outcome']);
        $this->assertSame('undecided', $this->item($w, $id)->decision);
    }

    #[Test]
    public function a_grade_with_no_mapping_or_no_target_section_is_reported_not_promoted(): void
    {
        $w = $this->world(perSection: 1, sectionMappings: false);
        $keys = $this->itemsByKey($w);
        // Grade 8 has no mapping (leavers); re-map Grade 6 with no default Section.
        app(EnrollmentRolloverPlanService::class)->upsertMapping($w['plan'], $w['g6'], null, $w['g7'], null);
        $plan = $this->fresh($w['school'], $w['plan']);

        $outcomes = $this->outcomes(app(EnrollmentRolloverBulkDecisionService::class)->apply($plan, [$keys['8A-1'], $keys['6A-1']], 'promote', $plan->configuration_version));

        $this->assertSame('no_mapping', $outcomes[$keys['8A-1']]);
        $this->assertSame('missing_target_section', $outcomes[$keys['6A-1']]);
        $this->assertSame('undecided', $this->item($w, $keys['8A-1'])->decision);
    }

    #[Test]
    public function a_student_already_promoted_with_a_roll_rule_is_left_unchanged(): void
    {
        $w = $this->world(perSection: 1);
        $id = $this->itemsByKey($w)['6A-1'];
        $service = app(EnrollmentRolloverBulkDecisionService::class);
        $service->apply($w['plan'], [$id], 'promote', $w['plan']->configuration_version);
        $plan = $this->fresh($w['school'], $w['plan']);

        $rows = $service->apply($plan, [$id], 'promote', $plan->configuration_version);

        $this->assertSame('already_decided', $rows[0]['outcome']);
        $this->assertSame($plan->configuration_version, $this->fresh($w['school'], $w['plan'])->configuration_version);
    }

    // ------------------------------------------------------------------ Exclude

    #[Test]
    public function excluding_one_and_many_students_including_a_leaver_and_an_already_excluded_one(): void
    {
        $w = $this->world(perSection: 2);
        $keys = $this->itemsByKey($w);
        $service = app(EnrollmentRolloverBulkDecisionService::class);

        $this->assertSame('applied', $service->apply($w['plan'], [$keys['8A-1']], 'exclude', $w['plan']->configuration_version)[0]['outcome'], 'A terminal-grade leaver can be excluded.');

        $plan = $this->fresh($w['school'], $w['plan']);
        $outcomes = $this->outcomes($service->apply($plan, [$keys['8A-1'], $keys['6A-1'], $keys['6B-2']], 'exclude', $plan->configuration_version));

        $this->assertSame(['already_decided', 'applied', 'applied'], [$outcomes[$keys['8A-1']], $outcomes[$keys['6A-1']], $outcomes[$keys['6B-2']]]);
        $this->assertSame('exclude', $this->item($w, $keys['6B-2'])->decision);
    }

    // ------------------------------------------------------- Stale / repeated

    #[Test]
    public function a_change_saved_after_the_page_loaded_makes_the_whole_request_stale_and_changes_nothing(): void
    {
        $w = $this->world(perSection: 2);
        $keys = $this->itemsByKey($w);
        $loadedVersion = $w['plan']->configuration_version;
        // Another administrator decides one of the selected Students meanwhile.
        app(EnrollmentRolloverPlanService::class)->setItemDecision($w['plan'], $this->item($w, $keys['6A-1']), 'manual_review');

        try {
            app(EnrollmentRolloverBulkDecisionService::class)->apply($this->fresh($w['school'], $w['plan']), [$keys['6A-1'], $keys['6A-2']], 'exclude', $loadedVersion);
            $this->fail('A stale bulk decision must be refused.');
        } catch (StaleRolloverDecisionsException) {
        }

        $this->assertSame('manual_review', $this->item($w, $keys['6A-1'])->decision, 'The newer decision is never overwritten.');
        $this->assertSame('undecided', $this->item($w, $keys['6A-2'])->decision);
    }

    #[Test]
    public function a_repeated_submission_is_refused_as_stale_through_the_page(): void
    {
        $w = $this->world(perSection: 1);
        $this->manager($w['school']);
        $payload = ['decision' => 'exclude', 'item_ids' => [$this->itemsByKey($w)['6A-1']], 'configuration_version' => $w['plan']->configuration_version];

        $this->post("/app/enrollment-rollovers/{$w['plan']->id}/bulk-decisions", $payload)->assertSessionHasNoErrors();
        $version = $this->fresh($w['school'], $w['plan'])->configuration_version;

        $this->post("/app/enrollment-rollovers/{$w['plan']->id}/bulk-decisions", $payload)
            ->assertSessionHasErrors(['bulk' => 'This rollover plan changed after you opened it, so nothing was changed. Reload the plan and choose again.']);
        $this->assertSame($version, $this->fresh($w['school'], $w['plan'])->configuration_version);
    }

    #[Test]
    public function the_preview_writes_nothing(): void
    {
        $w = $this->world();
        $this->manager($w['school']);
        $ids = array_values($this->itemsByKey($w));
        $audits = fn () => $this->in($w['school'], fn () => SchoolAuditEvent::query()->where('school_id', $w['school']->id)->count());
        $before = $audits();

        $response = $this->postJson("/app/enrollment-rollovers/{$w['plan']->id}/bulk-decisions/preview", ['decision' => 'promote', 'item_ids' => $ids])->assertOk();

        $this->assertCount(count($ids), $response->json('rows'));
        $this->assertSame($w['plan']->configuration_version, $this->fresh($w['school'], $w['plan'])->configuration_version);
        $this->assertSame($before, $audits());
        foreach ($ids as $id) {
            $this->assertSame('undecided', $this->item($w, $id)->decision);
        }
    }

    // ---------------------------------------------------------------- Security

    #[Test]
    public function students_from_another_plan_or_another_school_are_reported_and_never_written(): void
    {
        $w = $this->world(perSection: 1);
        $other = $this->world(perSection: 1);
        $own = $this->itemsByKey($w)['6A-1'];
        $foreign = $this->itemsByKey($other)['6A-1'];

        $outcomes = $this->outcomes(app(EnrollmentRolloverBulkDecisionService::class)->apply($w['plan'], [$own, $foreign], 'exclude', $w['plan']->configuration_version));

        $this->assertSame(['applied', 'not_in_plan'], [$outcomes[$own], $outcomes[$foreign]]);
        $this->assertSame('undecided', $this->item($other, $foreign)->decision);
    }

    #[Test]
    public function bulk_decisions_need_the_rollover_manage_capability_and_the_schools_own_plan(): void
    {
        $w = $this->world(perSection: 1);
        $other = $this->world(perSection: 1);
        $payload = ['decision' => 'exclude', 'item_ids' => [$this->itemsByKey($w)['6A-1']], 'configuration_version' => $w['plan']->configuration_version];

        $viewer = $this->createUserWithCapabilities($w['school'], ['enrollments.view', 'enrollments.rollovers.view']);
        $this->actingAs($viewer)->post("/app/schools/{$w['school']->id}/activate");
        $this->postJson("/app/enrollment-rollovers/{$w['plan']->id}/bulk-decisions/preview", $payload)->assertForbidden();
        $this->post("/app/enrollment-rollovers/{$w['plan']->id}/bulk-decisions", $payload)->assertForbidden();

        $this->manager($w['school']);
        $this->post("/app/enrollment-rollovers/{$other['plan']->id}/bulk-decisions", $payload)->assertNotFound();
        $this->assertSame('undecided', $this->item($w, $payload['item_ids'][0])->decision);
    }

    #[Test]
    public function the_request_is_validated_before_anything_runs(): void
    {
        $w = $this->world(perSection: 1);
        $this->manager($w['school']);
        $url = "/app/enrollment-rollovers/{$w['plan']->id}/bulk-decisions";
        $id = $this->itemsByKey($w)['6A-1'];

        $this->post($url, ['decision' => 'repeat', 'item_ids' => [$id], 'configuration_version' => 0])->assertSessionHasErrors('decision');
        $this->post($url, ['decision' => 'exclude', 'item_ids' => [], 'configuration_version' => 0])->assertSessionHasErrors('item_ids');
        $this->post($url, ['decision' => 'exclude', 'item_ids' => array_fill(0, 101, $id), 'configuration_version' => 0])->assertSessionHasErrors('item_ids');
        $this->post($url, ['decision' => 'exclude', 'item_ids' => ['not-a-uuid'], 'configuration_version' => 0])->assertSessionHasErrors('item_ids.0');
        $this->assertSame('undecided', $this->item($w, $id)->decision);
    }

    // ------------------------------------------------------------- Page state

    #[Test]
    public function saving_returns_to_the_same_page_size_page_and_filters(): void
    {
        $w = $this->world(perSection: 30);
        $this->manager($w['school']);
        $id = $this->itemsByKey($w)['6A-1'];
        $context = ['page' => 2, 'per_page' => 50, 'decision' => 'undecided', 'evil' => 'https://example.test'];

        $this->post("/app/enrollment-rollovers/{$w['plan']->id}/bulk-decisions", [
            'decision' => 'exclude', 'item_ids' => [$id], 'configuration_version' => $w['plan']->configuration_version, 'context' => $context,
        ])->assertRedirect("/app/enrollment-rollovers/{$w['plan']->id}?page=2&per_page=50&decision=undecided")
            ->assertSessionHas('rolloverBulkResult', fn (array $r) => $r['applied'] === 1 && $r['unresolved'] === []);

        $other = $this->itemsByKey($w)['6A-2'];
        $this->patch("/app/enrollment-rollovers/{$w['plan']->id}/items/{$other}", ['decision' => 'exclude', 'context' => $context])
            ->assertRedirect("/app/enrollment-rollovers/{$w['plan']->id}?page=2&per_page=50&decision=undecided")
            ->assertSessionHas('flash', 'Decision saved. Run Validation when the plan is ready.');
    }

    #[Test]
    public function rows_name_the_grade_and_the_page_carries_page_size_and_next_step_context(): void
    {
        $w = $this->world(perSection: 1);
        $this->manager($w['school']);

        $props = $this->get("/app/enrollment-rollovers/{$w['plan']->id}?per_page=50")->assertOk()->viewData('page')['props'];

        $sections = collect($props['items']['data'])->pluck('sourceEnrollment.section.gradeLevelName')->unique()->sort()->values()->all();
        $this->assertSame(['Grade 6', 'Grade 8'], $sections);
        $this->assertSame(50, $props['perPage']);
        $this->assertSame(['source' => 'draft', 'target' => 'draft', 'targetStartsOn' => '2026-04-01'], $props['yearStatus']);
        $this->assertSame(100, $props['bulkMax']);
    }

    // -------------------------------------------------------------- Scale

    #[Test]
    public function a_school_scale_cohort_is_decided_in_pages_of_one_hundred_with_bounded_queries(): void
    {
        // 2 x 75 = 150 Grade 6 Students, Section-mapped (6A -> 7A, 6B -> 7B).
        $w = $this->world(perSection: 75);
        $manager = $this->manager($w['school']);
        $service = app(EnrollmentRolloverBulkDecisionService::class);
        $all = array_values(array_filter($this->itemsByKey($w), fn ($k) => str_starts_with($k, '6'), ARRAY_FILTER_USE_KEY));
        $this->assertCount(150, $all);

        // Listing: the same number of queries for 20 rows as for 100.
        $count = function (int $perPage) use ($w): int {
            $this->get("/app/enrollment-rollovers/{$w['plan']->id}?per_page={$perPage}")->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get("/app/enrollment-rollovers/{$w['plan']->id}?per_page={$perPage}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->assertSame($count(20), $count(100), 'No query per listed Student.');

        // Two bulk actions decide everyone; the evaluation itself is batched.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $service->apply($this->fresh($w['school'], $w['plan']), array_slice($all, 0, 100), 'promote', $this->fresh($w['school'], $w['plan'])->configuration_version, $manager);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $second = $service->apply($this->fresh($w['school'], $w['plan']), array_slice($all, 100), 'promote', $this->fresh($w['school'], $w['plan'])->configuration_version, $manager);

        $this->assertSame(150, count(array_filter([...$first, ...$second], fn ($r) => $r['outcome'] === 'applied')));
        $this->assertLessThanOrEqual(100 * 4 + 40, $queries, 'A constant handful of writes per Student plus a fixed evaluation.');

        $summary = app(EnrollmentRolloverDryRunService::class)->run($this->fresh($w['school'], $w['plan']));
        $this->assertSame(150, $summary['ready']);
        $this->assertSame(150, $this->in($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'enrollment_rollover_plan.configuration_changed')->where('metadata->bulk', true)->count()), 'One audit event per Student.');
    }
}
