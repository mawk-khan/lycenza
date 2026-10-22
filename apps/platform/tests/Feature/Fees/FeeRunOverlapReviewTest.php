<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeAdministrationService;
use App\Domain\Fees\Application\ChargeCampusAttribution;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunItemNotDecidableException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunOverlapChangedException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunUnresolvedOverlapException;
use App\Domain\Fees\Application\FeeAssessmentItemExecutor;
use App\Domain\Fees\Application\FeeRunOverlapDetector;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Jobs\ExecuteFeeAssessmentRunJob;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * UAT-C6 (UAT-FIN-20, ADR 0075 section 19; owner decision): a fee run whose
 * Student already has a charge that may cover the same period neither
 * includes nor excludes them silently. The item is "decision required"
 * (`review` / `potential_overlap`) until someone with the run capability
 * chooses Include anyway or Exclude from this run; the run never executes
 * with an undecided item; a charge appearing after the preview refuses the
 * execution (and, per item, the executor fails it closed). Existing run
 * idempotency (`already_assessed`, the one-live-per-period key) is
 * unchanged and run charges are never "overlaps" of each other.
 *
 * World: Term 1 = 2026-06-01..2026-10-31, 5000.00 due 2026-06-10, fee head
 * "Tuition" on the INC-TUITION income account.
 */
class FeeRunOverlapReviewTest extends TestCase
{
    use CreatesFeeAssessmentFixtures, CreatesTenancyFixtures;

    private function adHoc(array $w, string $studentId, string $description, string $amount, ?string $due, ?string $revenueId = null, ?string $yearId = null): Charge
    {
        $result = app(ChargeAdministrationService::class)->assess($w['school'], new AssessChargeData(
            studentId: $studentId,
            academicYearId: $yearId ?? $w['year']->id,
            description: $description,
            amount: Money::of($amount, 'INR'),
            receivableLedgerAccountId: $w['receivable']->id,
            revenueLedgerAccountId: $revenueId ?? $w['revenue']->id,
            campus: ChargeCampusAttribution::placementOnAssessmentDate(),
            dueDate: $due,
        ), $w['actor']);

        return $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($result->chargeId));
    }

    /** An enrolled Student (only the id is needed). */
    private function student(array $w): object
    {
        return (object) ['id' => $this->enroll($w)->student_id];
    }

    private function chargesOf(array $w, string $studentId): array
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->where('student_id', $studentId)->whereNull('cancelled_at')->orderBy('created_at')->orderBy('id')->get()->map(fn (Charge $c) => $c->description.'='.$c->amount)->all());
    }

    private function draftRun(array $w, string $key = 'T1'): FeeAssessmentRun
    {
        return $this->runs()->create($w['school'], $w['structure']->id, $key, $w['actor']);
    }

    private function preview(array $w, FeeAssessmentRun $run): FeeAssessmentRun
    {
        return $this->runs()->preview($w['school'], $run->id, $w['actor']);
    }

    #[Test]
    public function without_an_existing_charge_the_preview_is_unchanged_and_executes(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->student($w);

        $run = $this->executedRun($w);

        $item = $this->itemFor($w, $run, $student->id);
        $this->assertSame([FeeAssessmentRunItem::RESULT_READY, null, null], [$item->preview_result, $item->overlap_charge_ids, $item->overlap_decision]);
        $this->assertSame(FeeAssessmentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(['Tuition · Term 1=5000.00'], $this->chargesOf($w, $student->id));
    }

    #[Test]
    public function the_uat_case_a_same_period_ad_hoc_charge_needs_a_decision_and_blocks_execution(): void
    {
        $w = $this->assessmentWorld();
        $billed = $this->student($w);
        $clean = $this->student($w);
        $existing = $this->adHoc($w, $billed->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');

        $run = $this->preview($w, $this->draftRun($w));

        $item = $this->itemFor($w, $run, $billed->id);
        $this->assertSame([FeeAssessmentRunItem::RESULT_REVIEW, FeeAssessmentRunItem::REASON_POTENTIAL_OVERLAP, [$existing->id], null],
            [$item->preview_result, $item->reason, $item->overlap_charge_ids, $item->overlap_decision]);
        $this->assertSame(FeeAssessmentRunItem::RESULT_READY, $this->itemFor($w, $run, $clean->id)->preview_result);
        $this->assertSame(1, $run->ready_count, 'only the Student without an existing charge is ready');

        try {
            $this->runs()->execute($w['school'], $run->id, $w['actor']);
            $this->fail('A run with an undecided Student must not execute.');
        } catch (FeeAssessmentRunUnresolvedOverlapException $e) {
            $this->assertStringContainsString('1 Student already has a charge that may cover this period', $e->getMessage());
        }

        $this->assertSame(FeeAssessmentRun::STATUS_PREVIEWED, $this->inSchool($w['school'], fn () => $run->refresh())->status);
        $this->assertSame(['Term 1 tuition fee 2026-27=25000.00'], $this->chargesOf($w, $billed->id), 'nothing was charged');
        $this->assertSame([], $this->chargesOf($w, $clean->id), 'nothing was charged, not even the clean Student');
    }

    #[Test]
    public function include_anyway_is_recorded_carried_over_a_re_preview_and_charges_exactly_once(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->student($w);
        $existing = $this->adHoc($w, $student->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');
        $run = $this->preview($w, $this->draftRun($w));
        $item = $this->itemFor($w, $run, $student->id);

        $after = $this->runs()->decideOverlap($w['school'], $run->id, $item->id, 'include', $w['actor']);
        $this->assertSame(FeeAssessmentRun::STATUS_DRAFT, $after->status, 'a decision needs a fresh preview');

        $run = $this->preview($w, $run);
        $item = $this->itemFor($w, $run, $student->id);
        $this->assertSame([FeeAssessmentRunItem::RESULT_READY, 'include', $w['actor']->id, [$existing->id]],
            [$item->preview_result, $item->overlap_decision, $item->overlap_decided_by_user_id, $item->overlap_charge_ids]);

        $this->runs()->execute($w['school'], $run->id, $w['actor']);

        $this->assertSame(['Term 1 tuition fee 2026-27=25000.00', 'Tuition · Term 1=5000.00'], $this->chargesOf($w, $student->id));
        $this->assertSame(1, $this->auditCount($w, 'fee_assessment_run.item_overlap_included'));
    }

    #[Test]
    public function exclude_from_this_run_is_recorded_and_never_charges_the_student(): void
    {
        $w = $this->assessmentWorld();
        $excluded = $this->student($w);
        $other = $this->student($w);
        $this->adHoc($w, $excluded->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');
        $run = $this->preview($w, $this->draftRun($w));

        $this->runs()->decideOverlap($w['school'], $run->id, $this->itemFor($w, $run, $excluded->id)->id, 'exclude', $w['actor']);
        $run = $this->preview($w, $run);
        $item = $this->itemFor($w, $run, $excluded->id);
        $this->assertSame([FeeAssessmentRunItem::RESULT_EXCLUDED, FeeAssessmentRunItem::REASON_OVERLAP_EXCLUDED, 'exclude'], [$item->preview_result, $item->reason, $item->overlap_decision]);

        $this->runs()->execute($w['school'], $run->id, $w['actor']);

        $this->assertSame(['Term 1 tuition fee 2026-27=25000.00'], $this->chargesOf($w, $excluded->id));
        $this->assertSame(['Tuition · Term 1=5000.00'], $this->chargesOf($w, $other->id));
        $this->assertSame(1, $this->auditCount($w, 'fee_assessment_run.item_overlap_excluded'));
    }

    #[Test]
    public function an_include_anyway_student_can_still_be_excluded_before_execution(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->student($w);
        $this->adHoc($w, $student->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');
        $run = $this->preview($w, $this->draftRun($w));
        $this->runs()->decideOverlap($w['school'], $run->id, $this->itemFor($w, $run, $student->id)->id, 'include', $w['actor']);
        $run = $this->preview($w, $run);

        $this->runs()->excludeItem($w['school'], $run->id, $this->itemFor($w, $run, $student->id)->id, $w['actor']);
        $run = $this->preview($w, $run);

        $item = $this->itemFor($w, $run, $student->id);
        $this->assertSame([FeeAssessmentRunItem::RESULT_EXCLUDED, FeeAssessmentRunItem::REASON_OVERLAP_EXCLUDED, 'exclude'], [$item->preview_result, $item->reason, $item->overlap_decision]);
    }

    #[Test]
    public function only_charges_that_may_cover_the_period_are_flagged(): void
    {
        $w = $this->assessmentWorld();
        $otherIncome = $this->createLedgerAccount($w['school'], ['code' => 'INC-LAB', 'type' => 'income']);
        $nextYear = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31', 'status' => 'draft']);
        $student = $this->student($w);

        $cancelled = $this->adHoc($w, $student->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');
        app(ChargeAdministrationService::class)->cancel($w['school'], $cancelled->id, $w['actor'], 'other', 'wrong', $this->freshProof($w['actor']));
        $this->adHoc($w, $student->id, 'Lab breakage', '300.00', '2026-07-01', $otherIncome->id);           // another purpose, another account
        $this->adHoc($w, $student->id, 'Tuition arrears 2025-26', '4000.00', '2026-12-01');                  // tuition account, due in Term 2
        $this->adHoc($w, $student->id, 'Term 1 tuition fee 2027-28', '25000.00', '2027-07-30', null, $nextYear->id); // another year
        $byText = $this->adHoc($w, $student->id, 'tuition - TERM 1', '100.00', null, $otherIncome->id);       // resembles "Tuition · Term 1"
        $byAmount = $this->adHoc($w, $student->id, 'Books', '5000.00', '2026-06-10', $otherIncome->id);       // same amount + due date

        $item = $this->itemFor($w, $this->preview($w, $this->draftRun($w)), $student->id);

        $this->assertSame(FeeAssessmentRunItem::RESULT_REVIEW, $item->preview_result);
        $this->assertEqualsCanonicalizing([$byText->id, $byAmount->id], $item->overlap_charge_ids);
    }

    #[Test]
    public function run_charges_are_never_overlaps_and_existing_idempotency_is_unchanged(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->student($w);

        $this->executedRun($w, 'T2');               // a run charge of another period
        $first = $this->executedRun($w, 'T1');      // and of this one
        $this->assertSame(FeeAssessmentRunItem::RESULT_READY, $this->itemFor($w, $first, $student->id)->preview_result);

        $again = $this->previewedRun($w, 'T1');
        $this->assertSame(FeeAssessmentRunItem::RESULT_ALREADY_ASSESSED, $this->itemFor($w, $again, $student->id)->preview_result);
        $this->assertSame([0, 1], [$again->ready_count, $again->already_assessed_count]);
        $this->assertSame(['Tuition · Term 2=7000.00', 'Tuition · Term 1=5000.00'], $this->chargesOf($w, $student->id));
    }

    #[Test]
    public function a_charge_added_after_the_preview_refuses_execution_and_comes_back_for_a_decision(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->student($w);
        $run = $this->previewedRun($w);
        $this->assertSame(FeeAssessmentRunItem::RESULT_READY, $this->itemFor($w, $run, $student->id)->preview_result);

        $this->adHoc($w, $student->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');

        $this->expectExceptionObject(new FeeAssessmentRunOverlapChangedException);
        try {
            $this->runs()->execute($w['school'], $run->id, $w['actor']);
        } finally {
            $this->assertSame(['Term 1 tuition fee 2026-27=25000.00'], $this->chargesOf($w, $student->id));
            $this->assertSame(FeeAssessmentRunItem::RESULT_REVIEW, $this->itemFor($w, $this->preview($w, $run), $student->id)->preview_result);
        }
    }

    #[Test]
    public function an_include_decision_does_not_cover_a_charge_added_after_it(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->student($w);
        $this->adHoc($w, $student->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');
        $run = $this->preview($w, $this->draftRun($w));
        $this->runs()->decideOverlap($w['school'], $run->id, $this->itemFor($w, $run, $student->id)->id, 'include', $w['actor']);
        $run = $this->preview($w, $run);

        $this->adHoc($w, $student->id, 'Term 1 tuition top-up', '1000.00', '2026-08-15');

        $this->expectException(FeeAssessmentRunOverlapChangedException::class);
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
    }

    #[Test]
    public function the_executor_fails_an_item_closed_when_a_charge_appears_after_the_run_started(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->student($w);
        $run = $this->previewedRun($w);

        Queue::fake([ExecuteFeeAssessmentRunJob::class]);
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        $this->adHoc($w, $student->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');

        app(FeeAssessmentItemExecutor::class)->executeBatch($w['school'], $run->id);

        $item = $this->itemFor($w, $run, $student->id);
        $this->assertSame([FeeAssessmentRunItem::EXEC_FAILED, FeeAssessmentRunItem::FAIL_OVERLAP_REVIEW_REQUIRED], [$item->execution_status, $item->failure_reason]);
        $this->assertSame(['Term 1 tuition fee 2026-27=25000.00'], $this->chargesOf($w, $student->id), 'never charged');
    }

    #[Test]
    public function only_an_undecided_review_item_takes_a_decision(): void
    {
        $w = $this->assessmentWorld();
        $billed = $this->student($w);
        $clean = $this->student($w);
        $this->adHoc($w, $billed->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');
        $run = $this->preview($w, $this->draftRun($w));

        try {
            $this->runs()->decideOverlap($w['school'], $run->id, $this->itemFor($w, $run, $clean->id)->id, 'include', $w['actor']);
            $this->fail('A ready item takes no overlap decision.');
        } catch (FeeAssessmentRunItemNotDecidableException) {
        }

        $item = $this->itemFor($w, $run, $billed->id);
        $this->runs()->decideOverlap($w['school'], $run->id, $item->id, 'exclude', $w['actor']);
        $this->expectException(FeeAssessmentRunItemNotDecidableException::class);
        $this->runs()->decideOverlap($w['school'], $run->id, $item->id, 'include', $w['actor']);
    }

    #[Test]
    public function the_database_refuses_a_review_item_made_ready_without_a_recorded_decision(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->student($w);
        $this->adHoc($w, $student->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');
        $item = $this->itemFor($w, $this->preview($w, $this->draftRun($w)), $student->id);

        $this->expectException(QueryException::class);
        $this->inSchool($w['school'], fn () => DB::table('fee_assessment_run_items')->where('id', $item->id)->update(['preview_result' => 'ready', 'reason' => null]));
    }

    #[Test]
    public function the_database_keeps_the_overlap_provenance_and_never_lets_a_review_item_execute(): void
    {
        $w = $this->assessmentWorld();
        $student = $this->student($w);
        $this->adHoc($w, $student->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');
        $item = $this->itemFor($w, $this->preview($w, $this->draftRun($w)), $student->id);
        $refused = function (array $change) use ($w, $item): bool {
            try {
                $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('fee_assessment_run_items')->where('id', $item->id)->update($change)));

                return false;
            } catch (QueryException) {
                return true;
            }
        };

        $this->assertTrue($refused(['overlap_charge_ids' => json_encode(['01a00000-0000-7000-8000-000000000000'])]), 'provenance is immutable');
        $this->assertTrue($refused(['execution_status' => 'pending']), 'a review item never gets execution state');
        $this->assertTrue($refused(['preview_result' => 'excluded', 'reason' => 'overlap_excluded']), 'an exclusion needs a recorded decision');
        $this->assertTrue($refused(['overlap_decision' => 'include']), 'a decision needs its actor, time and state');
        $this->assertSame(FeeAssessmentRunItem::RESULT_REVIEW, $this->inSchool($w['school'], fn () => $item->fresh())->preview_result);
    }

    #[Test]
    public function another_schools_charges_are_never_candidates(): void
    {
        $w = $this->assessmentWorld();
        $foreign = $this->assessmentWorld();
        $foreignStudent = $this->student($foreign);
        $this->adHoc($foreign, $foreignStudent->id, 'Term 1 tuition fee 2026-27', '25000.00', '2026-07-30');

        $pool = $this->inSchool($w['school'], fn () => app(FeeRunOverlapDetector::class)->pool($foreign['year']->id, [$foreignStudent->id]));

        $this->assertTrue($pool->isEmpty());
    }

    private function auditCount(array $w, string $type): int
    {
        return $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', $type)->count());
    }
}
