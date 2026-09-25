<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.5 correction (sections 7-10, ADR 0031 "Invariant C"): proves
 * a Payment's allocation set is frozen the instant its owning
 * transaction commits -- a genuinely LATER, separate transaction can
 * never insert an additional `payment_allocations` row against an
 * already-committed Payment, REGARDLESS of whether the new row would
 * individually still fit within its Charge's remaining capacity.
 *
 * Deliberately does NOT use DatabaseTransactions ($connectionsToTransact
 * = []) -- the whole point is a REAL, separate, later transaction after
 * the Payment's own transaction has REALLY committed; a test-wrapping
 * transaction would make both inserts share the same
 * `pg_current_xact_id()`, defeating the proof entirely. Manual cleanup
 * replaces the automatic rollback, mirroring
 * `Tests\Feature\Fees\ChargeCancellationAtomicityTest`'s established
 * pattern for this exact concern.
 */
class PaymentAllocationFreezeTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            // domain_event_outbox has no FK/cascade to schools (platform-
            // level table, ADR 0025) -- clean up this non-transactional
            // test's own real, committed outbox rows so they cannot
            // accumulate across the suite and compete with a LATER
            // test's own rows for DispatchOutboxEvents' fixed --batch
            // window.
            DomainEventOutbox::query()->where('school_id', $this->school->id)->delete();
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_post_commit_allocation_insert_that_would_exceed_charge_capacity_is_rejected(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '1000.00');

        $result = $this->recordSettlement($this->school, $settlement->id, [[$charge, '1000.00']], '1000.00');

        try {
            $context->withSchool($this->school, function () use ($result, $charge) {
                PaymentAllocation::query()->create([
                    'school_id' => $this->school->id,
                    'payment_id' => $result->paymentId,
                    'charge_id' => $charge->id,
                    'amount' => '1.00',
                    'currency' => 'INR',
                ]);
            });
            $this->fail('Expected a post-commit allocation insert to be rejected.');
        } catch (QueryException) {
            // Expected
        }

        $total = $context->withSchool($this->school, fn () => PaymentAllocation::query()->where('charge_id', $charge->id)->sum('amount'));
        $this->assertSame('1000.00', (string) $total, 'The original allocation total must remain exactly what it was.');
    }

    #[Test]
    public function a_post_commit_allocation_insert_that_would_still_fit_within_charge_capacity_is_still_rejected(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        // 1000.00 charge, only 400.00 settled -- 600.00 of "room" remains,
        // proving a rejection here can ONLY be the frozen-allocation-set
        // invariant, never the over-allocation invariant.
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '1000.00');

        $result = $this->recordSettlement($this->school, $settlement->id, [[$charge, '400.00']], '400.00');

        try {
            $context->withSchool($this->school, function () use ($result, $charge) {
                PaymentAllocation::query()->create([
                    'school_id' => $this->school->id,
                    'payment_id' => $result->paymentId,
                    'charge_id' => $charge->id,
                    'amount' => '100.00',
                    'currency' => 'INR',
                ]);
            });
            $this->fail('Expected a post-commit allocation insert to be rejected even though it would fit within the charge\'s remaining capacity.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('allocation set is frozen', $e->getMessage());
        }

        $total = $context->withSchool($this->school, fn () => PaymentAllocation::query()->where('charge_id', $charge->id)->sum('amount'));
        $this->assertSame('400.00', (string) $total, 'The original allocation total must remain exactly what it was.');
    }

    #[Test]
    public function a_payment_with_zero_allocations_is_rejected_at_commit(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '1000.00');
        $journal = $this->postBalancedJournalEntry($this->school, $settlement, $revenue, '500.00');

        try {
            $context->withSchool($this->school, function () use ($settlement, $journal) {
                DB::connection('pgsql')->transaction(function () use ($settlement, $journal) {
                    $event = PaymentProviderEvent::query()->create([
                        'school_id' => $this->school->id,
                        'provider' => 'test-provider',
                        'provider_event_id' => (string) Str::uuid(),
                        'event_type' => 'payment.settled',
                        'provider_payment_reference' => (string) Str::uuid(),
                        'amount' => '500.00',
                        'currency' => 'INR',
                        'occurred_at' => now(),
                        'received_at' => now(),
                    ]);

                    Payment::query()->create([
                        'school_id' => $this->school->id,
                        'provider' => 'test-provider',
                        'provider_payment_reference' => $event->provider_payment_reference,
                        'amount' => '500.00',
                        'currency' => 'INR',
                        'settlement_ledger_account_id' => $settlement->id,
                        'journal_entry_id' => $journal->id,
                        'provider_event_id' => $event->id,
                        'settled_at' => now(),
                    ]);
                    // No PaymentAllocation row created at all.
                });
            });
            $this->fail('Expected a Payment with zero allocations to be rejected at commit.');
        } catch (QueryException|\PDOException $e) {
            // The DEFERRED constraint trigger fires at the real COMMIT
            // issued by DB::transaction() itself -- a failure there
            // surfaces as a raw PDOException (the COMMIT statement
            // failing), not a QueryException (which only wraps failures
            // from an individual prepared statement/query).
            $this->assertStringContainsString('do not sum to its own amount', $e->getMessage());
        }
    }
}
