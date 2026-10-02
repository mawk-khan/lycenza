<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeResult;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\AcademicYearNotFoundException;
use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidChargeException;
use App\Domain\Fees\Application\Exceptions\StudentNotFoundException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Application\Exceptions\InvalidJournalCurrencyException;
use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.4 -- `ChargeService` is the trusted core write path for
 * `charges`, mirroring `Tests\Feature\Finance\LedgerServicePostTest`/
 * `LedgerServiceReverseTest`'s exact discipline: no authorization here
 * (that is `ChargeAdministrationServiceTest`'s job), only correctness
 * of assessment/cancellation, atomicity, and cross-School structural
 * rejection via `charges`' own composite foreign keys.
 */
class ChargeServiceTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function service(): ChargeService
    {
        return app(ChargeService::class);
    }

    private function assessData(string $studentId, string $academicYearId, string $receivableId, string $revenueId, string $amount = '1000.00', string $currency = 'INR'): AssessChargeData
    {
        return new AssessChargeData(
            studentId: $studentId,
            academicYearId: $academicYearId,
            description: 'Term 1 Tuition Fee',
            amount: Money::of($amount, $currency),
            receivableLedgerAccountId: $receivableId,
            revenueLedgerAccountId: $revenueId,
        );
    }

    // --- assess() ---------------------------------------------------

    #[Test]
    public function assessing_a_charge_posts_the_correct_debit_and_credit_and_links_the_journal_entry(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->service()->assess($school, $this->assessData($student->id, $year->id, $receivable->id, $revenue->id, '1000.00'));

        $this->assertInstanceOf(ChargeResult::class, $result);
        $this->assertNotInstanceOf(Charge::class, $result);
        $this->assertSame('INR', $result->currency);
        $this->assertSame('1000.00', $result->amount);
        $this->assertNull($result->cancelledAt);

        $entry = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->with('lines')->findOrFail($result->journalEntryId));
        $this->assertSame('INR', $entry->currency);
        $this->assertCount(2, $entry->lines);

        $debitLine = $entry->lines->firstWhere('ledger_account_id', $receivable->id);
        $creditLine = $entry->lines->firstWhere('ledger_account_id', $revenue->id);
        $this->assertSame('1000.00', $debitLine->debit_amount);
        $this->assertNull($debitLine->credit_amount);
        $this->assertSame('1000.00', $creditLine->credit_amount);
        $this->assertNull($creditLine->debit_amount);
    }

    #[Test]
    public function assessing_a_charge_with_an_exact_paise_amount_posts_exactly(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->service()->assess($school, $this->assessData($student->id, $year->id, $receivable->id, $revenue->id, '0.10'));

        $this->assertSame('0.10', $result->amount);
    }

    #[Test]
    public function the_same_receivable_and_revenue_account_is_rejected_before_any_ledger_posting(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $account = $this->createLedgerAccount($school, ['type' => 'asset']);

        $this->expectException(InvalidChargeException::class);

        try {
            $this->service()->assess($school, $this->assessData($student->id, $year->id, $account->id, $account->id));
        } finally {
            $entryCount = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->count());
            $this->assertSame(0, $entryCount, 'No journal entry may be posted when validation fails before posting.');
        }
    }

    #[Test]
    public function a_non_inr_currency_is_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(InvalidJournalCurrencyException::class);

        $this->service()->assess($school, $this->assessData($student->id, $year->id, $receivable->id, $revenue->id, '100.00', 'USD'));
    }

    #[Test]
    public function a_cross_school_ledger_account_is_rejected_and_nothing_is_persisted(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenueFromOtherSchool = $this->createLedgerAccount($otherSchool, ['type' => 'income']);

        $this->expectException(LedgerAccountNotFoundException::class);

        try {
            $this->service()->assess($school, $this->assessData($student->id, $year->id, $receivable->id, $revenueFromOtherSchool->id));
        } finally {
            $chargeCount = app(TenantContext::class)->withSchool($school, fn () => Charge::query()->count());
            $this->assertSame(0, $chargeCount, 'No charge may be persisted when the ledger posting itself fails.');
        }
    }

    #[Test]
    public function a_cross_school_student_is_rejected_and_the_ledger_posting_is_rolled_back(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $studentFromOtherSchool = $this->createStudent($otherSchool);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(StudentNotFoundException::class);

        try {
            $this->service()->assess($school, $this->assessData($studentFromOtherSchool->id, $year->id, $receivable->id, $revenue->id));
        } finally {
            $chargeCount = app(TenantContext::class)->withSchool($school, fn () => Charge::query()->count());
            $entryCount = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->count());
            $this->assertSame(0, $chargeCount, 'The charge must not persist when the student reference is invalid.');
            $this->assertSame(0, $entryCount, 'The journal entry LedgerService already posted must be rolled back atomically with the failed charge insert.');
        }
    }

    #[Test]
    public function a_nonexistent_student_id_is_rejected_identically_to_a_cross_school_one(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(StudentNotFoundException::class);

        $this->service()->assess($school, $this->assessData((string) Str::uuid(), $year->id, $receivable->id, $revenue->id));
    }

    #[Test]
    public function a_cross_school_academic_year_is_rejected_and_the_ledger_posting_is_rolled_back(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $student = $this->createStudent($school);
        $yearFromOtherSchool = $this->createAcademicYear($otherSchool);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(AcademicYearNotFoundException::class);

        try {
            $this->service()->assess($school, $this->assessData($student->id, $yearFromOtherSchool->id, $receivable->id, $revenue->id));
        } finally {
            $entryCount = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->count());
            $this->assertSame(0, $entryCount, 'The journal entry must be rolled back atomically with the failed charge insert.');
        }
    }

    #[Test]
    public function changing_the_charged_amount_or_accounts_after_assessment_has_no_write_path(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');

        // ChargeService exposes exactly assess()/cancel()/
        // lockChargeForAllocation() -- there is no update()/amend()
        // method whatsoever a caller could use to rewrite a recognized
        // charge's amount/subject/accounts. lockChargeForAllocation()
        // (Phase 0G.5) is a read+lock accessor for the sanctioned
        // cross-module Payments caller (App\Domain\Payments\Application\PaymentProviderEventService)
        // -- it returns a typed ChargeAllocationSnapshot, never a write
        // path, so it does not weaken this invariant. Phase 0O.11A:
        // uncancelledChargesForStudent() is a read-only lookup for the
        // manual payment form (a list of ChargeSummary), likewise no
        // write path. FEE.3: liveAdjustmentTotalsFor() is a read-only
        // per-charge adjustment total for Payments' outstanding display.
        // FEE.4: statementLinesFor*() are read-only statement/receipt facts.
        // FEE.5: lateFeeCandidates()/lateFeeSource() are read-only late-fee facts.
        // E21.3A: ledgerFacts()/adjustmentLedgerFacts() are read-only facts for
        // the financial-period close (Payments' charge states).
        $this->assertFalse(method_exists(ChargeService::class, 'update'));
        $this->assertSame(['assess', 'cancel', 'lockChargeForAllocation', 'liveAdjustmentTotalsFor', 'statementLinesForStudent', 'statementLinesForCharges', 'lateFeeCandidates', 'lateFeeSource', 'uncancelledChargesForStudent', 'ledgerFacts', 'adjustmentLedgerFacts'], array_values(array_filter(
            array_map(fn ($m) => $m->name, (new \ReflectionClass(ChargeService::class))->getMethods(\ReflectionMethod::IS_PUBLIC)),
            fn ($name) => $name !== '__construct',
        )));

        $this->assertSame('1000.00', $charge->amount);
    }

    // --- cancel() ---------------------------------------------------

    #[Test]
    public function cancelling_a_charge_reverses_the_ledger_entry_and_marks_the_charge_cancelled(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '500.00');

        $result = $this->service()->cancel($school, $charge->id);

        $this->assertNotNull($result->cancelledAt);
        $this->assertNotNull($result->cancellationJournalEntryId);
        $this->assertNotSame($charge->journal_entry_id, $result->cancellationJournalEntryId);

        $original = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->with('lines')->findOrFail($charge->journal_entry_id));
        $this->assertNull($original->reversal_of_journal_entry_id, 'The original entry itself is never mutated to point at its own reversal.');

        $reversal = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->with('lines')->findOrFail($result->cancellationJournalEntryId));
        $this->assertSame($charge->journal_entry_id, $reversal->reversal_of_journal_entry_id);

        $reversedDebitLine = $reversal->lines->firstWhere('ledger_account_id', $revenue->id);
        $reversedCreditLine = $reversal->lines->firstWhere('ledger_account_id', $receivable->id);
        $this->assertSame('500.00', $reversedDebitLine->debit_amount, 'Cancellation inverts the original: revenue is now debited.');
        $this->assertSame('500.00', $reversedCreditLine->credit_amount, 'Cancellation inverts the original: receivable is now credited.');
    }

    #[Test]
    public function cancelling_an_already_cancelled_charge_is_rejected_and_leaves_state_unchanged(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $first = $this->service()->cancel($school, $charge->id);

        $this->expectException(ChargeAlreadyCancelledException::class);

        try {
            $this->service()->cancel($school, $charge->id);
        } finally {
            $reversalCount = app(TenantContext::class)->withSchool(
                $school,
                fn () => JournalEntry::query()->where('reversal_of_journal_entry_id', $charge->journal_entry_id)->count(),
            );
            $this->assertSame(1, $reversalCount, 'A second cancellation attempt must not create a second reversal.');
            $reloaded = app(TenantContext::class)->withSchool($school, fn () => Charge::query()->findOrFail($charge->id));
            $this->assertSame($first->cancellationJournalEntryId, $reloaded->cancellation_journal_entry_id);
        }
    }

    #[Test]
    public function cancelling_a_nonexistent_charge_id_is_rejected(): void
    {
        $school = $this->createSchool();

        $this->expectException(ChargeNotFoundException::class);

        $this->service()->cancel($school, (string) Str::uuid());
    }

    #[Test]
    public function cancelling_a_charge_belonging_to_a_different_school_is_rejected_identically_to_nonexistent(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $student = $this->createStudent($otherSchool);
        $year = $this->createAcademicYear($otherSchool);
        $receivable = $this->createLedgerAccount($otherSchool, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($otherSchool, ['type' => 'income']);
        $chargeInOtherSchool = $this->assessCharge($otherSchool, $student, $year, $receivable, $revenue);

        $this->expectException(ChargeNotFoundException::class);

        $this->service()->cancel($school, $chargeInOtherSchool->id);
    }
}
