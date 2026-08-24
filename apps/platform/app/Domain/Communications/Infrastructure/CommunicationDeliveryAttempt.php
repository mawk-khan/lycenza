<?php

namespace App\Domain\Communications\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @use HasFactory<CommunicationDeliveryAttemptFactory>
 *
 * Append-only (TenantRls::makeAppendOnly on its migration) -- never
 * updated or deleted by application code.
 *
 * @property string $id
 * @property string $school_id
 * @property string $communication_delivery_id
 * @property int $attempt_number
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property string $outcome
 * @property string|null $provider_reference
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int|null $duration_ms
 */
class CommunicationDeliveryAttempt extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id', 'communication_delivery_id', 'attempt_number', 'started_at',
        'completed_at', 'outcome', 'provider_reference', 'failure_code',
        'failure_message', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CommunicationDeliveryAttemptFactory
    {
        return CommunicationDeliveryAttemptFactory::new();
    }

    /** @return BelongsTo<CommunicationDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(CommunicationDelivery::class, 'communication_delivery_id');
    }
}
