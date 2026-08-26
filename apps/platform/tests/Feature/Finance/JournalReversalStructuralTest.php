<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.1 (ADR 0030 "Reversal linkage direction"): structural
 * proofs for the reversal relationship, independent of the balance
 * trigger -- every constraint exercised here (the self-reversal CHECK,
 * the composite self-referential FK, the partial unique index) is
 * IMMEDIATE, not deferred, so no `SET CONSTRAINTS ALL IMMEDIATE` is
 * needed (contrast with JournalEntryBalanceEnforcementTest). These
 * tests use bare header-only journal_entries rows (no journal_lines) --
 * safe here because none of these constraints depend on lines, and the
 * deferred balance trigger is never forced, so it never fires inside
 * these DatabaseTransactions-wrapped (never truly committed) tests.
 *
 * 0G.2 will prove the reversing entry's LINES actually invert the
 * original's -- that semantic behavior does not exist yet (no Posting
 * & Reversal Application Service in 0G.1), so it is out of scope here.
 */
class JournalReversalStructuralTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function createBareEntry(School $school, array $attributes = []): JournalEntry
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => JournalEntry::query()->create(array_merge([
                'school_id' => $school->id,
                'currency' => 'INR',
                'description' => 'Structural test entry',
            ], $attributes)),
        );
    }

    #[Test]
    public function a_reversal_must_belong_to_the_same_school_as_its_original(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $original = $this->createBareEntry($schoolA);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($schoolB, fn () => JournalEntry::query()->create([
            'school_id' => $schoolB->id,
            'currency' => 'INR',
            'description' => 'Cross-school reversal attempt',
            'reversal_of_journal_entry_id' => $original->id,
        ]));
    }

    /**
     * Closure review section 7-9: since
     * journal_entries_currency_inr_only_check means a non-INR
     * journal_entries row can never exist at all under Phase 0G's
     * INR-only scope, this test can no longer construct "two
     * different but both valid currencies" to isolate the reversal
     * composite FK specifically -- the CHECK constraint alone already
     * rejects the USD attempt below. It still proves a meaningful,
     * true statement: a reversal attempt is not exempt from the
     * INR-only CHECK either. The composite FK's own SAME-currency
     * enforcement (structurally, for when a future multi-currency
     * checkpoint loosens the CHECK) remains in place in the migration
     * even though it cannot be independently exercised while Phase 0G
     * stays INR-only.
     */
    #[Test]
    public function a_reversal_with_a_non_inr_currency_is_rejected(): void
    {
        $school = $this->createSchool();
        $original = $this->createBareEntry($school, ['currency' => 'INR']);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->create([
            'school_id' => $school->id,
            'currency' => 'USD',
            'description' => 'Currency-mismatched reversal attempt',
            'reversal_of_journal_entry_id' => $original->id,
        ]));
    }

    #[Test]
    public function an_entry_cannot_reverse_itself(): void
    {
        $school = $this->createSchool();
        $selfId = (string) new UuidV7;

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->create([
            'id' => $selfId,
            'school_id' => $school->id,
            'currency' => 'INR',
            'description' => 'Self reversal attempt',
            'reversal_of_journal_entry_id' => $selfId,
        ]));
    }

    #[Test]
    public function a_reversal_cannot_reference_a_nonexistent_original(): void
    {
        $school = $this->createSchool();

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->create([
            'school_id' => $school->id,
            'currency' => 'INR',
            'description' => 'Dangling reversal attempt',
            'reversal_of_journal_entry_id' => (string) new UuidV7,
        ]));
    }

    #[Test]
    public function a_second_reversal_of_the_same_original_is_rejected(): void
    {
        $school = $this->createSchool();
        $original = $this->createBareEntry($school);

        app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->create([
            'school_id' => $school->id,
            'currency' => 'INR',
            'description' => 'First reversal',
            'reversal_of_journal_entry_id' => $original->id,
        ]));

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->create([
            'school_id' => $school->id,
            'currency' => 'INR',
            'description' => 'Second reversal attempt',
            'reversal_of_journal_entry_id' => $original->id,
        ]));
    }

    #[Test]
    public function a_valid_same_school_same_currency_reversal_succeeds_structurally(): void
    {
        $school = $this->createSchool();
        $original = $this->createBareEntry($school);

        $reversal = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->create([
            'school_id' => $school->id,
            'currency' => 'INR',
            'description' => 'Valid reversal',
            'reversal_of_journal_entry_id' => $original->id,
        ]));

        $this->assertSame($original->id, $reversal->reversal_of_journal_entry_id);
        $this->assertTrue($reversal->isReversal());
        $this->assertFalse($original->isReversal());
    }
}
