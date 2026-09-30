<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.5: one recognized, immutable-forever settlement of money via
 * a payment provider -- see the `create_payments_table` migration for
 * why there is no status column and no legitimate UPDATE path at all
 * (unlike `App\Domain\Fees\Infrastructure\Charge`'s cancellation pair).
 * Persistence only (CLAUDE.md rule 3) --
 * `App\Domain\Payments\Application\PaymentProviderEventService` owns
 * recognition.
 *
 * Phase 0O.11A: a Payment is now either provider-derived or manually
 * recorded (`source`, `payments_source_shape_check`); both are written
 * only by `App\Domain\Payments\Application\SettledPaymentRecorder`.
 *
 * @property string $id
 * @property string $school_id
 * @property string $source
 * @property string|null $provider
 * @property string|null $provider_payment_reference
 * @property string $amount
 * @property string $currency
 * @property string $settlement_ledger_account_id
 * @property string $journal_entry_id
 * @property string|null $provider_event_id
 * @property string|null $method
 * @property string|null $manual_reference
 * @property string|null $recorded_by_user_id
 * @property string|null $idempotency_key
 * @property Carbon $settled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Collection<int, PaymentAllocation> $allocations
 * @property PaymentReceipt|null $receipt
 */
class Payment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'payments';

    protected $fillable = [
        'school_id', 'source', 'provider', 'provider_payment_reference', 'amount', 'currency',
        'settlement_ledger_account_id', 'journal_entry_id', 'provider_event_id', 'settled_at',
        // Phase 0O.11A: manual/offline provenance (ADR 0031 amendment).
        'method', 'manual_reference', 'recorded_by_user_id', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'settled_at' => 'datetime',
        ];
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * FEE.4 (ADR 0062 §17): the one receipt of this Payment, if issued.
     *
     * @return HasOne<PaymentReceipt, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }
}
