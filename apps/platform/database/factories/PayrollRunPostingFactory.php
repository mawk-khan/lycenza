<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRunPosting>
 */
class PayrollRunPostingFactory extends Factory
{
    protected $model = PayrollRunPosting::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'payroll_run_id' => PayrollRunFactory::new()->posted(),
            'journal_entry_id' => JournalEntryFactory::new(),
            'currency' => 'INR',
            'posting_kind' => 'original',
            'reversal_of_payroll_run_posting_id' => null,
            'actor_user_id' => User::factory(),
            'reason' => null,
        ];
    }

    public function reversalOf(string $originalPostingId, string $payrollRunId): static
    {
        return $this->state(fn () => [
            'payroll_run_id' => $payrollRunId,
            'posting_kind' => 'reversal',
            'reversal_of_payroll_run_posting_id' => $originalPostingId,
        ]);
    }
}
