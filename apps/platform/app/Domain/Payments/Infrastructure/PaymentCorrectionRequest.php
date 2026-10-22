<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ORG.8C (ADR 0076 §61): a requested Payment correction (reallocation,
 * reversal, recorded refund) awaiting an independent decision. Its terms are
 * exactly what `PaymentCorrectionService` executes, frozen by the database
 * once submitted; `prior_correction_count` is the Payment's generation when it
 * was requested. Approval executes it in the same transaction.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payment_id
 * @property string $kind reallocation|reversal|refund
 * @property string|null $charge_id
 * @property string|null $to_charge_id
 * @property string|null $amount
 * @property string $currency
 * @property string|null $settlement_ledger_account_id
 * @property string|null $refund_method
 * @property string|null $refund_reference
 * @property Carbon|null $refunded_on
 * @property string $reason
 * @property int $prior_correction_count
 * @property string $status pending|approved|rejected|withdrawn
 * @property string $requested_by_user_id
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $withdrawn_at
 * @property string|null $withdrawn_by_user_id
 * @property string|null $payment_correction_id
 * @property string $idempotency_key
 * @property Carbon $created_at
 */
class PaymentCorrectionRequest extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string WITHDRAWN = 'withdrawn';

    protected $table = 'payment_correction_requests';

    protected $fillable = [
        'school_id', 'payment_id', 'kind', 'charge_id', 'to_charge_id', 'amount', 'currency', 'settlement_ledger_account_id',
        'refund_method', 'refund_reference', 'refunded_on', 'reason', 'prior_correction_count', 'status', 'requested_by_user_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['refunded_on' => 'date', 'decided_at' => 'datetime', 'withdrawn_at' => 'datetime', 'prior_correction_count' => 'integer'];
    }
}
