<?php

namespace Database\Factories;

use App\Domain\Finance\Infrastructure\JournalLine;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalLine>
 *
 * Deliberately does NOT self-configure a consistent School: the
 * default `school_id`/`journal_entry_id`/`ledger_account_id` each
 * resolve through independent nested factories, which by default
 * create three DIFFERENT Schools -- a bare `JournalLine::factory()
 * ->create()` fails immediately (the composite foreign keys are not
 * deferred) rather than silently producing a cross-School line
 * (section 34: factories must not casually generate cross-School
 * references). Callers must explicitly wire matching
 * school_id/journal_entry_id/ledger_account_id/currency -- see
 * Tests\Concerns\CreatesFinanceFixtures::postBalancedJournalEntry()
 * for the intentional, safe way to build a real posted entry.
 */
class JournalLineFactory extends Factory
{
    protected $model = JournalLine::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'journal_entry_id' => JournalEntryFactory::new(),
            'ledger_account_id' => LedgerAccount::factory(),
            'currency' => 'INR',
            'debit_amount' => '100.00',
            'credit_amount' => null,
        ];
    }

    public function debit(string $amount): static
    {
        return $this->state(fn () => ['debit_amount' => $amount, 'credit_amount' => null]);
    }

    public function credit(string $amount): static
    {
        return $this->state(fn () => ['credit_amount' => $amount, 'debit_amount' => null]);
    }
}
