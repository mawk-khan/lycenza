<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * UAT-C3 (ADR 0031 amendment §3): one explicit, compensating correction of a
 * Payment -- `reallocation`, `reversal` or `refund` -- with its own journal
 * entry and reason. Append-only; written only by `PaymentCorrectionService`.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payment_id
 * @property string $kind
 * @property string $amount
 * @property string $currency
 * @property string $journal_entry_id
 * @property string|null $settlement_ledger_account_id
 * @property string|null $refund_method
 * @property string|null $refund_reference
 * @property Carbon|null $refunded_on
 * @property string $reason
 * @property string $corrected_by_user_id
 * @property string $idempotency_key
 * @property Carbon $created_at
 */
class PaymentCorrection extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const string REALLOCATION = 'reallocation';

    public const string REVERSAL = 'reversal';

    public const string REFUND = 'refund';

    protected $table = 'payment_corrections';

    protected $fillable = [
        'school_id', 'payment_id', 'kind', 'amount', 'currency', 'journal_entry_id', 'settlement_ledger_account_id',
        'refund_method', 'refund_reference', 'refunded_on', 'reason', 'corrected_by_user_id', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['refunded_on' => 'date'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return HasMany<PaymentCorrectionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PaymentCorrectionLine::class);
    }
}
