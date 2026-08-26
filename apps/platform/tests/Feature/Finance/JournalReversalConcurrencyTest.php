<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (section 24/50 of the 0G.1 brief,
 * mirroring AcademicYearActivationConcurrencyTest's established
 * pattern): two GENUINELY separate OS processes -- not two sequential
 * calls in one PHP process -- both attempt to reverse the SAME
 * original journal_entry for the SAME School at the same time. ADR
 * 0030's partial unique index on `reversal_of_journal_entry_id` is
 * what makes this safe; this test proves the FINAL DATABASE STATE, not
 * just that the application code "looks" correct.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates ($connectionsToTransact = []) -- the two subprocesses
 * are separate PostgreSQL sessions/connections and can never see this
 * test process's uncommitted rows, exactly like
 * AcademicYearActivationConcurrencyTest's identical reasoning. This
 * also means the deferred balance-check trigger genuinely fires at
 * each subprocess's real COMMIT (not merely forced via `SET
 * CONSTRAINTS ALL IMMEDIATE` as the non-concurrency balance tests do).
 */
class JournalReversalConcurrencyTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades ledger_accounts/journal_entries/journal_lines
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_reversing_the_same_entry_leave_exactly_one_reversal(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($this->school, $cash, $income, '100.00');

        $script = __DIR__.'/../../Support/reverse-journal-entry.php';
        $args = [$this->school->id, $original->id, $cash->id, $income->id, '100.00'];
        $processA = new Process(['php', $script, ...$args]);
        $processB = new Process(['php', $script, ...$args]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $reversedCount = count(array_filter($outputs, fn ($o) => $o === 'reversed'));

        $this->assertSame(1, $reversedCount, 'Exactly one of the two concurrent reversal attempts must succeed.');
        $this->assertTrue(
            collect($outputs)->contains(fn ($o) => str_starts_with((string) $o, 'rejected:')),
            'The losing attempt must fail with a real database exception, not silently no-op.'
        );

        $reversalCount = $context->withSchool(
            $this->school,
            fn () => JournalEntry::query()->where('reversal_of_journal_entry_id', $original->id)->count(),
        );
        $this->assertSame(1, $reversalCount, 'The database must contain exactly one reversal of the original entry.');
    }
}
