<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.5: one durable, idempotent, PURE ingress-identity record for
 * an inbound payment-provider callback -- see the
 * `create_payment_provider_events_table` migration for the full
 * uniqueness/immutability rationale (no mutable processing-state column
 * exists; the relationship to the `Payment` it produced is represented
 * entirely by `Payment::$provider_event_id`). Persistence only, no
 * business behavior (CLAUDE.md rule 3) --
 * `App\Domain\Payments\Application\PaymentProviderEventService` owns
 * claim/processing.
 *
 * @property string $id
 * @property string $school_id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property string $provider_payment_reference
 * @property string $amount
 * @property string $currency
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PaymentProviderEvent extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'payment_provider_events';

    protected $fillable = [
        'school_id', 'provider', 'provider_event_id', 'event_type', 'provider_payment_reference',
        'amount', 'currency', 'occurred_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
