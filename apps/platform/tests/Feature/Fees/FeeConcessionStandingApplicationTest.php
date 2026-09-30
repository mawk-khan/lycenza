<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\Exceptions\ChargeHasActiveAdjustmentsException;
use App\Domain\Fees\Application\FeeAssessmentService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Finance\Infrastructure\JournalEntry;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * FEE.3 (ADR 0062 §14.4; owner decisions F2, G1): approved standing
 * concessions are posted inside the FEE.2 assessment item transaction, for
 * the matching Student, year, fee head and instalment period start. An
 * over-limit concession or an invalid concession account fails the item
 * closed: no charge, no assessment, no adjustment, no journal entry.
 * T1 = 5000.00 (2026-06-01..2026-10-31), T2 = 7000.00 (2026-11-01..).
 */
class FeeConcessionStandingApplicationTest extends TestCase
{
    use CreatesFeeConcessionFixtures;

    private function journalCount(array $w): int
    {
        return $this->inSchool($w['school'], fn () => JournalEntry::query()->count());
    }

    #[Test]
    public function an_approved_percentage_concession_posts_with_the_assessment(): void
    {
        $w = $this->concessionWorld();
        $enrollment = $this->enroll($w);
        $concession = $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_PERCENTAGE, '10.00');

        $run = $this->executedRun($w);

        $this->assertSame('succeeded', $this->itemFor($w, $run, $enrollment->student_id)->execution_status);
        $assessment = $this->inSchool($w['school'], fn () => FeeAssessment::query()->sole());
        $adjustment = $this->adjustmentsOf($w, $assessment->charge_id)->sole();
        $this->assertSame('500.00', $adjustment->amount);
        $this->assertSame($concession->id, $adjustment->fee_concession_id);
        $this->assertSame($assessment->id, $adjustment->fee_assessment_id);
        $this->assertSame('scholarship', $adjustment->category);
        $this->assertSame('5000.00', $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($assessment->charge_id)->amount), 'The gross charge is unchanged.');

        $this->executedRun($w, 'T2');
        $this->assertSame(['500.00', '700.00'], $this->adjustmentsOf($w)->where('fee_concession_id', $concession->id)->pluck('amount')->values()->all());
    }

    #[Test]
    public function the_window_head_and_status_decide_whether_a_concession_applies(): void
    {
        $w = $this->concessionWorld();
        $otherHead = $this->makeFeeHead($w, ['code' => 'TRANSPORT', 'name' => 'Transport']);
        $enrollment = $this->enroll($w);
        $student = $enrollment->student_id;

        $t1Only = $this->approvedStanding($w, $student, FeeConcession::KIND_FIXED, '100.00', null, '2026-06-01', '2026-10-31');
        $this->approvedStanding($w, $student, FeeConcession::KIND_FIXED, '999.00', $otherHead->id);
        $this->requestStanding($w, $student, FeeConcession::KIND_FIXED, '50.00');
        $rejected = $this->requestStanding($w, $student, FeeConcession::KIND_FIXED, '60.00');
        $this->concessions()->reject($w['school'], $rejected->id, $w['checker']);

        $this->executedRun($w, 'T1');
        $this->executedRun($w, 'T2');

        $this->assertSame([$t1Only->id], $this->adjustmentsOf($w)->pluck('fee_concession_id')->all(), 'Only the approved, same-head concession whose window covers T1 applies.');
    }

    #[Test]
    public function a_revoked_concession_does_not_apply_to_later_charges(): void
    {
        $w = $this->concessionWorld();
        $enrollment = $this->enroll($w);
        $concession = $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_FIXED, '100.00');

        $this->executedRun($w, 'T1');
        $this->concessions()->revoke($w['school'], $concession->id, $w['checker']);
        $this->executedRun($w, 'T2');

        $this->assertCount(1, $this->adjustmentsOf($w));
    }

    #[Test]
    public function concessions_exceeding_the_charge_fail_the_item_closed_with_no_partial_application(): void
    {
        $w = $this->concessionWorld();
        $enrollment = $this->enroll($w);
        $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_PERCENTAGE, '60.00');
        $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_PERCENTAGE, '50.00');
        $journals = $this->journalCount($w);

        $run = $this->executedRun($w);

        $item = $this->itemFor($w, $run, $enrollment->student_id);
        $this->assertSame('failed', $item->execution_status);
        $this->assertSame('concession_exceeds_outstanding', $item->failure_reason);
        $this->assertSame(0, $this->inSchool($w['school'], fn () => Charge::query()->where('student_id', $enrollment->student_id)->count()));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => FeeAssessment::query()->count()));
        $this->assertCount(0, $this->adjustmentsOf($w), 'The first 3000.00 adjustment rolled back with the item.');
        $this->assertSame($journals, $this->journalCount($w), 'No journal entry survives.');
    }

    #[Test]
    public function a_fixed_concession_above_the_instalment_fails_the_item(): void
    {
        $w = $this->concessionWorld();
        $enrollment = $this->enroll($w);
        $other = $this->enroll($w);
        $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_FIXED, '5000.01');

        $run = $this->executedRun($w);

        $this->assertSame('concession_exceeds_outstanding', $this->itemFor($w, $run, $enrollment->student_id)->failure_reason);
        $this->assertSame('succeeded', $this->itemFor($w, $run, $other->student_id)->execution_status, 'Other Students are unaffected.');
    }

    #[Test]
    public function an_invalid_concession_account_fails_only_items_a_concession_applies_to(): void
    {
        $w = $this->concessionWorld(configureAccount: false);
        $withConcession = $this->enroll($w);
        $without = $this->enroll($w);
        $this->approvedStanding($w, $withConcession->student_id, FeeConcession::KIND_FIXED, '100.00');

        $run = $this->executedRun($w);

        $this->assertSame('concession_account_invalid', $this->itemFor($w, $run, $withConcession->student_id)->failure_reason);
        $this->assertSame('succeeded', $this->itemFor($w, $run, $without->student_id)->execution_status);
        $this->assertSame('completed_with_errors', $this->inSchool($w['school'], fn () => $run->refresh()->status));
    }

    #[Test]
    public function voiding_an_assessment_with_a_live_concession_is_refused_until_the_adjustment_is_cancelled(): void
    {
        $w = $this->concessionWorld();
        $enrollment = $this->enroll($w);
        $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_FIXED, '100.00');
        $this->executedRun($w);
        $assessment = $this->inSchool($w['school'], fn () => FeeAssessment::query()->sole());

        try {
            app(FeeAssessmentService::class)->void($w['school'], $assessment->id, $w['actor']);
            $this->fail('A live adjustment blocks the void (its charge cannot be cancelled).');
        } catch (ChargeHasActiveAdjustmentsException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull($this->inSchool($w['school'], fn () => $assessment->refresh()->voided_at), 'The void rolled back.');

        $this->concessions()->cancelAdjustment($w['school'], $this->adjustmentsOf($w)->sole()->id, $w['checker']);
        app(FeeAssessmentService::class)->void($w['school'], $assessment->id, $w['actor']);

        $this->executedRun($w);
        $this->assertCount(2, $this->adjustmentsOf($w), 'Re-assessment applies the still-approved concession to the new charge.');
        $this->assertCount(1, $this->adjustmentsOf($w)->whereNull('cancelled_at'));
    }
}
