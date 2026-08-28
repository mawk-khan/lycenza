<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Charge>
 *
 * Unlike `JournalEntryFactory`/`LedgerAccountFactory`, `charges` has
 * FIVE required composite foreign keys that must all agree on the
 * SAME `school_id` (`student_id`, `academic_year_id`,
 * `receivable_ledger_account_id`, `revenue_ledger_account_id`,
 * `journal_entry_id`) -- `definition()` therefore eagerly creates one
 * shared School and every related row itself, rather than the usual
 * lazy `Model::factory()` reference (which would otherwise give each
 * relation its OWN unrelated School and fail every composite FK).
 *
 * A bare `Charge::factory()->create()` produces a syntactically valid
 * row (useful for tests exercising `charges`' OWN constraints/RLS in
 * isolation) but its `journal_entry_id` points at a HEADER-ONLY
 * `JournalEntry` with no lines -- exactly `JournalEntryFactory`'s own
 * documented limitation, inherited here. A test needing a charge whose
 * ledger posting is real and balanced must go through the actual
 * `App\Domain\Fees\Application\ChargeService::assess()` instead (see
 * `Tests\Concerns\CreatesFeesFixtures::assessCharge()`), the same
 * "don't maintain a second, competing implementation of posting
 * semantics" rule `CreatesFinanceFixtures::postBalancedJournalEntry()`
 * already established.
 */
class ChargeFactory extends Factory
{
    protected $model = Charge::class;

    public function definition(): array
    {
        $school = School::factory()->create();
        $student = Student::factory()->for($school, 'school')->create();
        $academicYear = AcademicYear::factory()->for($school, 'school')->create();
        $receivableAccount = LedgerAccount::factory()->for($school, 'school')->type('asset')->create();
        $revenueAccount = LedgerAccount::factory()->for($school, 'school')->type('income')->create();
        $journalEntry = JournalEntry::factory()->for($school, 'school')->create(['currency' => 'INR']);

        return [
            'school_id' => $school->id,
            'student_id' => $student->id,
            'academic_year_id' => $academicYear->id,
            'description' => fake()->sentence(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'currency' => 'INR',
            'due_date' => null,
            'receivable_ledger_account_id' => $receivableAccount->id,
            'revenue_ledger_account_id' => $revenueAccount->id,
            'journal_entry_id' => $journalEntry->id,
        ];
    }
}
