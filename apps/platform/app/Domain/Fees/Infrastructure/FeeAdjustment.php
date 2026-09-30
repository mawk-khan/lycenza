<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.3 (ADR 0062 §14.1, §14.5; owner decisions F2, G1): the posted
 * financial effect of an approved concession on one charge -- Dr the
 * snapshotted concession `expense` account / Cr the charge's receivable
 * account. Immutable except the one-time cancellation (a Finance
 * reversal), database-enforced (`fees_validate_fee_adjustment`); capacity
 * is the Payments-owned guard. `FeeAdjustmentService` is the only writer.
 *
 * @property string $id
 * @property string $school_id
 * @property string $charge_id
 * @property string $fee_concession_id
 * @property string|null $fee_assessment_id
 * @property string $category
 * @property string $amount
 * @property string $currency
 * @property string $debit_ledger_account_id
 * @property string $credit_ledger_account_id
 * @property string $journal_entry_id
 * @property string|null $posted_by_user_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_journal_entry_id
 * @property string|null $cancelled_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FeeAdjustment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'fee_adjustments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->cancelled_at === null;
    }
}
