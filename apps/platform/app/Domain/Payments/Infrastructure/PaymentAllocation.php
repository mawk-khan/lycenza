<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.5: one immutable-forever unit of one `Payment` applied to one
 * `App\Domain\Fees\Infrastructure\Charge` -- see the
 * `create_payment_allocations_table` migration for the cross-row
 * invariants (payment-fully-allocated, charge-not-overallocated) and the
 * Charge-cancellation interlock this table's mere existence enforces.
 * Persistence only (CLAUDE.md rule 3).
 *
 * @property string $id
 * @property string $school_id
 * @property string $payment_id
 * @property string $charge_id
 * @property string $amount
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PaymentAllocation extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'payment_allocations';

    protected $fillable = ['school_id', 'payment_id', 'charge_id', 'amount', 'currency'];

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
