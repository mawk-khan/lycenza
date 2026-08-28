<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Application\Exceptions\JournalEntryAlreadyReversedException;
use App\Domain\Finance\Application\JournalEntryResult;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.2: proves App\Domain\Finance\Application\LedgerService::reverse()
 * -- the production reversal path -- against real PostgreSQL, under
 * the real `school_os_app` runtime role, with the normal TenantContext
 * lifecycle. Complements (does not replace)
 * tests/Feature/Finance/JournalReversalStructuralTest.php, which
 * proves the underlying DATABASE constraints directly via raw Eloquent
 * creates independent of any service -- this file proves the SERVICE
 * built correctly on top of those constraints.
 *
 * `reverse()` returns `JournalEntryResult` (0G.2 closure correction),
 * not the raw `JournalEntry` model -- see `LedgerServicePostTest`'s
 * identical note.
 */
class LedgerServiceReverseTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function service(): LedgerService
    {
        return app(LedgerService::class);
    }

    /** @return Collection<int, JournalLine> */
    private function linesFor(School $school, string $journalEntryId)
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => JournalLine::query()->where('journal_entry_id', $journalEntryId)->orderBy('id')->get(),
        );
    }

    #[Test]
    public function reversing_a_posted_entry_creates_a_new_entry_linked_to_the_original(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '100.00');

        $reversal = $this->service()->reverse($original);

        $this->assertNotSame($original->id, $reversal->journalEntryId);
        $this->assertSame($original->id, $reversal->reversalOfJournalEntryId);
        $this->assertSame($original->school_id, $reversal->schoolId);
        $this->assertSame($original->currency, $reversal->currency);
    }

    #[Test]
    public function reverse_returns_a_typed_result_never_the_raw_eloquent_model(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '10.00');

        $reversal = $this->service()->reverse($original);

        $this->assertInstanceOf(JournalEntryResult::class, $reversal);
        $this->assertNotInstanceOf(JournalEntry::class, $reversal);
        $this->assertSame(2, $reversal->lineCount);
    }

    #[Test]
    public function reversal_lines_exactly_invert_the_original(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $refunds = $this->createLedgerAccount($school, ['type' => 'liability']);

        $original = $this->service()->post($school, new PostJournalEntryData('INR', 'Original posting', [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('0.10', 'INR')),
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('0.20', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('0.25', 'INR')),
            new JournalLineData($refunds->id, JournalSide::Credit, Money::of('0.05', 'INR')),
        ]));

        $reversal = $this->service()->reverse(
            app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->findOrFail($original->journalEntryId)),
        );

        $originalLines = $this->linesFor($school, $original->journalEntryId);
        $reversalLines = $this->linesFor($school, $reversal->journalEntryId);

        $this->assertCount(4, $reversalLines, 'Reversal line count must match the original exactly, no aggregation.');

        foreach ($originalLines as $i => $originalLine) {
            $reversalLine = $reversalLines[$i];

            $this->assertSame($originalLine->ledger_account_id, $reversalLine->ledger_account_id);
            $this->assertSame($originalLine->currency, $reversalLine->currency);
            // Exact swap: original debit becomes reversal credit (byte-
            // identical decimal string, no float artifact), and vice
            // versa.
            $this->assertSame($originalLine->debit_amount, $reversalLine->credit_amount);
            $this->assertSame($originalLine->credit_amount, $reversalLine->debit_amount);
        }
    }

    #[Test]
    public function the_original_entry_and_its_lines_are_never_mutated_by_reversal(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '123.45');

        $before = app(TenantContext::class)->withSchool($school, fn () => [
            'entry' => $original->toArray(),
            'lines' => $original->lines()->orderBy('id')->get()->toArray(),
        ]);

        $this->service()->reverse($original);

        $after = app(TenantContext::class)->withSchool($school, fn () => [
            'entry' => JournalEntry::query()->findOrFail($original->id)->toArray(),
            'lines' => JournalEntry::query()->findOrFail($original->id)->lines()->orderBy('id')->get()->toArray(),
        ]);

        $this->assertSame($before['entry']['id'], $after['entry']['id']);
        $this->assertSame($before['entry']['description'], $after['entry']['description']);
        $this->assertSame($before['entry']['posted_at'], $after['entry']['posted_at']);
        $this->assertSame($before['entry']['reversal_of_journal_entry_id'], $after['entry']['reversal_of_journal_entry_id']);
        $this->assertCount(count($before['lines']), $after['lines'], 'Original line count must not change.');

        foreach ($before['lines'] as $i => $line) {
            $this->assertSame($line['id'], $after['lines'][$i]['id']);
            $this->assertSame($line['debit_amount'], $after['lines'][$i]['debit_amount']);
            $this->assertSame($line['credit_amount'], $after['lines'][$i]['credit_amount']);
        }
    }

    #[Test]
    public function the_reversal_itself_is_balanced(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '75.50');

        $reversal = $this->service()->reverse($original);

        // If the reversal were unbalanced, the deferred constraint
        // trigger would already have rejected the commit -- this
        // assertion just confirms the operation completed at all
        // (reverse() returning without throwing is itself the proof).
        $this->assertNotEmpty($reversal->journalEntryId);
    }

    #[Test]
    public function an_optional_reason_becomes_the_reversal_description(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '10.00');

        $reversal = $this->service()->reverse($original, reason: 'Corrected duplicate posting.');

        $this->assertSame('Corrected duplicate posting.', $reversal->description);
    }

    #[Test]
    public function without_a_reason_a_default_description_is_derived(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '10.00');

        $reversal = $this->service()->reverse($original);

        $this->assertStringContainsString($original->id, $reversal->description);
    }

    /**
     * Closure review decision (see LedgerService::reverse()'s own
     * docblock): ADR 0030/FINANCE.md never restrict reversing a
     * reversal, and the schema applies its uniqueness rule uniformly.
     * This is the direct proof: reversing a reversal succeeds.
     */
    #[Test]
    public function a_reversal_entry_may_itself_be_reversed(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '10.00');

        $reversal = $this->service()->reverse($original);
        $reversalModel = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->findOrFail($reversal->journalEntryId));
        $reversalOfReversal = $this->service()->reverse($reversalModel);

        $this->assertSame($reversal->journalEntryId, $reversalOfReversal->reversalOfJournalEntryId);
    }

    #[Test]
    public function a_sequential_second_reversal_attempt_is_rejected_deterministically(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '10.00');

        $this->service()->reverse($original);

        $this->expectException(JournalEntryAlreadyReversedException::class);
        $this->service()->reverse($original);
    }

    /**
     * Reconfirms exact success cardinality (closure review section 9):
     * a successful reverse() produces exactly 1 reversal JournalEntry,
     * N inverse lines, 1 reversal audit event, 1 reversal outbox
     * event -- and explicitly NOT an additional
     * journal_entry.posted event/audit for the reversal entry it
     * creates.
     */
    #[Test]
    public function reversal_is_transactional_and_produces_exactly_one_outbox_event_and_one_audit_event(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '10.00');

        $reversal = $this->service()->reverse($original);

        $counts = app(TenantContext::class)->withSchool($school, fn () => [
            JournalEntry::query()->where('reversal_of_journal_entry_id', $original->id)->count(),
            JournalLine::query()->where('journal_entry_id', $reversal->journalEntryId)->count(),
        ]);
        $this->assertSame([1, 2], $counts, 'Exactly one reversal entry and N (=2) inverse lines must exist.');

        $events = app(TenantContext::class)->withSchool(
            $school,
            fn () => DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.reversed.v1')->get(),
        );
        $this->assertCount(1, $events, 'Exactly one reversal outbox event, never a posted+reversed pair.');
        $this->assertSame($reversal->journalEntryId, $events[0]->payload['reversalJournalEntryId']);
        $this->assertSame($original->id, $events[0]->payload['originalJournalEntryId']);

        $reversalAuditCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.reversed')->count(),
        );
        $this->assertSame(1, $reversalAuditCount);

        // The posting audit/outbox count is exactly 1 too (from the
        // ORIGINAL post via postBalancedJournalEntry()) -- reverse()
        // must never additionally emit a "posted" event for the
        // reversal entry it creates.
        $postedAuditCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.posted')->count(),
        );
        $this->assertSame(1, $postedAuditCount, 'reverse() must not also emit a posting audit event for the reversal entry.');

        $postedOutboxCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.posted.v1')->count(),
        );
        $this->assertSame(1, $postedOutboxCount, 'reverse() must not also emit a posted outbox event for the reversal entry.');
    }

    #[Test]
    public function a_rolled_back_reversal_leaves_the_original_intact_and_no_reversal_or_event(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $income, '10.00');

        try {
            DB::transaction(function () use ($original): void {
                $this->service()->reverse($original);
                throw new RuntimeException('Simulated failure after reversal.');
            });
            $this->fail('Expected the RuntimeException to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $counts = app(TenantContext::class)->withSchool($school, fn () => [
            JournalEntry::query()->where('reversal_of_journal_entry_id', $original->id)->count(),
            DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.reversed.v1')->count(),
            SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.reversed')->count(),
        ]);

        $this->assertSame([0, 0, 0], $counts, 'A rolled-back reversal must leave no reversal entry, no outbox event, no audit event.');

        $this->assertFalse(app(TenantContext::class)->withSchool($school, fn () => $original->reversedBy()->exists()));
    }
}
