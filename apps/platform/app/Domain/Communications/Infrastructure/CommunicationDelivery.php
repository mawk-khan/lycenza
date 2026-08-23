<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @use HasFactory<CommunicationDeliveryFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $recipient_id
 * @property string $channel
 * @property string $status
 * @property array<string, mixed>|null $destination_snapshot
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property int $attempts
 * @property Carbon|null $processing_lease_expires_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_code
 * @property string|null $failure_reason
 */
class CommunicationDelivery extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    /**
     * Section 11's closed terminal-state set -- a delivery in any of
     * these states is done and safe to redrive from scratch, never
     * silently re-processed by a job that happens to run again.
     */
    private const TERMINAL_STATUSES = [
        'delivered', 'read', 'failed', 'bounced', 'rejected', 'expired', 'cancelled',
    ];

    protected $fillable = [
        'school_id', 'recipient_id', 'channel', 'status', 'destination_snapshot',
        'provider', 'provider_message_id', 'attempts', 'processing_lease_expires_at',
        'next_attempt_at', 'queued_at', 'sent_at', 'delivered_at', 'read_at',
        'failed_at', 'failure_code', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'destination_snapshot' => 'array',
            'processing_lease_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CommunicationDeliveryFactory
    {
        return CommunicationDeliveryFactory::new();
    }

    /** @return BelongsTo<CommunicationRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CommunicationRecipient::class, 'recipient_id');
    }

    /** @return HasMany<CommunicationDeliveryAttempt, $this> */
    public function attemptsHistory(): HasMany
    {
        return $this->hasMany(CommunicationDeliveryAttempt::class, 'communication_delivery_id');
    }

    public function channelEnum(): CommunicationChannel
    {
        return CommunicationChannel::from($this->channel);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
