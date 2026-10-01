<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\ChargeAdministrationService;
use App\Domain\Fees\Application\Exceptions\ChargeIsFeeAssessedException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentAlreadyVoidedException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunIllegalStateException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunItemNotExcludableException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunOpenConflictException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunStalePreviewException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeAssessmentRunException;
use App\Domain\Fees\Application\FeeAssessmentItemExecutor;
use App\Domain\Fees\Application\FeeAssessmentService;
use App\Domain\Fees\Application\FeeHeadService;
use App\Domain\Fees\Application\FeeOptionalSelectionService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Jobs\ExecuteFeeAssessmentRunJob;
use App\Models\DomainEventOutbox;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * FEE.2 (ADR 0062 §9-§13, owner decision D1): the assessment run lifecycle,
 * D1 eligibility, structure resolution, optional fees, idempotency by the
 * fee_assessments key, execution-time fail-closed checks, recovery, exact
 * financial facts, and audit/outbox cardinality.
 */
class FeeAssessmentRunServiceTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    private function charges(array $w): Collection
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->orderBy('created_at')->get());
    }

    private function assessments(array $w): Collection
    {
        return $this->inSchool($w['school'], fn () => FeeAssessment::query()->get());
    }

    private function outbox(array $w, string $type): int
    {
        return DomainEventOutbox::query()->where('school_id', $w['school']->id)->where('event_type', $type)->count();
    }

    // --- creation and lifecycle ------------------------------------------------------

    #[Test]
    public function a_run_needs_an_active_structure_and_an_existing_period(): void
    {
        $w = $this->assessmentWorld();
        $draft = $this->makeDraftStructure($w, ['code' => 'DRAFT-ONLY']);

        foreach ([[$draft->id, 'T1', 'fee_structure_id'], [$w['structure']->id, 'T9', 'billing_period_key']] as [$structureId, $key, $field]) {
            try {
                $this->runs()->create($w['school'], $structureId, $key, $w['actor']);
                $this->fail("Expected {$field} rejection");
            } catch (InvalidFeeAssessmentRunException $e) {
                $this->assertSame($field, $e->field());
            }
        }

        $run = $this->runs()->create($w['school'], $w['structure']->id, 't1', $w['actor']);
        $this->assertSame('T1', $run->billing_period_key);
        $this->assertSame('draft', $run->status);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_assessment_run.created'));
    }

    #[Test]
    public function only_one_open_run_per_structure_and_period(): void
    {
        $w = $this->assessmentWorld();
        $first = $this->runs()->create($w['school'], $w['structure']->id, 'T1', $w['actor']);
        $this->runs()->create($w['school'], $w['structure']->id, 'T2', $w['actor']);

        try {
            $this->runs()->create($w['school'], $w['structure']->id, 'T1', $w['actor']);
            $this->fail('A second open T1 run must be refused.');
        } catch (FeeAssessmentRunOpenConflictException) {
            $this->addToAssertionCount(1);
        }

        $this->runs()->cancel($w['school'], $first->id, $w['actor']);
        $this->assertSame('draft', $this->runs()->create($w['school'], $w['structure']->id, 'T1', $w['actor'])->status);
    }

    #[Test]
    public function preview_creates_no_charge_and_totals_come_from_items(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $this->enroll($w);

        $run = $this->previewedRun($w);

        $this->assertSame('previewed', $run->status);
        $this->assertSame(2, $run->ready_count);
        $this->assertSame('10000.00', $run->ready_amount);
        $this->assertCount(0, $this->charges($w), 'Preview never creates a charge.');
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_assessment_run.previewed'));
    }

    #[Test]
    public function a_stale_preview_cannot_execute_and_an_exclusion_forces_a_fresh_preview(): void
    {
        $w = $this->assessmentWorld();
        $a = $this->enroll($w);
        $b = $this->enroll($w);
        $run = $this->runs()->create($w['school'], $w['structure']->id, 'T1', $w['actor']);

        try {
            $this->runs()->execute($w['school'], $run->id, $w['actor']);
            $this->fail('A never-previewed run cannot execute.');
        } catch (FeeAssessmentRunStalePreviewException) {
            $this->addToAssertionCount(1);
        }

        $run = $this->runs()->preview($w['school'], $run->id, $w['actor']);
        $item = $this->itemFor($w, $run, $a->student_id);
        $run = $this->runs()->excludeItem($w['school'], $run->id, $item->id, $w['actor']);

        $this->assertSame('draft', $run->status);
        $this->assertSame(2, $run->configuration_version);

        try {
            $this->runs()->execute($w['school'], $run->id, $w['actor']);
            $this->fail('After an exclusion the preview is stale.');
        } catch (FeeAssessmentRunStalePreviewException) {
            $this->addToAssertionCount(1);
        }

        $run = $this->runs()->preview($w['school'], $run->id, $w['actor']);
        $excluded = $this->itemFor($w, $run, $a->student_id);
        $this->assertSame(['excluded', 'staff_excluded'], [$excluded->preview_result, $excluded->reason], 'The exclusion survives a fresh preview.');
        $this->assertSame($w['actor']->id, $excluded->excluded_by_user_id);
        $this->assertNotNull($excluded->excluded_at);
        $this->assertSame(1, $run->ready_count);

        $done = $this->executedRunFrom($w, $run);
        $this->assertSame('completed', $done->status);
        $this->assertSame([$b->student_id], $this->charges($w)->pluck('student_id')->all());
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_assessment_run.item_excluded'));
    }

    private function executedRunFrom(array $w, FeeAssessmentRun $run): FeeAssessmentRun
    {
        $this->runs()->execute($w['school'], $run->id, $w['actor']);

        return $this->inSchool($w['school'], fn () => $run->refresh());
    }

    #[Test]
    public function an_exclusion_never_changes_the_structure_or_amount_and_only_ready_items_are_excludable(): void
    {
        $w = $this->assessmentWorld();
        $late = $this->enroll($w, '2026-11-15');
        $on = $this->enroll($w);
        $run = $this->previewedRun($w);
        $installment = $this->inSchool($w['school'], fn () => FeeStructureInstallment::query()->where('billing_period_key', 'T1')->sole());

        try {
            $this->runs()->excludeItem($w['school'], $run->id, $this->itemFor($w, $run, $late->student_id)->id, $w['actor']);
            $this->fail('An already-excluded item cannot be excluded.');
        } catch (FeeAssessmentRunItemNotExcludableException) {
            $this->addToAssertionCount(1);
        }

        $this->runs()->excludeItem($w['school'], $run->id, $this->itemFor($w, $run, $on->student_id)->id, $w['actor']);

        $this->assertSame('5000.00', $this->inSchool($w['school'], fn () => $installment->refresh()->amount));
        $this->assertSame('active', $this->inSchool($w['school'], fn () => $w['structure']->refresh()->status));
        $this->assertSame('5000.00', $this->itemFor($w, $run, $on->student_id)->amount);
    }

    #[Test]
    public function illegal_transitions_are_refused(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $run = $this->executedRun($w);

        foreach ([
            fn () => $this->runs()->preview($w['school'], $run->id, $w['actor']),
            fn () => $this->runs()->cancel($w['school'], $run->id, $w['actor']),
            fn () => $this->runs()->resume($w['school'], $run->id, $w['actor']),
            fn () => $this->runs()->execute($w['school'], $run->id, $w['actor']),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("Call {$i} on a completed run must be refused.");
            } catch (FeeAssessmentRunIllegalStateException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- D1 -------------------------------------------------------------------------

    #[Test]
    public function d1_skips_periods_that_ended_before_enrollment_and_bills_the_joining_and_later_periods_in_full(): void
    {
        $w = $this->assessmentWorld();
        $midT1 = $this->enroll($w, '2026-09-15');   // joins during T1
        $inT2 = $this->enroll($w, '2026-12-01');    // joins after T1 ended

        $t1 = $this->executedRun($w, 'T1');
        $this->assertSame(['excluded', 'period_before_enrollment'], [$this->itemFor($w, $t1, $inT2->student_id)->preview_result, $this->itemFor($w, $t1, $inT2->student_id)->reason]);
        $this->assertSame('ready', $this->itemFor($w, $t1, $midT1->student_id)->preview_result);

        $t2 = $this->executedRun($w, 'T2');
        $this->assertSame(2, $t2->succeeded_count);

        $byStudent = $this->charges($w)->groupBy('student_id');
        $this->assertSame(['5000.00', '7000.00'], $byStudent[$midT1->student_id]->pluck('amount')->all(), 'Joining mid-T1 pays T1 and T2 in full -- never prorated.');
        $this->assertSame(['7000.00'], $byStudent[$inT2->student_id]->pluck('amount')->all(), 'No backdated T1 charge; T2 in full.');
    }

    #[Test]
    public function no_proration_path_exists_anywhere(): void
    {
        foreach (['app/Domain/Fees/Application/FeeAssessmentRunService.php', 'app/Domain/Fees/Application/FeeAssessmentItemExecutor.php'] as $file) {
            // Code tokens only: the docblocks deliberately say "no proration".
            $code = implode('', array_map(
                fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                token_get_all((string) file_get_contents(base_path($file))),
            ));
            foreach (['prorat', 'multiplyByRate', 'diffInDays', 'bcdiv', 'bcmul'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $code, "{$file} must not prorate ({$forbidden}).");
            }
        }
    }

    #[Test]
    public function cancelled_enrollments_and_inactive_students_are_excluded_with_reasons(): void
    {
        $w = $this->assessmentWorld();
        $cancelled = $this->enroll($w, '2026-06-01', ['status' => 'cancelled', 'ends_on' => '2026-08-01']);
        $inactive = $this->enroll($w);
        $this->inSchool($w['school'], fn () => $inactive->student->forceFill(['status' => 'inactive'])->save());

        $run = $this->previewedRun($w);

        $this->assertSame('enrollment_cancelled', $this->itemFor($w, $run, $cancelled->student_id)->reason);
        $this->assertSame('student_inactive', $this->itemFor($w, $run, $inactive->student_id)->reason);
        $this->assertSame(0, $run->ready_count);
    }

    // --- resolution ------------------------------------------------------------------

    #[Test]
    public function a_campus_override_wins_and_the_default_run_skips_its_campus(): void
    {
        $w = $this->assessmentWorld();
        $north = $this->createCampus($w['school']);
        $northSection = $this->sectionIn($w, $north);
        $override = $this->activeTwoTermStructure($w, ['code' => 'G-NORTH', 'campus_id' => $north->id], $w['head']);
        $main = $this->enroll($w);
        $northStudent = $this->enroll($w, '2026-06-01', [], $northSection);

        $default = $this->previewedRun($w);
        $this->assertSame([$main->student_id], $this->items($w, $default)->pluck('student_id')->all());

        $northRun = $this->previewedRun($w, 'T1', $override);
        $this->assertSame([$northStudent->student_id], $this->items($w, $northRun)->pluck('student_id')->all());
    }

    #[Test]
    public function the_resolution_ignores_sections(): void
    {
        $w = $this->assessmentWorld();
        $otherSection = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'B', 'name' => 'B']);
        $this->enroll($w);
        $this->enroll($w, '2026-06-01', [], $otherSection);

        $this->assertSame(2, $this->previewedRun($w)->ready_count, 'Both Sections of the grade are billed by the one grade structure.');
    }

    #[Test]
    public function a_retired_structure_cannot_be_previewed_or_executed(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $run = $this->previewedRun($w);
        Queue::fake();
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        app(FeeStructureService::class)->retire($w['school'], $w['structure']->id, $w['actor']);

        $item = $this->items($w, $run)->first();
        $this->assertSame('failed', app(FeeAssessmentItemExecutor::class)->executeItem($w['school'], $run->id, $item->id));
        $this->assertSame('structure_not_active', $this->inSchool($w['school'], fn () => $item->refresh()->failure_reason));
        $this->assertCount(0, $this->charges($w));
    }

    // --- optional fees ------------------------------------------------------------------

    #[Test]
    public function an_optional_fee_is_billed_only_with_an_active_selection_and_withdrawal_affects_the_future_only(): void
    {
        $w = $this->assessmentWorld();
        $bus = $this->makeFeeHead($w, ['code' => 'BUS', 'name' => 'Bus']);
        $structure = $this->activeTwoTermStructure($w, ['code' => 'G-OPT', 'campus_id' => $w['campus']->id], $bus, optional: true, second: $w['head']);
        $chooser = $this->enroll($w);
        $other = $this->enroll($w);
        $busLine = $this->inSchool($w['school'], fn () => FeeStructureLine::query()->where('fee_structure_id', $structure->id)->where('fee_head_id', $bus->id)->sole());
        $selection = app(FeeOptionalSelectionService::class)->select($w['school'], $chooser->student_id, $busLine->id, $w['actor']);

        $t1 = $this->executedRun($w, 'T1', $structure);
        $busItems = $this->items($w, $t1)->where('fee_head_id', $bus->id)->keyBy('student_id');
        $this->assertSame('ready', $busItems[$chooser->student_id]->preview_result);
        $this->assertSame('optional_not_selected', $busItems[$other->student_id]->reason);
        $this->assertSame(3, $t1->succeeded_count, 'Two tuition charges and one bus charge.');

        app(FeeOptionalSelectionService::class)->withdraw($w['school'], $selection->id, $w['actor']);
        $t2 = $this->executedRun($w, 'T2', $structure);
        $this->assertSame('optional_not_selected', $this->items($w, $t2)->where('fee_head_id', $bus->id)->firstWhere('student_id', $chooser->student_id)->reason);
        $this->assertSame(1, $this->charges($w)->where('student_id', $chooser->student_id)->where('amount', '5000.00')->filter(fn ($c) => str_starts_with($c->description, 'Bus'))->count(), 'The T1 bus charge stays.');
    }

    // --- idempotency ------------------------------------------------------------------------

    #[Test]
    public function rerunning_the_period_and_a_successor_structure_never_bill_twice(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->enroll($w);
        $this->executedRun($w);

        $again = $this->previewedRun($w);
        $this->assertSame('already_assessed', $this->itemFor($w, $again, $student->student_id)->preview_result);
        $this->runs()->execute($w['school'], $again->id, $w['actor']);

        $successor = app(FeeStructureService::class)->createSuccessor($w['school'], $w['structure']->id, ['code' => 'G-DEF-V2'], $w['actor']);
        app(FeeStructureService::class)->activate($w['school'], $successor->id, $w['actor']);
        $fromSuccessor = $this->executedRun($w, 'T1', $successor);

        $this->assertSame(0, $fromSuccessor->succeeded_count);
        $this->assertCount(1, $this->charges($w), 'One T1 tuition charge, whatever structure or run.');
        $this->assertCount(1, $this->assessments($w));
    }

    #[Test]
    public function a_campus_override_activated_after_preview_makes_the_default_item_fail_closed(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->enroll($w);
        $first = $this->previewedRun($w);
        Queue::fake();
        $this->runs()->execute($w['school'], $first->id, $w['actor']);

        // A second structure (campus override) and run previewed BEFORE the
        // first executes: both previews say ready.
        $override = $this->activeTwoTermStructure($w, ['code' => 'G-OVR', 'campus_id' => $w['campus']->id], $w['head']);
        $second = $this->previewedRun($w, 'T1', $override);
        $this->assertSame('ready', $this->itemFor($w, $second, $student->student_id)->preview_result);
        $this->runs()->execute($w['school'], $second->id, $w['actor']);

        $executor = app(FeeAssessmentItemExecutor::class);
        $this->assertSame('succeeded', $executor->executeItem($w['school'], $second->id, $this->itemFor($w, $second, $student->student_id)->id));
        $this->assertSame('failed', $executor->executeItem($w['school'], $first->id, $this->itemFor($w, $first, $student->student_id)->id),
            'The default structure no longer resolves for this campus.');
        $this->assertCount(1, $this->charges($w));
    }

    #[Test]
    public function a_campus_transfer_in_one_period_is_billed_once_and_the_duplicate_rolls_back_its_charge_and_journal(): void
    {
        $w = $this->assessmentWorld();
        $north = $this->createCampus($w['school']);
        $override = $this->activeTwoTermStructure($w, ['code' => 'G-NORTH', 'campus_id' => $north->id], $w['head']);
        // Transfer effective 2026-08-01: the main-campus enrollment ends the
        // day before, the north-campus one starts that day. Both overlap T1.
        $student = $this->createStudent($w['school']);
        $this->enroll($w, '2026-06-01', ['status' => 'transferred', 'ends_on' => '2026-07-31'], null, $student);
        $this->enroll($w, '2026-08-01', [], $this->sectionIn($w, $north), $student);

        Queue::fake();
        $default = $this->previewedRun($w);
        $northRun = $this->previewedRun($w, 'T1', $override);
        $this->assertSame('ready', $this->itemFor($w, $default, $student->id)->preview_result);
        $this->assertSame('ready', $this->itemFor($w, $northRun, $student->id)->preview_result);
        $this->runs()->execute($w['school'], $default->id, $w['actor']);
        $this->runs()->execute($w['school'], $northRun->id, $w['actor']);

        $executor = app(FeeAssessmentItemExecutor::class);
        $this->assertSame('succeeded', $executor->executeItem($w['school'], $default->id, $this->itemFor($w, $default, $student->id)->id));
        $journals = $this->inSchool($w['school'], fn () => JournalEntry::query()->count());
        $this->assertSame('skipped_already_assessed', $executor->executeItem($w['school'], $northRun->id, $this->itemFor($w, $northRun, $student->id)->id));

        $this->assertSame($journals, $this->inSchool($w['school'], fn () => JournalEntry::query()->count()), 'The duplicate attempt\'s journal entry rolled back.');
        $this->assertCount(1, $this->charges($w));
        $this->assertSame(1, $this->outbox($w, 'charge.assessed.v1'), 'The rolled-back charge left no outbox event.');
    }

    #[Test]
    public function retrying_items_and_the_job_after_completion_creates_nothing(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $this->enroll($w);
        $run = $this->executedRun($w);
        $executor = app(FeeAssessmentItemExecutor::class);

        foreach ($this->items($w, $run) as $item) {
            $this->assertSame('not_executing', $executor->executeItem($w['school'], $run->id, $item->id));
        }
        (new ExecuteFeeAssessmentRunJob($run->id))->handle($executor, $this->runs());

        $this->assertCount(2, $this->charges($w));
        $this->assertSame(1, $this->outbox($w, 'fee_assessment_run.completed.v1'));
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_assessment_run.completed'));
    }

    // --- execution-time fail-closed and recovery -----------------------------------------------

    #[Test]
    public function a_head_or_account_deactivated_after_preview_fails_closed_and_the_rest_succeed(): void
    {
        $w = $this->assessmentWorld();
        $lab = $this->makeFeeHead($w, ['code' => 'LAB', 'name' => 'Lab']);
        $structure = $this->activeTwoTermStructure($w, ['code' => 'G-LAB', 'campus_id' => $w['campus']->id], $w['head'], second: $lab);
        $student = $this->enroll($w);
        $run = $this->previewedRun($w, 'T1', $structure);
        app(FeeHeadService::class)->deactivate($w['school'], $lab->id, $w['actor']);

        $done = $this->executedRunFrom($w, $run);

        $this->assertSame('completed_with_errors', $done->status);
        $this->assertSame([1, 1], [$done->succeeded_count, $done->failed_count]);
        $this->assertSame('head_inactive', $this->items($w, $done)->firstWhere('fee_head_id', $lab->id)->failure_reason);
        $this->assertCount(1, $this->charges($w));
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_assessment_run.completed_with_errors'));

        // An account deactivated after preview fails closed too.
        $second = $this->enroll($w);
        $run2 = $this->previewedRun($w, 'T2', $structure);
        $this->inSchool($w['school'], fn () => $w['revenue']->forceFill(['status' => 'inactive'])->save());
        $done2 = $this->executedRunFrom($w, $run2);
        $this->assertSame(0, $done2->succeeded_count);
        $this->assertContains('account_invalid', $this->items($w, $done2)->pluck('failure_reason')->all());
        $this->assertCount(1, $this->charges($w)->where('student_id', $student->student_id));
        $this->assertCount(0, $this->charges($w)->where('student_id', $second->student_id));
    }

    #[Test]
    public function an_interrupted_run_resumes_only_pending_items(): void
    {
        $w = $this->assessmentWorld();
        $a = $this->enroll($w);
        $b = $this->enroll($w);
        $run = $this->previewedRun($w);
        Queue::fake();
        $this->runs()->execute($w['school'], $run->id, $w['actor']);

        // A worker processed one item, then died.
        app(FeeAssessmentItemExecutor::class)->executeItem($w['school'], $run->id, $this->itemFor($w, $run, $a->student_id)->id);
        $this->assertSame('executing', $this->inSchool($w['school'], fn () => $run->refresh()->status));

        Queue::assertPushed(ExecuteFeeAssessmentRunJob::class, 1);
        $this->runs()->resume($w['school'], $run->id, $w['actor']);
        Queue::assertPushed(ExecuteFeeAssessmentRunJob::class, 2);

        // Run the resumed job inline.
        app(TenantContext::class)->withSchool($w['school'], fn () => (new ExecuteFeeAssessmentRunJob($run->id))->handle(app(FeeAssessmentItemExecutor::class), $this->runs()));

        $done = $this->inSchool($w['school'], fn () => $run->refresh());
        $this->assertSame('completed', $done->status);
        $this->assertSame(2, $done->succeeded_count);
        $this->assertCount(1, $this->charges($w)->where('student_id', $a->student_id));
        $this->assertCount(1, $this->charges($w)->where('student_id', $b->student_id));
    }

    #[Test]
    public function a_suspended_school_pauses_the_run_without_touching_items(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $run = $this->previewedRun($w);
        Queue::fake();
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        DB::table('schools')->where('id', $w['school']->id)->update(['status' => 'suspended']);

        app(TenantContext::class)->withSchool($w['school'], fn () => (new ExecuteFeeAssessmentRunJob($run->id))->handle(app(FeeAssessmentItemExecutor::class), $this->runs()));

        $this->assertSame('executing', $this->inSchool($w['school'], fn () => $run->refresh()->status));
        $this->assertSame(['pending'], $this->items($w, $run)->pluck('execution_status')->unique()->values()->all());
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_assessment_run.paused'));
        $this->assertCount(0, $this->charges($w));
    }

    // --- financial correctness -------------------------------------------------------------------

    #[Test]
    public function the_charge_copies_amount_due_date_and_accounts_exactly_with_one_journal_entry_each(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->enroll($w);
        $journalsBefore = $this->inSchool($w['school'], fn () => JournalEntry::query()->count());

        $this->executedRun($w, 'T2');

        $charge = $this->charges($w)->sole();
        $this->assertSame($student->student_id, $charge->student_id);
        $this->assertSame($w['year']->id, $charge->academic_year_id);
        $this->assertSame('7000.00', $charge->amount);
        $this->assertSame('INR', $charge->currency);
        $this->assertSame('2026-11-10', $charge->due_date->toDateString());
        $this->assertSame($w['receivable']->id, $charge->receivable_ledger_account_id);
        $this->assertSame($w['revenue']->id, $charge->revenue_ledger_account_id);
        $this->assertSame($journalsBefore + 1, $this->inSchool($w['school'], fn () => JournalEntry::query()->count()));

        $assessment = $this->assessments($w)->sole();
        $this->assertSame([$charge->id, 'T2', $w['head']->id], [$assessment->charge_id, $assessment->billing_period_key, $assessment->fee_head_id]);
    }

    #[Test]
    public function audit_and_outbox_cardinality_per_operation(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $this->enroll($w);
        $this->executedRun($w);

        $this->assertSame(2, $this->outbox($w, 'charge.assessed.v1'));
        $this->assertSame(1, $this->outbox($w, 'fee_assessment_run.completed.v1'));
        foreach (['created', 'previewed', 'execution_started', 'completed'] as $event) {
            $this->assertSame(1, $this->auditCount($w['school'], "fee_assessment_run.{$event}"), $event);
        }
        $this->assertSame(2, $this->auditCount($w['school'], 'charge.assessed'));

        $payload = DomainEventOutbox::query()->where('school_id', $w['school']->id)->where('event_type', 'fee_assessment_run.completed.v1')->sole()->payload;
        $this->assertSame('completed', $payload['status']);
        $this->assertArrayNotHasKey('studentId', $payload);
    }

    // --- void path ------------------------------------------------------------------------------------

    #[Test]
    public function a_fee_assessed_charge_is_cancelled_only_by_voiding_its_assessment_which_frees_the_key(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->enroll($w);
        $this->executedRun($w);
        $charge = $this->charges($w)->sole();
        $assessment = $this->assessments($w)->sole();

        try {
            app(ChargeAdministrationService::class)->cancel($w['school'], $charge->id, $w['actor']);
            $this->fail('A direct cancel of a fee-assessed charge must be refused.');
        } catch (ChargeIsFeeAssessedException) {
            $this->addToAssertionCount(1);
        }

        app(FeeAssessmentService::class)->void($w['school'], $assessment->id, $w['actor'], 'wrong grade');
        $this->assertNotNull($this->inSchool($w['school'], fn () => $charge->refresh()->cancelled_at));
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_assessment.voided'));

        try {
            app(FeeAssessmentService::class)->void($w['school'], $assessment->id, $w['actor']);
            $this->fail('A voided assessment cannot be voided again.');
        } catch (FeeAssessmentAlreadyVoidedException) {
            $this->addToAssertionCount(1);
        }

        $rerun = $this->executedRun($w);
        $this->assertSame(1, $rerun->succeeded_count, 'The freed key allows one deliberate re-assessment.');
        $this->assertCount(1, $this->charges($w)->whereNull('cancelled_at')->where('student_id', $student->student_id));
    }

    // --- authorization --------------------------------------------------------------------------------

    #[Test]
    public function every_run_mutation_needs_the_run_capability_and_void_needs_charges_manage(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $run = $this->previewedRun($w);
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.fee_structures.manage']);
        $item = $this->items($w, $run)->first();

        foreach ([
            fn () => $this->runs()->create($w['school'], $w['structure']->id, 'T2', $viewer),
            fn () => $this->runs()->preview($w['school'], $run->id, $viewer),
            fn () => $this->runs()->excludeItem($w['school'], $run->id, $item->id, $viewer),
            fn () => $this->runs()->execute($w['school'], $run->id, $viewer),
            fn () => $this->runs()->resume($w['school'], $run->id, $viewer),
            fn () => $this->runs()->cancel($w['school'], $run->id, $viewer),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("Mutation {$i} must be denied.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        $assessment = $this->assessments($w)->sole();
        $runner = $this->createUserWithCapabilities($w['school'], ['finance.fee_assessments.run']);

        $this->expectException(AuthorizationException::class);
        app(FeeAssessmentService::class)->void($w['school'], $assessment->id, $runner);
    }

    #[Test]
    public function the_audit_metadata_carries_no_amounts_or_names(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $this->executedRun($w);

        $events = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'like', 'fee_assessment%')->get());
        foreach ($events as $event) {
            // UUIDv7 identifiers may contain the digits by chance: strip them first.
            $json = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<id>', (string) json_encode($event->metadata));
            $this->assertStringNotContainsString('5000', (string) $json, $event->event_type);
            $this->assertStringNotContainsString('Tuition', (string) $json, $event->event_type);
        }
    }
}
