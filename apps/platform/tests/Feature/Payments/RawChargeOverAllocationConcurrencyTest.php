<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.5 correction (section 15, ADR 0031): proves the DATABASE
 * trigger (`payments_lock_and_validate_charge_allocation`) alone --
 * never Application-layer pre-validation -- prevents two genuinely
 * concurrent, separate OS processes from over-allocating the SAME
 * Charge. Each process bypasses `PaymentProviderEventService` entirely
 * (`tests/Support/raw-allocate-payment.php` creates the
 * PaymentProviderEvent/Payment/PaymentAllocation rows directly via
 * Eloquent, never through the Application service's own business
 * validation) -- only the real database triggers stand between this
 * test and a corrupted allocation total. Mirrors
 * `Tests\Feature\Payments\PaymentAllocationConcurrencyTest`'s process
 * pattern, deliberately with a DIFFERENT (raw) code path.
 */
class RawChargeOverAllocationConcurrencyTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

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
            // E21-RH.6: the runtime role no longer deletes outbox rows.
            DB::connection('pgsql_admin')->table('domain_event_outbox')->where('school_id', $this->school->id)->delete();
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    #[Test]
    public function two_raw_concurrent_processes_each_individually_valid_cannot_combine_to_overallocate_a_charge(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '1000.00');

        // Two independent, otherwise-valid settlement journal entries --
        // each raw process needs its own (payments.journal_entry_id is
        // school-scoped unique).
        $journalA = $this->postBalancedJournalEntry($this->school, $settlement, $revenue, '700.00');
        $journalB = $this->postBalancedJournalEntry($this->school, $settlement, $revenue, '700.00');

        $script = __DIR__.'/../../Support/raw-allocate-payment.php';
        $processA = new Process(['php', $script, $this->school->id, $charge->id, $settlement->id, $journalA->id, '700.00']);
        $processB = new Process(['php', $script, $this->school->id, $charge->id, $settlement->id, $journalB->id, '700.00']);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $settledCount = count(array_filter($outputs, fn ($o) => $o === 'settled'));

        $this->assertSame(1, $settledCount, 'Exactly one of the two concurrent raw 700.00 allocations against a 1000.00 charge must succeed. Got: '.implode(', ', $outputs));
        $this->assertTrue(
            collect($outputs)->contains(fn ($o) => str_starts_with($o, 'rejected:')),
            'The losing raw attempt must be rejected by the database itself. Got: '.implode(', ', $outputs)
        );

        $totalAllocated = $context->withSchool(
            $this->school,
            fn () => PaymentAllocation::query()->where('charge_id', $charge->id)->sum('amount'),
        );
        $this->assertSame('700.00', (string) $totalAllocated, 'Final allocation total must never exceed the charge amount.');
    }
}
