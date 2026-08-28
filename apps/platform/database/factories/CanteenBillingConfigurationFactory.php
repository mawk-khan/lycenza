<?php

namespace Database\Factories;

use App\Domain\Canteen\Infrastructure\CanteenBillingConfiguration;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanteenBillingConfiguration>
 *
 * Deliberately LAZY relation references -- see CanteenOutletFactory's
 * docblock for why (RLS-safety under an already-active TenantContext).
 */
class CanteenBillingConfigurationFactory extends Factory
{
    protected $model = CanteenBillingConfiguration::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'receivable_ledger_account_id' => LedgerAccount::factory()->type('asset'),
            'revenue_ledger_account_id' => LedgerAccount::factory()->type('income'),
            'currency' => 'INR',
        ];
    }
}
