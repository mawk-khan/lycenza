<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (section 43 of the 0G.4 brief),
 * mirroring `Tests\Feature\Finance\JournalReversalConcurrencyTest`'s
 * exact pattern: two GENUINELY separate OS processes both attempt to
 * cancel the SAME charge for the SAME School at the same time.
 * `journal_entries_reversal_of_unique` (ADR 0030), reached through
 * `LedgerService::reverseById()`, is what makes this safe -- this test
 * proves the FINAL DATABASE STATE (exactly one reversal, exactly one
 * cancelled charge, exactly one loser mapped to
 * `ChargeAlreadyCancelledException`), not just that the application
 * code "looks" correct.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates ($connectionsToTransact = []) -- the two subprocesses
 * are separate PostgreSQL sessions/connections and can never see this
 * test process's uncommitted rows, identical reasoning to
 * `JournalReversalConcurrencyTest`.
 */
class ChargeConcurrencyTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades charges/ledger_accounts/journal_entries/journal_lines
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_cancelling_the_same_charge_leave_exactly_one_cancellation(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '100.00');

        $script = __DIR__.'/../../Support/cancel-charge.php';
        $args = [$this->school->id, $charge->id];
        $processA = new Process(['php', $script, ...$args]);
        $processB = new Process(['php', $script, ...$args]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $cancelledCount = count(array_filter($outputs, fn ($o) => $o === 'cancelled'));

        $this->assertSame(1, $cancelledCount, 'Exactly one of the two concurrent cancellation attempts must succeed.');
        $this->assertTrue(
            collect($outputs)->contains(fn ($o) => $o === 'rejected:App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException'),
            'The losing attempt must fail with ChargeService::cancel()\'s typed already-cancelled exception, not silently no-op. Got: '.implode(', ', $outputs)
        );

        $reversalCount = $context->withSchool(
            $this->school,
            fn () => JournalEntry::query()->where('reversal_of_journal_entry_id', $charge->journal_entry_id)->count(),
        );
        $this->assertSame(1, $reversalCount, 'The database must contain exactly one reversal of the charge\'s original journal entry.');

        $cancelledCharges = $context->withSchool(
            $this->school,
            fn () => Charge::query()->where('id', $charge->id)->whereNotNull('cancelled_at')->count(),
        );
        $this->assertSame(1, $cancelledCharges, 'The charge itself must end up cancelled exactly once.');
    }
}
