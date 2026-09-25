<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.5 REQUIRED real-concurrency proof (rule 31/63): two
 * GENUINELY separate OS processes both attempt to allocate against the
 * SAME Charge, each requesting more than half its remaining balance --
 * if both succeeded, the Charge would be over-allocated.
 * `App\Domain\Fees\Application\ChargeService::lockChargeForAllocation()`'s
 * `SELECT ... FOR UPDATE` lock is what makes this safe; the database's
 * own `payment_allocations_charge_not_overallocated_check` deferred
 * constraint trigger is the authoritative backstop. Mirrors
 * `Tests\Feature\Fees\ChargeConcurrencyTest`'s exact two-real-process
 * pattern.
 */
class PaymentAllocationConcurrencyTest extends TestCase
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
            DomainEventOutbox::query()->where('school_id', $this->school->id)->delete();
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_allocating_more_than_the_remaining_balance_leave_exactly_one_winner(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '1000.00');

        $script = __DIR__.'/../../Support/allocate-payment.php';
        $args = [$this->school->id, $charge->id, $settlement->id, '700.00'];
        $processA = new Process(['php', $script, ...$args]);
        $processB = new Process(['php', $script, ...$args]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $settledCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'settled:')));

        $this->assertSame(1, $settledCount, 'Exactly one of the two concurrent 700.00 allocations against a 1000.00 charge must succeed. Got: '.implode(', ', $outputs));
        $this->assertTrue(
            collect($outputs)->contains(fn ($o) => str_starts_with($o, 'rejected:')),
            'The losing attempt must be rejected, not silently over-allocate. Got: '.implode(', ', $outputs)
        );

        $totalAllocated = $context->withSchool(
            $this->school,
            fn () => PaymentAllocation::query()->where('charge_id', $charge->id)->sum('amount'),
        );
        $this->assertSame('700.00', (string) $totalAllocated, 'The charge must end up allocated exactly once (700.00), never both.');
    }
}
