<?php

namespace App\Domain\Canteen\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CanteenBillingConfigurationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A School-wide singleton (unique(school_id)) naming the two
 * ledger_accounts rows ChargeService::assess() posts against at Order
 * fulfillment. Persistence only -- App\Domain\Canteen\Application\CanteenBillingConfigurationService
 * owns validation/mutation.
 *
 * @property string $id
 * @property string $school_id
 * @property string $receivable_ledger_account_id
 * @property string $revenue_ledger_account_id
 * @property string $currency
 */
class CanteenBillingConfiguration extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'canteen_billing_configurations';

    protected $fillable = ['school_id', 'receivable_ledger_account_id', 'revenue_ledger_account_id', 'currency'];

    protected static function newFactory(): CanteenBillingConfigurationFactory
    {
        return CanteenBillingConfigurationFactory::new();
    }
}
