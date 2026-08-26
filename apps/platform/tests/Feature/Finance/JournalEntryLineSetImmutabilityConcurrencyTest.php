<?php

namespace Tests\Feature\Finance;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (closure review section 4E): two
 * GENUINELY separate OS processes both attempt to INSERT an additional
 * journal_lines row against the SAME already-committed journal_entry
 * at the same time. Unlike the reversal race
 * (JournalReversalConcurrencyTest), this is not expected to let either
 * process win -- the posting_txid check is deterministic per
 * transaction, not a "first writer wins" race, so BOTH must fail.
 * Proven with real concurrency anyway (not merely asserted) to rule
 * out any accidental race window in the trigger itself.
 */
class JournalEntryLineSetImmutabilityConcurrencyTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_extending_the_same_committed_entry_both_fail(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($this->school, $cash, $income, '100.00');

        $script = __DIR__.'/../../Support/extend-journal-entry.php';
        $args = [$this->school->id, $entry->id, $cash->id];
        $processA = new Process(['php', $script, ...$args]);
        $processB = new Process(['php', $script, ...$args]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];

        $this->assertTrue(
            collect($outputs)->every(fn ($o) => str_starts_with((string) $o, 'rejected:')),
            'Neither concurrent attempt to extend an already-committed entry may succeed. Got: '.implode(', ', $outputs),
        );

        $lineCount = $context->withSchool($this->school, fn () => $entry->lines()->count());
        $this->assertSame(2, $lineCount, 'The entry must still have exactly its original two lines.');
    }
}
