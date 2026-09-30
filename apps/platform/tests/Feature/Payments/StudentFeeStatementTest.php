<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Payments\Application\StudentFeeStatementReadService;
use App\Models\SchoolAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * FEE.4 (ADR 0062 §18): the staff Student fee statement -- computed on read
 * (no stored balance), outstanding = amount - allocations - live
 * adjustments per charge (0 when cancelled), totals are the sums, receipt
 * numbers with each payment, fee head and period from the fee assessment,
 * an AcademicYear filter, both capabilities required, one read audit.
 */
class StudentFeeStatementTest extends TestCase
{
    use CreatesReceiptFixtures;

    private function statements(): StudentFeeStatementReadService
    {
        return app(StudentFeeStatementReadService::class);
    }

    private function viewer(array $w)
    {
        return $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.payments.view']);
    }

    #[Test]
    public function the_statement_nets_payments_and_live_adjustments_per_charge(): void
    {
        $w = $this->concessionWorld();
        $student = $this->enroll($w, '2026-06-01', [], null, $w['student'])->student_id;
        $this->executedRun($w);
        $assessed = $this->inSchool($w['school'], fn () => FeeAssessment::query()->sole());

        // Charge A (manual, 1000.00): a 200.00 concession, a 300.00 payment,
        // and a cancelled 50.00 concession adjustment that no longer counts.
        $live = $this->requestTargeted($w, '200.00');
        $this->concessions()->approve($w['school'], $live->id, $w['checker']);
        $gone = $this->requestTargeted($w, '50.00');
        $this->concessions()->approve($w['school'], $gone->id, $w['checker']);
        $this->concessions()->cancelAdjustment($w['school'], $this->adjustmentsOf($w, $w['charge']->id)->firstWhere('fee_concession_id', $gone->id)->id, $w['checker']);
        $payment = $this->pay($w, '300.00', '2026-04-01');

        // Charge B (assessed T1 5000.00): unpaid. Charge C: cancelled.
        $cancelled = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '75.00');
        app(ChargeService::class)->cancel($w['school'], $cancelled->id);

        $statement = $this->statements()->statementFor($w['school'], $student, null, $this->viewer($w));
        $lines = collect($statement->lines)->keyBy('chargeId');

        $a = $lines[$w['charge']->id];
        $this->assertSame(['1000.00', '200.00', '300.00', '500.00'], [$a['amount'], $a['adjustedTotal'], $a['paidTotal'], $a['outstanding']]);
        $this->assertCount(2, $a['adjustments'], 'Cancelled adjustments stay visible as history.');
        $this->assertSame('RCPT/2026-27/000001', $a['payments'][0]['receiptNumber']);
        $this->assertSame($payment->paymentId, $a['payments'][0]['paymentId']);

        $b = $lines[$assessed->charge_id];
        $this->assertSame(['5000.00', '5000.00', 'Tuition', 'T1', 'Term 1'], [$b['amount'], $b['outstanding'], $b['feeHeadName'], $b['billingPeriodKey'], $b['billingPeriodLabel']]);

        $c = $lines[$cancelled->id];
        $this->assertNotNull($c['cancelledAt']);
        $this->assertSame('0.00', $c['outstanding'], 'A cancelled charge owes nothing.');

        $this->assertSame(['charged' => '6000.00', 'adjusted' => '200.00', 'paid' => '300.00', 'outstanding' => '5500.00'], $statement->totals);
        $this->assertSame($statement->totals['outstanding'], collect($statement->lines)->reduce(fn ($carry, $l) => bcadd($carry, $l['outstanding'], 2), '0.00'), 'The total is the sum of the lines.');
    }

    #[Test]
    public function the_academic_year_filter_and_other_schools_students(): void
    {
        $w = $this->concessionWorld();
        $nextYear = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31', 'status' => 'draft']);
        $later = $this->assessCharge($w['school'], $w['student'], $nextYear, $w['receivable'], $w['revenue'], '10.00');

        $this->assertSame([$later->id], array_column($this->statements()->statementFor($w['school'], $w['student']->id, $nextYear->id, $this->viewer($w))->lines, 'chargeId'));
        $this->assertCount(2, $this->statements()->statementFor($w['school'], $w['student']->id, null, $this->viewer($w))->lines);

        $other = $this->concessionWorld();
        $this->assertSame([], $this->statements()->statementFor($w['school'], $other['student']->id, null, $this->viewer($w))->lines, 'Another School\'s Student has no lines here.');
    }

    #[Test]
    public function both_capabilities_are_required_and_only_a_successful_view_is_audited(): void
    {
        $w = $this->concessionWorld();

        foreach ([['finance.charges.view'], ['finance.payments.view'], ['finance.fee_concessions.view']] as $caps) {
            try {
                $this->statements()->statementFor($w['school'], $w['student']->id, null, $this->createUserWithCapabilities($w['school'], $caps));
                $this->fail('Denied with only '.implode(',', $caps));
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $this->auditCount($w['school'], 'fee_statement.viewed'));

        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $w['school']), 'principal');
        $this->expectException(AuthorizationException::class);
        try {
            $this->statements()->statementFor($w['school'], $w['student']->id, null, $principal);
        } finally {
            $this->statements()->statementFor($w['school'], $w['student']->id, null, $this->viewer($w));
            $event = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'fee_statement.viewed')->sole());
            $this->assertEqualsCanonicalizing(['studentId', 'academicYearId', 'lineCount'], array_keys($event->metadata));
        }
    }

    #[Test]
    public function standing_concessions_on_assessed_charges_appear_as_adjustments(): void
    {
        $w = $this->concessionWorld();
        $student = $this->enroll($w)->student_id;
        $this->approvedStanding($w, $student, FeeConcession::KIND_PERCENTAGE, '10.00');
        $this->executedRun($w);

        $line = $this->statements()->statementFor($w['school'], $student, $w['year']->id, $this->viewer($w))->lines[0];

        $this->assertSame(['5000.00', '500.00', '4500.00'], [$line['amount'], $line['adjustedTotal'], $line['outstanding']]);
        $this->assertSame('scholarship', $line['adjustments'][0]['category']);
    }
}
