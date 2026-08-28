<?php

namespace Database\Factories;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntry>
 *
 * A bare `JournalEntry::factory()->create()` produces a syntactically
 * valid HEADER row (passes every IMMEDIATE constraint: currency
 * format, no-self-reversal, the (id, school_id, currency) unique
 * index) but zero journal_lines -- ADR 0030's balance-check constraint
 * trigger is DEFERRABLE INITIALLY DEFERRED, so this does NOT fail at
 * insert time inside an ordinary DatabaseTransactions-wrapped test
 * (the deferred check only runs at a real COMMIT or an explicit `SET
 * CONSTRAINTS ALL IMMEDIATE`, neither of which an ordinary rolled-back
 * test reaches). It WOULD fail if used inside a test that disables
 * DatabaseTransactions (a real concurrency test) or that explicitly
 * forces immediate checking -- do not use this factory alone in
 * either of those; use Tests\Concerns\CreatesFinanceFixtures::
 * postBalancedJournalEntry() instead, which creates a balanced header
 * + lines together.
 */
class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'currency' => 'INR',
            'description' => fake()->sentence(),
            'posted_at' => now(),
        ];
    }
}
