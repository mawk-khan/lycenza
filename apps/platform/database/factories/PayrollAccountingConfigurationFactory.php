<?php

namespace Database\Factories;

use App\Domain\Payroll\Infrastructure\PayrollAccountingConfiguration;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollAccountingConfiguration>
 */
class PayrollAccountingConfigurationFactory extends Factory
{
    protected $model = PayrollAccountingConfiguration::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'salary_expense_ledger_account_id' => LedgerAccountFactory::new()->type('expense'),
            'salary_payable_ledger_account_id' => LedgerAccountFactory::new()->type('liability'),
            'currency' => 'INR',
        ];
    }
}
