<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string $id
 * @property string $school_id
 * @property string $provider
 * @property string $provider_payment_reference
 * @property string $amount
 * @property string $currency
 * @property string $settlement_ledger_account_id
 * @property string $journal_entry_id
 * @property string $provider_event_id
 * @property Carbon $settled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Collection<int, PaymentAllocation> $allocations
 */
class Payment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'payments';

    protected $fillable = [
        'school_id', 'provider', 'provider_payment_reference', 'amount', 'currency',
        'settlement_ledger_account_id', 'journal_entry_id', 'provider_event_id', 'settled_at',
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
}
