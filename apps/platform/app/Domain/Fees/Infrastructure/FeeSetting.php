<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * FEE.1 (ADR 0062 §14.5, §17.2): the per-School Fees settings singleton.
 * Its columns belong to FEE.3 (concession account) and FEE.4 (receipt
 * numbering) and stay NULL until those checkpoints; FEE.1 has no writer.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $concession_ledger_account_id
 * @property string|null $receipt_prefix
 * @property int|null $financial_year_start_month
 * @property string $currency
 */
class FeeSetting extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'fee_settings';

    protected $fillable = [
        'school_id', 'concession_ledger_account_id', 'receipt_prefix', 'financial_year_start_month', 'currency',
    ];
}
